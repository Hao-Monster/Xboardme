#!/usr/bin/env python3
"""Offline CI-harness regression tests; fake Docker and owned shell groups only."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import signal
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

PARSER = argparse.ArgumentParser()
PARSER.add_argument('--case', action='append', default=[])
OPTIONS = PARSER.parse_args()
SOURCE = Path(__file__).resolve().with_name('test-image-inventory-docker.py')
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location('reviewed_harness', SOURCE)
MOD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MOD)
MOD.HERE = Path(__file__).resolve().parent
CID = '1' * 64
IID = 'sha256:' + '2' * 64
LABEL = 'overhead-ci-' + '3' * 32
TAG = LABEL + ':image-0'


class FakeDocker:
    def __init__(self, containers=True, images=True):
        self.containers = [CID] if containers else []
        self.images = [IID] if images else []
        self.name = '/' + LABEL + '-container-0'
        self.status, self.running, self.container_label = 'created', 'false', LABEL
        self.image = dict(id=IID, label=LABEL, tags=[TAG], digests=[])
        self.consumer = b''
        self.bound_id = IID
        self.calls, self.removed = [], []

    def run(self, *args, **kwargs):
        self.calls.append(args)
        data = b''
        if args == ('ps', '-aq', '--no-trunc', '--filter', 'label=codex.retention.test=' + LABEL):
            data = ('\n'.join(self.containers) + ('\n' if self.containers else '')).encode()
        elif args == ('image', 'ls', '-aq', '--no-trunc', '--filter', 'label=codex.retention.test=' + LABEL):
            data = ('\n'.join(self.images) + ('\n' if self.images else '')).encode()
        elif args[:2] == ('inspect', '--format'):
            assert args[-1] == CID
            data = (' '.join([CID, self.running, self.status, self.container_label, self.name]) + '\n').encode()
        elif args == ('container', 'rm', CID):
            self.removed.append(('container', CID))
            self.containers.remove(CID)
        elif args[:3] == ('image', 'inspect', '--format'):
            if args[3] == '{{.Id}}':
                data = (self.bound_id + '\n').encode()
            else:
                assert args[-1] == IID
                data = json.dumps(self.image).encode()
        elif args == ('ps', '-aq', '--no-trunc', '--filter', 'ancestor=' + IID):
            data = self.consumer
        elif args[:3] == ('image', 'rm', '--no-prune'):
            assert len(args) == 4
            target = args[3]
            assert target == IID or target in self.image['tags'], 'foreign_image_removal'
            self.removed.append(('image', target))
            if target == IID:
                self.images.remove(IID)
            else:
                self.image['tags'].remove(target)
                if not self.image['tags']:
                    self.images.remove(IID)
        elif args == ('image', 'ls', '-aq', '--no-trunc'):
            data = ('\n'.join(self.images) + ('\n' if self.images else '')).encode()
        else:
            raise AssertionError('unexpected_fake_docker_command')
        return data, b'', 0, {}


def prototype(fake):
    obj = MOD.InventoryCI.__new__(MOD.InventoryCI)
    obj.abort_check = None
    obj.setup_started = True
    obj.label = LABEL
    obj.namespace = LABEL
    obj.baseline = dict(containers=[], images=[])
    obj.baseline_bytes = b'baseline'
    obj.images = [IID]
    obj.containers = [CID]
    obj.summary = {}
    obj.docker_run = fake.run
    obj.snapshot = lambda variant: (b'baseline', obj.baseline, {})
    return obj


class TeardownTests(unittest.TestCase):
    def refusal(self, mutate, code, *, containers=False, images=False):
        fake = FakeDocker(containers=containers, images=images)
        obj = prototype(fake)
        mutate(obj, fake)
        with self.assertRaisesRegex(MOD.ClosedFailure, '^' + code + '$'):
            obj.teardown()
        self.assertEqual(fake.removed, [])

    def test_no_setup_means_no_docker_access(self):
        fake = FakeDocker()
        obj = prototype(fake)
        obj.setup_started = False
        obj.teardown()
        self.assertEqual(fake.calls, [])
        self.assertEqual(obj.summary['teardown'], 'NOT_NEEDED_NO_RESOURCE_CREATION')

    def test_exact_owned_resources_only(self):
        fake = FakeDocker()
        obj = prototype(fake)
        obj.teardown()
        self.assertEqual(fake.removed, [('container', CID), ('image', TAG)])
        self.assertEqual(obj.summary['teardown'], 'PASS_EXACT_OWNED_ONLY')
        forbidden = {'prune', 'down', 'stop', 'restart', 'start', 'kill', '--force', '-f', '--volumes'}
        self.assertFalse(any(forbidden.intersection(call) for call in fake.calls))

    def test_container_other_name_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'name', '/unrelated-service'), 'teardown_container_changed', containers=True)

    def test_container_index24_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'name', '/' + LABEL + '-container-24'), 'teardown_container_changed', containers=True)

    def test_running_container_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'running', 'true'), 'teardown_container_changed', containers=True)

    def test_exited_container_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'status', 'exited'), 'teardown_container_changed', containers=True)

    def test_container_wrong_label_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'container_label', 'foreign'), 'teardown_container_changed', containers=True)

    def test_preexisting_container_preserved(self):
        self.refusal(lambda obj, fake: obj.baseline['containers'].append({'id': CID}), 'teardown_container_scope', containers=True)

    def test_preexisting_image_preserved(self):
        self.refusal(lambda obj, fake: obj.baseline['images'].append({'id': IID}), 'teardown_image_scope', images=True)

    def test_image_other_namespace_preserved(self):
        self.refusal(lambda obj, fake: fake.image.update(tags=['foreign:image-0']), 'teardown_image_identity', images=True)

    def test_image_index146_preserved(self):
        self.refusal(lambda obj, fake: fake.image.update(tags=[LABEL + ':image-146']), 'teardown_image_identity', images=True)

    def test_image_arbitrary_same_namespace_tag_preserved(self):
        self.refusal(lambda obj, fake: fake.image.update(tags=[LABEL + ':unreviewed']), 'teardown_image_identity', images=True)

    def test_image_digest_preserved(self):
        self.refusal(lambda obj, fake: fake.image.update(digests=['example.invalid/repo@' + IID]), 'teardown_image_identity', images=True)

    def test_image_wrong_label_preserved(self):
        self.refusal(lambda obj, fake: fake.image.update(label='foreign'), 'teardown_image_identity', images=True)

    def test_image_consumer_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'consumer', (CID + '\n').encode()), 'teardown_image_has_consumer', images=True)

    def test_rebound_tag_preserved(self):
        self.refusal(lambda obj, fake: setattr(fake, 'bound_id', 'sha256:' + '9' * 64), 'teardown_tag_rebound', images=True)

    def test_untagged_owned_image_uses_exact_id(self):
        fake = FakeDocker(containers=False)
        fake.image['tags'] = []
        obj = prototype(fake)
        obj.teardown()
        self.assertEqual(fake.removed, [('image', IID)])

    def test_untagged_label_only_unknown_image_preserved(self):
        def mutate(obj, fake):
            obj.images = []
            fake.image['tags'] = []
        self.refusal(mutate, 'teardown_untagged_not_in_ledger', images=True)

    def test_all_fixture_aliases_removed_explicitly(self):
        fake = FakeDocker(containers=False)
        fake.image['tags'].append(LABEL + ':second-alias')
        obj = prototype(fake)
        obj.teardown()
        self.assertEqual(fake.removed, [('image', TAG), ('image', LABEL + ':second-alias')])

    def test_global_inventory_mismatch_is_failure(self):
        fake = FakeDocker(containers=False, images=False)
        obj = prototype(fake)
        obj.snapshot = lambda variant: (b'drift', obj.baseline, {})
        with self.assertRaisesRegex(MOD.ClosedFailure, '^original_inventory_not_restored$'):
            obj.teardown()
        self.assertNotEqual(obj.summary.get('teardown'), 'PASS_EXACT_OWNED_ONLY')


class SupervisorTests(unittest.TestCase):
    def run_supervised(self, script, *, allow_nonzero=False, expected=None, abort_check=None):
        original = MOD.subprocess.Popen
        launched = []

        def record(*args, **kwargs):
            process = original(*args, **kwargs)
            launched.append(process)
            return process

        MOD.subprocess.Popen = record
        started = time.monotonic()
        try:
            if expected is None:
                result = MOD.supervised(['bash', '-c', script], budget=4, allow_nonzero=allow_nonzero, abort_check=abort_check)
            else:
                with self.assertRaises(MOD.ClosedFailure) as caught:
                    MOD.supervised(['bash', '-c', script], budget=4, allow_nonzero=allow_nonzero, abort_check=abort_check)
                self.assertIn(str(caught.exception), expected)
                result = None
            self.assertLess(time.monotonic() - started, 4.5, 'supervisor_exceeded_budget')
            self.assertIs(MOD.LAST_SUPERVISOR.get('residual_owned_group'), False)
            for process in launched:
                self.assertIsNotNone(process.poll(), 'leader_not_reaped')
                with self.assertRaises(ProcessLookupError, msg='owned_group_still_exists'):
                    os.killpg(process.pid, 0)
            return result
        finally:
            MOD.subprocess.Popen = original
            # These exact groups were started by this local test with
            # start_new_session=True. Cleanup never signals any other process.
            for process in launched:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=2)

    def test_normal_success(self):
        result = self.run_supervised('printf safe')
        self.assertEqual(result[0], b'safe')
        self.assertEqual(result[2], 0)

    def test_nonzero_requires_explicit_acceptance(self):
        self.run_supervised('printf partial; exit 7', expected={'child_nonzero'})
        result = self.run_supervised('printf partial; exit 7', allow_nonzero=True)
        self.assertEqual(result[0], b'partial')
        self.assertEqual(result[2], 7)

    def test_pipe_holding_descendant_cannot_fake_success(self):
        self.run_supervised('sleep 30 & exit 0', expected={'whole_group_timeout', 'residual_owned_process_group', 'residual_owned_process_tree'})

    def test_silent_descendant_cannot_fake_success(self):
        self.run_supervised('sleep 30 >/dev/null 2>&1 & exit 0', expected={'residual_owned_process_group', 'residual_owned_process_tree'})

    def test_term_resistant_group_is_killed(self):
        self.run_supervised('trap "" TERM; sleep 30', expected={'whole_group_timeout'})

    def test_nested_foreground_timeout_stays_owned(self):
        self.run_supervised('timeout --foreground --signal=TERM --kill-after=1s 30s bash -c \'trap "" TERM; sleep 30\'', expected={'whole_group_timeout'})

    def test_abort_check_cleans_up(self):
        def abort():
            raise MOD.ClosedFailure('test_resource_abort')
        self.run_supervised('sleep 30', abort_check=abort, expected={'test_resource_abort'})

    def test_selector_initialization_failure_cleans_up(self):
        original = MOD.selectors.DefaultSelector

        class BrokenSelector:
            def register(self, *args):
                raise MOD.ClosedFailure('test_selector_abort')

            def close(self):
                pass

        MOD.selectors.DefaultSelector = BrokenSelector
        try:
            self.run_supervised('sleep 30', expected={'test_selector_abort'})
        finally:
            MOD.selectors.DefaultSelector = original

    def test_stdout_limit_cannot_accept_partial_result(self):
        self.run_supervised('head -c 16777217 /dev/zero', expected={'bounded_stream_exceeded'})

    def test_stderr_limit_cannot_accept_partial_result(self):
        self.run_supervised('head -c 65537 /dev/zero >&2', expected={'bounded_stream_exceeded'})


class ObserverLivenessTests(unittest.TestCase):
    def test_dead_observer_cannot_satisfy_check(self):
        # Construct only: no thread starts, no benchmark sleep, no Docker call.
        observer = MOD.ResourceObserver()
        self.assertFalse(observer.thread.is_alive())
        with self.assertRaises(MOD.ClosedFailure):
            observer.check()


class BenchmarkPreparationTests(unittest.TestCase):
    """Pure clock and scalar fixtures: no thread, wait, Docker or procfs access."""
    @staticmethod
    def sample(index):
        return dict(cpu_ticks=[10 * (index + 1), 0, 5 * (index + 1), 80 * (index + 1),
                               5 * (index + 1), 0, 0, 0], mem_available_kib=1000000,
                    mem_dirty_kib=10, mem_writeback_kib=0, cpu_some_avg10=0.2,
                    io_full_avg10=0.1, cpu_some_total_usec=index * 100,
                    io_full_total_usec=index * 10, daemon_cpu_ticks=100 + index,
                    daemon_cpu_status='AVAILABLE')

    def execute(self, *, mutate=None, deadline=3000, sleep_delta=0, sample_duration=0,
                sample_error=None, expected=None):
        state = dict(now=0.0, samples=0, sleeps=0)
        published = []

        def clock():
            return state['now']

        def sleep(seconds):
            state['sleeps'] += 1
            state['now'] += seconds + sleep_delta

        def sampler():
            index = state['samples']
            state['samples'] += 1
            if sample_error is not None and index == sample_error:
                raise OSError('private diagnostic must not be published')
            value = self.sample(index)
            if mutate:
                mutate(index, value)
            state['now'] += sample_duration
            return value

        if expected:
            with self.assertRaisesRegex(MOD.ClosedFailure, '^' + expected + '$'):
                MOD.collect_benchmark_preparation(deadline=deadline, publish=published.append,
                    clock=clock, sleep=sleep, sampler=sampler)
        else:
            result = MOD.collect_benchmark_preparation(deadline=deadline, publish=published.append,
                clock=clock, sleep=sleep, sampler=sampler)
            self.assertIs(result, published[-1])
        self.assertEqual(len(published), 1, 'partial diagnostic must be published exactly once')
        self.assertNotIn('private diagnostic', json.dumps(published))
        return published[-1], state

    def test_exact_seven_slots_and_fresh_admission(self):
        report, state = self.execute()
        self.assertEqual(state, dict(now=60.0, samples=7, sleeps=6))
        self.assertEqual(report['admission'], 'PASS')
        self.assertEqual(report['early_health'], 'NO_THRESHOLD_VIOLATIONS')
        self.assertEqual([row['scheduled_offset_seconds'] for row in report['samples']], list(range(0, 61, 10)))
        self.assertEqual(report['tail_sample_indices'], [3, 4, 5, 6])
        self.assertEqual(report['tail_actual_start_span_seconds'], 30)
        self.assertEqual(report['limits'], MOD.LIMITS)

    def test_early_violation_is_permanent_warning_not_erased(self):
        report, state = self.execute(mutate=lambda i, row: row.update(io_full_avg10=1.47) if i == 0 else None)
        self.assertEqual(report['admission'], 'PASS')
        self.assertEqual(report['early_health'], 'WARN_UNSTABLE_PREPARATION')
        self.assertEqual(report['early_exceedances'][0]['io_full_avg10'], 1.47)
        self.assertEqual(report['samples'][0]['violations'], ['max_io_full_avg10'])
        self.assertEqual(state['samples'], 7)

    def test_each_tail_violation_blocks_without_extending(self):
        for bad in (3, 4, 5, 6):
            with self.subTest(bad=bad):
                report, state = self.execute(mutate=lambda i, row: row.update(io_full_avg10=1.01) if i == bad else None,
                                             expected='preparation_tail_threshold_failed')
                self.assertEqual(state['samples'], 7)
                self.assertEqual(state['now'], 60)
                self.assertEqual(report['admission'], 'FAIL')
                self.assertFalse(report['tail_admitted'])

    def test_late_start_fails_without_catchup(self):
        report, state = self.execute(sleep_delta=1.01, expected='preparation_sample_late')
        self.assertEqual(state['samples'], 1)
        self.assertEqual(len(report['samples']), 1)
        self.assertEqual(report['attempts'][-1]['actual_start_monotonic'], 11.01)

    def test_slow_sample_keeps_row_and_fails(self):
        report, state = self.execute(sample_duration=1.01, expected='preparation_sample_late')
        self.assertEqual(state['samples'], 1)
        self.assertEqual(report['samples'][0]['actual_end_monotonic'], 1.01)

    def test_early_wakeup_does_not_sample_or_retry(self):
        report, state = self.execute(sleep_delta=-0.01, expected='preparation_sample_early')
        self.assertEqual(state['samples'], 1)

    def test_one_second_tolerance_is_disclosed_not_continuous_health(self):
        report, state = self.execute(sample_duration=1)
        self.assertEqual(report['admission'], 'PASS')
        self.assertEqual(state['now'], 61)
        self.assertFalse(report['continuous_health_claim'])

    def test_budget_fails_before_sampler(self):
        report, state = self.execute(deadline=2704.99, expected='preparation_budget')
        self.assertEqual(state['samples'], 0)
        self.assertEqual(report['samples'], [])

    def test_exact_budget_boundary_accepts(self):
        self.assertEqual(self.execute(deadline=2705)[0]['admission'], 'PASS')

    def test_source_failure_preserves_partial_safe_evidence(self):
        report, state = self.execute(sample_error=2, expected='preparation_source_failed')
        self.assertEqual(len(report['samples']), 2)
        self.assertEqual(report['failure'], 'preparation_source_failed')
        self.assertEqual(report['attempts'][-1]['index'], 2)

    def test_invalid_scalars_fail_closed(self):
        for key, value in [('io_full_avg10', float('nan')), ('cpu_some_avg10', float('inf')),
                           ('mem_available_kib', True), ('mem_dirty_kib', -1),
                           ('cpu_some_total_usec', '1'), ('daemon_cpu_ticks', -1),
                           ('daemon_cpu_status', 'MISSING'), ('cpu_ticks', [0] * 7),
                           ('cpu_ticks', [True] + [0] * 7)]:
            with self.subTest(key=key, value=value):
                self.execute(mutate=lambda i, row: row.update({key: value}), expected='preparation_sample_invalid')

    def test_missing_or_extra_fields_fail(self):
        self.execute(mutate=lambda i, row: row.pop('io_full_avg10'), expected='preparation_sample_invalid')
        self.execute(mutate=lambda i, row: row.update(unknown=1), expected='preparation_sample_invalid')

    def test_cpu_delta_is_required_after_first(self):
        report, state = self.execute(mutate=lambda i, row: row.update(cpu_ticks=[10, 0, 5, 80, 5, 0, 0, 0]),
                                     expected='preparation_cpu_delta_invalid')
        self.assertEqual(len(report['samples']), 1)
        self.assertIsNone(report['samples'][0]['cpu_busy_percent'])

    def test_all_unchanged_limits_are_used(self):
        values = dict(cpu_some_avg10=10.01, io_full_avg10=1.01, mem_available_kib=131071)
        for key, value in values.items():
            with self.subTest(key=key):
                self.execute(mutate=lambda i, row: row.update({key: value}) if i == 4 else None,
                             expected='preparation_tail_threshold_failed')

    def test_cpu_busy_and_iowait_thresholds_are_not_weakened(self):
        for ticks in ([100, 0, 5, 1, 0, 0, 0, 0], [10, 0, 5, 50, 35, 0, 0, 0]):
            with self.subTest(ticks=ticks):
                self.execute(mutate=lambda i, row: row.update(cpu_ticks=[x * (i + 1) for x in ticks]),
                             expected='preparation_tail_threshold_failed')

    def test_individual_cpu_regression_cannot_hide_in_growing_total(self):
        self.execute(mutate=lambda i, row: row.update(cpu_ticks=[9, 0, 20, 160, 10, 0, 0, 0]) if i == 1 else None,
                     expected='preparation_cpu_delta_invalid')

    def test_outer_deadline_cannot_extend_to_find_healthy_samples(self):
        report, state = self.execute(sleep_delta=66, expected='preparation_deadline')
        self.assertEqual(state['samples'], 1)
        self.assertEqual(report['admission'], 'FAIL')

    def test_interruption_publishes_without_retry(self):
        published = []
        with self.assertRaisesRegex(MOD.ClosedFailure, '^preparation_interrupted$'):
            MOD.collect_benchmark_preparation(deadline=3000, publish=published.append,
                clock=lambda: 0, sleep=lambda unused: None,
                sampler=lambda: (_ for _ in ()).throw(KeyboardInterrupt()))
        self.assertEqual(len(published), 1)
        self.assertEqual(published[0]['failure'], 'preparation_interrupted')

    def test_invalid_initial_clock_never_reaches_json(self):
        for value in (float('nan'), float('inf'), True, -1):
            published = []
            with self.subTest(value=value), self.assertRaises(MOD.ClosedFailure):
                MOD.collect_benchmark_preparation(deadline=3000, publish=published.append,
                    clock=lambda: value, sampler=lambda: self.fail('unexpected sample'))
            self.assertEqual(len(published), 1)
            self.assertIsNone(published[0]['started_monotonic'])
            self.assertIsNone(published[0]['ended_monotonic'])
            json.dumps(published, allow_nan=False)

    def test_final_envelope_cannot_overwrite_success_with_invalid_or_late_time(self):
        for final in (float('nan'), float('inf'), 65.01):
            state = dict(now=0.0, samples=0, end_reads=0)
            published = []

            def clock():
                if state['samples'] == 7:
                    state['end_reads'] += 1
                    if state['end_reads'] == 3:
                        return final
                return state['now']

            def sample():
                value = self.sample(state['samples'])
                state['samples'] += 1
                return value

            with self.subTest(final=final), self.assertRaises(MOD.ClosedFailure):
                MOD.collect_benchmark_preparation(deadline=3000, publish=published.append,
                    clock=clock, sleep=lambda seconds: state.update(now=state['now'] + seconds), sampler=sample)
            self.assertEqual(published[0]['status'], 'FAIL')
            self.assertEqual(published[0]['admission'], 'FAIL')
            self.assertFalse(published[0]['tail_admitted'])
            json.dumps(published, allow_nan=False)

    def test_final_clock_exception_still_publishes_safe_partial_evidence(self):
        calls, published = [], []

        def clock():
            calls.append(1)
            if len(calls) > 1:
                raise OSError('private final clock detail')
            return 0

        with self.assertRaises(MOD.ClosedFailure):
            MOD.collect_benchmark_preparation(deadline=2704, publish=published.append,
                clock=clock, sampler=lambda: self.fail('unexpected sample'))
        self.assertEqual(len(published), 1)
        self.assertNotIn('private', json.dumps(published, allow_nan=False))


class PreparationSourceTests(unittest.TestCase):
    def source(self, replacements=None):
        contents = {'/proc/stat': 'cpu 10 0 5 80 5 0 0 0 0 0\n',
                    '/proc/meminfo': 'MemAvailable: 1000000 kB\nDirty: 10 kB\nWriteback: 0 kB\n',
                    '/proc/pressure/cpu': 'some avg10=0.20 avg60=0.10 avg300=0.01 total=20\n',
                    '/proc/pressure/io': 'some avg10=0.20 avg60=0.10 avg300=0.01 total=20\nfull avg10=0.10 avg60=0.01 avg300=0.00 total=10\n'}
        contents.update(replacements or {})
        with patch.object(MOD, 'read_preparation_proc', side_effect=lambda path: contents[str(path)]), \
                patch.object(MOD, 'daemon_identity', return_value=None):
            return MOD.PreparationSampler().sample()

    def test_safe_selected_proc_scalars(self):
        value = self.source()
        self.assertEqual(value['cpu_ticks'], [10, 0, 5, 80, 5, 0, 0, 0])
        self.assertEqual(value['io_full_total_usec'], 10)
        self.assertEqual(value['mem_dirty_kib'], 10)
        self.assertEqual(value['daemon_cpu_status'], 'NOT_AVAILABLE')

    def test_bad_proc_fields_are_rejected(self):
        for path, text in [('/proc/stat', 'cpu -1 0 5 80 5 0 0 0\n'),
                           ('/proc/meminfo', 'MemAvailable: 1 MB\nDirty: 0 kB\nWriteback: 0 kB\n'),
                           ('/proc/meminfo', 'MemAvailable: 1 kB\nDirty: 0 kB\nDirty: 0 kB\nWriteback: 0 kB\n'),
                           ('/proc/pressure/io', 'full avg10=nan avg60=0 avg300=0 total=1\n'),
                           ('/proc/pressure/io', 'full avg10=0 avg60=0 avg300=0 total=-1\n')]:
            with self.subTest(path=path, text=text), self.assertRaises(MOD.ClosedFailure):
                self.source({path: text})


class PreparationWiringTests(unittest.TestCase):
    def fixture(self, directory, *, preparation_failure=False, formal_failure=False):
        obj = MOD.InventoryCI.__new__(MOD.InventoryCI)
        obj.summary = dict(checkout_sha='a' * 40, sources={'ci_harness_sha256': 'b' * 64})
        obj.publication = Path(directory)
        obj.preparation_attempted = False
        obj.deadline = time.monotonic() + 3000
        obj.approved_snapshot = b'fixture'
        obj.abort_check = None
        events, instances = [], []

        def prepare(**kwargs):
            events.append('prepare')
            report = dict(early_health='WARN_UNSTABLE_PREPARATION', early_exceedances=[dict(io_full_avg10=1.47)],
                          admission='FAIL' if preparation_failure else 'PASS')
            kwargs['publish'](report)
            if preparation_failure:
                raise MOD.ClosedFailure('preparation_tail_threshold_failed')
            return report

        class Observer:
            def __init__(self):
                self.rows, self.previous, self.failure = [], None, None
                self.thread = type('Thread', (), {'start': lambda unused: events.append('observer_start')})()
                instances.append(self)

            def wait(self, seconds, deadline):
                events.append(('wait', seconds))
                if formal_failure:
                    raise MOD.ClosedFailure('formal_resource_failed')

            def check(self):
                pass

            def close(self):
                events.append('observer_close')

            def coverage(self, *args):
                return {'synthetic_coverage': True}

        def snapshot(variant):
            events.append(('snapshot', variant))
            return b'fixture', {}, {'variant': variant}

        obj.snapshot = snapshot
        obj.setup = lambda: events.append('setup')
        obj.correctness = lambda: events.append('correctness')
        obj.teardown = lambda: events.append('teardown')
        return obj, events, instances, prepare, Observer

    def test_benchmark_prepares_once_then_creates_five_fresh_observers(self):
        with tempfile.TemporaryDirectory(prefix='image-preparation-wiring.') as directory:
            obj, events, instances, prepare, observer = self.fixture(directory)
            with patch.object(MOD, 'collect_benchmark_preparation', side_effect=prepare), \
                    patch.object(MOD, 'ResourceObserver', observer):
                obj.benchmark()
                with self.assertRaisesRegex(MOD.ClosedFailure, '^preparation_may_run_once$'):
                    obj.benchmark()
            self.assertEqual(events.count('prepare'), 1)
            self.assertEqual(events[0], 'prepare')
            self.assertEqual(len(instances), 5)
            self.assertEqual(len({id(item.rows) for item in instances}), 5)
            self.assertTrue(all(item.previous is None for item in instances))
            self.assertEqual([item for item in events if isinstance(item, tuple) and item[0] == 'snapshot'],
                             [('snapshot', 'old'), ('snapshot', 'new'), ('snapshot', 'new'), ('snapshot', 'old')])
            self.assertEqual([item[1] for item in events if isinstance(item, tuple) and item[0] == 'wait'], [120, 300] * 5)
            self.assertEqual(obj.summary['preparation']['early_exceedances'][0]['io_full_avg10'], 1.47)

    def test_failed_preparation_never_starts_formal_observer_or_snapshot(self):
        with tempfile.TemporaryDirectory(prefix='image-preparation-wiring.') as directory:
            obj, events, instances, prepare, observer = self.fixture(directory, preparation_failure=True)
            with patch.object(MOD, 'collect_benchmark_preparation', side_effect=prepare), \
                    patch.object(MOD, 'ResourceObserver', observer), self.assertRaises(MOD.ClosedFailure):
                obj.benchmark()
            self.assertEqual(events, ['prepare'])
            self.assertEqual(instances, [])
            self.assertEqual(obj.summary['benchmark'], 'NOT_RUN_PREPARATION_REJECTED')
            self.assertEqual(json.loads((Path(directory) / 'preparation.json').read_text())['admission'], 'FAIL')

    def test_slow_preparation_publication_blocks_formal_entry(self):
        with tempfile.TemporaryDirectory(prefix='image-preparation-wiring.') as directory:
            obj, events, instances, prepare, observer = self.fixture(directory)
            obj.deadline = 2705
            state = dict(now=60.0)
            real_write = MOD.write_json

            def slow_publish(path, value):
                real_write(path, value)
                state['now'] = 65.01

            with patch.object(MOD, 'collect_benchmark_preparation', side_effect=prepare), \
                    patch.object(MOD, 'ResourceObserver', observer), patch.object(MOD, 'write_json', slow_publish), \
                    patch.object(MOD.time, 'monotonic', side_effect=lambda: state['now']), \
                    self.assertRaisesRegex(MOD.ClosedFailure, '^preparation_remaining_budget_after_publication$'):
                obj.benchmark()
            self.assertEqual(events, ['prepare'])
            self.assertEqual(instances, [])
            self.assertEqual(obj.summary['benchmark'], 'NOT_RUN_PREPARATION_REJECTED')
            self.assertEqual(obj.summary['preparation_formal_entry']['status'], 'FAIL')

    def test_preparation_publication_failure_never_starts_formal_observer(self):
        with tempfile.TemporaryDirectory(prefix='image-preparation-wiring.') as directory:
            obj, events, instances, prepare, observer = self.fixture(directory)
            with patch.object(MOD, 'collect_benchmark_preparation', side_effect=prepare), \
                    patch.object(MOD, 'ResourceObserver', observer), \
                    patch.object(MOD, 'write_json', side_effect=OSError('private disk error')), \
                    self.assertRaises(Exception):
                obj.benchmark()
            self.assertEqual(events, ['prepare'])
            self.assertEqual(instances, [])
            self.assertEqual(obj.summary['benchmark'], 'NOT_RUN_PREPARATION_REJECTED')

    def test_correctness_main_has_no_preparation(self):
        with tempfile.TemporaryDirectory(prefix='image-preparation-wiring.') as directory:
            obj, events, instances, prepare, observer = self.fixture(directory)
            with patch.object(MOD, 'InventoryCI', return_value=obj), \
                    patch.object(MOD, 'collect_benchmark_preparation', side_effect=AssertionError('unexpected preparation')), \
                    patch.object(MOD, 'ResourceObserver', side_effect=AssertionError('unexpected observer')), \
                    patch.object(MOD.signal, 'signal'), patch.object(sys, 'argv', ['synthetic-test']), patch('builtins.print'):
                MOD.main()
            self.assertEqual(events, ['setup', 'correctness', 'teardown'])
            self.assertNotIn('preparation', obj.summary)

    def test_early_warning_survives_formal_failure_and_teardown_failure(self):
        with tempfile.TemporaryDirectory(prefix='image-preparation-wiring.') as directory:
            obj, events, instances, prepare, observer = self.fixture(directory, formal_failure=True)
            obj.teardown = lambda: (_ for _ in ()).throw(MOD.ClosedFailure('synthetic_teardown_failure'))
            with patch.object(MOD, 'InventoryCI', return_value=obj), \
                    patch.object(MOD, 'collect_benchmark_preparation', side_effect=prepare), \
                    patch.object(MOD, 'ResourceObserver', observer), patch.object(MOD.signal, 'signal'), \
                    patch.object(sys, 'argv', ['synthetic-test', '--mode', 'benchmark']), \
                    self.assertRaisesRegex(MOD.ClosedFailure, '^formal_resource_failed$'):
                MOD.main()
            summary = json.loads((Path(directory) / 'summary.json').read_text())
            self.assertEqual(summary['status'], 'FAIL')
            self.assertEqual(summary['teardown'], 'FAIL_RETAIN_AMBIGUOUS_RESOURCES')
            self.assertEqual(summary['preparation']['early_health'], 'WARN_UNSTABLE_PREPARATION')
            self.assertEqual(summary['preparation']['early_exceedances'][0]['io_full_avg10'], 1.47)
            self.assertEqual(len(instances), 1)


class CorrectnessContractTests(unittest.TestCase):
    def test_old_one_listing_new_two_listings(self):
        for images, inspections in [(1, 1), (15, 1), (16, 1), (17, 2), (146, 10), (256, 16)]:
            with self.subTest(images=images):
                self.assertEqual(MOD.snapshot_call_contract('old', 24, images),
                                 dict(container_list=1, container_inspect=24, image_ls=1, image_inspect=images))
                self.assertEqual(MOD.snapshot_call_contract('new', 24, images),
                                 dict(container_list=1, container_inspect=24, image_ls=2, image_inspect=inspections))

    def test_bad_count_contract_rejected(self):
        for args in [('unknown', 24, 146), ('new', -1, 146), ('old', 24, 0)]:
            with self.subTest(args=args), self.assertRaises(MOD.ClosedFailure):
                MOD.snapshot_call_contract(*args)

    def test_full_correctness_positive_accepts_two_new_listings(self):
        obj = MOD.InventoryCI.__new__(MOD.InventoryCI)
        obj.baseline = dict(containers=[], images=[])
        obj.images = ['sha256:' + format(i + 100, '064x') for i in range(146)]
        obj.containers = [format(i + 1, '064x') for i in range(24)]
        value = dict(containers=[dict(id=x, status='created') for x in obj.containers],
                     images=[dict(id=x) for x in obj.images])

        def snapshot(variant):
            metric = dict(variant=variant, docker_calls=dict(container_list=1, container_inspect=24,
                          image_ls=1 if variant == 'old' else 2, image_inspect=146 if variant == 'old' else 10))
            return b'same-canonical-snapshot', value, metric

        def docker_run(*args, **kwargs):
            self.assertEqual(args[:2], ('image', 'inspect'))
            if args[-2] == obj.images[0]:
                return json.dumps(dict(Id=obj.images[0])).encode(), b'', 1, {}
            return b'', b'', 1, {}

        obj.snapshot = snapshot
        obj.docker_run = docker_run
        obj.inventory = Path(__file__).resolve().with_name('images-only-inventory.sh')
        obj.summary = {'measurements': []}
        obj.correctness()
        self.assertEqual(obj.summary['correctness'], 'PASS')
        self.assertEqual(len(obj.summary['measurements']), 2)


class ObserverCoverageTests(unittest.TestCase):
    def coverage(self, times=None, bounds=(0, 120, 140, 440)):
        observer = MOD.ResourceObserver.__new__(MOD.ResourceObserver)
        observer.rows = [{'monotonic': t} for t in (range(0, 441, 10) if times is None else times)]
        return observer.coverage(*bounds)

    def rejection(self, times=None, bounds=(0, 120, 140, 440), code=None):
        with self.assertRaisesRegex(MOD.ClosedFailure, '^' + code + '$' if code else '^[a-z_]+$'):
            self.coverage(times, bounds)

    def test_complete_120_and300_sampled_windows(self):
        result = self.coverage()
        self.assertEqual(result['all']['samples'], 45)
        self.assertEqual(result['baseline']['samples'], 13)
        self.assertEqual(result['post']['samples'], 31)
        self.assertTrue(all(x['max_gap_seconds'] == 10 for x in result.values()))

    def test_observer_only_has_full_windows(self):
        result = self.coverage(range(0, 421, 10), (0, 120, 120, 420))
        self.assertEqual(result['all']['samples'], 43)

    def test_empty_samples_rejected(self):
        self.rejection([], code='observation_timestamps_invalid')

    def test_short_baseline_rejected(self):
        self.rejection(bounds=(0, 119, 140, 440), code='observation_window_incomplete')

    def test_short_post_rejected(self):
        self.rejection(bounds=(0, 120, 141, 440), code='observation_window_incomplete')

    def test_regressed_samples_rejected(self):
        times = list(range(0, 441, 10))
        times[2], times[3] = times[3], times[2]
        self.rejection(times, code='observation_timestamps_invalid')

    def test_duplicate_sample_time_rejected(self):
        self.rejection([0] + list(range(0, 441, 10)), code='observation_timestamps_invalid')

    def test_nonfinite_samples_rejected(self):
        times = list(range(0, 441, 10))
        times[3] = float('nan')
        self.rejection(times, code='observation_timestamps_invalid')

    def test_samples_outside_window_rejected(self):
        self.rejection([-1] + list(range(0, 441, 10)), code='observation_timestamps_invalid')
        self.rejection(list(range(0, 441, 10)) + [441], code='observation_timestamps_invalid')

    def test_interior_gap40_rejected_even_with_enough_samples(self):
        times = sorted([t for t in range(0, 441, 10) if t not in (30, 40, 50)] + [11, 12, 13])
        self.rejection(times, code='observation_sample_gap')

    def test_first_gap31_rejected_even_with_enough_samples(self):
        times = sorted(list(range(40, 441, 10)) + [31, 32, 33, 34])
        self.rejection(times, code='observation_sample_gap')

    def test_tail_gap31_rejected_even_with_enough_samples(self):
        times = list(range(0, 401, 10)) + [406, 407, 408, 409]
        self.rejection(times, code='observation_sample_gap')

    def test_gap30_boundary_allowed(self):
        times = sorted([t for t in range(0, 441, 10) if t not in (30, 40)] + [11, 12])
        self.assertEqual(self.coverage(times)['all']['max_gap_seconds'], 30)

    def test_all_sample_count_shortage_rejected(self):
        self.rejection(range(0, 441, 20), code='observation_sample_count')

    def test_baseline_sample_shortage_not_hidden_by_dense_post(self):
        times = sorted([t for t in range(0, 441, 10) if t not in (10, 20)] + [141, 142])
        self.rejection(times, code='observation_sample_count')

    def test_post_sample_shortage_not_hidden_by_dense_baseline(self):
        times = sorted([t for t in range(0, 441, 10) if t not in (150, 160)] + [11, 12])
        self.rejection(times, code='observation_sample_count')



class EnvironmentGuardTests(unittest.TestCase):
    def test_fail_closed_before_any_process_or_docker_lookup(self):
        base = dict(GITHUB_ACTIONS='true', CI='true', RUNNER_OS='Linux', RUNNER_ENVIRONMENT='github-hosted',
                    GITHUB_REPOSITORY='Hao-Monster/Xboardme', IMAGE_INVENTORY_CI_ALLOW_LOCAL='correctness')
        cases = [({}, 'not_fixed_github_hosted_ci')]
        for key in ('GITHUB_ACTIONS', 'CI', 'RUNNER_OS', 'RUNNER_ENVIRONMENT', 'GITHUB_REPOSITORY'):
            cases.append((dict(base, **{key: 'wrong'}), 'not_fixed_github_hosted_ci'))
        cases += [
            (dict(base, IMAGE_INVENTORY_CI_ALLOW_LOCAL='benchmark'), 'explicit_opt_in_missing'),
            (dict(base, DOCKER_HOST='tcp://synthetic.invalid'), 'docker_override_forbidden'),
            (dict(base, BASH_ENV='/synthetic/not-executed'), 'harness_override_forbidden'),
        ]
        for environment, reason in cases:
            with self.subTest(reason=reason, environment=environment), patch.dict(os.environ, environment, clear=True), \
                    patch.object(MOD.subprocess, 'Popen', side_effect=AssertionError('unexpected_process')) as launched, \
                    patch.object(MOD.shutil, 'which', side_effect=AssertionError('unexpected_lookup')) as lookup:
                with self.assertRaisesRegex(MOD.ClosedFailure, '^' + reason + '$'):
                    MOD.InventoryCI('correctness')
                self.assertFalse(launched.called)
                self.assertFalse(lookup.called)


class ProductionSourceBindingTests(unittest.TestCase):
    def test_harness_calls_production_without_copy_or_override(self):
        obj = MOD.InventoryCI.__new__(MOD.InventoryCI)
        with tempfile.TemporaryDirectory(prefix='image-inventory-harness-test.') as temporary:
            obj.root = Path(temporary)
            obj.docker = '/synthetic/never-executed-docker'
            obj.env = dict(os.environ)
            obj.summary = {}
            obj.inventory = SOURCE.with_name('images-only-inventory.sh')
            obj.prepare_harness()
            child = obj.child.read_text()
            self.assertIn('source "$2"', child)
            self.assertIn('new) images_only_all_snapshots ;;', child)
            self.assertIn('old) retention_all_snapshots ;;', child)
            self.assertNotIn('retention_all_snapshots()', child)
            self.assertNotIn('images_only_all_snapshots()', child)
            self.assertNotIn('adapter', child)
            for path in (obj.child, obj.fixture / 'bin/docker'):
                result = MOD.supervised(['bash', '--noprofile', '--norc', '-n', str(path)], budget=4)
                self.assertEqual(result[2], 0)


class ProductionWatchdogIntegrationTests(unittest.TestCase):
    """Run the final Bash helper, then independently check its separate group.

    The fake Docker only sleeps; it cannot contact a daemon. No production
    function is copied or overridden. The outer Python group's success/failure
    flag is not used as a substitute for checking the inner group.
    """
    def exercise(self, entry, cancellation):
        with tempfile.TemporaryDirectory(prefix='image-inventory-linked-test.') as temporary:
            root = Path(temporary)
            group_file = root / 'group'
            group_prefix = ('read -r item < "/proc/$BASHPID/stat"; item=${item##*) }; '
                            'read -r state parent group rest <<< "$item"; '
                            'printf "%s\\n" "$group" > "$IMAGE_TEST_GROUP_FILE"\n')
            env = dict(os.environ, IMAGE_TEST_GROUP_FILE=str(group_file))
            if entry == 'primitive':
                (root / 'worker').write_text('set -Eeuo pipefail\n' + group_prefix +
                                            'trap "" TERM\nsleep 30 & wait\n')
                code = 'source "$3"; ( _images_only_supervise "$1" "$(_images_only_now_ms)" 120000 )'
            else:
                binary = root / 'bin'
                binary.mkdir()
                fake = binary / 'docker'
                fake.write_text('#!/usr/bin/env bash\nset -Eeuo pipefail\n' + group_prefix +
                                'trap "" TERM\nsleep 30 & wait\n')
                fake.chmod(0o700)
                env['PATH'] = str(binary) + ':' + env['PATH']
                code = 'source "$2"; source "$3"; images_only_all_snapshots'
            launched = []
            original = MOD.subprocess.Popen
            ancestry = []

            def record(*args, **kwargs):
                process = original(*args, **kwargs)
                launched.append(process)
                return process

            sent = False

            def interrupt():
                nonlocal sent
                if not group_file.exists():
                    return
                if not ancestry:
                    current = int(group_file.read_text().strip())
                    for unused in range(4):
                        try:
                            raw = Path('/proc/%d/stat' % current).read_text()
                            fields = raw.rsplit(') ', 1)[1].split()
                            ancestry.append((current, fields[0], int(fields[1]), int(fields[2])))
                            current = int(fields[1])
                        except (FileNotFoundError, ProcessLookupError):
                            break
                if cancellation == 'abort':
                    raise MOD.ClosedFailure('linked_observer_abort')
                if cancellation == 'hup' and not sent:
                    sent = True
                    os.killpg(launched[0].pid, signal.SIGHUP)

            group = None
            started = time.monotonic()
            try:
                with patch.object(MOD.subprocess, 'Popen', side_effect=record):
                    with self.assertRaises(MOD.ClosedFailure):
                        MOD.supervised(['bash', '--noprofile', '--norc', '-c', code, 'linked-test', str(root),
                                        str(SOURCE.with_name('retention-safety.sh')),
                                        str(SOURCE.with_name('images-only-inventory.sh'))],
                                       env=env, budget=4, abort_check=interrupt)
                self.assertTrue(group_file.exists(), 'production worker not reached')
                group = int(group_file.read_text().strip())
                self.assertGreater(group, 1)
                self.assertNotEqual(group, os.getpgrp())
                self.assertLess(time.monotonic() - started, 4.5)
                try:
                    os.killpg(group, 0)
                except ProcessLookupError:
                    pass
                else:
                    members = []
                    for stat in Path('/proc').glob('[0-9]*/stat'):
                        try:
                            fields = stat.read_text().rsplit(') ', 1)[1].split()
                            if int(fields[2]) == group:
                                members.append((int(stat.parent.name), fields[0], fields[1]))
                        except (FileNotFoundError, ProcessLookupError):
                            pass
                    self.fail('nested production group survived CI return: ' + str(members) +
                              '; original ancestors=' + str(ancestry) +
                              '; CI pid=' + str(launched[0].pid) + '; supervisor=' + str(MOD.LAST_SUPERVISOR))
                self.assertFalse(MOD.LAST_SUPERVISOR.get('residual_owned_group', True))
                for process in launched:
                    self.assertIsNotNone(process.poll(), 'CI leader not reaped')
                    with self.assertRaises(ProcessLookupError):
                        os.killpg(process.pid, 0)
            finally:
                # Only exact IDs returned by this test's Popen and fake worker.
                if group is None and group_file.exists():
                    group = int(group_file.read_text().strip())
                for group_id in [group, *(process.pid for process in launched)]:
                    if group_id is not None and group_id > 1 and group_id != os.getpgrp():
                        try:
                            os.killpg(group_id, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                for process in launched:
                    process.wait(timeout=2)

    def test_primitive_ci_timeout(self):
        self.exercise('primitive', 'budget')

    def test_primitive_ci_observer_abort(self):
        self.exercise('primitive', 'abort')

    def test_public_entry_ci_timeout(self):
        self.exercise('public', 'budget')

    def test_public_entry_ci_observer_abort(self):
        self.exercise('public', 'abort')

    def test_public_entry_external_hup(self):
        self.exercise('public', 'hup')


if __name__ == '__main__':
    unittest.main(argv=[sys.argv[0], *OPTIONS.case], verbosity=2)
