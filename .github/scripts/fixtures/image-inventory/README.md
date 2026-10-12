# Image-only inventory checks

The production entry point is `images_only_all_snapshots` in
`../../images-only-inventory.sh`, sourced after `retention-safety.sh`. It is
used only by the image-only audit and cleanup inventory calls. The shared
container, single-image and full-inventory helpers remain unchanged.

Production needs Bash, jq, Docker CLI and existing coreutils only. Go and
Python below are test/CI dependencies, not production-host requirements.

## Safety contract

- Read the complete global container and image inventory, including unrelated
  resources. Never restrict the safety inventory to fixture or project labels.
- Inspect images in batches of at most 16, using seven fixed metadata fields.
  Reject malformed rows, duplicate keys/IDs, unknown fields, omitted/foreign
  IDs, invalid types, nonzero CLI exits and a changed final image-ID listing.
- Fail closed above 256 unique image IDs, a 128 KiB image listing, 1 MiB raw
  metadata or canonical container record, or 16 MiB aggregate containers or
  final output. Bounds reject the whole read; they never truncate accepted
  inventory or make large resources eligible for deletion.
- The fixed 120-second successful-read deadline starts before setup. The
  worker timeout subtracts setup time and reserves four seconds for its
  two-second TERM/KILL escalation, group collection and final checks. Kernel
  stalls, uninterruptible tasks and SIGKILL/host loss are not hard-real-time
  guarantees. A late/failed read publishes no accepted inventory.
- The production supervisor owns a separate GNU timeout process group. Its
  inner metadata timeouts use `--foreground`. Cancellation cleanup ignores
  repeated TERM/HUP while stopping that exact owned group. It never signals
  the daemon, production containers or arbitrary process IDs.
- The public shell function is called synchronously by the two consumers. A
  caller cancelling it externally must signal the owned caller process group
  or actual body owner; the PID of an extra wrapper created by `function &` is
  not the body PID. The CI linked tests verify group cancellation and the
  direct watchdog tests verify its exact owned supervisor PID.
- Existing resource thresholds, 120/300-second observation windows, protection
  fingerprints, restore checks and immediately-adjacent deletion checks are
  not relaxed by this inventory implementation.

## Offline tests

```sh
bash .github/scripts/test-images-only-inventory.sh
python3 -B .github/scripts/test-image-inventory-docker.py --mode self-test
python3 -B .github/scripts/test-image-inventory-ci.py
bash .github/scripts/test-audit-xboard-images-only.sh
bash .github/scripts/test-cleanup-xboard-images-only.sh
```

`docker.sh` never forwards to Docker. `template-render.go` uses Go's actual
`text/template` implementation and JSON encoder, including null Config/Labels
and special-character cases. It is not evidence that an actual Docker daemon
rendered the template. The first positive fixture has 146 images and 24
containers; malformed-input cases use smaller fixtures for test speed.

The Bash tests exercise the final production parser, watchdog, absolute
deadline accounting, process-group cleanup and byte bounds. The Python tests
exercise CI ownership/recovery guards and the final production helper through
the CI supervisor. They independently inspect both the CI launch group and
the production timeout group; success of one group check is not substituted
for the other. Short synthetic watchdog tests are not production resource
observations or 120/300-second benchmark evidence.

Go normally builds the renderer in a private test directory. An explicitly
prebuilt renderer for the test platform can be passed with
`IMAGE_INVENTORY_TEST_RENDERER`; it must correspond to this fixture source.

## Isolated real-Docker CI

`.github/workflows/image-inventory-ci.yml` runs correctness on its own
GitHub-hosted Linux runner. A separate manual, main-only `benchmark=true` job
enables comparative resource sampling. Neither job receives production
credentials, environments or id-token permissions, and neither shares the
MySQL verification job's runner.

The harness requires an exact, clean GitHub checkout and an explicit mode
opt-in. It invokes the final production helper directly, not a temporary
adapter or copied implementation. It imports 146 tiny distinct images and
creates 24 containers with `docker create`; those containers are never
started, and no image is pulled. Pre-existing resources remain in the global
inventory. Recovery permits only the unique fixture label plus exact
namespace/index and original-inventory exclusions. It never uses force,
prune, stop, restart, down or volume deletion; ambiguous resources are retained
and the CI run fails.

The comparative benchmark performs observer-only and old/new/new/old serial
episodes. Every episode requires a real 120-second baseline and 300-second
post-read observation, live 10-second sampling, complete monotonic timestamp
coverage and the unchanged resource limits. PASS means valid CI collection,
not an automatic performance improvement or approval for production cleanup.

Only safe summaries and sampling episodes are uploaded. Raw inspect output,
private fixture directories and ownership ledgers are not artifact paths.
Actual RepoDigests, actual null-config Docker objects, Caddy/public connection
continuity and production headroom remain separately identified evidence gaps.
SIGKILL or runner loss can prevent normal fixture teardown; that is not claimed
to be recoverable by shell traps.
