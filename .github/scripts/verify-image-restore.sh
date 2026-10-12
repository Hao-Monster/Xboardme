#!/usr/bin/env bash
set -Eeuo pipefail
# Runner-only recovery proof. Never stream this script, registry credentials or
# Docker auth configuration to production. No image is run here or on the host.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/images-only-approval.sh"
source "$script_dir/select-images-only-review.sh"
operation=restore
if [[ ${1:-} == --check-evidence ]]; then operation=check; shift; fi
review=${1:-}
if [[ -z "$review" ]]; then review=$(images_only_selected_review) || exit 1; fi
fail() { printf 'IMAGE_RESTORE_FAIL=%s\n' "$1" >&2; exit 1; }
bash "$script_dir/verify-images-only-request.sh" "$review"
[[ "${IMAGE_CLEANUP_ACTION:-}" == cleanup ]] || fail passive_cleanup_required
jq -e '.schema_version==3 and .monitoring.mode=="passive_runtime_v1"' "$review" >/dev/null || fail passive_cleanup_required
[[ "${RUNNER_TEMP:-}" == /* && -d "$RUNNER_TEMP" && ! -L "$RUNNER_TEMP" &&
   "$(realpath -e -- "$RUNNER_TEMP")" == "$RUNNER_TEMP" ]] || fail invalid_runner_temp
[[ "${GITHUB_RUN_ID:-}" =~ ^[1-9][0-9]*$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[1-9][0-9]*$ ]] || fail invalid_run_identity
evidence="$RUNNER_TEMP/images-only-restore.json"
review_hash=$(sha256sum < "$review" | awk '{print $1}')
image_id=$(jq -er '.images[0].id' "$review")
digest=$(jq -er '.images[0].restore_digest' "$review")
revision=$(jq -er '.images[0].revision' "$review")
case "$digest" in
  ghcr.io/hao-monster/xboardme@sha256:*) expected_source=https://github.com/Hao-Monster/Xboardme ;;
  ghcr.io/fenghaoyun-monster/xboardme@sha256:*) expected_source=https://github.com/FengHaoyun-MONSTER/Xboardme ;;
  *) fail invalid_restore_registry ;;
esac
check_evidence() {
  [[ -f "$evidence" && ! -L "$evidence" && $(stat -c %s -- "$evidence") -le 8192 &&
     "$(stat -c '%u:%a:%h' -- "$evidence")" == "$EUID:600:1" ]] || fail invalid_restore_evidence
  jq -e --arg workflow "$EXPECTED_SHA" --arg run "$GITHUB_RUN_ID" --arg attempt "$GITHUB_RUN_ATTEMPT" \
    --arg review "$review_hash" --arg id "$image_id" --arg digest "$digest" --arg revision "$revision" \
    --arg source "$expected_source" --argjson now "$(date +%s)" --slurpfile approval "$review" '
      .schema_version==1 and .result=="PASS" and .workflow_sha==$workflow and .run_id==$run and .run_attempt==$attempt and
      .review_sha256==$review and .platform=="linux/amd64" and .restore_digest==$digest and .revision==$revision and
      .source==$source and .expected_image_id==$id and (.observed_image_id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
      ((.id_semantics=="image_id" and .observed_image_id==$id) or
        (.id_semantics=="manifest_digest" and $id==($digest|split("@")|last) and .observed_image_id!=$id)) and
      (.verified_at_epoch|type=="number" and floor==.) and
      .verified_at_epoch>=$approval[0].audit_completed_at_epoch and .verified_at_epoch<=$now and
      $now<$approval[0].approval_expires_at_epoch' "$evidence" >/dev/null 2>&1 || fail invalid_restore_evidence
}
if [[ "$operation" == check ]]; then
  check_evidence
  echo 'IMAGE_RESTORE_EVIDENCE=PASS mode=passive_runtime_v1'
  exit 0
fi
[[ ! -e "$evidence" && ! -L "$evidence" ]] || fail evidence_already_exists
# Pull even if cached: this proves the exact immutable registry object remains
# retrievable now. Never substitute a tag, retry, run it or use production auth.
timeout --signal=TERM --kill-after=5s 300s docker pull --platform linux/amd64 "$digest" >/dev/null 2>&1 || fail pull_failed
inspected=$(timeout --signal=TERM --kill-after=2s 10s docker image inspect --format \
  '{"id":{{json .Id}},"os":{{json .Os}},"architecture":{{json .Architecture}},"digests":{{json .RepoDigests}},"revision":{{json (index .Config.Labels "org.opencontainers.image.revision")}},"source":{{json (index .Config.Labels "org.opencontainers.image.source")}}}' \
  "$digest" 2>/dev/null) || fail inspect_failed
[[ ${#inspected} -le 32768 ]] || fail inspect_oversized
jq -e --arg digest "$digest" --arg revision "$revision" --arg source "$expected_source" '
  type=="object" and (.id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
  .os=="linux" and .architecture=="amd64" and .revision==$revision and .source==$source and
  (.digests|type=="array" and index($digest)!=null)' <<< "$inspected" >/dev/null 2>&1 || fail restore_metadata_mismatch
observed_id=$(jq -er .id <<< "$inspected")
if [[ "$observed_id" == "$image_id" ]]; then
  id_semantics=image_id
elif [[ "$image_id" == "${digest##*@}" ]]; then
  # The containerd image store may expose the manifest/index digest as Id;
  # classic Docker exposes its platform config Id. The exact pulled RepoDigest,
  # platform and labels above bind both to the same approved immutable object.
  id_semantics=manifest_digest
else
  fail restore_identity_mismatch
fi
retention_images_review_fresh "$review" || fail approval_expired
[[ "$(sha256sum < "$review" | awk '{print $1}')" == "$review_hash" ]] || fail review_changed
temporary=$(mktemp "$RUNNER_TEMP/.image-restore.XXXXXX")
trap 'rm -f -- "$temporary"' EXIT
chmod 600 "$temporary"
jq -cnS --arg workflow "$EXPECTED_SHA" --arg run "$GITHUB_RUN_ID" --arg attempt "$GITHUB_RUN_ATTEMPT" \
  --arg review "$review_hash" --arg id "$image_id" --arg observed "$observed_id" --arg digest "$digest" \
  --arg revision "$revision" --arg source "$expected_source" --arg semantics "$id_semantics" --argjson now "$(date +%s)" \
  '{schema_version:1,result:"PASS",workflow_sha:$workflow,run_id:$run,run_attempt:$attempt,
    review_sha256:$review,platform:"linux/amd64",restore_digest:$digest,revision:$revision,source:$source,
    expected_image_id:$id,observed_image_id:$observed,id_semantics:$semantics,verified_at_epoch:$now}' > "$temporary"
ln -- "$temporary" "$evidence" || fail evidence_already_exists
echo "IMAGE_RESTORE=PASS platform=linux/amd64 id_semantics=$id_semantics image=$image_id digest=$digest"
