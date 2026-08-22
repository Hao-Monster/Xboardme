# V2 low-memory production activation

## Decision

Activate the V2 split-role topology on the existing 4 GB host by using a
bounded maintenance window and a cold in-place cutover. The host cannot safely
run the complete legacy and V2 topologies in parallel, but the measured V2
steady state fits after the retained legacy anchor is stopped.

This change adds deployment capability only. It does not activate production,
change application business logic, merge Xboard-Node PR #1, alter the database
schema, or retire the legacy rollback runtime.

## Verified production facts (2026-08-23, Asia/Singapore)

- Host memory: 3.82 GiB total, about 1.2 GiB available, no swap.
- Current released roles: web about 239 MiB, Horizon about 590 MiB, scheduler
  about 46 MiB.
- Retained legacy anchor: about 941 MiB and owns Redis plus shared storage.
- Redis: about 15 MiB logical memory, 102 MiB observed peak, 2,278 DB0 keys,
  healthy RDB persistence, AOF disabled.
- All inspected Horizon queues were empty during the audit.
- SQLite, logs, themes, attachments and plugins are bind-mounted; Redis uses
  the external `xboard_redis-data` volume.

## Invariants

1. The exact `codex/distributor` workflow commit, candidate image revision
   label and immutable image digest must match. The separately recorded legacy
   rollback containers may remain on their preceding internally consistent SHA.
2. The existing SQLite/storage bind mounts and Redis volume remain the only
   authoritative state. No writable clone is introduced.
3. During the rollback compatibility window Redis remains RDB-only. Every
   owner transition performs a synchronous authenticated `SAVE` before Redis
   stops. Preparation first generates an isolated V2 RDB and requires the
   exact retained legacy image to validate it with `redis-check-rdb`.
4. Public traffic moves to a static loopback-only maintenance service before
   any current runtime is stopped.
5. The legacy scheduler stops first, Horizon pauses, and all reserved queue
   jobs must drain before the legacy web, Horizon and Redis owner stop. A
   rollback also pauses and drains V2 jobs with a bounded wait; availability
   recovery continues with an explicit warning if a broken worker cannot
   report or drain them.
6. Exactly one Horizon, one scheduler and one Redis owner may run after each
   transition.
7. The old containers are stopped but retained until an explicit finalize at
   least 24 hours after switch.
8. Every mutating phase uses one host-wide non-blocking `flock` and a JSON
   release state file with an explicit schema version. A second deployment
   cannot overlap, and incompatible future scripts fail closed.
9. The Redis password remains inside the root-only release directory. Its
   file is read-only to group 1000, and preparation proves that the candidate
   application's UID 1000 can read the real bind-mounted secret.

## State machine

| Phase | Required state | Result | Public traffic |
|---|---|---|---|
| `v2_prepare` | legacy active | validates/extracts immutable artifacts, records exact mounts and container identities, pulls images | legacy |
| `v2_start` | `prepared` | maintenance on, legacy stopped, V2 core and owners healthy for a scheduler observation window | maintenance |
| `v2_switch` | `ready` | restores the validated production Caddy route to the V2 edge | V2 |
| `v2_rollback` | `ready` or `active_v2` | saves V2 Redis, stops V2, restores exact legacy containers and Caddy backup | legacy |
| `v2_finalize` | `active_v2` for at least 24 h | removes only the recorded stopped legacy containers; keeps volumes and backups | V2 |

An external authenticated smoke failure after `v2_switch` triggers
`v2_rollback` automatically. A failed `v2_start` first invokes the same
rollback routine inside the remote process; the workflow also provides a
second rollback attempt when the start or private smoke job fails. Smoke tests
resolve the loopback port from the protected release state instead of trusting
the manually supplied historical blue/green port.

## Initial limits

| Service | Memory limit |
|---|---:|
| edge | 64 MiB |
| web | 512 MiB |
| WebSocket | 192 MiB |
| Horizon | 768 MiB |
| scheduler | 128 MiB |
| Redis | 384 MiB (`maxmemory` 256 MiB, noeviction) |

Horizon is deliberately not reduced below its currently observed 590 MiB.
The limits are safety ceilings, not a capacity claim. OOM, restart, health,
owner uniqueness or scheduler-reaper failures block switch or trigger rollback.

## Rollback boundary

Rollback is supported until finalize. Finalize never deletes bind-mounted data,
the Redis volume, Caddy backups or the V2 release state. Enabling Redis AOF is a
separate later change and may only occur after the legacy RDB-only rollback
runtime has been retired.
