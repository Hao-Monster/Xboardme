"""Observe one CI-only synthetic HTTP server and one never-reconnected TCP socket.

This proves fixture continuity only, not a production SLO or WebSocket behavior.
"""

import http.client
import signal
import socket
import sys
import time


def main() -> int:
    port = int(sys.argv[1])
    if not 1 <= port <= 65535:
        return 2
    stopping = False

    def stop(_signum: int, _frame: object) -> None:
        nonlocal stopping
        stopping = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    samples = 0
    with socket.create_connection(("127.0.0.1", port), timeout=2) as connection:
        connection.settimeout(2)
        while not stopping:
            connection.sendall(b"PING")
            response = b""
            while len(response) < 4:
                part = connection.recv(4 - len(response))
                if not part:
                    raise RuntimeError("synthetic persistent TCP connection closed")
                response += part
            if response != b"PONG":
                raise RuntimeError("synthetic persistent TCP reply mismatch")
            http = http.client.HTTPConnection("127.0.0.1", port, timeout=2)
            try:
                http.request("GET", "/health")
                reply = http.getresponse()
                if reply.status != 200 or reply.read() != b"synthetic-service-ok\r\n":
                    raise RuntimeError("synthetic HTTP content mismatch")
            finally:
                http.close()
            samples += 1
            if samples == 1:
                print("SYNTHETIC_OBSERVER_READY=true", flush=True)
            time.sleep(0.2)
    if samples < 1:
        raise RuntimeError("no synthetic continuity samples")
    print(f"SYNTHETIC_CONTINUITY=PASS samples={samples} reconnects=0", flush=True)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"SYNTHETIC_CONTINUITY=FAIL reason={exc}", file=sys.stderr, flush=True)
        raise SystemExit(1) from exc
