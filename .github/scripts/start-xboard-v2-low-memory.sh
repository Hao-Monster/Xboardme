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
scheduler_reaper_observation_seconds=${SCHEDULER_REAPER_OBSERVATION_SECONDS:-185}
[[ "$scheduler_reaper_observation_seconds" =~ ^[0-9]+$ ]] && ((scheduler_reaper_observation_seconds >= 185)) || v2_fail invalid_scheduler_observation_window

v2_require_tools
v2_open_release
[[ "$TRAFFIC_STATE" == prepared ]] || v2_fail "invalid_start_state:$TRAFFIC_STATE"
available_memory_kib=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
[[ "$available_memory_kib" =~ ^[0-9]+$ ]] && ((available_memory_kib >= 524288)) || v2_fail insufficient_available_memory
v2_assert_legacy_identity
while IFS= read -r id; do
  v2_container_running "$id" || v2_fail legacy_runtime_not_fully_running
done < <(v2_legacy_ids)
cmp -s -- "$CADDY_CONFIG" "$CADDY_BACKUP" || v2_fail caddy_changed_since_prepare
[[ "$(v2_caddy_reference_count "$CADDY_CONFIG" "$ACTIVE_PORT")" == 1 ]] || v2_fail active_caddy_route_missing

mutation_started=0
rollback_start_on_error() {
  status=$?
  if ((status != 0 && mutation_started == 1)); then
    set +e
    v2_rollback_runtime
    rollback_status=$?
    if ((rollback_status == 0)); then
      release_state_set "$V2_STATE_FILE" start_failure_rolled_back_at "$(date -u +%FT%TZ)"
      echo 'V2_START_AUTO_ROLLBACK=PASS' >&2
    else
      echo 'V2_START_AUTO_ROLLBACK=FAIL manual_intervention_required' >&2
    fi
  fi
  exit "$status"
}
trap rollback_start_on_error EXIT

v2_start_maintenance
v2_replace_caddy_upstream "$ACTIVE_PORT" "$MAINTENANCE_PORT"
mutation_started=1
release_state_set "$V2_STATE_FILE" traffic_state maintenance
TRAFFIC_STATE=maintenance

v2_stop_legacy_runtime
v2_compose up --detach --wait --wait-timeout 120 redis web ws edge
for service in redis web ws edge; do
  v2_wait_service_healthy "$service" 45
done
v2_wait_loopback_http "$ACTIVE_PORT" 30

web_id=$(v2_service_id web)
docker exec "$web_id" php /www/.github/scripts/validate-approved-migrations.php --require-clean
database_integrity=$(docker exec "$web_id" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = Illuminate\Support\Facades\DB::selectOne("PRAGMA integrity_check");
echo (string) (array_values((array) $result)[0] ?? "");
')
[[ "$database_integrity" == ok ]] || v2_fail sqlite_integrity_failed

v2_compose up --detach --wait --wait-timeout 120 horizon scheduler
for service in horizon scheduler; do
  v2_wait_service_healthy "$service" 60
done

scheduler_id=$(v2_service_id scheduler)
[[ "$(docker inspect -f '{{.HostConfig.Init}}' "$scheduler_id")" == true ]] || v2_fail scheduler_init_disabled
start_epoch=$(date +%s)
while (( $(date +%s) - start_epoch < scheduler_reaper_observation_seconds )); do
  for service in redis web ws edge horizon scheduler; do
    v2_service_healthy "$service" || v2_fail "sustained_health_failed:$service"
  done
  v2_loopback_http_ready "$ACTIVE_PORT" || v2_fail edge_loopback_unhealthy
  [[ "$(v2_scheduler_zombie_count)" == 0 ]] || v2_fail scheduler_zombies_detected
  v2_assert_v2_owners
  sleep 5
done
docker exec "$scheduler_id" php /www/artisan runtime:health scheduler >/dev/null
[[ "$(v2_scheduler_zombie_count)" == 0 ]] || v2_fail scheduler_zombies_detected
v2_loopback_http_ready "$ACTIVE_PORT" || v2_fail edge_loopback_unhealthy
v2_assert_v2_owners

release_state_set "$V2_STATE_FILE" traffic_state ready
release_state_set "$V2_STATE_FILE" started_at "$(date -u +%FT%TZ)"
release_state_set "$V2_STATE_FILE" scheduler_observation_seconds "$scheduler_reaper_observation_seconds"
TRAFFIC_STATE=ready

mutation_started=0
trap - EXIT
echo "V2_START=PASS id=$RELEASE_ID traffic=maintenance runtime=ready scheduler_observation=${scheduler_reaper_observation_seconds}s"
echo "V2_PRIVATE_SMOKE_REQUIRED=127.0.0.1:$ACTIVE_PORT"
