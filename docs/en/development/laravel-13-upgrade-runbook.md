# Laravel 13 upgrade and zero-downtime release runbook

## Purpose and fixed scope

This runbook governs the XBoard Laravel 12 to Laravel 13 upgrade. The release
must preserve public routes, API behavior, permissions, active Sanctum tokens,
cache and Redis prefixes, queue semantics, subscription output, plugins, and
all existing business data. The framework upgrade adds no business migration.

The release baseline is:

- PHP 8.3 in production; PHP 8.3 and 8.4 in CI.
- Laravel 13.x resolved by the committed `composer.lock`.
- Swoole 6.1.0 / PHP 8.3 using the pinned multi-architecture image digest.
- The existing persistent SQLite database in WAL mode. Moving production data
  to MySQL is explicitly excluded from this framework release because it would
  add an unrelated, higher-risk data migration.
- The existing Redis data remains authoritative during the initial web
  cutover. Redis ownership is separated in a later infrastructure phase before
  the old all-in-one container is retired.
- Two web runtimes on the same host during cutover. The initial framework
  upgrade switched from loopback port 7001 to 7002; subsequent releases must
  discover the active Caddy upstream and use a separately approved free port.
  Port/color names are not production identity.

This topology is eligible only while SQLite remains on the same local host,
uses a persistent bind mount, passes `PRAGMA integrity_check`, and remains in
WAL mode. SQLite must never be placed on a network filesystem. Only one Redis,
Horizon, and Scheduler owner may run during the first cutover phase.

## Release artifacts and invariants

Before approval, record the Git commit, image digest, Composer lock content
hash, anonymized database snapshot identifier, and test report. Never deploy a
mutable image tag by itself.

The following values must be identical in blue and green environments:

- `APP_KEY`, `APP_URL`, cookie domain and Sanctum configuration;
- database connection and credentials;
- `REDIS_PREFIX`, `CACHE_PREFIX`, queue names and Horizon prefix;
- `SESSION_COOKIE` and `SESSION_SERIALIZATION=php`;
- shared plugin and private attachment storage;
- session continuity. XBoard currently does not register `StartSession` in the
  public `web` or `api` middleware groups and authenticates API users with
  persisted Sanctum tokens. The live prepare script verifies that invariant
  on the running blue container and blocks if file sessions become active. If
  a future release enables session middleware, a shared session store and a
  rehearsed migration become mandatory before cutover.

Each release must have separate code, `vendor`, bootstrap cache and local
runtime files. Do not share `bootstrap/cache` or Octane state files.

## Required test matrix

All applicable gates must pass before traffic is sent to green:

1. Composer validation, locked dependency installation, platform requirement
   check and locked security audit with no known Critical or High advisory.
2. PHPUnit on PHP 8.3 and 8.4 with SQLite.
3. JavaScript syntax and Node regression tests.
4. PHPStan with the committed historical baseline and no new violations.
5. Public route-contract and migration-inventory hashes.
6. Authentication, logout, permissions and pre-existing Sanctum token tests.
7. Order, payment callback, coupon, distributor and subscription regression.
8. Queue serialization, Horizon worker drain and retry behavior.
9. Redis cache-prefix, settings cache and lock behavior.
10. Octane request isolation and graceful shutdown.
11. Workerman WebSocket handshake, connection drain and automatic reconnect.
12. SQLite WAL rehearsal from a production snapshot, plus MySQL 5.7 and MySQL
    8.x compatibility tests to preserve supported installation modes.
13. amd64 and arm64 image builds from the same commit and lock file.
14. Built-in plugins, all eleven subscription protocols, and every production
    third-party plugin listed in the release inventory.

Any missing production plugin inventory or anonymized database snapshot blocks
production approval, but does not block local framework development.

## Production-shaped data rehearsal

1. Take a transactionally consistent, encrypted backup and record its checksum.
2. Restore it into an isolated rehearsal database with external integrations
   disabled and secrets replaced.
3. Run the current Laravel 12 release against the restored SQLite database and capture
   table row counts plus critical aggregates for users, tokens, orders,
   payments, commissions, subscriptions, servers and statistics.
4. Run `php artisan migrate --pretend --no-interaction` from the Laravel 13
   artifact. This framework-only release must report no pending migration.
5. Start Laravel 13 against the rehearsal database and execute the complete
   regression and smoke suites.
6. Repeat the row counts and aggregates. Any unexplained difference blocks the
   release.
7. Stop Laravel 13, start the exact Laravel 12 rollback artifact against the
   same database, and repeat authentication and order read-only smoke tests.

Do not run destructive restore tests against production. A database restore is
reserved for a separately authorized disaster-recovery event.

## Isolated green rehearsal on the production host

The rehearsal is not a traffic deployment. It is started by
`.github/scripts/stage-xboard-green.sh` and must satisfy all of these controls:

1. Locate exactly one Compose storage anchor, discover the real active web from
   Caddy plus its bound container, and reject an occupied requested stage port
   or another stage container.
2. Require an immutable GHCR digest, sufficient measured disk/memory, active
   host Caddy, persistent SQLite WAL, and all required mounts.
3. Use SQLite online backup, copy sessions and private attachments, and mount
   production environment/theme/plugin files read-only.
4. Start isolated Redis, logs, attachments and database storage. Disable
   Horizon, outbound mail and Telescope, and skip application update so
   production data, queues and notification channels cannot be mutated.
5. Verify HTTP health, Laravel 13, migration status, database integrity, core
   data counts, attachment status and the authenticated distributor smoke test.
6. Automatically remove the container and cloned files on a failed smoke test.
   A successful stage remains isolated for inspection and is removed explicitly
   after approval or rollback rehearsal through the manual
   `distributor-stage-cleanup.yml` workflow using the recorded stage run ID.

Use `STAGE_DRY_RUN=true` first. A dry run performs discovery and resource
checks only; it does not create directories, copy data, pull images or start a
container.

## Blue-green deployment

The workflow input `production_release_action` exposes deliberately separate
phases. The `prepare` phase consumes the exact successful isolated stage run,
creates and checksums an online SQLite backup, records the current active image
and port, and starts the live candidate on the approved inactive loopback port
without changing Caddy. Pending migrations are accepted only when their names
are committed in `.github/release/approved-migrations.txt`; the same migration
set is rehearsed on the isolated SQLite clone before it can touch live data.
The `switch` phase runs authenticated smoke first, validates a candidate Caddy
configuration, reloads Caddy atomically, observes public and direct health for
one minute, and restores the exact saved Caddy file automatically on failure.
The `activate_roles` phase moves Horizon and scheduler ownership only after
green traffic is healthy. `rollback` is independent from build/test jobs so it
can restore blue immediately. `cleanup` is allowed only while traffic is blue
and preserves backups and state evidence.

1. Verify blue is healthy and record its immutable image digest.
2. Verify public route groups do not use file sessions; otherwise stop until a
   shared session store has been migrated and rehearsed.
3. Disable Redis, Horizon and Scheduler in green. Blue remains their only owner.
4. Start green web and WebSocket instances with the same persistent SQLite,
   Redis socket, application key and shared storage through the blue
   container's already-proven persistent mounts.
5. Run platform checks, `artisan about`, configuration continuity checks,
   read-only database checks and internal smoke tests on green.
6. Confirm there are no pending migrations. Do not run a business migration as
   part of this release.
7. Send a small canary share of stateless HTTP traffic to green. Do not send
   payment callbacks until normal API and authentication metrics are healthy.
8. Observe HTTP 5xx, latency, authentication failures, Redis errors, database
   errors, queue depth and Octane worker health for the agreed canary window.
9. Atomically switch the host Caddy upstream from the recorded active port to
   the recorded candidate port, validate the configuration before reload, and
   keep the previous web running for immediate rollback.
10. Route new WebSocket connections to green while blue drains existing
    connections. Verify client reconnect behavior before terminating blue.
11. Start a Laravel 13 Horizon-only container against the existing authoritative
    Redis socket, prove it is running, and then stop the previous Horizon owner.
    On the first cutover that owner is inside Compose; on later rotations it is
    the prior release role container.
12. Transfer the scheduler in the same single-owner sequence. The first
    cutover freezes the Compose Octane scheduler; later rotations stop and
    retain the prior scheduler container. Retain the previous web, roles, Redis
    process and image for the full rollback window.

## Rollback triggers

Immediately roll back on any of the following:

- deployment-induced HTTP 5xx or a sustained material latency regression;
- active tokens becoming invalid or an authorization boundary changing;
- payment callback, order state, commission or subscription inconsistency;
- duplicate, lost or permanently stuck jobs;
- database writes or schema changes not present in the approved plan;
- Redis prefix drift, cache deserialization failures or cross-release leakage;
- WebSocket clients failing to reconnect within the accepted window;
- any new Critical or High security finding.

## Rollback procedure

1. Stop increasing green traffic and route all new HTTP and WebSocket
   connections back to the recorded blue image.
2. Disable green Scheduler before restoring blue Scheduler ownership.
3. Stop green Horizon from accepting jobs, allow active jobs to finish, then
   resume blue Horizon. Never run both schedulers simultaneously.
4. Verify login, a read-only user request, administrator authorization, order
   lookup, queue depth and WebSocket handshake on blue.
5. Preserve green logs, metrics, image digest and failure evidence for review.

Because this release has no database migration, normal rollback does not alter
or restore the database. If an unexpected data mutation is detected, stop the
release, preserve evidence and require explicit disaster-recovery authorization.

## Approval record

Production release remains a high-risk action. Approval must identify
the environment, Git commit, image digest, maintenance owner, observation
window, rollback owner, plugin inventory and database snapshot. The operator
must record the user's production authorization and the exact approved commit.
Authorization does not waive any quality gate: a failed or missing gate stops
the affected release phase.
