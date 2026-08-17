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
- MySQL and an external Redis service for a zero-downtime production rollout.
- At least two web instances behind a load balancer.

SQLite and the all-in-one embedded Redis topology remain supported for normal
operation, but they are not eligible for a zero-downtime rollout.

## Release artifacts and invariants

Before approval, record the Git commit, image digest, Composer lock content
hash, anonymized database snapshot identifier, and test report. Never deploy a
mutable image tag by itself.

The following values must be identical in blue and green environments:

- `APP_KEY`, `APP_URL`, cookie domain and Sanctum configuration;
- database connection and credentials;
- `REDIS_PREFIX`, `CACHE_PREFIX`, queue names and Horizon prefix;
- `SESSION_COOKIE` and `SESSION_SERIALIZATION=php`;
- shared plugin and private attachment storage.

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
12. MySQL 5.7 and MySQL 8.x upgrade tests against production-shaped data.
13. amd64 and arm64 image builds from the same commit and lock file.
14. Built-in plugins, all eleven subscription protocols, and every production
    third-party plugin listed in the release inventory.

Any missing production plugin inventory or anonymized database snapshot blocks
production approval, but does not block local framework development.

## Production-shaped data rehearsal

1. Take a transactionally consistent, encrypted backup and record its checksum.
2. Restore it into an isolated rehearsal database with external integrations
   disabled and secrets replaced.
3. Run the current Laravel 12 release against the restored database and capture
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

## Blue-green deployment

1. Verify blue is healthy and record its immutable image digest.
2. Disable Scheduler in green. Blue remains the only Scheduler owner.
3. Start green web and WebSocket instances with the same external MySQL,
   Redis, application key and shared storage.
4. Run platform checks, `artisan about`, configuration continuity checks,
   read-only database checks and internal smoke tests on green.
5. Confirm there are no pending migrations. Do not run a business migration as
   part of this release.
6. Send a small canary share of stateless HTTP traffic to green. Do not send
   payment callbacks until normal API and authentication metrics are healthy.
7. Observe HTTP 5xx, latency, authentication failures, Redis errors, database
   errors, queue depth and Octane worker health for the agreed canary window.
8. Increase traffic in controlled steps. Stop immediately on a rollback trigger.
9. Stop old Horizon supervisors from accepting new jobs and let active jobs
   finish. Start green Horizon and verify queue depth and failed-job counts.
10. Route new WebSocket connections to green while blue drains existing
    connections. Verify client reconnect behavior before terminating blue.
11. Transfer Scheduler ownership from blue to green exactly once.
12. Route all HTTP traffic to green, retain blue without traffic for the full
    rollback window, then terminate it only after final approval.

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

Production release remains a separate high-risk action. Approval must identify
the environment, Git commit, image digest, maintenance owner, observation
window, rollback owner, plugin inventory and database snapshot. Passing local
and CI gates prepares the artifact; it does not authorize deployment.
