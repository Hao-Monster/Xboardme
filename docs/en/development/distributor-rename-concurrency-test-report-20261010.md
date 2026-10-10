# Distributor rename concurrency repair verification — 2026-10-10

## Scope and environment

Repair of the findings against `da90e8fc741a9ce16ffeebe76af3be6f8be9a875`, in the
primary checkout on `develop`. This report supersedes the concurrency assumptions
in the original feature report, not its historical execution record.

- Windows / PowerShell 7.6.5; PHP 8.5.9; PHPUnit 12.5.33; PHPStan 2.2.8.
- Node 24.12.0; npm 11.7.0; locked Playwright 1.62.1 / bundled Chromium 151.0.7922.34.
- Composer 2.10.2; Git Bash/sh 5.2.37; Go 1.25.13 / actionlint 1.7.11.
- PHP integration tests use the real application routes and isolated SQLite
  `:memory:` database. PHPUnit forces testing, array cache/session/mail and sync
  queue configuration. No application database migration was run outside tests.
- Browser tests load the actual companion script and, for native-editor tests,
  the real React/Radix bundle and CSS. API state is synthetic and intercepted;
  no production account or service is contacted. The two-page tests share only
  their per-test synthetic state.
- Local admin submodule checkout is the pre-existing `236b571`; the parent records
  `14dbae7`. The relevant compiled bundle is the same blob under a different file
  name. Fixtures resolve the manifest dynamically. The submodule was not changed.

## Fixes and verification matrix

| Risk / acceptance | Layer and executable case | Result |
| --- | --- | --- |
| Old native editor must not undo a rename or revive a revoked role when only remarks change | Two real React pages; actual form submit; exact `{id, remarks}` and final server identity assertions in `admin-distributor-rename-native.cjs` | PASS |
| Only the matching target's explicit role intent is appended | VM fetch/XHR x JSON/FormData/URLSearchParams; unchanged role, cancellation, toggle-back and conversion in `admin-distributor-rename-ui.test.js` | PASS |
| Asynchronous reads retain unsaved cancellation and email drafts | Real native checkbox/input and delayed reads | PASS |
| A committed email change must remove stale same-ID cache aliases | Failing VM regression, then real native name/role reads and remarks-only save | PASS |
| A timed-out command cannot overwrite a later successful rename | Browser delays the first server write beyond abort, closes/reopens the dialog, completes the second write, then releases the first and asserts 409/no rollback | PASS |
| Precondition is checked in the database write, including both delivery orders and ABA | Real SQLite route tests, SQL-before-execution competing write, model/legacy name ABA and role revoke/restore | PASS |
| Unknown write plus unchanged revision cannot unlock save | Browser timeout, HTTP 200 mismatch, 503 and read failure; explicit read-only recheck, exactly one write | PASS |
| Definite rejection/conflict preserves draft without false success | Browser 422, 409, same-name competing writer, explicit corrected retry with refreshed revision | PASS |
| Missing/invalid revision fails closed, null legacy revision works | API validation and browser detail responses; no legacy fallback | PASS |
| Guest/customer/distributor/staff and internal accounts cannot rename | Real admin route authorization and target-scope assertions | PASS |
| No unrelated mutations or chosen next revision | Full account/order/delivery/subscriber/session snapshots, visibility and audit checks | PASS |
| Names, credentials and renewals of old subscriptions stay stable | Existing naming API suite plus full PHP regression; 5 browser naming/QR/renewal cases | PASS |
| Additive migration does not rewrite old rows and supports re-entry | Isolated migration down/up/reapply with real SQLite; migration approved inventory | PASS |
| Browser coverage participates in the verification gate | Locked npm install, real bundled Chromium, both browser files in CI script, YAML and actionlint validation | PASS locally; remote CI NOT RUN |

## Red to green evidence

1. Request bridge before repair:
   `node --test tests/JavaScript/admin-distributor-rename-ui.test.js`
   — exit 1, 10 tests: 4 pass / 6 fail / 0 skip, 0.271 s. Ordinary remarks picked
   the first unrelated editor and appended stale identity. Final target:
   11 pass / 0 fail / 0 skip, 0.310 s, exit 0.
2. Real native stale-tab regressions before repair:
   `PLAYWRIGHT_CHANNEL=chrome node --test --test-timeout=20000 tests/Browser/admin-distributor-rename-native.cjs`
   — exit 1, 0 pass / 2 fail / 0 skip, 14.54 s. Both actual React submits contained
   unwanted `is_distributor:1, distributor_name:'QA Old Merchant'`.
   Final native suite: 5 pass / 0 fail / 0 skip, 18.202 s, exit 0.
   The extra three native cases were added after the first repair; no RED claimed.
3. Timeout UI before repair:
   `node --test --test-name-pattern="35-second" --test-timeout=15000 tests/Browser/admin-distributor-rename.cjs`
   — exit 1, 0 pass / 1 fail / 0 skip, about 9.234 s. The old UI treated an unchanged
   read as confirmation and unlocked save. The final browser suite verifies the
   lock, eventual read-only confirmation and the reversed-delivery CAS outcome.
4. Backend before implementation:
   `php vendor/bin/phpunit --do-not-cache-result tests/Feature/Distributor/DistributorRenameConcurrencyTest.php tests/Feature/Distributor/DistributorRenameMigrationTest.php`
   — exit 1, 13 tests, 12 failures + 1 error, 29 assertions, 13.283 s. Missing
   endpoint/revision/migration and remarks-derived identity were caught.
   Final independent target: 14 tests / 154 assertions, exit 0, 14.760 s.
5. Independent review discovered a new same-ID/email-cache alias issue.
   `node --test --test-name-pattern="saved email change" tests/JavaScript/admin-distributor-rename-ui.test.js`
   — exit 1, 1 failure, 0.241 s: the checkbox still reflected the stale role.
   After evicting old email aliases, all 11 target tests passed. Independent
   real-browser reproduction also turned green for the new name and revoked role.

The first complete PHP run had three expected inventory failures (315 tests /
3512 assertions, 135.852 s, exit 1): route fingerprint, migration inventory and
approved-migration list. These were updated for the single new admin route and
single additive migration, not removed or relaxed. Excluding the new migration
still reproduced the old 59-file digest. The focused compatibility suite then
passed 11 tests / 306 assertions before the complete rerun below.

## Final execution results

All test rows below have zero failures, errors and skips. Check-only rows use
`N/A` for test counts. Durations are test-reported unless marked wall time.

| Command / scope | Status / exit | Passing tests / assertions | Duration |
| --- | --- | --- | --- |
| `php vendor/bin/phpunit --do-not-cache-result` | PASS / 0 | 315 / 3517 | 146.421 s |
| `php vendor/bin/phpunit --do-not-cache-result tests/Feature/Distributor/DistributorRenameConcurrencyTest.php tests/Feature/Distributor/DistributorRenameMigrationTest.php tests/Feature/Distributor/DistributorSubscriptionNameTest.php` | PASS / 0 | 33 / 699 | 34.423 s |
| `php vendor/bin/phpunit --do-not-cache-result tests/Feature/LaravelUpgradeCompatibilityTest.php` | PASS / 0 | 11 / 306 | 1.416 s |
| `node --test tests/JavaScript/*.test.js` | PASS / 0 | 136 | 5.094 s |
| `npm run test:browser:distributor-rename` | PASS / 0 | 26 (21 dialog + 5 native) | 33.975 s |
| `node tests/Browser/distributor-subscription-name.cjs` | PASS / 0 | 5 scenarios | 9.528 s wall |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | PASS / 0 | N/A, no errors | 5.088 s wall |
| `composer audit --locked --no-interaction` | PASS / 0 | N/A, no advisories | 4.928 s wall |
| `npm audit --ignore-scripts --audit-level=low` | PASS / 0 | N/A, zero vulnerabilities | 11.085 s wall |
| `composer validate --no-check-publish` | PASS / 0 | N/A, existing constraint warnings only | 3.742 s wall |
| `bash -n` / `sh -n` deployment and hook scripts | PASS / 0 | N/A, 59 bash + 4 sh | 9.196 s wall |
| PowerShell `Parser::ParseFile` | PASS / 0 | N/A, 1 script, 0 parse errors | 0.283 s wall |
| `php -l` changed and deployment PHP | PASS / 0 | N/A, 14 files plus final compatibility test | 6.715 s plus unrecorded final-file timing |
| Symfony YAML `Yaml::parseFile` workflows | PASS / 0 | N/A, 5 files | 0.819 s wall |
| `go run github.com/rhysd/actionlint/cmd/actionlint@v1.7.11 -shellcheck= -pyflakes= -oneline` | PASS / 0 | N/A, 5 workflows | 3.189 s wall |
| `node --check public/assets/admin-distributor.js` | PASS / 0 | N/A | Timing not separately recorded |
| `git diff --check` | PASS / 0 | N/A | 0.521 s wall; repeated before commit |

`npm ci --ignore-scripts --no-fund`, matching Chromium installation and browser
launch were also executed successfully. Browser verification was repeated after
the cache-alias fix. The earlier two-suite run was 25/25 before adding that extra
native regression; the final count above is 26, not an inferred total.

No new production dependency: Playwright is a pinned development dependency.
There is no separate companion asset build; JS/CSS are served as source. Full
image building and remote CI were not performed. Composer's pre-existing exact
openspout and wildcard symfony/yaml/webmozart constraints remain unchanged.

## Independent review and remaining boundaries

- Backend independent review and real SQLite verification passed. Frontend
  independent review found the cache alias problem, reproduced it in a real
  browser, and confirmed the fix. No known unresolved Critical/High/Medium
  finding remains within the reviewed change; this is not a zero-risk guarantee.
- Line/branch coverage: NOT RUN. PHP has neither Xdebug nor PCOV; no numerical
  coverage gate is configured, and CI uses `coverage: none`. Passing test counts
  are not reported as coverage percentages.
- PHP 8.3/8.4 + actual MySQL 5.7/8.4 matrix, multi-connection contention/load:
  NOT RUN locally. Docker Desktop's Linux engine pipe is absent. MySQL grammar
  compilation and SQLite SQL interleaving are bounded evidence, not MySQL runtime
  or production-load acceptance. Existing CI matrix is retained.
- actionlint ran with shellcheck/pyflakes disabled. Shell syntax passed; deep
  shellcheck analysis is not claimed.
- The new nullable column must be migrated before enabling the new application
  code. MySQL 5.7 can rebuild/wait on locks for an added column; use the existing
  isolated-clone release rehearsal and approved migration workflow.
- Retain the added column during application rollback. Old code/old open admin
  tabs and direct SQL writers do not gain the new protections automatically.
  Retire old writers and reload admin assets. Explicit legacy `user/update`
  identity commands intentionally remain last-writer-wins, although upgraded
  Eloquent writers advance the revision. Ordinary profile saves are now minimal.
- NOT RUN: production migration, real merchant rename, deployed/public browser
  acceptance, deployment/rollback rehearsal, push or hosted CI execution.
- The original admin submodule pointer difference, `.codex-browser-fixtures/`
  and `subscription-redesign-demo.html` are preserved and excluded from the repair
  commit. No new branch, worktree, push, PR or deployment was created.
