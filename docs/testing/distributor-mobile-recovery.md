# Distributor mobile recovery regression plan

Follow-up review repairs and current local results (2026-10-22) are tracked in
[distributor-review-fix-checklist.md](distributor-review-fix-checklist.md).
The execution results dated 2026-10-21 below are the earlier baseline, not the
latest totals.

Scope: retain the pending distributor/session and database optimizations. Repair
mobile recovery and logout locally, without deployment or production API writes.
Tests use synthetic accounts and mocked order/logout endpoints.

## Acceptance matrix (before implementation)

| Case | Fault / action | Required result |
| --- | --- | --- |
| Initial / resumed read | `/user/info` or `/user/plan/fetch` fails transiently, then succeeds | Initial request plus at most one retry after 400 ms; render the catalog without manual retry. |
| Read budget | Response headers/body or retry is delayed | The first attempt, delay and retry share the original 12-second deadline. An exhausted deadline does not start another attempt. |
| Persistent failure | Both attempts fail | One recoverable Chinese message; retain the session; no detector request loop. Manual retry or a new visible/online recovery can start a new round. |
| Non-transient failure | 400, 401, permission 403, cancellation or old session | No automatic retry. Only confirmed current-session authentication failures clear login. |
| Background failure | A read fails while hidden; pageshow/visible/online arrive together | Do not paint a background error; coalesce recovery, preserve normal content, ignore cancelled results. |
| Existing content | Silent identity verification fails on resume | Keep the current catalog usable; do not replace it with an error page. |
| Frontend ownership | Confirmed distributor opens root, plan, orders or another distributor menu | Distributor UI owns the route. Hidden main-site business components do not mount or issue duplicate business requests. Login and normal-user routing remain functional. |
| Error ownership | Initialization / route reads fail concurrently | The owning recovery UI reports the error; no repeated generic main-site Toasts. Authentication, permission and write errors remain observable. |
| Logout | Normal/error page, pending reads, server logout success/500/network failure | Clear distributor UI, local token and timers; remain at login; old callbacks cannot return to plan. Server revocation failure is not represented as server success. |
| Slow write | Order POST remains pending beyond 12 seconds, with resume / read retry / repeated taps | Exactly one order POST; no timeout/cancellation/retry of that write; its lock survives UI recovery. |
| Unconfirmed write | Order may have committed but its response is lost / 5xx / invalid | Explain that the result is unconfirmed and provide a read-only order-check action. Do not automatically resend or unlock purchasing merely because an order lookup is empty. |
| Session change | Old read/write/QR completion arrives after a new login | Do not modify the new account, lock, modal or token. Existing regression tests stay green. |

## Validation

First run the added tests against the existing worktree and record failures.
Then run the targeted JavaScript and isolated Chromium tests after repair. The
browser loads the real compiled application, auth and distributor scripts while
all business APIs are mocked; mobile viewport and synthetic lifecycle events do
not constitute a real-phone or true BFCache acceptance test.

Run the repository-required JavaScript/PHP suites, PHPStan, Composer audit,
deployment script syntax checks, workflow validation and `git diff --check` once
the implementation is stable. No test may touch live orders, tokens or logout.

Actual phone lock/background/overnight recovery, real network switching, served
candidate resource identity and post-deployment checks remain separate acceptance
steps. Existing live tabs must load the new assets when no write is in flight;
never force a reload during an unconfirmed order submission.

## Local execution results (2026-10-21)

The added cases were run against the retained pre-change assets before repair.
The initial browser batch had 7 failures out of 11 cases; the role-cycle case was
added during review. Subsequent review also reproduced cancelled search messages
and delivery-modal supersession releasing the purchase lock; both have permanent
regressions and are fixed.

| Check | Result |
| --- | --- |
| JavaScript full suite | 194/194 pass (61 auth/session/mobile cases including parameterized cases). |
| Full-asset mobile Chromium regression | 12/12 pass; current source digests matched the tested fixture assets. |
| Existing admin browser regressions | 26/26 pass. |
| Complete PHPUnit suite | 368 tests, 4772 assertions pass (local PHP 8.5.9, forced in-memory SQLite). |
| PHPStan | No errors. |
| Composer locked audit | No vulnerability advisories. |
| Deployment shell syntax | 112 files pass. |
| CI scope regression | 9/9 pass. |
| Workflow validation | actionlint 1.7.12 pass; external shellcheck/pyflakes integrations disabled. |
| Local theme manifest | Seven asset digests/template markers verified using the checker's `local` mode; no release SHA or image was produced. |
| Working diff | `git diff --check` passes. Previous backend optimization and unrelated admin submodule differences are unchanged. |

The browser regression is available as `npm run test:browser:distributor-mobile`
and is wired into the existing UI browser gate. Evidence is retained locally in
`C:/Temp/xboard-mobile-recovery-green-final/`; the final full JavaScript TAP is
`C:/Temp/xboard-mobile-final-js-tests.tap`.

The new-purchase safety lock protects the current page's pending or unconfirmed
operation. It is not a claim of backend exactly-once processing across a fresh
browser page, another tab or a new explicitly acknowledged purchase. No order
POST is automatically retried, and an empty order lookup never releases the lock
by itself.

Actual phone background/lock/overnight recovery, real BFCache restoration, the
production PHP platform, immutable candidate image/resource checks and public
post-deployment acceptance have not been run in this local task. Nothing was
committed, pushed or deployed.
