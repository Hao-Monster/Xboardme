# Distributor rename verification — 2026-10-10 (Asia/Singapore)

Scope: local implementation on existing `develop`, starting at `17bad07`.
No new branch, migration, production request, remote push, or deployment.
The pre-existing admin submodule checkout mismatch and five prototype HTML files
are excluded from this change.

## Environment and execution

Windows, PHP 8.5.9, PHPUnit 12.5.33, PHPStan 2.2.8, Node 24.12.0,
Chrome 155, Playwright 1.62.1, Composer 2.10.2. PHPUnit forces SQLite `:memory:`,
array cache/session/mail and testing configuration; no cached app configuration.
Browser requests are intercepted synthetic fixtures, not the production service.

| Command / check | Result / exit | Passed / failed / skipped | Duration |
| --- | --- | --- | --- |
| `php vendor/bin/phpunit --do-not-cache-result --filter DistributorSubscriptionName tests/Feature/Distributor` | PASS / 0 | 22 / 0 / 0; 618 assertions | 13.859 s |
| `php vendor/bin/phpunit --do-not-cache-result` | PASS / 0 | 301 / 0 / 0; 3361 assertions | 77.564 s |
| `node --test tests/JavaScript/admin-distributor-rename-ui.test.js tests/JavaScript/admin-distributor-ui.test.js` | PASS / 0 | 7 / 0 / 0 | 0.355 s |
| `node --test <all tests/JavaScript/*.test.js paths>` (PowerShell expanded) | PASS / 0 | 129 / 0 / 0 | 4.063 s |
| `node --test --test-timeout=20000 tests/Browser/admin-distributor-rename.cjs` | PASS / 0 | 14 / 0 / 0 | 11.789 s |
| `node tests/Browser/distributor-subscription-name.cjs` | PASS / 0 | 5 / 0 / 0 | 7.589 s (command wall time) |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | PASS / 0 | No errors | Not separately timed |
| `composer validate --no-check-publish`; `composer audit --locked --no-interaction` | PASS / 0 | No security advisories; existing constraint warnings | 6.494 s combined |
| Git Bash `bash -n` on 56 `.github/scripts/*.sh` plus `deploy.sh`, `init.sh`, `update.sh`; `sh -n` on 4 `.docker` scripts | PASS / 0 | 63 syntax checks | Not separately timed |
| `php -l` on 6 `.github/scripts/*.php` | PASS / 0 | 6 syntax checks | Not separately timed |
| Symfony YAML `Yaml::parseFile` on `.github/workflows/*.{yml,yaml}` | PASS / 0 | 5 workflows | Not separately timed |
| `node --check public/assets/admin-distributor.js`; `git diff --check` | PASS / 0 | No syntax/whitespace errors | Not separately timed |
| `php .github/scripts/build-theme-asset-manifest.php local <temporary manifest>`; `php .github/scripts/verify-theme-assets.php local <temporary manifest>` | PASS / 0 | 7 theme assets; no repository manifest written | 0.66 s including 3 root shell syntax checks |

Browser runtime discovery (no project dependency added):

```powershell
$env:NODE_PATH = 'C:\Users\冯飏\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
node --test --test-timeout=20000 tests/Browser/admin-distributor-rename.cjs
```

## Acceptance matrix and observed results

| Requirement / risk | Evidence | Result |
| --- | --- | --- |
| Exact minimal request, even with another visible user editor | VM and Chrome compare actual JSON keys and values | PASS |
| List and search entry, trim, 16 UTF-16 units, controls/email rejected | JS unit, Chrome interactions, real API boundary tests | PASS |
| Cancel/unchanged input performs no write | Chrome counts requests | PASS |
| Duplicate submit and close during pending write | Chrome delayed response, duplicate form submissions, Escape and disabled controls | PASS |
| Reject/mismatch/role change does not report success | Chrome injected 422 / successful response with mismatching state | PASS |
| Lost response can be resolved without resubmitting | Chrome aborts response after fixture commit, verifies one POST | PASS |
| Unverifiable state blocks save; recheck is read-only | Chrome failed GET then recovered GET; one total POST | PASS |
| 35-second request timeout and expired auth | Chrome controlled clock and local token removal | PASS |
| Cached native name cannot silently restore old value | Chrome subsequent ordinary native update sends new cached name | PASS |
| Late pre-rename native fetch/XHR responses cannot restore the old name | Chrome delays each response until after confirmed rename, then asserts actual profile update JSON | PASS |
| Detail response cents must not replace native-list yuan fields; later identity changes remain visible | Distinct fixture API shapes and VM balance/commission/role assertions | PASS |
| No unrelated account/order/subscriber/session/visibility mutation | Real SQLite rows compared before and after minimal admin API call | PASS |
| Guest/customer/distributor/staff denied | Actual admin route and middleware; 403 and unchanged name | PASS |
| Audit contains only ID and name | Stored admin audit request data asserted | PASS |
| Old title/code/token stays stable through renewal; new purchase has new prefix | Actual API rename, renewal API, new purchase and stored subscription assertions | PASS |
| Order live merchant label changes, stable subscription title does not | Actual admin order-detail API assertions | PASS |
| Escaping, keyboard focus loop/restoration, mobile/desktop layout | Chrome 390/1440px, no overflow or unexpected console/page errors; screenshots visually inspected | PASS |

Screenshots and local asset manifest evidence:
`C:\Temp\xboard-distributor-rename-817bfe31e5fb45a08f5cb1431c117ebf`.

## Red → Green and corrections

- Before implementation, the new JS target failed 2/3 cases: no rename entry,
  and the native request bridge changed a targeted rename into an unrelated
  role/name payload. After implementation, all three pass; existing JS tests
  remain unchanged and pass.
- The first new API snapshot assertion compared a freshly inserted in-memory
  model with a database-hydrated model, falsely detecting default/type/order
  differences. Both snapshots now read persisted rows; no assertion was removed.
  The API suite then passed 22/22. No backend business-code change was needed.
- Browser bring-up corrected the Playwright assertion-module import and made
  the native-editor fixture match its real nested form structure. A subsequent
  browser run exposed missing explicit Shift+Tab cycling; the dialog now keeps
  keyboard focus within its enabled controls.
- Independent review found a late-user-response race. New JS and Chrome tests
  first failed by sending the old merchant name on a subsequent ordinary profile
  save. Request-start identity revisions now protect verified names from stale
  fetch/XHR responses while allowing fresh updates. The test fixtures also now
  distinguish list yuan values from detail cents and limited option fields.
  Final complete run: 14/14 browser cases, 129/129 JS cases.
- Independent follow-up review confirmed both findings resolved and found no
  additional High/Critical or blocking issue. The reviewer did not run tests;
  execution evidence above is from the implementation run.

## Limits and remaining release verification

- NOT RUN: PHP 8.3/8.4 and MySQL 5.7/8.4 CI matrix. This local run used PHP 8.5
  and SQLite; Docker daemon is unavailable. No backend/schema logic changed.
- NOT RUN: `actionlint` (not installed). YAML parsing is not full Actions lint.
- NOT RUN: authenticated live SPA/production acceptance, real merchant rename,
  production load/concurrency test, image build, push, PR and release workflow.
  Browser fixtures do not prove an already-deployed runtime contains the feature.
  They load the enhancement JS/CSS and a simplified native editor, not the full
  admin React/Radix bundle and stylesheet; complete native focus/style integration
  remains an environment acceptance check.
- Line and branch coverage: not measured. No PHP Xdebug/PCOV driver or repository
  coverage gate is configured; pass counts are not a coverage percentage.
- Existing Composer warnings: exact `openspout/openspout` version and unconstrained
  `symfony/yaml` / `webmozart/assert`; dependency files were not changed.
- A later write by another administrator can still supersede the saved name;
  the existing API has no compare-and-swap contract. There is no automatic write
  retry or bulk rename, and no claim of zero SQLite contention during peak orders.
