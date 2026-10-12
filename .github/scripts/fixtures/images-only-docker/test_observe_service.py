"""Real loopback-socket regression for the CI-only continuity observer.

No Docker daemon, application or production endpoint is used. POSIX runs send
SIGTERM to the child process. Windows has no equivalent external SIGTERM, so a
test-only stdin bridge raises SIGTERM inside that same child instead; the real
observer signal handler and socket I/O are unchanged.
"""

import os
from pathlib import Path
import re
import signal
import socket
import socketserver
import subprocess
import sys
import threading
import unittest


OBSERVER = Path(__file__).with_name("observe_service.py")
BODY = b"synthetic-service-ok\r\n"
WINDOWS_SIGNAL_BRIDGE = """
import os, runpy, signal, sys, threading
sys.argv = sys.argv[1:]
def stop_requested():
    if os.read(sys.stdin.fileno(), 1):
        signal.raise_signal(signal.SIGTERM)
threading.Thread(target=stop_requested, daemon=True).start()
runpy.run_path(sys.argv[0], run_name="__main__")
"""


class FixtureServer(socketserver.ThreadingTCPServer):
    daemon_threads = True

    def __init__(self, *, disconnect_at_ping=0, status=200, body=BODY):
        self.condition = threading.Condition()
        self.disconnect_at_ping = disconnect_at_ping
        self.http_status = status
        self.body = body
        self.tcp_connections = 0
        self.pings = 0
        self.http_requests = 0
        self.errors = []
        super().__init__(("127.0.0.1", 0), FixtureHandler)
        self.thread = threading.Thread(
            target=self.serve_forever, kwargs={"poll_interval": 0.01}, daemon=True
        )
        self.thread.start()

    def server_close(self):
        self.shutdown()
        super().server_close()
        self.thread.join(timeout=3)


class FixtureHandler(socketserver.BaseRequestHandler):
    def exact(self, count):
        data = b""
        while len(data) < count:
            chunk = self.request.recv(count - len(data))
            if not chunk:
                return data
            data += chunk
        return data

    def handle(self):
        self.request.settimeout(3)
        server = self.server
        try:
            prefix = self.exact(4)
            if prefix == b"PING":
                with server.condition:
                    server.tcp_connections += 1
                while prefix:
                    if prefix != b"PING":
                        raise AssertionError("unexpected persistent TCP request")
                    with server.condition:
                        server.pings += 1
                        disconnect = server.pings == server.disconnect_at_ping
                        server.condition.notify_all()
                    if disconnect:
                        self.request.shutdown(socket.SHUT_RDWR)
                        return
                    self.request.sendall(b"PONG")
                    prefix = self.exact(4)
            elif prefix == b"GET ":
                request = prefix
                while not request.endswith(b"\r\n\r\n"):
                    chunk = self.exact(1)
                    if not chunk or len(request) >= 8192:
                        raise AssertionError("invalid HTTP request framing")
                    request += chunk
                if not request.startswith(b"GET /health HTTP/1.1\r\n"):
                    raise AssertionError("unexpected HTTP endpoint")
                response = (
                    f"HTTP/1.1 {server.http_status} Synthetic\r\n"
                    f"Content-Length: {len(server.body)}\r\n"
                    "Connection: close\r\nContent-Type: text/plain\r\n\r\n"
                ).encode("ascii") + server.body
                self.request.sendall(response)
                with server.condition:
                    server.http_requests += 1
                    server.condition.notify_all()
            else:
                raise AssertionError("unexpected synthetic protocol")
        except Exception as exc:
            with server.condition:
                server.errors.append(repr(exc))
                server.condition.notify_all()


class ObserverTests(unittest.TestCase):
    def start_observer(self, server):
        command = [sys.executable, "-u"]
        if os.name == "nt":
            command += ["-c", WINDOWS_SIGNAL_BRIDGE]
        command += [str(OBSERVER), str(server.server_address[1])]
        process = subprocess.Popen(
            command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True,
        )

        def notify_exit():
            process.wait()
            with server.condition:
                server.condition.notify_all()

        watcher = threading.Thread(target=notify_exit, daemon=True)
        watcher.start()

        def cleanup():
            if process.poll() is None:
                process.kill()
            process.communicate(timeout=5)
            watcher.join(timeout=3)

        self.addCleanup(cleanup)
        return process

    def wait_for_http(self, server, process, count):
        with server.condition:
            ready = server.condition.wait_for(
                lambda: server.http_requests >= count
                or process.poll() is not None or server.errors,
                timeout=6,
            )
        if process.poll() is not None:
            stdout, stderr = process.communicate(timeout=5)
            self.fail(f"observer exited before HTTP sample: {stdout}{stderr}")
        self.assertTrue(ready, "observer did not produce bounded HTTP samples")
        self.assertEqual(server.errors, [])
        self.assertGreaterEqual(server.http_requests, count)

    def stop_observer(self, process):
        if os.name == "nt":
            process.stdin.write("T")
            process.stdin.flush()
        else:
            process.send_signal(signal.SIGTERM)
        return process.communicate(timeout=5)

    def test_success_reuses_tcp_connection_and_checks_http(self):
        with FixtureServer() as server:
            process = self.start_observer(server)
            self.wait_for_http(server, process, 3)
            stdout, stderr = self.stop_observer(process)
            self.assertEqual(process.returncode, 0, stderr)
            self.assertEqual(stderr, "")
            self.assertIn("SYNTHETIC_OBSERVER_READY=true", stdout)
            match = re.search(
                r"SYNTHETIC_CONTINUITY=PASS samples=(\d+) reconnects=0", stdout
            )
            self.assertIsNotNone(match, stdout)
            self.assertGreaterEqual(int(match.group(1)), 3)
            self.assertEqual(server.tcp_connections, 1)
            self.assertEqual(server.pings, server.http_requests)
            self.assertEqual(server.errors, [])

    def test_tcp_disconnect_fails_without_reconnecting(self):
        with FixtureServer(disconnect_at_ping=2) as server:
            process = self.start_observer(server)
            stdout, stderr = process.communicate(timeout=6)
            self.assertEqual(process.returncode, 1, stdout + stderr)
            self.assertIn("synthetic persistent TCP connection closed", stderr)
            self.assertNotIn("SYNTHETIC_CONTINUITY=PASS", stdout)
            self.assertEqual(server.tcp_connections, 1)
            self.assertEqual(server.http_requests, 1)
            self.assertEqual(server.errors, [])

    def assert_http_failure(self, **options):
        with FixtureServer(**options) as server:
            process = self.start_observer(server)
            stdout, stderr = process.communicate(timeout=6)
            self.assertEqual(process.returncode, 1, stdout + stderr)
            self.assertIn("synthetic HTTP content mismatch", stderr)
            self.assertNotIn("SYNTHETIC_CONTINUITY=PASS", stdout)
            self.assertEqual(server.tcp_connections, 1)
            self.assertEqual(server.http_requests, 1)
            self.assertEqual(server.errors, [])

    def test_http_error_status_fails(self):
        self.assert_http_failure(status=503)

    def test_http_wrong_body_fails(self):
        self.assert_http_failure(body=b"unexpected-body\r\n")

    def test_sigterm_finishes_a_ready_observation(self):
        with FixtureServer() as server:
            process = self.start_observer(server)
            self.wait_for_http(server, process, 1)
            stdout, stderr = self.stop_observer(process)
            self.assertEqual(process.returncode, 0, stderr)
            self.assertIn("SYNTHETIC_OBSERVER_READY=true", stdout)
            self.assertIn("SYNTHETIC_CONTINUITY=PASS", stdout)
            self.assertEqual(server.tcp_connections, 1)
            self.assertEqual(server.errors, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
