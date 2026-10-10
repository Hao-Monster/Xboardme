# Administrator distributor rename

## Scope and implementation plan

- Work in the primary checkout on the existing `develop` branch; no new branch,
  worktree, production writes, push, or deployment.
- Add an explicit **编辑名称** action to existing distributors in the distributor
  account list and search result. Keep the native user editor's saved name
  read-only; do not rename by revoking/regranting the distributor role.
- Reuse the administrator-only user update API with exactly
  `{id, distributor_name}`. Isolate internal API requests from the native form
  request bridge so another open user editor cannot overwrite the payload.
- Read the current user before editing, validate using the existing 16 UTF-16
  unit rule, trim whitespace, reject empty/control/email-shaped names, and
  prevent duplicate submissions. No new uniqueness requirement.
- Read back after a write (including an ambiguous network failure). Only report
  success when the target is still a distributor and the stored name matches.
  If verification fails, disable saving and offer a read-only recheck. Never
  automatically retry a write. Bound each rename request to 35 seconds.
- Update cached names and relevant displays after a verified read. Existing
  subscription names/codes, trade numbers, credentials, balances, roles,
  sessions, and plan visibility remain unchanged. The order's live distributor
  label may change; new purchases use the new name, renewals retain their title.
- Stamp native fetch/XHR user reads with the identity revision at request start;
  responses started before a verified rename cannot restore the old name/role.
  Later fresh reads still apply other administrators' changes. Preserve each
  response's money units and unrelated fields rather than replacing cached users
  wholesale with the detail response.
- No backend schema, permission, payment, or order logic changes are planned.
  The existing API's concurrent-admin last-writer behavior is unchanged; this
  UI does not claim to eliminate SQLite writer contention during peak traffic.

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
served directly; there is no new frontend build dependency. Rollback is a normal
revert of this change, with no database rollback or name rewrite.

Execution results, corrections, review and remaining release checks are recorded
in [the test report](distributor-rename-test-report-20261010.md).
