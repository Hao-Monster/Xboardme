# Exact single-image retirement sequence scope review

This review implements the owner's continuous historical-version cleanup
authorization. It is not an application deployment and grants no container,
network, volume, database, configuration, log, backup or directory deletion.
The running service must not be stopped, restarted, reloaded or switched.

## Fresh protected evidence

- Repository: Hao-Monster/Xboardme.
- Audit: https://github.com/Hao-Monster/Xboardme/actions/runs/38255536652
- Exact main/workflow: ba89a8a4d9039e5f079f5beea17a15e287e6a6af.
- Audit raw operation text SHA256: 2021439339a89449ef5fb97661ce613419b1ab4818733d36b82bbda01d458e2b.
- Observed audit epoch: 1791811631 (2026-10-12 21:27:11 +08:00).
- Hard expiry: 1791898031 (2026-10-13 21:27:11 +08:00); generation/commit time is not a new audit.
- 24 containers / 146 images, unchanged from the previous audited inventory.
- Caddy upstream: 127.0.0.1:7002; active release 37503793113-1.
- Active application: 17bad07edbbf1da2b8c1263fc5bb33499e22affc.
- Active state raw SHA256: ed841a62d7b4c6e3e5ff411126bd28ca5dcc228257ca0a57fa6fceec8d2cd6b5.
- Inventory: 2eeaa03650dd563c2681495d7f8e31afc4c40f2e51db6b0c460ac75a8f4ef0c4.
- Protection: e3193761cad71b4bf98c6adcd54417c93238dfc3195e0dfe9b52297fc2c647ee.
- Startup references: 35d5e4641d0e185b88610ab8cac703315405001161e2255ae8d908ddf4cb8412.
- Initial lifecycle/resource: 6ccd78749f8eb5fdce9ff54238a2256da89a430a8c30ba6a36534f635b294e7e.

## Narrow recovery

The first item is exactly the original unremoved target:
sha256:42e9170c0ab7c56014ec54a4e04a8ad78bc45bbb95cef090b2353267c514317a.
Its original gate remains HOLD with zero samples and no baseline, deletion
intent, removal, completion or post-verification evidence. The fresh protected
audit binds the old raw gate and plan, which the executor must revalidate under
the host lock before archiving the original HOLD and installing a fresh RUNNING
operation. No false COMPLETE or general deployment admission is introduced.

- Gate raw SHA256: 755a87349e0cda1b2b748b95c01792dde310b878382106f1567cdc46dbed2128.
- Plan raw SHA256: 340c4b2a9a7072e1358146776031e8c397105739a75edb4697bb2090a1d8af0c.
- Old review: 175339c7e1a5ac853f7c491fd4d0cc0046bd4133fe1774e89cceea6944b468db.
- Old workflow: 40472a6fb27a0d25b10b0a3d7d267705e79c8912.
- Old session: d3f84bd2-867b-415c-8dac-0cc1553a7651.

## Proposed scope and invariant checks

The separate explicit ordered allowlist has 90 image IDs and SHA256
e211e8bb1741d94d9e2a6dfd91024aa3d2268c234b29515e1dae17ab0fc5b694.
Item 001 is the old HOLD target; other items are ordered by creation time then
full image ID. Every item must independently pass all existing source/revision,
seven-day age, 512-MiB size, at-most-eight-alias, immutable recovery, protected
revision/digest, all-container-consumer and startup-reference checks. The full
formatter validates these again; the live executor matches a fresh locked full
inventory before deletion. No prefix, tag or age alone is deletion authority.

The logical total is 16,256,774,258 bytes, NOT predicted reclaimable space.
85 images belong to the historical namespace and 5 to the current namespace;
each exact immutable restore digest must be pulled and identity-verified on the
runner before host access, never assumed accessible and never pulled on host.
71 images have two repository digest aliases; the real Docker test below
covers this topology. Different-manifest/same-config behavior is not claimed.

All 24 current container identities/start times/restart counts remain protected
throughout the image phase, as do current Caddy process/route and mount identities.
The pending rename image/revision remains explicitly protected. Existing
configuration, startup metadata, logs, backups and all data remain unchanged.
The stopped storage/Redis anchor must remain even after later old-role retirement.

Each numbered review has exactly one image, no containers, exact projected
resource fingerprint and the raw-byte predecessor review hash. The next item
requires the previous host gate to be COMPLETE and independently post-verified.
The projected inventory is derived evidence, never represented as a new audit.
Use UTF-8/LF bytes and verify Git blobs before push; no later reformatting.

Existing resource thresholds, 120-second baseline, 300-second observation,
10-second sampling and 30-second heartbeat freshness are unchanged. The minimum
baseline/observation time for all 90 items is 37,800 seconds (10.5 hours), excluding
checkout, restore and audits. The chain must stop at expiry, any drift, HOLD,
unknown deletion result, recovery failure or failed independent verification.
Do not rerun an uncertain deletion or advance to the next item.

## Verification and remaining gates

- PR77 CI: https://github.com/Hao-Monster/Xboardme/actions/runs/38250610429
- verify and pr-image-build: SUCCESS.
- Real Docker: 16 PASS, including same-manifest/two-repository digest aliases,
  shared-layer/parent/container/data preservation and predelete recovery.
- Raw verify log SHA256: ed17f73acf156b21b1f541030b169b63412da6765c2f72ca383de655ad5f47fe.
- Exact-main push CI: https://github.com/Hao-Monster/Xboardme/actions/runs/38253235167
  SUCCESS, including image signing and immutable handoff artifact.
- Offline formatter: 55/55 PASS and independent code/schema/canonical/hash review.
- Independent real-evidence candidate review: PASS. The complete 90-ID set and
  first-HOLD/creation-time/ID order match the independently reconstructed pool.
  The ID-per-line/LF sequence hash is fd6975eba1a2217a34c5c335b7a0473bca40bc077e73e3b7e19ea398b5a6932c.
- Candidate sizes are 146,880,580 through 190,631,059 bytes. The 90 objects have
  89 revisions; the two distinct IDs at revision 637c7127 are separate objects,
  not duplicates to collapse. Aliases are counted/dropped uniquely; repeated
  tags/digests are not extra targets. Missing local protected images do not
  justify shrinking the inherited protected set.
- The generated final manifest bytes, every predecessor hash and every projected
  inventory still require independent verification before commit and execution.
- Future approval-manifest PR verify/pr-image-build and exact merged-main push
  build: REQUIRED before the first cleanup dispatch.
- Production request/long-connection continuity is not proven by passive runtime
  samples; these results must not be represented as an absolute zero-impact guarantee.

No historical container is approved by this document. Data/log preservation and
truthful lifecycle/startup-reference retirement remain separate required work.
