#!/usr/bin/env python3
"""Offline coordinator contracts; synthetic clocks/fixtures, never real Docker."""
import copy
import ctypes
import importlib.util
import json
import os
from pathlib import Path
import sys
import signal
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
SOURCE = HERE / 'image-inventory-diagnostic.py'


def load():
    if not SOURCE.is_file():
        raise AssertionError('same-runner diagnostic coordinator is not implemented')
    spec = importlib.util.spec_from_file_location('diagnostic_under_test', SOURCE)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Clock:
    def __init__(self):
        self.now = 0.0
        self.extra_sleep = 0.0

    def __call__(self):
        return self.now

    def sleep(self, seconds):
        self.now += seconds + self.extra_sleep


def raw(index):
    n = index + 1
    return dict(cpu_ticks=[10*n, 0, 5*n, 80*n, 5*n, 0, 0, 0],
                mem_available_kib=1000000, mem_dirty_kib=10, mem_writeback_kib=0,
                cpu_some_avg10=0.2, io_full_avg10=0.1,
                cpu_some_total_usec=index*100, io_full_total_usec=index*10,
                daemon_cpu_ticks=100+index, daemon_cpu_status='AVAILABLE')


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.mod = load()

    def exercise(self, *, mutate=None, command_failure=None, setup_failure=False,
                 correctness_failure=False, teardown_failure=False, publish_delay=None,
                 sample_duration=0, sleep_extra=0, workload_duration=1, identity_failure=False,
                 cancel_publication=None):
        mod, clock, events, artifacts = self.mod, Clock(), [], {}
        clock.extra_sleep = sleep_extra
        samples = []

        def sample():
            index = len(samples)
            value = raw(index)
            samples.append(index)
            if mutate:
                mutate(index, value)
            clock.now += sample_duration
            return value

        class Runner:
            def __init__(self):
                self.deadline = 3600
                self.setup_started = False
                self.summary = dict(teardown='NOT_RUN', sources={})

            def setup(self):
                events.append('setup')
                self.setup_started = True
                clock.now += workload_duration
                if setup_failure:
                    raise mod.CI.ClosedFailure('synthetic_setup_failure')

            def correctness(self):
                events.append('correctness')
                clock.now += workload_duration
                if correctness_failure:
                    raise RuntimeError('private canary path must not be published')
                self.summary.update(correctness='PASS', fixture_counts=dict(images=146, containers=24))

            def teardown(self):
                events.append('teardown')
                self.deadline = clock.now + 300
                clock.now += 2
                if teardown_failure:
                    raise RuntimeError('private teardown detail')
                self.summary['teardown'] = ('PASS_EXACT_OWNED_ONLY' if self.setup_started else
                                            'NOT_NEEDED_NO_RESOURCE_CREATION')

        runner = Runner()

        def command(name, argv, deadline):
            events.append(name)
            clock.now += workload_duration
            return 7 if command_failure == name else 0

        def identity(deadline):
            events.append('identity')
            if identity_failure:
                raise mod.CI.ClosedFailure('diagnostic_identity_invalid')
            return dict(checkout_sha='a'*40, workflow_sha='a'*40, kernel_release='synthetic', sources={})

        def publish(name, value, deadline):
            artifacts[name] = copy.deepcopy(value)
            if name == cancel_publication and coordinator.report['publication_phase'] == 'pre-teardown':
                coordinator.cancel_requested = True
            if publish_delay:
                publish_delay(name, value, clock)

        coordinator = mod.Coordinator(clock=clock, sleep=clock.sleep, sampler=sample,
            runner_factory=lambda: runner, command_runner=command, identity_reader=identity,
            publisher=publish, environment_guard=lambda: None)
        with patch.object(mod.CI, 'collect_benchmark_preparation', side_effect=AssertionError('formal preparation forbidden')), \
                patch.object(mod.CI, 'ResourceObserver', side_effect=AssertionError('formal observer forbidden')):
            result = coordinator.execute()
        self.assertNotIn('private', json.dumps(result, allow_nan=False))
        return result, events, samples, artifacts, runner, clock

    def test_fixed_order_31_samples_three_fresh_traces_and_five_boundaries(self):
        result, events, samples, artifacts, runner, clock = self.exercise()
        self.assertEqual(events, ['identity', 'parser-watchdog', 'supervisor-self-test', 'ci-boundary',
                                  'setup', 'correctness', 'teardown'])
        self.assertEqual(len(samples), 31)
        self.assertEqual(result['status'], 'COMPLETE_DIAGNOSTIC_ONLY')
        self.assertEqual(result['classification'], 'UNRESOLVED_NO_SAMPLED_LIMIT_EXCEEDANCE')
        self.assertEqual(result['result'], 'DIAGNOSTIC_ONLY_NO_ADMISSION')
        self.assertFalse(result['production_authority'])
        self.assertFalse(result['continuous_health_claim'])
        self.assertEqual(len(result['workloads']), 5)
        for trace in result['traces']:
            self.assertEqual(len(trace['samples']), 7)
            self.assertEqual(len(trace['intervals']), 6)
            self.assertIsNone(trace['samples'][0]['cpu_busy_percent'])
            self.assertEqual([r['scheduled_offset_seconds'] for r in trace['samples']], list(range(0, 61, 10)))
        for boundary in result['workloads']:
            self.assertEqual(boundary['status'], 'COMPLETE')
            self.assertEqual(boundary['io_full_total_delta_usec'], 10)
            self.assertFalse(boundary['continuous_10_second_sampling'])
        self.assertEqual(result['scopes']['normal']['deadline_monotonic'], 900)
        self.assertEqual(result['scopes']['normal']['status'], 'COMPLETE')
        self.assertNotEqual(runner.deadline, 900)
        self.assertIn('completion.json', artifacts)

    def test_all_eight_patterns_follow_preregistered_classification(self):
        for mask in range(8):
            pattern = [bool(mask & (1 << i)) for i in range(3)]
            points = {i for i, exceeded in zip((0, 13, 24), pattern) if exceeded}
            with self.subTest(pattern=pattern):
                result = self.exercise(mutate=lambda i, v: v.update(io_full_avg10=1.5) if i in points else None)[0]
                expected = ('BASELINE_LIMIT_EXCEEDED' if pattern[0] else
                            'FIRST_EXCEEDANCE_AFTER_TESTS' if pattern[1] else
                            'FIRST_EXCEEDANCE_AFTER_FIXTURE' if pattern[2] else
                            'UNRESOLVED_NO_SAMPLED_LIMIT_EXCEEDANCE')
                self.assertEqual(result['classification'], expected)
                self.assertEqual(result['trace_exceeded_pattern'], pattern)
                self.assertEqual(result['status'], 'COMPLETE_DIAGNOSTIC_ONLY')

    def test_nonzero_totals_do_not_invent_a_threshold(self):
        result = self.exercise(mutate=lambda i, v: v.update(io_full_total_usec=1000000*i))[0]
        self.assertEqual(result['classification'], 'UNRESOLVED_NO_SAMPLED_LIMIT_EXCEEDANCE')

    def test_source_failure_stops_without_fabricated_later_trace(self):
        result, events, samples, artifacts, _, _ = self.exercise(
            mutate=lambda i, v: v.update(mem_available_kib=True) if i == 2 else None)
        self.assertEqual(events, ['identity', 'teardown'])
        self.assertEqual(len(samples), 3)
        self.assertEqual(len(result['traces']), 1)
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')
        self.assertIn('trace-before-tests.json', artifacts)

    def test_each_workload_has_both_boundaries_even_when_command_fails(self):
        for name in ('parser-watchdog', 'supervisor-self-test', 'ci-boundary'):
            with self.subTest(name=name):
                result, events, _, _, _, _ = self.exercise(command_failure=name)
                self.assertNotIn('setup', events)
                boundary = result['workloads'][-1]
                self.assertEqual(boundary['name'], name)
                self.assertIsNotNone(boundary['before'])
                self.assertIsNotNone(boundary['after'])
                self.assertEqual(boundary['result'], 7)
                self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_setup_and_correctness_failure_retain_owned_teardown(self):
        for key in ('setup_failure', 'correctness_failure'):
            with self.subTest(key=key):
                result, events, _, _, _, _ = self.exercise(**{key: True})
                self.assertEqual(events[-1], 'teardown')
                self.assertEqual(result['teardown'], 'PASS_EXACT_OWNED_ONLY')
                self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')
                self.assertEqual(len(result['traces']), 2)

    def test_teardown_failure_invalidates_otherwise_complete_evidence(self):
        result = self.exercise(teardown_failure=True)[0]
        self.assertEqual(len(result['traces']), 3)
        self.assertEqual(result['teardown'], 'FAIL_RETAIN_AMBIGUOUS_RESOURCES')
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_scalar_psi_regression_between_traces_is_rejected(self):
        result, events, _, _, _, _ = self.exercise(
            mutate=lambda i, v: v.update(io_full_total_usec=0) if i == 7 else None)
        self.assertNotIn('parser-watchdog', events)
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')
        self.assertIn('diagnostic_psi_total_regressed', result['failures'])

    def test_late_start_early_wakeup_slow_sample_do_not_retry(self):
        for kwargs in (dict(sleep_extra=1.01), dict(sleep_extra=-0.01), dict(sample_duration=1.01)):
            with self.subTest(kwargs=kwargs):
                result, events, samples, _, _, _ = self.exercise(**kwargs)
                self.assertEqual(len(samples), 1)
                self.assertNotIn('parser-watchdog', events)
                self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_normal_deadline_includes_pre_teardown_publication(self):
        def delay(name, value, clock):
            if name == 'summary.json' and value['publication_phase'] == 'pre-teardown':
                clock.now = 901
        result, events, _, _, runner, _ = self.exercise(publish_delay=delay)
        self.assertEqual(result['scopes']['normal']['deadline_monotonic'], 900)
        self.assertEqual(result['scopes']['normal']['status'], 'FAIL')
        self.assertGreater(runner.deadline, 900)
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_final_publication_has_independent_60_second_scope(self):
        def delay(name, value, clock):
            if name == 'summary.json' and value['publication_phase'] == 'final':
                clock.now += 61
        result, _, _, artifacts, _, _ = self.exercise(publish_delay=delay)
        self.assertEqual(result['scopes']['normal']['status'], 'COMPLETE')
        self.assertEqual(result['scopes']['final']['status'], 'FAIL')
        self.assertEqual(result['status'], 'FAIL_DIAGNOSTIC_ONLY')
        self.assertNotIn('completion.json', artifacts)

    def test_identity_failure_never_samples_or_starts_workloads(self):
        result, events, samples, _, _, _ = self.exercise(identity_failure=True)
        self.assertEqual(samples, [])
        self.assertEqual(events, ['identity', 'teardown'])
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_cancel_during_pre_teardown_publication_cannot_be_complete(self):
        for name in ('summary.json', 'trace-before-tests.json'):
            with self.subTest(name=name):
                result, events, _, _, runner, _ = self.exercise(cancel_publication=name)
                self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')
                self.assertEqual(result['status'], 'FAIL_DIAGNOSTIC_ONLY')
                self.assertIn('diagnostic_interrupted', result['failures'])
                self.assertEqual(result['teardown'], 'PASS_EXACT_OWNED_ONLY')
                self.assertEqual(events[-1], 'teardown')

    def test_failed_publication_and_receipt_never_claim_complete(self):
        for name in ('summary.json', 'completion.json'):
            def fail_write(current, value, clock):
                if current == name:
                    raise OSError('private disk error')
            with self.subTest(name=name):
                result = self.exercise(publish_delay=fail_write)[0]
                self.assertEqual(result['status'], 'FAIL_DIAGNOSTIC_ONLY')
                self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_all_original_thresholds_and_violation_indices_are_retained(self):
        def busy(index, value):
            value.update(cpu_ticks=[100*(index+1), 0, 0, 0, 0, 0, 0, 0],
                         cpu_some_avg10=10.01, io_full_avg10=1.01, mem_available_kib=131071)
        result = self.exercise(mutate=busy)[0]
        self.assertEqual(result['status'], 'COMPLETE_DIAGNOSTIC_ONLY')
        for trace in result['traces']:
            self.assertEqual(trace['violations_by_limit']['max_cpu_busy_percent'], list(range(1, 7)))
            for key in ('max_cpu_some_avg10', 'max_io_full_avg10', 'min_mem_available_kib'):
                self.assertEqual(trace['violations_by_limit'][key], list(range(7)))

    def test_slow_workload_cannot_borrow_recovery_deadline(self):
        result, events, _, _, _, _ = self.exercise(workload_duration=850)
        self.assertEqual(events, ['identity', 'parser-watchdog', 'teardown'])
        self.assertEqual(result['scopes']['normal']['status'], 'FAIL')
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')

    def test_invalid_initial_and_regressing_clocks_are_rejected(self):
        for bad in (True, -1, float('nan'), float('inf')):
            with self.subTest(bad=repr(bad)):
                coordinator = self.mod.Coordinator(clock=lambda: bad)
                with self.assertRaisesRegex(self.mod.CI.ClosedFailure, '^diagnostic_clock_invalid$'):
                    coordinator.now()
                self.assertIsNone(coordinator.last_clock)
                json.dumps(coordinator.report, allow_nan=False)
        sequence = iter((2, 1))
        coordinator = self.mod.Coordinator(clock=lambda: next(sequence))
        self.assertEqual(coordinator.now(), 2)
        with self.assertRaisesRegex(self.mod.CI.ClosedFailure, '^diagnostic_clock_invalid$'):
            coordinator.now()

    def test_source_exception_stops_after_partial_trace_without_details(self):
        def fail(index, value):
            if index == 1:
                raise OSError('private source content')
        result, events, samples, _, _, _ = self.exercise(mutate=fail)
        self.assertEqual(len(samples), 2)
        self.assertEqual(events, ['identity', 'teardown'])
        self.assertEqual(len(result['traces'][0]['samples']), 1)
        self.assertEqual(result['classification'], 'UNRESOLVED_INVALID_OR_INCOMPLETE')


class GuardTests(unittest.TestCase):
    def setUp(self):
        self.mod = load()

    def test_exact_main_opt_in_and_no_overrides(self):
        base = dict(GITHUB_ACTIONS='true', CI='true', RUNNER_OS='Linux', RUNNER_ENVIRONMENT='github-hosted',
            GITHUB_REPOSITORY='Hao-Monster/Xboardme', GITHUB_EVENT_NAME='workflow_dispatch',
            GITHUB_REF='refs/heads/main', GITHUB_SHA='a'*40, GITHUB_WORKFLOW_SHA='a'*40,
            GITHUB_WORKFLOW_REF='Hao-Monster/Xboardme/.github/workflows/image-inventory-ci.yml@refs/heads/main',
            IMAGE_INVENTORY_CI_ALLOW_LOCAL='diagnostic', IMAGE_INVENTORY_CI_DIAGNOSTIC='true',
            IMAGE_INVENTORY_CI_BENCHMARK='false')
        with patch.dict(os.environ, base, clear=True):
            self.mod.diagnostic_guard()
        for key, value in [('GITHUB_REF', 'refs/heads/develop'), ('GITHUB_WORKFLOW_SHA', 'b'*40),
            ('GITHUB_EVENT_NAME', 'pull_request'), ('IMAGE_INVENTORY_CI_BENCHMARK', 'true'),
            ('IMAGE_INVENTORY_CI_DIAGNOSTIC', 'false'), ('DOCKER_HOST', 'tcp://forbidden'),
            ('BASH_ENV', '/private'), ('LD_PRELOAD', '/private')]:
            with self.subTest(key=key), patch.dict(os.environ, dict(base, **{key: value}), clear=True):
                with self.assertRaises(self.mod.CI.ClosedFailure):
                    self.mod.diagnostic_guard()


def recorded_child_present(boundary, record):
    pid, started = record
    try:
        return boundary.identity(pid)[1] == started
    except (FileNotFoundError, ProcessLookupError):
        return False


def recover_synthetic_child(boundary, record):
    """Test fallback: only our adopted child with its original identity."""
    pid, started = record
    expected = (os.getpid(), started)
    fd = None
    try:
        if boundary.identity(pid) != expected:
            return
        fd = os.pidfd_open(pid)
        if boundary.identity(pid) != expected:
            return
        signal.pidfd_send_signal(fd, signal.SIGKILL)
        deadline = time.monotonic() + 1
        while recorded_child_present(boundary, record):
            if os.waitpid(pid, os.WNOHANG)[0] == pid:
                return
            if time.monotonic() >= deadline:
                raise AssertionError('exact synthetic child could not be reaped')
            time.sleep(0.01)
    except (FileNotFoundError, ProcessLookupError, ChildProcessError):
        return
    finally:
        if fd is not None:
            os.close(fd)


def signal_recorded_wrapper(boundary, record):
    """Signal only the original test wrapper, never a later adoptive parent."""
    pid, started = record
    fd = None
    try:
        if os.getppid() != pid or boundary.identity(pid)[1] != started:
            return False
        fd = os.pidfd_open(pid)
        for unused in range(2):
            if os.getppid() != pid or boundary.identity(pid)[1] != started:
                return False
            signal.pidfd_send_signal(fd, signal.SIGTERM)
        return True
    except (FileNotFoundError, ProcessLookupError):
        return False
    finally:
        if fd is not None:
            os.close(fd)


class LinuxBoundaryTests(unittest.TestCase):
    def test_nested_session_timeout_is_reaped_before_return(self):
        self.exercise('timeout')

    def test_coordinator_cancel_flag_reaps_nested_session(self):
        self.exercise('cancel-flag')

    def test_direct_repeated_term_reaps_nested_session(self):
        self.exercise('repeated-term')

    def test_popen_registration_signals_do_not_lose_owned_child(self):
        self.exercise('registration-signal')

    def test_popen_registration_failure_still_adopts_owned_child(self):
        self.exercise('registration-failure')

    def test_thread_spawned_nested_session_is_adopted_and_reaped(self):
        self.exercise('thread-spawn')

    def test_deep_separate_sessions_are_adopted_and_reaped(self):
        self.exercise('deep-sessions')

    def test_leader_exit_with_live_descendant_cannot_be_success(self):
        self.exercise('leader-exit')

    def test_term_resistant_nested_session_is_reaped(self):
        self.exercise('ignore-term')

    def exercise(self, mode):
        mod = load()
        # This test process adopts only the synthetic descendants it creates,
        # so the Red baseline can recover/reap its exact leaked child as well.
        self.assertEqual(ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0), 0)
        with tempfile.TemporaryDirectory(prefix='diagnostic-boundary-test.') as directory:
            root = Path(directory)
            record = root / 'pid'
            cancel = root / 'cancel'
            cancel.write_bytes(b'0')
            env = dict(os.environ, DIAGNOSTIC_TEST_PID=str(record))
            child_code = ('import os,time,json;from pathlib import Path;'
                          'started=int(Path("/proc/self/stat").read_text().rsplit(") ",1)[1].split()[19]);'
                          'Path(os.environ["DIAGNOSTIC_TEST_PID"]).write_text(json.dumps([os.getpid(),started]));'
                          'time.sleep(30)')
            if mode == 'ignore-term':
                child_code = 'import signal;signal.signal(signal.SIGTERM,signal.SIG_IGN);' + child_code
            if mode == 'deep-sessions':
                for unused in range(3):
                    child_code = ('import subprocess,sys,time;subprocess.Popen([sys.executable,"-c",' +
                                  repr(child_code) + '],start_new_session=True);time.sleep(30)')
            command = [sys.executable, '-B', '-c',
                'import subprocess,sys,time;subprocess.Popen([sys.executable,"-c",' + repr(child_code) +
                '],start_new_session=True);time.sleep(30)']
            if mode == 'thread-spawn':
                command[-1] = ('import subprocess,sys,time,threading;'
                    'threading.Thread(target=lambda:subprocess.Popen([sys.executable,"-c",' + repr(child_code) +
                    '],start_new_session=True)).start();time.sleep(30)')
            if mode == 'repeated-term':
                command = [sys.executable, '-B', str(Path(__file__)), '--repeat-wrapper-term',
                           json.dumps([sys.executable, '-B', '-c', child_code])]
            if mode == 'leader-exit':
                command[-1] = ('import subprocess,sys,time;'
                    'subprocess.Popen([sys.executable,"-c",' + repr(child_code) + '],start_new_session=True);time.sleep(0.2)')
            boundary = HERE / 'image-inventory-diagnostic-boundary.py'
            spec = importlib.util.spec_from_file_location('test_cleanup_boundary', boundary)
            boundary_module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(boundary_module)
            started = time.monotonic()
            recorded = None
            try:
                if boundary.exists():
                    worker = [sys.executable, '-B', str(Path(__file__)), '--owned-worker',
                              json.dumps(command), str(cancel), mode]
                    def cancel_check():
                        if mode == 'cancel-flag' and record.exists():
                            cancel.write_bytes(b'1')
                    result = mod.CI.supervised(worker, env=env, budget=10, allow_nonzero=True, abort_check=cancel_check)
                    self.assertNotEqual(result[2], 0, 'synthetic timeout/cancel cannot pass')
                else:
                    # Red: the existing outer group alone cannot own the inner
                    # session. This branch is removed once the new boundary exists.
                    with self.assertRaises(mod.CI.ClosedFailure):
                        mod.CI.supervised(command, env=env, budget=3)
                self.assertTrue(record.is_file(), 'nested session was not reached')
                recorded = tuple(json.loads(record.read_text()))
                self.assertEqual(len(recorded), 2)
                self.assertGreater(recorded[0], 1)
                self.assertGreater(recorded[1], 0)
                self.assertFalse(recorded_child_present(boundary_module, recorded),
                                 'original nested owned session survived outer return')
                self.assertLess(time.monotonic() - started, 8)
            finally:
                if recorded is None and record.is_file():
                    recorded = tuple(json.loads(record.read_text()))
                if recorded is not None:
                    recover_synthetic_child(boundary_module, recorded)

    def test_test_cleanup_reused_or_nonowned_pid_is_never_signalled(self):
        boundary = type('SyntheticIdentity', (), {})()
        parent, original = os.getpid(), 1234
        for observed in ((parent, original + 1), (parent + 1, original)):
            for after_open in (False, True):
                with self.subTest(observed=observed, after_open=after_open), \
                        patch.object(boundary, 'identity', create=True,
                                     side_effect=[(parent, original), observed] if after_open else [observed]), \
                        patch.object(os, 'pidfd_open', return_value=999) as opened, \
                        patch.object(signal, 'pidfd_send_signal') as sent, \
                        patch.object(os, 'close') as closed, patch.object(os, 'waitpid') as waited:
                    recover_synthetic_child(boundary, (200, original))
                    self.assertEqual(opened.called, after_open)
                    self.assertEqual(closed.called, after_open)
                    self.assertFalse(sent.called)
                    self.assertFalse(waited.called)
        with patch.object(boundary, 'identity', create=True, return_value=(parent, original + 1)):
            self.assertFalse(recorded_child_present(boundary, (200, original)))

    def test_wrapper_signal_rejects_reparenting_or_reuse_before_and_after_binding(self):
        boundary = type('SyntheticIdentity', (), {})()
        for after_open in (False, True):
            for changed in ('parent', 'starttime'):
                parents = [200, 201] if after_open else [201]
                identities = [(100, 1234), (100, 1235)] if after_open else [(100, 1235)]
                with self.subTest(after_open=after_open, changed=changed), \
                        patch.object(os, 'getppid', side_effect=parents if changed == 'parent' else None,
                                     return_value=200), \
                        patch.object(boundary, 'identity', create=True,
                                     side_effect=identities if changed == 'starttime' else None,
                                     return_value=(100, 1234)), \
                        patch.object(os, 'pidfd_open', return_value=999) as opened, \
                        patch.object(signal, 'pidfd_send_signal') as sent, \
                        patch.object(os, 'close') as closed:
                    self.assertFalse(signal_recorded_wrapper(boundary, (200, 1234)))
                    self.assertFalse(sent.called)
                    self.assertEqual(opened.called, after_open)
                    self.assertEqual(closed.called, after_open)

    def test_wrapper_signal_rechecks_before_each_identity_bound_signal(self):
        boundary = type('SyntheticIdentity', (), {})()
        for parents, expected in (([200, 200, 200], 2), ([200, 200, 201], 1)):
            with self.subTest(parents=parents), patch.object(os, 'getppid', side_effect=parents), \
                    patch.object(boundary, 'identity', create=True, return_value=(100, 1234)), \
                    patch.object(os, 'pidfd_open', return_value=999), \
                    patch.object(signal, 'pidfd_send_signal') as sent, patch.object(os, 'close'):
                self.assertEqual(signal_recorded_wrapper(boundary, (200, 1234)), expected == 2)
                self.assertEqual(sent.call_count, expected)
                self.assertTrue(all(call.args == (999, signal.SIGTERM) for call in sent.call_args_list))

    def test_actual_reparenting_never_signals_adoptive_test_subreaper(self):
        mod = load()
        spec = importlib.util.spec_from_file_location('reparent_cleanup_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        boundary = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(boundary)
        self.assertEqual(ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0), 0)
        with tempfile.TemporaryDirectory(prefix='diagnostic-reparent.') as directory:
            record = Path(directory) / 'child'
            script = (
                'import os,sys,json,subprocess;from pathlib import Path;'
                'started=int(Path("/proc/self/stat").read_text().rsplit(") ",1)[1].split()[19]);'
                'os.environ["DIAGNOSTIC_TEST_WRAPPER_IDENTITY"]=json.dumps([os.getpid(),started]);'
                'subprocess.Popen([sys.executable,"-B",' + repr(str(Path(__file__))) +
                ',"--reparent-signal-probe",' + repr(str(record)) + '],start_new_session=True)')
            recorded = None
            try:
                out, err, status, unused = mod.CI.supervised([sys.executable, '-B', '-c', script],
                                                           budget=6, allow_nonzero=True)
                self.assertEqual(status, 0, err.decode())
                result = json.loads(out)
                self.assertEqual(result['adoptive_parent'], os.getpid())
                self.assertNotEqual(result['original_wrapper'], result['adoptive_parent'])
                self.assertFalse(result['signalled'])
                self.assertEqual(result['pid_signals'], 0)
                self.assertEqual(result['pidfd_signals'], 0)
                recorded = tuple(json.loads(record.read_text()))
                self.assertEqual(boundary.identity(recorded[0]), (os.getpid(), recorded[1]))
                self.assertEqual(os.waitpid(recorded[0], os.WNOHANG)[0], recorded[0])
            finally:
                if recorded is None and record.is_file():
                    recorded = tuple(json.loads(record.read_text()))
                if recorded is not None:
                    recover_synthetic_child(boundary, recorded)

    def test_normal_command_success_and_nonzero(self):
        mod = load()
        with tempfile.TemporaryDirectory(prefix='diagnostic-boundary-test.') as directory:
            cancel = Path(directory) / 'cancel'
            cancel.write_bytes(b'0')
            for code in (0, 7):
                worker = [sys.executable, '-B', str(Path(__file__)), '--owned-worker',
                          json.dumps([sys.executable, '-B', '-c', 'raise SystemExit(%d)' % code]),
                          str(cancel), 'normal']
                result = mod.CI.supervised(worker, budget=10, allow_nonzero=True)
                self.assertEqual(result[2], code)

    def test_absolute_expired_budget_rejects_before_spawn(self):
        spec = importlib.util.spec_from_file_location('deadline_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with patch.object(module.subprocess, 'Popen', side_effect=AssertionError('spawn forbidden')) as popen:
            with self.assertRaises(module.BoundaryFailure):
                module.own_command(['never-run'], work_deadline=time.monotonic()-1,
                                   recovery_deadline=time.monotonic()+2, cancellation_file=Path('/never-read'))
            self.assertFalse(popen.called)

    def test_early_cancel_recovery_is_at_most_five_seconds_despite_repeated_signals(self):
        spec = importlib.util.spec_from_file_location('early_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        clock, handlers, kills = Clock(), {}, []
        def install(sig, handler):
            previous = handlers.get(sig, signal.SIG_DFL)
            handlers[sig] = handler
            return previous
        class PersistentOwned:
            def scan(self):
                pass
            def reap(self, process):
                pass
            def kill(self):
                kills.append(clock.now)
                handlers[signal.SIGTERM](signal.SIGTERM, None)
            def live(self):
                return True
            def close(self):
                pass
        process = type('SyntheticProcess', (), {'poll': lambda self: None, 'returncode': None})()
        with tempfile.TemporaryDirectory(prefix='diagnostic-early-cancel.') as directory:
            cancel = Path(directory) / 'cancel'
            cancel.write_bytes(b'0')
            with patch.object(module, 'OwnedChildren', return_value=PersistentOwned()), \
                    patch.object(module.subprocess, 'Popen', return_value=process), \
                    patch.object(module.time, 'monotonic', side_effect=clock), \
                    patch.object(module.time, 'sleep', side_effect=clock.sleep), \
                    patch.object(module.signal, 'signal', side_effect=install), \
                    patch.object(module.os, 'pread', side_effect=lambda *args: b'1' if process.started else b'0'):
                process.started = False
                def launch(*args, **kwargs):
                    process.started = True
                    return process
                with patch.object(module.subprocess, 'Popen', side_effect=launch):
                    status = module.own_command(['synthetic-only'], work_deadline=112,
                                               recovery_deadline=117, cancellation_file=cancel)
            self.assertEqual(status, 125)
            self.assertGreater(len(kills), 2)
            self.assertLessEqual(clock.now, 5, 'early failure must not use the distant absolute recovery deadline')

    def test_scan_failure_still_recovers_already_bound_owned_children(self):
        spec = importlib.util.spec_from_file_location('failed_scan_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        killed = []
        class FailedScanOwned:
            def scan(self):
                raise module.BoundaryFailure('owned_child_identity_changed')
            def reap(self, process):
                pass
            def kill(self):
                killed.append(True)
            def live(self):
                return not killed
            def close(self):
                pass
        process = type('SyntheticProcess', (), {'poll': lambda self: 0, 'returncode': 0})()
        with tempfile.TemporaryDirectory(prefix='diagnostic-failed-scan.') as directory:
            cancel = Path(directory) / 'cancel'
            cancel.write_bytes(b'0')
            with patch.object(module, 'OwnedChildren', return_value=FailedScanOwned()), \
                    patch.object(module.subprocess, 'Popen', return_value=process):
                outer = time.monotonic() + 9
                status = module.own_command(['synthetic-only'], work_deadline=outer-8,
                                           recovery_deadline=outer-3, cancellation_file=cancel)
            self.assertEqual(status, 125)
            self.assertTrue(killed, 'invalid new discovery cannot bypass recovery of previously bound owned children')

    def test_outer_budget_includes_control_file_preparation(self):
        spec = importlib.util.spec_from_file_location('outer_budget_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        clock, observed = Clock(), {}
        clock.now = 100
        original_write = os.write
        def delayed_write(fd, data):
            clock.now += 2
            return original_write(fd, data)
        class SyntheticCI:
            @staticmethod
            def require(condition, unused):
                if not condition:
                    raise AssertionError('unexpected budget rejection')
            @staticmethod
            def supervised(argv, **kwargs):
                observed.update(argv=argv, **kwargs)
                return b'', b'', 0, {}
        with tempfile.TemporaryDirectory(prefix='diagnostic-outer-budget.') as directory, \
                patch.dict(os.environ, RUNNER_TEMP=directory), \
                patch.object(module.time, 'monotonic', side_effect=clock), \
                patch.object(module.os, 'write', side_effect=delayed_write):
            self.assertEqual(module.run_pretest(SyntheticCI, 'parser-watchdog', 200, lambda: False), 0)
        self.assertEqual(observed['budget'], 98)
        self.assertEqual(observed['argv'][observed['argv'].index('--work-deadline')+1], '192')
        self.assertEqual(observed['argv'][observed['argv'].index('--recovery-deadline')+1], '197')

    def test_same_outer_anchor_preserves_strict_five_second_reserve(self):
        spec = importlib.util.spec_from_file_location('anchor_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        start = 11.0003
        self.assertGreater((start+117)-(start+112), 5)
        outer = start + 120
        self.assertEqual((outer-3)-(outer-8), 5)
        with patch.object(module.time, 'monotonic', return_value=start), \
                patch.object(module.subprocess, 'Popen') as popen:
            with self.assertRaisesRegex(module.BoundaryFailure, '^owned_deadline_invalid$'):
                module.own_command(['never-run'], work_deadline=start+112,
                                   recovery_deadline=start+117, cancellation_file=Path('/never-read'))
            self.assertFalse(popen.called)

    def test_simultaneous_257_owned_children_reject_without_signalling(self):
        spec = importlib.util.spec_from_file_location('count_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        owned = module.OwnedChildren.__new__(module.OwnedChildren)
        owned.pid, owned.start, owned.entries = 100, 1, {}
        with patch.object(module, 'identity', return_value=(1, 1)), \
                patch.object(module, 'bounded_proc', return_value=' '.join(str(i) for i in range(200, 457))), \
                patch.object(module.os, 'pidfd_open', side_effect=AssertionError('must reject before fd open')) as opened, \
                patch.object(module.signal, 'pidfd_send_signal') as sent:
            with self.assertRaisesRegex(module.BoundaryFailure, '^owned_children_invalid$'):
                owned.scan()
            self.assertFalse(opened.called)
            self.assertFalse(sent.called)

    def test_pid_reuse_is_not_signalled(self):
        spec = importlib.util.spec_from_file_location('pid_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        owned = module.OwnedChildren.__new__(module.OwnedChildren)
        owned.entries = {200: (1, 123)}
        with patch.object(module, 'identity', return_value=(100, 2)), \
                patch.object(module.signal, 'pidfd_send_signal') as sent, patch.object(module.os, 'close') as closed:
            owned.kill()
            self.assertFalse(sent.called)
            self.assertFalse(owned.live())
            closed.assert_called_once_with(123)

    def test_invalid_identity_does_not_skip_other_bound_children(self):
        spec = importlib.util.spec_from_file_location('invalid_identity_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        owned = module.OwnedChildren.__new__(module.OwnedChildren)
        owned.entries = {200: (1, 123), 201: (2, 124)}
        with patch.object(module, 'identity', side_effect=[module.BoundaryFailure('owned_identity_invalid'), (100, 2)]), \
                patch.object(module.signal, 'pidfd_send_signal') as sent:
            with self.assertRaisesRegex(module.BoundaryFailure, '^owned_identity_invalid$'):
                owned.kill()
            sent.assert_called_once_with(124, signal.SIGKILL)

    def test_unavailable_pidfd_never_starts_command(self):
        spec = importlib.util.spec_from_file_location('capability_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with patch.object(module.os, 'pidfd_open', side_effect=OSError(38, 'synthetic unavailable')), \
                patch.object(module.subprocess, 'Popen') as popen:
            with self.assertRaises(OSError):
                outer = time.monotonic() + 9
                module.own_command(['never-run'], work_deadline=outer-8, recovery_deadline=outer-3,
                                   cancellation_file=Path('/never-read'))
            self.assertFalse(popen.called)

    def test_more_than_256_cumulative_children_with_small_live_set(self):
        mod = load()
        with tempfile.TemporaryDirectory(prefix='diagnostic-churn-test.') as directory:
            cancel = Path(directory) / 'cancel'
            cancel.write_bytes(b'0')
            script = ('import subprocess\nfor unused in range(90):\n'
                      ' children=[subprocess.Popen(["/bin/sleep","0.025"]) for item in range(4)]\n'
                      ' for child in children: child.wait()\n'
                      'print("CHURN_CREATED_360=TRUE",flush=True)\n')
            worker = [sys.executable, '-B', str(Path(__file__)), '--owned-worker',
                      json.dumps([sys.executable, '-B', '-c', script]), str(cancel), 'churn']
            result = mod.CI.supervised(worker, budget=15, allow_nonzero=True)
            self.assertEqual(result[2], 0, result[0].decode())
            self.assertIn(b'CHURN_CREATED_360=TRUE', result[0])
            self.assertIn(b'CHURN_SMALL_TRACKED_SET=TRUE', result[0])


class PublicationTests(unittest.TestCase):
    @staticmethod
    def environment(directory):
        return dict(GITHUB_ACTIONS='true', CI='true', RUNNER_OS='Linux', RUNNER_ENVIRONMENT='github-hosted',
            GITHUB_REPOSITORY='Hao-Monster/Xboardme', GITHUB_EVENT_NAME='workflow_dispatch',
            GITHUB_REF='refs/heads/main', GITHUB_SHA='a'*40, GITHUB_WORKFLOW_SHA='a'*40,
            GITHUB_WORKFLOW_REF='Hao-Monster/Xboardme/.github/workflows/image-inventory-ci.yml@refs/heads/main',
            IMAGE_INVENTORY_CI_ALLOW_LOCAL='diagnostic', IMAGE_INVENTORY_CI_DIAGNOSTIC='true',
            IMAGE_INVENTORY_CI_BENCHMARK='false', RUNNER_TEMP=directory)

    def test_serialization_exhausts_budget_before_writer_launch(self):
        mod = load()
        original_json = mod.json_bytes
        for delay in (3, 10):
            clock = Clock()
            clock.now = 100
            def delayed_json(value):
                data = original_json(value)
                clock.now += delay
                return data
            with self.subTest(delay=delay), tempfile.TemporaryDirectory(prefix='diagnostic-serialization.') as directory:
                coordinator = mod.Coordinator()
                coordinator.runner = type('SyntheticRunner', (), {'runner_temp': Path(directory)})()
                with patch.object(mod.time, 'monotonic', side_effect=clock), \
                        patch.object(mod, 'json_bytes', side_effect=delayed_json), \
                        patch.object(mod.CI, 'supervised', return_value=(b'', b'', 0, {})) as supervised:
                    with self.assertRaisesRegex(mod.CI.ClosedFailure, '^diagnostic_publication_deadline$'):
                        coordinator.publish('summary.json', {'status': 'PENDING_FINAL_PUBLICATION_STATUS'}, 105)
                    self.assertFalse(supervised.called)

    def test_serialization_time_is_deducted_from_writer_budget(self):
        mod, clock = load(), Clock()
        clock.now = 100
        original_json = mod.json_bytes
        value = {'status': 'PENDING_FINAL_PUBLICATION_STATUS'}
        expected = original_json(value)
        def delayed_json(value):
            data = original_json(value)
            clock.now += 2
            return data
        with tempfile.TemporaryDirectory(prefix='diagnostic-serialization.') as directory:
            coordinator = mod.Coordinator()
            coordinator.runner = type('SyntheticRunner', (), {'runner_temp': Path(directory)})()
            with patch.object(mod.time, 'monotonic', side_effect=clock), \
                    patch.object(mod, 'json_bytes', side_effect=delayed_json), \
                    patch.object(mod.CI, 'supervised', return_value=(b'', b'', 0, {})) as supervised:
                coordinator.publish('summary.json', value, 110)
                self.assertEqual(supervised.call_count, 1)
                self.assertEqual(supervised.call_args.kwargs['budget'], 8)
                self.assertEqual(supervised.call_args.kwargs['input_bytes'], expected)
                self.assertEqual(clock.now, 102)

    def test_actual_atomic_writer_and_strict_size(self):
        mod = load()
        with tempfile.TemporaryDirectory(prefix='diagnostic-publication.') as directory, \
                patch.dict(os.environ, self.environment(directory), clear=True):
            coordinator = mod.Coordinator()
            coordinator.runner = type('SyntheticRunner', (), {'runner_temp': Path(directory)})()
            value = dict(status='PENDING_FINAL_PUBLICATION_STATUS', result=mod.RESULT)
            coordinator.publish('summary.json', value, time.monotonic()+5)
            self.assertEqual((Path(directory) / 'image-inventory-diagnostic-results/summary.json').read_bytes(),
                             mod.json_bytes(value))
            self.assertFalse((Path(directory) / 'image-inventory-diagnostic-results/summary.json.new').exists())
            for value in ({'bad': float('nan')}, {'oversized': 'x'*131072}, {'secret': mod.CI.CANARY}):
                with self.subTest(value_type=next(iter(value))), self.assertRaises((mod.CI.ClosedFailure, ValueError)):
                    mod.json_bytes(value)

    def test_slow_actual_writer_is_killed_without_replacing_partial_summary(self):
        mod = load()
        with tempfile.TemporaryDirectory(prefix='diagnostic-publication.') as directory, \
                patch.dict(os.environ, self.environment(directory), clear=True):
            coordinator = mod.Coordinator()
            coordinator.runner = type('SyntheticRunner', (), {'runner_temp': Path(directory)})()
            first = dict(status='PENDING_FINAL_PUBLICATION_STATUS')
            coordinator.publish('summary.json', first, time.monotonic()+5)
            original = mod.CI.supervised
            def slow(argv, **kwargs):
                return original([sys.executable, '-B', str(Path(__file__)), '--slow-writer'], **kwargs)
            started = time.monotonic()
            with patch.object(mod.CI, 'supervised', side_effect=slow):
                with self.assertRaises(mod.CI.ClosedFailure):
                    coordinator.publish('summary.json', dict(status='DO_NOT_PUBLISH'), time.monotonic()+3)
            self.assertLess(time.monotonic()-started, 4)
            self.assertFalse(mod.CI.LAST_SUPERVISOR['residual_owned_group'])
            self.assertEqual((Path(directory) / 'image-inventory-diagnostic-results/summary.json').read_bytes(),
                             mod.json_bytes(first))

    def test_private_exception_and_paths_do_not_enter_artifacts(self):
        mod = load()
        for error in (RuntimeError('/private/host/password=do-not-publish'), OSError('synthetic secret')):
            self.assertEqual(mod.failure_code(error), 'diagnostic_local_failure')
        self.assertEqual(mod.failure_code(mod.CI.ClosedFailure('not a safe code')), 'diagnostic_local_failure')


class SourceContractTests(unittest.TestCase):
    def test_original_safety_inventory_and_harness_unchanged(self):
        import hashlib
        hashes = {'test-image-inventory-docker.py': '485534124d2bde63308b0df959b2f1a1877cfcc5225c2cbe58e8fab0b865d818',
                  'images-only-inventory.sh': '94012936a626f1f7b59a6ebc0ec5c7500897d6a2513e0c83563917cb1b182146',
                  'retention-safety.sh': '92e4ae6310727b9557cde03a974915a3fac07f2a8b4fa0532c1644d4dd4ea89b'}
        for name, wanted in hashes.items():
            with self.subTest(name=name):
                self.assertEqual(hashlib.sha256((HERE / name).read_bytes()).hexdigest(), wanted)

    def test_workflow_diagnostic_is_main_only_mutually_exclusive_and_first_workload(self):
        text = (HERE.parent / 'workflows/image-inventory-ci.yml').read_text()
        diagnostic = text.split('  inventory-diagnostic:', 1)[1]
        self.assertIn('inputs.diagnostic && !inputs.benchmark', diagnostic)
        self.assertIn("github.ref == 'refs/heads/main'", diagnostic)
        self.assertIn('timeout-minutes: 25', diagnostic)
        self.assertEqual(diagnostic.count('run:'), 1)
        self.assertIn('run: python3 -B .github/scripts/image-inventory-diagnostic.py', diagnostic)
        self.assertNotRegex(diagnostic, r'(?m)^\s+(environment|services|id-token):')
        self.assertNotIn('secrets.', diagnostic)
        self.assertIn('persist-credentials: false', diagnostic)
        self.assertIn('submodules: recursive', diagnostic)
        self.assertIn('contents: read', text)
        self.assertIn('inputs.benchmark && !inputs.diagnostic', text)
        self.assertIn('!inputs.benchmark && !inputs.diagnostic', text)


def owned_worker():
    spec = importlib.util.spec_from_file_location('owned_test_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    command, cancel, mode = json.loads(sys.argv[2]), Path(sys.argv[3]), sys.argv[4]
    if mode == 'repeated-term':
        # Captured by the original wrapper, not by a possibly already orphaned
        # child. The inherited identity is checked again around pidfd binding.
        os.environ['DIAGNOSTIC_TEST_WRAPPER_IDENTITY'] = json.dumps([os.getpid(), module.identity(os.getpid())[1]])
    start = time.monotonic()
    original = module.subprocess.Popen
    observed, peak = set(), [0]
    original_scan = module.OwnedChildren.scan
    def scan(owned):
        original_scan(owned)
        observed.update(owned.entries)
        peak[0] = max(peak[0], len(owned.entries))
    def create(*args, **kwargs):
        process = original(*args, **kwargs)
        if mode in ('registration-signal', 'registration-failure'):
            while not Path(os.environ['DIAGNOSTIC_TEST_PID']).exists() and time.monotonic() - start < 0.5:
                time.sleep(0.005)
            if mode == 'registration-failure':
                raise RuntimeError('synthetic failure after child creation')
            os.kill(os.getpid(), signal.SIGTERM)
            os.kill(os.getpid(), signal.SIGTERM)
        return process
    outer = start + (14 if mode == 'churn' else 8)
    with patch.object(module.subprocess, 'Popen', side_effect=create), patch.object(module.OwnedChildren, 'scan', scan):
        status = module.own_command(command, work_deadline=outer - (8 if mode == 'churn' else 7.25),
                                    recovery_deadline=outer - 3, cancellation_file=cancel)
    if mode == 'churn' and status == 0:
        if not observed or peak[0] > 8:
            return 126
        print('CHURN_SMALL_TRACKED_SET=TRUE')
    return status


def wrapper_signal_worker(probe=False):
    spec = importlib.util.spec_from_file_location('signal_test_boundary', HERE / 'image-inventory-diagnostic-boundary.py')
    boundary = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(boundary)
    original = tuple(json.loads(os.environ['DIAGNOSTIC_TEST_WRAPPER_IDENTITY']))
    if probe:
        record = Path(sys.argv[2])
        record.write_text(json.dumps([os.getpid(), boundary.identity(os.getpid())[1]]))
        deadline = time.monotonic() + 2
        while os.getppid() == original[0] and time.monotonic() < deadline:
            time.sleep(0.005)
        # Even a faulty implementation cannot send a real signal in this
        # negative probe. Parent death/adoption itself is real Linux behavior.
        with patch.object(os, 'kill') as pid_signals, patch.object(signal, 'pidfd_send_signal') as pidfd_signals:
            signalled = signal_recorded_wrapper(boundary, original)
            print(json.dumps(dict(original_wrapper=original[0], adoptive_parent=os.getppid(), signalled=signalled,
                                  pid_signals=pid_signals.call_count, pidfd_signals=pidfd_signals.call_count)), flush=True)
        return 0
    subprocess.Popen(json.loads(sys.argv[2]), start_new_session=True)
    time.sleep(0.2)
    signal_recorded_wrapper(boundary, original)
    time.sleep(30)
    return 0


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--owned-worker':
        raise SystemExit(owned_worker())
    if len(sys.argv) > 1 and sys.argv[1] in ('--repeat-wrapper-term', '--reparent-signal-probe'):
        raise SystemExit(wrapper_signal_worker(probe=sys.argv[1] == '--reparent-signal-probe'))
    if len(sys.argv) > 1 and sys.argv[1] == '--slow-writer':
        module = load()
        original_open = Path.open
        def delayed_open(path, *args, **kwargs):
            if path.name == 'summary.json.new':
                time.sleep(30)
            return original_open(path, *args, **kwargs)
        with patch.object(Path, 'open', delayed_open):
            module.write_child('summary.json')
        raise SystemExit(0)
    unittest.main(verbosity=2)
