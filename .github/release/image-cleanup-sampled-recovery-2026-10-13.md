# Image-only sequence: fresh D audit and failed C recovery

This supplements, without editing or extending, the fixed scope in
`image-cleanup-sequence-2026-10-13.md`. It does not deploy application code,
migrate a database, remove containers or volumes, or restart/reload a service.
No image has been removed at this approval-preparation checkpoint.

## Evidence and unchanged scope

- Failed cleanup C: `cdc78de2014d6e47df6a8397462dc5e4c0fe059c`,
  run [38360368858](https://github.com/Hao-Monster/Xboardme/actions/runs/38360368858), attempt 1.
  Its 120-second baseline passed; 19 samples passed and sample 20 failed only
  CPU PSI (`21.17 > 10`). There was no deletion intent or completed removal.
- Startup revalidation and strict prior-recovery verification were reviewed in
  [PR87](https://github.com/Hao-Monster/Xboardme/pull/87), merged as D
  `08e751d3f4a9b5d606d35e24ef08e9b0219779ab`. No resource threshold was relaxed.
- Fresh read-only D audit:
  [38367341175](https://github.com/Hao-Monster/Xboardme/actions/runs/38367341175),
  attempt 1, completed `2026-10-13T08:11:36Z`.
  Exact artifact: `production-image-cleanup-08e751d3f4a9b5d606d35e24ef08e9b0219779ab-38367341175-1`.
  Its sole `images-only-operation.txt` is 280362 bytes, SHA256
  `71d208ad35c80dcb1c862c5797e484c7416e282f3c48e53c5c0987ed33a0a280`.
- Complete snapshots still contain 146 images and 24 containers. Active release
  `37503793113-1`, application SHA `17bad07edbbf1da2b8c1263fc5bb33499e22affc`,
  Caddy PID 4287 and upstream `127.0.0.1:7002` are unchanged. The exact C HOLD
  has 19 samples and all six intent/removal/completion/post-verification fields
  explicitly null. The frozen validator binds the complete gate and plan.
- The same ordered 90-image allowlist is retained, SHA256
  `e211e8bb1741d94d9e2a6dfd91024aa3d2268c234b29515e1dae17ab0fc5b694`.
  All targets exist, have no container consumer or startup reference, and do
  not overlap protected revisions/digests. Registry restore remains a per-run
  mandatory check, not an inferred result from inventory.
- Original scope SHA256 remains
  `fa0d46860e11b5b48a9a510d0ad1f7eb0a8ac9c537a81db899faab860e89b41f`.
  Fixed expiry remains epoch `1791957688`, **2026-10-14 14:01:28 Asia/Singapore**.
  Merging/building this approval does not renew or extend it.

## Exact recovery inputs

The content-addressed C proof is
`2f9523ebf289f60e5652fa98b2b960fa98b6dccd1f753a96a990147542181a5a`;
timeline is `6838b36327686ee3af02b889992dddee364ac03b9478aec45d8a31c8842df936`;
alignment is `8bc5121791b3ee3edae170e81d4bc89370afb042d47ad18e670bd0989a1fde94`.
These name the exact repository JSON files under the existing proof/evidence
directories. Raw bytes match their filenames.

The historical operation hash is
`bf1bc076a1337ab30cdad6c2b7abbb79446449b38097d503bc2e5bb66a001314`.
Its prior proof and ten-field canonical receipt are fetched from that same C
artifact, hashes `d2e2ea4f7b6a43cd8b0a2a8ca2ed968ee9861e0d9659a150a3332ede954383f1`
and `50c4d1de9224d390ec223d020241a94a7d004a95eb8f102e4b168c6528183096`.
They do not substitute D for the historical C workflow/run/session.

The original seed (`49ec4368eedf8f21be0e4d68508b91646c0e895acea7586e992b3ed3f43f78c6`)
is preserved. New `image-cleanup-sampled-seed-recovery-2026-10-13.json`, SHA256
`76f196149fd668a9fdef2802a8f2896fdd9beac79b955d1f2076e523fe3e483a`, changes only
`protected_image_revisions`, adding historical audit revision
`e1c98b36a02f5523735235113e2cd913ef922554` to avoid reducing C's protections.
The generated reviews additionally protect D; neither added protection overlaps
the approved 90 targets. No original seed field or runtime limit is relaxed.

## Generated sequence identity and execution conditions

- Frozen planner SHA256:
  `d52732614679307580a991955ab549f1bcfbe02133edc725c3b09893b15cd72c`.
- Frozen sequential driver SHA256:
  `0fecdd1ced067bc0dc8438194aeffaeabac90d90ef24f2915763fb2c08ba4eea`.
- Planner configuration `sampled-plan-38367341175.json` SHA256:
  `1ea1927969ea5e9aaa4aaad815542b2837b5fff63d431496d66d32b75605eaf4`.
- Full draft bundle SHA256:
  `2a1a9f53ccf301b5643c44d6bf975f5916f3697f1726476dcd39837d55b88129`.
- Ordered review set hash, canonical `[{index,sha256}]` plus LF:
  `a37120687174628e9dce2da952096b0af84400f45dff4ae9795f29ab99476d0c`.
- Review 001 SHA256:
  `8529f1c2e4f140f7700207a4c2e22bdfe9e2ce94f909888f6d9ba20c01c215e2`.
- Review 090 SHA256:
  `c35d494422617afd26102cabad55e6633516004ed7401d8c09b62bd77f7a3c93`.

Only 001 contains the exact C sampled-HOLD recovery. Each successor binds the
previous review's raw bytes and the inventory projected after precisely that
one image is absent. All container and protection snapshots remain unchanged.

Root admission requires independent full-chain review, actual repository
schema/freshness/monitoring validators, exact staged blobs and a normal
develop-to-main PR with nonempty successful required checks. Before the first
dispatch, freeze the resulting main SHA E and accept its unique canonical
main-push build, signature/provenance and immutable image manifest. D or C build
success is not a substitute. Recheck exact remote main, no parallel production
operation and more than 4200 seconds of remaining approval validity.

Independent admission passed at epoch `1791879837`: all ten raw sources,
90/90 byte-identical regenerated reviews, every predecessor link, independent
inventory/resource projection and the ordered set hash matched. Root reran the
actual repository validators against all 90 installed reviews (PASS), Linux
proof/runner tests (181 PASS, zero failures/skips), request admission tests
(66 cases PASS, no production connections), and `git diff --check` (PASS).
The task-local schema harness initially omitted the existing sampled-validator
import; adding that import made the complete real-schema run pass. No production
validator, assertion or threshold was changed to obtain this result. These
results admit normal PR/CI preparation, not an unverified production deletion.

Dispatch the new 001 once, reconcile an uncertain response without retrying,
and accept its full terminal evidence before continuing. The same frozen
driver configuration/journal must validate 001 read-only before dispatching
002 or later successors. Never skip a failed item or lower a resource guard.
The 120-second baseline and 300-second post-delete observation remain mandatory
for each image. A failed gate stops further removals pending diagnosis.

Logical image size is not reclaimed disk space because layers can be shared.
Runtime identity and passive observations are evidence, not proof of every
connection's continuity: `connection_continuity=unverified` remains explicit.
