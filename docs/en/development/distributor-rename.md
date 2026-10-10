# Administrator distributor rename

## Scope and implementation plan

- Work in the primary checkout on the existing `develop` branch; no new branch,
  worktree, production writes, push, or deployment.
- Add an explicit **编辑名称** action to existing distributors in the distributor
  account list and search result. Keep the native user editor's saved name
  read-only; do not rename by revoking/regranting the distributor role.
- Use the administrator-only `POST /user/distributor/rename` with exactly
  `{id, distributor_name, expected_distributor_revision}`. Read the nullable UUID
  revision from the detail endpoint and compare it in the atomic SQL UPDATE.
  Isolate internal requests from the native form bridge. Native profile saves
  append identity fields only for an explicit role change on the matching ID.
- Read the current user before editing, validate using the existing 16 UTF-16
  unit rule, trim whitespace, reject empty/control/email-shaped names, and
  prevent duplicate submissions. No new uniqueness requirement.
- Read back after a write (including an ambiguous network failure). Only report
  success when the target is still a distributor, the stored name matches and
  the revision advanced, with no definite rejection. An unchanged revision after
  an uncertain write keeps saving locked; offer a read-only recheck. A conflict
  preserves the draft and requires an explicit retry with the newly read revision.
  Never automatically retry a write. Bound each rename request to 35 seconds.
- Update cached names and relevant displays after a verified read. Existing
  subscription names/codes, trade numbers, credentials, balances, roles,
  sessions, and plan visibility remain unchanged. The order's live distributor
  label may change; new purchases use the new name, renewals retain their title.
- Stamp native fetch/XHR user reads with the identity revision at request start;
  responses started before a verified rename cannot restore the old name/role.
  Later fresh reads still apply other administrators' changes. Preserve each
  response's money units and unrelated fields rather than replacing cached users
  wholesale with the detail response.
- Add nullable `v2_user.distributor_revision` without a data backfill. Model
  identity updates rotate it in the same write; ordinary profile saves do not.
  Existing explicit identity writes through `user/update` retain last-writer
  behavior. No permission, payment or order changes; no claim of eliminating
  SQLite writer contention. See [the concurrency repair plan](distributor-rename-concurrency-fix.md)
  for the revised regression matrix and release boundaries.

## Acceptance and test matrix (before implementation)

| Risk / acceptance | Layer / case | Execution |
| --- | --- | --- |
| Only the requested name changes; session, visibility and credentials survive | Real SQLite + real admin route; minimal payload and audit assertions | `php vendor/bin/phpunit --do-not-cache-result --filter DistributorSubscriptionName tests/Feature/Distributor` |
| Guest/customer/distributor/staff cannot rename; invalid input is rejected | API authorization and name boundary tests | Same PHPUnit target |
| Old subscriptions/renewals stay stable; new purchases use new name | API rename followed by renewal/new purchase, order label assertions | Same PHPUnit target |
| Native request bridge cannot contaminate targeted update | VM executes actual JS with another user's injected editor present | `node --test tests/JavaScript/admin-distributor-rename-ui.test.js` |
| Late fetch/XHR responses cannot undo rename; future updates and money units remain correct | VM cache checks and Chrome delayed-response profile saves | JS and browser targets |
| Both entry points, trim/validation, cancel/no-op, duplicate submit | Chrome with isolated synthetic API responses | `node --test tests/Browser/admin-distributor-rename.cjs` |
| Error, expired auth, ambiguous write, failed readback, role change | Chrome failure injection; no false success or automatic write retries | Same browser target |
| Escaping, desktop/mobile, keyboard/focus and cached editor consistency | Chrome at 390/1440px with console/overflow checks | Same browser target |
| Existing behavior | Complete PHPUnit/JS, PHPStan, Composer audit, shell/YAML checks, diff whitespace | Repository CI-equivalent checks |

Browser fixtures are synthetic, not production acceptance. SQLite evidence does
not substitute for the CI PHP 8.3/8.4 and MySQL 5.7/8.4 matrix. Source JS/CSS are
served directly; Playwright is a locked test-only dependency, not a new asset
build. Run `npm ci --ignore-scripts`, `npx --no-install playwright install chromium`,
then `npm run test:browser:distributor-rename`. CI runs both the focused dialog and
real native-admin suites. `PLAYWRIGHT_CHANNEL=chrome` optionally selects a local
Chrome. Roll application code back while retaining the additive column; do not
drop it while new writers run. Old assets/writers must be retired for full protection.

Execution results, corrections, review and remaining release checks are recorded
in [the original test report](distributor-rename-test-report-20261010.md) and
[the concurrency repair report](distributor-rename-concurrency-test-report-20261010.md).
