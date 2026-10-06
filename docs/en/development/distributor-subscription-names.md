# Distributor subscription names

New distributor subscriptions use `merchant-YYMMDD-CODE`, for example
`GZXBL小北Mustafa-261006-A7K9Q2`, without an `订单号：` prefix.

- Merchant names are trimmed and limited to 16 UTF-16 code units, matching
  browser `maxlength` and Dart clients. Control characters and email addresses
  are rejected; stored historical merchant names are never silently truncated.
- The date is the original purchase day in `Asia/Shanghai`. The six-character
  code uses uppercase letters and digits excluding `0`, `1`, `I` and `O`.
  A database unique index enforces uniqueness, with at most ten collision retries.
- The complete title and code belong to the distributor subscription. They are
  stored once at new purchase, so renewal and merchant
  account renaming do not change an existing subscription's identity.
- Transaction `trade_no`, subscription token/UUID, HWID authorization, financial
  records and original timestamps are preserved. Short codes are lookup labels,
  never authentication credentials.

The response `profile-title`, UTF-8 `Content-Disposition` filename and subscription
URL fragment share the stored title. The ASCII filename fallback uses the short
code; configuration extensions are retained. `x-order-no` keeps the original full
order number. Lists, QR delivery, search and spreadsheet exports expose the new
name/code alongside the original transaction number.

Existing subscriptions retain their original format. Their new metadata columns
remain null: `profile-title` stays `订单号：<trade_no>`, the URL fragment stays the
original trade number, and lists and QR images retain the legacy display. Reading,
exporting or renewing an old subscription never assigns a new name. Renewal orders
inherit the existing subscription identity; only a new subscription purchase
receives the new format. There is no date cutoff or background backfill.

## Migration and rollback

`2026_10_06_000001_add_distributor_subscription_names` adds nullable columns and a
unique index without filling historical rows. Orders created by the old application
during preparation also retain their original format. The new application assigns
identity only inside the new-subscription purchase transaction, never from read or
export paths. The allocation method rejects persisted legacy models. Each new
subscription is locked while allocating its identity; only the two new columns are updated.
Migration retry repairs independently missing columns/indexes, including after
non-transactional MySQL DDL interruption.

The isolated database-clone rehearsal must include this additive migration.
Historical merchant names are not validated or changed by migration or legacy
subscription reads. Merchant-name validation applies when creating new subscriptions.

Application rollback can retain the additive columns and generated identities.
Do not run the destructive migration `down()` during ordinary application rollback:
dropping the new columns loses the assigned public identifiers. The down migration
exists for clean test rollback and does not delete orders or subscription users.

## Verification

See the [test plan and task list](distributor-subscription-names-test-plan.md)
and [local test report](distributor-subscription-names-test-report-20261006.md)
for acceptance cases, execution evidence and remaining environment checks.
The [release validation addendum](distributor-subscription-names-release-validation.md)
records the MySQL test-lifecycle fix and served-resource browser failure checks.

Run `php vendor/bin/phpunit --filter DistributorSubscriptionName tests/Feature/Distributor`
and the existing distributor suite. Migration cases use a separate, non-transactional
database lifecycle because MySQL DDL commits implicitly; regular purchase and read
tests retain transaction isolation. The tests cover HTTP headers for Karing,
FlClash and Clash Verge user agents, stable renewals, unchanged legacy records, UTC/Shanghai
date boundaries, name validation, real SQLite unique constraints, forced collision
retry/exhaustion, data isolation and transaction rollback.

Run the JavaScript suite, full PHPUnit suite, PHPStan, Composer audit and release
checks required by `AGENTS.md`. SQLite results do not replace the CI MySQL matrix.
Client response tests do not prove UI import behavior: verify new imports in the
actual supported client versions. Existing client-local names and previously
distributed QR images do not necessarily rename automatically; an old URL fragment
may retain its original label. Existing subscriptions remain valid.
