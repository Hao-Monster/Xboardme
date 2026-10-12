# Historical Xboard retirement: execution plan and acceptance contract

The owner has authorized continuous, reviewed cleanup of obsolete versions and
has relinquished the need to retain their direct rollback runtime. This is not
authorization to stop, restart, reload, switch or deploy the current service,
or to delete business data, volumes, configuration, logs, backups or release
audit evidence. The distributor rename release remains out of scope.

## Verified starting point

Protected exact-main audit run `38243530349` at
`b701f95cc5bad138ef848a50010bb9b47d745b47` succeeded on 2026-10-12.
Its artifact SHA256 is
`67dd8ae51916036be65389dfc14315c4b669d7c1b7610a4cddb9f23d5ddc2266`.
The audit found 24 containers (7 running, 17 exited), 146 images and 137
release/startup metadata files. Active release `37503793113-1`, application
`17bad07edbbf1da2b8c1263fc5bb33499e22affc`, was resolved from Caddy's
`127.0.0.1:7002` upstream and its bound container, not inferred from age.
The resource/protection/inventory fingerprints match the unsuccessful
pre-deletion cleanup's snapshots. This is current inventory evidence, not
proof that the old HOLD can be removed or that every connection is continuous.

136 Xboard images have no container reference; 45 of those still have a
release/startup metadata reference. The remaining 91 are a *candidate pool*,
not an approved removal list. Every image still needs source, age, size,
immutable recovery, alias, protected revision and startup checks. Non-Xboard
images are outside this cleanup. The stopped Compose anchor is protected.

Offline verification of this exact artifact narrows that pool to 90 images
under the existing static rules. The excluded image is the protected pending
rename candidate (`873e943cdd8b2a994a189558ef3b4aadb953bd34`), which also fails
the seven-day minimum. The eligible images have 16,256,774,258 logical bytes;
this is not a prediction of reclaimed space. Seventy-one have the same digest
under two repository aliases, which requires real-Docker regression evidence.
Eighty-five use the historical registry namespace; successful current runner
access to each immutable digest remains an execution gate, not an assumption.

## Milestones

1. Collect bounded, read-only retirement evidence under the same production
   lock: original HOLD and immutable plan hashes, historical container state
   and log metadata. Do not invoke Docker writable-layer scans or read log
   contents: a client timeout does not reliably stop daemon-side disk work.
   Writable-layer preservation remains a separate prerequisite for stage 4.
2. Recover only the specific HOLD that failed before its first baseline sample
   and before any deletion intent. Match the original gate/session/plan hashes,
   prove the full original inventory, startup references, active state and
   protection still match, archive the original HOLD verbatim, and atomically
   replace it with a newly reviewed RUNNING operation. There is no general
   unlock, false COMPLETE, threshold override or deployment admission window.
3. Remove eligible unreferenced images through the existing single-image
   schema-3 executor. Each committed numbered manifest freezes one exact image
   and its expected inventory. Subsequent manifests bind the previous manifest
   and require its COMPLETE **and independently verified** gate. A projected
   inventory is explicitly derived from the original audit, never represented
   as a new live audit; the executor must match a fresh host audit exactly.
4. Independently review retirement of the stopped old role containers and
   historical startup references. Preserve writable business files, Docker
   logs, volumes and backup/audit evidence. Do not treat all exited containers
   or all old state references as disposable. Implement and test any necessary
   narrow retirement support before approving this stage. The entire cleanup
   goal is not complete after the image-only stage.
5. Re-audit the final live inventory, actual route/runtime identity and service
   signals. Report exact removals, measured disk delta, retained resources with
   reasons and verification gaps. Never report image logical size as reclaimed
   bytes, or a green workflow as proof of every live request's continuity.

## Invariants and failure handling

- Only exact-main protected CI receives production credentials. All changes go
  through permanent develop -> main, required checks and a recursive checkout.
- No broad prune, forced deletion, service lifecycle operation, production
  image pull, SQL write, authenticated login or new monitoring service. The
  readiness audit is metadata-only: no docker diff/export/inspect --size or
  recursive filesystem size walk.
- Existing image-only limits remain: one image, <=512 MiB, <=8 aliases, >=7 days;
  120-second baseline, 300-second observation, 10-second sampling, fixed resource
  thresholds, all containers and all active mounts/configuration protected.
- No automatic retry of a failed delete/unknown outcome. Preserve HOLD and its
  durable intent, investigate, review a specific recovery plan, then continue
  only if the safety conditions have again been proved. Routine research and
  verified fixes do not require asking the owner for the same authorization.
- Keep current roles, Caddy process identity, maintenance service, storage/Redis
  anchor, live data and all audit evidence. Age alone never authorizes removal.
- Host resource and container observations control risk but cannot guarantee
  zero transient latency or prove long-connection continuity. Such evidence
  remains NOT RUN unless actually measured; do not weaken it into a claim.

## Executable test matrix

| Risk | Required evidence before execution |
| --- | --- |
| Wrong/stale main, dirty checkout or selected manifest | Runner admission rejects before SSH |
| HOLD changed, nonzero sample/intent/removal, wrong plan | Recovery refuses without modifying original gate |
| Corrupt/symlink/hardlinked evidence or pending operation | Fail closed; no deletion or gate reset |
| Archive or gate replacement interruption | Old HOLD or new RUNNING remains blocking; no permissive state |
| Skipped/out-of-order/repeated manifest | Exact predecessor verification rejects |
| Forged projected inventory or unexpected new resource | Fresh full audit differs; no deletion |
| Protected/current/startup-referenced target | Existing eligibility and reference checks reject |
| Resource or runtime failure during delete/observation | Durable HOLD; no next image |
| Compatibility with existing callers | Existing admission, continuity, executor, post-audit and transport suites pass |
| Real daemon behavior and continuous synthetic TCP | Isolated real-Docker CI passes; not claimed as production continuity |
| Historical container/data/log preservation | Separate retirement contract and evidence before any container removal |

Approval manifests remain separate from this plan. This document does not by
itself grant any executor a removal target or relax a protection rule.
