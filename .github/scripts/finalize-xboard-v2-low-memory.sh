#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_get >/dev/null || ! declare -F v2_open_release >/dev/null; then
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

v2_require_tools
v2_open_release
case "$TRAFFIC_STATE" in
  active_v2|finalizing) ;;
  *) v2_fail "invalid_finalize_state:$TRAFFIC_STATE" ;;
esac
switched_at=$(release_state_get "$V2_STATE_FILE" switched_at)
switched_epoch=$(date -u -d "$switched_at" +%s 2>/dev/null || true)
[[ "$switched_epoch" =~ ^[0-9]+$ ]] || v2_fail invalid_switch_timestamp
age_seconds=$(( $(date -u +%s) - switched_epoch ))
if [[ "$V2_FINALIZE_REASON" == retention_window ]]; then
  ((age_seconds >= V2_FINALIZE_MIN_AGE_SECONDS)) || v2_fail "rollback_window_open:$age_seconds"
fi

for service in redis web ws edge horizon scheduler; do
  v2_service_healthy "$service" || v2_fail "finalize_service_unhealthy:$service"
done
v2_assert_v2_owners
[[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$ACTIVE_PORT")" == 1 ]] || v2_fail active_caddy_route_missing
[[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$MAINTENANCE_PORT")" == 0 ]] || v2_fail maintenance_caddy_route_still_active

previous_release_id=$(release_state_get_optional "$V2_STATE_FILE" previous_release_retiring_id)
previous_maintenance=''
if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
  if [[ -z "$previous_release_id" ]]; then
    previous_project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$LEGACY_ANCHOR_ID")
    [[ "$previous_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]] || v2_fail previous_v2_project_invalid
    previous_release_id=${BASH_REMATCH[1]}
    release_state_set "$V2_STATE_FILE" previous_release_retiring_id "$previous_release_id"
  fi
  [[ "$previous_release_id" =~ ^[0-9]+-[0-9]+$ ]] || v2_fail previous_release_id_invalid
  [[ "$previous_release_id" != "$RELEASE_ID" ]] || v2_fail previous_release_matches_current
  previous_maintenance="xboard-v2-maintenance-$previous_release_id"
  if docker container inspect "$previous_maintenance" >/dev/null 2>&1; then
    [[ "$(docker inspect -f '{{ index .Config.Labels "codex.xboard.v2.release" }}' "$previous_maintenance")" == "$previous_release_id" ]] ||
      v2_fail previous_maintenance_identity_mismatch
    previous_maintenance_port=$(docker inspect -f '{{(index (index .HostConfig.PortBindings "7001/tcp") 0).HostPort}}' "$previous_maintenance")
    [[ "$previous_maintenance_port" =~ ^[0-9]+$ ]] || v2_fail previous_maintenance_port_invalid
    [[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$previous_maintenance_port")" == 0 ]] ||
      v2_fail previous_maintenance_caddy_route_active
  fi
fi

if docker container inspect "$MAINTENANCE_CONTAINER" >/dev/null 2>&1; then
  [[ "$(docker inspect -f '{{ index .Config.Labels "codex.xboard.v2.release" }}' "$MAINTENANCE_CONTAINER")" == "$RELEASE_ID" ]] ||
    v2_fail maintenance_identity_mismatch
fi

if [[ "$TRAFFIC_STATE" == active_v2 ]]; then
  while IFS= read -r id; do
    v2_assert_recorded_container "$id" legacy
    ! v2_container_running "$id" || v2_fail legacy_container_still_running
  done < <(v2_legacy_ids)
  release_state_set "$V2_STATE_FILE" finalize_reason "$V2_FINALIZE_REASON"
  if [[ "$V2_FINALIZE_REASON" == superseded ]]; then
    release_state_set "$V2_STATE_FILE" superseded_by_sha "$SUPERSEDING_RELEASE_SHA"
    release_state_set "$V2_STATE_FILE" superseded_at "$(date -u +%FT%TZ)"
  fi
  release_state_set "$V2_STATE_FILE" traffic_state finalizing
  release_state_set "$V2_STATE_FILE" finalizing_at "$(date -u +%FT%TZ)"
  TRAFFIC_STATE=finalizing
else
  recorded_finalize_reason=$(release_state_get_optional "$V2_STATE_FILE" finalize_reason)
  [[ -z "$recorded_finalize_reason" || "$recorded_finalize_reason" == "$V2_FINALIZE_REASON" ]] ||
    v2_fail finalize_reason_changed_during_retry
  if [[ "$V2_FINALIZE_REASON" == superseded ]]; then
    recorded_superseding_sha=$(release_state_get_optional "$V2_STATE_FILE" superseded_by_sha)
    [[ -z "$recorded_superseding_sha" || "$recorded_superseding_sha" == "$SUPERSEDING_RELEASE_SHA" ]] ||
      v2_fail superseding_release_changed_during_retry
  fi
fi
mapfile -t legacy_ids < <(v2_legacy_ids)
for ((index = ${#legacy_ids[@]} - 1; index >= 0; index--)); do
  id=${legacy_ids[$index]}
  if [[ "$LEGACY_TOPOLOGY" == legacy && "$id" == "$LEGACY_ANCHOR_ID" ]]; then
    continue
  fi
  if docker container inspect "$id" >/dev/null 2>&1; then
    ! v2_container_running "$id" || v2_fail legacy_container_still_running
    docker rm "$id" >/dev/null
  fi
done
if docker container inspect "$MAINTENANCE_CONTAINER" >/dev/null 2>&1; then
  docker rm -f "$MAINTENANCE_CONTAINER" >/dev/null
fi
if [[ -n "$previous_maintenance" ]] && docker container inspect "$previous_maintenance" >/dev/null 2>&1; then
  docker rm -f "$previous_maintenance" >/dev/null
fi

release_state_set "$V2_STATE_FILE" traffic_state finalized
release_state_set "$V2_STATE_FILE" finalized_at "$(date -u +%FT%TZ)"
release_state_set "$V2_STATE_FILE" rollback_supported false
release_state_set "$V2_STATE_FILE" previous_release_retired_id "${previous_release_id:-legacy}"
release_state_set "$V2_STATE_FILE" previous_maintenance_retired "${previous_maintenance:-none}"
release_state_set "$V2_STATE_FILE" compose_anchor_preserved "$([[ "$LEGACY_TOPOLOGY" == legacy ]] && echo "$LEGACY_ANCHOR_ID" || echo external)"

echo "V2_FINALIZE=PASS id=$RELEASE_ID reason=$V2_FINALIZE_REASON age_seconds=$age_seconds traffic_state active_v2 legacy_containers=retired previous_maintenance=${previous_maintenance:-none} compose_anchor=preserved volumes=preserved"
