# Independent production resource diagnostic — 2026-10-12

## Scope and authority

The failed image-cleanup run `38226064662` stopped at the resource baseline before
deleting the approved image. Its generic resource failure did not distinguish a
source/read/parse failure from a real resource-threshold violation. This change
adds a separate, bounded diagnostic to collect that missing evidence. It does
not authorize another cleanup attempt or an application release.

The operation is fixed to `resource_diagnostic`. Its only dispatch input is the
full `expected_sha`; callers cannot choose an action, command, path, proc source,
threshold, interval, sample count, image, release, or gate transition. The user
authorized normal PR/CI delivery and one diagnostic run, not an automatic retry.
`GITHUB_RUN_ATTEMPT` must equal `1`; the release owner must also verify that no
duplicate manual dispatch is made.

The diagnostic does not read or write the cleanup HOLD/gate, release state,
application data, databases, Docker objects, or Caddy configuration. It does not
run container commands, service commands, HTTP/authentication/WebSocket probes,
image/registry operations, cleanup, migration, deployment, or rollback. A passing
diagnostic is not permission to clear HOLD or resume cleanup/deployment.

## Protected entry and payload

- `.github/workflows/production-resource-diagnostic.yml` is manual-only, uses the
  protected `distributor-server` environment, has only `contents: read`, and
  shares `xboard-production-release` concurrency with `cancel-in-progress: false`.
- Admission requires the exact repository, `main` ref, workflow path, workflow
  SHA, checked-out SHA, current remote `origin/main`, a clean index/worktree
  including untracked files, and initialized/clean recursive submodules. It
  occurs before host-key access and again inside the transport before SSH.
- The runner reads only `.monitoring.resources` from the fixed committed
  `.github/release/image-cleanup-review.json`. It checks the schema and equality
  with the same file at the approved SHA. Cleanup approval status/expiry is not
  consulted, renewed, or modified by this diagnostic.
- The resource object must contain exactly the five numeric fields accepted by
  `_rc_resource_limits_valid`, with the stricter schema-3 caps enforced on both
  runner and host: busy CPU at most 85%, iowait at most 10%, available memory at
  least 131072 KiB, I/O full PSI avg10 at most 1, CPU some PSI avg10 at most 10.
  The actual values must remain those in the committed review, not substituted
  defaults or workflow inputs.
- The runner sources the source-only continuity module locally, but never sends
  the module itself. The payload is exactly the `declare -f` closure of
  `_rc_run`, `_rc_jq`, `_rc_resource_read`, `_rc_resource_error`,
  `_rc_resource_limits_valid`, `_rc_resource_sample`, followed by
  `diagnose-retention-resources.sh`. Lifecycle/HOLD functions are not transmitted.
- The remote command starts a clean environment with a fixed PATH/locale and
  receives only the exact workflow SHA and validated five-number JSON. SSH
  credentials remain on the runner. No remote script or temporary file is
  installed.

The only production resource inputs are the aggregate first CPU row in
`/proc/stat`, bounded `/proc/meminfo` content for `MemAvailable`, and bounded
`/proc/pressure/io` and `/proc/pressure/cpu` content for the required PSI values.
No environment variable or dispatch argument can redirect these sources.

## Fixed timing and failure semantics

There are at most **three samples**, with a fixed **10-second wait** between
samples. Calls run directly in the same shell so successful CPU baselines carry
forward. The first sample reports CPU percentages as `null`/`initial`; it must
not be described as an instantaneous measured CPU percentage. Subsequent
successful samples can report delta percentages. A rejected sample does not
advance the previous successful CPU baseline.

Resource-reader and jq commands each have the shared **3-second timeout**. The
fixed 10-second interval has a separate 12-second wait cap; failure of that wait
stops further sampling rather than creating a tight loop. The production remote
program has a **90-second hard timeout plus 2-second kill grace**, not the earlier
60-second design option. The runner SSH wrapper is bounded to 120 seconds plus
2 seconds, with connect timeout 10 seconds and bounded keepalives. The job has a
5-minute cap. There is no retry or follow-up operation.

Each resource sample emits either a structured `RETENTION_RESOURCE_SAMPLE` JSON
record, including thresholds and individual violations, or a
`RETENTION_RESOURCE_ERROR` with the source, category, and original command exit
code. Rejected samples do not suppress the remaining fixed samples. The final
builtin-only summary records attempted/passed/failed samples, failed waits, and
every observed failure category even if jq comparison failed. Any rejected or
incomplete sample set exits nonzero. SSH loss or the outer hard deadline may
leave partial records only; missing summary/completion evidence is not success.

The artifact step runs even on failure and retains available diagnostic logs for
90 days, keyed by SHA, run ID and attempt. All summaries explicitly state that
continuation is not authorized, connection continuity is unverified, and the
lifecycle gate was not accessed. Passive resource evidence cannot prove real
request success, long-connection continuity, or future production stability.

## Local test matrix and results

| Boundary or risk | Executable verification | Result |
| --- | --- | --- |
| Exact repo/ref/workflow/SHA; dirty/untracked/submodule state; stale or unavailable remote main; rerun denial | `bash .github/scripts/test-resource-diagnostic-request.sh` | PASS: 33 cases, zero production connections |
| Committed-only limits; wrong schema/extra fields/non-numeric/relaxed values; no cleanup freshness dependency | Same request suite | PASS; blocked/expired cleanup fixture does not become cleanup authority |
| Real function serialization and same-shell host execution; fixed proc command allowlist; initial/delta CPU; inherited-state isolation | `bash .github/scripts/test-resource-diagnostic-remote.sh` | PASS: 16 cases |
| Mixed threshold/parse/read errors; no failed-baseline advancement; comparison command failure; complete rejection counts | Same transport suite | PASS; third-sample 55% assertion distinguishes the retained successful baseline |
| Three actual per-read timeouts; wait failure; SSH loss; remote deadline; invalid requests before SSH | Same transport suite | PASS; actual GNU 3-second resource timeouts, no retry |
| No forbidden host commands, no lifecycle payload, unchanged existing HOLD sentinel | Same transport suite | PASS; zero production connections and registry access |
| Workflow input/permissions/concurrency/step ordering and closure boundaries | `php vendor/bin/phpunit tests/Feature/ResourceDiagnosticWorkflowTest.php` | PASS: 4 tests, 61 assertions, 0 failures, 0 skips; PHP 8.5.9 |

The shell suites ran locally in WSL Debian. They execute the production host
program and real serialized resource functions; the receiving host PATH/proc
command results and interval waits are isolated fixtures. The source-timeout
case uses real GNU timeouts. This is not a production load, connection, Docker,
or SSH-network acceptance test. The remote 90-second wrapper is structurally
checked and its failure is simulated; the test does not deliberately wait for a
90-second production timeout.

The main agent also reported these local regression results for the combined
change: full PHPUnit 326 tests/3808 assertions (144.996 seconds, PHP 8.5.9,
in-memory SQLite), JavaScript 136 tests, rename-browser 26 cases, PHPStan, and
Composer audit with no advisories: PASS. Actionlint 1.7.12 with shellcheck disabled
passed both changed workflows. These are local evidence; remote required CI
checks remain the release owner's delivery gate. The new diagnostic must not
change the existing cleanup execution or lifecycle protections.

The final main-agent rerun also passed the resource parser/comparison suite (77
cases), request suite (33), and real-closure transport suite (16), plus the
existing continuity (41), passive monitoring (84), image transport (10), image
executor (46), restore (15), and twelve other offline suites. The independent
security review reported no unresolved Critical/High finding in this scoped
change. Production safety remains bounded by the explicitly stated NOT RUN
items and the prohibition on follow-up lifecycle operations.

## NOT RUN and handoff boundary

At document preparation, no production diagnostic, new cleanup, application
deployment, registry access, or HOLD change had been performed by this task.
Real-Docker validation and the remote PHP/MySQL matrix remain CI evidence to be
collected by the main agent; local fixture success does not substitute for them.

After normal develop-to-main PR review/required CI, the release owner must
recheck the exact current main SHA and absence of another production operation,
then dispatch this workflow once using that SHA. Follow the run to its terminal
state, inspect each sample and the artifact, and report the observed cause or
remaining uncertainty. Do not use the result to trigger any cleanup/deployment
or to reset HOLD; those actions remain outside this diagnostic authorization.
