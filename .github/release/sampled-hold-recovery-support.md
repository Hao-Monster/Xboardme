# Sampled pre-delete HOLD recovery support

This support change does not approve cleanup, regenerate old reviews, clear a
gate, retire containers or deploy application code. Old review `001` and its
successors must not be reused against a different HOLD. Fresh approvals and
their complete predecessor chain are a separate reviewed change.

## Admission contract

- The existing zero-sample pair `schema_version=1, mode=predelete_hold_v1`
  retains its original semantics. Only the exact new pair `schema_version=2,
  mode=predelete_sampled_hold_v2` enters sampled recovery.
- The v2 recovery object has the eight v1 keys plus `proof_sha256`. It binds one
  original gate, immutable plan, review, workflow, session and image. A new
  workflow and new review are required; neither alone grants recovery.
- The reviewed proof is canonical JSON with one LF, at most 16 KiB, stored as
  `.github/release/image-cleanup-recovery-proofs/<proof_sha256>.json`. Its two
  safe historical sources are hash-named files under
  `.github/release/image-cleanup-recovery-evidence/`. No arbitrary path is a
  workflow input. This support change supplies no approved production proof.
- Proof top-level keys are exactly `schema_version`, `mode`, `gate_sha256`,
  `plan_sha256`, `gate`, `source`, `audit`, `trace`. The complete 28-field gate
  preserves all original values; six post-intent fields must exist and be null.
  The original failure must be the specifically reviewed CPU-PSI failure
  before any delete intent. Other failures are not recoverable through v2.
- Trace claims contain only `sample_uptimes`, `baseline_after_samples` and a
  decimal-string `baseline_wall_nanoseconds`. Full source events, nanosecond
  timings, 120-second baseline, no gaps over 30 seconds and the exact downstream
  failure cascade are verified on the runner. A PASS in a local model is not
  sufficient evidence.

## Two independent verification boundaries

1. The exact-main runner admits a clean checkout and fresh review, then reads
   the exact failed run/attempt metadata and its uniquely named full artifact.
   It verifies raw hashes, complete inventory snapshots, original gate and
   plan byte reconstructions, source-aligned timeline and strict proof schema.
   Raw workflow logs are not downloaded; the previously reviewed alignment
   projection is hash-bound, and masked logs are never inventory evidence.
   Only a small proof and same-run receipt are published. Registry restoration
   checks remain separate and mandatory. Missing positive evidence blocks host
   cleanup; it never prevents a post-failure HOLD transition.
2. The host receives only the proof bytes, not Node, Python, tokens or raw
   artifacts. Under the existing deploy lock it installs an exact hash-derived
   root-owned 0600 file with create-only semantics. Under the gate lock it
   revalidates proof, gate/plan raw hashes and every original current-state
   check: Caddy upstream, bound runtime, all containers/images/aliases, active
   state, startup references, protection, ownership, monitoring and resources.
   It archives the original gate and atomically creates a new RUNNING session;
   the original immutable plan remains at its existing path. The old gate is
   not reset, rewritten as successful or promoted to COMPLETE.

The new session repeats the unchanged 120-second baseline and 300-second
post-delete observation with 10-second sampling. Incomplete, timed-out or
ambiguous results remain fail-closed. Partial archive/proof creation is never
cleaned up by force or interpreted as success.

## Image inventory optimization

Only image-only audit/cleanup use `images_only_all_snapshots`. The shared
`retention_all_snapshots`, container snapshot and single-image snapshot helpers
remain unchanged for other release paths. The wrapper preserves the complete
container Config/HostConfig hashes, reads images serially in groups of 16 using
a fixed seven-field template, then relists full IDs and rejects any mismatch.
No consumer/startup/protection check, threshold or observation window is removed.

The Bash worker has a bounded whole-process-group watchdog, bounded output and
strict protocol parsing; partial/late/nonzero/residual-group results are rejected.
This is a software timeout for fixed non-detaching tools, not a hard-real-time
guarantee under uninterruptible kernel work or host failure.

Failed snapshots additionally report a fixed phase enum and the worker's numeric
wait status. These two private scalar files are removed by the existing exact
temporary-file cleanup. Raw Docker/jq stderr, configuration, container IDs and
environment values are not diagnostic output. Missing, invalid, multiline,
oversized or NUL-bearing diagnostic values become `unavailable`; they never
change a failed snapshot into success. The original deadlines, output caps,
protocol validation and process-group cleanup remain unchanged. This enables a
single reviewed read-only gate audit to locate a real host inventory failure;
it neither retries that failure nor grants cleanup or HOLD-recovery authority.

`Image Inventory CI` uses a dedicated GitHub-hosted Docker daemon with no
production environment or credentials. Correctness uses 146 synthetic images
and 24 created, never-started containers and compares complete global snapshots.
The separately selected main benchmark uses full 120/300/10-second windows and
observer-only plus old/new/new/old ordering. It neither starts services nor
establishes production CPU headroom or real connection continuity. No broad
prune, force removal, volume deletion or unknown-resource cleanup is permitted.

### Same-SHA benchmark precheck isolation

The main-only benchmark opt-in runs the three original parser/watchdog,
supervisor self-test and CI-boundary commands, in that order, in the separate
`inventory-benchmark-prechecks` job. Both jobs use `ubuntu-24.04`, the exact
`${{ github.sha }}` checkout and recursive submodules, with persisted credentials
disabled and only `contents: read`. The benchmark has a hard `needs` dependency
and explicitly requires the precheck job's result to be `success`; failed,
cancelled or skipped prechecks cannot start measurement. Neither job receives
production credentials, services or a Docker override, and no renderer binary
is transferred between runners. Correctness-only and diagnostic entry points
are unchanged.

This changes one assurance boundary: the negative compatibility pre-tests run
on another runner of the same class, not on the measurement VM itself. Fixture
setup, correctness, runtime checks and supervision still run on the measurement
VM. Its original benchmark command, 70-minute job timeout, fixed preparation,
unchanged limits and tail admission points, five formal episodes and exact-owned
teardown are retained. There is no added wait, warm-up, cache flush or retry.
Runner startup or background pressure can still reject preparation or an episode.

A subsequent qualification is limited to one reviewed exact-main attempt 1 with
successful precheck and benchmark jobs and all original seven evidence files.
Success establishes only that run's CI qualification under the new scheduling
protocol; it neither proves the cause of earlier failures nor clears production
HOLD or authorizes cleanup or deployment. A failed or incomplete attempt remains
not admitted and must not trigger an automatic repeat.

### Fixed CI benchmark preparation

After fixture setup and correctness, benchmark mode runs one predeclared
60-second procfs-only preparation phase. Its seven absolute sample slots are
0/10/20/30/40/50/60 seconds; both actual start and completion must be within one
second after their slot. The sampling/validation phase has a 65-second software
deadline. There is no retry, eighth point, catch-up, extension or repeated phase.
Correctness-only mode does not enter preparation.

Preparation requires at least 2705 seconds left on the existing overall
deadline. After its evidence is published, formal entry rechecks that at least
2640 seconds remain; publication time is charged to that same overall deadline,
not the 65-second sampling envelope. The deadline is never reset for admission.
Clock, source, schema, finite/range and per-field CPU-counter checks fail closed.
Only the initial CPU delta may be null. PSI totals, Dirty/Writeback and optional
daemon counters are controlled diagnostic scalars, not additional load limits.

Threshold exceedances at 0/10/20 seconds remain visible as
`WARN_UNSTABLE_PREPARATION` in both `preparation.json` and the summary, even if
the subsequent samples or benchmark pass. All four preselected 30/40/50/60-second
samples must satisfy the unchanged `LIMITS` with valid source/CPU data. Their
actual start-time span is reported; four discrete points do not establish
continuous health for 30 seconds or identify what caused earlier pressure.
Invalid/late samples, tail exceedances, missing evidence or insufficient budget
leave `benchmark=NOT_RUN_PREPARATION_REJECTED`, with no formal observer or
benchmark snapshot started. The summary's `preparation_formal_entry` records the
post-publication gate separately from the sampling artifact.

Admitted formal episodes create five new observers with independent rows and
CPU history. Their existing limits, failure behavior, coverage checks, order and
120/300/10-second protocol remain unchanged. Benchmark run `38314674406` remains
FAIL on its observed first-sample I/O pressure; these diagnostics neither
attribute that failure to fixture creation nor retroactively turn it green.
A future successful run establishes only that run's CI conditions, not production
cleanup authority, available capacity or uninterrupted public service.

### One same-runner stage diagnostic

The separate `diagnostic` dispatch input selects an exact-main-only job and is
mutually exclusive with `benchmark`; selecting both fails without fixture work.
No production environment, secrets, id-token, services or Docker override is
available. The original correctness and benchmark harness paths are unchanged.
The correctness job additionally runs the offline diagnostic regression suite
after its original real-Docker correctness step, on that job's own runner.

The diagnostic coordinator records checkout/workflow SHA and exact source
hashes, then runs one fixed 60-second trace **before any project test or Docker
call**. It runs the three original parser/watchdog, supervisor self-test and
CI-boundary commands in the existing order, takes a second fixed trace, invokes
the unchanged guarded fixture setup/correctness, then takes a third fixed trace.
The fixture remains 146 images and 24 created-never-started containers. There
are no benchmark observers, benchmark preparation, snapshot episodes, added
waits, replacement points, retries, cache flushes or automatically chained runs.

Each trace reuses the preparation source validator but has independent CPU
history: seven slots at 0/10/20/30/40/50/60, actual start/end within one second,
and a 65-second sampling envelope. Every original LIMIT violation remains
visible; it is a diagnostic observation, not an admission failure in this
synthetic-only path. Source, type, clock, timing or globally regressing PSI
totals stop new workloads and retain partial evidence. Each of the five
workloads has mandatory before/after scalar readings and total-usec deltas.
Those intervals explicitly lack continuous ten-second sampling. Nonzero stall
increments are raw measurements, not a newly invented acceptance threshold.

All guard/identity reads, normal phases and pre-teardown evidence publication
share immutable `D=start+900s`, including post-write checks. The unchanged exact
owned teardown has its separate 300-second recovery allowance; resetting the
harness deadline cannot extend D. A further independent 60-second final window
covers all final trace/summary/receipt writes. The job timeout is 25 minutes.

Pre-tests keep the original bounded subprocess supervisor and add a CI-only
Linux subreaper boundary for their nested test sessions. It reads only its
own direct-child list, with bounded reads/counts and
PID/starttime validation plus identity-bound pidfd signals; lack of pidfd
support rejects the diagnostic. No global process scan or broad kill occurs.
For actual outer budget B, absolute work/recovery deadlines are calculated
before Python startup at outer-start+B-8 and outer-start+B-3. This reserves five
seconds for exact-owned reaping before the original supervisor's B-2 TERM and
B-1 KILL fallback. On first entering recovery its effective end is the earlier
of the absolute recovery deadline and recovery-start+5 seconds; early failures
cannot borrow unused work time, and repeated signals never renew it. Control
file preparation is also deducted from the outer supervisor's remaining budget.
An invalid new child identity fails the command but does not bypass bounded
recovery of other already identity-bound owned children. Nonleader-thread
descendants are recovered by killing their
known parent and repeatedly discovering newly adopted own children. It does not
inspect grandchildren still managed by a live intermediate parent or claim to
measure whole-tree concurrency; the 256 limit bounds simultaneously tracked
direct/adopted children. Normal
completion with residual descendants is failure. Cancellation latches and an
owned one-byte signal file preserve the recovery allowance, including the
Popen registration window; repeated signals cannot interrupt owned recovery.
SIGKILL, kernel stalls and runner loss are not hard-real-time guarantees.
The test fallback likewise records original PID/starttime, requires current
ownership by the test's own subreaper before and after pidfd binding, and never
signals a reused PID or another parent's process. A numeric PID alone is not
proof of test ownership or of a surviving original process.

Publication uses a separate bounded writer with atomic replacement and a
128-KiB per-file cap. The disk summary deliberately says
`PENDING_FINAL_PUBLICATION_STATUS`: it cannot certify its own completed write.
`completion.json` binds that summary's hash, but is provisional and must be
combined with the coordinator's zero exit and the successful terminal job.
A missing/late/failed receipt, nonzero exit or failed job is incomplete even
if the available traces contain a complete sampling sequence. Final console
status reports the measured final-window result. No raw command output,
command line, host path, environment or Docker inventory is published.

Only three complete source/timing-valid traces, all five complete workload
boundaries, globally nondecreasing PSI totals, valid D and successful exact
owned teardown permit stage classification. Otherwise the result remains
`UNRESOLVED_INVALID_OR_INCOMPLETE`. For exceeded booleans A/B/C corresponding to
before-tests/after-tests/after-fixture, the first true position maps to
`BASELINE_LIMIT_EXCEEDED`, `FIRST_EXCEEDANCE_AFTER_TESTS` or
`FIRST_EXCEEDANCE_AFTER_FIXTURE`; all false is
`UNRESOLVED_NO_SAMPLED_LIMIT_EXCEEDANCE`. Always retain the entire pattern,
per-LIMIT indices, raw valid rows, and all six intervals per trace.

The artifact is exclusively `DIAGNOSTIC_ONLY_NO_ADMISSION`, not the seven-file
benchmark artifact and not production health. Extra traces change runner age
and pressure history, so stage association is not process causality or an
exact replay of failed benchmarks `38314674406` / `38319726963`. Neither old
failure is rewritten. A subsequent proposal needs a new falsifiable fact and
the concrete decision it changes; this path does not authorize repeated runs,
production cleanup, deployment, or purchase of different infrastructure.

## Verification and remaining production gates

Run the pure Node proof tests, real Linux runner/receipt tests (local gh
replacement), sampled host tests, unchanged v1 tests, continuity and transport
regression, inventory parser/watchdog tests, normal PHP/JS/static/security CI,
and dedicated real-Docker correctness. A new reviewed proof and fresh allowlist
are still required before any protected production recovery. Local fixtures do
not establish real service continuity; passive production samples cannot prove
that every request or long-lived connection was uninterrupted.

### Local support-code evidence (2026-10-13)

The synthetic proof and actual Linux runner suites passed 56 and 25 cases;
the sampled-host, existing v1 recovery, continuity, transport and startup-reference
suites passed 139, 113, 79, 38 and 80 cases respectively. The independent reviewer
also exercised the complete historical evidence (152 cases) and actual Linux CLI
filesystem/receipt boundary (32 cases). Two Medium findings were fixed with
Red-to-Green coverage: UTF-8 BOM normalization and missing/invalid audit scalars.

The complete application regression passed 328 PHPUnit tests / 4063 assertions
and 136 JavaScript tests. PHPStan, Composer audit, workflow validation and diff
whitespace checks passed. These local results do not replace the protected PR
checks, real-Docker correctness, comparative benchmark or production observation.
The first temporary aggregate runner lacked the Go test renderer and two Compose
fixture files; its overall exit was nonzero. The missing Compose fixtures were
restored in an isolated test copy and the 80-case suite passed without modifying
its assertions. Inventory validation and its real-Docker gate are tracked
separately, not counted as passing by that aggregate runner. Final inventory
verification passed 42 direct Bash cases, 57 Python CI-boundary cases and five
CI-supervisor self-tests. The audit/cleanup consumer suites passed 19 and 46
cases; all 103 deployment shell scripts passed syntax checks.

Cancellation tests found and fixed a signal-reentry race: a second TERM could
exit the supervisor before its EXIT cleanup installed signal ignores, leaving
the separate metadata process group running. Regression tests cover that exact
window, pending signals during PID registration, the whole-call deadline and
independent checks of both process groups. These are local synthetic process
tests, not a claim that production cleanup has run.
