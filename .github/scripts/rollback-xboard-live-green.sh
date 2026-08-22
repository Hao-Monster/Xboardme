#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_get >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_run_id'
  exit 1
fi

mapfile -t compose_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#compose_ids[@]} != 1)); then
  echo 'RELEASE_ROLLBACK_FAIL=compose_base_missing'
  exit 1
fi
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${compose_ids[0]}")
release_dir="$workdir/.codex-release/$RELEASE_ID"
if ! state_file=$(release_state_open "$release_dir"); then
  echo 'RELEASE_ROLLBACK_FAIL=state_missing'
  exit 1
fi
STATE_RELEASE_ID=$(release_state_get "$state_file" release_id)
BLUE_CONTAINER=$(release_state_get "$state_file" blue_container)
BLUE_PORT=$(release_state_get "$state_file" blue_port)
GREEN_PORT=$(release_state_get "$state_file" green_port)
ROLE_STATE=$(release_state_get "$state_file" role_state)
CADDY_BACKUP=$(release_state_get_optional "$state_file" caddy_backup)
CADDY_CONFIG=$(release_state_get_optional "$state_file" caddy_config)
ROLE_MODE=$(release_state_get_optional "$state_file" role_mode)
HORIZON_CONTAINER=$(release_state_get_optional "$state_file" horizon_container)
SCHEDULER_CONTAINER=$(release_state_get_optional "$state_file" scheduler_container)
PREVIOUS_HORIZON_CONTAINER=$(release_state_get_optional "$state_file" previous_horizon_container)
PREVIOUS_SCHEDULER_CONTAINER=$(release_state_get_optional "$state_file" previous_scheduler_container)
BLUE_OCTANE_PGID=$(release_state_get_optional "$state_file" blue_octane_pgid)
if [[ "$STATE_RELEASE_ID" != "$RELEASE_ID" ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=release_state_identity_mismatch'
  exit 1
fi
blue=$BLUE_CONTAINER
if [[ "$(docker inspect -f '{{.State.Running}}' "$blue" 2>/dev/null || true)" != true ]] ||
   [[ ! "$BLUE_PORT" =~ ^[0-9]+$ ]] || [[ ! "$GREEN_PORT" =~ ^[0-9]+$ ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_release_state'
  exit 1
fi

caddy_backup=${CADDY_BACKUP:-$workdir/.codex-release/$RELEASE_ID/backups/caddy-before-switch.conf}
if [[ ! -f "$caddy_backup" ]] ||
   [[ "$(grep -o "127\\.0\\.0\\.1:$BLUE_PORT" "$caddy_backup" | wc -l)" != 1 ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_caddy_backup'
  exit 1
fi
caddy_config=${CADDY_CONFIG:-}
if [[ -z "$caddy_config" ]]; then
  mapfile -t caddy_candidates < <(
    grep -RIlE --include='*.conf' --include='Caddyfile' \
      -- "127\\.0\\.0\\.1:($BLUE_PORT|$GREEN_PORT)" /etc/caddy 2>/dev/null || true
  )
  if ((${#caddy_candidates[@]} != 1)); then
    echo "RELEASE_ROLLBACK_FAIL=ambiguous_caddy_file count=${#caddy_candidates[@]}"
    exit 1
  fi
  caddy_config=${caddy_candidates[0]}
fi

horizon_master_ready() {
  local container=$1 horizon_state master_count
  [[ "$(docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null || true)" == true ]] || return 1

  # Backward compatibility for the retained pre-fix release container.
  horizon_state=$(docker exec "$container" supervisorctl status 2>&1 || true)
  if grep -Eq '^horizon:horizon_00[[:space:]]+RUNNING([[:space:]]|$)' <<< "$horizon_state"; then
    return 0
  fi

  master_count=$(docker exec "$container" sh -c '
    count=0
    for proc in /proc/[0-9]*; do
      [ -r "$proc/cmdline" ] || continue
      executable=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "1p")
      argument1=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "2p")
      argument2=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "3p")
      if [ "${executable##*/}" = php ] && [ "$argument1" = /www/artisan ] && [ "$argument2" = horizon ]; then
        count=$((count + 1))
      fi
    done
    printf "%s\n" "$count"
  ' 2>/dev/null || true)
  [[ "$master_count" == 1 ]]
}

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

role_handoff_started=0
current_horizon=''
current_scheduler=''
restore_current_roles_on_error() {
  status=$?
  if ((status != 0 && role_handoff_started == 1)); then
    if [[ "${ROLE_MODE:-compose}" == release ]]; then
      docker stop --time 20 "${PREVIOUS_SCHEDULER_CONTAINER:-}" "${PREVIOUS_HORIZON_CONTAINER:-}" >/dev/null 2>&1 || true
    elif [[ "${BLUE_OCTANE_PGID:-}" =~ ^[1-9][0-9]*$ ]]; then
      docker exec "$blue" php -r 'posix_kill(-((int) $argv[1]), SIGSTOP);' "$BLUE_OCTANE_PGID" >/dev/null 2>&1 || true
    fi
    docker start "$current_horizon" "$current_scheduler" >/dev/null 2>&1 || true
    for attempt in {1..20}; do
      if horizon_master_ready "$current_horizon"; then
        docker exec "$current_horizon" php /www/artisan horizon:continue >/dev/null 2>&1 || true
        horizon_master_running "$current_horizon" && break
      fi
      sleep 2
    done
  fi
  exit "$status"
}
trap restore_current_roles_on_error EXIT

if [[ "$ROLE_STATE" == green ]]; then
  current_horizon=${HORIZON_CONTAINER:-}
  current_scheduler=${SCHEDULER_CONTAINER:-}
  if [[ -z "$current_horizon" || -z "$current_scheduler" ]] ||
     [[ "$(docker inspect -f '{{.State.Running}}' "$current_horizon" 2>/dev/null || true)" != true ]] ||
     [[ "$(docker inspect -f '{{.State.Running}}' "$current_scheduler" 2>/dev/null || true)" != true ]]; then
    echo 'RELEASE_ROLLBACK_FAIL=current_release_roles_missing'
    exit 1
  fi

  role_handoff_started=1
  docker exec "$current_horizon" php /www/artisan horizon:pause >/dev/null
  docker stop --time 20 "$current_scheduler" >/dev/null
  docker stop --time 20 "$current_horizon" >/dev/null

  previous_horizon_owner=$blue
  if [[ "${ROLE_MODE:-compose}" == release ]]; then
    : "${PREVIOUS_HORIZON_CONTAINER:?}"
    : "${PREVIOUS_SCHEDULER_CONTAINER:?}"
    docker start "$PREVIOUS_SCHEDULER_CONTAINER" >/dev/null
    docker start "$PREVIOUS_HORIZON_CONTAINER" >/dev/null
    previous_horizon_owner=$PREVIOUS_HORIZON_CONTAINER
    previous_roles_ready=0
    for attempt in {1..20}; do
      if horizon_master_ready "$PREVIOUS_HORIZON_CONTAINER" &&
         [[ "$(docker inspect -f '{{.State.Running}}' "$PREVIOUS_SCHEDULER_CONTAINER")" == true ]]; then
        previous_roles_ready=1
        break
      fi
      sleep 2
    done
    if ((previous_roles_ready != 1)); then
      echo 'RELEASE_ROLLBACK_FAIL=previous_release_roles_unhealthy'
      exit 1
    fi
  else
    if [[ ! "${BLUE_OCTANE_PGID:-}" =~ ^[1-9][0-9]*$ ]]; then
      echo 'RELEASE_ROLLBACK_FAIL=invalid_blue_octane_group'
      exit 1
    fi
    docker exec "$blue" php -r 'exit(posix_kill(-((int) $argv[1]), SIGCONT) ? 0 : 1);' "$BLUE_OCTANE_PGID"
  fi

  docker exec "$previous_horizon_owner" php /www/artisan horizon:continue >/dev/null
  previous_horizon_running_samples=0
  for attempt in {1..15}; do
    if horizon_master_ready "$previous_horizon_owner" &&
       horizon_master_running "$previous_horizon_owner"; then
      ((previous_horizon_running_samples += 1))
      ((previous_horizon_running_samples >= 3)) && break
    else
      previous_horizon_running_samples=0
    fi
    sleep 2
  done
  if ((previous_horizon_running_samples < 3)); then
    echo 'RELEASE_ROLLBACK_FAIL=previous_horizon_not_running'
    exit 1
  fi
fi

blue_healthy=0
for attempt in {1..30}; do
  if docker exec "$blue" wget -q -O /dev/null http://127.0.0.1:7001/; then
    blue_healthy=1
    break
  fi
  sleep 2
done
if ((blue_healthy != 1)); then
  echo 'RELEASE_ROLLBACK_FAIL=previous_web_not_healthy'
  exit 1
fi

cp -p -- "$caddy_backup" "$caddy_config"
chmod 0644 "$caddy_config"
caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null
systemctl reload caddy
if [[ "$(systemctl is-active caddy)" != active ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=caddy_inactive'
  exit 1
fi
if [[ "$(grep -o "127\\.0\\.0\\.1:$BLUE_PORT" "$caddy_config" | wc -l)" != 1 ]] ||
   grep -q "127\\.0\\.0\\.1:$GREEN_PORT" "$caddy_config"; then
  echo 'RELEASE_ROLLBACK_FAIL=caddy_not_on_previous_port'
  exit 1
fi
echo 'RELEASE_ROLLBACK_CHECK=previous_web_roles_and_caddy_ready external_smoke_required'

release_state_set "$state_file" traffic_state blue
release_state_set "$state_file" role_state blue
release_state_set "$state_file" caddy_config "$caddy_config"
release_state_set "$state_file" caddy_backup "$caddy_backup"
release_state_set "$state_file" rolled_back_at "$(date -u +%FT%TZ)"

trap - EXIT
if ((role_handoff_started == 1)); then
  docker rm -f "$current_scheduler" "$current_horizon" >/dev/null 2>&1 || true
fi
echo "RELEASE_ROLLBACK=PASS id=$RELEASE_ID upstream=127.0.0.1:$BLUE_PORT"
