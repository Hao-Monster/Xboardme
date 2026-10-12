# Passive single-image cleanup: evidence review

Date: 2026-10-12, Asia/Singapore. This is an operations-only change, not an
application release. The user authorized the narrowed idle-image cleanup.

## Exact scope reviewed

- Audit: [38215833245](https://github.com/Hao-Monster/Xboardme/actions/runs/38215833245),
  exact main `c078add837315ed23949437267843d21a130c854`, completed epoch `1791788852`.
- Audit artifact `images-only-operation.txt` SHA256:
  `d5f9e84df0b2a104d1e8024845f159c31f94e8d78db47e7cacf9d69379577236`.
- Active release `37503793113-1`, revision
  `17bad07edbbf1da2b8c1263fc5bb33499e22affc`, actual Caddy upstream
  `127.0.0.1:7002`, rollback supported. All 24 containers are preserved.
- Sole selected image:
  `sha256:42e9170c0ab7c56014ec54a4e04a8ad78bc45bbb95cef090b2353267c514317a`.
- Immutable recovery reference:
  `ghcr.io/hao-monster/xboardme@sha256:42e9170c0ab7c56014ec54a4e04a8ad78bc45bbb95cef090b2353267c514317a`.
- Source `https://github.com/Hao-Monster/Xboardme`; revision
  `39bf5b3b57177c0c0389307a7004b7648643ade1`; created
  `2026-08-23T04:46:01.429397044Z`; logical size 155737451 bytes.
- Selected snapshot SHA256:
  `a0c8cb037658f7337d182d7da19df90e0c42daab6c56718f4827dc95cd27f0e5`.
- One unique alias. No running or stopped container references this ID. No
  startup ID, revision, alias or repository-independent digest overlaps this
  candidate. All container snapshots and startup references remain frozen.
- Resource fingerprint:
  `6ccd78749f8eb5fdce9ff54238a2256da89a430a8c30ba6a36534f635b294e7e`.
- Protection fingerprint:
  `e3193761cad71b4bf98c6adcd54417c93238dfc3195e0dfe9b52297fc2c647ee`.
- Startup-reference SHA256:
  `35d5e4641d0e185b88610ab8cac703315405001161e2255ae8d908ddf4cb8412`.
- A different container-unreferenced image beginning `43eb23c8` was excluded
  because startup configuration still references it. Container absence alone
  was not used as deletion authority.

Registry metadata was read without a production connection: the approved root
is an index, its linux/amd64 child manifest is
`sha256:ba3c659125325f8eb187f3c9a4f1e74e336c629702bd13ffc2f6b94186db763e`,
and its config is
`sha256:f72f470b84c53c9fa6d8d8a4c9bae4f24c7a1e7b7ac797a802119bc0f34e7978`.
Source/revision match. This metadata check is not a complete layer pull. The
cleanup run must pull and inspect the exact digest on its isolated runner
before its first production access; it must never pull or run it on production.

## Executed local validation

All results below are exit 0, with no reported failures or skips. Windows and
WSL tests used isolated fixtures, not production. No shared Docker daemon was
started for this work.

| Check | Result and environment |
|---|---|
| Full PHPUnit | 322 tests / 3730 assertions, PHP 8.5.9, Windows, 86.924s |
| JavaScript suite | 136 tests, Node, Windows, 4.49s |
| PHPStan | No errors, Windows |
| Composer audit | No security advisories, Windows |
| Legacy continuity | 41 cases, WSL Debian |
| Passive monitoring | 84 cases, WSL Debian; final source rerun passed |
| Approval / request | 35 / 18 cases, WSL Debian |
| Runner restore | 15 cases, mocked registry/CLI; not a real pull |
| Precise executor | 46 cases, WSL Debian; final source rerun passed |
| Independent post-audit | 22 cases, WSL Debian |
| Remote transport orchestration | 10 isolated cases, WSL Debian; genuine admission, restore validator and persistent gate, local SSH substitute |
| Workflow contract | 5 tests / 37 assertions, Windows PHPUnit |
| Existing offline release fixtures | PASS; startup80, audit19, admission8, timeout1, retention9/14, session13, bounded-smoke, release-state4 |
| Shell syntax | 85 shell files passed before the transport regression addition |
| Workflow validation | actionlint 1.7.12, `-shellcheck=`, PASS; ShellCheck NOT RUN |
| Whitespace | `git diff --check`, PASS |

Red-to-green evidence: schema3 admission was rejected by the old validator;
the new passive test initially failed at `schema3_passive_monitoring_rejected`;
the workflow contract initially failed without the runner restore step. All
three targeted failures passed after implementation. Tests retain the old
schema2 live-signal contract and reject mode/environment downgrade attempts.

## Real Docker evidence and required CI gate

The earlier exact PR head `37949dc70c9aa5d378f81e659212836d5ce1f8d5` passed
[run 38198776964](https://github.com/Hao-Monster/Xboardme/actions/runs/38198776964):
eight real Docker scenarios, 408 continuous synthetic TCP samples, zero
reconnects. This is prior-contract evidence, not proof of the new schema3 path.

The current test adds five scenarios (13 total), with a loopback registry
serving genuine synthetic config/layers and real Docker pull, inspect and
remove. It retains shared-layer, all-container, bind/volume sentinel and
continuous synthetic TCP tests. New cases cover stopped historical unhealthy
metadata, running unhealthy rejection, digest mismatch, omitted rollback
member and StartedAt drift after the first alias. Local registry protocol
checks passed 8/8; real Docker execution is NOT RUN locally.

The committed scope approval is conditional on this exact change passing the
normal PR `verify` and `pr-image-build` checks, including the 13 real Docker
cases and the PHP/MySQL matrix. Do not merge or dispatch cleanup based on this
document alone. Preserve the actual successful PR CI log with the execution
report; an empty check list or prior successful run cannot satisfy this gate.
The exact merged main build must also complete before the one cleanup dispatch.

## Independent review and remaining limits

Independent reviews found no new Critical/High path bypassing persistent gates,
expanding deletion scope or dropping existing consumers. They checked schema2
compatibility, all-container monitoring, restore identity, isolated production
credentials and independent post-audit. A Medium issue in failure reporting
(post-failure unnecessarily requiring positive restore evidence) was corrected
with a minimal failure-only exception. The new ten-case transport regression
failed before the fix at `IMAGE_RESTORE_FAIL=invalid_restore_evidence`, then
passed. Cleanup/post-success still require recovery evidence before any SSH;
post-failure can only tighten the matching existing gate. Main drift, expired
approval, mismatched review and an old HOLD remain rejected. Freshness/main
drift can still prevent recording HOLD; RUNNING or COMPLETE/pending then stays
fail-closed and must not be reported as a successful HOLD write.

The reviewer explicitly retained these limits: inspect-to-remove is not Docker
CAS; no other Docker writer may run concurrently. CLI timeout does not prove
the daemon stopped. A 512 MiB logical cap is not a disk-IO cap. Actual SSH loss
and daemon timeout under production load are NOT RUN. Line/branch coverage was
not collected. Stopped containers' historical unhealthy metadata is not evidence
that the current online application is unhealthy.

Execution still requires fresh locked inventory, exact-main identity, a fixed
120-second passive baseline, one no-force/no-prune removal, 300-second passive
observation, an independent full audit and preserved evidence before marking
post-verification passed. HTTP/login/WS and natural-traffic continuity are not
measured by this contract. Any failure stops further deletion and deployment;
no automatic next image, repeat dispatch, finalize or application deployment.
