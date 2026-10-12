#!/usr/bin/env bash
# Source-only sampled HOLD proof checks. The protected runner validates the
# complete original operation and trusted timed trace; this host independently
# binds the small proof to the actual gate, immutable plan and fresh inventory.
# A proof is not an override for the ordinary ownership/restore/runtime gates.

retention_sampled_recovery_schema_validate() {
  [[ $# == 1 ]] || return 1
  _rc_jq -e '
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    . as $review | .recovery as $r |
    $review.schema_version==3 and $review.status=="approved" and
    $review.operation=="image_cleanup" and $review.containers==[] and
    ($review.images|type=="array" and length==1) and
    ($review.resource_fingerprint|hash) and ($review.protection_fingerprint|hash) and
    $review.monitoring.mode=="passive_runtime_v1" and
    ($r|type=="object" and keys==["gate_sha256","image_id","mode","plan_sha256",
      "proof_sha256","review_sha256","schema_version","session_id","workflow_sha"]) and
    $r.schema_version==2 and $r.mode=="predelete_sampled_hold_v2" and
    ([$r.gate_sha256,$r.plan_sha256,$r.proof_sha256,$r.review_sha256]|all(.[];hash)) and
    ($r.workflow_sha|type=="string" and test("^[a-f0-9]{40}$")) and
    ($r.session_id|type=="string" and test("^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")) and
    ($r.image_id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
    $review.images[0].id==$r.image_id
  ' "$1" >/dev/null 2>&1
}

_rc_sampled_proof_schema() {
  _rc_jq -se '
    def integer: type=="number" and floor==. and .>=0 and .<=9007199254740991;
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    length==1 and (.[0] |
      type=="object" and keys==["audit","gate","gate_sha256","mode","plan_sha256","schema_version","source","trace"] and
      .schema_version==1 and .mode=="sampled_predelete_trace_v1" and
      (.gate_sha256|hash) and (.plan_sha256|hash) and
      (.gate|type=="object" and keys==[
        "active_release_id","active_release_sha","baseline_completed_at_uptime","baseline_seconds",
        "baseline_started_at_uptime","cleanup_validated","completed_at_uptime","failure_reason","image_id",
        "intent_at_uptime","last_heartbeat_uptime","monitoring_mode","observation_completed_at_uptime",
        "observe_seconds","owner_uid","post_evidence_sha256","post_verification","post_verified_at_uptime",
        "protection_fingerprint","removed_at_uptime","review_sha256","sample_count","sample_interval_seconds",
        "schema_version","session_id","started_at_uptime","status","workflow_sha"]) and
      (.source|type=="object" and keys==["alignment_report_sha256","branch","conclusion","event",
        "operation_artifact_sha256","raw_workflow_log_sha256","repository","run_attempt","run_id","timeline_sha256","workflow_sha"] and
        .repository=="Hao-Monster/Xboardme" and .run_attempt==1 and
        (.run_id|type=="string" and test("^[1-9][0-9]{0,14}$")) and
        .event=="workflow_dispatch" and .branch=="main" and .conclusion=="failure" and
        (.workflow_sha|type=="string" and test("^[a-f0-9]{40}$")) and
        ([.alignment_report_sha256,.operation_artifact_sha256,.raw_workflow_log_sha256,.timeline_sha256]|all(.[];hash))) and
      (.audit|type=="object" and keys==["active_state_sha256","container_count","image_count","inventory_sha256",
        "protection_fingerprint","resource_fingerprint","startup_sha256"] and
        ([.active_state_sha256,.inventory_sha256,.protection_fingerprint,.resource_fingerprint,.startup_sha256]|all(.[];hash)) and
        (.container_count|integer and .>=6 and .<=128) and (.image_count|integer and .>=1 and .<=8192)) and
      (.trace|type=="object" and keys==["baseline_after_samples","baseline_wall_nanoseconds","sample_uptimes"] and
        (.sample_uptimes|type=="array" and length>=13 and length<=256 and all(.[];integer)) and
        (.baseline_after_samples|integer and .>=13 and .<=256) and
        (.baseline_wall_nanoseconds|type=="string" and test("^(0|[1-9][0-9]{0,14})$"))))
  ' "$1" >/dev/null 2>&1
}

# Install only reviewed, canonical, bounded proof bytes. An existing exact
# proof is reusable; no path from the transport is trusted and no file is
# overwritten. A crash-created extra hardlink remains a failure, not cleanup.
retention_sampled_proof_install() (
  [[ $# == 1 ]] || return 1
  local review=$1 encoded=${RETENTION_SAMPLED_PROOF_B64:-} proof_hash destination temporary='' size
  _rc_assert_deploy_lock && retention_sampled_recovery_schema_validate "$review" || return 1
  [[ "$(_rc_hash < "$review")" == "${EXPECTED_RETENTION_REVIEW_SHA256:-}" ]] || return 1
  [[ -n "$encoded" && ${#encoded} -le 21848 && $((${#encoded}%4)) == 0 &&
     "$encoded" =~ ^[A-Za-z0-9+/]+={0,2}$ ]] || return 1
  proof_hash=$(_rc_jq -er '.recovery.proof_sha256' "$review") || return 1
  destination="$RC_ROOT/image-cleanup-proof-$proof_hash.json"
  temporary=$(_rc_run mktemp "$RC_ROOT/.sampled-proof.XXXXXX") || return 1
  trap '[[ -z "$temporary" ]] || _rc_run rm -f -- "$temporary"' EXIT
  _rc_run chmod 600 "$temporary" || return 1
  _rc_run base64 --decode <<< "$encoded" > "$temporary" 2>/dev/null || return 1
  size=$(_rc_run stat -Lc '%s' -- "$temporary") || return 1
  [[ "$size" =~ ^[0-9]+$ ]] && ((size>0 && size<=16384)) || return 1
  [[ "$(_rc_run base64 -w0 < "$temporary")" == "$encoded" &&
     "$(_rc_hash < "$temporary")" == "$proof_hash" ]] || return 1
  _rc_sampled_proof_schema "$temporary" || return 1
  [[ "$(_rc_jq -cS . "$temporary" | _rc_hash)" == "$proof_hash" ]] || return 1
  if [[ -e "$destination" || -L "$destination" ]]; then
    _rc_secure_file "$destination" && [[ "$(_rc_hash < "$destination")" == "$proof_hash" ]] || return 1
  else
    # link is create-only, unlike a rename that could replace an existing file.
    _rc_run ln -- "$temporary" "$destination" || return 1
  fi
  _rc_run rm -- "$temporary" || return 1
  temporary=''
  _rc_secure_file "$destination" && [[ "$(_rc_hash < "$destination")" == "$proof_hash" ]]
)

# Shared current-file/ownership/plan/protection checks remain in the caller.
# This additional validator does not read Docker or weaken any ordinary gate.
retention_sampled_recovery_validate() {
  [[ $# == 8 ]] || return 1
  local review=$1 gate=$2 plan=$3 inventory=$4 startup=$5 state=$6 protection=$7 workflow=$8
  local proof proof_hash size now inventory_hash startup_hash state_hash protection_hash
  retention_sampled_recovery_schema_validate "$review" || return 1
  proof_hash=$(_rc_jq -er '.recovery.proof_sha256' "$review") || return 1
  proof="${gate%/*}/image-cleanup-proof-$proof_hash.json"
  [[ -f "$proof" && ! -L "$proof" && "$(_rc_run realpath -e -- "$proof")" == "$proof" ]] &&
    _rc_secure_file "$proof" || return 1
  size=$(_rc_run stat -Lc '%s' -- "$proof") || return 1
  [[ "$size" =~ ^[0-9]+$ ]] && ((size>0 && size<=16384)) || return 1
  [[ "$(_rc_hash < "$proof")" == "$proof_hash" && "$(_rc_jq -cS . "$proof" | _rc_hash)" == "$proof_hash" ]] || return 1
  _rc_sampled_proof_schema "$proof" || return 1
  now=$(_rc_now) && inventory_hash=$(_rc_hash < "$inventory") && startup_hash=$(_rc_hash < "$startup") &&
    state_hash=$(_rc_hash < "$state") && protection_hash=$(printf '%s\n' "$protection" | _rc_hash) || return 1
  [[ "$now" =~ ^[0-9]{1,15}$ ]] || return 1
  _rc_jq -ne --slurpfile review "$review" --slurpfile gate "$gate" --slurpfile plan "$plan" \
    --slurpfile proof "$proof" --slurpfile inventory "$inventory" --argjson now "$now" \
    --arg workflow "$workflow" --arg inventory_hash "$inventory_hash" --arg startup_hash "$startup_hash" \
    --arg state_hash "$state_hash" --arg protection_hash "$protection_hash" '
    def clock: type=="number" and floor==. and .>=0 and .<=9007199254740991;
    $review[0] as $r | $gate[0] as $g | $proof[0] as $p | $p.trace as $t | $t.sample_uptimes as $u |
    ($t.baseline_wall_nanoseconds | if length<=9 then 0 else .[0:-9]|tonumber end) as $wall_seconds |
    ($t.baseline_wall_nanoseconds[-9:]|tonumber) as $wall_fraction |
    ($g.baseline_completed_at_uptime-$g.baseline_started_at_uptime) as $baseline_duration |
    ($proof|length)==1 and $p.gate==$g and $p.gate_sha256==$r.recovery.gate_sha256 and
    $p.plan_sha256==$r.recovery.plan_sha256 and $p.source.workflow_sha==$r.recovery.workflow_sha and
    $workflow!=$p.source.workflow_sha and
    $p.audit.inventory_sha256==$inventory_hash and $p.audit.startup_sha256==$startup_hash and
    $p.audit.active_state_sha256==$state_hash and $plan[0].active_state_sha256==$state_hash and
    $p.audit.protection_fingerprint==$protection_hash and $p.audit.protection_fingerprint==$r.protection_fingerprint and
    $p.audit.resource_fingerprint==$r.resource_fingerprint and
    $p.audit.container_count==($inventory[0].containers|length) and $p.audit.image_count==($inventory[0].images|length) and
    $g.failure_reason=="resource_limit_or_source_failed" and $g.post_verification!="passed" and
    ([$g.started_at_uptime,$g.baseline_started_at_uptime,$g.baseline_completed_at_uptime,
      $g.last_heartbeat_uptime,$g.sample_count]|all(.[];clock)) and
    $g.sample_count==($u|length) and $g.sample_count>=13 and
    $g.baseline_started_at_uptime==$u[0] and $g.last_heartbeat_uptime==$u[-1] and
    $u[0]>=$g.started_at_uptime and $u[0]-$g.started_at_uptime<=30 and $now>$u[-1] and
    all(range(1;($u|length)); . as $i | $u[$i]>$u[$i-1] and $u[$i]-$u[$i-1]<=30) and
    $baseline_duration>=120 and $g.baseline_completed_at_uptime<=$u[-1] and
    $t.baseline_after_samples<=($u|length) and
    $g.baseline_completed_at_uptime>=$u[$t.baseline_after_samples-1] and
    ($t.baseline_after_samples==($u|length) or $g.baseline_completed_at_uptime<=$u[$t.baseline_after_samples]) and
    $wall_seconds>=$baseline_duration-2 and
    ($wall_seconds<$baseline_duration+2 or ($wall_seconds==$baseline_duration+2 and $wall_fraction==0))
  ' >/dev/null 2>&1
}
