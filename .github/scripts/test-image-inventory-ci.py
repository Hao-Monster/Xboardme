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
