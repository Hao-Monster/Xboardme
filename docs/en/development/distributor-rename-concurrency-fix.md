# Distributor rename concurrency repair

## Confirmed scope and design

Repair the two independently reproduced failures from `da90e8f` on the existing
`develop` branch. No new branch, production writes, push, or deployment.

1. Native user edits send distributor identity only when the user actually
   changes the role. A remarks-only save must not contain the cached name/role.
   Bind injected controls to the immutable target ID, preserve explicit edits
   across asynchronous reads, and retain creation/role-conversion validation.
2. Add administrator-only `POST /user/distributor/rename` using only the
   relevant command fields for its write: `id`, `distributor_name`, and
   `expected_distributor_revision`. The existing detail endpoint supplies the
   revision. Missing revisions fail closed; no unprotected API fallback.
3. Add nullable UUID `v2_user.distributor_revision`. Existing rows use `null` as
   their initial revision; no name/data backfill. A single conditional UPDATE
   matches ID, active distributor status and revision, then writes the name and
   a fresh UUID together. Stale writes return 409, including after name ABA.
   Normal Eloquent identity changes also replace the revision in the same write,
   so legacy explicit name/role changes invalidate outstanding rename commands.
4. After timeout/network/server uncertainty, readback of the unchanged revision
   does not prove completion: keep the current dialog write-locked and offer a
   read-only recheck. Closing/reopening is allowed; the server CAS still prevents
   a pending older command from overwriting a later successful rename.
5. Pin a test-only Playwright dependency and run both focused dialog and real
   native-admin browser regressions in the existing CI verification gate.

Only name/revision/normal update timestamp may change on rename. Accounts,
balances, sessions, permissions, existing orders/subscription identities and
renewals retain their prior behavior. Explicit legacy `user/update` identity
commands retain compatibility and last-writer semantics; they are not silently
upgraded to CAS. The new UI uses the protected rename endpoint exclusively.

## Frozen regression matrix

| Risk / requirement | Executable evidence | Expected |
| --- | --- | --- |
| A stale native editor only saves remarks after another page renames/revokes role | Real admin React/CSS, two pages, actual submit payload | No identity fields; newer identity survives |
| Unchanged/toggled-back role, explicit conversion/revocation, wrong target ID | VM fetch/XHR + JSON/FormData/URLSearchParams | Only intentional matching fields are appended |
| Late reads do not erase unsaved role intent | VM / Chrome cached-form interactions | Draft remains; ordinary saves stay minimal |
| Saved email changes do not leave an older cache alias for the same ID | VM + real native-admin detail reads | Latest name and role replace the old alias |
| First request times out, second succeeds, first later commits | Chrome independently delayed synthetic server write + real SQL CAS API tests | Late command conflicts; final identity remains second |
| Old revision after name ABA or legacy role/name changes | Real SQLite admin routes and model writes | 409 and unchanged current identity |
| Unknown result and unchanged revision, read failure, definite rejection | Chrome failure injection | Recheck only for unknown; no blind write retry |
| Missing/invalid revision, role revoked, unauthorized/internal target | Real API + browser failure handling | Fail closed without unrelated mutations |
| Name boundaries, audit, sessions, money, historical subscriptions | Real SQLite route and existing naming suite | Prior invariants preserved |
| Nullable additive migration, rollback/reapply | Isolated migration test | Old rows retained; revision lifecycle valid |
| CI regression availability | Locked npm install, bundled Chromium, workflow static check | Both browser suites participate in verify gate |

## Release and rollback boundaries

The new nullable column is additive and must be deployed by the existing approved
migration/preflight/rehearsal workflow. Old code can run with the column present;
prefer retaining the column when rolling application code back. Old code does
not advance revisions, so CAS protection cannot be promised during a mixed
old/new writer rollout. Retire old writers and require administrator page reload
before accepting the new behavior. No migration or release is executed here.

Local SQLite and synthetic browser ordering are bounded evidence, not production
load validation. Record PHP/MySQL matrix and public-runtime acceptance separately.

The implementation and independent-review results are recorded in
[the repair test report](distributor-rename-concurrency-test-report-20261010.md).
