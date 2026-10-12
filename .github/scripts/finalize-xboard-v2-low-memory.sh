#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_set_many >/dev/null || ! declare -F v2_open_release >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
  # shellcheck source=v2-low-memory-common.sh
  source "$script_dir/v2-low-memory-common.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"
: "${V2_FINALIZE_REASON:=retention_window}"
: "${V2_FINALIZE_MIN_AGE_SECONDS:=86400}"
: "${V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID:=}"
: "${RETENTION_MAX_OBJECTS_PER_RUN:=1}"
[[ "$RETENTION_MAX_OBJECTS_PER_RUN" =~ ^[1-5]$ ]] || v2_fail invalid_finalize_batch_limit
command -v timeout >/dev/null || v2_fail finalize_timeout_tool_missing
trap 'echo "V2_FINALIZE_INTERRUPTED=stop id=$RELEASE_ID durable_pending_record_preserved=true" >&2; exit 130' HUP INT TERM
case "$V2_FINALIZE_REASON" in
  retention_window)
    [[ "$V2_FINALIZE_MIN_AGE_SECONDS" =~ ^[0-9]+$ ]] && ((V2_FINALIZE_MIN_AGE_SECONDS >= 86400)) || v2_fail invalid_finalize_age
    ;;
  superseded)
    : "${SUPERSEDING_RELEASE_SHA:?SUPERSEDING_RELEASE_SHA is required for superseded finalize}"
    [[ "$SUPERSEDING_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || v2_fail invalid_superseding_release_sha
    [[ "$SUPERSEDING_RELEASE_SHA" != "$EXPECTED_RELEASE_SHA" ]] || v2_fail superseding_release_matches_active
    ;;
  *) v2_fail invalid_finalize_reason ;;
esac
[[ -z "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" ||
   "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" =~ ^[0-9a-f]{64}$ ]] || v2_fail invalid_previous_maintenance_id

v2_require_tools
v2_open_release # Holds the shared release lock before discovering any identity.
release_assert_cleanup_admission "$V2_WORKDIR"
case "$TRAFFIC_STATE" in
  active_v2|finalizing) ;;
  # A finalized rerun is deliberately fail-closed, not a second cleanup pass.
  *) v2_fail "invalid_finalize_state:$TRAFFIC_STATE" ;;
esac
switched_at=$(release_state_get "$V2_STATE_FILE" switched_at)
switched_epoch=$(date -u -d "$switched_at" +%s 2>/dev/null || true)
[[ "$switched_epoch" =~ ^[0-9]+$ ]] || v2_fail invalid_switch_timestamp
age_seconds=$(( $(date -u +%s) - switched_epoch ))
if [[ "$V2_FINALIZE_REASON" == retention_window ]]; then
  ((age_seconds >= V2_FINALIZE_MIN_AGE_SECONDS)) || v2_fail "rollback_window_open:$age_seconds"
fi
case "$TRAFFIC_STATE:$(release_state_get "$V2_STATE_FILE" rollback_supported)" in
  active_v2:true|finalizing:false) ;;
  *) v2_fail finalize_rollback_state_mismatch ;;
esac

v2_finalize_snapshot() {
  local reference=$1 inspected snapshot mount source volume identity config_sha host_sha enriched='[]'
  inspected=$(docker container inspect "$reference") || return 1
  config_sha=$(jq -cS '.[0].Config' <<<"$inspected" | sha256sum | awk '{print $1}') || return 1
  host_sha=$(jq -cS '.[0].HostConfig' <<<"$inspected" | sha256sum | awk '{print $1}') || return 1
  snapshot=$(jq -ceS --arg config_sha "$config_sha" --arg host_sha "$host_sha" '
    if length != 1 then error("ambiguous container") else .[0] end
    | {id:.Id, name:.Name, image:.Image, config_image:.Config.Image,
       labels:.Config.Labels, status:.State.Status, running:.State.Running,
       paused:.State.Paused, restarting:.State.Restarting, dead:.State.Dead,
       oom_killed:.State.OOMKilled, started_at:.State.StartedAt,
       restart_count:.RestartCount, health:(.State.Health.Status // "none"),
       config_sha256:$config_sha,host_config_sha256:$host_sha,
       live_ports:(.NetworkSettings.Ports // {}),
       networks:((.NetworkSettings.Networks // {}) | with_entries(.value |=
         {NetworkID,EndpointID,IPAddress,GlobalIPv6Address,Aliases})),
       bindings:(.HostConfig.PortBindings // {}),
       mounts:([.Mounts[]? | {Type,Source,Destination,Name:(.Name // ""),RW,Mode,Propagation}]
         | sort_by(.Destination))}
    | if (.id | test("^[0-9a-f]{64}$")) and (.image | test("^sha256:[0-9a-f]{64}$"))
      then . else error("incomplete container identity") end
  ' <<<"$inspected") || return 1
  while IFS= read -r mount; do
    source=$(jq -er '.Source' <<<"$mount") || return 1
    case "$(jq -er '.Type' <<<"$mount")" in
      bind)
        [[ "$source" == /* && -e "$source" && ! -L "$source" ]] || {
          v2_fail finalize_bind_source_invalid; return 1;
        }
        identity=$(stat -c '%d:%i' -- "$source") || return 1
        ;;
      volume)
        volume=$(jq -er '.Name | select(length > 0)' <<<"$mount") || return 1
        identity=$(docker volume inspect "$volume" | jq -ceS --arg source "$source" '
          if length == 1 and .[0].Mountpoint == $source then
            .[0] | {Name,Driver,Mountpoint,CreatedAt,Scope}
          else error("volume source mismatch") end
        ') || return 1
        identity+="|$(stat -c '%d:%i' -- "$source")" || return 1
        ;;
      tmpfs) identity=tmpfs ;;
      *) v2_fail finalize_mount_type_unknown; return 1 ;;
    esac
    enriched=$(jq -ceS --argjson mount "$mount" --arg identity "$identity" \
      '. + [$mount + {source_identity:$identity}]' <<<"$enriched") || return 1
  done < <(jq -c '.mounts[]' <<<"$snapshot")
  jq -ceS --argjson mounts "$enriched" '.mounts=$mounts' <<<"$snapshot"
}

v2_finalize_maintenance_snapshot() {
  local reference=$1 release=$2 release_dir=$3 port=$4 image=$5 snapshot image_id config_sha
  snapshot=$(v2_finalize_snapshot "$reference") || return 1
  image_id=$(docker image inspect -f '{{.Id}}' "$image") || return 1
  jq -e --arg release "$release" --arg port "$port" --arg image "$image" \
    --arg image_id "$image_id" --arg config "$release_dir/.docker/caddy/Caddyfile.maintenance" '
    .name == ("/xboard-v2-maintenance-" + $release) and .config_image == $image and .image == $image_id
    and .labels["codex.xboard.v2.maintenance"] == "true"
    and .labels["codex.xboard.v2.release"] == $release
    and .bindings == {"7001/tcp":[{HostIp:"127.0.0.1",HostPort:$port}]}
    and (.mounts | map(select(.Destination == "/etc/caddy/Caddyfile"))
      | length == 1 and .[0].Type == "bind" and .[0].Source == $config and .[0].RW == false)
  ' <<<"$snapshot" >/dev/null || { v2_fail maintenance_identity_mismatch; return 1; }
  config_sha=$(sha256sum -- "$release_dir/.docker/caddy/Caddyfile.maintenance" | awk '{print $1}') || return 1
  jq -ceS --arg config_sha "$config_sha" '. + {maintenance_config_sha256:$config_sha}' <<<"$snapshot"
}

v2_finalize_protected_snapshot() {
  local service id snapshot image_revision protected='[]' maintenance_image all_ids port_ids='' port_output caddy_sha
  [[ -f "$CADDY_CONFIG" && ! -L "$CADDY_CONFIG" ]] || { v2_fail finalize_caddy_invalid; return 1; }
  [[ "$(grep -Ec "reverse_proxy[[:space:]]+127\\.0\\.0\\.1:$ACTIVE_PORT([[:space:]]|$)" "$CADDY_CONFIG")" == 1 ]] || {
    v2_fail active_caddy_route_missing; return 1;
  }
  [[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$MAINTENANCE_PORT")" == 0 ]] || {
    v2_fail maintenance_caddy_route_still_active; return 1;
  }
  caddy_sha=$(sha256sum -- "$CADDY_CONFIG" | awk '{print $1}') || return 1
  all_ids=$(docker ps -q --no-trunc) || return 1
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    port_output=$(docker port "$id" 7001/tcp 2>/dev/null || true)
    if grep -Fxq "127.0.0.1:$ACTIVE_PORT" <<<"$port_output"; then port_ids+="$id"$'\n'; fi
  done <<<"$all_ids"
  [[ "${port_ids%$'\n'}" == "$(v2_service_id edge)" ]] || { v2_fail finalize_active_port_owner_mismatch; return 1; }
  for service in redis web ws edge horizon scheduler; do
    v2_service_healthy "$service" || { v2_fail "finalize_service_unhealthy:$service"; return 1; }
    id=$(v2_service_id "$service") || return 1
    snapshot=$(v2_finalize_snapshot "$id") || return 1
    jq -e --arg project "$PROJECT_NAME" --arg service "$service" '
      .labels["com.docker.compose.project"] == $project
      and .labels["com.docker.compose.service"] == $service
      and .running and (.paused or .restarting or .dead or .oom_killed | not)
    ' <<<"$snapshot" >/dev/null || { v2_fail finalize_current_identity_mismatch; return 1; }
    if [[ "$service" == web || "$service" == ws || "$service" == horizon || "$service" == scheduler ]]; then
      image_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' \
        "$(jq -er '.image' <<<"$snapshot")") || return 1
      [[ "$image_revision" == "$EXPECTED_RELEASE_SHA" ]] || { v2_fail finalize_current_image_mismatch; return 1; }
      jq -e --arg path "$APP_DATA_PATH" '.mounts | any(.Type == "bind" and .Destination == "/www/.docker/.data" and .Source == $path)' \
        <<<"$snapshot" >/dev/null || { v2_fail finalize_current_data_mismatch; return 1; }
    elif [[ "$service" == redis ]]; then
      jq -e --arg volume "$REDIS_VOLUME_NAME" '.mounts | any(.Type == "volume" and .Destination == "/data" and .Name == $volume)' \
        <<<"$snapshot" >/dev/null || { v2_fail finalize_current_redis_mismatch; return 1; }
    else
      jq -e --arg port "$ACTIVE_PORT" '.bindings == {"7001/tcp":[{HostIp:"127.0.0.1",HostPort:$port}]}' \
        <<<"$snapshot" >/dev/null || { v2_fail finalize_current_edge_binding_mismatch; return 1; }
    fi
    protected=$(jq -ceS --argjson item "$snapshot" '. + [$item]' <<<"$protected") || return 1
  done
  maintenance_image=$(release_state_get "$V2_STATE_FILE" maintenance_image) || return 1
  snapshot=$(v2_finalize_maintenance_snapshot "$MAINTENANCE_CONTAINER" "$RELEASE_ID" "$V2_RELEASE_DIR" \
    "$MAINTENANCE_PORT" "$maintenance_image") || return 1
  jq -e '.running and (.paused or .restarting or .dead or .oom_killed | not)' <<<"$snapshot" >/dev/null || {
    v2_fail finalize_current_maintenance_not_running; return 1;
  }
  protected=$(jq -ceS --argjson item "$snapshot" '. + [$item]' <<<"$protected") || return 1
  if [[ -n "${V2_ANCHOR_DISCOVERY_ID:-}" ]]; then
    snapshot=$(v2_finalize_snapshot "$V2_ANCHOR_DISCOVERY_ID") || return 1
    jq -e '.labels["com.docker.compose.service"] == "xboard"' <<<"$snapshot" >/dev/null || {
      v2_fail finalize_anchor_identity_mismatch; return 1;
    }
    protected=$(jq -ceS --argjson item "$snapshot" '. + [$item]' <<<"$protected") || return 1
  fi
  jq -e 'length == (map(.id) | unique | length)' <<<"$protected" >/dev/null || {
    v2_fail finalize_protected_identity_collision; return 1;
  }
  jq -cnS --arg sha "$EXPECTED_RELEASE_SHA" --arg route "127.0.0.1:$ACTIVE_PORT" --arg caddy_sha "$caddy_sha" \
    --argjson containers "$protected" '{sha:$sha,route:$route,caddy_sha256:$caddy_sha,containers:($containers|sort_by(.id))}'
}

v2_finalize_assert_protected() {
  local actual
  actual=$(v2_finalize_protected_snapshot) || return 1
  [[ "$actual" == "$finalize_protected" ]] || { v2_fail finalize_protected_identity_changed; return 1; }
  if [[ "$previous_release_id" != legacy ]]; then
    [[ "$(sha256sum -- "$V2_WORKDIR/.codex-v2-release/$previous_release_id/state.json" | awk '{print $1}')" == "$finalize_previous_state_sha" ]] || {
      v2_fail finalize_previous_state_changed; return 1;
    }
  fi
}

if ! declare -F retention_review_validate_finalize >/dev/null; then
  retention_review_validate_finalize() (
    set -Eeuo pipefail
    local plan=$1 review snapshots protection ids
    local workdir=$V2_WORKDIR active_project=$PROJECT_NAME active_state_file=$V2_STATE_FILE
    local active_container active_image_id active_revision=$EXPECTED_RELEASE_SHA
    local active_app_data_path=$APP_DATA_PATH active_redis_volume=$REDIS_VOLUME_NAME
    local EXPECTED_ACTIVE_RELEASE_ID=$RELEASE_ID EXPECTED_ACTIVE_RELEASE_SHA=$EXPECTED_RELEASE_SHA
    local EXPECTED_RETENTION_RESOURCE_FINGERPRINT
    declare -F retention_load_review >/dev/null && declare -F retention_resource_fingerprint >/dev/null || {
      v2_fail finalize_review_validator_missing; return 1;
    }
    active_container=$(v2_service_id web)
    active_image_id=$(docker inspect -f '{{.Image}}' "$active_container")
    review=$(mktemp "$V2_RELEASE_DIR/.finalize-review.XXXXXX")
    snapshots=$(mktemp "$V2_RELEASE_DIR/.finalize-inventory.XXXXXX")
    trap 'rm -f -- "$review" "$snapshots"' EXIT
    retention_all_snapshots > "$snapshots"
    protection=$(retention_live_protection)
    EXPECTED_RETENTION_RESOURCE_FINGERPRINT=$(retention_resource_fingerprint "$snapshots" "$protection")
    retention_load_review "$review" v2_finalize || { v2_fail finalize_review_not_approved; return 1; }
    jq -e --arg protection "$(printf '%s\n' "$protection" | retention_hash)" \
      '(.images|length)==0 and .protection_fingerprint==$protection' "$review" >/dev/null || {
      v2_fail finalize_review_protection_mismatch; return 1;
    }
    ids=$(jq -r '.containers[].id' <<<"$plan")
    retention_verify_reviewed_containers "$review" "$ids" || { v2_fail finalize_review_container_mismatch; return 1; }
  )
fi

v2_assert_v2_owners
finalize_protected=$(v2_finalize_protected_snapshot)
mapfile -t legacy_ids < <(v2_legacy_ids)
for id in "${legacy_ids[@]}"; do
  [[ "$id" =~ ^[0-9a-f]{64}$ ]] || v2_fail finalize_legacy_id_not_full
done
legacy_ids_json=$(printf '%s\n' "${legacy_ids[@]}" | jq -Rc . | jq -sc 'sort')
jq -e 'length == (unique | length)' <<<"$legacy_ids_json" >/dev/null || v2_fail finalize_legacy_identity_collision
previous_release_id=$(release_state_get_optional "$V2_STATE_FILE" previous_release_retiring_id)
previous_maintenance='none'
finalize_previous_state_sha=none
finalize_batch_limit=$RETENTION_MAX_OBJECTS_PER_RUN
if [[ "$V2_FINALIZE_REASON" == retention_window ]] && ((finalize_batch_limit > 2)); then finalize_batch_limit=2; fi

if [[ "$TRAFFIC_STATE" == active_v2 ]]; then
  # Manual retention always starts with a one-object canary batch. Signed
  # normal release rotation remains a complete, identity-bound operation.
  [[ "$V2_FINALIZE_REASON" != retention_window ]] || finalize_batch_limit=1
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    previous_project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$LEGACY_ANCHOR_ID")
    [[ "$previous_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]] || v2_fail previous_v2_project_invalid
    previous_release_id=${BASH_REMATCH[1]}
    [[ "$previous_release_id" != "$RELEASE_ID" ]] || v2_fail previous_release_matches_current
    previous_state="$V2_WORKDIR/.codex-v2-release/$previous_release_id/state.json"
    [[ "$(realpath -e -- "$previous_state")" == "$previous_state" ]] || v2_fail previous_release_state_path_mismatch
    [[ "$(release_state_get "$previous_state" release_id)" == "$previous_release_id" &&
       "$(release_state_get "$previous_state" project_name)" == "$previous_project" &&
       "$(release_state_get "$previous_state" release_sha)" == "$(release_state_get "$V2_STATE_FILE" legacy_release_sha)" ]] ||
      v2_fail previous_release_state_mismatch
    finalize_previous_state_sha=$(sha256sum -- "$previous_state" | awk '{print $1}')
  else
    previous_release_id=legacy
    [[ "$LEGACY_ANCHOR_ID" == "${V2_ANCHOR_DISCOVERY_ID:-}" ]] || v2_fail finalize_anchor_discovery_mismatch
  fi
  finalize_containers='[]'
  for id in "${legacy_ids[@]}"; do
    if [[ "$LEGACY_TOPOLOGY" == legacy && "$id" == "$LEGACY_ANCHOR_ID" ]]; then continue; fi
    snapshot=$(v2_finalize_snapshot "$id")
    [[ "$(jq -er '.id' <<<"$snapshot")" == "$id" ]] || v2_fail finalize_recorded_container_changed
    jq -e '.status == "exited" and (.running or .paused or .restarting or .dead | not)' <<<"$snapshot" >/dev/null ||
      v2_fail legacy_container_not_exited
    if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
      case "$id" in
        "$LEGACY_ANCHOR_ID") expected_role=redis ;;
        "$LEGACY_WEB_ID") expected_role=web ;;
        "$LEGACY_WS_ID") expected_role=ws ;;
        "$LEGACY_EDGE_ID") expected_role=edge ;;
        "$LEGACY_HORIZON_ID") expected_role=horizon ;;
        "$LEGACY_SCHEDULER_ID") expected_role=scheduler ;;
      esac
      jq -e --arg project "$previous_project" --arg role "$expected_role" \
        '.labels["com.docker.compose.project"] == $project and .labels["com.docker.compose.service"] == $role' \
        <<<"$snapshot" >/dev/null ||
        v2_fail previous_container_project_mismatch
      if [[ "$expected_role" != redis && "$expected_role" != edge ]]; then
        old_image_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' \
          "$(jq -er '.image' <<<"$snapshot")")
        [[ "$old_image_revision" == "$(release_state_get "$V2_STATE_FILE" legacy_release_sha)" ]] ||
          v2_fail previous_container_image_mismatch
      fi
    fi
    finalize_containers=$(jq -ceS --argjson item "$snapshot" '. + [$item]' <<<"$finalize_containers")
  done
  # A predecessor maintenance proxy is not an implicit deletion target. It may
  # retire only via a separate explicit full-ID approval and only when exited.
  if [[ -n "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" ]]; then
    [[ "$LEGACY_TOPOLOGY" == v2 ]] || v2_fail previous_maintenance_without_v2_predecessor
    snapshot=$(v2_finalize_maintenance_snapshot "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" "$previous_release_id" \
      "$(dirname -- "$previous_state")" "$(release_state_get "$previous_state" maintenance_port)" \
      "$(release_state_get "$previous_state" maintenance_image)")
    [[ "$(jq -er '.id' <<<"$snapshot")" == "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" ]] || v2_fail previous_maintenance_identity_mismatch
    jq -e '.status == "exited" and (.running or .paused or .restarting or .dead | not)' <<<"$snapshot" >/dev/null ||
      v2_fail previous_maintenance_not_exited
    previous_maintenance=$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID
    finalize_containers=$(jq -ceS --argjson item "$snapshot" '. + [$item]' <<<"$finalize_containers")
  fi
  jq -e --argjson protected "$finalize_protected" '
    (map(.id) | length == (unique | length)) and
    all(.[]; .id as $id | $protected.containers | all(.[]; .id != $id))
  ' <<<"$finalize_containers" >/dev/null || v2_fail finalize_candidate_is_protected
  while IFS= read -r port; do
    [[ "$port" =~ ^[0-9]+$ && "$(v2_caddy_reference_count "$CADDY_CONFIG" "$port")" == 0 ]] ||
      v2_fail finalize_old_container_still_routed
  done < <(jq -r '.[].bindings[]?[]? | .HostPort' <<<"$finalize_containers" | sort -u)
  finalize_plan=$(jq -cnS --argjson protected "$finalize_protected" --argjson containers "$finalize_containers" \
    --argjson legacy_ids "$legacy_ids_json" --arg reason "$V2_FINALIZE_REASON" --arg superseding "${SUPERSEDING_RELEASE_SHA:-}" \
    --arg previous "$previous_release_id" --arg previous_maintenance "$previous_maintenance" --arg previous_state_sha "$finalize_previous_state_sha" \
    '{schema:1,reason:$reason,superseding_sha:$superseding,previous_release_id:$previous,
      previous_maintenance:$previous_maintenance,previous_state_sha256:$previous_state_sha,
      legacy_ids:$legacy_ids,protected:$protected,containers:($containers|sort_by(.id))}')
  if [[ "$V2_FINALIZE_REASON" == retention_window ]]; then
    declare -F retention_review_validate_finalize >/dev/null || v2_fail finalize_review_validator_missing
    retention_review_validate_finalize "$finalize_plan"
  fi
  finalize_plan_sha=$(printf '%s' "$finalize_plan" | sha256sum | awk '{print $1}')
  release_state_set_many "$V2_STATE_FILE" \
    traffic_state finalizing rollback_supported false finalizing_at "$(date -u +%FT%TZ)" finalize_reason "$V2_FINALIZE_REASON" \
    previous_release_retiring_id "$previous_release_id" superseded_by_sha "${SUPERSEDING_RELEASE_SHA:-}" \
    finalize_plan "$finalize_plan" finalize_plan_sha256 "$finalize_plan_sha" \
    finalize_completed_ids '[]' finalize_pending_id '' finalize_previous_maintenance_id "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" \
    finalize_review_sha256 "${EXPECTED_RETENTION_REVIEW_SHA256:-none}"
else
  # Pre-hardening partial attempts without a frozen manifest need review.
  finalize_plan=$(release_state_get "$V2_STATE_FILE" finalize_plan)
  finalize_plan_sha=$(release_state_get "$V2_STATE_FILE" finalize_plan_sha256)
  [[ "$(printf '%s' "$finalize_plan" | sha256sum | awk '{print $1}')" == "$finalize_plan_sha" ]] || v2_fail finalize_plan_checksum_mismatch
  if [[ "$V2_FINALIZE_REASON" == retention_window ]]; then
    [[ -n "${RETENTION_REVIEW_MANIFEST_B64:-}" &&
       "$(release_state_get "$V2_STATE_FILE" finalize_review_sha256)" == "${EXPECTED_RETENTION_REVIEW_SHA256:-}" &&
       "$(printf '%s' "$RETENTION_REVIEW_MANIFEST_B64" | base64 -d | sha256sum | awk '{print $1}')" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] ||
      v2_fail finalize_retry_review_changed
  fi
  jq -e --arg reason "$V2_FINALIZE_REASON" --arg sha "${SUPERSEDING_RELEASE_SHA:-}" \
    --argjson protected "$finalize_protected" --argjson ids "$legacy_ids_json" '
    .schema == 1 and .reason == $reason and .superseding_sha == $sha and .protected == $protected and .legacy_ids == $ids
  ' <<<"$finalize_plan" >/dev/null || v2_fail finalize_retry_identity_changed
  [[ "$(release_state_get "$V2_STATE_FILE" finalize_previous_maintenance_id)" == "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" ]] ||
    v2_fail previous_maintenance_changed_during_retry
  previous_release_id=$(jq -er '.previous_release_id' <<<"$finalize_plan")
  finalize_previous_state_sha=$(jq -er '.previous_state_sha256' <<<"$finalize_plan")
  previous_maintenance=$(jq -er '.previous_maintenance' <<<"$finalize_plan")
  finalize_containers=$(jq -ceS '.containers' <<<"$finalize_plan")
fi

v2_finalize_assert_protected

completed_ids=$(release_state_get "$V2_STATE_FILE" finalize_completed_ids)
pending_id=$(release_state_get "$V2_STATE_FILE" finalize_pending_id)
finalize_target_count=$(jq -er '.containers | length' <<<"$finalize_plan")
[[ "$V2_FINALIZE_REASON" != superseded ]] || finalize_batch_limit=$finalize_target_count
completed_this_run=0
jq -e --argjson plan "$finalize_plan" 'type == "array" and length == (unique|length) and
  all(.[]; . as $id | $plan.containers | any(.id == $id))' <<<"$completed_ids" >/dev/null || v2_fail finalize_invalid_ledger
[[ -z "$pending_id" ]] || jq -e --arg id "$pending_id" 'any(.containers[]; .id == $id)' <<<"$finalize_plan" >/dev/null ||
  v2_fail finalize_invalid_pending_id

while IFS= read -r recorded; do
  id=$(jq -er '.id' <<<"$recorded")
  all_ids=$(docker ps -aq --no-trunc) # A daemon failure is not "already absent".
  if jq -e --arg id "$id" 'index($id) != null' <<<"$completed_ids" >/dev/null; then
    ! grep -Fxq "$id" <<<"$all_ids" || v2_fail finalize_completed_container_reappeared
    continue
  fi
  v2_finalize_assert_protected
  if grep -Fxq "$id" <<<"$all_ids"; then
    actual=$(v2_finalize_snapshot "$id")
    if [[ "$id" == "$previous_maintenance" ]]; then
      actual=$(v2_finalize_maintenance_snapshot "$id" "$previous_release_id" \
        "$V2_WORKDIR/.codex-v2-release/$previous_release_id" \
        "$(release_state_get "$V2_WORKDIR/.codex-v2-release/$previous_release_id/state.json" maintenance_port)" \
        "$(release_state_get "$V2_WORKDIR/.codex-v2-release/$previous_release_id/state.json" maintenance_image)")
    fi
    [[ "$actual" == "$recorded" ]] || v2_fail finalize_old_identity_changed
    release_state_set_many "$V2_STATE_FILE" finalize_pending_id "$id" finalize_last_attempt_at "$(date -u +%FT%TZ)"
    # A hung Docker deletion is bounded. An interruption keeps the pending ID
    # durable; no subsequent object can run until a reviewed resume.
    timeout --signal=TERM --kill-after=5s 60s docker rm "$id" >/dev/null
    all_ids=$(docker ps -aq --no-trunc)
    ! grep -Fxq "$id" <<<"$all_ids" || v2_fail finalize_remove_not_confirmed
  else
    [[ "$pending_id" == "$id" ]] || v2_fail finalize_unexplained_missing_container
  fi
  completed_ids=$(jq -ceS --arg id "$id" '. + [$id] | unique' <<<"$completed_ids")
  release_state_set_many "$V2_STATE_FILE" finalize_completed_ids "$completed_ids" finalize_pending_id ''
  pending_id=''
  v2_finalize_assert_protected
  ((completed_this_run += 1))
  if ((completed_this_run >= finalize_batch_limit)) &&
      [[ "$(jq -r 'length' <<<"$completed_ids")" != "$finalize_target_count" ]]; then
    v2_assert_v2_owners
    release_state_set_many "$V2_STATE_FILE" finalize_status pending_batch finalize_batch_completed_at "$(date -u +%FT%TZ)"
    echo 'V2_FINALIZE_STATUS=pending_batch'
    echo "V2_FINALIZE=PASS result=pending_batch id=$RELEASE_ID traffic_state=finalizing rollback_supported=false completed_this_run=$completed_this_run completed_total=$(jq -r 'length' <<<"$completed_ids") total=$finalize_target_count plan_sha256=$finalize_plan_sha current_maintenance=preserved"
    exit 0
  fi
done < <(jq -cS '.containers[]' <<<"$finalize_plan")

v2_finalize_assert_protected
v2_assert_v2_owners
release_state_set_many "$V2_STATE_FILE" \
  traffic_state finalized finalized_at "$(date -u +%FT%TZ)" rollback_supported false \
  finalize_status complete \
  previous_release_retired_id "$previous_release_id" previous_maintenance_retired "$previous_maintenance" \
  compose_anchor_preserved "${V2_ANCHOR_DISCOVERY_ID:-external}" \
  current_maintenance_preserved "$(jq -er --arg name "/$MAINTENANCE_CONTAINER" '.containers[] | select(.name == $name) | .id' <<<"$finalize_protected")"

echo 'V2_FINALIZE_STATUS=complete'
echo "V2_FINALIZE=PASS result=complete id=$RELEASE_ID reason=$V2_FINALIZE_REASON age_seconds=$age_seconds traffic_state finalized rollback_supported=false legacy_containers=retired current_maintenance=preserved previous_maintenance=$previous_maintenance compose_anchor=preserved volumes=preserved plan_sha256=$finalize_plan_sha"
