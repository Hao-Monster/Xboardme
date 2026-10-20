# Customer Connection Diagnostics Logging & Pilot Guard (`deploy/diagnostics/`)

Isolated configuration generator, capacity/mount guard, and verification suite for the Customer Connection Diagnostics pilot (`xboard-customer-diagnostics-pilot`).

## Design & Operational Boundaries

1. **Isolation from Operational Logs (`xboard-logs`)**:
   - Uses dedicated Compose project `xboard-customer-diagnostics-pilot`, dedicated loopback port `127.0.0.1:19438`, dedicated credentials (`secrets.json`), and a dedicated 30 GiB pre-allocated `ext4` loop image (`storage.ext4` mounted at `./data`).
   - Never shares data directories, Vector disk buffers, or retention settings with `xboard-logs` (`127.0.0.1:19428`).
2. **Pinned Immutable Images**:
   - VictoriaLogs: `victoriametrics/victoria-logs:v1.53.0@sha256:251121fa882af99b95ba0c230a4a2f412ea602d2698c64a96c58dc9842bb755d` (`1.0` CPU, `1536m` memory, `-retentionPeriod=3d`, `-retention.maxDiskSpaceUsageBytes=20GiB`, `-storage.minFreeDiskSpaceBytes=5368709120`, `-memory.allowedPercent=70`).
   - vmauth: `victoriametrics/vmauth:v1.153.0@sha256:4ebf2f21490df3e8837302b85d9db6ac45765442dfa28db77ffacd3577035a74` (`0.25` CPU, `128m` memory).
   - Capacity & Ingestion Guard (`diagnostics-guard`): `0.25` CPU, `64m` memory, single-instance check + write forwarding (`GET /diagnostics/health` & `POST /insert/jsonline`).
   - Per-node Vector collector: `1.0` CPU, `384m` memory, independent `2 GiB` disk buffer (`when_full: block`, `batch.timeout_secs: 2`).
3. **Mount & Root Disk Protection**:
   - `scripts/diagnostics/preflight-storage.sh` verifies root free space after 30 GiB allocation remains `>= max(50 GiB, 20% of root total)`, rejects sparse image files, refuses to overwrite existing images, and verifies active `ext4` mount + UUID before container startup.
   - Docker bind mounts set `bind.create_host_path: false` and `restart: "no"` so an unmounted directory on the root disk is never auto-created or written to.
4. **Capacity Guard & Persistent Latch (`guard.py`)**:
   - Runs every 30 seconds using a monotonic clock (`time.monotonic()`); every write verifies check age `<= 60s`.
   - `16 GiB` (`capacity_warning_16gib`), `20 GiB` (`capacity_critical_20gib`), `24 GiB` (`capacity_blocked_used_24gib`), `<= 6 GiB` free (`capacity_blocked_free_6gib`), `<= 5 GiB` emergency (`vl_emergency_free_5gib`), or root free `< max(50 GiB, 20%)` (`root_disk_free_below_threshold`).
   - Defaults to closed (`not_yet_enabled`) until explicitly enabled; any block condition or stale check sets a persistent latch in `state/guard-state.json` that survives restarts and requires explicit `guard.py resume --config ... --reason ...`.

## Local Verification Commands

```bash
python3 -m unittest discover -s deploy/diagnostics -p '*_test.py' -v
bash -n scripts/diagnostics/preflight-storage.sh
```
