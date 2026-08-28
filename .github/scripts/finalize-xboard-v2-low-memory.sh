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
: "${V2_FINALIZE_MIN_AGE_SECONDS:=86400}"
[[ "$V2_FINALIZE_MIN_AGE_SECONDS" =~ ^[0-9]+$ ]] && ((V2_FINALIZE_MIN_AGE_SECONDS >= 86400)) || v2_fail invalid_finalize_age

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
((age_seconds >= V2_FINALIZE_MIN_AGE_SECONDS)) || v2_fail "rollback_window_open:$age_seconds"

for service in redis web ws edge horizon scheduler; do
  v2_service_healthy "$service" || v2_fail "finalize_service_unhealthy:$service"
done
v2_assert_v2_owners
[[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$ACTIVE_PORT")" == 1 ]] || v2_fail active_caddy_route_missing

if [[ "$TRAFFIC_STATE" == active_v2 ]]; then
  while IFS= read -r id; do
    v2_assert_recorded_container "$id" legacy
    ! v2_container_running "$id" || v2_fail legacy_container_still_running
  done < <(v2_legacy_ids)
  release_state_set "$V2_STATE_FILE" traffic_state finalizing
  release_state_set "$V2_STATE_FILE" finalizing_at "$(date -u +%FT%TZ)"
  TRAFFIC_STATE=finalizing
fi
mapfile -t legacy_ids < <(v2_legacy_ids)
for ((index = ${#legacy_ids[@]} - 1; index >= 0; index--)); do
  id=${legacy_ids[$index]}
  if docker container inspect "$id" >/dev/null 2>&1; then
    ! v2_container_running "$id" || v2_fail legacy_container_still_running
    docker rm "$id" >/dev/null
  fi
done
docker rm -f "$MAINTENANCE_CONTAINER" >/dev/null 2>&1 || true

release_state_set "$V2_STATE_FILE" traffic_state finalized
release_state_set "$V2_STATE_FILE" finalized_at "$(date -u +%FT%TZ)"
release_state_set "$V2_STATE_FILE" rollback_supported false

echo "V2_FINALIZE=PASS id=$RELEASE_ID age_seconds=$age_seconds traffic_state active_v2 legacy_containers=retired volumes=preserved"
