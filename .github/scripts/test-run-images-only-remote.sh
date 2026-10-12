#!/usr/bin/env bash
set -Eeuo pipefail
# Transport orchestration with a local SSH replacement. Real admission, restore
# evidence validation and persistent gate transitions run; no host is contacted.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/scripts" "$test_root/release" "$test_root/bin"
cd -- "$test_root"
for file in run-images-only-remote.sh verify-images-only-request.sh images-only-approval.sh \
  verify-image-restore.sh retention-continuity.sh select-images-only-review.sh retention-predelete-recovery.sh; do
  cp -- "$script_dir/$file" "$test_root/scripts/$file"
done
cat > "$test_root/scripts/production-runtime-discovery.sh" <<'DISCOVERY'
xboard_find_compose_anchor() { XBOARD_ANCHOR_WORKDIR=$TEST_REMOTE_WORKDIR; }
DISCOVERY
for file in release-state.sh retention-safety.sh image-startup-references.sh; do : > "$test_root/scripts/$file"; done
printf 'echo TEST_AUDIT=PASS\n' > "$test_root/scripts/audit-xboard-images-only.sh"
cat > "$test_root/scripts/audit-retirement-readiness.sh" <<'READINESS'
case "$retirement_scope" in
  full) echo TEST_RETIREMENT_AUDIT=PASS ;;
  gate) echo TEST_GATE_AUDIT=PASS ;;
  *) exit 97 ;;
esac
READINESS
cat > "$test_root/scripts/cleanup-xboard-images-only.sh" <<'CLEANUP'
echo TEST_CLEANUP=PASS
actual_hash=$(printf '%s' "$RETENTION_REVIEW_MANIFEST_B64" | base64 -d | sha256sum | awk '{print $1}')
[[ "$actual_hash" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] || exit 97
printf 'TEST_CLEANUP_REVIEW_SHA256=%s\n' "$actual_hash"
printf '%s' "$RETENTION_REVIEW_MANIFEST_B64" | base64 -d | jq -r '"TEST_CLEANUP_IMAGE="+.images[0].id'
CLEANUP
cat > "$test_root/scripts/ssh-with-password.sh" <<'SSH'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'LOCAL_SSH_BOUNDARY\n' >> "$TEST_SSH_LOG"
command=${!#}
[[ "$command" == "EXPECTED_WORKFLOW_SHA='"* && "$command" == *' timeout --signal=TERM --kill-after=30s '* &&
   "$command" == *'s bash -se' ]] || exit 97
cat > "$TEST_SSH_PAYLOAD"
# Only the real transport-produced, schema-validated command is interpreted.
# Discovery above maps its target to our private temporary directory.
bash -c "$command" < "$TEST_SSH_PAYLOAD"
SSH
cat > "$test_root/bin/git" <<'GIT'
#!/usr/bin/env bash
case "$1" in
  rev-parse) printf '%s\n' "$GITHUB_SHA" ;;
  diff|merge-base) exit 0 ;;
  status) printf '%s' "${TEST_STATUS:-}" ;;
  submodule) printf ' clean\n' ;;
  ls-remote) printf '%s\trefs/heads/main\n' "${TEST_REMOTE_HEAD:-$GITHUB_SHA}" ;;
  *) exit 97 ;;
esac
GIT
for tool in ssh docker curl systemctl; do
  printf '#!/usr/bin/env bash\nprintf "FORBIDDEN_EXTERNAL_COMMAND\\n" >&2\nexit 97\n' > "$test_root/bin/$tool"
done
chmod +x "$test_root/bin/"*
export PATH="$test_root/bin:$PATH"
export GITHUB_REPOSITORY=Hao-Monster/Xboardme GITHUB_REF=refs/heads/main
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa EXPECTED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export GITHUB_WORKFLOW_SHA=$GITHUB_SHA GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=1
export GITHUB_WORKFLOW_REF=Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main
export IMAGE_CLEANUP_ACTION=cleanup
export IMAGE_CLEANUP_REVIEW_ID=default GITHUB_EVENT_NAME=workflow_dispatch
export DEPLOY_HOST=example.invalid DEPLOY_PORT=22 DEPLOY_USER=fixture
export POST_EVIDENCE_SHA256="$(printf 'f%.0s' {1..64})"
default_review="$test_root/scripts/../release/image-cleanup-review.json"
review=$default_review
jq -n --argjson now "$(date +%s)" --arg sha "$GITHUB_SHA" '
  ("c"*64) as $h | {
    schema_version:3,status:"approved",operation:"image_cleanup",active_release_id:"123-1",active_release_sha:$sha,
    resource_fingerprint:$h,protection_fingerprint:$h,protected_image_revisions:[$sha],
    protected_image_digests:[("ghcr.io/hao-monster/xboardme@sha256:"+$h)],
    review_evidence:{scope_review_sha256:$h,isolated_docker_review_sha256:$h},
    audit_completed_at_epoch:($now-10),approval_expires_at_epoch:($now+3600),max_image_size_bytes:1024,
    containers:[],images:[{id:("sha256:"+$h),revision:("b"*40),restore_digest:("ghcr.io/hao-monster/xboardme@sha256:"+$h),
      snapshot_sha256:$h,evidence:{startup_reference_review_sha256:$h}}],
    monitoring:{mode:"passive_runtime_v1",active_port:7002,baseline_seconds:120,post_delete_observe_seconds:300,
      sample_interval_seconds:10,heartbeat_max_age_seconds:30,
      caddy:{unit:"caddy.service",invocation_id:("d"*32),main_pid:42,active_enter_monotonic_usec:1000},
      protected_containers:[range(1;7)|{id:((tostring)*64),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}],
      resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,max_io_full_avg10:1,max_cpu_some_avg10:10}}
  }' > "$test_root/passive.json"
new_case() {
  local name=$1
  review=$default_review
  export TEST_REMOTE_WORKDIR="$test_root/$name/host" RUNNER_TEMP="$test_root/$name/runner"
  export TEST_SSH_LOG="$test_root/$name/ssh.log" TEST_SSH_PAYLOAD="$test_root/$name/payload"
  mkdir -p "$TEST_REMOTE_WORKDIR/.codex-v2-release" "$RUNNER_TEMP"
  chmod 700 "$TEST_REMOTE_WORKDIR/.codex-v2-release"
  : > "$TEST_REMOTE_WORKDIR/.codex-v2-release/deploy.lock"
  : > "$TEST_REMOTE_WORKDIR/.codex-v2-release/image-cleanup-gate.lock"
  chmod 600 "$TEST_REMOTE_WORKDIR/.codex-v2-release/"*.lock
  : > "$TEST_SSH_LOG"
  cp "$test_root/passive.json" "$review"
  gate="$TEST_REMOTE_WORKDIR/.codex-v2-release/image-cleanup-gate.json"
  result="$test_root/$name/result"
  write_gate RUNNING
}
write_gate() {
  jq -n --slurpfile review "$review" --arg workflow "$GITHUB_SHA" --arg status "$1" \
    --arg hash "$(sha256sum < "$review" | awk '{print $1}')" --argjson owner "$EUID" '
    $review[0] as $r | {schema_version:2,monitoring_mode:"passive_runtime_v1",status:$status,
      owner_uid:$owner,session_id:"11111111-1111-1111-1111-111111111111",workflow_sha:$workflow,review_sha256:$hash,
      active_release_sha:$r.active_release_sha,active_release_id:$r.active_release_id,
      protection_fingerprint:$r.protection_fingerprint,image_id:$r.images[0].id,
      baseline_seconds:120,observe_seconds:300,sample_interval_seconds:10,started_at_uptime:0,sample_count:0,
      cleanup_validated:false,failure_reason:(if $status=="HOLD" then "prior_failure" else null end),
      post_verification:(if $status=="HOLD" then "failed" else "pending" end),post_evidence_sha256:null,post_verified_at_uptime:null}
  ' > "$gate"
  chmod 600 "$gate"
}
restore_fixture() {
  jq -n --slurpfile review "$review" --arg workflow "$GITHUB_SHA" \
    --arg hash "$(sha256sum < "$review" | awk '{print $1}')" --argjson now "$(date +%s)" '
    $review[0].images[0] as $i | {schema_version:1,result:"PASS",workflow_sha:$workflow,run_id:"123",run_attempt:"1",
      review_sha256:$hash,platform:"linux/amd64",restore_digest:$i.restore_digest,revision:$i.revision,
      source:"https://github.com/Hao-Monster/Xboardme",expected_image_id:$i.id,observed_image_id:$i.id,
      id_semantics:"image_id",verified_at_epoch:$now}' > "$RUNNER_TEMP/images-only-restore.json"
  chmod 600 "$RUNNER_TEMP/images-only-restore.json"
}
invoke() { bash "$test_root/scripts/run-images-only-remote.sh" "$1" > "$result" 2>&1; }
unchanged_hash() { sha256sum < "$gate" | awk '{print $1}'; }
assert_absent() {
  local pattern=$1 file=$2 status
  if grep -Eq "$pattern" "$file"; then
    echo "IMAGE_TRANSPORT_TEST_FAIL=forbidden_payload:$pattern" >&2; exit 1
  else
    status=$?
    [[ "$status" == 1 ]] || { echo 'IMAGE_TRANSPORT_TEST_FAIL=unreadable_evidence' >&2; exit 1; }
  fi
}
cases=0
for operation in cleanup post-success; do
  new_case "missing_restore_$operation"
  original=$(unchanged_hash)
  if invoke "$operation"; then echo "unexpected success:$operation" >&2; exit 1; fi
  grep -Fxq 'IMAGE_RESTORE_FAIL=invalid_restore_evidence' "$result"
  [[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
  ((cases+=1))
done
new_case missing_restore_failure
if ! invoke post-failure; then cat "$result" >&2; echo 'IMAGE_TRANSPORT_TEST_FAIL=post_failure_depends_on_restore' >&2; exit 1; fi
jq -e '.status=="HOLD" and .post_verification=="failed" and .failure_reason=="independent_post_verification_failed"' "$gate" >/dev/null
[[ $(wc -l < "$TEST_SSH_LOG") == 1 ]]
grep -Fxq 'RETENTION_CONTINUITY_POST_VERIFICATION=failure' "$result"
assert_absent 'SSHPASS|GHCR_TOKEN|DEPLOY_HOST' "$TEST_SSH_PAYLOAD"
((cases+=1))

new_case completed_pending_missing_restore
jq '.status="COMPLETE" | .cleanup_validated=true | .started_at_uptime=1000 |
  .baseline_started_at_uptime=1000 | .baseline_completed_at_uptime=1120 |
  .intent_at_uptime=1121 | .removed_at_uptime=1122 | .observation_completed_at_uptime=1422 |
  .completed_at_uptime=1423 | .last_heartbeat_uptime=1423 | .sample_count=43' "$gate" > "$gate.next"
mv -- "$gate.next" "$gate"; chmod 600 "$gate"
invoke post-failure
jq -e '.status=="HOLD" and .post_verification=="failed"' "$gate" >/dev/null
((cases+=1))

new_case existing_hold
write_gate HOLD
original=$(unchanged_hash)
invoke post-failure
[[ $(unchanged_hash) == "$original" ]]
restore_fixture
if invoke post-success; then echo 'unexpected HOLD promotion' >&2; exit 1; fi
[[ $(unchanged_hash) == "$original" ]]
((cases+=1))

new_case stale_main
original=$(unchanged_hash)
if TEST_REMOTE_HEAD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb invoke post-failure; then exit 1; fi
grep -Fxq 'IMAGE_REQUEST_FAIL=stale_main' "$result"
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))

new_case new_main_old_hold
write_gate HOLD
original=$(unchanged_hash)
if GITHUB_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb EXPECTED_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
  GITHUB_WORKFLOW_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb invoke post-failure; then exit 1; fi
grep -Fxq 'RETENTION_CONTINUITY_FAIL=post_verification_identity_changed' "$result"
[[ $(unchanged_hash) == "$original" ]]
source "$script_dir/retention-continuity.sh"
if retention_continuity_admit_deployment "$TEST_REMOTE_WORKDIR" > "$result" 2>&1; then exit 1; fi
grep -Fxq 'RETENTION_CONTINUITY_FAIL=cleanup_running_or_held' "$result"
((cases+=1))

new_case wrong_review_gate
jq '.review_sha256=("9"*64)' "$gate" > "$gate.next"
mv -- "$gate.next" "$gate"; chmod 600 "$gate"
original=$(unchanged_hash)
if invoke post-failure; then exit 1; fi
grep -Fxq 'RETENTION_CONTINUITY_FAIL=post_verification_identity_changed' "$result"
[[ $(unchanged_hash) == "$original" ]]
((cases+=1))

new_case expired_approval
jq '.approval_expires_at_epoch=(.audit_completed_at_epoch+1)' "$review" > "$review.next"
mv -- "$review.next" "$review"
original=$(unchanged_hash)
if invoke post-failure; then exit 1; fi
grep -Fxq 'IMAGE_REQUEST_FAIL=approved_fresh_review_required' "$result"
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))

new_case legacy_without_restore
jq '.schema_version=2 | del(.images[0].restore_digest,.images[0].revision,.monitoring.mode,.monitoring.active_port) |
  .monitoring.baseline_seconds=600 | .monitoring.post_delete_observe_seconds=900 |
  ("c"*64) as $h |
  .review_evidence={backup_review_sha256:$h,monitoring_review_sha256:$h,authentication_review_sha256:$h,isolated_docker_review_sha256:$h} |
  .monitoring.public={url:"https://example.invalid/health",expected_status:200,body_sha256:$h,max_latency_ms:2000} |
  .monitoring.origin={url:"http://127.0.0.1:7002/health",expected_status:200,body_sha256:$h,max_latency_ms:2000} |
  .monitoring.evidence={read_only_endpoints_sha256:$h,production_baseline_sha256:$h,long_connections_sha256:$h,
    natural_traffic_sha256:$h,resource_overhead_sha256:$h} |
  .monitoring.live_signals={transport:"host_json_v1",source_review_sha256:$h,max_age_seconds:20}' \
  "$review" > "$review.next"
mv -- "$review.next" "$review"
invoke cleanup
grep -Fxq 'TEST_CLEANUP=PASS' "$result"
[[ $(wc -l < "$TEST_SSH_LOG") == 1 && ! -e "$RUNNER_TEMP/images-only-restore.json" ]]
((cases+=1))
# Read-only retirement audit never needs restore evidence and must never append
# the cleanup executor or mutate the existing gate, even when it is held.
new_case retirement_audit
write_gate HOLD
original=$(unchanged_hash)
IMAGE_CLEANUP_ACTION=retirement-audit invoke retirement-audit
grep -Fxq 'TEST_AUDIT=PASS' "$result"
grep -Fxq 'TEST_RETIREMENT_AUDIT=PASS' "$result"
assert_absent 'TEST_CLEANUP=' "$result"
assert_absent 'TEST_CLEANUP=' "$TEST_SSH_PAYLOAD"
assert_absent 'RETENTION_REVIEW_MANIFEST_B64=' "$TEST_SSH_PAYLOAD"
[[ $(wc -l < "$TEST_SSH_LOG") == 1 && $(unchanged_hash) == "$original" &&
   ! -e "$RUNNER_TEMP/images-only-restore.json" ]]
((cases+=1))
for operation in cleanup post-success post-failure; do
  new_case "retirement_rejects_$operation"
  original=$(unchanged_hash)
  if IMAGE_CLEANUP_ACTION=retirement-audit invoke "$operation"; then exit 1; fi
  [[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
  ((cases+=1))
done
for action in audit cleanup; do
  new_case "retirement_action_mismatch_$action"
  restore_fixture
  original=$(unchanged_hash)
  if IMAGE_CLEANUP_ACTION=$action invoke retirement-audit; then exit 1; fi
  [[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
  ((cases+=1))
done
new_case retirement_retry
original=$(unchanged_hash)
if IMAGE_CLEANUP_ACTION=retirement-audit GITHUB_RUN_ATTEMPT=2 invoke retirement-audit; then exit 1; fi
grep -Fxq 'IMAGE_REQUEST_FAIL=manual_first_attempt_required' "$result"
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))
new_case retirement_scheduled
original=$(unchanged_hash)
if IMAGE_CLEANUP_ACTION=retirement-audit GITHUB_EVENT_NAME=schedule invoke retirement-audit; then exit 1; fi
grep -Fxq 'IMAGE_REQUEST_FAIL=manual_first_attempt_required' "$result"
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))
new_case retirement_dirty
original=$(unchanged_hash)
if IMAGE_CLEANUP_ACTION=retirement-audit TEST_STATUS='?? unreviewed.json' invoke retirement-audit; then exit 1; fi
grep -Fxq 'IMAGE_REQUEST_FAIL=dirty_checkout' "$result"
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))

# Gate-only audit binds its own action and scope. It cannot be redirected to
# metadata inspection or any persistent gate writer, and needs no restore file.
new_case gate_audit
write_gate HOLD
original=$(unchanged_hash)
IMAGE_CLEANUP_ACTION=gate-audit retirement_scope=full invoke gate-audit
grep -Fxq 'TEST_AUDIT=PASS' "$result"
grep -Fxq 'TEST_GATE_AUDIT=PASS' "$result"
assert_absent 'TEST_RETIREMENT_AUDIT=PASS|TEST_CLEANUP=' "$result"
assert_absent 'TEST_CLEANUP=|RETENTION_REVIEW_MANIFEST_B64=' "$TEST_SSH_PAYLOAD"
[[ $(wc -l < "$TEST_SSH_LOG") == 1 && $(unchanged_hash) == "$original" &&
   ! -e "$RUNNER_TEMP/images-only-restore.json" ]]
((cases+=1))
for operation in audit retirement-audit cleanup post-success post-failure; do
  new_case "gate_rejects_$operation"
  original=$(unchanged_hash)
  if IMAGE_CLEANUP_ACTION=gate-audit invoke "$operation"; then exit 1; fi
  [[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
  ((cases+=1))
done
for action in audit retirement-audit cleanup; do
  new_case "gate_action_mismatch_$action"
  restore_fixture
  original=$(unchanged_hash)
  if IMAGE_CLEANUP_ACTION=$action invoke gate-audit; then exit 1; fi
  [[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
  ((cases+=1))
done
for fault in retry scheduled dirty; do
  new_case "gate_$fault"
  original=$(unchanged_hash)
  case "$fault" in
    retry) if IMAGE_CLEANUP_ACTION=gate-audit GITHUB_RUN_ATTEMPT=2 invoke gate-audit; then exit 1; fi ;;
    scheduled) if IMAGE_CLEANUP_ACTION=gate-audit GITHUB_EVENT_NAME=schedule invoke gate-audit; then exit 1; fi ;;
    dirty) if IMAGE_CLEANUP_ACTION=gate-audit TEST_STATUS='?? unreviewed.json' invoke gate-audit; then exit 1; fi ;;
  esac
  [[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
  ((cases+=1))
done

# The runner and remote payload must bind the selected bytes, not the default
# manifest. The remote executor remains synthetic; transport/restore are real.
new_case selected_review
mkdir -p "$test_root/release/image-cleanup-reviews"
first="$test_root/scripts/../release/image-cleanup-reviews/001.json"
selected="$test_root/scripts/../release/image-cleanup-reviews/002.json"
jq '.sequence={schema_version:1,index:"001",predecessor_review_sha256:null,audit_artifact_sha256:("d"*64)} |
  .recovery={schema_version:1,mode:"predelete_hold_v1",gate_sha256:("e"*64),plan_sha256:("f"*64),
    review_sha256:("9"*64),workflow_sha:("8"*40),session_id:"11111111-1111-1111-1111-111111111111",
    image_id:.images[0].id}' "$review" > "$first"
jq --arg previous "$(sha256sum < "$first" | awk '{print $1}')" '
  .sequence.index="002" | .sequence.predecessor_review_sha256=$previous |
  .images[0].id=("sha256:"+("e"*64)) | del(.recovery)' "$first" > "$selected"
# A valid default-image proof is deliberately wrong for selected review 002.
restore_fixture
original=$(unchanged_hash)
if IMAGE_CLEANUP_REVIEW_ID=002 invoke cleanup; then exit 1; fi
grep -Fxq 'IMAGE_RESTORE_FAIL=invalid_restore_evidence' "$result"
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))
review=$selected
restore_fixture
IMAGE_CLEANUP_REVIEW_ID=002 invoke cleanup
grep -Fxq "TEST_CLEANUP_REVIEW_SHA256=$(sha256sum < "$selected" | awk '{print $1}')" "$result"
grep -Fxq "TEST_CLEANUP_IMAGE=sha256:$(printf 'e%.0s' {1..64})" "$result"
assert_absent 'TEST_RETIREMENT_AUDIT=PASS' "$TEST_SSH_PAYLOAD"
[[ $(wc -l < "$TEST_SSH_LOG") == 1 && $(unchanged_hash) == "$original" ]]
((cases+=1))
new_case invalid_review_identifier
original=$(unchanged_hash)
if IMAGE_CLEANUP_REVIEW_ID='../001' invoke cleanup; then exit 1; fi
[[ ! -s "$TEST_SSH_LOG" && $(unchanged_hash) == "$original" ]]
((cases+=1))
echo "IMAGE_TRANSPORT_TEST=PASS cases=$cases production_connections=0 real_registry_pulls=0"
