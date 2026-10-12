#!/usr/bin/env bash
set -Eeuo pipefail
# Read-only extension of audit-xboard-images-only.sh. Keep its fd9 and EXIT
# trap intact. Evidence is not deletion authority, and HOLD is never modified.

retirement_fail() { printf 'RETIREMENT_READINESS_FAIL=%s\n' "$1" >&2; exit 1; }
retirement_deadline=$((SECONDS+300))
retirement_run() {
  ((SECONDS<retirement_deadline)) || return 124
  command timeout --signal=TERM --kill-after=1s 3s "$@"
}
retirement_jq() { retirement_run jq "$@"; }
retirement_hash() {
  local value
  value=$(retirement_run sha256sum) || return 1
  printf '%s\n' "${value%% *}"
}
retirement_emit() { printf 'RETIREMENT_READINESS_%s=%s\n' "$1" "$2"; }

# Pin a safely owned regular file through an inherited read-only descriptor.
# No JSON, environment, log or configuration contents escape except the fixed
# validated projection. Hashes cover original bytes, including whitespace.
retirement_file() (
  local path=$1 limit=$2 filter=$3 components=${4:-false}
  local fd pinned before after hash repeated safe inventory_hash startup_hash
  [[ -f "$path" && ! -L "$path" && "$path" == "$workdir/"* &&
     "$(retirement_run realpath -e -- "$path")" == "$path" ]] || return 1
  before=$(retirement_run stat -Lc '%d:%i:%u:%a:%h:%s:%y:%z' -- "$path") || return 1
  [[ "$(retirement_run stat -Lc '%u:%a:%h' -- "$path")" == "$EUID:600:1" ]] || return 1
  local size
  size=$(retirement_run stat -Lc '%s' -- "$path") || return 1
  [[ "$size" =~ ^[0-9]+$ ]] && ((size>0 && size<=limit)) || return 1
  exec {fd}<"$path" || return 1
  pinned="/proc/$BASHPID/fd/$fd"
  [[ "$(retirement_run stat -Lc '%d:%i:%u:%a:%h:%s:%y:%z' -- "$pinned")" == "$before" ]] || return 1
  hash=$(retirement_run head -c "$((limit+1))" -- "$pinned" | retirement_hash) || return 1
  safe=$(retirement_jq -ceS "$filter" "$pinned" 2>/dev/null) || return 1
  if [[ "$components" == true ]]; then
    inventory_hash=$(retirement_jq -ceS '.inventory|select(type=="object")' "$pinned" | retirement_hash) || return 1
    startup_hash=$(retirement_jq -ceS '.startup|select(type=="object")' "$pinned" | retirement_hash) || return 1
    safe=$(retirement_jq -cnS --argjson safe "$safe" --arg inventory "$inventory_hash" --arg startup "$startup_hash" \
      '$safe+{inventory_sha256:$inventory,startup_sha256:$startup}') || return 1
  fi
  repeated=$(retirement_run head -c "$((limit+1))" -- "$pinned" | retirement_hash) || return 1
  after=$(retirement_run stat -Lc '%d:%i:%u:%a:%h:%s:%y:%z' -- "$path") || return 1
  [[ "$hash" == "$repeated" && "$before" == "$after" && ! -L "$path" &&
     "$(retirement_run realpath -e -- "$path")" == "$path" ]] || return 1
  retirement_jq -cnS --arg hash "$hash" --argjson safe "$safe" --argjson size "$size" \
    '{present:true,status:"verified",raw_sha256:$hash,size_bytes:$size,safe:$safe}'
)

for retirement_tool in timeout jq sha256sum stat realpath head docker; do
  command -v "$retirement_tool" >/dev/null || retirement_fail missing_tool
done
[[ "${RETENTION_ACQUIRE_LOCK:-false}" == true && -n "${workdir:-}" && "$workdir" == /* &&
   "${lock_root:-}" == "$workdir/.codex-v2-release" && -d "$lock_root" && ! -L "$lock_root" &&
   "$(retirement_run realpath -e -- "$lock_root")" == "$lock_root" &&
   "$(retirement_run stat -Lc '%u:%a' -- "$lock_root")" == "$EUID:700" &&
   -f "$lock_root/deploy.lock" && ! -L "$lock_root/deploy.lock" &&
   "$(retirement_run stat -Lc '%u:%a:%h' -- "$lock_root/deploy.lock")" == "$EUID:600:1" &&
   -e "/proc/$BASHPID/fd/9" &&
   "$(retirement_run stat -Lc '%d:%i' -- "/proc/$BASHPID/fd/9")" == \
   "$(retirement_run stat -Lc '%d:%i' -- "$lock_root/deploy.lock")" ]] || retirement_fail locked_audit_context_required
[[ -n "${retention_snapshot_file:-}" && -n "${retention_startup_file:-}" &&
   -n "${active_state_file:-}" && -n "${protection_snapshot:-}" &&
   "${active_release_id:-}" =~ ^[0-9]+-[0-9]+$ &&
   "$active_state_file" == "$lock_root/$active_release_id/state.json" &&
   "${resource_fingerprint:-}" =~ ^[a-f0-9]{64}$ ]] || retirement_fail missing_audit_context

# A missing optional state key becomes null, exactly as in
# retention_resource_fingerprint. Never coerce rollback_supported to boolean.
retirement_state_filter='
  def sha: type=="string" and test("^[a-f0-9]{40}$");
  def optional_sha: .==null or .=="" or .=="none" or sha;
  def cid: .==null or .=="" or .=="none" or (type=="string" and test("^[a-f0-9]{64}$"));
  def maintenance: .==null or .=="" or (type=="string" and test("^xboard-v2-maintenance-[0-9]+-[0-9]+$"));
  select(type=="object" and .schema_version==2 and
    (.release_id|type=="string" and test("^[0-9]+-[0-9]+$")) and (.release_sha|sha) and
    (.traffic_state|IN("prepared","maintenance","ready","active_v2","finalizing","finalized","rolled_back","blue","green")) and
    (.rollback_supported|.==null or .=="true" or .=="false") and
    (.legacy_release_sha|optional_sha) and
    all(.legacy_anchor_id,.legacy_web_id,.legacy_ws_id,.legacy_edge_id,.legacy_horizon_id,.legacy_scheduler_id;cid) and
    (.maintenance_container|maintenance)) |
  {release_id,release_sha,traffic_state,rollback_supported,legacy_release_sha,
   legacy_anchor_id,legacy_web_id,legacy_ws_id,legacy_edge_id,legacy_horizon_id,
   legacy_scheduler_id,maintenance_container}'
retirement_active=$(retirement_file "$active_state_file" 1048576 "$retirement_state_filter") || retirement_fail unsafe_active_state
retirement_lifecycle=$(retirement_jq -cS .safe <<< "$retirement_active") || retirement_fail lifecycle_projection_failed
retirement_recomputed=$(retirement_jq -cnS --slurpfile inventory "$retention_snapshot_file" \
  --argjson protection "$protection_snapshot" --argjson lifecycle "$retirement_lifecycle" \
  '{inventory:$inventory[0],protection:$protection,lifecycle:$lifecycle}' | retirement_hash) || retirement_fail fingerprint_recompute_failed
[[ "$retirement_recomputed" == "$resource_fingerprint" ]] || retirement_fail resource_fingerprint_mismatch
retirement_emit SCHEMA 1
retirement_emit ACTIVE_STATE_SHA256 "$(retirement_jq -r .raw_sha256 <<< "$retirement_active")"
retirement_emit LIFECYCLE_JSON "$retirement_lifecycle"
retirement_emit RESOURCE_FINGERPRINT "$retirement_recomputed"

retirement_gate_filter='
  def hash: type=="string" and test("^[a-f0-9]{64}$");
  def sha: type=="string" and test("^[a-f0-9]{40}$");
  def number: type=="number" and floor==. and .>=0;
  def clock: .==null or number;
  select(type=="object" and (.schema_version==1 or .schema_version==2) and
    (.status|IN("RUNNING","HOLD","COMPLETE")) and (.owner_uid|number) and
    (.session_id|type=="string" and test("^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")) and
    (.workflow_sha|sha) and (.review_sha256|hash) and (.active_release_sha|sha) and
    (.active_release_id|type=="string" and test("^[0-9]+-[0-9]+$")) and
    (.protection_fingerprint|hash) and (.image_id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
    all(.baseline_seconds,.observe_seconds,.sample_interval_seconds,.started_at_uptime,.sample_count;number) and
    all(.last_heartbeat_uptime,.baseline_started_at_uptime,.baseline_completed_at_uptime,.intent_at_uptime,
      .removed_at_uptime,.observation_completed_at_uptime,.completed_at_uptime,.post_verified_at_uptime;clock) and
    (.cleanup_validated|type=="boolean") and (.post_verification|IN("pending","passed","failed")) and
    (.failure_reason|.==null or (type=="string" and test("^[a-zA-Z0-9_:-]{1,120}$"))) and
    (.post_evidence_sha256|.==null or hash) and
    (.monitoring_mode|.==null or .=="passive_runtime_v1")) |
  {schema_version,status,owner_uid,session_id,workflow_sha,review_sha256,active_release_id,
   active_release_sha,protection_fingerprint,image_id,baseline_seconds,observe_seconds,
   sample_interval_seconds,started_at_uptime,last_heartbeat_uptime,baseline_started_at_uptime,
   baseline_completed_at_uptime,intent_at_uptime,removed_at_uptime,observation_completed_at_uptime,
   completed_at_uptime,sample_count,cleanup_validated,failure_reason,post_verification,
   post_evidence_sha256,post_verified_at_uptime,monitoring_mode}'
retirement_gate_path="$lock_root/image-cleanup-gate.json"
if [[ -e "$retirement_gate_path" || -L "$retirement_gate_path" ]]; then
  retirement_gate=$(retirement_file "$retirement_gate_path" 262144 "$retirement_gate_filter") || retirement_fail unsafe_gate
  retirement_jq -e --argjson owner "$EUID" '.safe.owner_uid==$owner' <<< "$retirement_gate" >/dev/null || retirement_fail gate_owner_mismatch
  retirement_emit GATE_JSON "$retirement_gate"
  retirement_review=$(retirement_jq -r .safe.review_sha256 <<< "$retirement_gate")
  retirement_plan_path="$lock_root/image-cleanup-plan-$retirement_review.json"
  if [[ -e "$retirement_plan_path" || -L "$retirement_plan_path" ]]; then
    retirement_plan_filter='
      def hash: type=="string" and test("^[a-f0-9]{64}$");
      def sha: type=="string" and test("^[a-f0-9]{40}$");
      select(type=="object" and .schema_version==2 and .operation=="image_cleanup" and
        (.review_sha256|hash) and (.workflow_sha|sha) and (.active_state_sha256|hash) and
        .containers==[] and (.images|type=="array" and length==1 and all(.[];
          (.id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and (.revision|sha)))) |
      {schema_version,operation,review_sha256,workflow_sha,active_state_sha256,containers,
       images:[.images[]|{id,revision}]}'
    retirement_plan=$(retirement_file "$retirement_plan_path" 8388608 "$retirement_plan_filter" true) || retirement_fail unsafe_plan
    retirement_jq -e --argjson gate "$retirement_gate" '
      .safe.review_sha256==$gate.safe.review_sha256 and .safe.workflow_sha==$gate.safe.workflow_sha and
      .safe.images[0].id==$gate.safe.image_id' <<< "$retirement_plan" >/dev/null || retirement_fail plan_gate_binding_mismatch
    retirement_emit PLAN_JSON "$retirement_plan"
  else retirement_emit PLAN_JSON '{"present":false,"status":"missing"}'; fi
else
  retirement_gate='{"present":false,"status":"missing"}'
  retirement_emit GATE_JSON "$retirement_gate"
  retirement_emit PLAN_JSON '{"present":false,"status":"no_gate"}'
fi

# This asks Docker for named scalar metadata only, never Env, raw inspect,
# GraphDriver paths, log contents, container exec or a writable-layer size walk.
retirement_inspect_format='{"id":{{json .Id}},"status":{{json .State.Status}},"finished_at":{{json .State.FinishedAt}},"exit_code":{{json .State.ExitCode}},"oom_killed":{{json .State.OOMKilled}},"readonly_rootfs":{{json .HostConfig.ReadonlyRootfs}},"restart_policy":{"name":{{json .HostConfig.RestartPolicy.Name}},"maximum_retry_count":{{json .HostConfig.RestartPolicy.MaximumRetryCount}}},"storage_driver":{{json .GraphDriver.Name}},"log_options":{"max_size":{{json (index .HostConfig.LogConfig.Config "max-size")}},"max_file":{{json (index .HostConfig.LogConfig.Config "max-file")}},"compress":{{json (index .HostConfig.LogConfig.Config "compress")}}},"log_driver":{{json .HostConfig.LogConfig.Type}},"log_path":{{json .LogPath}},"legacy_release":{{json (index .Config.Labels "codex.xboard.release")}},"legacy_run":{{json (index .Config.Labels "codex.xboard.release.run")}},"stage":{{json (index .Config.Labels "codex.xboard.stage")}},"stage_run":{{json (index .Config.Labels "codex.xboard.stage.run")}}}'
retirement_inspect() {
  local result
  result=$(retirement_run docker inspect --format "$retirement_inspect_format" "$1" 2>/dev/null) || return 1
  ((${#result}<=16384)) || return 1
  retirement_jq -ceS --arg id "$1" '
    def run: .==null or .=="" or (type=="string" and test("^[0-9]+-[0-9]+$"));
    select(.id==$id and .status=="exited" and
      (.finished_at|type=="string" and test("^[0-9]{4}-[0-9T:Z.+-]+$")) and
      (.exit_code|type=="number" and floor==. and .>=0 and .<=255) and
      (.oom_killed|type=="boolean") and
      (.readonly_rootfs|type=="boolean") and
      (.restart_policy|type=="object" and (keys|sort)==["maximum_retry_count","name"] and
        (.name|IN("","no","always","unless-stopped","on-failure")) and
        (.maximum_retry_count|type=="number" and floor==. and .>=0 and .<=2147483647)) and
      (.storage_driver|type=="string" and length<=64 and (test("[[:cntrl:]]")|not) and test("^[a-z0-9_.-]*$")) and
      (.log_options|type=="object" and (keys|sort)==["compress","max_file","max_size"] and
        (.max_size|.==null or (type=="string" and length<=32 and (test("[[:cntrl:]]")|not) and test("^(-1|[0-9]+([.][0-9]+)?([kKmMgGtTpP]([iI]?[bB])?|[bB])?)?$"))) and
        (.max_file|.==null or (type=="string" and (test("[[:cntrl:]]")|not) and test("^([1-9][0-9]{0,8})?$"))) and
        (.compress|IN(null,"","true","false"))) and
      (.log_driver|IN("json-file","local","journald","none","syslog","fluentd","awslogs","gelf","splunk","gcplogs")) and
      (.log_path|type=="string" and length<=4096 and (test("[[:cntrl:]]")|not)) and
      all(.legacy_release,.stage;.==null or .=="" or .=="true" or .=="false") and
      all(.legacy_run,.stage_run;run))' <<< "$result"
}

retirement_docker_root=$(retirement_run docker info --format '{{.DockerRootDir}}' 2>/dev/null) || retirement_docker_root=''
if [[ ! "$retirement_docker_root" =~ ^/[A-Za-z0-9_./-]+$ || "$retirement_docker_root" == / ||
      ! -d "$retirement_docker_root" || -L "$retirement_docker_root" ||
      "$(retirement_run realpath -e -- "$retirement_docker_root" 2>/dev/null || true)" != "$retirement_docker_root" ]]; then
  retirement_docker_root=''
fi
retirement_log_metadata() {
  local id=$1 driver=$2 path=$3 metadata hash expected=''
  hash=$(printf '%s' "$path" | retirement_hash) || return 1
  if [[ -z "$path" ]]; then
    retirement_jq -cnS --arg driver "$driver" '{status:"no_file_path",driver:$driver,contents_read:false,rotated_logs:"not_investigated"}'; return
  fi
  case "$driver" in
    json-file) expected="$retirement_docker_root/containers/$id/$id-json.log" ;;
    local) expected="$retirement_docker_root/containers/$id/local-logs/container.log" ;;
  esac
  if [[ -z "$retirement_docker_root" || "$path" != "$expected" || ! -f "$path" || -L "$path" ||
        "$(retirement_run realpath -e -- "$path" 2>/dev/null || true)" != "$path" ]]; then
    retirement_jq -cnS --arg hash "$hash" --arg driver "$driver" \
      '{status:"unverified_path_or_missing",driver:$driver,path_sha256:$hash,contents_read:false,rotated_logs:"not_investigated"}'; return
  fi
  metadata=$(retirement_run stat -c '%d %i %s %b %B %Y %u %g %h' -- "$path") || return 1
  retirement_jq -cnS --arg metadata "$metadata" --arg hash "$hash" --arg driver "$driver" '
    ($metadata|split(" ")|map(tonumber)) as $m | select(($m|length)==9 and all($m[];.>=0)) |
    {status:"verified_metadata",driver:$driver,path_sha256:$hash,ownership:"exact_container_log_path",
     device:$m[0],inode:$m[1],size_bytes:$m[2],allocated_bytes:($m[3]*$m[4]),mtime_epoch:$m[5],
     owner_uid:$m[6],owner_gid:$m[7],hard_links:$m[8],contents_read:false,rotated_logs:"not_investigated"}'
}

retirement_targets=$(retirement_jq -ceS --argjson protection "$protection_snapshot" '
  [.containers[]|select(.status=="exited")|. as $c|select(all($protection.containers[];.id!=$c.id))] |
  select(length<=128 and all(.[];(.id|test("^[a-f0-9]{64}$"))))' "$retention_snapshot_file") || retirement_fail invalid_stopped_inventory
retirement_count=0
while IFS= read -r retirement_snapshot; do
  [[ -n "$retirement_snapshot" ]] || continue
  retirement_id=$(retirement_jq -r .id <<< "$retirement_snapshot")
  ((retirement_count+=1))
  if ((SECONDS>=retirement_deadline-15)); then retirement_fail investigation_time_budget; fi
  retirement_meta=$(retirement_inspect "$retirement_id") || retirement_fail stopped_container_changed_or_inspection_failed
  retirement_kind=unrelated
  retirement_release=''
  retirement_state_path=''
  retirement_project=$(retirement_jq -r .project <<< "$retirement_snapshot")
  retirement_maintenance=$(retirement_jq -r .maintenance_release <<< "$retirement_snapshot")
  if [[ "$retirement_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]]; then
    retirement_kind=v2; retirement_release=${BASH_REMATCH[1]}
    retirement_state_path="$lock_root/$retirement_release/state.json"
  elif [[ "$retirement_maintenance" =~ ^[0-9]+-[0-9]+$ ]]; then
    retirement_kind=maintenance; retirement_release=$retirement_maintenance
    retirement_state_path="$lock_root/$retirement_release/state.json"
  elif [[ "$(retirement_jq -r .legacy_release <<< "$retirement_meta")" == true ]]; then
    retirement_release=$(retirement_jq -r .legacy_run <<< "$retirement_meta")
    [[ "$retirement_release" =~ ^[0-9]+-[0-9]+$ ]] || retirement_fail invalid_legacy_release_id
    retirement_kind=legacy; retirement_state_path="$workdir/.codex-release/$retirement_release/state.json"
  elif [[ "$(retirement_jq -r .stage <<< "$retirement_meta")" == true ]]; then
    retirement_release=$(retirement_jq -r .stage_run <<< "$retirement_meta")
    [[ "$retirement_release" =~ ^[0-9]+-[0-9]+$ ]] || retirement_fail invalid_stage_run_id
    retirement_kind=stage
  fi
  retirement_state='{"present":false,"status":"no_associated_json_state"}'
  if [[ -n "$retirement_state_path" && (-e "$retirement_state_path" || -L "$retirement_state_path") ]]; then
    retirement_state=$(retirement_file "$retirement_state_path" 1048576 "$retirement_state_filter") || \
      retirement_state='{"present":true,"status":"unsafe_or_invalid","deletion_ready":false}'
    if [[ "$(retirement_jq -r .status <<< "$retirement_state")" == verified ]]; then
      retirement_jq -e --arg id "$retirement_release" '.safe.release_id==$id' <<< "$retirement_state" >/dev/null || retirement_fail historical_state_identity_mismatch
    fi
  fi
  # Writable-layer inspection can make the Docker daemon walk filesystem data
  # beyond the client's lifetime. Keep this audit metadata-only; deletion needs
  # a separately reviewed data/archive investigation, not a timeout assumption.
  retirement_writable_layer='{"status":"not_investigated_passive_only","deletion_ready":false,"contents_read":false,"byte_size":"not_measured"}'
  retirement_log='{"status":"unrelated_not_inspected","contents_read":false}'
  if [[ "$retirement_kind" != unrelated ]]; then
    retirement_log=$(retirement_log_metadata "$retirement_id" "$(retirement_jq -r .log_driver <<< "$retirement_meta")" \
      "$(retirement_jq -r .log_path <<< "$retirement_meta")") || retirement_fail log_metadata_failed
  fi
  retirement_after=$(retirement_inspect "$retirement_id") || retirement_fail stopped_container_changed_after_inspection
  [[ "$retirement_meta" == "$retirement_after" ]] || retirement_fail stopped_container_changed_after_inspection
  retirement_row=$(retirement_jq -cnS --arg id "$retirement_id" --arg kind "$retirement_kind" --arg release "$retirement_release" \
    --argjson metadata "$retirement_meta" --argjson state "$retirement_state" --argjson writable_layer "$retirement_writable_layer" \
    --argjson log "$retirement_log" --argjson snapshot "$retirement_snapshot" --argjson protection "$protection_snapshot" '
    {id:$id,kind:$kind,associated_release_id:$release,deletion_authorized:false,
     runtime:($metadata|{status,finished_at,exit_code,oom_killed}),
     storage:{readonly_rootfs:$metadata.readonly_rootfs,driver:$metadata.storage_driver},
     restart_policy:$metadata.restart_policy,
     log_configuration:{driver:$metadata.log_driver,options:$metadata.log_options,effective_limits_verified:false},
     state:$state,writable_layer:$writable_layer,log:$log,
     mounted_paths_preserved:true,mount_count:($snapshot.mounts|length),
     shares_protected_mount: any($snapshot.mounts[]; . as $m | any($protection.containers[].mounts[]?;
       (.Type==$m.Type and .Source==$m.Source and .Source!="") or (.Name!="" and .Name==$m.Name)))}') || retirement_fail container_projection_failed
  retirement_emit CONTAINER_JSON "$retirement_row"
done <<< "$(retirement_jq -c '.[]' <<< "$retirement_targets")"

# Rebind the projection and recovery evidence after the metadata calls. This
# detects concurrent writers without querying or reading production databases.
retirement_final_active=$(retirement_file "$active_state_file" 1048576 "$retirement_state_filter") || retirement_fail active_state_changed
[[ "$retirement_final_active" == "$retirement_active" ]] || retirement_fail active_state_changed
if [[ "$(retirement_jq -r .present <<< "$retirement_gate")" == true ]]; then
  [[ "$(retirement_file "$retirement_gate_path" 262144 "$retirement_gate_filter")" == "$retirement_gate" ]] || retirement_fail gate_changed
  if [[ -n "${retirement_plan:-}" ]]; then
    [[ "$(retirement_file "$retirement_plan_path" 8388608 "$retirement_plan_filter" true)" == "$retirement_plan" ]] || retirement_fail plan_changed
  fi
fi
retirement_emit STOPPED_NONPROTECTED_COUNT "$retirement_count"
printf 'RETIREMENT_READINESS=PASS mode=read_only deletion_authorized=false directories=preserved logs_read=false connection_continuity=unverified\n'
