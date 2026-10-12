#!/usr/bin/env bash
set -Eeuo pipefail
# Pure, isolated recovery-evidence tests. No Docker, SSH, service operation,
# clock shortcut, archived production fixture or production approval is used.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-continuity.sh"
source "$script_dir/retention-safety.sh"
source "$script_dir/retention-predelete-recovery.sh"
test_root=$(mktemp -d /tmp/xboard-predelete-recovery.XXXXXX)
[[ "$test_root" == /tmp/xboard-predelete-recovery.* && ! -L "$test_root" &&
   "$(realpath -e -- "$test_root")" == "$test_root" ]] || exit 1
cleanup() {
  [[ "$test_root" == /tmp/xboard-predelete-recovery.* && -d "$test_root" && ! -L "$test_root" &&
     "$(realpath -e -- "$test_root")" == "$test_root" ]] || return 1
  rm -rf -- "$test_root"
}
trap cleanup EXIT
cases=0
fail() { printf 'PREDELETE_RECOVERY_TEST=FAIL case=%s reason=%s\n' "${case_name:-setup}" "$1" >&2; exit 1; }
hash_file() { sha256sum < "$1" | cut -d' ' -f1; }
hash_text() { printf '%s\n' "$1" | sha256sum | cut -d' ' -f1; }
old_workflow=$(printf 'a%.0s' {1..40})
new_workflow=$(printf 'c%.0s' {1..40})
active_sha=$(printf 'd%.0s' {1..40})
old_review_hash=$(printf 'b%.0s' {1..64})
target="sha256:$(printf 'e%.0s' {1..64})"

# Instrument only the command boundary, never the production comparison code.
# Recovery validation must not acquire locks or call any host operation.
_rc_run() {
  case "$1" in jq|stat|realpath|sha256sum) ;; *) fail "forbidden_command:$1" ;; esac
  printf '%s\n' "$1" >> "$test_root/reader-commands"
  timeout --signal=TERM --kill-after=1s 3s "$@"
}
: > "$test_root/reader-commands"
source "$script_dir/retention-predelete-recovery.sh"
[[ ! -s "$test_root/reader-commands" ]] || fail source_had_side_effects

fixture() {
  case_name=$1
  root="$test_root/case-$cases/.codex-v2-release"
  mkdir -p "$root/123-1"
  chmod 700 "$root" "$root/123-1"
  review="$root/new-review.json" gate="$root/image-cleanup-gate.json"
  plan="$root/image-cleanup-plan-$old_review_hash.json"
  inventory="$root/inventory.json" startup="$root/startup.json" state="$root/123-1/state.json"
  jq -cnS --arg active "$active_sha" '{schema_version:2,release_id:"123-1",release_sha:$active,
    project_name:"xboard-v2-123-1",traffic_state:"active_v2",rollback_supported:"true",
    legacy_release_sha:("1"*40),legacy_anchor_id:("2"*64),legacy_web_id:("3"*64),
    legacy_ws_id:("4"*64),legacy_edge_id:("5"*64),legacy_horizon_id:("6"*64),
    legacy_scheduler_id:("7"*64),maintenance_container:"xboard-v2-maintenance-123-1"}' > "$state"
  jq -cnS --arg target "$target" '{containers:[range(1;7)|{id:(tostring*64),
      image_id:("sha256:"+("f"*64)),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}],
    images:[{id:$target,size_bytes:1024,created_at:"2026-09-01T00:00:00Z",revision:("e"*40),
      source:"https://github.com/Hao-Monster/Xboardme",tags:["ghcr.io/hao-monster/xboardme:old"],
      digests:[("ghcr.io/hao-monster/xboardme@"+$target)]}]}' > "$inventory"
  jq -cnS '{files:[],image_refs:[]}' > "$startup"
  protection=$(jq -cnS --slurpfile inventory "$inventory" '{upstream:"127.0.0.1:7002",
    caddy_file:"/etc/caddy/Caddyfile",caddy_sha256:("9"*64),app_data_identity:"1:42",
    redis_volume:"fixture-redis",containers:$inventory[0].containers}')
  protection_hash=$(hash_text "$protection")
  resource_document=$(jq -cnS --slurpfile inventory "$inventory" --slurpfile state "$state" \
    --argjson protection "$protection" '{inventory:$inventory[0],protection:$protection,
      lifecycle:($state[0]|{release_id,release_sha,traffic_state,rollback_supported,
        legacy_release_sha,legacy_anchor_id,legacy_web_id,legacy_ws_id,legacy_edge_id,
        legacy_horizon_id,legacy_scheduler_id,maintenance_container})}')
  resource_hash=$(hash_text "$resource_document")
  jq -cnS --arg workflow "$old_workflow" --arg review "$old_review_hash" --arg target "$target" \
    --arg active "$active_sha" --arg protection "$protection_hash" --argjson owner "$EUID" '{
      schema_version:2,monitoring_mode:"passive_runtime_v1",status:"HOLD",owner_uid:$owner,
      session_id:"12345678-1234-1234-1234-123456789abc",workflow_sha:$workflow,review_sha256:$review,
      active_release_id:"123-1",active_release_sha:$active,protection_fingerprint:$protection,image_id:$target,
      baseline_seconds:120,observe_seconds:300,sample_interval_seconds:10,started_at_uptime:1000,
      last_heartbeat_uptime:null,baseline_started_at_uptime:null,baseline_completed_at_uptime:null,
      intent_at_uptime:null,removed_at_uptime:null,observation_completed_at_uptime:null,completed_at_uptime:null,
      sample_count:0,cleanup_validated:false,failure_reason:"resource_limit_or_source_failed",
      post_verification:"pending",post_evidence_sha256:null,post_verified_at_uptime:null}' > "$gate"
  jq -cnS --arg review "$old_review_hash" --arg workflow "$old_workflow" --arg state "$(hash_file "$state")" \
    --slurpfile inventory "$inventory" --slurpfile startup "$startup" '{schema_version:2,operation:"image_cleanup",
      review_sha256:$review,workflow_sha:$workflow,active_state_sha256:$state,inventory:$inventory[0],
      startup:$startup[0],containers:[],images:[$inventory[0].images[0]]}' > "$plan"
  image_document=$(jq -cS '.images[0]' "$plan")
  jq -cnS --arg active "$active_sha" --arg target "$target" --arg old_workflow "$old_workflow" \
    --arg old_review "$old_review_hash" --arg gate_hash "$(hash_file "$gate")" \
    --arg plan_hash "$(hash_file "$plan")" --arg image_hash "$(hash_text "$image_document")" \
    --arg startup_hash "$(hash_file "$startup")" --arg protection "$protection_hash" --arg resource "$resource_hash" \
    --slurpfile inventory "$inventory" '{schema_version:3,status:"approved",operation:"image_cleanup",
      active_release_id:"123-1",active_release_sha:$active,resource_fingerprint:$resource,protection_fingerprint:$protection,
      containers:[],images:[{id:$target,snapshot_sha256:$image_hash,evidence:{startup_reference_review_sha256:$startup_hash}}],
      monitoring:{mode:"passive_runtime_v1",active_port:7002,baseline_seconds:120,post_delete_observe_seconds:300,
        sample_interval_seconds:10,heartbeat_max_age_seconds:30,
        caddy:{unit:"caddy.service",invocation_id:("8"*32),main_pid:42,active_enter_monotonic_usec:1000000},
        protected_containers:[$inventory[0].containers[]|{id,status,started_at,restart_count}],
        resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,
          max_io_full_avg10:1,max_cpu_some_avg10:10}},
      recovery:{schema_version:1,mode:"predelete_hold_v1",gate_sha256:$gate_hash,plan_sha256:$plan_hash,
        review_sha256:$old_review,workflow_sha:$old_workflow,image_id:$target,
        session_id:"12345678-1234-1234-1234-123456789abc"}}' > "$review"
  chmod 600 "$review" "$gate" "$plan" "$inventory" "$startup" "$state"
}
mutate() {
  local path=$1 expression=$2
  jq "$expression" "$path" > "$root/mutated"
  chmod 600 "$root/mutated"
  mv -- "$root/mutated" "$path"
}
reseal_gate() { mutate "$review" ".recovery.gate_sha256=\"$(hash_file "$gate")\""; }
reseal_plan() { mutate "$review" ".recovery.plan_sha256=\"$(hash_file "$plan")\""; }
fingerprint_inputs() {
  for evidence in "$review" "$gate" "$plan" "$inventory" "$startup" "$state"; do
    [[ -f "$evidence" ]] || continue
    stat -Lc '%d:%i:%u:%a:%h' -- "$evidence"
    hash_file "$evidence"
  done
}
validate() {
  retention_predelete_recovery_validate "$review" "$gate" "$plan" "$inventory" "$startup" "$state" "$protection" "$new_workflow"
}
expect() {
  local expected=$1 before after status
  shift
  before=$(fingerprint_inputs)
  if "$@" > "$root/result.out" 2> "$root/result.err"; then status=0; else status=$?; fi
  after=$(fingerprint_inputs)
  [[ "$before" == "$after" ]] || fail validator_changed_evidence
  [[ "$status" == "$expected" ]] || {
    cat "$root/result.err" >&2
    fail "exit:$status:expected:$expected"
  }
  if [[ "$expected" == 1 ]]; then
    grep -Eq '^RETENTION_PREDELETE_RECOVERY_FAIL=[a-z_]+$' "$root/result.err" || fail missing_failure_reason
  else
    [[ ! -s "$root/result.out" && ! -s "$root/result.err" ]] || fail successful_validator_not_silent
  fi
  ((cases+=1))
}

fixture valid_predelete_hold
expect 0 validate
# Validation is repeatable under a caller-owned lock and never obtains its own.
expect 0 validate
# Compare against the real shared serializer, not only the fixture's copy of its
# envelope. Explicit protection must make this a purely local calculation.
shared_fingerprint_matches() (
  active_state_file=$state
  retention_live_protection() { fail unexpected_host_discovery; }
  [[ "$(retention_resource_fingerprint "$inventory" "$protection")" == "$resource_hash" ]] ||
    fail shared_resource_fingerprint_mismatch
)
expect 0 shared_fingerprint_matches
expect 1 retention_predelete_recovery_validate "$review"
expect 1 retention_predelete_recovery_schema_validate "$review" extra

for mutation in 'del(.recovery)' '.recovery=null' '.recovery=[]' '.recovery.extra="skip"' \
  '.recovery.schema_version=2' '.recovery.mode="resume"' '.recovery.gate_sha256=""' \
  '.recovery.gate_sha256=("0"*64)' '.recovery.plan_sha256=("z"*64)' \
  '.recovery.workflow_sha="short"' '.recovery.session_id="------------------------------------"' \
  '.recovery.review_sha256="123"' '.recovery.image_id=("sha256:"+("a"*64))' \
  '.schema_version=2' '.status="blocked"' '.containers=[{id:("1"*64)}]' \
  '.monitoring.mode="legacy_live_v1"' '.monitoring.baseline_seconds=30' \
  '.monitoring.post_delete_observe_seconds=20' '.monitoring.resources.max_cpu_busy_percent=86'; do
  fixture "invalid_review:$mutation"
  mutate "$review" "$mutation"
  expect 1 validate
done
for mutation in '.recovery.session_id="aaaaaaaa-1234-1234-1234-123456789abc"' \
  '.recovery.workflow_sha=("f"*40)' '.recovery.gate_sha256=("f"*64)' \
  '.recovery.plan_sha256=("f"*64)' '.active_release_sha=("f"*40)' \
  '.images[0].snapshot_sha256=("f"*64)' '.images[0].evidence.startup_reference_review_sha256=("f"*64)' \
  '.resource_fingerprint=("f"*64)' '.protection_fingerprint=("f"*64)' \
  '.monitoring.protected_containers[0].restart_count=1'; do
  fixture "binding_mismatch:$mutation"
  mutate "$review" "$mutation"
  expect 1 validate
done

for mutation in '.status="RUNNING"' '.status="COMPLETE"' '.schema_version=1' \
  '.monitoring_mode="legacy_live_v1"' '.sample_count=1' '.cleanup_validated=true' \
  '.failure_reason=null' '.post_verification="passed"' '.review_sha256=("f"*64)' \
  '.workflow_sha=("f"*40)' '.image_id=("sha256:"+("a"*64))'; do
  fixture "old_gate_not_recoverable:$mutation"
  mutate "$gate" "$mutation"
  reseal_gate
  expect 1 validate
done
for key in last_heartbeat_uptime baseline_started_at_uptime baseline_completed_at_uptime intent_at_uptime \
  removed_at_uptime observation_completed_at_uptime completed_at_uptime post_evidence_sha256 post_verified_at_uptime; do
  for kind in recorded missing; do
    fixture "gate_clock:$key:$kind"
    if [[ "$kind" == missing ]]; then mutate "$gate" "del(.$key)"; else mutate "$gate" ".$key=1001"; fi
    reseal_gate
    expect 1 validate
  done
done
fixture failure_postcheck_still_predelete
mutate "$gate" '.post_verification="failed"'
reseal_gate
expect 0 validate

for mutation in '.schema_version=1' '.operation="retention_cleanup"' '.extra="unknown"' \
  '.review_sha256=("f"*64)' '.workflow_sha=("f"*40)' '.active_state_sha256=("f"*64)' \
  '.containers=[{id:("1"*64)}]' '.images=[]' '.images += .images' \
  '.images[0].tags=[]' '.inventory.containers[0].restart_count=1' '.startup.files=["unexpected"]'; do
  fixture "old_plan_mismatch:$mutation"
  mutate "$plan" "$mutation"
  reseal_plan
  expect 1 validate
done
for mutation in '.containers[0].started_at="2026-10-12T01:00:00Z"' '.containers[0].restart_count=1' \
  '.containers[0].image_id=("sha256:"+("e"*64))' '.images[0].tags += ["ghcr.io/hao-monster/xboardme:new"]' \
  '.images=[]' '.images += .images' '.containers=[]'; do
  fixture "inventory_drift:$mutation"
  mutate "$inventory" "$mutation"
  expect 1 validate
done
fixture startup_drift
mutate "$startup" '.files=["unexpected"]'
expect 1 validate
fixture active_state_drift
mutate "$state" '.unrelated_metadata="changed"'
expect 1 validate
fixture live_protection_drift
protection=$(jq -cS '.upstream="127.0.0.1:7003"' <<< "$protection")
expect 1 validate
fixture malformed_protection
protection='not-json'
expect 1 validate
fixture same_workflow_not_new_approval
expect 1 retention_predelete_recovery_validate "$review" "$gate" "$plan" "$inventory" "$startup" "$state" "$protection" "$old_workflow"
fixture invalid_workflow
expect 1 retention_predelete_recovery_validate "$review" "$gate" "$plan" "$inventory" "$startup" "$state" "$protection" short

for evidence_name in review gate plan inventory startup state; do
  fixture "insecure_file:$evidence_name"
  chmod 644 "${!evidence_name}"
  expect 1 validate
done
for evidence_name in review gate plan inventory startup state; do
  fixture "missing_file:$evidence_name"
  rm -- "${!evidence_name}"
  expect 1 validate
done
fixture old_plan_extra_hardlink
ln -- "$plan" "$root/unexplained-plan-link"
expect 1 validate
fixture old_gate_extra_hardlink
ln -- "$gate" "$root/unexplained-gate-link"
expect 1 validate
fixture symlink_gate
mv -- "$gate" "$root/real-gate"
ln -s -- "$root/real-gate" "$gate"
expect 1 validate
fixture different_old_plan_path
cp -- "$plan" "$root/other-plan.json"
chmod 600 "$root/other-plan.json"
plan="$root/other-plan.json"
expect 1 validate
fixture raw_gate_bytes_changed
printf '\n' >> "$gate"
expect 1 validate
fixture raw_plan_bytes_changed
printf '\n' >> "$plan"
expect 1 validate
fixture multiple_inventory_documents
printf '{}\n' >> "$inventory"
expect 1 validate
fixture invalid_inventory_json
printf 'not-json\n' > "$inventory"
expect 1 validate
fixture missing_validator_dependency
missing_dependency() ( unset -f _rc_gate_validate; validate; )
expect 1 missing_dependency
fixture timed_out_validator_dependency
timed_out_dependency() (
  _rc_jq() { timeout --signal=TERM --kill-after=1s 0.1s sleep 1; }
  validate
)
expect 1 timed_out_dependency

fixture final_valid_case
expect 0 validate
printf 'PREDELETE_RECOVERY_TEST=PASS cases=%s source_only=true immutable_inputs=true host_mutations=0 production_connections=0\n' "$cases"
