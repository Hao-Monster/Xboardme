#!/usr/bin/env bash
# Source-only, read-only validators for one explicitly reviewed pre-delete HOLD.
# They neither acquire locks nor archive/change gates. The caller must already
# own deploy.lock and must repeat validation while holding image-cleanup-gate.lock
# immediately before archiving the old bytes and installing a fresh RUNNING gate.
# Source retention-continuity.sh first. No environment variable grants recovery.

_rc_predelete_recovery_error() {
  printf 'RETENTION_PREDELETE_RECOVERY_FAIL=%s\n' "$1" >&2
  return 1
}

retention_predelete_recovery_schema_validate() {
  [[ $# == 1 ]] || { _rc_predelete_recovery_error invalid_schema_arguments; return 1; }
  declare -F _rc_jq >/dev/null || { _rc_predelete_recovery_error validator_helper_missing; return 1; }
  _rc_jq -e '
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    def sha: type=="string" and test("^[a-f0-9]{40}$");
    . as $review | .recovery as $r |
    $review.schema_version==3 and $review.status=="approved" and
    $review.operation=="image_cleanup" and $review.containers==[] and
    ($review.images|type=="array" and length==1) and
    ($review.resource_fingerprint|hash) and ($review.protection_fingerprint|hash) and
    $review.monitoring.mode=="passive_runtime_v1" and
    ($r|type=="object" and keys==[
      "gate_sha256","image_id","mode","plan_sha256","review_sha256",
      "schema_version","session_id","workflow_sha"]) and
    $r.schema_version==1 and $r.mode=="predelete_hold_v1" and
    ($r.gate_sha256|hash) and ($r.plan_sha256|hash) and ($r.review_sha256|hash) and
    ($r.workflow_sha|sha) and
    ($r.session_id|type=="string" and test("^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")) and
    ($r.image_id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
    $review.images[0].id==$r.image_id
  ' "$1" >/dev/null 2>&1 || { _rc_predelete_recovery_error invalid_recovery_schema; return 1; }
}

# Arguments: new review file, old gate file, old immutable plan file, fresh full
# inventory file, fresh startup-reference file, fresh active-state file, fresh
# protection JSON string, current full workflow SHA. Freshness, exact-main
# admission, real live collection and the ordinary image review/restore checks
# remain mandatory caller responsibilities; these validators do not replace them.
retention_predelete_recovery_validate() {
  [[ $# == 8 ]] || { _rc_predelete_recovery_error invalid_arguments; return 1; }
  local review=$1 gate=$2 plan=$3 inventory=$4 startup=$5 state=$6 protection=$7 workflow=$8
  local helper path root old_review_hash gate_hash plan_hash review_hash state_hash
  local protection_hash startup_hash resource_hash image_hash resource_document image_document
  for helper in _rc_run _rc_jq _rc_hash _rc_secure_file _rc_gate_validate retention_continuity_validate_review; do
    declare -F "$helper" >/dev/null || { _rc_predelete_recovery_error validator_helper_missing; return 1; }
  done
  [[ "$workflow" =~ ^[a-f0-9]{40}$ ]] || { _rc_predelete_recovery_error invalid_workflow_sha; return 1; }
  # The failed attempt has exited. Its temporary plan hardlink must already
  # have been unlinked by the normal EXIT trap; unexplained extra links fail
  # closed and are not removed here. The new attempt's live plan is not passed.
  for path in "$review" "$gate" "$plan" "$inventory" "$startup" "$state"; do
    [[ "$path" == /* && -f "$path" && ! -L "$path" ]] &&
      [[ "$(_rc_run realpath -e -- "$path")" == "$path" ]] &&
      _rc_secure_file "$path" || { _rc_predelete_recovery_error unsafe_evidence_file; return 1; }
  done
  retention_predelete_recovery_schema_validate "$review" || return 1
  retention_continuity_validate_review "$review" || { _rc_predelete_recovery_error invalid_monitoring_review; return 1; }
  _rc_gate_validate "$gate" || { _rc_predelete_recovery_error invalid_old_gate; return 1; }
  old_review_hash=$(_rc_jq -er '.recovery.review_sha256' "$review") || return 1
  root=${gate%/*}
  [[ "$gate" == "$root/image-cleanup-gate.json" &&
     "$plan" == "$root/image-cleanup-plan-$old_review_hash.json" &&
     "$state" == "$root/$(_rc_jq -er .active_release_id "$review")/state.json" ]] || {
    _rc_predelete_recovery_error evidence_path_mismatch; return 1;
  }
  gate_hash=$(_rc_hash < "$gate") && plan_hash=$(_rc_hash < "$plan") &&
    review_hash=$(_rc_hash < "$review") && state_hash=$(_rc_hash < "$state") &&
    startup_hash=$(_rc_hash < "$startup") &&
    protection_hash=$(printf '%s\n' "$protection" | _rc_hash) || {
      _rc_predelete_recovery_error evidence_hash_failed; return 1;
    }
  # Use the same canonical inventory/protection/lifecycle envelope as
  # retention_resource_fingerprint, without any global path or host discovery.
  resource_document=$(_rc_jq -cnS --slurpfile inventory "$inventory" --slurpfile state "$state" \
    --argjson protection "$protection" '
      {inventory:$inventory[0],protection:$protection,
       lifecycle:($state[0]|{release_id,release_sha,traffic_state,rollback_supported,
         legacy_release_sha,legacy_anchor_id,legacy_web_id,legacy_ws_id,legacy_edge_id,
         legacy_horizon_id,legacy_scheduler_id,maintenance_container})}' 2>/dev/null) || {
      _rc_predelete_recovery_error evidence_hash_failed; return 1;
    }
  resource_hash=$(printf '%s\n' "$resource_document" | _rc_hash) || {
    _rc_predelete_recovery_error evidence_hash_failed; return 1;
  }
  image_document=$(_rc_jq -ceS '.images | if length==1 then .[0] else error("invalid count") end' "$plan" 2>/dev/null) || {
    _rc_predelete_recovery_error invalid_old_plan; return 1;
  }
  image_hash=$(printf '%s\n' "$image_document" | _rc_hash) || {
    _rc_predelete_recovery_error evidence_hash_failed; return 1;
  }
  _rc_jq -ne --slurpfile review "$review" --slurpfile gate "$gate" --slurpfile plan "$plan" \
    --slurpfile inventory "$inventory" --slurpfile startup "$startup" --slurpfile state "$state" \
    --argjson protection "$protection" --arg workflow "$workflow" \
    --arg gate_hash "$gate_hash" --arg plan_hash "$plan_hash" --arg review_hash "$review_hash" \
    --arg state_hash "$state_hash" --arg protection_hash "$protection_hash" \
    --arg startup_hash "$startup_hash" --arg resource_hash "$resource_hash" --arg image_hash "$image_hash" '
      (($review|length)==1 and ($gate|length)==1 and ($plan|length)==1 and
       ($inventory|length)==1 and ($startup|length)==1 and ($state|length)==1) as $single |
      ($review[0]) as $r | ($gate[0]) as $g | ($plan[0]) as $p |
      ($inventory[0]) as $i | ($state[0]) as $s |
      $single and $r.recovery.gate_sha256==$gate_hash and $r.recovery.plan_sha256==$plan_hash and
      $r.recovery.review_sha256==$g.review_sha256 and $review_hash!=$g.review_sha256 and
      $r.recovery.session_id==$g.session_id and $r.recovery.workflow_sha==$g.workflow_sha and
      $workflow!=$g.workflow_sha and $r.recovery.image_id==$g.image_id and
      $g.schema_version==2 and $g.monitoring_mode=="passive_runtime_v1" and
      $g.status=="HOLD" and $g.cleanup_validated==false and $g.sample_count==0 and
      ($g.failure_reason|type=="string" and test("^[a-zA-Z0-9_:-]{1,120}$")) and
      (["last_heartbeat_uptime","baseline_started_at_uptime","baseline_completed_at_uptime",
        "intent_at_uptime","removed_at_uptime","observation_completed_at_uptime",
        "completed_at_uptime","post_evidence_sha256","post_verified_at_uptime"] |
        all(.[]; . as $key | $g | has($key))) and
      $g.last_heartbeat_uptime==null and $g.baseline_started_at_uptime==null and
      $g.baseline_completed_at_uptime==null and $g.intent_at_uptime==null and
      $g.removed_at_uptime==null and $g.observation_completed_at_uptime==null and
      $g.completed_at_uptime==null and $g.post_evidence_sha256==null and
      $g.post_verified_at_uptime==null and $g.post_verification!="passed" and
      $g.active_release_id==$r.active_release_id and $g.active_release_sha==$r.active_release_sha and
      $g.protection_fingerprint==$r.protection_fingerprint and
      ($p|type=="object" and keys==["active_state_sha256","containers","images","inventory",
        "operation","review_sha256","schema_version","startup","workflow_sha"]) and
      $p.schema_version==2 and $p.operation=="image_cleanup" and $p.containers==[] and
      ($p.images|type=="array" and length==1) and $p.images[0].id==$g.image_id and
      $p.review_sha256==$g.review_sha256 and $p.workflow_sha==$g.workflow_sha and
      $p.active_state_sha256==$state_hash and $p.inventory==$i and $p.startup==$startup[0] and
      ($i|type=="object" and keys==["containers","images"]) and
      ($i.containers|type=="array" and length>=6) and ($i.images|type=="array" and length>=1) and
      ([$i.containers[].id]|length==(unique|length)) and
      ([$i.images[].id]|length==(unique|length)) and
      ([$i.images[]|select(.id==$g.image_id)]==[$p.images[0]]) and
      all($i.containers[];.image_id!=$g.image_id) and
      $r.images[0].snapshot_sha256==$image_hash and
      $r.images[0].evidence.startup_reference_review_sha256==$startup_hash and
      $r.protection_fingerprint==$protection_hash and $r.resource_fingerprint==$resource_hash and
      ($protection|type=="object") and $s.schema_version==2 and
      $s.release_id==$r.active_release_id and $s.release_sha==$r.active_release_sha and
      $s.project_name==("xboard-v2-"+$r.active_release_id) and
      ([$i.containers[]|{id,status,started_at,restart_count}]|sort_by(.id))==
        ($r.monitoring.protected_containers|sort_by(.id))
    ' >/dev/null 2>&1 || { _rc_predelete_recovery_error recovery_evidence_mismatch; return 1; }
}
