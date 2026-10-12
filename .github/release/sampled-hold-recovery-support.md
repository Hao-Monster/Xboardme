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

`Image Inventory CI` uses a dedicated GitHub-hosted Docker daemon with no
production environment or credentials. Correctness uses 146 synthetic images
and 24 created, never-started containers and compares complete global snapshots.
The separately selected main benchmark uses full 120/300/10-second windows and
observer-only plus old/new/new/old ordering. It neither starts services nor
establishes production CPU headroom or real connection continuity. No broad
prune, force removal, volume deletion or unknown-resource cleanup is permitted.

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
