#!/usr/bin/env python3
"""CI-only real-Docker inventory correctness and opt-in comparative benchmark.

Default real mode is correctness. Benchmark requires separate explicit opt-in.
Local --mode self-test uses only synthetic shell processes; no Docker or network.
"""
import argparse
import hashlib
import io
import json
import math
import os
from pathlib import Path
import re
import resource
import selectors
import shutil
import signal
import subprocess
import tarfile
import tempfile
import threading
import time
import uuid

HERE = Path(__file__).resolve().parent
SAFETY_SHA = '92e4ae6310727b9557cde03a974915a3fac07f2a8b4fa0532c1644d4dd4ea89b'
IMAGE_ID = re.compile(r'^sha256:[a-f0-9]{64}$')
CONTAINER_ID = re.compile(r'^[a-f0-9]{64}$')
LIMITS = dict(max_cpu_busy_percent=85, max_cpu_iowait_percent=10,
              max_cpu_some_avg10=10, max_io_full_avg10=1, min_mem_available_kib=131072)
CANARY = 'OVERHEAD_SYNTHETIC_SECRET_CANARY'
LAST_SUPERVISOR = {}


class ClosedFailure(Exception):
    pass


def require(condition, code):
    if not condition:
        raise ClosedFailure(code)


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def write_json(path, value):
    temporary = path.with_suffix(path.suffix + '.new')
    temporary.write_text(json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n')
    temporary.replace(path)


def snapshot_call_contract(variant, containers, images):
    require(variant in ('old', 'new') and containers >= 0 and images > 0, 'call_contract_invalid')
    return dict(container_list=1, container_inspect=containers, image_ls=1 if variant == 'old' else 2,
                image_inspect=images if variant == 'old' else (images + 15) // 16)


def group_exists(pid):
    try:
        os.killpg(pid, 0)
        return True
    except ProcessLookupError:
        return False


def stop_owned_group(process, deadline):
    """Only the group created by this invocation; no global process scanning.

    This covers the CI launch group, not arbitrary detached code. The new
    production helper owns one additional GNU timeout group: its Bash traps
    forward TERM and reap that group before returning. Direct linked tests
    verify that extra group separately; this function does not scan or kill it.
    A residual CI group (including unreaped orphan zombies) means failure.
    """
    require(process.pid > 1 and process.pid != os.getpgrp(), 'unsafe_child_group')
    end = min(deadline, time.monotonic() + 3)
    kill_at = max(time.monotonic(), min(end - 1, time.monotonic() + 2))
    sent = None
    while time.monotonic() < end:
        process.poll()
        if not group_exists(process.pid):
            return False
        wanted = signal.SIGKILL if time.monotonic() >= kill_at else signal.SIGTERM
        if wanted != sent:
            try:
                os.killpg(process.pid, wanted)
            except ProcessLookupError:
                return False
            sent = wanted
        time.sleep(min(0.02, max(0, end - time.monotonic())))
    if group_exists(process.pid):
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    process.poll()
    return group_exists(process.pid)


def supervised(argv, *, env=None, input_bytes=None, budget=120.0, allow_nonzero=False, abort_check=None):
    """Bounds the fixed harness's CI group; never accepts partial success.

    Software deadlines are not a hard real-time kernel guarantee. TERM starts
    at budget-2, KILL at budget-1; success must complete inside budget with no
    CI group members. Failure recovery is charged to that same budget. The
    reviewed production helper has its own timeout group and cancellation
    traps, exercised by linked tests. Other group changes/job control/detaches
    are forbidden. A CI-group check alone does not prove nested-group cleanup.
    """
    global LAST_SUPERVISOR
    require(2 < budget <= 120, 'invalid_budget')
    require(input_bytes is None or len(input_bytes) <= 1048576, 'input_limit')
    start = time.monotonic()
    deadline = start + budget
    before = resource.getrusage(resource.RUSAGE_CHILDREN)
    self_before = resource.getrusage(resource.RUSAGE_SELF)
    p, selector = None, None
    residual, shutdown_done = False, False
    failure = None
    LAST_SUPERVISOR = dict(status='RUNNING')
    # Tiny private generated tar input; regular input avoids blocked stdin writes.
    with tempfile.TemporaryFile(dir=os.environ.get('RUNNER_TEMP', tempfile.gettempdir())) as input_file:
        if input_bytes is not None:
            input_file.write(input_bytes)
            input_file.seek(0)
        streams = {1: bytearray(), 2: bytearray()}
        try:
            p = subprocess.Popen(argv, stdin=input_file, stdout=subprocess.PIPE,
                                 stderr=subprocess.PIPE, env=env, start_new_session=True)
            selector = selectors.DefaultSelector()
            for stream, number in ((p.stdout, 1), (p.stderr, 2)):
                os.set_blocking(stream.fileno(), False)
                selector.register(stream, selectors.EVENT_READ, number)
            while selector.get_map() or p.poll() is None:
                now = time.monotonic()
                if abort_check is not None:
                    abort_check()
                if now >= deadline - 2:
                    failure = 'whole_group_timeout'
                    break
                for key, _ in selector.select(min(0.05, max(0, deadline - 2 - now))):
                    data = os.read(key.fileobj.fileno(), 65536)
                    if not data:
                        selector.unregister(key.fileobj)
                        continue
                    cap = 16 * 1024 * 1024 if key.data == 1 else 65536
                    if len(streams[key.data]) + len(data) > cap:
                        failure = 'bounded_stream_exceeded'
                        break
                    streams[key.data].extend(data)
                if failure == 'bounded_stream_exceeded':
                    break
            status = p.poll()
            if group_exists(p.pid):
                failure = failure or 'residual_owned_process_group'
            if failure:
                residual = stop_owned_group(p, deadline)
                shutdown_done = True
            require(not residual, 'unreaped_owned_process_group')
            require(failure is None, failure or 'supervisor_failure')
            require(time.monotonic() < deadline, 'whole_group_deadline_exceeded')
            require(allow_nonzero or status == 0, 'child_nonzero')
            after = resource.getrusage(resource.RUSAGE_CHILDREN)
            self_after = resource.getrusage(resource.RUSAGE_SELF)
            LAST_SUPERVISOR.update(status='PASS', residual_owned_group=False)
            return bytes(streams[1]), bytes(streams[2]), status, dict(
                wall_seconds=time.monotonic() - start,
                user_seconds=after.ru_utime - before.ru_utime,
                system_seconds=after.ru_stime - before.ru_stime,
                supervisor_user_seconds=self_after.ru_utime - self_before.ru_utime,
                supervisor_system_seconds=self_after.ru_stime - self_before.ru_stime)
        finally:
            if selector is not None:
                selector.close()
            if p is not None and not shutdown_done and group_exists(p.pid):
                residual = stop_owned_group(p, deadline)
            LAST_SUPERVISOR.update(residual_owned_group=residual,
                                   wall_seconds=time.monotonic() - start)
            if LAST_SUPERVISOR['status'] != 'PASS':
                LAST_SUPERVISOR['status'] = 'FAIL'
            if p is not None:
                p.stdout.close()
                p.stderr.close()


def cpu_stat():
    line = Path('/proc/stat').read_text().splitlines()[0].split()
    require(line[0] == 'cpu' and len(line) >= 9, 'cpu_stat_invalid')
    values = [int(x) for x in line[1:9]]
    return sum(values), sum(values) - values[3] - values[4], values[4]


def pressure(which, field):
    rows = [x.split() for x in Path('/proc/pressure/' + which).read_text().splitlines()]
    matches = [x for x in rows if x[0] == field]
    require(len(matches) == 1 and matches[0][1].startswith('avg10='), 'psi_invalid')
    value = float(matches[0][1].split('=')[1])
    require(math.isfinite(value) and 0 <= value <= 100, 'psi_invalid')
    return value


def daemon_identity():
    found = []
    for path in Path('/proc').glob('[0-9]*'):
        try:
            if (path / 'comm').read_text().strip() == 'dockerd':
                fields = (path / 'stat').read_text().rsplit(') ', 1)[1].split()
                found.append((int(path.name), int(fields[19])))
        except (OSError, ValueError, IndexError):
            continue
    return found[0] if len(found) == 1 else None


class ResourceObserver:
    """CI procfs sampler only, not Caddy/connection/production continuity."""
    def __init__(self):
        self.rows = []
        self.failure = None
        self.stop = threading.Event()
        self.previous = None
        self.daemon = daemon_identity()
        self.thread = threading.Thread(target=self.loop, daemon=True)

    def sample(self):
        total, busy, waiting = cpu_stat()
        memory = [int(x.split()[1]) for x in Path('/proc/meminfo').read_text().splitlines()
                  if x.startswith('MemAvailable:')]
        require(len(memory) == 1, 'memory_invalid')
        row = dict(monotonic=time.monotonic(), cpu_busy_percent=None,
                   cpu_iowait_percent=None, mem_available_kib=memory[0],
                   cpu_some_avg10=pressure('cpu', 'some'), io_full_avg10=pressure('io', 'full'),
                   daemon_cpu_ticks=None, daemon_cpu_status='NOT_AVAILABLE')
        if self.previous is not None:
            delta = total - self.previous[0]
            require(delta > 0 and busy >= self.previous[1] and waiting >= self.previous[2], 'cpu_regressed')
            row['cpu_busy_percent'] = 100 * (busy - self.previous[1]) / delta
            row['cpu_iowait_percent'] = 100 * (waiting - self.previous[2]) / delta
            require(0 <= row['cpu_busy_percent'] <= 100 and 0 <= row['cpu_iowait_percent'] <= 100,
                    'cpu_percent_invalid')
        self.previous = (total, busy, waiting)
        if self.daemon:
            try:
                fields = Path('/proc/%d/stat' % self.daemon[0]).read_text().rsplit(') ', 1)[1].split()
                if int(fields[19]) == self.daemon[1]:
                    row['daemon_cpu_ticks'] = int(fields[11]) + int(fields[12])
                    row['daemon_cpu_status'] = 'AVAILABLE'
            except (OSError, ValueError, IndexError):
                pass
        row['violations'] = []
        for metric in ('cpu_busy_percent', 'cpu_iowait_percent', 'cpu_some_avg10', 'io_full_avg10'):
            if row[metric] is not None and row[metric] > LIMITS['max_' + metric]:
                row['violations'].append('max_' + metric)
        if row['mem_available_kib'] < LIMITS['min_mem_available_kib']:
            row['violations'].append('min_mem_available_kib')
        self.rows.append(row)
        require(not row['violations'], 'resource_threshold_exceeded')

    def loop(self):
        try:
            while True:
                self.sample()
                if self.stop.wait(10):
                    return
        except Exception:
            self.failure = 'resource_source_or_threshold_failed'

    def wait(self, seconds, deadline):
        end = time.monotonic() + seconds
        require(end < deadline, 'observation_operation_deadline')
        while time.monotonic() < end:
            self.check()
            time.sleep(min(1, max(0, end - time.monotonic())))
        self.check()

    def check(self):
        require(self.failure is None, self.failure or 'resource_observer_failed')
        require(self.thread.is_alive(), 'resource_observer_not_running')

    def coverage(self, start, baseline_end, read_end, end):
        require(all(math.isfinite(x) for x in (start, baseline_end, read_end, end)) and
                start + 120 <= baseline_end <= read_end and read_end + 300 <= end,
                'observation_window_incomplete')
        times = [row['monotonic'] for row in self.rows]
        require(times and all(isinstance(t, (int, float)) and math.isfinite(t) for t in times) and
                start <= times[0] <= times[-1] <= end and
                all(left < right for left, right in zip(times, times[1:])), 'observation_timestamps_invalid')
        result = {}
        for name, first, last in (('all', start, end), ('baseline', start, baseline_end), ('post', read_end, end)):
            selected = [t for t in times if first <= t <= last]
            minimum = math.floor((last - first) / 10)
            require(len(selected) >= minimum and len(selected) >= 2, 'observation_sample_count')
            boundaries = [first, *selected, last]
            largest = max(right - left for left, right in zip(boundaries, boundaries[1:]))
            require(largest <= 30, 'observation_sample_gap')
            result[name] = dict(samples=len(selected), minimum_samples=minimum,
                               first_gap_seconds=selected[0] - first, last_gap_seconds=last - selected[-1],
                               max_gap_seconds=largest)
        return result

    def close(self):
        self.stop.set()
        self.thread.join(timeout=5)
        require(not self.thread.is_alive(), 'observer_join_failed')


class InventoryCI:
    def __init__(self, mode):
        self.mode = mode
        self.images, self.containers = [], []
        self.baseline = None
        self.abort_check = None
        self.setup_started = False
        self.summary = dict(schema_version=1, status='RUNNING', mode=mode,
                            fixture_container_state='created_never_started', measurements=[],
                            real_service_continuity='NOT_RUN', production_headroom='NOT_RUN',
                            true_repo_digests='NOT_RUN', docker_null_config='NOT_RUN')
        self.guard()
        self.publication = self.runner_temp / 'image-inventory-ci-results'
        self.publication.mkdir(mode=0o700)
        self.root = Path(tempfile.mkdtemp(prefix='image-inventory-ci.', dir=self.runner_temp))
        self.root.chmod(0o700)
        self.label = 'overhead-ci-' + uuid.uuid4().hex
        self.namespace = self.label
        self.deadline = time.monotonic() + (900 if mode == 'correctness' else 3600)
        self.docker = shutil.which('docker')
        require(self.docker is not None, 'docker_missing')
        self.env = dict(os.environ)
        self.prepare_harness()

    def guard(self):
        env = os.environ
        require(env.get('GITHUB_ACTIONS') == 'true' and env.get('CI') == 'true' and
                env.get('RUNNER_OS') == 'Linux' and env.get('RUNNER_ENVIRONMENT') == 'github-hosted' and
                env.get('GITHUB_REPOSITORY') == 'Hao-Monster/Xboardme', 'not_fixed_github_hosted_ci')
        require(env.get('IMAGE_INVENTORY_CI_ALLOW_LOCAL') == self.mode, 'explicit_opt_in_missing')
        require(not env.get('DOCKER_HOST') and not env.get('DOCKER_CONTEXT'), 'docker_override_forbidden')
        require(not any(env.get(key) for key in ('BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS',
                    'LD_PRELOAD')) and
                not any(key.startswith('BASH_FUNC_') for key in env), 'harness_override_forbidden')
        self.runner_temp = Path(env.get('RUNNER_TEMP', '')).resolve()
        require(self.runner_temp.is_dir() and HERE == Path(env.get('GITHUB_WORKSPACE', '')).resolve() / '.github/scripts', 'not_checked_out_ci_source')
        require(re.fullmatch(r'/[A-Za-z0-9_./-]+', str(self.runner_temp)) is not None, 'unsafe_temporary_path')
        self.workspace = Path(env['GITHUB_WORKSPACE']).resolve()
        self.safety = HERE / 'retention-safety.sh'
        self.inventory = HERE / 'images-only-inventory.sh'
        require(digest(self.safety) == SAFETY_SHA and self.inventory.is_file() and not self.inventory.is_symlink(),
                'source_hash_mismatch')
        require(re.fullmatch(r'[a-f0-9]{40}', env.get('GITHUB_SHA', '')) is not None, 'checkout_sha_missing')

    def run(self, argv, *, input_bytes=None, allow_nonzero=False, maximum=120):
        remaining = self.deadline - time.monotonic()
        require(remaining > 2, 'operation_deadline')
        return supervised(argv, env=self.env, input_bytes=input_bytes,
                          allow_nonzero=allow_nonzero, budget=min(maximum, remaining),
                          abort_check=self.abort_check)

    def docker_run(self, *args, **kwargs):
        return self.run([self.docker, *args], **kwargs)

    def prepare_harness(self):
        # The old path is the unchanged shared helper. The new path invokes the
        # final production helper directly: no copied implementation or adapter.
        self.fixture = self.root / 'readonly-bridge'
        (self.fixture / 'bin').mkdir(parents=True)
        self.calls = self.root / 'calls.txt'
        bridge = self.fixture / 'bin/docker'
        bridge.write_text('#!/usr/bin/env bash\nset -Eeuo pipefail\n'
            'case "$1 ${2:-}" in "image inspect"|"image ls") kind="image_${2}" ;; '
            '"inspect "*) kind=container_inspect ;; "ps "*) kind=container_list ;; *) exit 90 ;; esac\n'
            'printf "%s\\n" "$kind" >> "$OVERHEAD_CALLS"\n'
            'exec "$OVERHEAD_REAL_DOCKER" "$@"\n')
        bridge.chmod(0o700)
        self.env.update(OVERHEAD_CALLS=str(self.calls), OVERHEAD_REAL_DOCKER=self.docker,
                        PATH=str(self.fixture / 'bin') + ':' + os.environ['PATH'])
        self.child = self.root / 'read-inventory.sh'
        self.child.write_text('''#!/usr/bin/env bash
set -Eeuo pipefail
set +m
source "$1"
source "$2"
case "$3" in
  old) retention_all_snapshots ;;
  new) images_only_all_snapshots ;;
  *) exit 90 ;;
esac
''')
        self.summary['sources'] = dict(safety_sha256=SAFETY_SHA,
            inventory_sha256=digest(self.inventory), ci_harness_sha256=digest(Path(__file__)),
            supervisor_scope='fixed_no_detach_ci_group_plus_direct_production_watchdog')

    def snapshot(self, variant):
        self.calls.write_text('')
        require(variant in ('old', 'new'), 'snapshot_variant_invalid')
        stdout, stderr, _, metrics = self.run(['bash', '--noprofile', '--norc', str(self.child), str(self.safety), str(self.inventory), variant])
        require(CANARY.encode() not in stdout + stderr, 'secret_canary_in_result')
        value = json.loads(stdout)
        require(set(value) == {'containers', 'images'}, 'snapshot_schema')
        metrics.update(variant=variant, docker_calls={}, containers=len(value['containers']), images=len(value['images']),
                       snapshot_sha256=hashlib.sha256(stdout).hexdigest())
        for kind in self.calls.read_text().splitlines():
            require(kind in ('image_inspect', 'image_ls', 'container_inspect', 'container_list'), 'call_log_invalid')
            metrics['docker_calls'][kind] = metrics['docker_calls'].get(kind, 0) + 1
        return stdout, value, metrics

    def setup(self):
        checkout = self.run(['git', '-C', str(self.workspace), 'rev-parse', 'HEAD'])[0].decode().strip()
        dirty = self.run(['git', '-C', str(self.workspace), 'status', '--porcelain', '--untracked-files=no'])[0].strip()
        require(checkout == os.environ['GITHUB_SHA'] and not dirty, 'checkout_not_exact_clean')
        self.summary['checkout_sha'] = checkout
        endpoint = self.docker_run('context', 'inspect', '--format', '{{.Endpoints.docker.Host}}')[0].decode().strip()
        require(endpoint.startswith('unix:///'), 'nonlocal_docker_endpoint')
        metadata = self.docker_run('info', '--format', '{"server_version":{{json .ServerVersion}},"storage_driver":{{json .Driver}},"cpu_count":{{json .NCPU}}}')[0]
        metadata = json.loads(metadata)
        require(isinstance(metadata, dict) and set(metadata) == {'server_version', 'storage_driver', 'cpu_count'} and
                isinstance(metadata['server_version'], str) and
                re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.+~-]{0,63}', metadata['server_version']) is not None and
                isinstance(metadata['storage_driver'], str) and
                re.fullmatch(r'[A-Za-z0-9_.-]{1,32}', metadata['storage_driver']) is not None and
                type(metadata['cpu_count']) is int and 0 < metadata['cpu_count'] <= 4096, 'daemon_metadata_invalid')
        self.summary['docker'] = metadata
        self.baseline_bytes, self.baseline, _ = self.snapshot('old')
        require(len(self.baseline['images']) <= 110, 'insufficient_bounded_inventory_headroom')
        self.summary['preexisting_counts'] = dict(images=len(self.baseline['images']), containers=len(self.baseline['containers']))
        self.ledger()
        self.setup_started = True
        tar_bytes = io.BytesIO()
        with tarfile.open(fileobj=tar_bytes, mode='w') as archive:
            member = tarfile.TarInfo('synthetic-record')
            data = b'isolated metadata fixture; never executed\n'
            member.size = len(data)
            member.mtime = 0
            archive.addfile(member, io.BytesIO(data))
        for number in range(146):
            changes = ['--change', 'LABEL codex.retention.test=' + self.label,
                       '--change', 'LABEL codex.overhead.index=' + str(number),
                       '--change', 'ENV OVERHEAD_TEST_SECRET=' + CANARY,
                       '--change', 'LABEL unrelated.secret=' + CANARY]
            if number % 2:
                changes += ['--change', 'LABEL org.opencontainers.image.revision=' + 'a' * 40,
                            '--change', 'LABEL org.opencontainers.image.source=https://github.com/Hao-Monster/Xboardme']
            image = self.docker_run('image', 'import', *changes, '-', self.namespace + ':image-' + str(number),
                                    input_bytes=tar_bytes.getvalue(), maximum=30)[0].decode().strip()
            require(IMAGE_ID.fullmatch(image) is not None and image not in self.images, 'distinct_image_id_required')
            self.images.append(image)
            self.ledger()
        for number in range(24):
            container = self.docker_run('create', '--name', self.label + '-container-' + str(number),
                '--network', 'none', '--restart', 'no', '--label', 'codex.retention.test=' + self.label,
                '--label', 'unrelated.secret=' + CANARY,
                '--env', 'OVERHEAD_TEST_SECRET=' + CANARY, '--log-driver', 'json-file', '--log-opt', 'labels=' + CANARY,
                self.images[number], '/never-executed', maximum=30)[0].decode().strip()
            require(CONTAINER_ID.fullmatch(container) is not None, 'container_id_invalid')
            self.containers.append(container)
            self.ledger()
        self.docker_run('tag', self.images[0], self.namespace + ':second-alias')
        self.ledger()

    def ledger(self):
        write_json(self.root / 'ownership-ledger.json', dict(label=self.label, namespace=self.namespace,
                   images=self.images, containers=self.containers, rollback_or_production_authority=False))

    def correctness(self):
        old_bytes, old, old_metric = self.snapshot('old')
        new_bytes, new, new_metric = self.snapshot('new')
        require(old_bytes == new_bytes, 'canonical_snapshot_mismatch')
        require(len(new['containers']) == len(self.baseline['containers']) + 24 and
                len(new['images']) == len(self.baseline['images']) + 146, 'global_inventory_count_drift')
        fixture_containers = [x for x in new['containers'] if x['id'] in self.containers]
        require(len(fixture_containers) == 24 and all(x['status'] == 'created' for x in fixture_containers), 'fixture_not_created')
        require(len(set(self.images)) == 146 and all(x in [y['id'] for y in new['images']] for x in self.images), 'fixture_images_missing')
        for kind in ('containers', 'images'):
            require(all(row in new[kind] for row in self.baseline[kind]), 'preexisting_resource_changed')
        for metric in (old_metric, new_metric):
            require(metric['docker_calls'] == snapshot_call_contract(metric['variant'], len(new['containers']),
                    len(new['images'])), 'docker_call_accounting_mismatch')
        source = self.inventory.read_text()
        format_match = re.search(r"local format='([^']+)'", source)
        require(format_match is not None, 'fixed_template_missing')
        missing = 'sha256:' + uuid.uuid4().hex + uuid.uuid4().hex
        _, _, absent_status, _ = self.docker_run('image', 'inspect', '--format', '{{.Id}}', missing, allow_nonzero=True)
        require(absent_status != 0, 'missing_probe_not_absent')
        partial, stderr, status, _ = self.docker_run('image', 'inspect', '--format', format_match[1], self.images[0], missing, allow_nonzero=True)
        require(status != 0 and CANARY.encode() not in partial + stderr, 'mixed_missing_not_rejected')
        parsed = [json.loads(line) for line in partial.splitlines() if line]
        require(all(row.get('Id') == self.images[0] for row in parsed), 'mixed_missing_foreign_output')
        self.summary.update(correctness='PASS', fixture_counts=dict(images=146, containers=24),
                            global_counts=dict(images=len(new['images']), containers=len(new['containers'])),
                            mixed_missing=dict(exit_nonzero=True, safe_partial_rows=len(parsed)),
                            config_host_config_hashes='canonical_old_new_equal')
        self.summary['measurements'] += [old_metric, new_metric]
        self.approved_snapshot = old_bytes

    def benchmark(self):
        for episode, variant in enumerate(('observer_only', 'old', 'new', 'new', 'old')):
            observer = ResourceObserver()
            observation_start = time.monotonic()
            observer.thread.start()
            metrics = None
            completed = False
            coverage = None
            episode_failure = None
            try:
                observer.wait(120, self.deadline)
                baseline_end = time.monotonic()
                if variant != 'observer_only':
                    self.abort_check = observer.check
                    actual, _, metrics = self.snapshot(variant)
                    require(actual == self.approved_snapshot, 'benchmark_inventory_drift')
                    self.abort_check = None
                read_end = time.monotonic()
                observer.wait(300, self.deadline)
                observer.check()
                completed = True
            except Exception as error:
                episode_failure = str(error) if isinstance(error, ClosedFailure) else 'unexpected_local_error'
                raise
            finally:
                self.abort_check = None
                observation_end = time.monotonic()
                try:
                    observer.close()
                    observation_end = time.monotonic()
                    if completed:
                        coverage = observer.coverage(observation_start, baseline_end, read_end, observation_end)
                except Exception as error:
                    episode_failure = str(error) if isinstance(error, ClosedFailure) else 'unexpected_local_error'
                    raise
                finally:
                    write_json(self.publication / ('episode-%d.json' % episode), dict(variant=variant,
                        baseline_seconds=120, post_seconds=300, sample_interval_seconds=10,
                        observed_wall_seconds=observation_end - observation_start, limits=LIMITS,
                        resource_samples=observer.rows, failure=episode_failure or observer.failure, measurement=metrics,
                        windows_completed=completed, sampling_coverage=coverage,
                        caddy_or_connection_observation='NOT_RUN'))
            require(observer.failure is None, 'benchmark_inconclusive_resource_gate')
        self.summary['benchmark'] = 'PASS_COMPARATIVE_CI_ONLY'

    def teardown(self):
        # Independent bounded recovery budget. Discover only this exact unique
        # label to recover resources created before a lost CLI response.
        self.abort_check = None
        if not self.setup_started:
            self.summary['teardown'] = 'NOT_NEEDED_NO_RESOURCE_CREATION'
            return
        self.deadline = time.monotonic() + 300
        containers = self.docker_run('ps', '-aq', '--no-trunc', '--filter', 'label=codex.retention.test=' + self.label)[0].decode().splitlines()
        images = self.docker_run('image', 'ls', '-aq', '--no-trunc', '--filter', 'label=codex.retention.test=' + self.label)[0].decode().splitlines()
        original_containers = {x['id'] for x in (self.baseline or {}).get('containers', [])}
        original_images = {x['id'] for x in (self.baseline or {}).get('images', [])}
        for container in sorted(set(containers)):
            require(CONTAINER_ID.fullmatch(container) is not None and container not in original_containers, 'teardown_container_scope')
            safe = self.docker_run('inspect', '--format', '{{.Id}} {{.State.Running}} {{.State.Status}} {{index .Config.Labels "codex.retention.test"}} {{.Name}}', container)[0].decode().split()
            require(safe[:4] == [container, 'false', 'created', self.label] and len(safe) == 5 and
                    re.fullmatch('/' + re.escape(self.label) + r'-container-(?:[0-9]|1[0-9]|2[0-3])', safe[4]) is not None,
                    'teardown_container_changed')
            self.docker_run('container', 'rm', container)
        for image in sorted(set(images)):
            require(IMAGE_ID.fullmatch(image) is not None and image not in original_images, 'teardown_image_scope')
            refs = json.loads(self.docker_run('image', 'inspect', '--format', '{"id":{{json .Id}},"label":{{json (index .Config.Labels "codex.retention.test")}},"tags":{{json .RepoTags}},"digests":{{json .RepoDigests}}}', image)[0])
            require(refs['id'] == image and refs['label'] == self.label and not refs['digests'] and
                    all(re.fullmatch(re.escape(self.namespace) + r':(?:image-(?:[0-9]|[1-9][0-9]|1[0-3][0-9]|14[0-5])|second-alias)', x)
                        is not None for x in (refs['tags'] or [])), 'teardown_image_identity')
            require(refs['tags'] or image in self.images, 'teardown_untagged_not_in_ledger')
            users = self.docker_run('ps', '-aq', '--no-trunc', '--filter', 'ancestor=' + image)[0].strip()
            require(not users, 'teardown_image_has_consumer')
            for tag in refs['tags'] or []:
                bound_id = self.docker_run('image', 'inspect', '--format', '{{.Id}}', tag)[0].decode().strip()
                require(bound_id == image, 'teardown_tag_rebound')
                self.docker_run('image', 'rm', '--no-prune', tag)
            remaining = self.docker_run('image', 'ls', '-aq', '--no-trunc')[0].decode().splitlines()
            if image in remaining:
                self.docker_run('image', 'rm', '--no-prune', image)
        if self.baseline is not None:
            final_bytes, _, _ = self.snapshot('old')
            require(final_bytes == self.baseline_bytes, 'original_inventory_not_restored')
        self.summary['teardown'] = 'PASS_EXACT_OWNED_ONLY'


def self_test():
    require(os.name == 'posix', 'self_test_linux_required')
    out, _, code, metrics = supervised(['bash', '-c', 'printf safe'], budget=3)
    require(out == b'safe' and code == 0 and metrics['wall_seconds'] >= 0, 'self_test_success')
    out, _, code, _ = supervised(['bash', '-c', 'printf partial; exit 7'], budget=3, allow_nonzero=True)
    require(code == 7 and out == b'partial', 'self_test_nonzero')
    for script in ('sleep 30 & exit 0', 'trap "" TERM; sleep 30',
                   'timeout --foreground --signal=TERM --kill-after=1 30s bash -c "sleep 30 & wait"'):
        try:
            supervised(['bash', '-c', script], budget=3)
        except ClosedFailure as error:
            require(str(error) in ('whole_group_timeout', 'residual_owned_process_group'), 'self_test_wrong_failure')
            require(not LAST_SUPERVISOR.get('residual_owned_group', True), 'self_test_residual_group')
            require(LAST_SUPERVISOR['wall_seconds'] < 4, 'self_test_deadline_overrun')
        else:
            raise ClosedFailure('self_test_descendant_accepted')
    print('IMAGE_INVENTORY_CI_SELF_TEST=PASS cases=5 real_docker=NOT_RUN production=NOT_RUN')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mode', choices=['correctness', 'benchmark', 'self-test'], default='correctness')
    mode = parser.parse_args().mode
    if mode == 'self-test':
        self_test()
        return
    signal_state = dict(cleaning=False, raised=False)
    def interrupt(signum, frame):
        # One interruption enters finally. During the separately bounded owned
        # cleanup, repeated TERM/INT must not interrupt midway through teardown.
        # SIGKILL/runner loss cannot be handled and remains a documented gap.
        if not signal_state['cleaning'] and not signal_state['raised']:
            signal_state['raised'] = True
            raise ClosedFailure('ci_interrupted')
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, interrupt)
    runner = InventoryCI(mode)
    failure = None
    try:
        runner.setup()
        runner.correctness()
        if mode == 'benchmark':
            runner.benchmark()
        runner.summary['status'] = 'PASS_CI_ONLY'
    except Exception as error:
        failure = str(error) if isinstance(error, ClosedFailure) else 'unexpected_local_error'
        runner.summary.update(status='FAIL', failure=failure)
    finally:
        signal_state['cleaning'] = True
        try:
            runner.teardown()
        except Exception:
            runner.summary.update(status='FAIL', teardown='FAIL_RETAIN_AMBIGUOUS_RESOURCES')
            failure = failure or 'teardown_failed'
        require(CANARY not in json.dumps(runner.summary), 'publication_canary')
        write_json(runner.publication / 'summary.json', runner.summary)
    require(failure is None, failure)
    print('IMAGE_INVENTORY_DOCKER_CI=PASS mode=' + mode + ' production_authority=false')


if __name__ == '__main__':
    try:
        main()
    except ClosedFailure as error:
        # Controlled codes only; never forward raw Docker stderr/JSON/arguments.
        code = str(error)
        if not re.fullmatch(r'[a-z0-9_]+', code):
            code = 'internal_validation_failure'
        print('IMAGE_INVENTORY_DOCKER_CI=NOT_RUN_OR_FAIL reason=' + code)
        raise SystemExit(2)
    except Exception:
        print('IMAGE_INVENTORY_DOCKER_CI=NOT_RUN_OR_FAIL reason=unexpected_local_error')
        raise SystemExit(2)
