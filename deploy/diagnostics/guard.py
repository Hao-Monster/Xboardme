#!/usr/bin/env python3
"""Lightweight Customer Connection Diagnostics Capacity & Ingestion Guard (D6.3).

Responsibilities:
1. Periodic 30-second space & mount verification using a monotonic clock (`time.monotonic()`).
2. Enforces 16 GiB warning, 20 GiB critical, 24 GiB / <=6 GiB free / root < max(50 GiB, 20%) ingestion stop thresholds.
3. Persistent latch (`state/guard-state.json`): defaults to closed until explicitly enabled; once any block condition or >60s check staleness occurs, latches `blocked` across restarts until an operator explicitly runs `resume`.
4. Separates capacity thresholds (`capacity_warning`, `capacity_critical`) from confirmed historical deletion (`retention_shortened`).
5. Serves authenticated `GET /diagnostics/health` for panel lease creation preflight (D5) and dashboard status display (D7).
6. Validates & forwards `POST /insert/jsonline` with server-enforced node identity (`panel_scope`, `node_id`, `collector_id`), stripping forged query parameters, tenant headers, and unwhitelisted fields.
"""
from __future__ import annotations

import argparse
import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import shutil
import threading
import time
from typing import Any
import urllib.error
import urllib.parse
import urllib.request

from configure import (
    CONNECTION_ALLOWED_FIELDS,
    CONNECTION_EVENTS,
    FORBIDDEN_PII_FIELDS,
    NODE_ALLOWED_FIELDS,
    NODE_EVENTS,
    RESOLVER_ALLOWED_FIELDS,
    RESOLVER_EVENTS,
    UUID_V4_RE,
    atomic_write_json,
)

HEX24_RE = re.compile(r"^[0-9a-f]{24}$")
HEX32_RE = re.compile(r"^[0-9a-f]{32}$")
HEX64_RE = re.compile(r"^[0-9a-f]{64}$")

FORBIDDEN_HEADERS = {
    "accountid",
    "projectid",
    "vl-time-field",
    "vl-stream-fields",
    "vl-msg-field",
    "vl-extra-fields",
    "x-scope-orgid",
}

FORBIDDEN_CLIENT_QUERY_KEYS = {
    "debug",
    "accountid",
    "projectid",
}


def parse_rfc3339(value: str) -> datetime.datetime | None:
    if not isinstance(value, str) or not value.strip():
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        dt = datetime.datetime.fromisoformat(text)
    except ValueError:
        return None
    if dt.tzinfo is None:
        return None
    return dt.astimezone(datetime.timezone.utc)


def sanitize_and_validate_event_line(
    raw_line: str,
    enforced_panel_scope: str,
    enforced_node_id: int,
    enforced_collector_id: str,
    now_utc: datetime.datetime | None = None,
) -> tuple[dict[str, Any] | None, str]:
    """Validate a single JSON line against per-family whitelist and override identity fields."""
    if not raw_line or len(raw_line.encode("utf-8")) > 4096:
        return None, "line_length_invalid"
    try:
        parsed = json.loads(raw_line)
    except ValueError:
        return None, "invalid_json"
    if not isinstance(parsed, dict):
        return None, "not_json_object"

    for bad_key in FORBIDDEN_PII_FIELDS:
        if bad_key in parsed:
            return None, f"forbidden_field:{bad_key}"

    if parsed.get("schema_version") != 1:
        return None, "invalid_schema_version"

    event_id = str(parsed.get("event_id") or "")
    if not HEX64_RE.fullmatch(event_id):
        return None, "invalid_event_id"

    ev_time_raw = str(parsed.get("event_time") or "")
    ev_dt = parse_rfc3339(ev_time_raw)
    if ev_dt is None:
        return None, "invalid_event_time"

    ref_now = now_utc or datetime.datetime.now(datetime.timezone.utc)
    age_seconds = (ref_now - ev_dt).total_seconds()
    if age_seconds < -600:
        return None, "event_time_in_future"
    if age_seconds > 3 * 86400:
        return None, "event_time_exceeds_3d_retention"

    family = str(parsed.get("event_family") or "")
    ev_name = str(parsed.get("event") or "")

    if family == "connection" and ev_name in CONNECTION_EVENTS:
        diag_id = str(parsed.get("diagnostic_id") or "")
        conn_id = str(parsed.get("connection_id") or "")
        if not HEX24_RE.fullmatch(diag_id) or not HEX32_RE.fullmatch(conn_id):
            return None, "invalid_connection_identifiers"
        allowed = CONNECTION_ALLOWED_FIELDS
    elif family == "resolver_operation" and ev_name in RESOLVER_EVENTS:
        op_id = str(parsed.get("resolver_operation_id") or "")
        if (
            not HEX32_RE.fullmatch(op_id)
            or "diagnostic_id" in parsed
            or "connection_id" in parsed
        ):
            return None, "invalid_resolver_identifiers"
        allowed = RESOLVER_ALLOWED_FIELDS
    elif family == "node" and ev_name in NODE_EVENTS:
        if "diagnostic_id" in parsed or "connection_id" in parsed:
            return None, "invalid_node_identifiers"
        allowed = NODE_ALLOWED_FIELDS
    else:
        return None, "unsupported_event_family_or_name"

    clean: dict[str, Any] = {}
    for key in allowed:
        if key in parsed and parsed[key] is not None:
            clean[key] = parsed[key]

    if "journal_cursor_hash" in parsed and isinstance(
        parsed["journal_cursor_hash"], str
    ):
        clean["journal_cursor_hash"] = parsed["journal_cursor_hash"][:64]

    # Server-enforced identity overwrite: body forgery cannot override panel_scope, node_id, collector_id, or service.
    clean["panel_scope"] = enforced_panel_scope
    clean["node_id"] = int(enforced_node_id)
    clean["collector_id"] = enforced_collector_id
    clean["service"] = "xboard-node"
    clean["collected_at"] = ref_now.strftime("%Y-%m-%dT%H:%M:%S.%fZ")
    if not isinstance(clean.get("safe_message"), str) or not clean["safe_message"]:
        clean["safe_message"] = ev_name

    return clean, ""


class DiagnosticsCapacityGuard:
    """Evaluates storage thresholds, mount identity, and persistent stop latch."""

    def __init__(
        self,
        config: dict[str, Any],
        monotonic_fn: Any = time.monotonic,
        now_utc_fn: Any = lambda: datetime.datetime.now(datetime.timezone.utc),
        disk_usage_fn: Any = shutil.disk_usage,
        mount_inspector_fn: Any = None,
    ) -> None:
        self.config = config
        self.monotonic_fn = monotonic_fn
        self.now_utc_fn = now_utc_fn
        self.disk_usage_fn = disk_usage_fn
        self.mount_inspector_fn = mount_inspector_fn or self._default_mount_inspector
        self.lock = threading.Lock()

        self.panel_scope = str(config["panel_scope"])
        self.storage_id = str(config["storage_id"])
        self.state_dir = Path(config["state_dir"])
        self.state_file = self.state_dir / "guard-state.json"
        self.data_mount_path = Path(config["data_mount_path"])
        self.root_mount_path = Path(config.get("root_mount_path", "/"))
        self.expected_fs_type = str(config.get("expected_fs_type", "ext4"))
        self.expected_fs_uuid = str(config.get("expected_fs_uuid", "")).strip()
        self.max_check_age_seconds = int(config.get("max_check_age_seconds", 60))

        thresholds = config.get("thresholds") or {}
        self.warn_used_bytes = int(thresholds.get("warn_used_bytes", 16 * 1024**3))
        self.critical_used_bytes = int(
            thresholds.get("critical_used_bytes", 20 * 1024**3)
        )
        self.block_used_bytes = int(thresholds.get("block_used_bytes", 24 * 1024**3))
        self.block_min_free_bytes = int(
            thresholds.get("block_min_free_bytes", 6 * 1024**3)
        )
        self.vl_emergency_free_bytes = int(
            thresholds.get("vl_emergency_free_bytes", 5 * 1024**3)
        )
        self.root_min_free_bytes = int(
            thresholds.get("root_min_free_bytes", 50 * 1024**3)
        )
        self.root_min_free_ratio = float(thresholds.get("root_min_free_ratio", 0.20))

        self.last_check_mono: float | None = None
        self.last_checked_utc: str = ""
        self.last_data_used: int = 0
        self.last_data_avail: int = 0
        self.last_root_avail: int = 0
        self.last_reasons: list[str] = ["guard_uninitialized"]
        self.suppressed_lines_total: int = 0
        self.accepted_lines_total: int = 0

        self._ensure_persistent_state()

    def _ensure_persistent_state(self) -> dict[str, Any]:
        self.state_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        if not self.state_file.exists():
            # Default closed until explicitly enabled by an authorized operator/test!
            initial = {
                "schema_version": 1,
                "enabled": False,
                "latched": True,
                "latch_reasons": ["not_yet_enabled"],
                "retention_shortened": False,
                "retention_earliest_available_utc": None,
                "updated_at_utc": self.now_utc_fn().strftime("%Y-%m-%dT%H:%M:%SZ"),
            }
            atomic_write_json(self.state_file, initial, mode=0o600)
            return initial
        try:
            data = json.loads(self.state_file.read_text(encoding="utf-8"))
            if not isinstance(data, dict):
                raise ValueError("corrupt state")
            return data
        except Exception:
            corrupt = {
                "schema_version": 1,
                "enabled": False,
                "latched": True,
                "latch_reasons": ["state_file_corrupt_or_unreadable"],
                "retention_shortened": False,
                "retention_earliest_available_utc": None,
                "updated_at_utc": self.now_utc_fn().strftime("%Y-%m-%dT%H:%M:%SZ"),
            }
            atomic_write_json(self.state_file, corrupt, mode=0o600)
            return corrupt

    def _default_mount_inspector(self, mount_path: Path, root_path: Path) -> dict[str, Any]:
        proc_mounts = Path("/proc/mounts")
        if not proc_mounts.exists():
            return {"mounted": False, "fs_type": "", "fs_uuid": "", "writable": False}
        resolved = str(mount_path.resolve())
        for line in proc_mounts.read_text(encoding="utf-8", errors="ignore").splitlines():
            parts = line.split()
            if len(parts) >= 4 and parts[1] == resolved:
                opts = parts[3].split(",")
                return {
                    "mounted": True,
                    "fs_type": parts[2],
                    "fs_uuid": "",
                    "writable": "rw" in opts,
                }
        return {"mounted": False, "fs_type": "", "fs_uuid": "", "writable": False}

    def enable_gate(self, reason: str = "operator_enabled") -> dict[str, Any]:
        with self.lock:
            pstate = self._ensure_persistent_state()
            pstate["enabled"] = True
            pstate["latched"] = False
            pstate["latch_reasons"] = []
            pstate["last_resume_reason"] = reason
            pstate["updated_at_utc"] = self.now_utc_fn().strftime("%Y-%m-%dT%H:%M:%SZ")
            atomic_write_json(self.state_file, pstate, mode=0o600)
        return self.run_check()

    def record_retention_shortened(self, earliest_available_utc: str | None) -> None:
        with self.lock:
            pstate = self._ensure_persistent_state()
            pstate["retention_shortened"] = True
            pstate["retention_earliest_available_utc"] = earliest_available_utc
            pstate["updated_at_utc"] = self.now_utc_fn().strftime("%Y-%m-%dT%H:%M:%SZ")
            atomic_write_json(self.state_file, pstate, mode=0o600)

    def run_check(self) -> dict[str, Any]:
        with self.lock:
            pstate = self._ensure_persistent_state()
            now_mono = float(self.monotonic_fn())
            now_utc = self.now_utc_fn().strftime("%Y-%m-%dT%H:%M:%SZ")

            # Check if previous check became stale before this check ran
            if (
                self.last_check_mono is not None
                and (now_mono - self.last_check_mono) > self.max_check_age_seconds
            ):
                pstate["latched"] = True
                if "check_stale_exceeded_60s" not in pstate.get("latch_reasons", []):
                    pstate.setdefault("latch_reasons", []).append(
                        "check_stale_exceeded_60s"
                    )

            reasons: list[str] = []
            block_reasons: list[str] = []

            if not pstate.get("enabled", False):
                block_reasons.append("not_yet_enabled")

            mount_info = self.mount_inspector_fn(
                self.data_mount_path, self.root_mount_path
            )
            if not mount_info.get("mounted", False):
                block_reasons.append("mount_missing")
            else:
                fs_type = str(mount_info.get("fs_type") or "")
                if self.expected_fs_type and fs_type != self.expected_fs_type:
                    block_reasons.append(f"invalid_filesystem_type:{fs_type}")
                if self.expected_fs_uuid:
                    actual_uuid = str(mount_info.get("fs_uuid") or "")
                    if actual_uuid != self.expected_fs_uuid:
                        block_reasons.append("filesystem_uuid_mismatch")
                if not mount_info.get("writable", True):
                    block_reasons.append("mount_not_writable")

            try:
                data_usage = self.disk_usage_fn(self.data_mount_path)
                data_used = int(data_usage.used)
                data_avail = int(data_usage.free)
            except Exception:
                data_used = 0
                data_avail = 0
                block_reasons.append("data_disk_usage_unavailable")

            try:
                root_usage = self.disk_usage_fn(self.root_mount_path)
                root_total = int(root_usage.total)
                root_avail = int(root_usage.free)
                root_floor = max(
                    self.root_min_free_bytes,
                    int(root_total * self.root_min_free_ratio),
                )
                if root_avail < root_floor:
                    block_reasons.append("root_disk_free_below_threshold")
            except Exception:
                root_avail = 0
                block_reasons.append("root_disk_usage_unavailable")

            if data_used >= self.warn_used_bytes:
                reasons.append("capacity_warning_16gib")
            if data_used >= self.critical_used_bytes:
                reasons.append("capacity_critical_20gib")
            if data_used >= self.block_used_bytes:
                block_reasons.append("capacity_blocked_used_24gib")
            if data_avail <= self.block_min_free_bytes:
                block_reasons.append("capacity_blocked_free_6gib")
            if data_avail <= self.vl_emergency_free_bytes:
                block_reasons.append("vl_emergency_free_5gib")

            if pstate.get("retention_shortened", False):
                reasons.append("retention_shortened")

            if block_reasons:
                pstate["latched"] = True
                existing_latch = list(pstate.get("latch_reasons") or [])
                for br in block_reasons:
                    if br not in existing_latch:
                        existing_latch.append(br)
                pstate["latch_reasons"] = existing_latch
                pstate["recoverable"] = False
            else:
                pstate["recoverable"] = True

            pstate["updated_at_utc"] = now_utc
            atomic_write_json(self.state_file, pstate, mode=0o600)

            all_reasons = list(dict.fromkeys(block_reasons + list(pstate.get("latch_reasons") or []) + reasons))
            self.last_check_mono = now_mono
            self.last_checked_utc = now_utc
            self.last_data_used = data_used
            self.last_data_avail = data_avail
            self.last_root_avail = root_avail
            self.last_reasons = all_reasons

            return self._health_Locked(pstate, now_mono)

    def _health_Locked(self, pstate: dict[str, Any], now_mono: float) -> dict[str, Any]:
        if self.last_check_mono is None:
            return {
                "schema_version": 1,
                "panel_scope": self.panel_scope,
                "storage_id": self.storage_id,
                "state": "unknown",
                "reason_codes": ["guard_uninitialized"],
                "checked_at_utc": "",
                "check_age_seconds": 999999,
                "latched": True,
                "recoverable": False,
                "retention_shortened": bool(pstate.get("retention_shortened", False)),
                "retention_earliest_available_utc": pstate.get(
                    "retention_earliest_available_utc"
                ),
                "data_used_bytes": 0,
                "data_available_bytes": 0,
                "root_available_bytes": 0,
            }

        age_seconds = int(max(0.0, now_mono - self.last_check_mono))
        latched = bool(pstate.get("latched", True))
        reasons = list(self.last_reasons)

        if age_seconds > self.max_check_age_seconds:
            latched = True
            pstate["latched"] = True
            if "check_stale_exceeded_60s" not in pstate.get("latch_reasons", []):
                pstate.setdefault("latch_reasons", []).append(
                    "check_stale_exceeded_60s"
                )
            atomic_write_json(self.state_file, pstate, mode=0o600)
            if "check_stale_exceeded_60s" not in reasons:
                reasons.append("check_stale_exceeded_60s")

        state_str = "blocked" if latched else "ready"
        return {
            "schema_version": 1,
            "panel_scope": self.panel_scope,
            "storage_id": self.storage_id,
            "state": state_str,
            "reason_codes": reasons,
            "checked_at_utc": self.last_checked_utc,
            "check_age_seconds": age_seconds,
            "latched": latched,
            "recoverable": bool(pstate.get("recoverable", False)),
            "retention_shortened": bool(pstate.get("retention_shortened", False)),
            "retention_earliest_available_utc": pstate.get(
                "retention_earliest_available_utc"
            ),
            "data_used_bytes": self.last_data_used,
            "data_available_bytes": self.last_data_avail,
            "root_available_bytes": self.last_root_avail,
            "accepted_lines_total": self.accepted_lines_total,
            "suppressed_lines_total": self.suppressed_lines_total,
        }

    def get_health(self) -> dict[str, Any]:
        with self.lock:
            pstate = self._ensure_persistent_state()
            now_mono = float(self.monotonic_fn())
            return self._health_Locked(pstate, now_mono)

    def validate_and_prepare_insert(
        self,
        path_and_query: str,
        headers: dict[str, str],
        raw_body: bytes,
    ) -> tuple[int, dict[str, Any], bytes]:
        """Validate guard state, headers, query parameters, and JSON lines before forwarding to VictoriaLogs."""
        health = self.get_health()
        if (
            health["state"] != "ready"
            or health["latched"]
            or health["check_age_seconds"] > self.max_check_age_seconds
        ):
            return (
                503,
                {
                    "error": {
                        "code": "diagnostics_storage_not_ready",
                        "message": "Diagnostics ingestion is paused by capacity/mount guard.",
                        "reason_codes": health["reason_codes"],
                        "latched": health["latched"],
                    }
                },
                b"",
            )

        max_body = int(self.config.get("max_insert_body_bytes", 4 * 1024 * 1024))
        if len(raw_body) == 0 or len(raw_body) > max_body:
            return (
                413 if len(raw_body) > max_body else 400,
                {
                    "error": {
                        "code": "invalid_insert_body_size",
                        "message": "Insert payload size is invalid or exceeds limit.",
                    }
                },
                b"",
            )

        lower_headers = {k.lower(): v for k, v in headers.items()}
        for bad_hdr in FORBIDDEN_HEADERS:
            if bad_hdr in lower_headers:
                return (
                    403,
                    {
                        "error": {
                            "code": "forbidden_ingestion_header",
                            "message": f"Forbidden header {bad_hdr} is not allowed.",
                        }
                    },
                    b"",
                )

        parsed_url = urllib.parse.urlsplit(path_and_query)
        query_pairs = urllib.parse.parse_qsl(
            parsed_url.query, keep_blank_values=True
        )
        seen_keys: set[str] = set()
        query_map: dict[str, str] = {}
        allowed_query_keys = {
            "_time_field",
            "_msg_field",
            "_stream_fields",
            "extra_fields",
        }
        for k, v in query_pairs:
            lk = k.lower()
            if lk in FORBIDDEN_CLIENT_QUERY_KEYS or lk not in allowed_query_keys:
                return (
                    403,
                    {
                        "error": {
                            "code": "forbidden_query_parameter",
                            "message": f"Query parameter {k} is forbidden.",
                        }
                    },
                    b"",
                )
            if lk in seen_keys:
                return (
                    400,
                    {
                        "error": {
                            "code": "duplicate_query_parameter",
                            "message": f"Duplicate query parameter {k} is rejected.",
                        }
                    },
                    b"",
                )
            seen_keys.add(lk)
            query_map[lk] = v

        hdr_scope = lower_headers.get("x-diagnostics-panel-scope", "").strip()
        hdr_node_id = lower_headers.get("x-diagnostics-node-id", "").strip()
        hdr_collector_id = lower_headers.get("x-diagnostics-collector-id", "").strip()

        if hdr_scope != self.panel_scope or not hdr_node_id.isdigit():
            return (
                403,
                {
                    "error": {
                        "code": "untrusted_writer_identity",
                        "message": "Missing or mismatched vmauth writer identity headers.",
                    }
                },
                b"",
            )

        allowed_writers = self.config.get("allowed_writers") or {}
        writer_meta = allowed_writers.get(hdr_node_id)
        if not writer_meta or writer_meta.get("collector_id") != hdr_collector_id:
            return (
                403,
                {
                    "error": {
                        "code": "unauthorized_node_writer",
                        "message": "Node writer identity is not registered in guard config.",
                    }
                },
                b"",
            )

        if "extra_fields" in query_map:
            raw_extra = query_map["extra_fields"].strip()
            extra_pairs: dict[str, str] = {}
            for item in raw_extra.split(","):
                part = item.strip()
                if not part:
                    continue
                if "=" not in part:
                    return (
                        400,
                        {
                            "error": {
                                "code": "invalid_extra_fields",
                                "message": "Malformed extra_fields parameter.",
                            }
                        },
                        b"",
                    )
                ek, ev = part.split("=", 1)
                ek = ek.strip()
                ev = ev.strip()
                if ek in extra_pairs:
                    return (
                        400,
                        {
                            "error": {
                                "code": "duplicate_extra_field",
                                "message": f"Duplicate extra_fields key {ek} is rejected.",
                            }
                        },
                        b"",
                    )
                extra_pairs[ek] = ev

            if (
                extra_pairs.get("panel_scope", self.panel_scope) != self.panel_scope
                or extra_pairs.get("node_id", hdr_node_id) != hdr_node_id
                or extra_pairs.get("collector_id", hdr_collector_id) != hdr_collector_id
                or extra_pairs.get("service", "xboard-node") != "xboard-node"
                or not set(extra_pairs.keys()).issubset(
                    {"panel_scope", "node_id", "collector_id", "service"}
                )
            ):
                return (
                    403,
                    {
                        "error": {
                            "code": "forged_extra_fields",
                            "message": "extra_fields conflicts with authenticated writer identity.",
                        }
                    },
                    b"",
                )

        enforced_node_id = int(hdr_node_id)
        now_utc = self.now_utc_fn()
        out_lines: list[str] = []
        suppressed = 0

        for raw_line in raw_body.decode("utf-8", errors="replace").splitlines():
            stripped = raw_line.strip()
            if not stripped:
                continue
            cleaned, _reason = sanitize_and_validate_event_line(
                stripped,
                enforced_panel_scope=self.panel_scope,
                enforced_node_id=enforced_node_id,
                enforced_collector_id=hdr_collector_id,
                now_utc=now_utc,
            )
            if cleaned is None:
                suppressed += 1
                continue
            out_lines.append(json.dumps(cleaned, ensure_ascii=False, separators=(",", ":")))

        with self.lock:
            self.suppressed_lines_total += suppressed
            self.accepted_lines_total += len(out_lines)

        if not out_lines:
            return (
                422,
                {
                    "error": {
                        "code": "all_lines_suppressed_by_whitelist",
                        "message": "No valid diagnostic events remained after schema/time whitelist check.",
                    }
                },
                b"",
            )

        canonical_extra_fields = (
            f"panel_scope={self.panel_scope},"
            f"node_id={enforced_node_id},"
            f"collector_id={hdr_collector_id},"
            "service=xboard-node"
        )
        forwarded_payload = ("\n".join(out_lines) + "\n").encode("utf-8")
        return (
            200,
            {
                "accepted_lines": len(out_lines),
                "suppressed_lines": suppressed,
                "canonical_extra_fields": canonical_extra_fields,
            },
            forwarded_payload,
        )


def make_http_handler(guard: DiagnosticsCapacityGuard) -> type[BaseHTTPRequestHandler]:
    class GuardHandler(BaseHTTPRequestHandler):
        def log_message(self, format: str, *args: Any) -> None:
            # Never log request bodies or credentials.
            return

        def _write_json(self, status: int, payload: dict[str, Any]) -> None:
            raw = (json.dumps(payload, ensure_ascii=False) + "\n").encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)

        def do_GET(self) -> None:
            parsed = urllib.parse.urlsplit(self.path)
            if parsed.path == "/diagnostics/health":
                health = guard.get_health()
                status = 200 if health["state"] == "ready" else 503
                self._write_json(status, health)
                return
            self._write_json(404, {"error": {"code": "not_found"}})

        def do_POST(self) -> None:
            parsed = urllib.parse.urlsplit(self.path)
            if parsed.path != "/insert/jsonline":
                self._write_json(404, {"error": {"code": "not_found"}})
                return

            content_len = int(self.headers.get("Content-Length", "0") or "0")
            raw_body = self.rfile.read(content_len) if content_len > 0 else b""
            hdr_dict = {k: v for k, v in self.headers.items()}

            status, meta, forwarded_body = guard.validate_and_prepare_insert(
                self.path, hdr_dict, raw_body
            )
            if status != 200:
                self._write_json(status, meta)
                return

            upstream_base = str(guard.config["upstream_insert_url"]).rstrip("/")
            canonical_params = {
                "_time_field": "event_time",
                "_msg_field": "safe_message",
                "_stream_fields": "panel_scope,node_id,service,event_family",
            }
            if meta.get("canonical_extra_fields"):
                canonical_params["extra_fields"] = str(meta["canonical_extra_fields"])
            canonical_query = urllib.parse.urlencode(canonical_params)
            req = urllib.request.Request(
                f"{upstream_base}?{canonical_query}",
                data=forwarded_body,
                headers={"Content-Type": "application/stream+json"},
                method="POST",
            )
            timeout_sec = int(guard.config.get("upstream_timeout_seconds", 5))
            try:
                with urllib.request.urlopen(req, timeout=timeout_sec) as resp:
                    resp_status = resp.getcode() or 204
                    if 200 <= resp_status < 300:
                        self._write_json(200, meta)
                    else:
                        self._write_json(
                            503,
                            {
                                "error": {
                                    "code": "upstream_insert_failed",
                                    "upstream_status": resp_status,
                                }
                            },
                        )
            except Exception as exc:
                self._write_json(
                    503,
                    {
                        "error": {
                            "code": "upstream_unreachable",
                            "type": type(exc).__name__,
                        }
                    },
                )

    return GuardHandler


def serve(config_path: Path) -> None:
    config = json.loads(config_path.read_text(encoding="utf-8"))
    guard = DiagnosticsCapacityGuard(config)
    guard.run_check()

    interval = int(config.get("check_interval_seconds", 30))
    stop_event = threading.Event()

    def background_checker() -> None:
        while not stop_event.wait(interval):
            try:
                guard.run_check()
            except Exception:
                pass

    thread = threading.Thread(target=background_checker, daemon=True)
    thread.start()

    host_port = str(config.get("listen_addr", "127.0.0.1:9430"))
    host, port_str = host_port.rsplit(":", 1)
    server = ThreadingHTTPServer((host, int(port_str)), make_http_handler(guard))
    try:
        server.serve_forever()
    finally:
        stop_event.set()
        server.server_close()


def main() -> None:
    parser = argparse.ArgumentParser(description="Diagnostics capacity & ingestion guard")
    sub = parser.add_subparsers(dest="command", required=True)

    s_serve = sub.add_parser("serve")
    s_serve.add_argument("--config", required=True)

    s_check = sub.add_parser("check")
    s_check.add_argument("--config", required=True)

    s_resume = sub.add_parser("resume")
    s_resume.add_argument("--config", required=True)
    s_resume.add_argument("--reason", required=True)

    args = parser.parse_args()
    cfg_path = Path(args.config)
    config = json.loads(cfg_path.read_text(encoding="utf-8"))
    guard = DiagnosticsCapacityGuard(config)

    if args.command == "serve":
        serve(cfg_path)
    elif args.command == "check":
        result = guard.run_check()
        print(json.dumps(result))
        raise SystemExit(0 if result["state"] == "ready" else 1)
    elif args.command == "resume":
        result = guard.enable_gate(reason=args.reason)
        print(json.dumps(result))
        raise SystemExit(0 if result["state"] == "ready" else 1)


if __name__ == "__main__":
    main()
