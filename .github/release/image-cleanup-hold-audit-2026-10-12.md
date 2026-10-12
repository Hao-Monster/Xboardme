# Read-only evidence for a sampled cleanup HOLD

This operations-only change does not approve a new cleanup attempt or an
application deployment. It changes neither recovery policy nor approved image
manifests, resource limits, sampling intervals or observation windows.

## Reason and scope

Cleanup run 38265050808 at main
`354608ffd90e1febe51f36c3063d3a71816e4f76` stopped after its 120-second baseline:
CPU PSI some avg10 was 20.34, above the fixed limit 10. Its complete independent
post-audit retained all 24 container and 146 image snapshots, current Caddy
identity, active state, protection and startup references. No image was removed.
The failure is preserved; it is not counted as a successful cleanup.

The existing recovery approval covers a different, zero-sample HOLD. Absence of
an alias-intent log does not prove that the persistent intent field is null.
The current gate and immutable plan require fresh, complete read-only evidence.
The separately failing stopped-container metadata extension cannot supply a
successful full audit by removing its failure lines.

## New fixed gate-audit action

The protected image-cleanup workflow may run `action=gate-audit` on exact main,
with a clean recursive checkout and a manual first attempt. It performs the
existing locked base audit and then only the safe gate/plan/state projections.
The selected gate must actually be a schema-2, `passive_runtime_v1` HOLD and its
bound immutable plan must exist. This new path rejects legacy schema-1 gates;
the existing full retirement audit's compatibility is unchanged.
Missing critical fields are not silently projected as null. Unsafe paths,
permissions, links, schemas or changing evidence fail closed.

The final check re-reads the original state, gate and plan and checks their
original byte hashes and file identities; the base inventory/startup evidence
files must also be unchanged. It does not perform another full live inventory
walk. The base inventory therefore describes its own collection time, not a
claim that all runtime state stayed unchanged throughout the entire audit.
Any future recovery still requires a fresh, locked, full live comparison.

Gate-only collection has its own terminal result. It cannot invoke container
metadata collection, cleanup, post-success or the HOLD writer, and cannot
change gate/plan/state or grant recovery authority. The existing base audit
uses temporary evidence files and the existing deployment lock; this is not
described as a workflow with absolutely no host filesystem writes.

The ordinary retirement metadata audit retains its strict acceptance rules.
Only fixed, redacted failure categories are added to distinguish command,
JSON, field/schema and subsequent-read failures. Unsupported data is not
replaced with successful defaults. Raw inspect output, Docker errors, Env,
configuration and log contents must not appear in diagnostic output.

## Required verification and remaining boundary

Before merge, run the extended request, transport, readiness and workflow
contract tests, related cleanup/recovery regression tests, full PHPUnit and
JavaScript suites, PHPStan, Composer audit, shell syntax, workflow validation
and whitespace checks. Normal PR checks and the exact-main push build remain
required before a production audit.

Tests must cover missing/non-HOLD gates, missing plans, missing nullable keys,
unsafe file attributes, identity/byte drift, action/scope mismatches, reruns,
untracked checkout files, forbidden operations and diagnostic secret canaries.
The pre-existing recovery negatives must still reject a sampled HOLD.

Passing gate-audit does not clear HOLD or qualify any image/container for
deletion. Existing image approvals and the old sequence driver must not be
reused against the new HOLD. A separately researched and reviewed recovery
would need new exact evidence and a fresh complete baseline and observation.
The CPU spike's cause and production request/long-connection continuity are
not established by the prior passive samples or this read-only action.
