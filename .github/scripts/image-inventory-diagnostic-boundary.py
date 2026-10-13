#!/usr/bin/env python3
"""CI-only ownership boundary for the three fixed diagnostic pre-test commands.

No global process discovery or process-group kill. The subreaper follows only
its owned children, binds signals to pidfds, and reaps its adopted descendants.
Kernel stalls/SIGKILL/runner loss remain software-supervision limitations.
"""
import argparse
import ctypes
import importlib.util
import math
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import tempfile
import time

HERE = Path(__file__).resolve().parent


class BoundaryFailure(Exception):
    pass


def require(condition, code):
    if not condition:
        raise BoundaryFailure(code)


def bounded_proc(path):
    with path.open('rb') as stream:
        data = stream.read(8193)
    require(len(data) <= 8192, 'owned_proc_limit')
    return data.decode('ascii')


def identity(pid):
    text = bounded_proc(Path('/proc/%d/stat' % pid))
    fields = text.rsplit(') ', 1)
    require(len(fields) == 2 and fields[0].split(' ', 1)[0] == str(pid), 'owned_identity_invalid')
    fields = fields[1].split()
    require(len(fields) >= 20 and fields[1].isdigit() and fields[19].isdigit(), 'owned_identity_invalid')
    return int(fields[1]), int(fields[19])


class OwnedChildren:
    def __init__(self):
        require(hasattr(os, 'pidfd_open') and hasattr(signal, 'pidfd_send_signal'), 'owned_pidfd_required')
        require(ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0) == 0, 'owned_subreaper_required')
        self.pid = os.getpid()
        self.start = identity(self.pid)[1]
        self.entries = {}
        # Verify pidfd support before any command is started. No weak PID-only
        # fallback is allowed when the kernel or policy lacks this capability.
        fd = os.pidfd_open(self.pid)
        os.close(fd)

    def scan(self):
        # Parents reap their own short-lived children. Those pidfds must not
        # turn the simultaneous-live cap into a cumulative process counter.
        self.live()
        require(identity(self.pid)[1] == self.start, 'owned_parent_reused')
        # Only our direct children. Inspecting grandchildren while another
        # parent is execing/reaping them adds races and unnecessary overhead.
        # Recovery kills known parents then discovers the newly adopted roots.
        raw = bounded_proc(Path('/proc/%d/task/%d/children' % (self.pid, self.pid)))
        tokens = raw.split()
        require(len(tokens) <= 256 and all(re.fullmatch(r'[1-9][0-9]{0,14}', x) for x in tokens),
                'owned_children_invalid')
        for token in tokens:
            pid = int(token)
            require(pid > 1 and pid != self.pid, 'owned_child_invalid')
            fd = None
            try:
                ppid, started = identity(pid)
                require(ppid == self.pid, 'owned_child_parent_changed')
                fd = os.pidfd_open(pid)
                current_parent, current_start = identity(pid)
                require(current_start == started and current_parent == self.pid,
                        'owned_child_identity_changed')
                previous = self.entries.pop(pid, None)
                if previous is not None:
                    os.close(previous[1])
                self.entries[pid] = (started, fd)
                fd = None
                require(len(self.entries) <= 256, 'owned_descendant_limit')
            except (FileNotFoundError, ProcessLookupError):
                pass
            finally:
                if fd is not None:
                    os.close(fd)

    def reap(self, leader=None):
        if leader is not None:
            leader.poll()
        while True:
            try:
                pid, status = os.waitpid(-1, os.WNOHANG)
            except ChildProcessError:
                return
            if pid == 0:
                return
            if leader is not None and pid == leader.pid:
                leader.returncode = os.waitstatus_to_exitcode(status)
            entry = self.entries.pop(pid, None)
            if entry is not None:
                os.close(entry[1])

    def live(self):
        for pid, (started, fd) in list(self.entries.items()):
            try:
                current_start = identity(pid)[1]
            except FileNotFoundError:
                current_start = None
            if current_start != started:
                os.close(fd)
                del self.entries[pid]
        return bool(self.entries)

    def kill(self):
        failure = None
        for pid, (started, fd) in list(self.entries.items()):
            try:
                # pidfd prevents a PID reuse after this check from redirecting
                # the signal. This is never os.kill/killpg of a discovered PID.
                if identity(pid)[1] == started:
                    signal.pidfd_send_signal(fd, signal.SIGKILL)
            except (FileNotFoundError, ProcessLookupError):
                pass
            except BaseException as error:
                # A newly invalid identity must not prevent recovery of other
                # previously bound owned children. Still fail the boundary.
                failure = error
        if failure is not None:
            raise failure

    def close(self):
        for unused, fd in self.entries.values():
            os.close(fd)
        self.entries.clear()


def own_command(argv, *, work_deadline, recovery_deadline, cancellation_file):
    now = time.monotonic()
    require(all(type(x) in (int, float) and math.isfinite(x) for x in (work_deadline, recovery_deadline)) and
            now < work_deadline < recovery_deadline and recovery_deadline - work_deadline <= 5 and
            recovery_deadline - now <= 120, 'owned_deadline_invalid')
    owned = OwnedChildren()
    cancelled, process, control, failure = [False], None, None, None
    old_handlers = {}

    def interrupt(signum, frame):
        # Never raise between Popen creating a child and returning its PID.
        cancelled[0] = True

    try:
        for sig in (signal.SIGINT, signal.SIGTERM):
            old_handlers[sig] = signal.signal(sig, interrupt)
        control = os.open(cancellation_file, os.O_RDONLY | os.O_NOFOLLOW)
        info = os.fstat(control)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid() and info.st_size == 1,
                'owned_control_invalid')
        require(os.pread(control, 2, 0) in (b'0', b'1'), 'owned_control_invalid')
        if os.pread(control, 2, 0) == b'1' or cancelled[0]:
            return 125
        require(time.monotonic() < work_deadline, 'owned_work_deadline')
        process = subprocess.Popen(argv)
        while True:
            owned.scan()
            owned.reap(process)
            cancelled[0] |= os.pread(control, 2, 0) == b'1'
            if cancelled[0]:
                failure = 'owned_cancelled'
                break
            if time.monotonic() >= work_deadline:
                failure = 'owned_work_deadline'
                break
            if process.poll() is not None:
                # Death of the leader may have adopted another session since
                # the first scan. Check it before accepting normal completion.
                owned.scan()
                owned.reap(process)
                if owned.live():
                    failure = 'owned_residual_descendant'
                break
            time.sleep(0.01)
    except BaseException as error:
        failure = str(error) if isinstance(error, BoundaryFailure) else (
            'owned_process_disappeared' if isinstance(error, ProcessLookupError) else
            'owned_file_disappeared' if isinstance(error, FileNotFoundError) else
            'owned_os_error_%s' % error.errno if isinstance(error, OSError) and type(error.errno) is int else
            'owned_value_error' if isinstance(error, ValueError) else
            'owned_boundary_failure')
    finally:
        # Even a creation/registration exception cannot bypass adoption. Keep
        # discovering self-owned children after killing known parents; this
        # catches grandchildren spawned by nonleader threads after adoption.
        if process is not None or failure is not None:
            # Early cancellation cannot borrow the unused normal-work window.
            # This one effective end is never renewed by signals or failures.
            effective_end = min(recovery_deadline, time.monotonic() + 5)
            recovered = False
            while time.monotonic() < effective_end:
                valid_scan = False
                for index, operation in enumerate((owned.scan, owned.kill, lambda: owned.reap(process), owned.scan)):
                    if time.monotonic() >= effective_end:
                        break
                    try:
                        operation()
                        if index == 3:
                            valid_scan = True
                    except BaseException as error:
                        valid_scan = False
                        failure = ('owned_recovery_' + str(error)) if isinstance(error, BoundaryFailure) else 'owned_recovery_incomplete'
                try:
                    if valid_scan and not owned.live() and (process is None or process.poll() is not None):
                        recovered = time.monotonic() <= effective_end
                        break
                except BaseException:
                    failure = 'owned_recovery_incomplete'
                remaining = effective_end - time.monotonic()
                if remaining > 0:
                    time.sleep(min(0.01, remaining))
            if not recovered or time.monotonic() > effective_end:
                failure = 'owned_recovery_incomplete'
        if control is not None:
            os.close(control)
        owned.close()
        for sig, handler in old_handlers.items():
            signal.signal(sig, handler)
    if failure:
        print('IMAGE_DIAGNOSTIC_OWNED=FAIL reason=' + failure, flush=True)
    return 125 if failure else process.returncode


def run_pretest(ci, name, deadline, cancelled):
    start = time.monotonic()
    budget = min(120, deadline - start)
    ci.require(budget > 10, 'diagnostic_pretest_budget')
    # Absolute deadlines are computed BEFORE Python startup and never reset in
    # the wrapper. Normal work+reaping finish before outer TERM(budget-2).
    outer_deadline = start + budget
    with tempfile.TemporaryDirectory(prefix='image-diagnostic-control.', dir=os.environ['RUNNER_TEMP']) as temporary:
        control = Path(temporary) / 'cancel'
        fd = os.open(control, os.O_CREAT | os.O_EXCL | os.O_RDWR, 0o600)
        try:
            os.write(fd, b'0')
            def forward_cancel():
                if cancelled():
                    os.pwrite(fd, b'1', 0)
            remaining = outer_deadline - time.monotonic()
            ci.require(remaining > 10, 'diagnostic_pretest_budget')
            out, err, status, metrics = ci.supervised([
                sys.executable, '-B', str(Path(__file__)), '--pretest', name,
                '--work-deadline', repr(outer_deadline - 8),
                '--recovery-deadline', repr(outer_deadline - 3), '--cancellation-file', str(control)],
                budget=remaining, allow_nonzero=True, abort_check=forward_cancel)
            # Original command output is bounded by the existing supervisor but
            # is deliberately not forwarded to the diagnostic public artifact.
            return status
        finally:
            os.close(fd)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--pretest', required=True, choices=['parser-watchdog', 'supervisor-self-test', 'ci-boundary'])
    parser.add_argument('--work-deadline', required=True, type=float)
    parser.add_argument('--recovery-deadline', required=True, type=float)
    parser.add_argument('--cancellation-file', required=True)
    options = parser.parse_args()
    spec = importlib.util.spec_from_file_location('owned_diagnostic_guard', HERE / 'image-inventory-diagnostic.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    module.diagnostic_guard()
    command = dict(module.PRETESTS)[options.pretest]
    return own_command(command, work_deadline=options.work_deadline, recovery_deadline=options.recovery_deadline,
                       cancellation_file=Path(options.cancellation_file))


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception:
        print('IMAGE_INVENTORY_DIAGNOSTIC_BOUNDARY=FAIL')
        raise SystemExit(125)
