#!/usr/bin/env bash
set -Eeuo pipefail
source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
script_dir="$test_root/scripts"
mkdir -p "$test_root/bin" "$script_dir" "$test_root/release/image-cleanup-reviews"
cd -- "$test_root"
for file in verify-image-restore.sh verify-images-only-request.sh images-only-approval.sh \
  retention-continuity.sh select-images-only-review.sh retention-predelete-recovery.sh; do
  cp -- "$source_dir/$file" "$script_dir/$file"
done
cat > "$test_root/bin/git" <<'GIT'
#!/usr/bin/env bash
case "$1" in
  rev-parse) printf '%s\n' "$GITHUB_SHA" ;;
  diff|merge-base) exit 0 ;;
  status) printf '%s' "${TEST_STATUS:-}" ;;
  submodule) printf ' clean\n' ;;
  ls-remote) printf '%s\trefs/heads/main\n' "${TEST_REMOTE:-$GITHUB_SHA}" ;;
  *) exit 97 ;;
esac
GIT
cat > "$test_root/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\n' "$*" >> "$TEST_DOCKER_CALLS"
case "$1" in
  pull)
    [[ $# == 4 && "$2" == --platform && "$3" == linux/amd64 && "$4" == "$TEST_DIGEST" ]] || exit 97
    printf 'SENSITIVE_FIXTURE_DO_NOT_LOG\n' >&2
    [[ ${TEST_PULL_FAIL:-false} != true ]] ;;
  image)
    [[ $# == 5 && "$2" == inspect && "$3" == --format && "$5" == "$TEST_DIGEST" ]] || exit 97
    jq -cn --arg id "${TEST_OBSERVED_ID:-$TEST_IMAGE_ID}" --arg digest "${TEST_OBSERVED_DIGEST:-$TEST_DIGEST}" \
      --arg revision "${TEST_OBSERVED_REVISION:-$TEST_REVISION}" \
      --arg source "${TEST_OBSERVED_SOURCE:-https://github.com/Hao-Monster/Xboardme}" \
      --arg arch "${TEST_OBSERVED_ARCH:-amd64}" \
      '{id:$id,os:"linux",architecture:$arch,digests:[$digest],revision:$revision,source:$source}' ;;
  *) printf 'FORBIDDEN_DOCKER_MUTATION\n' >&2; exit 97 ;;
esac
DOCKER
chmod +x "$test_root/bin/git" "$test_root/bin/docker"
export PATH="$test_root/bin:$PATH"
export GITHUB_REPOSITORY=Hao-Monster/Xboardme GITHUB_REF=refs/heads/main
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa EXPECTED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export GITHUB_WORKFLOW_SHA=$GITHUB_SHA GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=1
export GITHUB_WORKFLOW_REF=Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main
export IMAGE_CLEANUP_ACTION=cleanup
export IMAGE_CLEANUP_REVIEW_ID=default GITHUB_EVENT_NAME=workflow_dispatch
export TEST_REVISION=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
export TEST_IMAGE_ID="sha256:$(printf 'f%.0s' {1..64})"
export TEST_DIGEST="ghcr.io/hao-monster/xboardme@sha256:$(printf 'd%.0s' {1..64})"
export TEST_DOCKER_CALLS="$test_root/docker-calls"
make_review() {
  jq -n --argjson now "$(date +%s)" --arg sha "$GITHUB_SHA" --arg id "$TEST_IMAGE_ID" \
    --arg digest "$TEST_DIGEST" --arg revision "$TEST_REVISION" '
    ("c"*64) as $h | {
      schema_version:3,status:"approved",operation:"image_cleanup",active_release_id:"123-1",active_release_sha:$sha,
      resource_fingerprint:$h,protection_fingerprint:$h,protected_image_revisions:[$sha],
      protected_image_digests:[("ghcr.io/hao-monster/xboardme@sha256:"+$h)],
      review_evidence:{scope_review_sha256:$h,isolated_docker_review_sha256:$h},
      audit_completed_at_epoch:($now-10),approval_expires_at_epoch:($now+3600),max_image_size_bytes:1024,
      containers:[],images:[{id:$id,revision:$revision,restore_digest:$digest,snapshot_sha256:$h,
        evidence:{startup_reference_review_sha256:$h}}],
      monitoring:{mode:"passive_runtime_v1",active_port:7002,baseline_seconds:120,post_delete_observe_seconds:300,
        sample_interval_seconds:10,heartbeat_max_age_seconds:30,
        caddy:{unit:"caddy.service",invocation_id:("d"*32),main_pid:42,active_enter_monotonic_usec:1000},
        protected_containers:[range(1;7)|{id:((tostring)*64),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}],
        resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,max_io_full_avg10:1,max_cpu_some_avg10:10}}
    }' > "$test_root/review.json"
}
new_case() {
  export RUNNER_TEMP="$test_root/$1"
  mkdir "$RUNNER_TEMP"
  : > "$TEST_DOCKER_CALLS"
}
invoke() { bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"; }
assert_redacted() {
  local status
  if grep -Fq 'SENSITIVE_FIXTURE_DO_NOT_LOG' "$1"; then
    echo 'IMAGE_RESTORE_TEST_FAIL=sensitive_output_leaked' >&2; exit 1
  else
    status=$?
    [[ "$status" == 1 ]] || { echo 'IMAGE_RESTORE_TEST_FAIL=unreadable_evidence' >&2; exit 1; }
  fi
}
passed=0
failure() {
  local expected=$1
  shift
  new_case "${expected}_$passed"
  if "$@" > "$RUNNER_TEMP/result" 2>&1; then echo "unexpected success:$expected" >&2; exit 1; fi
  grep -Eq "(IMAGE_RESTORE_FAIL|IMAGE_REQUEST_FAIL)=$expected" "$RUNNER_TEMP/result"
  [[ ! -e "$RUNNER_TEMP/images-only-restore.json" ]]
  assert_redacted "$RUNNER_TEMP/result"
  ((passed+=1))
}
make_review
new_case exact_id
invoke > "$RUNNER_TEMP/result"
jq -e --arg id "$TEST_IMAGE_ID" --arg digest "$TEST_DIGEST" --arg revision "$TEST_REVISION" \
  '.result=="PASS" and .expected_image_id==$id and .observed_image_id==$id and .restore_digest==$digest and
    .revision==$revision and .platform=="linux/amd64" and .id_semantics=="image_id" and
    .workflow_sha==("a"*40) and .run_id=="123" and .run_attempt=="1"' "$RUNNER_TEMP/images-only-restore.json" >/dev/null
[[ $(wc -l < "$TEST_DOCKER_CALLS") == 2 ]]
assert_redacted "$RUNNER_TEMP/result"
((passed+=1))
bash "$script_dir/verify-image-restore.sh" --check-evidence "$test_root/review.json" > "$RUNNER_TEMP/check-result"
[[ $(wc -l < "$TEST_DOCKER_CALLS") == 2 ]]
((passed+=1))
if GITHUB_RUN_ATTEMPT=2 bash "$script_dir/verify-image-restore.sh" --check-evidence "$test_root/review.json" > "$RUNNER_TEMP/check-result" 2>&1; then exit 1; fi
grep -Fxq 'IMAGE_RESTORE_FAIL=invalid_restore_evidence' "$RUNNER_TEMP/check-result"
((passed+=1))
failure pull_failed env TEST_PULL_FAIL=true bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
failure restore_identity_mismatch env TEST_OBSERVED_ID="sha256:$(printf '9%.0s' {1..64})" bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
failure restore_metadata_mismatch env TEST_OBSERVED_DIGEST="ghcr.io/hao-monster/xboardme@sha256:$(printf '8%.0s' {1..64})" bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
failure restore_metadata_mismatch env TEST_OBSERVED_SOURCE=https://example.invalid/other bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
failure restore_metadata_mismatch env TEST_OBSERVED_REVISION=cccccccccccccccccccccccccccccccccccccccc bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
failure restore_metadata_mismatch env TEST_OBSERVED_ARCH=arm64 bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
failure stale_main env TEST_REMOTE=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
[[ ! -s "$TEST_DOCKER_CALLS" ]]
failure passive_cleanup_required env IMAGE_CLEANUP_ACTION=audit bash "$script_dir/verify-image-restore.sh" "$test_root/review.json"
[[ ! -s "$TEST_DOCKER_CALLS" ]]

# Containerd may use the pinned manifest/index digest as its image ID. Only
# that exact approved identity permits the runner classic-store config ID.
jq --arg id "${TEST_DIGEST##*@}" '.images[0].id=$id' "$test_root/review.json" > "$test_root/manifest-review.json"
new_case manifest_digest
bash "$script_dir/verify-image-restore.sh" "$test_root/manifest-review.json" > "$RUNNER_TEMP/result"
jq -e --arg id "${TEST_DIGEST##*@}" --arg observed "$TEST_IMAGE_ID" \
  '.expected_image_id==$id and .observed_image_id==$observed and .id_semantics=="manifest_digest"' \
  "$RUNNER_TEMP/images-only-restore.json" >/dev/null
((passed+=1))
new_case existing_output
printf 'do not overwrite\n' > "$RUNNER_TEMP/images-only-restore.json"
if invoke > "$RUNNER_TEMP/result" 2>&1; then exit 1; fi
grep -Fxq 'IMAGE_RESTORE_FAIL=evidence_already_exists' "$RUNNER_TEMP/result"
[[ $(< "$RUNNER_TEMP/images-only-restore.json") == 'do not overwrite' && ! -s "$TEST_DOCKER_CALLS" ]]
((passed+=1))
new_case symlink_output
ln -s "$test_root/missing" "$RUNNER_TEMP/images-only-restore.json"
if invoke > "$RUNNER_TEMP/result" 2>&1; then exit 1; fi
grep -Fxq 'IMAGE_RESTORE_FAIL=evidence_already_exists' "$RUNNER_TEMP/result"
[[ -L "$RUNNER_TEMP/images-only-restore.json" && ! -s "$TEST_DOCKER_CALLS" ]]
((passed+=1))
jq '.approval_expires_at_epoch=(.audit_completed_at_epoch+1)' "$test_root/review.json" > "$test_root/expired.json"
failure approved_fresh_review_required bash "$script_dir/verify-image-restore.sh" "$test_root/expired.json"
[[ ! -s "$TEST_DOCKER_CALLS" ]]
# No-argument compatibility and numbered selection must both stay on private
# fixtures, with restore evidence bound to the selected full review hash.
cp -- "$test_root/review.json" "$test_root/release/image-cleanup-review.json"
new_case default_selection
bash "$script_dir/verify-image-restore.sh" > "$RUNNER_TEMP/result"
jq -e --arg hash "$(sha256sum < "$test_root/review.json" | awk '{print $1}')" \
  '.review_sha256==$hash' "$RUNNER_TEMP/images-only-restore.json" >/dev/null
[[ $(wc -l < "$TEST_DOCKER_CALLS") == 2 ]]
((passed+=1))
first="$script_dir/../release/image-cleanup-reviews/001.json"
second="$script_dir/../release/image-cleanup-reviews/002.json"
jq '.images[0].id=("sha256:"+("9"*64)) |
  .sequence={schema_version:1,index:"001",predecessor_review_sha256:null,audit_artifact_sha256:("d"*64)} |
  .recovery={schema_version:1,mode:"predelete_hold_v1",gate_sha256:("e"*64),plan_sha256:("f"*64),
    review_sha256:("9"*64),workflow_sha:("8"*40),session_id:"11111111-1111-1111-1111-111111111111",
    image_id:.images[0].id}' "$test_root/review.json" > "$first"
jq --arg previous "$(sha256sum < "$first" | awk '{print $1}')" '
  .sequence={schema_version:1,index:"002",predecessor_review_sha256:$previous,audit_artifact_sha256:("d"*64)}' \
  "$test_root/review.json" > "$second"
# The just-created default proof cannot authorize an otherwise identical image
# under a different sequenced approval. No registry access is made by a check.
before_calls=$(wc -l < "$TEST_DOCKER_CALLS")
if IMAGE_CLEANUP_REVIEW_ID=002 bash "$script_dir/verify-image-restore.sh" --check-evidence > "$RUNNER_TEMP/check-result" 2>&1; then exit 1; fi
grep -Fxq 'IMAGE_RESTORE_FAIL=invalid_restore_evidence' "$RUNNER_TEMP/check-result"
[[ $(wc -l < "$TEST_DOCKER_CALLS") == "$before_calls" ]]
((passed+=1))
new_case selected_review
IMAGE_CLEANUP_REVIEW_ID=002 bash "$script_dir/verify-image-restore.sh" > "$RUNNER_TEMP/result"
jq -e --arg hash "$(sha256sum < "$second" | awk '{print $1}')" --arg id "$TEST_IMAGE_ID" \
  '.review_sha256==$hash and .expected_image_id==$id' "$RUNNER_TEMP/images-only-restore.json" >/dev/null
[[ $(wc -l < "$TEST_DOCKER_CALLS") == 2 ]]
((passed+=1))
IMAGE_CLEANUP_REVIEW_ID=002 bash "$script_dir/verify-image-restore.sh" --check-evidence > "$RUNNER_TEMP/check-result"
[[ $(wc -l < "$TEST_DOCKER_CALLS") == 2 ]]
((passed+=1))
failure manual_first_attempt_required env IMAGE_CLEANUP_REVIEW_ID=002 GITHUB_RUN_ATTEMPT=2 \
  bash "$script_dir/verify-image-restore.sh"
[[ ! -s "$TEST_DOCKER_CALLS" ]]
failure dirty_checkout env IMAGE_CLEANUP_REVIEW_ID=002 TEST_STATUS='?? unreviewed.json' \
  bash "$script_dir/verify-image-restore.sh"
[[ ! -s "$TEST_DOCKER_CALLS" ]]
failure passive_cleanup_required env IMAGE_CLEANUP_ACTION=retirement-audit \
  bash "$script_dir/verify-image-restore.sh"
[[ ! -s "$TEST_DOCKER_CALLS" ]]
echo "IMAGE_RESTORE_TEST=PASS cases=$passed production_connections=0 real_registry_pulls=0"
