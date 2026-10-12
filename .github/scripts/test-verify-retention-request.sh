#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
work_dir=$(mktemp -d)
trap 'rm -rf -- "$work_dir"' EXIT
mkdir "$work_dir/bin"
cat > "$work_dir/bin/git" <<'GIT'
#!/usr/bin/env bash
case "$1" in
  rev-parse) printf '%s\n' "$GITHUB_SHA" ;;
  merge-base) exit 0 ;;
  ls-remote) printf '%s\trefs/heads/main\n' "${TEST_REMOTE_SHA:-$GITHUB_SHA}" ;;
  *) exit 97 ;;
esac
GIT
chmod +x "$work_dir/bin/git"
export PATH="$work_dir/bin:$PATH"
export GITHUB_REF=refs/heads/main GITHUB_REPOSITORY=Hao-Monster/Xboardme
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export EXPECTED_SHA=$GITHUB_SHA
export RELEASE_ID=123-1 RELEASE_EXPECTED_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
export MAINTENANCE_ACTION=retention_cleanup RETENTION_MAX_OBJECTS_PER_RUN=1
export RESOURCE_FINGERPRINT=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
jq -n --arg fingerprint "$RESOURCE_FINGERPRINT" --arg active "$RELEASE_EXPECTED_SHA" '
  ("d"*64) as $hash | {
    schema_version:1,status:"approved",operation:"retention_cleanup",
    active_release_id:"123-1",active_release_sha:$active,
    resource_fingerprint:$fingerprint,protection_fingerprint:$hash,
    protected_image_revisions:[],protected_image_digests:[],
    review_evidence:{backup_review_sha256:$hash,monitoring_review_sha256:$hash,
      authentication_review_sha256:$hash,isolated_docker_review_sha256:$hash},
    containers:[{id:$hash,snapshot_sha256:$hash,evidence:{release_state_sha256:$hash,
      writable_layer_review_sha256:$hash,startup_reference_review_sha256:$hash}}],images:[]
  }' > "$work_dir/review.json"
expect_failure() {
  local expected=$1
  shift
  if "$@" > "$work_dir/result" 2>&1; then echo "Unexpected success: $expected" >&2; exit 1; fi
  grep -Fq "RETENTION_REQUEST_FAIL=$expected" "$work_dir/result"
}
expect_failure reviewed_manifest_required bash "$script_dir/verify-retention-request.sh" "$script_dir/../release/retention-review.json"
bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
expect_failure workflow_identity_mismatch env GITHUB_REF=refs/heads/develop bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
expect_failure current_main_changed env TEST_REMOTE_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
expect_failure invalid_batch_limit env RETENTION_MAX_OBJECTS_PER_RUN=6 bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
expect_failure image_age_below_seven_days env RETENTION_IMAGE_MIN_AGE_SECONDS=10 bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
expect_failure review_identity_mismatch env RELEASE_ID=124-1 bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
expect_failure review_fingerprint_mismatch env RESOURCE_FINGERPRINT=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd bash "$script_dir/verify-retention-request.sh" "$work_dir/review.json"
jq '.operation="v2_finalize"' "$work_dir/review.json" > "$work_dir/finalize.json"
MAINTENANCE_ACTION=v2_finalize bash "$script_dir/verify-retention-request.sh" "$work_dir/finalize.json"
echo 'RETENTION_REQUEST_TEST=PASS cases=9 production_connections=0'
