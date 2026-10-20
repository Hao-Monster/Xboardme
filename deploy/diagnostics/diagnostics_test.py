#!/usr/bin/env python3
"""Unit and isolated integration tests for D6 (T11, T12, T17, T18)."""
from __future__ import annotations

from collections import namedtuple
import datetime
import gzip
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parent))

import configure  # noqa: E402
import guard  # noqa: E402

DiskUsage = namedtuple("DiskUsage", ["total", "used", "free"])

GIB = 1024**3
PANEL_SCOPE = "11111111-2222-4333-8444-555555555555"
DIAG_ID = "0123456789abcdef01234567"
CONN_ID = "abcdef0123456789abcdef0123456789"
RUNTIME_ID = "99999999999999999999999999999999"
EVENT_ID_1 = "a" * 64


def sample_connection_event(**overrides: object) -> dict[str, object]:
    base: dict[str, object] = {
        "schema_version": 1,
        "event_time": "2026-10-18T10:00:01.000000Z",
        "event_id": EVENT_ID_1,
        "event_family": "connection",
        "panel_scope": PANEL_SCOPE,
        "node_id": 101,
        "runtime_id": RUNTIME_ID,
        "diagnostic_id": DIAG_ID,
        "connection_id": CONN_ID,
        "seq": 1,
        "event": "diag.connection.close",
        "stage": "close",
        "network": "tcp",
        "observation_mode": "baseline",
        "trace_id": CONN_ID,
        "span_id": "1122334455667788",
        "parent_span_id": "",
        "span_name": "connection",
        "span_start_time": "2026-10-18T10:00:00.000000Z",
        "span_duration_ms": 1000,
        "span_status": "error",
        "transport_state": "dial_failed",
        "has_error": True,
        "error_stages": ["dial"],
        "reason_codes": ["connect_timeout"],
        "last_error_stage": "dial",
        "last_reason_code": "connect_timeout",
        "duration_ms": 1000,
        "bytes_up": 0,
        "bytes_down": 0,
        "close_reason": "connect_timeout",
        "safe_message": "dial tcp timeout",
    }
    base.update(overrides)
    return base


class DiagnosticsConfigAndGuardTest(unittest.TestCase):
    def test_central_and_collector_generation_enforces_isolation_and_whitelists(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            central_dir = Path(tmp) / "central"
            res = configure.generate_central(
                directory=central_dir,
                panel_scope=PANEL_SCOPE,
                node_ids=[101, 102],
                storage_id="pilot-bingo-dev-v1",
                listen_port=19438,
                expected_fs_uuid="deadbeef-1234",
            )
            self.assertEqual(res["project"], "xboard-customer-diagnostics-pilot")

            compose = json.loads((central_dir / "compose.yaml").read_text(encoding="utf-8"))
            vl = compose["services"]["victoria-logs"]
            self.assertEqual(vl["restart"], "no")
            self.assertEqual(vl["cpus"], "1.0")
            self.assertEqual(vl["mem_limit"], "1536m")
            self.assertIn("-retentionPeriod=3d", vl["command"])
            self.assertIn("-retention.maxDiskSpaceUsageBytes=20GiB", vl["command"])
            self.assertIn("-storage.minFreeDiskSpaceBytes=5368709120", vl["command"])
            self.assertFalse(vl["volumes"][0]["bind"]["create_host_path"])

            auth_cfg = json.loads((central_dir / "auth.json").read_text(encoding="utf-8"))
            secrets_cfg = json.loads((central_dir / "secrets.json").read_text(encoding="utf-8"))
            self.assertIn("101", secrets_cfg["writers"])
            self.assertIn("102", secrets_cfg["writers"])
            self.assertNotEqual(
                secrets_cfg["writers"]["101"]["bearer_token"],
                secrets_cfg["writers"]["102"]["bearer_token"],
            )
            self.assertNotEqual(
                secrets_cfg["viewer"]["bearer_token"],
                secrets_cfg["writers"]["101"]["bearer_token"],
            )

            # Verify viewer routes bypass write guard for queries and cannot access /insert/jsonline
            viewer_entry = auth_cfg["users"][0]
            viewer_paths = [m["src_paths"][0] for m in viewer_entry["url_map"]]
            self.assertEqual(viewer_paths, ["/select/logsql/query", "/diagnostics/health"])
            self.assertEqual(
                viewer_entry["url_map"][0]["url_prefix"], "http://victoria-logs:9428"
            )

            # Verify collector generation creates isolated 2 GiB disk buffer & VRL whitelist
            token_file = Path(tmp) / "writer-101.token"
            token_file.write_text(
                secrets_cfg["writers"]["101"]["bearer_token"], encoding="utf-8"
            )
            collector_dir = Path(tmp) / "collector-101"
            configure.generate_collector(
                directory=collector_dir,
                panel_scope=PANEL_SCOPE,
                node_id=101,
                collector_id="collector-node-101",
                token=token_file.read_text(encoding="utf-8"),
                tag="xboard-node-101",
                endpoint="http://127.0.0.1:19438",
                unit="xboard-node.service",
            )
            col_yaml = json.loads(
                (collector_dir / "collector.yaml").read_text(encoding="utf-8")
            )
            sink = col_yaml["sinks"]["diagnostics_node_101"]
            self.assertEqual(sink["uri"], "http://127.0.0.1:19438/insert/jsonline")
            self.assertEqual(sink["buffer"]["type"], "disk")
            self.assertEqual(sink["buffer"]["max_size"], 2147483648)
            self.assertEqual(sink["buffer"]["when_full"], "block")
            self.assertEqual(sink["batch"]["timeout_secs"], 2)

    def test_sanitize_event_line_overrides_forged_identity_and_rejects_pii_or_bad_time(self) -> None:
        now_utc = datetime.datetime(2026, 10, 18, 10, 0, 5, tzinfo=datetime.timezone.utc)

        # 1. Valid line with forged node_id and panel_scope in JSON body -> overwritten by server identity
        forged_body = sample_connection_event(
            node_id=999,
            panel_scope="99999999-9999-4999-8999-999999999999",
            unlisted_extra_field="should_be_stripped",
        )
        cleaned, reason = guard.sanitize_and_validate_event_line(
            json.dumps(forged_body),
            enforced_panel_scope=PANEL_SCOPE,
            enforced_node_id=101,
            enforced_collector_id="collector-node-101",
            now_utc=now_utc,
        )
        self.assertIsNotNone(cleaned, reason)
        assert cleaned is not None
        self.assertEqual(cleaned["node_id"], 101)
        self.assertEqual(cleaned["panel_scope"], PANEL_SCOPE)
        self.assertEqual(cleaned["collector_id"], "collector-node-101")
        self.assertNotIn("unlisted_extra_field", cleaned)

        # 2. Forbidden PII field (uuid, source_ip, trade_no) -> rejected
        for forbidden_key in ("uuid", "user_id", "source_ip", "trade_no", "sni"):
            with_pii = sample_connection_event(**{forbidden_key: "secret"})
            rejected, err_code = guard.sanitize_and_validate_event_line(
                json.dumps(with_pii),
                enforced_panel_scope=PANEL_SCOPE,
                enforced_node_id=101,
                enforced_collector_id="collector-node-101",
                now_utc=now_utc,
            )
            self.assertIsNone(rejected)
            self.assertIn("forbidden_field", err_code)

        # 3. Missing, invalid, or >3d old event_time -> suppressed (never falls back to now())
        old_event = sample_connection_event(event_time="2026-10-10T10:00:00Z")
        rejected_old, err_old = guard.sanitize_and_validate_event_line(
            json.dumps(old_event),
            enforced_panel_scope=PANEL_SCOPE,
            enforced_node_id=101,
            enforced_collector_id="collector-node-101",
            now_utc=now_utc,
        )
        self.assertIsNone(rejected_old)
        self.assertEqual(err_old, "event_time_exceeds_3d_retention")

        # 4. Resolver operation event with customer diagnostic_id -> rejected
        bad_resolver = {
            "schema_version": 1,
            "event_time": "2026-10-18T10:00:01.000000Z",
            "event_id": EVENT_ID_1,
            "event_family": "resolver_operation",
            "panel_scope": PANEL_SCOPE,
            "node_id": 101,
            "runtime_id": RUNTIME_ID,
            "resolver_operation_id": CONN_ID,
            "diagnostic_id": DIAG_ID,
            "operation_seq": 1,
            "event": "diag.dns.exchange.result",
            "stage": "dns",
            "observation_mode": "baseline",
        }
        rejected_res, err_res = guard.sanitize_and_validate_event_line(
            json.dumps(bad_resolver),
            enforced_panel_scope=PANEL_SCOPE,
            enforced_node_id=101,
            enforced_collector_id="collector-node-101",
            now_utc=now_utc,
        )
        self.assertIsNone(rejected_res)
        self.assertEqual(err_res, "invalid_resolver_identifiers")

    def test_capacity_guard_thresholds_latch_and_persistence_across_restart(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            central_dir = Path(tmp) / "central"
            configure.generate_central(
                directory=central_dir,
                panel_scope=PANEL_SCOPE,
                node_ids=[101],
                expected_fs_uuid="uuid-ext4-30g",
            )
            cfg = json.loads(
                (central_dir / "guard-config.json").read_text(encoding="utf-8")
            )
            cfg["state_dir"] = str(central_dir / "state")
            cfg["data_mount_path"] = str(central_dir / "data")
            cfg["root_mount_path"] = str(central_dir / "root")

            mono_now = [1000.0]
            data_usage = [DiskUsage(30 * GIB, 4 * GIB, 26 * GIB)]
            root_usage = [DiskUsage(240 * GIB, 120 * GIB, 120 * GIB)]
            mount_state = [
                {
                    "mounted": True,
                    "fs_type": "ext4",
                    "fs_uuid": "uuid-ext4-30g",
                    "writable": True,
                }
            ]

            def disk_fn(path: Path) -> DiskUsage:
                if str(path) == cfg["data_mount_path"]:
                    return data_usage[0]
                return root_usage[0]

            g = guard.DiagnosticsCapacityGuard(
                cfg,
                monotonic_fn=lambda: mono_now[0],
                now_utc_fn=lambda: datetime.datetime(
                    2026, 10, 18, 12, 0, 0, tzinfo=datetime.timezone.utc
                ),
                disk_usage_fn=disk_fn,
                mount_inspector_fn=lambda _d, _r: mount_state[0],
            )

            # 1. Default state before explicit enable is blocked (`not_yet_enabled`)
            h0 = g.run_check()
            self.assertEqual(h0["state"], "blocked")
            self.assertTrue(h0["latched"])
            self.assertIn("not_yet_enabled", h0["reason_codes"])

            # 2. Explicit operator enable transitions to ready
            h1 = g.enable_gate("pilot_authorized_enable")
            self.assertEqual(h1["state"], "ready")
            self.assertFalse(h1["latched"])

            # 3. 16 GiB warning and 20 GiB critical do NOT block ingestion and do NOT falsely set retention_shortened
            data_usage[0] = DiskUsage(30 * GIB, 20 * GIB, 10 * GIB)
            mono_now[0] += 15.0
            h_crit = g.run_check()
            self.assertEqual(h_crit["state"], "ready")
            self.assertFalse(h_crit["latched"])
            self.assertFalse(h_crit["retention_shortened"])
            self.assertIn("capacity_warning_16gib", h_crit["reason_codes"])
            self.assertIn("capacity_critical_20gib", h_crit["reason_codes"])

            # 4. 24 GiB usage triggers block and persistent latch
            data_usage[0] = DiskUsage(30 * GIB, 24 * GIB, 6 * GIB)
            mono_now[0] += 15.0
            h_blocked = g.run_check()
            self.assertEqual(h_blocked["state"], "blocked")
            self.assertTrue(h_blocked["latched"])
            self.assertIn("capacity_blocked_used_24gib", h_blocked["reason_codes"])

            # 5. Space recovers to 5 GiB used, but latch MUST remain blocked until explicit resume!
            data_usage[0] = DiskUsage(30 * GIB, 5 * GIB, 25 * GIB)
            mono_now[0] += 15.0
            h_still_latched = g.run_check()
            self.assertEqual(h_still_latched["state"], "blocked")
            self.assertTrue(h_still_latched["latched"])
            self.assertTrue(h_still_latched["recoverable"])

            # 6. Restart guard instance -> latch persists from state/guard-state.json
            g_restarted = guard.DiagnosticsCapacityGuard(
                cfg,
                monotonic_fn=lambda: mono_now[0],
                now_utc_fn=lambda: datetime.datetime(
                    2026, 10, 18, 12, 1, 0, tzinfo=datetime.timezone.utc
                ),
                disk_usage_fn=disk_fn,
                mount_inspector_fn=lambda _d, _r: mount_state[0],
            )
            h_after_restart = g_restarted.run_check()
            self.assertEqual(h_after_restart["state"], "blocked")
            self.assertTrue(h_after_restart["latched"])

            # 7. Explicit resume clears latch
            h_resumed = g_restarted.enable_gate("verified_space_and_resumed")
            self.assertEqual(h_resumed["state"], "ready")
            self.assertFalse(h_resumed["latched"])

            # 8. Monotonic check staleness (>60s without check) latches blocked
            mono_now[0] += 65.0
            h_stale = g_restarted.get_health()
            self.assertEqual(h_stale["state"], "blocked")
            self.assertTrue(h_stale["latched"])
            self.assertIn("check_stale_exceeded_60s", h_stale["reason_codes"])

    def test_http_guard_rejects_forgery_and_blocks_writes_when_latched(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            central_dir = Path(tmp) / "central"
            configure.generate_central(
                directory=central_dir,
                panel_scope=PANEL_SCOPE,
                node_ids=[101],
            )
            cfg = json.loads(
                (central_dir / "guard-config.json").read_text(encoding="utf-8")
            )
            cfg["state_dir"] = str(central_dir / "state")
            cfg["data_mount_path"] = str(central_dir / "data")
            cfg["root_mount_path"] = str(central_dir / "root")

            # Start a tiny fake VictoriaLogs upstream
            received_lines: list[dict[str, object]] = []
            received_paths: list[str] = []

            class FakeVLHandler(guard.BaseHTTPRequestHandler):
                def log_message(self, format: str, *args: object) -> None:
                    return

                def do_POST(self) -> None:
                    received_paths.append(self.path)
                    length = int(self.headers.get("Content-Length", "0"))
                    body = self.rfile.read(length).decode("utf-8")
                    for line in body.splitlines():
                        if line.strip():
                            received_lines.append(json.loads(line))
                    self.send_response(204)
                    self.end_headers()

            vl_server = guard.ThreadingHTTPServer(("127.0.0.1", 0), FakeVLHandler)
            vl_port = vl_server.server_address[1]
            vl_thread = threading.Thread(target=vl_server.serve_forever, daemon=True)
            vl_thread.start()

            cfg["upstream_insert_url"] = f"http://127.0.0.1:{vl_port}/insert/jsonline"

            g = guard.DiagnosticsCapacityGuard(
                cfg,
                monotonic_fn=lambda: 100.0,
                now_utc_fn=lambda: datetime.datetime(
                    2026, 10, 18, 10, 0, 5, tzinfo=datetime.timezone.utc
                ),
                disk_usage_fn=lambda _p: DiskUsage(240 * GIB, 8 * GIB, 180 * GIB),
                mount_inspector_fn=lambda _d, _r: {
                    "mounted": True,
                    "fs_type": "ext4",
                    "fs_uuid": "",
                    "writable": True,
                },
            )
            g.enable_gate("test_ready")

            guard_server = guard.ThreadingHTTPServer(
                ("127.0.0.1", 0), guard.make_http_handler(g)
            )
            guard_port = guard_server.server_address[1]
            guard_thread = threading.Thread(
                target=guard_server.serve_forever, daemon=True
            )
            guard_thread.start()

            try:
                # 1. GET /diagnostics/health returns 200 ready
                with urllib.request.urlopen(
                    f"http://127.0.0.1:{guard_port}/diagnostics/health", timeout=2
                ) as resp:
                    self.assertEqual(resp.getcode(), 200)
                    h_data = json.loads(resp.read().decode("utf-8"))
                    self.assertEqual(h_data["state"], "ready")

                # 2. Duplicate query parameter forgery -> 400
                valid_line = json.dumps(sample_connection_event()).encode("utf-8")
                trusted_headers = {
                    "X-Diagnostics-Panel-Scope": PANEL_SCOPE,
                    "X-Diagnostics-Node-Id": "101",
                    "X-Diagnostics-Collector-Id": "collector-node-101",
                    "Content-Type": "application/x-ndjson",
                }
                req_dup = urllib.request.Request(
                    f"http://127.0.0.1:{guard_port}/insert/jsonline?extra_fields=node_id=101&extra_fields=node_id=999",
                    data=valid_line,
                    headers=trusted_headers,
                    method="POST",
                )
                with self.assertRaises(urllib.error.HTTPError) as ctx_dup:
                    urllib.request.urlopen(req_dup, timeout=2)
                self.assertEqual(ctx_dup.exception.code, 400)

                # 2b. Single forged extra_fields -> 403 forged_extra_fields
                req_forged_extra = urllib.request.Request(
                    f"http://127.0.0.1:{guard_port}/insert/jsonline?extra_fields=node_id=999",
                    data=valid_line,
                    headers=trusted_headers,
                    method="POST",
                )
                with self.assertRaises(urllib.error.HTTPError) as ctx_forged_extra:
                    urllib.request.urlopen(req_forged_extra, timeout=2)
                self.assertEqual(ctx_forged_extra.exception.code, 403)

                # 3. Forbidden tenant header forgery (`AccountID` or `VL-Extra-Fields`) -> 403
                bad_headers = dict(trusted_headers)
                bad_headers["VL-Extra-Fields"] = "node_id=999"
                req_hdr = urllib.request.Request(
                    f"http://127.0.0.1:{guard_port}/insert/jsonline",
                    data=valid_line,
                    headers=bad_headers,
                    method="POST",
                )
                with self.assertRaises(urllib.error.HTTPError) as ctx_hdr:
                    urllib.request.urlopen(req_hdr, timeout=2)
                self.assertEqual(ctx_hdr.exception.code, 403)

                # 4. Valid insert using exact vmauth url_prefix query + forged node_id in body -> accepted (200), body overwritten to node_id=101, and canonical extra_fields forwarded
                forged_line = json.dumps(
                    sample_connection_event(node_id=999)
                ).encode("utf-8")
                vmauth_query = (
                    "_time_field=event_time"
                    "&_msg_field=safe_message"
                    "&_stream_fields=panel_scope,node_id,service,event_family"
                    f"&extra_fields=panel_scope={PANEL_SCOPE},node_id=101,collector_id=collector-node-101,service=xboard-node"
                )
                req_ok = urllib.request.Request(
                    f"http://127.0.0.1:{guard_port}/insert/jsonline?{vmauth_query}",
                    data=forged_line,
                    headers=trusted_headers,
                    method="POST",
                )
                with urllib.request.urlopen(req_ok, timeout=2) as resp_ok:
                    self.assertEqual(resp_ok.getcode(), 200)
                self.assertEqual(len(received_lines), 1)
                self.assertEqual(received_lines[0]["node_id"], 101)
                self.assertEqual(received_lines[0]["panel_scope"], PANEL_SCOPE)
                self.assertEqual(len(received_paths), 1)
                self.assertIn("extra_fields=", received_paths[0])

                # 5. Gzip-compressed insert from Vector collector -> accepted (200) and decompressed NDJSON forwarded to VictoriaLogs
                gzip_headers = dict(trusted_headers)
                gzip_headers["Content-Encoding"] = "gzip"
                req_gzip = urllib.request.Request(
                    f"http://127.0.0.1:{guard_port}/insert/jsonline?{vmauth_query}",
                    data=gzip.compress(forged_line + b"\n"),
                    headers=gzip_headers,
                    method="POST",
                )
                with urllib.request.urlopen(req_gzip, timeout=2) as resp_gzip:
                    self.assertEqual(resp_gzip.getcode(), 200)
                self.assertEqual(len(received_lines), 2)
                self.assertEqual(received_lines[1]["node_id"], 101)

                # 6. Malformed gzip payload (invalid header and valid header with corrupt DEFLATE stream) -> 400 invalid_gzip_payload
                for bad_gzip_payload in (
                    b"not-a-valid-gzip-stream",
                    b"\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\x03\x07" + b"\x00" * 8,
                ):
                    req_bad_gzip = urllib.request.Request(
                        f"http://127.0.0.1:{guard_port}/insert/jsonline",
                        data=bad_gzip_payload,
                        headers=gzip_headers,
                        method="POST",
                    )
                    with self.assertRaises(urllib.error.HTTPError) as ctx_bad_gzip:
                        urllib.request.urlopen(req_bad_gzip, timeout=2)
                    self.assertEqual(ctx_bad_gzip.exception.code, 400)
            finally:
                guard_server.shutdown()
                guard_server.server_close()
                vl_server.shutdown()
                vl_server.server_close()

    def test_generated_guard_standalone_startup_and_readonly_mount_inspection(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            central_dir = Path(tmp) / "central"
            configure.generate_central(
                directory=central_dir,
                panel_scope=PANEL_SCOPE,
                node_ids=[101],
            )
            self.assertTrue((central_dir / "guard.py").exists())
            self.assertTrue((central_dir / "configure.py").exists())

            compose = json.loads(
                (central_dir / "compose.yaml").read_text(encoding="utf-8")
            )
            guard_volumes = compose["services"]["diagnostics-guard"]["volumes"]
            volume_targets = {v["target"]: v for v in guard_volumes}
            self.assertIn("/app/guard.py", volume_targets)
            self.assertIn("/app/configure.py", volume_targets)
            self.assertTrue(volume_targets["/storage"]["read_only"])

            clean_env = {
                k: v for k, v in os.environ.items() if k != "PYTHONPATH"
            }
            proc = subprocess.run(
                [sys.executable, "-B", str(central_dir / "guard.py"), "--help"],
                cwd=central_dir,
                env=clean_env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)

            cfg = json.loads(
                (central_dir / "guard-config.json").read_text(encoding="utf-8")
            )
            cfg["state_dir"] = str(central_dir / "state")
            cfg["data_mount_path"] = str(central_dir / "data")
            cfg["root_mount_path"] = str(central_dir / "root")

            g = guard.DiagnosticsCapacityGuard(
                cfg,
                monotonic_fn=lambda: 100.0,
                now_utc_fn=lambda: datetime.datetime(
                    2026, 10, 18, 10, 0, 5, tzinfo=datetime.timezone.utc
                ),
                disk_usage_fn=lambda _p: DiskUsage(240 * GIB, 8 * GIB, 180 * GIB),
            )
            resolved = str(g.data_mount_path.resolve())
            host_mirror_path = "/host-root/opt/xboard/customer-diagnostics/data"
            g.host_data_mount_path = host_mirror_path
            headers = {
                "X-Diagnostics-Panel-Scope": PANEL_SCOPE,
                "X-Diagnostics-Node-Id": "101",
                "X-Diagnostics-Collector-Id": "collector-node-101",
            }
            event_body = (json.dumps(sample_connection_event()) + "\n").encode("utf-8")

            # 1. Normal read-only observer mount (and recursive /host-root ro) with rw underlying ext4 superblock -> ready
            mounts_text = (
                f"/dev/loop17 {resolved} ext4 ro,relatime 0 0\n"
                f"/dev/loop17 {host_mirror_path} ext4 ro,relatime 0 0\n"
            )
            mountinfo_rw = (
                f"101 80 7:17 / {resolved} ro,relatime - ext4 /dev/loop17 rw,errors=remount-ro\n"
                f"102 81 7:17 / {host_mirror_path} ro,relatime - ext4 /dev/loop17 rw,errors=remount-ro\n"
            )

            def read_proc_rw(path: Path, *_args: object, **_kwargs: object) -> str:
                name = str(path).replace("\\", "/")
                if name.endswith("/mountinfo"):
                    return mountinfo_rw
                if name.endswith("/mounts"):
                    return mounts_text
                raise AssertionError(f"Unexpected proc read: {name}")

            with (
                patch.object(Path, "exists", return_value=True),
                patch.object(Path, "read_text", new=read_proc_rw),
            ):
                inspection_rw = g._default_mount_inspector(
                    g.data_mount_path, g.root_mount_path
                )
            self.assertTrue(inspection_rw["mounted"])
            self.assertTrue(inspection_rw["guard_read_only"])
            self.assertTrue(inspection_rw["superblock_writable"])
            self.assertTrue(inspection_rw["writable"])

            g.mount_inspector_fn = lambda _d, _r: inspection_rw
            health_rw = g.enable_gate("test_ro_observer_rw_superblock")
            self.assertEqual(health_rw["state"], "ready")
            self.assertFalse(health_rw["latched"])
            self.assertEqual(health_rw["reason_codes"], [])
            status_rw, _, _ = g.validate_and_prepare_insert(
                "/insert/jsonline", headers, event_body
            )
            self.assertEqual(status_rw, 200)

            # 2. Backing ext4 superblock remounted read-only on error -> blocked, latched, and insert rejected (503)
            mountinfo_ro = (
                f"101 80 7:17 / {resolved} ro,relatime - ext4 /dev/loop17 ro,errors=remount-ro\n"
                f"102 81 7:17 / {host_mirror_path} ro,relatime - ext4 /dev/loop17 ro,errors=remount-ro\n"
            )

            def read_proc_ro(path: Path, *_args: object, **_kwargs: object) -> str:
                name = str(path).replace("\\", "/")
                if name.endswith("/mountinfo"):
                    return mountinfo_ro
                if name.endswith("/mounts"):
                    return mounts_text
                raise AssertionError(f"Unexpected proc read: {name}")

            with (
                patch.object(Path, "exists", return_value=True),
                patch.object(Path, "read_text", new=read_proc_ro),
            ):
                inspection_ro = g._default_mount_inspector(
                    g.data_mount_path, g.root_mount_path
                )
            self.assertTrue(inspection_ro["mounted"])
            self.assertTrue(inspection_ro["guard_read_only"])
            self.assertFalse(inspection_ro["superblock_writable"])
            self.assertFalse(inspection_ro["writable"])

            g.mount_inspector_fn = lambda _d, _r: inspection_ro
            health_ro = g.run_check()
            self.assertEqual(health_ro["state"], "blocked")
            self.assertTrue(health_ro["latched"])
            self.assertIn("mount_not_writable", health_ro["reason_codes"])
            status_ro, err_ro, _ = g.validate_and_prepare_insert(
                "/insert/jsonline", headers, event_body
            )
            self.assertEqual(status_ro, 503)
            self.assertIn("mount_not_writable", err_ro["error"]["reason_codes"])

            # 3. Unconfirmed superblock state (only /proc/mounts ro without /proc/self/mountinfo) -> fail-closed
            with (
                patch.object(Path, "exists", return_value=True),
                patch.object(Path, "read_text", return_value=mounts_text),
            ):
                inspection_unconfirmed = g._default_mount_inspector(
                    g.data_mount_path, g.root_mount_path
                )
            self.assertTrue(inspection_unconfirmed["mounted"])
            self.assertTrue(inspection_unconfirmed["guard_read_only"])
            self.assertFalse(inspection_unconfirmed["writable"])


if __name__ == "__main__":
    unittest.main()
