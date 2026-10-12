# Production release retention and cleanup policy

This policy applies to the distributor production host. Cleanup is a guarded
release operation, not a general Docker housekeeping task.

## Separate non-disruptive image-only path

Use `production-image-cleanup.yml` only with its fixed, independently approved
`image-cleanup-review.json`. Its default action is a lightweight audit. It does
not finalize releases or remove containers, data, volumes, directories or
rollback generations. The older finalized-retention path described below is
not a prerequisite for image-only cleanup. See
`image-only-cleanup-preparation-2026-10-12.md` for this path's gates and limits.

Approval permits exactly one unreferenced image ID, at most 512 MiB, eight
aliases and at least seven days old. No force/prune, automatic next batch,
automatic retry or application deployment is provided. Two explicitly reviewed
contracts are supported; neither is selected by a skip flag or environment:

- Schema 2 retains the 600s/900s live traffic/connection contract. Missing
  signals fail closed; evidence hashes do not replace those signals.
- Schema 3 (`passive_runtime_v1`) is the narrowly authorized idle-image path.
  It uses a fixed 120s baseline and 300s post-removal observation, sampled every
  10s, with a 30s maximum heartbeat age. It preserves every container and
  observes Caddy/container identity, existing running-container health and host
  resource pressure. It sends no application HTTP/WS requests, performs no
  login, and installs no monitoring producer. It does not certify individual
  connections, natural traffic or absolute zero latency impact. See
  `image-only-passive-scope-2026-10-12.md` for the reviewed scope and stop limits.

RUNNING/HOLD persist in `image-cleanup-gate.json`. COMPLETE with independent
post-verification still pending also blocks new deployment. Only exact-main
post-verification may mark it passed, after exact inventory/identity comparison
and evidence preservation succeed. Schema 2 additionally requires the original
public/authenticated smoke; schema 3 requires an exact-digest restore check on
the isolated CI runner before any production access, not an application login.
A later separately approved image
request archives a COMPLETE/passed gate without overwriting old evidence;
RUNNING/HOLD are never automatically reset. Existing emergency rollback is
not blocked. A committed approval must bind fresh audit evidence, the selected
contract and the isolated Docker rehearsal. Schema changes never clear an old
RUNNING/HOLD, malformed gate or COMPLETE with verification still pending.

## Safety invariants

The following resources are never cleanup candidates:

- the runtime selected by the host Caddy loopback upstream;
- every container in the active V2 Compose project;
- the current release's maintenance proxy, even when it has no public traffic;
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
   containers remain intact for 24 hours when no successor release is being
   delivered. Retention time is not a deployment-admission lock.
4. `retention_audit` is read-only. It discovers production from Caddy, validates
   the active image revision and release state, and records a full inventory
   fingerprint.
5. `v2_finalize` is allowed only for the exact active release. The manual path
   runs after the 24-hour rollback window. A successor-release path may finalize
   earlier only after that exact signed successor has passed production
   preflight, isolated database-clone staging, authenticated browser smoke and
   immutable preparation. Both paths require an authenticated public smoke test
   before deletion, retire only the recorded oldest direct rollback containers,
   preserve the current maintenance proxy, volumes, backups, release state and the Compose anchor, and repeat
   preflight, inventory and authenticated smoke tests afterward.
6. `retention_cleanup` is a separate post-finalize action. It requires a
   committed reviewed allowlist and exact resource fingerprint, holds the
   deployment lock, freezes its first inventory/plan, and resumes only the
   original remaining objects. A fresh scan never grants deletion authority.
   Independent protected-identity checks are attempted after every batch,
   including a failed batch when the runner/host remain reachable. Authenticated
   smoke runs only after identity and preflight both pass.
7. Standard delivery discovers an unused loopback stage port from the guarded
   range and maintains exactly one direct rollback generation. If the active
   release still protects its predecessor, the successor is staged and prepared
   first; only then is the oldest generation retired under the deployment lock.
   The active runtime remains untouched and becomes the successor's direct
   rollback target after switch. A future release is blocked only when identity,
   lifecycle, port, health, migration or verification invariants fail.

## Continuous delivery contract

1. Pull requests run the complete applicable integration and release-safety
   checks before merge. An empty check set is not evidence.
2. A production-branch push builds one immutable image, records its full source
   SHA and digest, and publishes signed provenance. Release never rebuilds it.
3. The standard release resolves that exact successful build, performs
   production preflight, chooses an unused stage port, rehearses against an
   isolated database clone and runs authenticated browser smoke tests.
4. Rollback-slot rotation is part of the same serialized release workflow. It
   has no general-purpose force input and cannot run before candidate staging
   and immutable preparation succeed.
5. Production preparation and traffic switch remain explicit protected
   environment boundaries. Continuous delivery removes manual lifecycle chores;
   it does not remove production authorization or fail-closed controls.

## One-time historical debt cleanup

Historical debt predating this policy is handled separately from `finalize`:

1. Run `retention_audit` from the exact `main` workflow SHA and
   retain its log/artifact, full audit fingerprint and stable resource-identity
   fingerprint.
2. Classify every object as `anchor`, `active`, `direct_rollback`, a recognized
   Xboard cleanup candidate, or `unrelated`. Unrelated Docker resources are out
   of scope.
3. For every candidate, cross-check container ID, project/release labels,
   running state, bound loopback port, Caddy references, image revision, mounts,
   release state and rollback relationship. Any mismatch blocks deletion.
4. The reviewed resource fingerprint binds
   active and rollback identity, every container ID/label/state/port/mount, and
   every local image ID/revision/source/digest. Volatile disk-use metrics and
   directory sizes are evidence but are excluded from that deletion identity.
   The independent protection fingerprint covers only current Caddy routing,
   all six active roles, current maintenance, anchor, image IDs, start/restart
   identity and actual mount sources/volume identities. It must stay unchanged;
   the full inventory hash normally changes when approved objects are removed.
5. Delete only exact allowlisted, still-exited containers. Bind each to its
   historical state, reviewed writable-layer contents and startup references.
   Do not stop a running container, including an old maintenance proxy. Never
   pass force/volume-removal flags or tear down a Compose project.
6. Delete only locally unreferenced application images that are at least seven
   days old, carry a 40-character revision, have a recorded GHCR digest, and
   declare one of the two reviewed Xboard source repositories. Never use image
   prune or force removal. Every `docker image rm` uses `--no-prune` to prevent
   implicit removal of an unapproved untagged parent. Inventory includes
   intermediate images (`image ls -a`). Image references must belong to the matching GHCR
   repository; historical FengHaoyun images may additionally carry the local
   `xboard-rollback` tag or digest alias. Third-party images are outside this
   cleanup scope. Preserve release directories, secrets, state and backup
   evidence. Explicitly pin the approved pending release SHA and immutable
   digest even after a later maintenance commit changes the workflow SHA.
   An image made unreferenced by this cleanup is not automatically added.
7. Re-run production preflight, Caddy validation, authenticated distributor and
   admin smoke tests, scheduler/role checks, data/Redis mount identity checks,
   and `retention_audit`. Compare the before/after protected-resource identity.

## Manual approval and bounded execution

`.github/release/retention-review.json` ships with `status: blocked`, empty
execution IDs and empty evidence references. This is deliberate: preparing
the executor does not approve a production deletion. Both manual operations
reject it before an authenticated smoke or host mutation. Normal signed
successor-release rotation uses its separate existing admission contract.

An approved review must bind the operation, exact active release ID/SHA,
fresh resource and protection fingerprints, every full container/image ID and
snapshot hash, protected image revisions/digests, and hashes of the reviewed
backup, monitoring, authentication, isolated-Docker and per-object evidence.
These hashes identify independently reviewed evidence; validating their shape
does **not** establish that a backup is usable, a monitor is working, or a test
passed. Preserve the referenced evidence outside secrets-bearing artifacts.
Never populate them with synthetic hashes to pass a production gate.

The workflow validates that the checkout and current remote `main` match the
full workflow SHA, verifies the active SHA is in its history, then sends the
raw reviewed JSON and SHA-256 to the host. The JSON is parsed, not sourced or
evaluated. The host verifies live identity again under the common release lock.

- The first batch processes at most one object. Cleanup later processes at
  most five objects, including at most two exited containers; manual finalize
  processes at most two exited containers. The requested ceiling defaults to
  one. No automatic loop dispatches the next batch.
- Cleanup observes protection and inventory for at least 60 seconds. This is
  not an HTTP/latency/5xx/I/O monitor: the separately reviewed production
  monitoring baseline and stop thresholds remain mandatory execution gates.
- Both remote mutators are process-group bounded to 900 seconds, with TERM
  followed by KILL after 30 seconds; the workflow job allows 20 minutes.
  Individual Docker removals are also bounded. A timeout remains failure,
  never a successful completion.
- `pending_batch` is progress, not completion. Finalize atomically records
  `finalizing + rollback_supported=false` before the first deletion and
  `finalized + rollback_supported=false` only after all recorded objects are
  retired. It keeps a per-ID pending/completed ledger and an immutable plan.
- Cleanup freezes inventory/approval/workflow identity and persists deletion
  intent before each destructive call. Only recorded intents can explain a
  missing object or removed alias on retry. New IDs, new aliases, changed
  history or protected identity block the next removal. Old attempts without
  a verifiable plan fail closed.
- A retry must retain the original approved manifest and workflow identity.
  If `main` or evidence changes, stop and review a recovery procedure; do not
  dispatch an old workflow or edit state to bypass this restriction.
- Finalize changes the lifecycle/inventory. A separate fresh audit and
  approved cleanup manifest are required before historical-image cleanup.

The preparation's Docker protocol test creates only labelled local synthetic
resources, never running application containers. It checks multiple tags,
shared layers and bind/volume retention. It does not replace a full isolated
executor/SSH-loss test, multiple RepoDigest semantics, backup restore rehearsal,
production ownership review, monitoring or post-cleanup public acceptance.
It was not executed during this preparation: its status is NOT RUN.

## Authentication and evidence

Authenticated smoke is not entirely read-only: a dedicated test account login
may update login metadata or trigger installed hooks. Review the real deployed
hooks before execution. The smoke keeps credentials/token responses in private
temporary files and revokes only its own returned session via `user/logout`,
then verifies that token is rejected before closing its tunnel. It does not log
out other sessions or modify the test account's business attributes.

Network loss, a lost login response, SIGKILL or runner loss can prevent proof
of session revocation. When exit handling or the outer supervisor still runs,
it reports `INCOMPLETE` with non-secret run metadata. A missing trustworthy
cleanup result is also unverified revocation, even when no marker could be
written. These cases require a scoped follow-up review; do not claim revocation,
disable the account or invalidate unrelated sessions to conceal this gap.

Post-operation preflight and inventory are independent steps. Even a failed
preflight should leave a locked read-only inventory when identity admission and
host access succeeded. Active release ID/SHA and the independent protection
fingerprint are compared after success, failure or cancellation. Authenticated
post-smoke is allowed only after both identity and preflight pass. Artifacts
are retained on failure when the runner is available. A green post-audit does
not turn a failed mutator into a successful cleanup.

## Rollback and failure handling

- Before finalize, rollback uses the recorded direct previous runtime.
- During a successor release, the previous active runtime remains running while
  the oldest rollback generation is retired. After switch it becomes the new
  direct rollback target, so rollback depth stays at one without blocking
  delivery.
- Finalize is fail-closed and retryable. It records the previous release ID
  and, for a successor rotation, the exact superseding SHA before deleting its
  containers so a partial cleanup cannot change identity.
- Cancellation/SSH loss may leave a pending deletion intent or no post-audit.
  Wait for the remote timeout/lock to terminate, perform a guarded read-only
  recovery audit, and review exact remaining IDs before any retry. Do not
  equate an Actions failure with "nothing was deleted".
- If a post-finalize verification fails, stop further cleanup and investigate;
  do not recreate containers from mutable tags. Restore only from the recorded
  immutable digest and retained state/backups.
- No further production mutation may continue while release-slot planning,
  identity, health, migration, stage, smoke or rotation verification fails.
- Cleanup has no automatic business rollback. Removed writable layers and
  container logs are not recoverable merely by pulling an image; archive any
  required evidence first. Do not restore an old database, restart the current
  runtime, or auto-deploy the pending application to repair cleanup failure.
