#!/usr/bin/env python3
"""Generate isolated customer connection diagnostics logging & pilot protection configuration.

 provenance & license:
 Adapted from Xboard-Go `deploy/observability/configure.py` (MIT License / project internal).
 Do NOT modify `Xboard-Go/deploy/observability/` or existing operational logging (`xboard-logs`).
 Never print credentials to stdout/stderr. All secret files are written atomically with mode 0600.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import secrets
import tempfile
from typing import Any
from urllib.parse import urlsplit

# Pinned immutable image digests (aligned with verified VictoriaLogs v1.53.0 & vmauth v1.153.0)
VL_IMAGE = "victoriametrics/victoria-logs:v1.53.0@sha256:251121fa882af99b95ba0c230a4a2f412ea602d2698c64a96c58dc9842bb755d"
AUTH_IMAGE = "victoriametrics/vmauth:v1.153.0@sha256:4ebf2f21490df3e8837302b85d9db6ac45765442dfa28db77ffacd3577035a74"
GUARD_IMAGE = os.environ.get(
    "XBOARD_DIAGNOSTICS_GUARD_IMAGE",
    "python:3.12.15-slim@sha256:a6e34c598f2467ed0e9a8d349809fcd8b5c603269512df273a0bb1784edc11b1",
)
VECTOR_IMAGE = os.environ.get(
    "XBOARD_DIAGNOSTICS_COLLECTOR_IMAGE",
    "xboard-diagnostics-collector:local",
)

PILOT_COMPOSE_PROJECT = "xboard-customer-diagnostics-pilot"
DEFAULT_LISTEN_PORT = 19438

UUID_V4_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)
IDENTIFIER_RE = re.compile(r"^[a-z][a-z0-9_-]{0,63}$")

# Strict per-family field whitelists matching contract-schema.json v1
COMMON_REQUIRED_FIELDS = [
    "schema_version",
    "event_time",
    "event_id",
    "event_family",
    "panel_scope",
    "node_id",
    "runtime_id",
    "event",
]

CONNECTION_EVENTS = [
    "diag.connection.open",
    "diag.admission.rejected",
    "diag.route.step",
    "diag.route.result",
    "diag.dns.result",
    "diag.dial.attempt",
    "diag.dial.result",
    "diag.relay.result",
    "diag.target.summary",
    "diag.connection.close",
]

RESOLVER_EVENTS = [
    "diag.dns.attempt",
    "diag.dns.exchange.result",
]

NODE_EVENTS = [
    "diag.observer.health",
]

CONNECTION_ALLOWED_FIELDS = [
    "schema_version",
    "event_time",
    "event_id",
    "event_family",
    "panel_scope",
    "node_id",
    "runtime_id",
    "diagnostic_id",
    "connection_id",
    "seq",
    "event",
    "stage",
    "network",
    "observation_mode",
    "trace_id",
    "span_id",
    "parent_span_id",
    "span_name",
    "span_start_time",
    "span_duration_ms",
    "span_status",
    "span_links",
    "session_id",
    "protocol",
    "requested_host",
    "requested_port",
    "observed_domain",
    "domain_source",
    "outbound_tag",
    "rule_id",
    "node_resolution",
    "resolver_operation_id",
    "dns_rcode",
    "dns_answers",
    "dns_answers_total",
    "dns_answers_truncated",
    "dns_cache_hit",
    "dns_query_type",
    "selected_target_address",
    "socket_peer",
    "attempt_id",
    "transport_state",
    "has_error",
    "error_stages",
    "reason_codes",
    "last_error_stage",
    "last_reason_code",
    "reason_code",
    "error_type",
    "timeout",
    "errno",
    "safe_message",
    "exception",
    "device_limit",
    "active_ip_count",
    "duration_ms",
    "bytes_up",
    "bytes_down",
    "close_reason",
    "target_id",
    "summary_reason",
    "targets_evicted_total",
]

RESOLVER_ALLOWED_FIELDS = [
    "schema_version",
    "event_time",
    "event_id",
    "event_family",
    "panel_scope",
    "node_id",
    "runtime_id",
    "resolver_operation_id",
    "operation_seq",
    "event",
    "stage",
    "observation_mode",
    "trace_id",
    "span_id",
    "parent_span_id",
    "span_name",
    "span_start_time",
    "span_duration_ms",
    "span_status",
    "observed_domain",
    "dns_server",
    "dns_transport",
    "dns_query_type",
    "attempt_id",
    "dns_rcode",
    "dns_answers",
    "dns_answers_total",
    "dns_answers_truncated",
    "dns_cache_hit",
    "detail_started_mid_operation",
    "reason_code",
    "error_type",
    "timeout",
    "errno",
    "safe_message",
    "exception",
]

NODE_ALLOWED_FIELDS = [
    "schema_version",
    "event_time",
    "event_id",
    "event_family",
    "panel_scope",
    "node_id",
    "runtime_id",
    "runtime_seq",
    "event",
    "observer_state",
    "control_epoch",
    "control_version",
    "active_leases",
    "queue_depth",
    "queue_bytes",
    "dropped_total",
    "dropped_detail",
    "dropped_baseline",
    "evicted_targets_total",
    "reason_code",
    "safe_message",
]

FORBIDDEN_PII_FIELDS = [
    "user_id",
    "uuid",
    "token",
    "email",
    "trade_no",
    "order_id",
    "source_ip",
    "client_ip",
    "dest_ip",
    "resolved_ip",
    "ecs_subnet",
    "raw_url",
    "sni",
    "ech",
    "alpn",
    "stack_raw",
]


def validate_panel_scope(value: str) -> str:
    normalized = str(value or "").strip().lower()
    if not UUID_V4_RE.fullmatch(normalized):
        raise ValueError("panel_scope must be a lowercase UUIDv4")
    return normalized


def validate_identifier(value: str, label: str = "identifier") -> str:
    normalized = str(value or "").strip()
    if not IDENTIFIER_RE.fullmatch(normalized):
        raise ValueError(f"{label} is invalid: {value!r}")
    return normalized


def validate_node_id(value: int | str) -> int:
    try:
        node_id = int(value)
    except (TypeError, ValueError) as exc:
        raise ValueError(f"node_id must be a positive integer: {value!r}") from exc
    if node_id <= 0:
        raise ValueError(f"node_id must be > 0: {node_id}")
    return node_id


def atomic_write_json(path: Path | str, payload: Any, mode: int = 0o600) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if target.is_symlink():
        raise ValueError(f"refusing symlink output: {target}")
    fd, tmp_name = tempfile.mkstemp(dir=target.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
        os.chmod(tmp_name, mode)
        os.replace(tmp_name, target)
    finally:
        if os.path.exists(tmp_name):
            os.unlink(tmp_name)


def atomic_write_text(path: Path | str, content: str, mode: int = 0o600) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if target.is_symlink():
        raise ValueError(f"refusing symlink output: {target}")
    fd, tmp_name = tempfile.mkstemp(dir=target.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(content)
        os.chmod(tmp_name, mode)
        os.replace(tmp_name, target)
    finally:
        if os.path.exists(tmp_name):
            os.unlink(tmp_name)


def runtime_user() -> str:
    uid = os.getuid() if hasattr(os, "getuid") else 0
    gid = os.getgid() if hasattr(os, "getgid") else 0
    return f"{uid}:{gid}"


def journal_groups() -> list[str]:
    if os.name != "posix":
        return []
    import grp

    try:
        return [str(grp.getgrnam("systemd-journal").gr_gid)]
    except KeyError:
        return []


def base_service(image: str, memory: str, cpus: str) -> dict[str, Any]:
    return {
        "image": image,
        "user": runtime_user(),
        "restart": "no",
        "mem_limit": memory,
        "cpus": cpus,
        "security_opt": ["no-new-privileges:true"],
        "cap_drop": ["ALL"],
        "logging": {
            "driver": "local",
            "options": {"max-size": "10m", "max-file": "3"},
        },
    }


def build_vrl_remap(panel_scope: str, node_id: int, collector_id: str) -> str:
    """Build Vector VRL remap script enforcing per-category whitelists and event_time validation."""
    panel_scope = validate_panel_scope(panel_scope)
    node_id = validate_node_id(node_id)
    collector_id = validate_identifier(collector_id, "collector_id")

    conn_events_json = json.dumps(CONNECTION_EVENTS)
    res_events_json = json.dumps(RESOLVER_EVENTS)
    node_events_json = json.dumps(NODE_EVENTS)
    conn_fields_json = json.dumps(CONNECTION_ALLOWED_FIELDS)
    res_fields_json = json.dumps(RESOLVER_ALLOWED_FIELDS)
    node_fields_json = json.dumps(NODE_ALLOWED_FIELDS)

    lines = [
        "# Generated by Xboardme deploy/diagnostics/configure.py — DO NOT EDIT.",
        "# Strict per-event-family whitelist; never forward raw message or fallback event_time to now().",
        "raw_line = string(.message) ?? \"\"",
        "if strlen(raw_line) == 0 || strlen(raw_line) > 4096 {",
        "  abort",
        "}",
        "parsed, err = parse_json(raw_line)",
        "if err != null || !is_object(parsed) {",
        "  abort",
        "}",
        "record = object!(parsed)",
        "if " + " || ".join(f"exists(record.{field})" for field in FORBIDDEN_PII_FIELDS) + " {",
        "  abort",
        "}",
        "schema_ver = int(record.schema_version) ?? 0",
        "if schema_ver != 1 {",
        "  abort",
        "}",
        "family = string(record.event_family) ?? \"\"",
        "ev_name = string(record.event) ?? \"\"",
        "ev_id = string(record.event_id) ?? \"\"",
        "if !match(ev_id, r'^[0-9a-f]{64}$') {",
        "  abort",
        "}",
        "# Validate event_time strictly; never fallback to now() on missing/invalid/out-of-range timestamps.",
        "ev_time_str = string(record.event_time) ?? \"\"",
        "ev_ts, ts_err = parse_timestamp(ev_time_str, format: \"%+\")",
        "if ts_err != null {",
        "  abort",
        "}",
        "now_ts = now()",
        "age_sec = to_unix_timestamp(now_ts) - to_unix_timestamp(ev_ts)",
        "if age_sec < -600 || age_sec > 259200 {",
        "  abort",
        "}",
        f"allowed_fields = []",
        f"if family == \"connection\" && includes({conn_events_json}, ev_name) {{",
        "  diag_id = string(record.diagnostic_id) ?? \"\"",
        "  conn_id = string(record.connection_id) ?? \"\"",
        "  if !match(diag_id, r'^[0-9a-f]{24}$') || !match(conn_id, r'^[0-9a-f]{32}$') {",
        "    abort",
        "  }",
        f"  allowed_fields = {conn_fields_json}",
        f"}} else if family == \"resolver_operation\" && includes({res_events_json}, ev_name) {{",
        "  op_id = string(record.resolver_operation_id) ?? \"\"",
        "  if !match(op_id, r'^[0-9a-f]{32}$') || exists(record.diagnostic_id) || exists(record.connection_id) {",
        "    abort",
        "  }",
        f"  allowed_fields = {res_fields_json}",
        f"}} else if family == \"node\" && includes({node_events_json}, ev_name) {{",
        "  if exists(record.diagnostic_id) || exists(record.connection_id) {",
        "    abort",
        "  }",
        f"  allowed_fields = {node_fields_json}",
        "} else {",
        "  abort",
        "}",
        "clean = {}",
        "for_each(allowed_fields) -> |_idx, key| {",
        "  val = get(record, [key]) ?? null",
        "  if val != null {",
        "    clean = set!(clean, [key], val)",
        "  }",
        "}",
        "cursor = string(.__CURSOR) ?? \"\"",
        "if cursor != \"\" {",
        "  clean.journal_cursor_hash = sha2(cursor, variant: \"SHA-256\")",
        "}",
        "clean.collected_at = format_timestamp!(now_ts, format: \"%+\")",
        f"clean.panel_scope = {json.dumps(panel_scope)}",
        f"clean.node_id = {node_id}",
        f"clean.collector_id = {json.dumps(collector_id)}",
        "clean.service = \"xboard-node\"",
        "if !is_string(clean.safe_message) || string!(clean.safe_message) == \"\" {",
        "  clean.safe_message = ev_name",
        "}",
        ". = clean",
    ]
    return "\n".join(lines)


def generate_central(
    directory: Path | str,
    panel_scope: str,
    node_ids: list[int],
    storage_id: str = "pilot-bingo-dev-v1",
    listen_port: int = DEFAULT_LISTEN_PORT,
    expected_fs_uuid: str = "",
) -> dict[str, Any]:
    """Generate central VictoriaLogs + vmauth + diagnostics-guard + systemd mount protection configs."""
    panel_scope = validate_panel_scope(panel_scope)
    storage_id = validate_identifier(storage_id, "storage_id")
    validated_nodes = sorted({validate_node_id(n) for n in node_ids})
    if not validated_nodes:
        raise ValueError("at least one --node-id is required")
    if listen_port <= 1024 or listen_port > 65535 or listen_port == 19428:
        raise ValueError("listen_port must be an unprivileged port distinct from operational logs (19428)")

    base_dir = Path(directory).resolve()
    base_dir.mkdir(parents=True, exist_ok=True, mode=0o700)

    secret_path = base_dir / "secrets.json"
    if secret_path.is_symlink():
        raise ValueError("refusing symlink secrets.json")

    existing: dict[str, Any] = {}
    if secret_path.exists():
        existing = json.loads(secret_path.read_text(encoding="utf-8"))

    viewer = existing.get("viewer") or {}
    viewer_state = {
        "username": viewer.get("username") or "diagnostics-viewer",
        "password": viewer.get("password") or secrets.token_urlsafe(36),
        "bearer_token": viewer.get("bearer_token") or secrets.token_urlsafe(36),
    }

    writers_state: dict[str, dict[str, Any]] = {}
    existing_writers = existing.get("writers") or {}
    for nid in validated_nodes:
        key = str(nid)
        prev = existing_writers.get(key) or {}
        writers_state[key] = {
            "node_id": nid,
            "collector_id": prev.get("collector_id") or f"collector-node-{nid}",
            "bearer_token": prev.get("bearer_token") or secrets.token_urlsafe(36),
        }

    secrets_payload = {
        "schema_version": 1,
        "panel_scope": panel_scope,
        "storage_id": storage_id,
        "viewer": viewer_state,
        "writers": writers_state,
    }
    atomic_write_json(secret_path, secrets_payload, mode=0o600)

    # Read-only viewer identity: only `/select/logsql/query` (direct to VictoriaLogs) and `/diagnostics/health` (to guard).
    # Cannot call `/insert/*` or mutate guard state.
    viewer_url_map = [
        {
            "src_paths": ["/select/logsql/query"],
            "url_prefix": "http://victoria-logs:9428",
        },
        {
            "src_paths": ["/diagnostics/health"],
            "url_prefix": "http://diagnostics-guard:9430",
        },
    ]
    vmauth_users: list[dict[str, Any]] = [
        {
            "username": viewer_state["username"],
            "password": viewer_state["password"],
            "url_map": viewer_url_map,
        },
        {
            "bearer_token": viewer_state["bearer_token"],
            "url_map": viewer_url_map,
        },
    ]

    # Per-node writer identities: restricted strictly to `/insert/jsonline` routed through `diagnostics-guard`.
    # Fixed extra_fields override any client-supplied panel_scope, node_id, collector_id.
    for key, writer in sorted(writers_state.items(), key=lambda item: int(item[0])):
        nid = writer["node_id"]
        cid = writer["collector_id"]
        fixed_query = (
            "_time_field=event_time"
            "&_msg_field=safe_message"
            "&_stream_fields=panel_scope,node_id,service,event_family"
            f"&extra_fields=panel_scope={panel_scope},node_id={nid},collector_id={cid},service=xboard-node"
        )
        vmauth_users.append(
            {
                "bearer_token": writer["bearer_token"],
                "url_map": [
                    {
                        "src_paths": ["/insert/jsonline"],
                        "url_prefix": f"http://diagnostics-guard:9430/insert/jsonline?{fixed_query}",
                        "headers": [
                            f"X-Diagnostics-Panel-Scope: {panel_scope}",
                            f"X-Diagnostics-Node-Id: {nid}",
                            f"X-Diagnostics-Collector-Id: {cid}",
                        ],
                    }
                ],
            }
        )

    atomic_write_json(base_dir / "auth.json", {"users": vmauth_users}, mode=0o600)

    guard_config = {
        "schema_version": 1,
        "panel_scope": panel_scope,
        "storage_id": storage_id,
        "listen_addr": "0.0.0.0:9430",
        "upstream_insert_url": "http://victoria-logs:9428/insert/jsonline",
        "data_mount_path": "/storage",
        "root_mount_path": "/host-root",
        "host_data_mount_path": f"/host-root{(base_dir / 'data').resolve().as_posix()}",
        "guard_mount_read_only": True,
        "storage_writable_by_writer": True,
        "state_dir": "/state",
        "expected_fs_type": "ext4",
        "expected_fs_uuid": expected_fs_uuid.strip(),
        "check_interval_seconds": 30,
        "max_check_age_seconds": 60,
        "max_insert_body_bytes": 4 * 1024 * 1024,
        "upstream_timeout_seconds": 5,
        "thresholds": {
            "data_total_bytes": 30 * 1024**3,
            "warn_used_bytes": 16 * 1024**3,
            "critical_used_bytes": 20 * 1024**3,
            "block_used_bytes": 24 * 1024**3,
            "block_min_free_bytes": 6 * 1024**3,
            "vl_emergency_free_bytes": 5 * 1024**3,
            "root_min_free_bytes": 50 * 1024**3,
            "root_min_free_ratio": 0.20,
        },
        "allowed_writers": {
            str(w["node_id"]): {
                "node_id": w["node_id"],
                "collector_id": w["collector_id"],
                "panel_scope": panel_scope,
            }
            for w in writers_state.values()
        },
    }
    atomic_write_json(base_dir / "guard-config.json", guard_config, mode=0o600)

    # Copy guard.py and its required local module configure.py into target directory
    # so the container and standalone invocations have self-contained immutable scripts.
    for module_name in ("guard.py", "configure.py"):
        module_src = Path(__file__).with_name(module_name)
        if module_src.exists():
            atomic_write_text(
                base_dir / module_name,
                module_src.read_text(encoding="utf-8"),
                mode=0o600,
            )

    (base_dir / "state").mkdir(exist_ok=True, mode=0o700)

    vl_service = base_service(VL_IMAGE, "1536m", "1.0")
    vl_service.update(
        {
            "command": [
                "-storageDataPath=/storage",
                "-retentionPeriod=3d",
                "-retention.maxDiskSpaceUsageBytes=20GiB",
                "-storage.minFreeDiskSpaceBytes=5368709120",
                "-memory.allowedPercent=70",
            ],
            "volumes": [
                {
                    "type": "bind",
                    "source": "./data",
                    "target": "/storage",
                    "bind": {"create_host_path": False},
                }
            ],
        }
    )

    guard_service = base_service(GUARD_IMAGE, "64m", "0.25")
    guard_service.update(
        {
            "command": [
                "python3",
                "/app/guard.py",
                "serve",
                "--config",
                "/config/guard-config.json",
            ],
            "volumes": [
                {
                    "type": "bind",
                    "source": "./guard.py",
                    "target": "/app/guard.py",
                    "read_only": True,
                    "bind": {"create_host_path": False},
                },
                {
                    "type": "bind",
                    "source": "./configure.py",
                    "target": "/app/configure.py",
                    "read_only": True,
                    "bind": {"create_host_path": False},
                },
                {
                    "type": "bind",
                    "source": "./guard-config.json",
                    "target": "/config/guard-config.json",
                    "read_only": True,
                    "bind": {"create_host_path": False},
                },
                {
                    "type": "bind",
                    "source": "./state",
                    "target": "/state",
                    "bind": {"create_host_path": False},
                },
                {
                    "type": "bind",
                    "source": "./data",
                    "target": "/storage",
                    "read_only": True,
                    "bind": {"create_host_path": False},
                },
                {
                    "type": "bind",
                    "source": "/",
                    "target": "/host-root",
                    "read_only": True,
                    "bind": {"create_host_path": False},
                },
            ],
            "depends_on": ["victoria-logs"],
        }
    )

    auth_service = base_service(AUTH_IMAGE, "128m", "0.25")
    auth_service.update(
        {
            "command": [
                "-auth.config=/config/auth.json",
                "-httpListenAddr=:8427",
                "-httpInternalListenAddr=127.0.0.1:8426",
            ],
            "volumes": [
                {
                    "type": "bind",
                    "source": "./auth.json",
                    "target": "/config/auth.json",
                    "read_only": True,
                    "bind": {"create_host_path": False},
                }
            ],
            "ports": [f"127.0.0.1:{listen_port}:8427"],
            "depends_on": ["victoria-logs", "diagnostics-guard"],
        }
    )

    compose_payload = {
        "name": PILOT_COMPOSE_PROJECT,
        "services": {
            "victoria-logs": vl_service,
            "diagnostics-guard": guard_service,
            "auth": auth_service,
        },
    }
    atomic_write_json(base_dir / "compose.yaml", compose_payload, mode=0o600)

    systemd_service = "\n".join(
        [
            "[Unit]",
            "Description=Xboard Customer Connection Diagnostics Pilot Stack",
            "After=network.target docker.service",
            "Requires=docker.service",
            "",
            "[Service]",
            "Type=oneshot",
            "RemainAfterExit=yes",
            f"WorkingDirectory={base_dir}",
            f"ExecStartPre=/usr/bin/env bash {base_dir}/preflight-storage.sh --verify-mounted --directory {base_dir}",
            f"ExecStart=/usr/bin/docker compose -f {base_dir}/compose.yaml up -d",
            f"ExecStop=/usr/bin/docker compose -f {base_dir}/compose.yaml down",
            "Restart=no",
            "",
            "[Install]",
            "WantedBy=multi-user.target",
            "",
        ]
    )
    atomic_write_text(
        base_dir / "xboard-customer-diagnostics-pilot.service",
        systemd_service,
        mode=0o600,
    )

    return {
        "project": PILOT_COMPOSE_PROJECT,
        "directory": str(base_dir),
        "panel_scope": panel_scope,
        "storage_id": storage_id,
        "listen_port": listen_port,
        "node_ids": validated_nodes,
    }


def generate_collector(
    directory: Path | str,
    panel_scope: str,
    node_id: int,
    collector_id: str,
    token: str,
    tag: str,
    endpoint: str,
    unit: str | None = None,
    metrics_port: int = 19439,
) -> dict[str, Any]:
    """Generate per-node Vector collector configuration with 2 GiB isolated disk buffer."""
    panel_scope = validate_panel_scope(panel_scope)
    node_id = validate_node_id(node_id)
    collector_id = validate_identifier(collector_id, "collector_id")
    tag = validate_identifier(tag, "tag")

    url = urlsplit(endpoint)
    if (
        url.scheme not in ("http", "https")
        or url.username
        or url.password
        or url.query
        or url.fragment
        or url.path not in ("", "/")
    ):
        raise ValueError("collector endpoint must be a clean HTTP(S) base URL without query or embedded credentials")
    if url.scheme == "http" and url.hostname not in ("127.0.0.1", "localhost"):
        raise ValueError("plain HTTP collector endpoint must be loopback (127.0.0.1 or localhost)")
    if not re.fullmatch(r"[A-Za-z0-9_-]{32,128}", token.strip()):
        raise ValueError("invalid writer bearer token")

    sources: dict[str, Any] = {
        "journal": {
            "type": "journald",
            "current_boot_only": False,
            "emit_cursor": True,
            "include_matches": {"CONTAINER_TAG": [tag]},
            "extra_args": [f"CONTAINER_TAG={tag}"],
        }
    }
    if unit:
        if not re.fullmatch(r"xboard[-a-zA-Z0-9@_.]*\.service", unit):
            raise ValueError("only exact Xboard service units are supported")
        sources["node_journal"] = {
            "type": "journald",
            "current_boot_only": False,
            "emit_cursor": True,
            "include_units": [unit],
            "extra_args": [f"_SYSTEMD_UNIT={unit}"],
        }

    input_names = list(sources.keys())
    sources["internal_metrics"] = {
        "type": "internal_metrics",
        "scrape_interval_secs": 30,
    }

    sink_uri = endpoint.rstrip("/") + "/insert/jsonline"

    collector_config = {
        "data_dir": "/var/lib/vector-diagnostics",
        "sources": sources,
        "transforms": {
            "diagnostics_whitelist": {
                "type": "remap",
                "inputs": input_names,
                "source": build_vrl_remap(panel_scope, node_id, collector_id),
            }
        },
        "sinks": {
            f"diagnostics_node_{node_id}": {
                "type": "http",
                "inputs": ["diagnostics_whitelist"],
                "uri": sink_uri,
                "auth": {"strategy": "bearer", "token": token.strip()},
                "compression": "gzip",
                "encoding": {"codec": "json"},
                "framing": {"method": "newline_delimited"},
                "buffer": {
                    "type": "disk",
                    "max_size": 2147483648,
                    "when_full": "block",
                },
                "acknowledgements": {"enabled": True},
                "healthcheck": {"enabled": False},
                "batch": {"timeout_secs": 2},
                "request": {
                    "retry_initial_backoff_secs": 1,
                    "retry_max_duration_secs": 30,
                    "timeout_secs": 10,
                },
            },
            "metrics": {
                "type": "prometheus_exporter",
                "inputs": ["internal_metrics"],
                "address": f"127.0.0.1:{metrics_port}",
            },
        },
    }

    base_dir = Path(directory).resolve()
    base_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    (base_dir / f"vector-diagnostics-data-node-{node_id}").mkdir(
        exist_ok=True, mode=0o700
    )

    atomic_write_json(base_dir / "collector.yaml", collector_config, mode=0o600)

    vector_service = base_service(VECTOR_IMAGE, "384m", "1.0")
    vector_service.update(
        {
            "restart": "unless-stopped",
            "command": ["--config", "/etc/vector/collector.yaml"],
            "network_mode": "host",
            "group_add": journal_groups(),
            "volumes": [
                "./collector.yaml:/etc/vector/collector.yaml:ro",
                f"./vector-diagnostics-data-node-{node_id}:/var/lib/vector-diagnostics",
                "/var/log/journal:/var/log/journal:ro",
                "/run/log/journal:/run/log/journal:ro",
                "/etc/machine-id:/etc/machine-id:ro",
            ],
        }
    )

    compose_payload = {
        "name": f"xboard-diagnostics-collector-node-{node_id}",
        "services": {"collector": vector_service},
    }
    atomic_write_json(base_dir / "collector-compose.yaml", compose_payload, mode=0o600)

    return {
        "project": f"xboard-diagnostics-collector-node-{node_id}",
        "directory": str(base_dir),
        "panel_scope": panel_scope,
        "node_id": node_id,
        "collector_id": collector_id,
    }


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Generate customer connection diagnostics logging configuration."
    )
    sub = parser.add_subparsers(dest="command", required=True)

    c_central = sub.add_parser("central")
    c_central.add_argument("--directory", required=True)
    c_central.add_argument("--panel-scope", required=True)
    c_central.add_argument("--storage-id", default="pilot-bingo-dev-v1")
    c_central.add_argument("--node-id", action="append", type=int, required=True)
    c_central.add_argument("--listen-port", type=int, default=DEFAULT_LISTEN_PORT)
    c_central.add_argument("--expected-fs-uuid", default="")

    c_collector = sub.add_parser("collector")
    c_collector.add_argument("--directory", required=True)
    c_collector.add_argument("--panel-scope", required=True)
    c_collector.add_argument("--node-id", type=int, required=True)
    c_collector.add_argument("--collector-id", required=True)
    c_collector.add_argument("--token-file", required=True)
    c_collector.add_argument("--tag", required=True)
    c_collector.add_argument("--endpoint", required=True)
    c_collector.add_argument("--unit", default=None)
    c_collector.add_argument("--metrics-port", type=int, default=19439)

    args = parser.parse_args()
    if args.command == "central":
        generate_central(
            directory=args.directory,
            panel_scope=args.panel_scope,
            node_ids=args.node_id,
            storage_id=args.storage_id,
            listen_port=args.listen_port,
            expected_fs_uuid=args.expected_fs_uuid,
        )
    else:
        token = Path(args.token_file).read_text(encoding="utf-8").strip()
        generate_collector(
            directory=args.directory,
            panel_scope=args.panel_scope,
            node_id=args.node_id,
            collector_id=args.collector_id,
            token=token,
            tag=args.tag,
            endpoint=args.endpoint,
            unit=args.unit,
            metrics_port=args.metrics_port,
        )


if __name__ == "__main__":
    main()
