#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_get >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"

if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_RETIRE_FAIL=invalid_run_id'
  exit 1
fi
if [[ ! "$EXPECTED_RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'RELEASE_RETIRE_FAIL=invalid_commit_sha'
  exit 1
fi

mapfile -t compose_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#compose_ids[@]} != 1)); then
  echo 'RELEASE_RETIRE_FAIL=compose_base_missing'
  exit 1
fi
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${compose_ids[0]}")
release_dir="$workdir/.codex-release/$RELEASE_ID"
if ! state_file=$(release_state_open "$release_dir"); then
  echo 'RELEASE_RETIRE_FAIL=state_missing'
  exit 1
fi
STATE_RELEASE_ID=$(release_state_get "$state_file" release_id)
RELEASE_SHA=$(release_state_get "$state_file" release_sha)
BLUE_CONTAINER=$(release_state_get "$state_file" blue_container)
BLUE_PORT=$(release_state_get "$state_file" blue_port)
GREEN_CONTAINER=$(release_state_get "$state_file" green_container)
GREEN_PORT=$(release_state_get "$state_file" green_port)
TRAFFIC_STATE=$(release_state_get "$state_file" traffic_state)
ROLE_STATE=$(release_state_get "$state_file" role_state)
PREVIOUS_RELEASE_ID=$(release_state_get_optional "$state_file" previous_release_id)
ROLE_MODE=$(release_state_get_optional "$state_file" role_mode)
HORIZON_CONTAINER=$(release_state_get_optional "$state_file" horizon_container)
SCHEDULER_CONTAINER=$(release_state_get_optional "$state_file" scheduler_container)
PREVIOUS_HORIZON_CONTAINER=$(release_state_get_optional "$state_file" previous_horizon_container)
PREVIOUS_SCHEDULER_CONTAINER=$(release_state_get_optional "$state_file" previous_scheduler_container)
if [[ "$STATE_RELEASE_ID" != "$RELEASE_ID" ]]; then
  echo 'RELEASE_RETIRE_FAIL=release_state_identity_mismatch'
  exit 1
fi

if [[ "$RELEASE_SHA" != "$EXPECTED_RELEASE_SHA" ]]; then
  echo 'RELEASE_RETIRE_FAIL=release_commit_mismatch'
  exit 1
fi
if [[ "$TRAFFIC_STATE" != green ]] || [[ "$ROLE_STATE" != green ]] || [[ "${ROLE_MODE:-}" != release ]]; then
  echo "RELEASE_RETIRE_FAIL=invalid_state traffic=$TRAFFIC_STATE roles=$ROLE_STATE mode=${ROLE_MODE:-unknown}"
  exit 1
fi
if [[ ! "${PREVIOUS_RELEASE_ID:-}" =~ ^[0-9]+-[0-9]+$ ]] || [[ "$PREVIOUS_RELEASE_ID" == "$RELEASE_ID" ]]; then
  echo 'RELEASE_RETIRE_FAIL=invalid_previous_release'
  exit 1
fi

mapfile -t current_web_ids < <(
  docker ps -q \
    --filter label=codex.xboard.release=true \
    --filter label=codex.xboard.release.role=web \
    --filter "label=codex.xboard.release.run=$RELEASE_ID"
)
if ((${#current_web_ids[@]} != 1)); then
  echo 'RELEASE_RETIRE_FAIL=active_web_missing'
  exit 1
fi
current_web=${current_web_ids[0]}
if [[ "$(docker inspect -f '{{.Name}}' "$current_web" | sed 's#^/##')" != "$GREEN_CONTAINER" ]]; then
  echo 'RELEASE_RETIRE_FAIL=active_web_state_mismatch'
  exit 1
fi

current_horizon=${HORIZON_CONTAINER:-}
current_scheduler=${SCHEDULER_CONTAINER:-}
if [[ -z "$current_horizon" || -z "$current_scheduler" ]] ||
   [[ "$(docker inspect -f '{{.State.Running}}' "$current_horizon" 2>/dev/null || true)" != true ]] ||
   [[ "$(docker inspect -f '{{.State.Running}}' "$current_scheduler" 2>/dev/null || true)" != true ]]; then
  echo 'RELEASE_RETIRE_FAIL=active_roles_missing'
  exit 1
fi
for role_and_container in "web:$current_web" "horizon:$current_horizon" "scheduler:$current_scheduler"; do
  role=${role_and_container%%:*}
  container=${role_and_container#*:}
  if [[ "$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release.run" }}' "$container")" != "$RELEASE_ID" ]] ||
     [[ "$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release.role" }}' "$container")" != "$role" ]] ||
     [[ "$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$container")" != "$RELEASE_SHA" ]]; then
    echo "RELEASE_RETIRE_FAIL=active_container_identity_mismatch role=$role"
    exit 1
  fi
done

mapfile -t proxy_files < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' /etc/caddy 2>/dev/null || true
)
if ((${#proxy_files[@]} != 1)); then
  echo "RELEASE_RETIRE_FAIL=ambiguous_caddy_file count=${#proxy_files[@]}"
  exit 1
fi
caddy_config=${proxy_files[0]}
caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null
mapfile -t active_upstreams < <(
  grep -Eo 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "$caddy_config" |
    awk '{print $2}' | sort -u
)
if ((${#active_upstreams[@]} != 1)) || [[ "${active_upstreams[0]}" != "127.0.0.1:$GREEN_PORT" ]] ||
   grep -q "127\.0\.0\.1:$BLUE_PORT" "$caddy_config"; then
  echo 'RELEASE_RETIRE_FAIL=traffic_not_on_current_release'
  exit 1
fi
if ! docker exec "$current_web" wget -q -O /dev/null http://127.0.0.1:7001/; then
  echo 'RELEASE_RETIRE_FAIL=active_web_unhealthy'
  exit 1
fi

horizon_master_running() {
  local container=$1
  docker exec "$container" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $basename = Laravel\Horizon\MasterSupervisor::basename();
  $matching = array_values(array_filter(
      app(Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all(),
      static fn ($master): bool => str_starts_with($master->name, $basename."-")
  ));
  if (count($matching) !== 1) {
      exit(1);
  }
  $master = $matching[0];
  exit($master->status === "running" && count($master->supervisors) > 0 ? 0 : 1);
  '
}

horizon_ready_samples=0
for _ in {1..12}; do
  horizon_restart_count=$(docker inspect -f '{{.RestartCount}}' "$current_horizon" 2>/dev/null || true)
  horizon_oom_kills=$(docker exec "$current_horizon" sh -c \
    "awk '\$1 == \"oom_kill\" {print \$2}' /sys/fs/cgroup/memory.events" 2>/dev/null || true)
  if [[ "$(docker inspect -f '{{.State.Running}}' "$current_horizon" 2>/dev/null || true)" == true ]] &&
     [[ "$horizon_restart_count" == 0 ]] && [[ "$horizon_oom_kills" == 0 ]] &&
     horizon_master_running "$current_horizon"; then
    ((horizon_ready_samples += 1))
    ((horizon_ready_samples >= 3)) && break
  else
    horizon_ready_samples=0
  fi
  sleep 2
done
if ((horizon_ready_samples < 3)); then
  echo "RELEASE_RETIRE_FAIL=active_horizon_unhealthy restarts=${horizon_restart_count:-unknown} oom_kills=${horizon_oom_kills:-unknown}"
  exit 1
fi
if ! docker exec "$current_scheduler" php -r 'echo PHP_MAJOR_VERSION;' | grep -q '^8$'; then
  echo 'RELEASE_RETIRE_FAIL=active_scheduler_unhealthy'
  exit 1
fi

mapfile -t previous_containers < <(
  docker ps -aq \
    --filter label=codex.xboard.release=true \
    --filter "label=codex.xboard.release.run=$PREVIOUS_RELEASE_ID"
)
mapfile -t previous_web_ids < <(
  docker ps -aq \
    --filter label=codex.xboard.release.role=web \
    --filter "label=codex.xboard.release.run=$PREVIOUS_RELEASE_ID"
)
mapfile -t previous_horizon_ids < <(
  docker ps -aq \
    --filter label=codex.xboard.release.role=horizon \
    --filter "label=codex.xboard.release.run=$PREVIOUS_RELEASE_ID"
)
mapfile -t previous_scheduler_ids < <(
  docker ps -aq \
    --filter label=codex.xboard.release.role=scheduler \
    --filter "label=codex.xboard.release.run=$PREVIOUS_RELEASE_ID"
)
if ((${#previous_containers[@]} != 3 || ${#previous_web_ids[@]} != 1 ||
      ${#previous_horizon_ids[@]} != 1 || ${#previous_scheduler_ids[@]} != 1)); then
  echo 'RELEASE_RETIRE_FAIL=previous_container_set_mismatch'
  exit 1
fi
if [[ "${previous_web_ids[0]}" != "$BLUE_CONTAINER" ]] ||
   [[ "${previous_horizon_ids[0]}" != "${PREVIOUS_HORIZON_CONTAINER:-}" ]] ||
   [[ "${previous_scheduler_ids[0]}" != "${PREVIOUS_SCHEDULER_CONTAINER:-}" ]]; then
  echo 'RELEASE_RETIRE_FAIL=previous_container_state_mismatch'
  exit 1
fi
if [[ "$(docker inspect -f '{{.State.Running}}' "${previous_horizon_ids[0]}")" == true ]] ||
   [[ "$(docker inspect -f '{{.State.Running}}' "${previous_scheduler_ids[0]}")" == true ]]; then
  echo 'RELEASE_RETIRE_FAIL=previous_release_roles_still_running'
  exit 1
fi
if ! docker inspect -f '{{range $bindings := .NetworkSettings.Ports}}{{range $bindings}}{{println .HostPort}}{{end}}{{end}}' \
    "${previous_web_ids[0]}" | grep -qx "$BLUE_PORT"; then
  echo 'RELEASE_RETIRE_FAIL=previous_web_port_mismatch'
  exit 1
fi

# Recheck the two authoritative active signals immediately before the only
# destructive step. Release state and backups remain on disk as audit evidence.
if [[ "$(grep -Eo 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "$caddy_config" | awk '{print $2}' | sort -u)" != "127.0.0.1:$GREEN_PORT" ]] ||
   ! horizon_master_running "$current_horizon"; then
  echo 'RELEASE_RETIRE_FAIL=active_release_changed_during_check'
  exit 1
fi
docker rm -f "${previous_containers[@]}" >/dev/null

release_state_set "$state_file" previous_release_retired_id "$PREVIOUS_RELEASE_ID"
release_state_set "$state_file" previous_release_retired_at "$(date -u +%FT%TZ)"

echo "RELEASE_RETIRE=PASS current=$RELEASE_ID previous=$PREVIOUS_RELEASE_ID containers=${#previous_containers[@]} evidence=preserved"
