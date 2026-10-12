#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir "$test_root/bin"
cat > "$test_root/bin/git" <<'GIT'
#!/usr/bin/env bash
case "$1" in
  rev-parse) printf '%s\n' "${TEST_HEAD:-$GITHUB_SHA}" ;;
  diff) [[ ${TEST_DIRTY:-false} == false ]] ;;
  submodule) printf '%s\n' "${TEST_SUBMODULE:- clean}" ;;
  ls-remote) printf '%s\trefs/heads/main\n' "${TEST_REMOTE:-$GITHUB_SHA}" ;;
  merge-base) [[ ${TEST_ANCESTOR:-true} == true ]] ;;
  *) exit 97 ;;
esac
GIT
chmod +x "$test_root/bin/git"
export PATH="$test_root/bin:$PATH"
export GITHUB_REPOSITORY=Hao-Monster/Xboardme GITHUB_REF=refs/heads/main
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa EXPECTED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export GITHUB_WORKFLOW_SHA=$GITHUB_SHA
export GITHUB_WORKFLOW_REF=Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main
export IMAGE_CLEANUP_ACTION=audit
check_failure() {
  local expected=$1
  shift
  if "$@" > "$test_root/result" 2>&1; then echo "unexpected success: $expected" >&2; exit 1; fi
  grep -Fq "IMAGE_REQUEST_FAIL=$expected" "$test_root/result"
}
bash "$script_dir/verify-images-only-request.sh"
check_failure workflow_identity env GITHUB_REF=refs/heads/develop bash "$script_dir/verify-images-only-request.sh"
check_failure workflow_identity env GITHUB_WORKFLOW_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$script_dir/verify-images-only-request.sh"
check_failure workflow_identity env GITHUB_REPOSITORY=other/repo bash "$script_dir/verify-images-only-request.sh"
check_failure invalid_action env IMAGE_CLEANUP_ACTION=finalize bash "$script_dir/verify-images-only-request.sh"
check_failure checkout_mismatch env TEST_HEAD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$script_dir/verify-images-only-request.sh"
check_failure dirty_checkout env TEST_DIRTY=true bash "$script_dir/verify-images-only-request.sh"
check_failure dirty_submodule env TEST_SUBMODULE='+changed' bash "$script_dir/verify-images-only-request.sh"
check_failure stale_main env TEST_REMOTE=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$script_dir/verify-images-only-request.sh"
check_failure approved_fresh_review_required env IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh"
jq -n --argjson now "$(date +%s)" --arg sha "$GITHUB_SHA" '
  ("c"*64) as $h | {
    schema_version:2,status:"approved",operation:"image_cleanup",active_release_id:"123-1",active_release_sha:$sha,
    resource_fingerprint:$h,protection_fingerprint:$h,protected_image_revisions:[$sha],
    protected_image_digests:[("ghcr.io/hao-monster/xboardme@sha256:"+$h)],
    review_evidence:{backup_review_sha256:$h,monitoring_review_sha256:$h,authentication_review_sha256:$h,isolated_docker_review_sha256:$h},
    audit_completed_at_epoch:($now-10),approval_expires_at_epoch:($now+3600),max_image_size_bytes:1024,
    containers:[],images:[{id:("sha256:"+$h),snapshot_sha256:$h,evidence:{startup_reference_review_sha256:$h}}],
    monitoring:{baseline_seconds:600,post_delete_observe_seconds:900,sample_interval_seconds:10,heartbeat_max_age_seconds:30,
      public:{url:"https://example.invalid/health",expected_status:200,body_sha256:$h,max_latency_ms:2000},
      origin:{url:"http://127.0.0.1:7002/health",expected_status:200,body_sha256:$h,max_latency_ms:2000},
      caddy:{unit:"caddy.service",invocation_id:("d"*32),main_pid:42,active_enter_monotonic_usec:1000},
      protected_containers:[range(1;7)|{id:((tostring)*64),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}],
      resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,max_io_full_avg10:1,max_cpu_some_avg10:10},
      evidence:{read_only_endpoints_sha256:$h,production_baseline_sha256:$h,long_connections_sha256:$h,natural_traffic_sha256:$h,resource_overhead_sha256:$h},
      live_signals:{transport:"host_json_v1",source_review_sha256:$h,max_age_seconds:20}}
  }' > "$test_root/valid.json"
IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/valid.json"
check_failure active_not_in_history env IMAGE_CLEANUP_ACTION=cleanup TEST_ANCESTOR=false bash "$script_dir/verify-images-only-request.sh" "$test_root/valid.json"
jq '.monitoring.baseline_seconds=1' "$test_root/valid.json" > "$test_root/invalid.json"
check_failure monitoring_review_required env IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/invalid.json"
jq '.approval_expires_at_epoch=(.audit_completed_at_epoch+1)' "$test_root/valid.json" > "$test_root/expired.json"
check_failure approved_fresh_review_required env IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/expired.json"
jq '.schema_version=3 | .monitoring.mode="passive_runtime_v1" |
  .monitoring.active_port=7002 | .monitoring.baseline_seconds=120 | .monitoring.post_delete_observe_seconds=300 |
  del(.monitoring.public,.monitoring.origin,.monitoring.live_signals,.monitoring.evidence) |
  .review_evidence={scope_review_sha256:("c"*64),isolated_docker_review_sha256:("c"*64)} |
  .images[0].revision=("b"*40) |
  .images[0].restore_digest=("ghcr.io/hao-monster/xboardme@sha256:"+("c"*64))' \
  "$test_root/valid.json" > "$test_root/passive.json"
IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/passive.json" > "$test_root/result"
grep -Fxq 'IMAGE_REQUEST_MONITORING_MODE=passive_runtime_v1' "$test_root/result"
jq '.monitoring.active_port=0' "$test_root/passive.json" > "$test_root/invalid.json"
check_failure monitoring_review_required env IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/invalid.json"
jq '.monitoring.post_delete_observe_seconds=299' "$test_root/passive.json" > "$test_root/invalid.json"
check_failure monitoring_review_required env IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/invalid.json"
jq '.schema_version=2' "$test_root/passive.json" > "$test_root/invalid.json"
check_failure approved_fresh_review_required env IMAGE_CLEANUP_ACTION=cleanup bash "$script_dir/verify-images-only-request.sh" "$test_root/invalid.json"
echo 'IMAGE_REQUEST_TEST=PASS cases=18 production_connections=0'
