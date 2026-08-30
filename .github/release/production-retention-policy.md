# Production release retention and cleanup policy

This policy applies to the distributor production host. Cleanup is a guarded
release operation, not a general Docker housekeeping task.

## Safety invariants

The following resources are never cleanup candidates:

- the runtime selected by the host Caddy loopback upstream;
- every container in the active V2 Compose project;
- the single retained Compose `xboard` anchor used to locate the authoritative
  working directory;
- containers recorded as the active release's direct rollback target while
  `rollback_supported` is true;
- the authoritative application data, logs, theme, attachment and plugin bind
  mounts, the `.env` file, and the external Redis volume;
- release state and Caddy/database backup evidence.

`docker system prune`, `docker volume prune`, broad path globs, mutable image
tags and container age alone are forbidden deletion selectors.

## Lifecycle

1. A production commit is tested once and built once. Its full commit SHA is
   resolved to one immutable image digest.
2. Stage, prepare, start and switch reuse that exact digest.
3. Switch records `switched_at` and `finalize_due_at`. Direct rollback
   containers remain intact for at least 24 hours.
4. `retention_audit` is read-only. It discovers production from Caddy, validates
   the active image revision and release state, and records a full inventory
   fingerprint.
5. `v2_finalize` is allowed only for the exact active release after the rollback
   window. It requires an authenticated public smoke test before deletion,
   retires only the recorded direct rollback containers, preserves volumes and
   the Compose anchor, and repeats preflight, inventory and authenticated smoke
   tests afterward.
6. A future release is blocked unless the active release is finalized and the
   selected stage port is unused. This prevents rollback chains from growing.

## One-time historical debt cleanup

Historical debt predating this policy is handled separately from `finalize`:

1. Run `retention_audit` from the exact `codex/distributor` workflow SHA and
   retain its log/artifact, full audit fingerprint and stable resource-identity
   fingerprint.
2. Classify every object as `anchor`, `active`, `direct_rollback`, a recognized
   Xboard cleanup candidate, or `unrelated`. Unrelated Docker resources are out
   of scope.
3. For every candidate, cross-check container ID, project/release labels,
   running state, bound loopback port, Caddy references, image revision, mounts,
   release state and rollback relationship. Any mismatch blocks deletion.
4. Delete only an explicit ID allowlist whose resource-identity fingerprint
   still matches the reviewed audit. Volatile disk-use metrics are evidence but
   are not deletion identities. Container cleanup must not remove volumes.
   Preserve release state and backup evidence.
5. Re-run production preflight, Caddy validation, authenticated distributor and
   admin smoke tests, scheduler/role checks, data/Redis mount identity checks,
   and `retention_audit`. Compare the before/after protected-resource identity.

## Rollback and failure handling

- Before finalize, rollback uses the recorded direct previous runtime.
- Finalize is fail-closed and retryable. It records the previous release ID
  before deleting its containers so a partial cleanup cannot change identity.
- If a post-finalize verification fails, stop further cleanup and investigate;
  do not recreate containers from mutable tags. Restore only from the recorded
  immutable digest and retained state/backups.
- No further production deployment may start while the retention gate fails.
