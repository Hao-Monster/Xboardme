# Lower-overhead startup revalidation for image-only cleanup

## Scope and observed failure

The user authorized clearing unused historical Xboardme resources while preserving
the running service and data. This change is cleanup support only: no application
deployment, database migration, container removal/restart, Caddy reload, cutover,
volume removal, broad prune, or resource-threshold relaxation.

Cleanup run 38360368858, main cdc78de2014d6e47df6a8397462dc5e4c0fe059c,
failed before deletion. Its 120-second baseline passed; after 19 accepted samples,
CPU PSI some avg10 reached 21.17 (limit 10). All 146 image and 24 container
snapshots were unchanged in the independent post-audit. This does not prove
interval-wide connection continuity or that all CPU load came from the audit.

Read-only gate audit 38362627257 subsequently confirmed the same failure session,
19 persisted samples, and explicitly null intent/removal/completion/post-evidence
fields. It did not reset HOLD or authorize a retry. Its raw operation SHA256 is
1f8830510527b03bb9fc2b827b82d0b507e3b1f23ef85c15ca763415b17bfc90.

## Implementation and unchanged boundaries

- The initial locked audit still performs the full conservative parser. Shared
  discovery has the same Compose metadata and historical-file selection rules.
- Repeated cleanup checks consume one fixed, byte-hash-bound copy of that initial
  manifest. They rediscover the complete input set and Compose metadata three
  times and hash every input twice. Newly created indirect dotenv inputs cannot
  evade comparison: any existing dependency omitted from the manifest fails.
- File count, per-file/aggregate size, canonical paths, symlinks, file identity,
  and nonzero dependency exits remain fail-closed. No mtime-only caching,
  permissive parser, automatic baseline replacement, or fallback is used.
- Each tool retains the existing five-second deadline and one-second kill grace.
  GNU tools already used by the workflow suffice; no runtime is installed.
- Executor phase markers identify live protection, startup revalidation, and
  inventory duration without changing deletion/monitoring decisions.
- The existing full inventory, all-container checks, immutable review/plan,
  fresh current main, restore verification, 120/300-second monitoring, resource
  limits, and independent post-audit remain required.

The bounded filesystem checks are not an atomic filesystem snapshot. Concurrent
growth can exceed the stat-observed byte budget until timeout; this existing
limitation is not described as zero I/O or zero connection risk.

## Test matrix and local evidence

| Risk | Evidence | Result |
| --- | --- | --- |
| Parser/discovery compatibility | Existing startup parser suite; same 137-file fixture against frozen C parser | 80 cases; byte-identical JSON |
| Content/metadata drift | New startup revalidation suite: same size/mtime, new/missing inputs, indirect env, metadata, NUL, oversize | Rejected |
| Path and race safety | Symlink/parent-link, spaces, changes during batch hash, manifest ABA | Rejected or preserved as required |
| Dependency failure propagation | Partial valid output then nonzero, including final stat/realpath | Rejected |
| Executor deletion boundary | Existing image-only executor suite | 46 cases PASS |
| Streamed transport and HOLD | Existing transport suite with native Linux Node | 38 cases PASS |
| Application regression | PHP 8.5.9/SQLite PHPUnit; JavaScript | 328 tests/4083 assertions; 136 tests PASS |
| Static/dependency checks | PHPStan; locked Composer audit; Bash syntax; workflow YAML; diff check | PASS |

New startup revalidation suite: 28 cases PASS with no production connections.
The initial test failed because the new helper was absent. A later final-command
exit-status regression reproduced as an unexpected success before the correction
and failed closed afterward. The independent review's manifest-consumption issue
was fixed using a captured payload and its actual byte hash, not by trusting
matching pathname hashes before and after separate reads.

In a single isolated WSL fixture with 137 files, the old scan invoked 1,376 tools;
the new check invoked 283, or 292 when conservatively counting synthetic metadata
reads as production tools. A <=350 call budget passed. Old user+system CPU was
6.525 seconds, new 1.460 seconds; wall time 16.749 versus 10.301 seconds. These
local synthetic measurements do not predict production PSI or replace host gates.

The first direct WSL transport test lacked native Linux Node and failed before
the intended evidence checks. Running with the already provisioned native Linux
Node 24.12.0 passed all 38 cases; no assertion or production behavior was relaxed.

Independent source review found and closed the manifest-consumption and final
dependency-exit issues. Remote required PR/main CI and actual cleanup acceptance
remain separate gates; this document does not claim any image was deleted.

## Prior recovery evidence compatibility

The failed C operation legitimately includes one `SAMPLED_RECOVERY_PROOF=PASS`
marker from its prior H recovery. The strict trace reader previously rejected
that marker. It now accepts it only after admission and before the base audit,
exactly once, with the complete original proof and canonical ten-field receipt
from that same failed-run artifact. The receipt binds C's workflow/run/review to
H's proof/source, with matching target, active release, protection and inventory
identity. Missing, substituted, duplicate, misplaced or malformed evidence is
rejected before any new receipt is written. No raw-log download or production
action was added.

The existing proof schema, six explicit null deletion/completion fields, sample
timing, resource limits and permitted failure cascade are unchanged. The first
two positive compatibility tests failed before the fix; final native Linux
proof/runner suites passed 181/181 (139 proof, 42 runner), exit 0, no skips.
An independent reviewer reran all 181 and found no known Critical/High/Medium
issue or weakened gate. Root separately reran the same final suites successfully.
Coverage was not collected.

The real C evidence also passed local historical verification with proof hash
2f9523ebf289f60e5652fa98b2b960fa98b6dccd1f753a96a990147542181a5a.
That is historical fact validation, not recovery or deletion authorization. A
new exact-main audit and reviewed immutable sequence remain required before a
new first cleanup; the failed old sequence cannot resume at its second item.
