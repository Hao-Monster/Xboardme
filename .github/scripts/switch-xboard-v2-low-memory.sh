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

v2_require_tools
v2_open_release
[[ "$TRAFFIC_STATE" == ready ]] || v2_fail "invalid_switch_state:$TRAFFIC_STATE"
for service in redis web ws edge horizon scheduler; do
  v2_service_healthy "$service" || v2_fail "pre_switch_service_unhealthy:$service"
done
v2_assert_v2_owners
[[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$MAINTENANCE_PORT")" == 1 ]] || v2_fail maintenance_caddy_route_missing
curl --silent --show-error --fail --max-time 5 "http://127.0.0.1:$ACTIVE_PORT/" >/dev/null

switch_mutated=0
rollback_switch_on_error() {
  status=$?
  if ((status != 0 && switch_mutated == 1)); then
    set +e
    v2_rollback_runtime
    rollback_status=$?
    if ((rollback_status == 0)); then
      echo 'V2_SWITCH_AUTO_ROLLBACK=PASS' >&2
    else
      echo 'V2_SWITCH_AUTO_ROLLBACK=FAIL manual_intervention_required' >&2
    fi
  fi
  exit "$status"
}
trap rollback_switch_on_error EXIT

v2_replace_caddy_upstream "$MAINTENANCE_PORT" "$ACTIVE_PORT"
switch_mutated=1
release_state_set "$V2_STATE_FILE" traffic_state active_v2
release_state_set "$V2_STATE_FILE" rollback_supported true
switched_at=$(date -u +%FT%TZ)
switched_epoch=$(date -u -d "$switched_at" +%s)
finalize_due_at=$(date -u -d "@$((switched_epoch + 86400))" +%FT%TZ)
release_state_set "$V2_STATE_FILE" switched_at "$switched_at"
release_state_set "$V2_STATE_FILE" finalize_due_at "$finalize_due_at"
TRAFFIC_STATE=active_v2

switch_mutated=0
trap - EXIT
echo "V2_SWITCH=PASS id=$RELEASE_ID upstream=127.0.0.1:$ACTIVE_PORT finalize_due_at=$finalize_due_at external_smoke_required"
