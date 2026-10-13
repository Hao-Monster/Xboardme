#!/usr/bin/env python3
"""One exact-main, CI-only stage diagnostic; never a benchmark admission."""
import argparse
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import signal
import sys
import time

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('diagnostic_inventory_ci', HERE / 'test-image-inventory-docker.py')
CI = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CI)
PRETESTS = (
    ('parser-watchdog', ('bash', '.github/scripts/test-images-only-inventory.sh')),
    ('supervisor-self-test', ('python3', '-B', '.github/scripts/test-image-inventory-docker.py', '--mode', 'self-test')),
    ('ci-boundary', ('python3', '-B', '.github/scripts/test-image-inventory-ci.py')),
)
TRACES = ('before-tests', 'after-tests', 'after-fixture')
TOTALS = ('cpu_some_total_usec', 'io_full_total_usec')
RESULT = 'DIAGNOSTIC_ONLY_NO_ADMISSION'
INVALID = 'UNRESOLVED_INVALID_OR_INCOMPLETE'
WORKFLOW = 'Hao-Monster/Xboardme/.github/workflows/image-inventory-ci.yml@refs/heads/main'


def failure_code(error):
    if isinstance(error, CI.ClosedFailure) and re.fullmatch(r'[a-z0-9_]{1,96}', str(error)):
        return str(error)
    return 'diagnostic_interrupted' if isinstance(error, (KeyboardInterrupt, SystemExit)) else 'diagnostic_local_failure'


def json_bytes(value):
    data = (json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False) + '\n').encode('ascii')
    CI.require(len(data) <= 131072 and CI.CANARY.encode() not in data, 'diagnostic_publication_invalid')
    return data


def diagnostic_guard():
    env = os.environ
    CI.require(env.get('GITHUB_ACTIONS') == 'true' and env.get('CI') == 'true' and
               env.get('RUNNER_OS') == 'Linux' and env.get('RUNNER_ENVIRONMENT') == 'github-hosted' and
               env.get('GITHUB_REPOSITORY') == 'Hao-Monster/Xboardme', 'diagnostic_hosted_ci_required')
    CI.require(env.get('GITHUB_EVENT_NAME') == 'workflow_dispatch' and env.get('GITHUB_REF') == 'refs/heads/main' and
               env.get('GITHUB_WORKFLOW_REF') == WORKFLOW and
               re.fullmatch(r'[a-f0-9]{40}', env.get('GITHUB_SHA', '')) is not None and
               env.get('GITHUB_WORKFLOW_SHA') == env.get('GITHUB_SHA'), 'diagnostic_exact_main_required')
    CI.require(env.get('IMAGE_INVENTORY_CI_ALLOW_LOCAL') == 'diagnostic' and
               env.get('IMAGE_INVENTORY_CI_DIAGNOSTIC') == 'true' and
               env.get('IMAGE_INVENTORY_CI_BENCHMARK') == 'false', 'diagnostic_exclusive_opt_in_required')
    CI.require(not env.get('DOCKER_HOST') and not env.get('DOCKER_CONTEXT'), 'docker_override_forbidden')
    CI.require(not any(env.get(key) for key in ('BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS', 'LD_PRELOAD')) and
               not any(key.startswith('BASH_FUNC_') for key in env), 'harness_override_forbidden')


def read_identity(deadline):
    def run(args):
        remaining = deadline - time.monotonic()
        CI.require(remaining > 2, 'diagnostic_normal_deadline')
        return CI.supervised(args, budget=min(120, remaining))[0]
    checkout = run(['git', 'rev-parse', 'HEAD']).decode('ascii').strip()
    dirty = run(['git', 'status', '--porcelain', '--untracked-files=no']).strip()
    CI.require(checkout == os.environ['GITHUB_SHA'] and not dirty, 'diagnostic_identity_invalid')
    kernel = os.uname().release
    CI.require(re.fullmatch(r'[A-Za-z0-9_.+~-]{1,128}', kernel) is not None, 'diagnostic_kernel_invalid')
    files = (Path(__file__), HERE / 'image-inventory-diagnostic-boundary.py',
             HERE / 'test-image-inventory-docker.py', HERE / 'test-image-inventory-ci.py',
             HERE / 'test-images-only-inventory.sh', HERE / 'images-only-inventory.sh',
             HERE / 'retention-safety.sh', HERE.parent / 'workflows/image-inventory-ci.yml')
    CI.require(all(path.is_file() and not path.is_symlink() for path in files), 'diagnostic_source_invalid')
    return dict(checkout_sha=checkout, workflow_sha=os.environ['GITHUB_WORKFLOW_SHA'],
                workflow_ref=WORKFLOW, kernel_release=kernel,
                sources={path.name: CI.digest(path) for path in files})


class Coordinator:
    """Dependency seams are for offline tests, not command-line overrides."""
    def __init__(self, *, clock=None, sleep=None, sampler=None, runner_factory=None,
                 command_runner=None, identity_reader=None, publisher=None, environment_guard=None):
        self.clock, self.sleep = clock or time.monotonic, sleep or time.sleep
        self.sampler = sampler
        self.runner_factory = runner_factory or (lambda: CI.InventoryCI('diagnostic'))
        self.command_runner = command_runner or self.run_pretest
        self.identity_reader = identity_reader or read_identity
        self.publisher = publisher or self.publish
        self.environment_guard = environment_guard or diagnostic_guard
        self.runner, self.publication = None, None
        self.last_clock, self.last_totals = None, None
        self.normal_deadline = None
        self.cancel_requested = False
        self.recovering = False
        self.finalizing = False
        self.report = dict(schema_version=1, protocol='same_runner_stage_diagnostic_v1', result=RESULT,
            status='RUNNING', classification=INVALID, trace_exceeded_pattern=[None, None, None],
            production_authority=False, continuous_health_claim=False, causal_conclusion=False,
            schedule_is_exact_failed_benchmark_replay=False, source_error_details='REDACTED',
            limits=dict(CI.LIMITS), identity=None, traces=[], workloads=[], sample_sequence=[],
            fixture_summary={}, teardown='NOT_RUN', failures=[], publication_phase='not-started',
            scopes={name: dict(budget_seconds=budget, started_monotonic=None, deadline_monotonic=None,
                               ended_monotonic=None, status='NOT_STARTED')
                    for name, budget in (('normal', 900), ('recovery', 300), ('final', 60))})

    def now(self):
        try:
            value = self.clock()
            CI.require(type(value) in (int, float) and math.isfinite(value) and value >= 0 and
                       (self.last_clock is None or value >= self.last_clock), 'diagnostic_clock_invalid')
        except BaseException:
            raise CI.ClosedFailure('diagnostic_clock_invalid') from None
        self.last_clock = value
        return value

    def fail(self, error):
        code = failure_code(error)
        if code not in self.report['failures']:
            self.report['failures'].append(code)
        self.report['classification'] = INVALID

    def cancel_check(self):
        CI.require(not self.cancel_requested, 'diagnostic_interrupted')

    def normal_check(self, *, cancellation=True):
        now = self.now()
        CI.require(self.normal_deadline is not None and now <= self.normal_deadline, 'diagnostic_normal_deadline')
        if cancellation:
            self.cancel_check()
        return now

    def read_row(self, previous=None):
        start = self.normal_check(cancellation=False)
        value = CI.validate_preparation_sample(self.sampler(), previous)
        end = self.now()
        value.update(actual_start_monotonic=start, actual_end_monotonic=end,
                     sequence_index=len(self.report['sample_sequence']))
        # This sequence retains every valid scalar, including a regressing PSI
        # observation. No raw invalid source or exception text is published.
        self.report['sample_sequence'].append(value)
        return value

    def accept_totals(self, row):
        current = tuple(row[key] for key in TOTALS)
        valid = self.last_totals is None or all(a >= b for a, b in zip(current, self.last_totals))
        row['psi_totals_nondecreasing'] = valid
        CI.require(valid, 'diagnostic_psi_total_regressed')
        self.last_totals = current

    @staticmethod
    def interval(before, after):
        return dict(elapsed_seconds=after['actual_start_monotonic'] - before['actual_start_monotonic'],
                    cpu_some_total_delta_usec=after['cpu_some_total_usec'] - before['cpu_some_total_usec'],
                    io_full_total_delta_usec=after['io_full_total_usec'] - before['io_full_total_usec'])

    def trace(self, name):
        start = self.normal_check()
        trace = dict(name=name, status='RUNNING', started_monotonic=start, ended_monotonic=None,
                     duration_seconds=60, sample_interval_seconds=10, sample_lateness_limit_seconds=1,
                     sampling_envelope_seconds=65, attempts=[], samples=[], intervals=[], exceeded=None,
                     violations_by_limit={key: [] for key in CI.LIMITS}, failure=None)
        self.report['traces'].append(trace)
        previous = None
        try:
            CI.require(self.normal_deadline - start >= 65, 'diagnostic_trace_budget')
            for index in range(7):
                target = start + index * 10
                now = self.normal_check()
                if now < target:
                    self.sleep(target - now)
                actual_start = self.normal_check()
                attempt = dict(index=index, scheduled_monotonic=target,
                               actual_start_monotonic=actual_start, actual_end_monotonic=None)
                trace['attempts'].append(attempt)
                CI.require(actual_start <= start + 65, 'diagnostic_trace_envelope')
                CI.require(target <= actual_start <= target + 1, 'diagnostic_sample_timing')
                row = self.read_row(previous)
                row.update(index=index, scheduled_offset_seconds=index * 10, scheduled_monotonic=target)
                trace['samples'].append(row)
                attempt['actual_end_monotonic'] = row['actual_end_monotonic']
                self.accept_totals(row)
                CI.require(target <= row['actual_start_monotonic'] <= row['actual_end_monotonic'] <= target + 1,
                           'diagnostic_sample_timing')
                CI.require(row['actual_end_monotonic'] <= start + 65, 'diagnostic_trace_envelope')
                previous = row['cpu_ticks']
            end = self.normal_check()
            CI.require(end <= start + 65, 'diagnostic_trace_envelope')
            trace.update(status='COMPLETE', ended_monotonic=end,
                         exceeded=any(row['violations'] for row in trace['samples']))
            trace['violations_by_limit'] = {key: [row['index'] for row in trace['samples'] if key in row['violations']]
                                             for key in CI.LIMITS}
            trace['intervals'] = [dict(from_index=a['index'], to_index=b['index'], **self.interval(a, b))
                                  for a, b in zip(trace['samples'], trace['samples'][1:])]
            self.report['trace_exceeded_pattern'][TRACES.index(name)] = trace['exceeded']
        except BaseException as error:
            trace.update(status='FAIL', ended_monotonic=self.last_clock, failure=failure_code(error))
            raise

    def workload(self, name, action):
        self.normal_check()
        boundary = dict(name=name, status='RUNNING', before=None, after=None,
                        started_monotonic=None, ended_monotonic=None, result=None,
                        continuous_10_second_sampling=False, failure=None)
        self.report['workloads'].append(boundary)
        try:
            boundary['before'] = self.read_row()
            self.accept_totals(boundary['before'])
            boundary['started_monotonic'] = self.normal_check()
            error = None
            try:
                boundary['result'] = action()
            except BaseException as caught:
                error = caught
            boundary['ended_monotonic'] = self.now()
            # A failed command still has its scalar after-boundary when sources
            # and D remain valid. No subsequent workload begins on failure.
            boundary['after'] = self.read_row(boundary['before']['cpu_ticks'])
            self.accept_totals(boundary['after'])
            boundary.update(self.interval(boundary['before'], boundary['after']))
            self.normal_check()
            if error is not None:
                raise error
            CI.require(boundary['result'] is None or type(boundary['result']) is int and boundary['result'] == 0,
                       'diagnostic_workload_nonzero')
            boundary['status'] = 'COMPLETE'
        except BaseException as error:
            boundary.update(status='FAIL', failure=failure_code(error))
            raise

    def run_pretest(self, name, argv, deadline):
        # The boundary module is CI-only and still executes the three original
        # commands. It owns nested sessions that a single outer group cannot.
        spec = importlib.util.spec_from_file_location('diagnostic_owned_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        boundary = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(boundary)
        CI.require((name, tuple(argv)) in PRETESTS, 'diagnostic_command_invalid')
        return boundary.run_pretest(CI, name, deadline, lambda: self.cancel_requested)

    def publish(self, name, value, deadline):
        CI.require(re.fullmatch(r'(?:summary|completion|trace-(?:before-tests|after-tests|after-fixture))\.json', name)
                   is not None, 'diagnostic_publication_name')
        if self.publication is None:
            # The InventoryCI constructor has already checked RUNNER_TEMP and
            # checkout provenance. Do not write to a caller-supplied path.
            self.publication = self.runner.runner_temp / 'image-inventory-diagnostic-results'
            self.publication.mkdir(mode=0o700)
        remaining = deadline - time.monotonic()
        CI.require(remaining > 2, 'diagnostic_publication_deadline')
        data = json_bytes(value)
        # Serialization belongs to the same absolute publication scope; a
        # pre-serialization budget must not permit a late writer to start.
        remaining = deadline - time.monotonic()
        CI.require(remaining > 2, 'diagnostic_publication_deadline')
        CI.supervised([sys.executable, '-B', str(Path(__file__)), '--write-json', name],
                      input_bytes=data, budget=min(120, remaining))

    def publish_all(self, phase, deadline):
        self.report['publication_phase'] = phase
        for trace in self.report['traces']:
            CI.require(self.now() <= deadline, 'diagnostic_publication_deadline')
            self.publisher('trace-' + trace['name'] + '.json',
                           dict(result=RESULT, identity=self.report['identity'], limits=self.report['limits'], trace=trace), deadline)
            CI.require(self.now() <= deadline, 'diagnostic_publication_deadline')
        self.publisher('summary.json', self.report, deadline)
        CI.require(self.now() <= deadline, 'diagnostic_publication_deadline')

    def classify(self):
        report = self.report
        if (report['failures'] or report['scopes']['normal']['status'] != 'COMPLETE' or
                report['teardown'] != 'PASS_EXACT_OWNED_ONLY' or
                [row['name'] for row in report['traces']] != list(TRACES) or
                any(row['status'] != 'COMPLETE' for row in report['traces']) or
                [row['name'] for row in report['workloads']] != [x[0] for x in PRETESTS] + ['setup', 'correctness'] or
                any(row['status'] != 'COMPLETE' or row['before'] is None or row['after'] is None
                    for row in report['workloads'])):
            return INVALID
        a, b, c = report['trace_exceeded_pattern']
        return ('BASELINE_LIMIT_EXCEEDED' if a else 'FIRST_EXCEEDANCE_AFTER_TESTS' if b else
                'FIRST_EXCEEDANCE_AFTER_FIXTURE' if c else 'UNRESOLVED_NO_SAMPLED_LIMIT_EXCEEDANCE')

    def execute(self):
        normal = self.report['scopes']['normal']
        normal_work_complete = False
        try:
            start = self.now()
            self.normal_deadline = start + 900
            normal.update(started_monotonic=start, deadline_monotonic=self.normal_deadline, status='RUNNING')
            self.environment_guard()
            self.normal_check()
            self.runner = self.runner_factory()
            self.runner.deadline = self.normal_deadline
            self.runner.abort_check = self.cancel_check
            self.normal_check()
            self.report['identity'] = self.identity_reader(self.normal_deadline)
            self.normal_check()
            if self.sampler is None:
                self.sampler = CI.PreparationSampler().sample
            self.trace(TRACES[0])
            for name, argv in PRETESTS:
                self.workload(name, lambda name=name, argv=argv: self.command_runner(name, argv, self.normal_deadline))
            self.trace(TRACES[1])
            self.workload('setup', self.runner.setup)
            self.workload('correctness', self.runner.correctness)
            self.trace(TRACES[2])
            self.normal_check()
            normal_work_complete = True
        except BaseException as error:
            self.fail(error)
        finally:
            # D is never taken back from runner.deadline, which teardown resets.
            if self.runner is not None:
                # An explicit allowlist, not the raw runner object or Docker
                # inventory. Keep the existing correctness/source/count proof.
                self.report['fixture_summary'] = {key: self.runner.summary[key] for key in
                    ('checkout_sha', 'sources', 'docker', 'preexisting_counts', 'correctness',
                     'fixture_counts', 'global_counts', 'mixed_missing', 'config_host_config_hashes', 'measurements')
                    if key in self.runner.summary}
            try:
                self.normal_check(cancellation=False)
                self.publish_all('pre-teardown', self.normal_deadline)
                self.normal_check(cancellation=False)
                self.cancel_check()
            except BaseException as error:
                self.fail(error)
                normal_work_complete = False
            normal.update(status='COMPLETE' if normal_work_complete and not self.report['failures'] else 'FAIL',
                          ended_monotonic=self.last_clock)
            self.recover()
            self.report['classification'] = self.classify()
            self.final_publish()
        return self.report

    def recover(self):
        self.recovering = True
        scope = self.report['scopes']['recovery']
        try:
            start = self.now()
            scope.update(started_monotonic=start, deadline_monotonic=start + 300, status='RUNNING')
        except BaseException as error:
            self.fail(error)
        try:
            if self.runner is not None:
                # Normal cancellation is latched in the report, not forwarded
                # into the original separately bounded exact-owned recovery.
                self.runner.abort_check = None
                self.runner.teardown()
                self.report['teardown'] = self.runner.summary['teardown']
            else:
                self.report['teardown'] = 'NOT_NEEDED_NO_RESOURCE_CREATION'
            end = self.now()
            CI.require(scope['deadline_monotonic'] is not None and end <= scope['deadline_monotonic'],
                       'diagnostic_recovery_deadline')
            scope.update(status='COMPLETE', ended_monotonic=end)
        except BaseException as error:
            scope.update(status='FAIL', ended_monotonic=self.last_clock)
            self.report['teardown'] = 'FAIL_RETAIN_AMBIGUOUS_RESOURCES'
            self.fail(error)
        self.recovering = False

    def final_publish(self):
        self.finalizing = True
        scope = self.report['scopes']['final']
        try:
            start = self.now()
            deadline = start + 60
            scope.update(started_monotonic=start, deadline_monotonic=deadline, status='PENDING_POST_WRITE_STATUS')
            self.report['status'] = 'PENDING_FINAL_PUBLICATION_STATUS'
            self.publish_all('final', deadline)
            summary_sha = hashlib.sha256(json_bytes(self.report)).hexdigest()
            CI.require(self.now() <= deadline, 'diagnostic_final_deadline')
            terminal = 'FAIL_DIAGNOSTIC_ONLY' if self.report['failures'] else 'COMPLETE_DIAGNOSTIC_ONLY'
            receipt = dict(schema_version=1, result=RESULT, summary_sha256=summary_sha,
                           evidence_status=terminal, classification=self.report['classification'],
                           final_started_monotonic=start, final_deadline_monotonic=deadline,
                           receipt_started_monotonic=self.last_clock,
                           status='PROVISIONAL_REQUIRES_ZERO_EXIT_AND_SUCCESSFUL_JOB', production_authority=False)
            self.publisher('completion.json', receipt, deadline)
            end = self.now()
            CI.require(end <= deadline, 'diagnostic_final_deadline')
            scope.update(status='COMPLETE', ended_monotonic=end)
            self.report['status'] = terminal
        except BaseException as error:
            self.fail(error)
            self.report['status'] = 'FAIL_DIAGNOSTIC_ONLY'
            scope.update(status='FAIL', ended_monotonic=self.last_clock)
        self.finalizing = False


def write_child(name):
    """Only the supervised private writer uses this entry, not a workflow input."""
    diagnostic_guard()
    CI.require(re.fullmatch(r'(?:summary|completion|trace-(?:before-tests|after-tests|after-fixture))\.json', name)
               is not None, 'diagnostic_publication_name')
    root = Path(os.environ.get('RUNNER_TEMP', '')).resolve()
    CI.require(root.is_dir() and re.fullmatch(r'/[A-Za-z0-9_./-]+', str(root)) is not None,
               'diagnostic_publication_root')
    directory = root / 'image-inventory-diagnostic-results'
    CI.require(directory.is_dir() and not directory.is_symlink(), 'diagnostic_publication_root')
    data = sys.stdin.buffer.read(131073)
    value = json.loads(data)
    CI.require(data == json_bytes(value), 'diagnostic_publication_invalid')
    path = directory / name
    temporary = path.with_suffix('.json.new')
    CI.require(not path.is_symlink() and not temporary.is_symlink(), 'diagnostic_publication_root')
    # The parent owns and bounds this one-shot process. Atomic replace leaves an
    # earlier partial summary in place if a later publication is interrupted.
    with temporary.open('wb') as stream:
        stream.write(data)
    temporary.replace(path)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--write-json', default=None)
    options = parser.parse_args()
    if options.write_json is not None:
        write_child(options.write_json)
        return
    coordinator = Coordinator()
    def interrupt(signum, frame):
        if not coordinator.recovering and not coordinator.finalizing:
            coordinator.cancel_requested = True
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, interrupt)
    report = coordinator.execute()
    print('IMAGE_INVENTORY_DIAGNOSTIC=' + report['status'] + ' result=' + RESULT +
          ' classification=' + report['classification'] + ' final_scope=' + report['scopes']['final']['status'] +
          ' production_authority=false')
    if report['status'] != 'COMPLETE_DIAGNOSTIC_ONLY':
        raise SystemExit(2)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('IMAGE_INVENTORY_DIAGNOSTIC=FAIL reason=' + failure_code(error))
        raise SystemExit(2)
