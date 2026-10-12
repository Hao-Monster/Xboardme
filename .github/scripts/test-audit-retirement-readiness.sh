#!/usr/bin/env bash
set -Eeuo pipefail
# Isolated contract fixtures. Every Docker call is intercepted; no daemon,
# network, production data, container or service can be touched by these tests.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
passed=0
full_id() { printf '%064x' "$1"; }
active_sha=$(printf 'a%.0s' {1..40})
previous_sha=$(printf 'b%.0s' {1..40})
workflow_sha=$(printf 'c%.0s' {1..40})
review_hash=$(printf 'd%.0s' {1..64})
protected_id=$(full_id 1)
old_id=$(full_id 2)
second_id=$(full_id 3)
old_image="sha256:$(full_id 91)"

fixture_docker() {
  local operation=${1:-} id metadata_template metadata_action
  shift || return 97
  printf '%s\n' "$operation" >> "$case_root/docker-reads.log"
  case "$operation" in
    info)
      [[ $# == 2 && $1 == --format && $2 == '{{.DockerRootDir}}' ]] || return 97
      printf '%s\n' "$case_root/docker"
      ;;
    inspect)
      [[ $# == 3 && $1 == --format && $2 != *Env* && $2 != *GraphDriver.Data* &&
         $2 != *UpperDir* && $2 != *'json .HostConfig.LogConfig.Config'* ]] || return 97
      metadata_template=$2
      for metadata_action in '.Id' '.State.Status' '.State.FinishedAt' '.State.ExitCode' '.State.OOMKilled' \
        '.HostConfig.ReadonlyRootfs' '.HostConfig.RestartPolicy.Name' '.HostConfig.RestartPolicy.MaximumRetryCount' \
        '.GraphDriver.Name' '.HostConfig.LogConfig.Type' '.LogPath' \
        '(index .HostConfig.LogConfig.Config "max-size")' '(index .HostConfig.LogConfig.Config "max-file")' \
        '(index .HostConfig.LogConfig.Config "compress")' '(index .Config.Labels "codex.xboard.release")' \
        '(index .Config.Labels "codex.xboard.release.run")' '(index .Config.Labels "codex.xboard.stage")' \
        '(index .Config.Labels "codex.xboard.stage.run")'; do
        metadata_template=${metadata_template//"{{json $metadata_action}}"/}
      done
      [[ "$metadata_template" != *'{{'* && "$metadata_template" != *'}}'* ]] || return 97
      id=$3
      [[ "$id" == "$old_id" || "$id" == "$second_id" ]] || { echo FORBIDDEN_PROTECTED_INSPECT >&2; return 97; }
      case "${metadata_fault:-}" in
        command) printf 'inspect-secret-do-not-emit\n' >&2; return 42 ;;
        invalid_json) printf '{"secret":"invalid-json-secret-do-not-emit",\n'; return ;;
        json_stream) printf '{}\n{}\n'; return ;;
        oversize) printf '%17000s' 'oversize-secret-do-not-emit'; return ;;
      esac
      if [[ -f "$case_root/inspect-change" ]]; then
        if [[ -f "$case_root/inspected-$id" ]]; then
          jq -c --arg id "$id" --arg field "${metadata_change_field:-status}" '.[]|select(.id==$id)|
            if $field=="readonly_rootfs" then .readonly_rootfs=(.readonly_rootfs|not)
            elif $field=="restart_policy" then .restart_policy.name="always"
            elif $field=="storage_driver" then .storage_driver="vfs"
            elif $field=="log_options" then .log_options.max_file="4"
            else .status="running" end' "$case_root/metadata.json"
          return
        fi
        : > "$case_root/inspected-$id"
      fi
      jq -c --arg id "$id" '.[]|select(.id==$id)' "$case_root/metadata.json"
      ;;
    *) echo "FORBIDDEN_DOCKER_OPERATION:$operation" >&2; return 97 ;;
  esac
}
command() {
  if [[ ${1:-} == -v && ${2:-} == docker ]]; then return 0; fi
  if [[ ${1:-} == timeout && ${5:-} == docker ]]; then
    [[ $2 == --signal=TERM && $3 == --kill-after=1s && $4 == 3s ]] || return 97
    shift 5
    fixture_docker "$@"
  elif [[ ${1:-} == timeout && ${5:-} == stat && "${drift_kind:-}" == rename &&
          ${7:-} == '%d:%i:%u:%a:%h:%s:%y:%z' && ${9:-} == "${drift_path:-}" ]]; then
    local count=0
    if [[ -f "$case_root/drift-stat-count" ]]; then read -r count < "$case_root/drift-stat-count"; fi
    ((count+=1))
    printf '%s\n' "$count" > "$case_root/drift-stat-count"
    if ((count==3)); then
      cp -p -- "$drift_path" "$case_root/replacement.json"
      mv -- "$case_root/replacement.json" "$drift_path"
    fi
    builtin command "$@"
  elif [[ ${1:-} == timeout && ${5:-} == head && -n "${drift_path:-}" && "${drift_kind:-}" != rename ]]; then
    local actual_path count
    actual_path=$(readlink -f -- "${9:-}")
    if [[ "$actual_path" == "$drift_path" ]]; then
      count=0
      if [[ -f "$case_root/drift-read-count" ]]; then read -r count < "$case_root/drift-read-count"; fi
      ((count+=1))
      printf '%s\n' "$count" > "$case_root/drift-read-count"
      # Simulate an external writer between the first and final complete reads.
      if ((count==3)); then printf '\n' >> "$drift_path"; fi
    fi
    builtin command "$@"
  elif [[ ${1:-} == du || (${1:-} == timeout && ${5:-} == du) ]]; then
    echo FORBIDDEN_DU >&2; return 97
  else builtin command "$@"; fi
}
docker() { fixture_docker "$@"; }
du() { echo FORBIDDEN_DU >&2; return 97; }

make_case() {
  metadata_change_field=status
  metadata_fault=''
  retirement_scope=full
  drift_path=''
  drift_kind=bytes
  case_root=$(mktemp -d "$test_root/$1.XXXXXX")
  workdir=$case_root
  lock_root="$case_root/.codex-v2-release"
  active_release_id=333-1
  active_state_file="$lock_root/333-1/state.json"
  retention_snapshot_file="$lock_root/inventory.json"
  retention_startup_file="$lock_root/startup.json"
  mkdir -p "$lock_root/333-1" "$lock_root/222-1" "$case_root/docker/containers/$old_id" "$case_root/data"
  chmod 700 "$lock_root" "$lock_root/333-1" "$lock_root/222-1"
  printf 'PRESERVED_LOCK\n' > "$lock_root/deploy.lock"
  printf 'DO_NOT_READ_LOG_CONTENTS\n' > "$case_root/docker/containers/$old_id/$old_id-json.log"
  printf 'PRESERVED_APP_DATA\n' > "$case_root/data/value"
  jq -cn --arg sha "$active_sha" --arg old "$previous_sha" --arg id "$old_id" '{
    schema_version:2,release_id:"333-1",release_sha:$sha,traffic_state:"active_v2",rollback_supported:"true",
    legacy_release_sha:$old,legacy_anchor_id:$id,legacy_web_id:$id,legacy_ws_id:$id,
    legacy_edge_id:$id,legacy_horizon_id:$id,legacy_scheduler_id:$id,
    maintenance_container:"xboard-v2-maintenance-333-1",redis_password:"state-secret-do-not-emit"}' > "$active_state_file"
  jq -cn --arg sha "$previous_sha" '{schema_version:2,release_id:"222-1",release_sha:$sha,
    traffic_state:"active_v2",rollback_supported:"true",maintenance_container:"xboard-v2-maintenance-222-1",
    secret:"historical-secret-do-not-emit"}' > "$lock_root/222-1/state.json"
  jq -cnS --arg protected "$protected_id" --arg old "$old_id" --arg second "$second_id" --arg image "$old_image" --arg data "$case_root/data" '{
    containers:[
      {id:$protected,status:"exited",project:"legacy",maintenance_release:"none",image_id:$image,
       mounts:[{Type:"bind",Source:$data,Destination:"/www/storage",Name:""}]},
      {id:$old,status:"exited",project:"xboard-v2-222-1",maintenance_release:"none",image_id:$image,
       mounts:[{Type:"bind",Source:$data,Destination:"/www/storage",Name:""}]},
      {id:$second,status:"exited",project:"unrelated",maintenance_release:"none",image_id:$image,mounts:[]}],
    images:[{id:$image,revision:"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}]}' > "$retention_snapshot_file"
  protection_snapshot=$(jq -cS --arg protected "$protected_id" '{containers:[.containers[]|select(.id==$protected)]}' "$retention_snapshot_file")
  printf '{"schema_version":1,"files":[],"references":[]}\n' > "$retention_startup_file"
  resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot")
  jq -cn --arg old "$old_id" --arg second "$second_id" --arg path "$case_root/docker/containers/$old_id/$old_id-json.log" '[
    {id:$old,status:"exited",finished_at:"2026-10-01T00:00:00Z",exit_code:0,oom_killed:false,
     readonly_rootfs:false,restart_policy:{name:"unless-stopped",maximum_retry_count:0},storage_driver:"overlay2",
     log_options:{max_size:"10m",max_file:"3",compress:"true"},
     log_driver:"json-file",log_path:$path,legacy_release:null,legacy_run:null,stage:null,stage_run:null},
    {id:$second,status:"exited",finished_at:"2026-10-01T00:00:00Z",exit_code:0,oom_killed:false,
     readonly_rootfs:true,restart_policy:{name:"no",maximum_retry_count:0},storage_driver:"overlayfs",
     log_options:{max_size:"",max_file:"",compress:""},
     log_driver:"none",log_path:"",legacy_release:null,legacy_run:null,stage:null,stage_run:null}]' > "$case_root/metadata.json"
  jq -cn --arg workflow "$workflow_sha" --arg review "$review_hash" --arg sha "$active_sha" --arg image "$old_image" \
    --arg protection "$(printf '%s\n' "$protection_snapshot" | retention_hash)" --argjson owner "$EUID" '{
    schema_version:2,status:"HOLD",owner_uid:$owner,session_id:"11111111-2222-3333-4444-555555555555",
    workflow_sha:$workflow,review_sha256:$review,active_release_id:"333-1",active_release_sha:$sha,
    protection_fingerprint:$protection,image_id:$image,baseline_seconds:120,observe_seconds:300,
    sample_interval_seconds:10,started_at_uptime:10,last_heartbeat_uptime:10,baseline_started_at_uptime:10,
    baseline_completed_at_uptime:null,intent_at_uptime:null,removed_at_uptime:null,observation_completed_at_uptime:null,
    completed_at_uptime:null,sample_count:1,cleanup_validated:false,failure_reason:"resource_probe_failed",
    post_verification:"pending",post_evidence_sha256:null,post_verified_at_uptime:null,monitoring_mode:"passive_runtime_v1",
    unknown_secret:"gate-secret-do-not-emit"}' > "$lock_root/image-cleanup-gate.json"
  jq -cn --slurpfile inventory "$retention_snapshot_file" --slurpfile startup "$retention_startup_file" \
    --arg workflow "$workflow_sha" --arg review "$review_hash" --arg sha "$previous_sha" --arg image "$old_image" \
    --arg state "$(retention_hash < "$active_state_file")" '{schema_version:2,operation:"image_cleanup",
      workflow_sha:$workflow,review_sha256:$review,active_state_sha256:$state,containers:[],images:[{id:$image,revision:$sha}],
      inventory:$inventory[0],startup:$startup[0],secret:"plan-secret-do-not-emit"}' > "$lock_root/image-cleanup-plan-$review_hash.json"
  chmod 600 "$lock_root/deploy.lock" "$lock_root/333-1/state.json" "$lock_root/222-1/state.json" \
    "$lock_root/image-cleanup-gate.json" "$lock_root/image-cleanup-plan-$review_hash.json" \
    "$retention_snapshot_file" "$retention_startup_file"
  : > "$case_root/docker-reads.log"
}
rewrite_json() {
  local path=$1 filter=$2
  jq "$filter" "$path" > "$case_root/rewrite.json"
  cp "$case_root/rewrite.json" "$path"
  rm "$case_root/rewrite.json"
}
invoke() (
  set -Eeuo pipefail
  RETENTION_ACQUIRE_LOCK=true
  exec 9<>"$lock_root/deploy.lock"
  flock -n 9
  trap ':' EXIT
  original_trap=$(trap -p EXIT)
  original_fd=$(stat -Lc '%d:%i' "/proc/$BASHPID/fd/9")
  source "$script_dir/audit-retirement-readiness.sh"
  [[ "$(trap -p EXIT)" == "$original_trap" && "$(stat -Lc '%d:%i' "/proc/$BASHPID/fd/9")" == "$original_fd" ]]
)
run_case() {
  local before after before_identity=''
  if [[ -n "$drift_path" ]]; then before_identity=$(stat -Lc '%d:%i' -- "$drift_path"); fi
  before=$(find "$lock_root" "$case_root/data" "$case_root/docker" -type f -print0 | sort -z | xargs -0 sha256sum)
  set +e
  invoke > "$case_root/result.out" 2>&1
  status=$?
  set -e
  after=$(find "$lock_root" "$case_root/data" "$case_root/docker" -type f -print0 | sort -z | xargs -0 sha256sum)
  if [[ -n "$drift_path" && "$drift_kind" == bytes ]]; then [[ "$before" != "$after" ]]
  else [[ "$before" == "$after" ]]; fi
  if [[ -n "$drift_path" && "$drift_kind" == rename ]]; then
    [[ "$before_identity" != "$(stat -Lc '%d:%i' -- "$drift_path")" ]]
  fi
  ! grep -q 'do-not-emit\|DO_NOT_READ_LOG\|FORBIDDEN_' "$case_root/result.out"
  ! grep -qvE '^(inspect|info)$' "$case_root/docker-reads.log"
}
expect_pass() { run_case; ((status==0)) || { cat "$case_root/result.out" >&2; return 1; }; grep -q '^RETIREMENT_READINESS=PASS ' "$case_root/result.out"; }
expect_fail() { run_case; ((status!=0)); ! grep -q '^RETIREMENT_READINESS=PASS ' "$case_root/result.out"; }
expect_gate_pass() {
  retirement_scope=gate
  run_case
  ((status==0)) || { cat "$case_root/result.out" >&2; return 1; }
  grep -q '^RETIREMENT_GATE_AUDIT=PASS ' "$case_root/result.out"
  ! grep -q '^RETIREMENT_READINESS=PASS\|CONTAINER_JSON=' "$case_root/result.out"
  [[ ! -s "$case_root/docker-reads.log" ]]
}
expect_gate_fail() {
  retirement_scope=gate
  run_case
  ((status!=0))
  ! grep -q '^RETIREMENT_GATE_AUDIT=PASS\|^RETIREMENT_READINESS=PASS' "$case_root/result.out"
  [[ ! -s "$case_root/docker-reads.log" ]]
}
record() { sed -n "s/^RETIREMENT_READINESS_$1=//p" "$case_root/result.out"; }
pass() { ((passed+=1)); printf 'RETIREMENT_READINESS_CASE=PASS %s\n' "$1"; }

make_case normal_hold
expect_pass
[[ $(record GATE_JSON | jq -r .raw_sha256) == "$(retention_hash < "$lock_root/image-cleanup-gate.json")" ]]
[[ $(record PLAN_JSON | jq -r .raw_sha256) == "$(retention_hash < "$lock_root/image-cleanup-plan-$review_hash.json")" ]]
record GATE_JSON | jq -e '.safe.status=="HOLD" and .safe.intent_at_uptime==null and (.safe|has("unknown_secret")|not)' >/dev/null
record PLAN_JSON | jq -e --arg hash "$(jq -cS . "$retention_snapshot_file" | retention_hash)" '.safe.inventory_sha256==$hash' >/dev/null
record CONTAINER_JSON | jq -se --arg id "$old_id" 'map(select(.id==$id))[0]|
  .kind=="v2" and .shares_protected_mount==true and .state.safe.release_sha=="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" and
  .writable_layer=={status:"not_investigated_passive_only",deletion_ready:false,contents_read:false,byte_size:"not_measured"} and
  .storage.readonly_rootfs==false and .storage.driver=="overlay2" and
  .restart_policy=={name:"unless-stopped",maximum_retry_count:0} and
  .log_configuration.options=={max_size:"10m",max_file:"3",compress:"true"} and
  .log_configuration.effective_limits_verified==false and
  .log.status=="verified_metadata" and .log.contents_read==false and .deletion_authorized==false' >/dev/null || {
    printf 'RETIREMENT_READINESS_TEST_FAIL=missing_or_incorrect_safe_container_metadata\n' >&2; exit 1;
  }
! grep -q '^diff$' "$case_root/docker-reads.log"
pass hold_audited_without_bypass_and_no_secrets_or_mutations

make_case gate_only_hold
expect_gate_pass
sed -n 's/^RETIREMENT_GATE_AUDIT_GATE_JSON=//p' "$case_root/result.out" |
  jq -e '.present==true and .safe.status=="HOLD" and (.safe|has("intent_at_uptime")) and .safe.intent_at_uptime==null' >/dev/null
pass gate_only_hold_has_own_terminal_without_docker_or_mutations

for fault in missing_gate missing_plan gate_permissions plan_permissions gate_symlink plan_symlink gate_hardlink \
  plan_hardlink gate_owner gate_running gate_complete gate_legacy_schema gate_null_mode \
  invalid_gate_json invalid_plan_json plan_binding invalid_scope; do
  make_case "gate_only_$fault"
  gate_path="$lock_root/image-cleanup-gate.json"
  plan_path="$lock_root/image-cleanup-plan-$review_hash.json"
  case "$fault" in
    missing_gate) rm -- "$gate_path" ;;
    missing_plan) rm -- "$plan_path" ;;
    gate_permissions) chmod 644 "$gate_path" ;;
    plan_permissions) chmod 644 "$plan_path" ;;
    gate_symlink) mv -- "$gate_path" "$case_root/gate-original"; ln -s "$case_root/gate-original" "$gate_path" ;;
    plan_symlink) mv -- "$plan_path" "$case_root/plan-original"; ln -s "$case_root/plan-original" "$plan_path" ;;
    gate_hardlink) ln -- "$gate_path" "$case_root/gate-hardlink" ;;
    plan_hardlink) ln -- "$plan_path" "$case_root/plan-hardlink" ;;
    gate_owner) rewrite_json "$gate_path" '.owner_uid+=1' ;;
    gate_running) rewrite_json "$gate_path" '.status="RUNNING"' ;;
    gate_complete) rewrite_json "$gate_path" '.status="COMPLETE"' ;;
    gate_legacy_schema) rewrite_json "$gate_path" '.schema_version=1 | del(.monitoring_mode)' ;;
    gate_null_mode) rewrite_json "$gate_path" '.monitoring_mode=null' ;;
    invalid_gate_json) printf '{"secret":"gate-malformed-do-not-emit",\n' > "$gate_path" ;;
    invalid_plan_json) printf '{"secret":"plan-malformed-do-not-emit",\n' > "$plan_path" ;;
    plan_binding) rewrite_json "$plan_path" '.workflow_sha="eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"' ;;
    invalid_scope) retirement_scope='gate;do-not-emit'; expect_fail; pass gate_only_invalid_scope; continue ;;
  esac
  expect_gate_fail
  pass "gate_only_$fault"
done

# Null is evidence only when the key exists in the original gate. Never infer
# absence of deletion intent from a projection that silently filled missing keys.
for field in last_heartbeat_uptime baseline_started_at_uptime baseline_completed_at_uptime intent_at_uptime \
  removed_at_uptime observation_completed_at_uptime completed_at_uptime post_verified_at_uptime \
  failure_reason post_evidence_sha256 monitoring_mode; do
  make_case "gate_only_missing_$field"
  rewrite_json "$lock_root/image-cleanup-gate.json" "del(.$field)"
  expect_gate_fail
  pass "gate_only_missing_$field"
done
for kind in bytes rename; do
  for target in gate plan active inventory startup; do
    make_case "gate_only_${target}_${kind}_drift"
    drift_kind=$kind
    case "$target" in
      gate) drift_path="$lock_root/image-cleanup-gate.json" ;;
      plan) drift_path="$lock_root/image-cleanup-plan-$review_hash.json" ;;
      active) drift_path="$active_state_file" ;;
      inventory) drift_path="$retention_snapshot_file" ;;
      startup) drift_path="$retention_startup_file" ;;
    esac
    expect_gate_fail
    pass "gate_only_${target}_${kind}_drift"
  done
done

make_case gate_observes_intent_without_authorizing_recovery
rewrite_json "$lock_root/image-cleanup-gate.json" '.intent_at_uptime=130 | .baseline_completed_at_uptime=130'
expect_gate_pass
grep -q '^RETIREMENT_GATE_AUDIT=PASS .*recovery_authorized=false ' "$case_root/result.out"
pass gate_only_records_intent_without_recovery_authority

for fault in command invalid_json json_stream oversize missing_field unsupported_field schema_rejected; do
  make_case "metadata_$fault"
  metadata_fault=$fault
  expected_diagnostic=$fault
  case "$fault" in
    command) expected_diagnostic='inspect_command_failed exit_code=42' ;;
    json_stream|oversize) expected_diagnostic=schema_rejected ;;
    missing_field) rewrite_json "$case_root/metadata.json" '.[0]|=del(.readonly_rootfs)' ;;
    unsupported_field) rewrite_json "$case_root/metadata.json" '.[0].storage_driver=null' ;;
    schema_rejected) rewrite_json "$case_root/metadata.json" '.[0].readonly_rootfs="true"' ;;
  esac
  expect_fail
  grep -Fxq "RETIREMENT_METADATA_DIAGNOSTIC=$expected_diagnostic" "$case_root/result.out"
  pass "metadata_redacted_$fault"
done

make_case readonly_is_not_deletion_approval
rewrite_json "$case_root/metadata.json" '.[0].readonly_rootfs=true | .[0].log_options={max_size:"",max_file:"",compress:""}'
expect_pass
record CONTAINER_JSON | jq -se --arg id "$old_id" 'map(select(.id==$id))[0]|
  .storage.readonly_rootfs==true and .deletion_authorized==false and .writable_layer.deletion_ready==false and
  .log_configuration.options=={max_size:"",max_file:"",compress:""} and
  .log_configuration.effective_limits_verified==false and .log.rotated_logs=="not_investigated"' >/dev/null
pass readonly_rootfs_and_missing_rotation_do_not_authorize_deletion

make_case null_log_options
rewrite_json "$case_root/metadata.json" '.[0].log_options={max_size:null,max_file:null,compress:null}'
expect_pass
record CONTAINER_JSON | jq -se --arg id "$old_id" 'map(select(.id==$id))[0]|
  .log_configuration.options=={max_size:null,max_file:null,compress:null} and
  .log_configuration.effective_limits_verified==false and .deletion_authorized==false' >/dev/null
pass null_options_are_preserved_not_replaced_with_defaults

make_case unlimited_log_size
rewrite_json "$case_root/metadata.json" '.[0].log_options.max_size="-1" | .[0].log_options.compress="false"'
expect_pass
record CONTAINER_JSON | jq -se --arg id "$old_id" 'map(select(.id==$id))[0]|
  .log_configuration.options.max_size=="-1" and .log_configuration.options.compress=="false"' >/dev/null
pass unlimited_rotation_is_recorded_without_inference

for fault in readonly_missing readonly_string restart_unknown restart_negative restart_fractional storage_path log_extra log_secret log_bad_compress log_bad_count log_control log_oversize log_type; do
  make_case "$fault"
  case "$fault" in
    readonly_missing) rewrite_json "$case_root/metadata.json" '.[0]|=del(.readonly_rootfs)' ;;
    readonly_string) rewrite_json "$case_root/metadata.json" '.[0].readonly_rootfs="true"' ;;
    restart_unknown) rewrite_json "$case_root/metadata.json" '.[0].restart_policy.name="unexpected"' ;;
    restart_negative) rewrite_json "$case_root/metadata.json" '.[0].restart_policy.maximum_retry_count=-1' ;;
    restart_fractional) rewrite_json "$case_root/metadata.json" '.[0].restart_policy.maximum_retry_count=1.5' ;;
    storage_path) rewrite_json "$case_root/metadata.json" '.[0].storage_driver="/var/lib/docker/overlay2/private"' ;;
    log_extra) rewrite_json "$case_root/metadata.json" '.[0].log_options.token="log-secret-do-not-emit"' ;;
    log_secret) rewrite_json "$case_root/metadata.json" '.[0].log_options.max_size="log-secret-do-not-emit"' ;;
    log_bad_compress) rewrite_json "$case_root/metadata.json" '.[0].log_options.compress="yes"' ;;
    log_bad_count) rewrite_json "$case_root/metadata.json" '.[0].log_options.max_file="0"' ;;
    log_control) rewrite_json "$case_root/metadata.json" '.[0].log_options.max_size="1m\n"' ;;
    log_oversize) rewrite_json "$case_root/metadata.json" '.[0].log_options.max_size=("1"*33)' ;;
    log_type) rewrite_json "$case_root/metadata.json" '.[0].log_options.compress=true' ;;
  esac
  expect_fail
  pass "$fault"
done

make_case nullable_lifecycle
rewrite_json "$active_state_file" 'del(.legacy_ws_id,.legacy_edge_id) | .legacy_horizon_id=null'
resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot")
expect_pass
life=$(record LIFECYCLE_JSON)
[[ $(jq -cnS --slurpfile inventory "$retention_snapshot_file" --argjson protection "$protection_snapshot" --argjson lifecycle "$life" \
  '{inventory:$inventory[0],protection:$protection,lifecycle:$lifecycle}' | retention_hash) == "$resource_fingerprint" ]]
[[ $(record LIFECYCLE_JSON | jq -r '.legacy_ws_id==null and .legacy_horizon_id==null and (.rollback_supported|type)=="string"') == true ]]
pass exact_lifecycle_projection_preserves_null_and_string_semantics

for fault in gate_permissions gate_symlink gate_hardlink plan_permissions plan_symlink plan_binding active_fingerprint gate_string active_short_id active_short_sha; do
  make_case "$fault"
  case "$fault" in
    gate_permissions) chmod 644 "$lock_root/image-cleanup-gate.json" ;;
    gate_symlink) mv "$lock_root/image-cleanup-gate.json" "$case_root/gate-target.json"; ln -s "$case_root/gate-target.json" "$lock_root/image-cleanup-gate.json" ;;
    gate_hardlink) ln "$lock_root/image-cleanup-gate.json" "$case_root/gate-hardlink.json" ;;
    plan_permissions) chmod 644 "$lock_root/image-cleanup-plan-$review_hash.json" ;;
    plan_symlink) mv "$lock_root/image-cleanup-plan-$review_hash.json" "$case_root/plan-target.json"; ln -s "$case_root/plan-target.json" "$lock_root/image-cleanup-plan-$review_hash.json" ;;
    plan_binding) rewrite_json "$lock_root/image-cleanup-plan-$review_hash.json" '.workflow_sha="eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"' ;;
    active_fingerprint) resource_fingerprint=$(printf 'e%.0s' {1..64}) ;;
    gate_string) rewrite_json "$lock_root/image-cleanup-gate.json" '.failure_reason="secret-do-not-emit with whitespace"' ;;
    active_short_id) rewrite_json "$active_state_file" '.legacy_anchor_id="abc01234"'; resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot") ;;
    active_short_sha) rewrite_json "$active_state_file" '.legacy_release_sha="bbbbbbb"'; resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot") ;;
  esac
  expect_fail
  pass "$fault"
done

make_case absent_gate
rm "$lock_root/image-cleanup-gate.json"
expect_pass
record GATE_JSON | jq -e '.present==false and .status=="missing"' >/dev/null
pass absent_gate_is_explicit_not_repaired

make_case no_stopped_targets
# Keep only the protected stopped anchor; it must never reach Docker diff.
jq --arg id "$protected_id" '.containers=[.containers[]|select(.id==$id)]' "$retention_snapshot_file" > "$case_root/only-protected.json"
cp "$case_root/only-protected.json" "$retention_snapshot_file"
resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot")
expect_pass
[[ $(record STOPPED_NONPROTECTED_COUNT) == 0 ]]
! grep -q '^diff$' "$case_root/docker-reads.log"
pass protected_anchor_and_empty_candidate_set_are_preserved

make_case unsafe_historical_state
chmod 644 "$lock_root/222-1/state.json"
expect_pass
record CONTAINER_JSON | jq -se --arg id "$old_id" 'map(select(.id==$id))[0]|.state.status=="unsafe_or_invalid"' >/dev/null
pass unsafe_history_is_not_read_with_weaker_permissions

make_case unexpected_log_path
rewrite_json "$case_root/metadata.json" '.[0].log_path="/etc/shadow"'
expect_pass
record CONTAINER_JSON | jq -se --arg id "$old_id" 'map(select(.id==$id))[0]|.log.status=="unverified_path_or_missing" and .log.contents_read==false' >/dev/null
pass unexpected_log_path_not_followed

make_case changed_stopped_container
: > "$case_root/inspect-change"
expect_fail
grep -q 'RETIREMENT_READINESS_FAIL=stopped_container_changed_after_inspection' "$case_root/result.out"
pass container_restart_during_audit_blocks_evidence

for field in readonly_rootfs restart_policy storage_driver log_options; do
  make_case "changed_$field"
  metadata_change_field=$field
  : > "$case_root/inspect-change"
  expect_fail
  grep -q 'RETIREMENT_READINESS_FAIL=stopped_container_changed_after_inspection' "$case_root/result.out"
  grep -Fxq 'RETIREMENT_METADATA_DIAGNOSTIC=post_read_drift' "$case_root/result.out"
  pass "container_metadata_drift_$field"
done

make_case legacy_and_stage
mkdir -p "$case_root/.codex-release/222-1"
cp "$lock_root/222-1/state.json" "$case_root/.codex-release/222-1/state.json"
chmod 600 "$case_root/.codex-release/222-1/state.json"
rewrite_json "$retention_snapshot_file" '(.containers[]|select(.project=="xboard-v2-222-1")|.project)="legacy"'
rewrite_json "$case_root/metadata.json" '.[0].legacy_release="true" | .[0].legacy_run="222-1" | .[1].stage="true" | .[1].stage_run="111-1"'
resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot")
expect_pass
record CONTAINER_JSON | jq -se 'map(.kind)|sort==["legacy","stage"]' >/dev/null
pass legacy_and_stage_metadata_scoped_without_directory_deletion

printf 'RETIREMENT_READINESS_TESTS=PASS cases=%s offline=true live_runtime=false\n' "$passed"
