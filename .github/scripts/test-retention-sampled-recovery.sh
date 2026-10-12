#!/usr/bin/env bash
set -Eeuo pipefail
# Real Bash/jq/files/locks/atomic gate paths; all host data and observations are
# synthetic. No Docker/SSH/Caddy/production call, no real 120/300-second claim.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-continuity.sh"
source "$script_dir/retention-predelete-recovery.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
cases=0
fail() { printf 'SAMPLED_RECOVERY_TEST=FAIL case=%s reason=%s\n' "${case_name:-setup}" "$1" >&2; exit 1; }
hash_file() { sha256sum "$1" | cut -d' ' -f1; }
hash_text() { printf '%s\n' "$1" | sha256sum | cut -d' ' -f1; }
# Loading the module must have no effects, not even read-only host commands.
_rc_run() { fail module_source_side_effect; }
source "$script_dir/retention-sampled-recovery.sh"
_rc_run() { timeout --signal=TERM --kill-after=1s 3s "$@"; }
_rc_now() { printf '%s\n' "$test_now"; }

mutate() {
  local file=$1 filter=$2
  jq -cS "$filter" "$file" > "$root/mutation.json"
  chmod 600 "$root/mutation.json"
  mv -- "$root/mutation.json" "$file"
}
seal_proof() {
  local new_hash
  new_hash=$(hash_file "$proof_input")
  mutate "$review" ".recovery.proof_sha256=\"$new_hash\""
  proof="$root/image-cleanup-proof-$new_hash.json"
  RETENTION_SAMPLED_PROOF_B64=$(base64 -w0 < "$proof_input")
  EXPECTED_RETENTION_REVIEW_SHA256=$(hash_file "$review")
}
fixture() {
  case_name=$1
  workdir="$test_root/case-$cases"
  root="$workdir/.codex-v2-release"
  mkdir -p "$root/123-1"
  chmod 700 "$root" "$root/123-1"
  exec 9>"$root/deploy.lock"
  chmod 600 "$root/deploy.lock"
  flock -n 9
  review="$root/review.json" gate="$root/image-cleanup-gate.json"
  old_review_hash=$(printf 'b%.0s' {1..64})
  old_workflow=$(printf 'c%.0s' {1..40})
  EXPECTED_WORKFLOW_SHA=$(printf 'a%.0s' {1..40})
  EXPECTED_ACTIVE_RELEASE_ID=123-1
  EXPECTED_ACTIVE_RELEASE_SHA=$(printf 'd%.0s' {1..40})
  XBOARD_ACTIVE_PORT=7002
  target="sha256:$(printf 'e%.0s' {1..64})"
  plan="$root/image-cleanup-plan-$old_review_hash.json"
  inventory="$root/inventory.json" startup="$root/startup.json" state="$root/123-1/state.json"
  jq -cnS --arg active "$EXPECTED_ACTIVE_RELEASE_SHA" '{schema_version:2,release_id:"123-1",release_sha:$active,
    project_name:"xboard-v2-123-1",traffic_state:"active_v2",rollback_supported:"true",
    legacy_release_sha:("1"*40),legacy_anchor_id:("2"*64),legacy_web_id:("3"*64),legacy_ws_id:("4"*64),
    legacy_edge_id:("5"*64),legacy_horizon_id:("6"*64),legacy_scheduler_id:("7"*64),maintenance_container:"fixture"}' > "$state"
  jq -cnS --arg target "$target" '{containers:[range(1;25)|{id:(tostring|"0"*(64-length)+.),
      image_id:("sha256:"+("f"*64)),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}],
    images:[{id:$target,size_bytes:1024,created_at:"2026-09-01T00:00:00Z",revision:("e"*40),
      source:"https://github.com/Hao-Monster/Xboardme",tags:["ghcr.io/hao-monster/xboardme:old"],
      digests:[("ghcr.io/hao-monster/xboardme@"+$target)]}]}' > "$inventory"
  jq -cnS '{files:[],image_refs:[]}' > "$startup"
  protection=$(jq -cnS --slurpfile i "$inventory" '{upstream:"127.0.0.1:7002",caddy_file:"/etc/caddy/Caddyfile",
    caddy_sha256:("9"*64),app_data_identity:"1:42",redis_volume:"fixture-redis",containers:$i[0].containers}')
  protection_hash=$(hash_text "$protection")
  resource_hash=$(jq -cnS --slurpfile i "$inventory" --slurpfile s "$state" --argjson p "$protection" '
    {inventory:$i[0],protection:$p,lifecycle:($s[0]|{release_id,release_sha,traffic_state,rollback_supported,
      legacy_release_sha,legacy_anchor_id,legacy_web_id,legacy_ws_id,legacy_edge_id,legacy_horizon_id,
      legacy_scheduler_id,maintenance_container})}' | sha256sum | cut -d' ' -f1)
  jq -cnS --arg workflow "$old_workflow" --arg review "$old_review_hash" --arg target "$target" \
    --arg active "$EXPECTED_ACTIVE_RELEASE_SHA" --arg protection "$protection_hash" --argjson owner "$EUID" '{
    schema_version:2,monitoring_mode:"passive_runtime_v1",status:"HOLD",owner_uid:$owner,
    session_id:"12345678-1234-1234-1234-123456789abc",workflow_sha:$workflow,review_sha256:$review,
    active_release_id:"123-1",active_release_sha:$active,protection_fingerprint:$protection,image_id:$target,
    baseline_seconds:120,observe_seconds:300,sample_interval_seconds:10,started_at_uptime:1000,
    last_heartbeat_uptime:1180,baseline_started_at_uptime:1000,baseline_completed_at_uptime:1120,
    intent_at_uptime:null,removed_at_uptime:null,observation_completed_at_uptime:null,completed_at_uptime:null,
    sample_count:19,cleanup_validated:false,failure_reason:"resource_limit_or_source_failed",
    post_verification:"pending",post_evidence_sha256:null,post_verified_at_uptime:null}' > "$gate"
  jq -cnS --arg review "$old_review_hash" --arg workflow "$old_workflow" --arg state "$(hash_file "$state")" \
    --slurpfile i "$inventory" --slurpfile s "$startup" '{schema_version:2,operation:"image_cleanup",
    review_sha256:$review,workflow_sha:$workflow,active_state_sha256:$state,inventory:$i[0],startup:$s[0],
    containers:[],images:[$i[0].images[0]]}' > "$plan"
  image_hash=$(jq -cS '.images[0]' "$plan" | sha256sum | cut -d' ' -f1)
  jq -cnS --arg active "$EXPECTED_ACTIVE_RELEASE_SHA" --arg target "$target" --arg old_workflow "$old_workflow" \
    --arg old_review "$old_review_hash" --arg gate_hash "$(hash_file "$gate")" --arg plan_hash "$(hash_file "$plan")" \
    --arg image_hash "$image_hash" --arg startup_hash "$(hash_file "$startup")" \
    --arg protection "$protection_hash" --arg resource "$resource_hash" --slurpfile i "$inventory" '{
    schema_version:3,status:"approved",operation:"image_cleanup",active_release_id:"123-1",active_release_sha:$active,
    resource_fingerprint:$resource,protection_fingerprint:$protection,containers:[],
    images:[{id:$target,snapshot_sha256:$image_hash,evidence:{startup_reference_review_sha256:$startup_hash}}],
    monitoring:{mode:"passive_runtime_v1",active_port:7002,baseline_seconds:120,post_delete_observe_seconds:300,
      sample_interval_seconds:10,heartbeat_max_age_seconds:30,
      caddy:{unit:"caddy.service",invocation_id:("8"*32),main_pid:42,active_enter_monotonic_usec:1000000},
      protected_containers:[$i[0].containers[]|{id,status,started_at,restart_count}],
      resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,max_io_full_avg10:1,max_cpu_some_avg10:10}},
    recovery:{schema_version:2,mode:"predelete_sampled_hold_v2",gate_sha256:$gate_hash,plan_sha256:$plan_hash,
      proof_sha256:("1"*64),review_sha256:$old_review,workflow_sha:$old_workflow,image_id:$target,
      session_id:"12345678-1234-1234-1234-123456789abc"}}' > "$review"
  proof_input="$root/proof-input.json"
  jq -cnS --slurpfile g "$gate" --slurpfile r "$review" --arg inventory "$(hash_file "$inventory")" \
    --arg startup "$(hash_file "$startup")" --arg state "$(hash_file "$state")" '{
    schema_version:1,mode:"sampled_predelete_trace_v1",gate_sha256:$r[0].recovery.gate_sha256,
    plan_sha256:$r[0].recovery.plan_sha256,gate:$g[0],source:{repository:"Hao-Monster/Xboardme",run_id:"123456",
      run_attempt:1,workflow_sha:$r[0].recovery.workflow_sha,event:"workflow_dispatch",branch:"main",conclusion:"failure",
      operation_artifact_sha256:("1"*64),timeline_sha256:("2"*64),alignment_report_sha256:("3"*64),raw_workflow_log_sha256:("4"*64)},
    audit:{active_state_sha256:$state,inventory_sha256:$inventory,startup_sha256:$startup,
      protection_fingerprint:$r[0].protection_fingerprint,resource_fingerprint:$r[0].resource_fingerprint,container_count:24,image_count:1},
    trace:{sample_uptimes:[range(1000;1190;10)],baseline_after_samples:13,baseline_wall_nanoseconds:"120000000000"}}' > "$proof_input"
  chmod 600 "$review" "$gate" "$plan" "$inventory" "$startup" "$state" "$proof_input"
  seal_proof
  _rc_paths "$workdir"
  test_now=2000
  RC_ARMED=false RC_OBSERVER_PID=''
  retention_snapshot_file=$inventory retention_startup_file=$startup active_state_file=$state
  fixture_protection=$protection
  retention_live_protection() { printf '%s\n' "$fixture_protection"; }
  old_gate_hash=$(hash_file "$gate") old_plan_hash=$(hash_file "$plan")
}
install() { retention_sampled_proof_install "$review"; }
validate() {
  retention_predelete_recovery_validate "$review" "$gate" "$plan" "$inventory" "$startup" "$state" "$protection" "$EXPECTED_WORKFLOW_SHA"
}
expect() {
  local wanted=$1 actual
  shift
  if "$@" > "$root/out" 2> "$root/err"; then actual=0; else actual=$?; fi
  [[ "$actual" == "$wanted" ]] || { cat "$root/err" >&2; fail "status_${actual}_expected_${wanted}"; }
  ((cases+=1))
}
unchanged() { [[ "$(hash_file "$gate")" == "$old_gate_hash" && "$(hash_file "$plan")" == "$old_plan_hash" ]] || fail old_evidence_changed; }

fixture missing_proof
expect 1 validate
expect 0 install
expect 0 install
expect 0 validate
unchanged

for expression in '.recovery.schema_version=1' '.recovery.mode="predelete_hold_v1"' 'del(.recovery.proof_sha256)' \
  '.recovery.proof_sha256=("0"*64)' '.recovery.extra=true' '.recovery.schema_version=3'; do
  fixture exact_mode_pair
  mutate "$review" "$expression"
  expect 1 validate
  unchanged
done

for expression in '.extra=true' '.mode="unrecognized"' '.gate.extra=true' '.source.branch="develop"' \
  '.source.run_attempt=2' '.source.run_id=123456' '.source.repository="other/repo"' '.source.conclusion="success"' \
  '.source.run_id="1000000000000000"' '.audit.image_count=8193' '.trace.baseline_wall_nanoseconds="1000000000000000"' \
  '.source.workflow_sha=("a"*40)' '.source.raw_workflow_log_sha256=("0"*64)' '.source.timeline_sha256="short"' \
  '.gate_sha256=("f"*64)' '.plan_sha256=("f"*64)' '.gate.owner_uid+=1' '.gate.sample_count=18' \
  '.audit.inventory_sha256=("f"*64)' '.audit.startup_sha256=("f"*64)' '.audit.active_state_sha256=("f"*64)' \
  '.audit.protection_fingerprint=("f"*64)' '.audit.resource_fingerprint=("f"*64)' \
  '.audit.container_count=23' '.audit.image_count=2' '.trace.sample_uptimes=[]' \
  '.trace.sample_uptimes[1]=1000' '.trace.sample_uptimes[1]=999' '.trace.sample_uptimes[1]=1031' \
  '.trace.sample_uptimes[0]=999' '.trace.sample_uptimes[-1]=1181' '.trace.sample_uptimes[4]=null' \
  '.trace.sample_uptimes[4]=1040.5' '.trace.sample_uptimes += [1190]' '.trace.sample_uptimes=[range(257)]' \
  '.trace.baseline_after_samples=12' '.trace.baseline_after_samples=15' '.trace.baseline_after_samples=20' \
  '.trace.baseline_wall_nanoseconds="122000000001"' '.trace.baseline_wall_nanoseconds="117999999999"' \
  '.trace.baseline_wall_nanoseconds="0120000000000"' '.trace.baseline_wall_nanoseconds=120000000000'; do
  fixture invalid_proof
  mutate "$proof_input" "$expression"
  seal_proof
  # Some invalidity is caught on installation, the remainder on real binding.
  invalid_proof() { install && validate; }
  expect 1 invalid_proof
  unchanged
done

for expression in '.sample_count=0' '.failure_reason="other_failure"' '.baseline_completed_at_uptime=1119' \
  '.baseline_started_at_uptime=999' '.last_heartbeat_uptime=1181' '.started_at_uptime=969' \
  '.started_at_uptime=1001' '.baseline_seconds=119' '.observe_seconds=299' '.status="RUNNING"' \
  '.cleanup_validated=true' '.post_verification="passed"'; do
  fixture ineligible_gate
  mutate "$gate" "$expression"
  mutate "$review" ".recovery.gate_sha256=\"$(hash_file "$gate")\""
  jq -cS --slurpfile g "$gate" --arg hash "$(hash_file "$gate")" '.gate=$g[0]|.gate_sha256=$hash' "$proof_input" > "$root/new-proof"
  mv -- "$root/new-proof" "$proof_input"
  seal_proof
  expect 0 install
  expect 1 validate
done
for key in intent_at_uptime removed_at_uptime observation_completed_at_uptime completed_at_uptime post_evidence_sha256 post_verified_at_uptime; do
  for mode in missing nonnull; do
    fixture explicit_null_required
    if [[ "$mode" == missing ]]; then expression="del(.$key)"; else expression=".$key=1200"; fi
    mutate "$gate" "$expression"
    mutate "$review" ".recovery.gate_sha256=\"$(hash_file "$gate")\""
    jq -cS --slurpfile g "$gate" --arg hash "$(hash_file "$gate")" '.gate=$g[0]|.gate_sha256=$hash' "$proof_input" > "$root/new-proof"
    mv -- "$root/new-proof" "$proof_input"
    seal_proof
    invalid_gate() { install && validate; }
    expect 1 invalid_gate
  done
done

for document in inventory startup state; do
  fixture current_document_drift
  expect 0 install
  mutate "${!document}" '.new_field=true'
  expect 1 validate
done
for file_kind in gate plan proof; do
  fixture extra_hardlink
  expect 0 install
  ln -- "${!file_kind}" "$root/unexplained-link"
  expect 1 validate
done
fixture proof_symlink
expect 0 install
mv -- "$proof" "$root/saved-proof"
ln -s "$root/saved-proof" "$proof"
expect 1 validate
expect 1 install
fixture proof_insecure
expect 0 install
chmod 644 "$proof"
expect 1 validate
expect 1 install
fixture proof_collision
printf 'different bytes\n' > "$proof"
chmod 600 "$proof"
expect 1 install
[[ $(< "$proof") == 'different bytes' ]] || fail collision_overwritten
fixture noncanonical
jq . "$proof_input" > "$root/pretty"
mv -- "$root/pretty" "$proof_input"
seal_proof
expect 1 install
fixture duplicate_keys
printf '{"schema_version":1,%s' "$(tail -c +2 "$proof_input")" > "$root/duplicate"
printf '\n' >> "$root/duplicate"
mv -- "$root/duplicate" "$proof_input"
seal_proof
expect 1 install
fixture overlarge
printf '%17000s' ' ' >> "$proof_input"
seal_proof
expect 1 install
fixture invalid_base64
RETENTION_SAMPLED_PROOF_B64='not-valid!'
expect 1 install
fixture wrong_current_clock
expect 0 install
test_now=1180
expect 1 validate
test_now=900
expect 1 validate
for nanoseconds in 118000000000 122000000000; do
  fixture nanosecond_tolerance_boundary
  mutate "$proof_input" ".trace.baseline_wall_nanoseconds=\"$nanoseconds\""
  seal_proof
  expect 0 install
  expect 0 validate
done
fixture maximum_sample_gap_boundary
mutate "$proof_input" '.trace.sample_uptimes[1]=1030|.trace.sample_uptimes[2]=1031|.trace.sample_uptimes[3]=1032'
seal_proof
expect 0 install
expect 0 validate
fixture proof_link_interrupted
link_interrupted() (
  _rc_run() {
    if [[ "$1" == rm && "${*: -1}" == "$RC_ROOT/.sampled-proof."* ]]; then
      # Kill only this installer's owned subshell, after its exact link exists.
      # EXIT cannot unlink the temporary, so the surviving two-link proof must
      # be rejected on retry rather than silently repaired or accepted.
      kill -KILL "$BASHPID"
      return 1
    fi
    timeout --signal=TERM --kill-after=1s 3s "$@"
  }
  if install; then return 0; else return 1; fi
)
expect 1 link_interrupted
[[ -f "$proof" && $(stat -Lc '%h' -- "$proof") == 2 ]] || fail crash_link_not_preserved
expect 1 install
expect 1 validate
unchanged

# Keep genuine begin/update/archive/admission paths. Only the isolated clock
# and host probes are replaced; production has no environment bypass for these.
_rc_start_observer() { _rc_sample >/dev/null; }
_rc_stop_observer() { :; }
_rc_wait() { test_now=$((test_now+10)); _rc_sample >/dev/null; }
_rc_probe() { :; }
_rc_image_absent() { return 0; }
fixture complete_new_session
expect 0 retention_continuity_begin "$review"
[[ "$test_now" == 2120 ]] || fail baseline_shortened
expect 0 jq -e '.started_at_uptime==2000 and .baseline_started_at_uptime==2000 and
  .baseline_completed_at_uptime==2120 and .last_heartbeat_uptime==2120 and .sample_count==13 and
  .intent_at_uptime==null and .removed_at_uptime==null and .observation_completed_at_uptime==null and
  .completed_at_uptime==null and .post_evidence_sha256==null and .post_verified_at_uptime==null' "$gate"
[[ "$(hash_file "$root/image-cleanup-gate-history-12345678-1234-1234-1234-123456789abc.json")" == "$old_gate_hash" ]] || fail old_gate_not_archived
[[ "$(hash_file "$plan")" == "$old_plan_hash" ]] || fail old_plan_changed
expect 1 retention_continuity_admit_deployment "$workdir"
expect 0 retention_continuity_intent "$target"
expect 0 retention_continuity_observe "$target"
[[ "$test_now" == 2420 ]] || fail post_window_shortened
expect 0 retention_continuity_complete
expect 1 retention_continuity_admit_deployment "$workdir"
expect 0 retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$(printf 'f%.0s' {1..64})"
expect 0 retention_continuity_admit_deployment "$workdir"

fixture no_deploy_lock
without_lock() ( exec 9>&-; retention_continuity_begin "$review"; )
expect 1 without_lock
unchanged
fixture archive_collision
printf 'foreign history\n' > "$root/image-cleanup-gate-history-12345678-1234-1234-1234-123456789abc.json"
chmod 600 "$root/image-cleanup-gate-history-12345678-1234-1234-1234-123456789abc.json"
expect 1 retention_continuity_begin "$review"
unchanged
fixture archive_copy_failure
copy_failure() (
  _rc_run() { [[ "$1" != cp ]] || return 1; timeout --signal=TERM --kill-after=1s 3s "$@"; }
  retention_continuity_begin "$review"
)
expect 1 copy_failure
unchanged
fixture interrupted_after_archive
replacement_interrupted() (
  _rc_run() {
    if [[ "$1" == mv && "${*: -1}" == "$RC_GATE" ]]; then kill -TERM "$BASHPID"; return 1; fi
    timeout --signal=TERM --kill-after=1s 3s "$@"
  }
  retention_continuity_begin "$review"
)
expect 1 replacement_interrupted
unchanged
[[ "$(hash_file "$root/image-cleanup-gate-history-12345678-1234-1234-1234-123456789abc.json")" == "$old_gate_hash" ]] || fail archive_missing_after_interrupt
# Retry uses exact old history; it cannot overwrite or reset the old gate.
expect 0 retention_continuity_begin "$review"

fixture locked_recheck_drift
recheck_drift() (
  printf '0\n' > "$root/protection-reads"
  retention_live_protection() {
    local reads
    reads=$(< "$root/protection-reads"); printf '%s\n' "$((reads+1))" > "$root/protection-reads"
    if ((reads==0)); then printf '%s\n' "$fixture_protection"; else printf '{}\n'; fi
  }
  retention_continuity_begin "$review"
)
expect 1 recheck_drift
unchanged

fixture gate_lock_contended
: > "$root/image-cleanup-gate.lock"
chmod 600 "$root/image-cleanup-gate.lock"
(
  exec 8>"$root/image-cleanup-gate.lock"
  flock -x 8
  : > "$root/lock-ready"
  sleep 10
) &
blocker=$!
for ((attempt=0;attempt<100;attempt++)); do [[ -e "$root/lock-ready" ]] && break; sleep 0.02; done
[[ -e "$root/lock-ready" ]] || fail lock_helper_not_ready
expect 1 retention_continuity_begin "$review"
kill -TERM "$blocker" 2>/dev/null || true
wait "$blocker" 2>/dev/null || true
unchanged
printf 'SAMPLED_RECOVERY_TEST=PASS cases=%s synthetic_host_chain=true production_connections=0 real_120_300_observation=NOT_RUN\n' "$cases"
