#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_ROLES_FAIL=invalid_run_id'
  exit 1
fi

mapfile -t compose_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
mapfile -t green_ids < <(
  docker ps -q \
    --filter label=codex.xboard.release=true \
    --filter label=codex.xboard.release.role=web \
    --filter "label=codex.xboard.release.run=$RELEASE_ID"
)
if ((${#compose_ids[@]} != 1 || ${#green_ids[@]} != 1)); then
  echo 'RELEASE_ROLES_FAIL=compose_base_or_candidate_missing'
  exit 1
fi
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${compose_ids[0]}")
green=${green_ids[0]}
state_file="$workdir/.codex-release/$RELEASE_ID/state.env"
if [[ ! -f "$state_file" ]]; then
  echo 'RELEASE_ROLES_FAIL=state_missing'
  exit 1
fi
# shellcheck disable=SC1090
source "$state_file"
blue=$BLUE_CONTAINER
if [[ "$RELEASE_SHA" != "$EXPECTED_RELEASE_SHA" ]]; then
  echo 'RELEASE_ROLES_FAIL=release_commit_mismatch'
  exit 1
fi
if [[ "$TRAFFIC_STATE" != green || "$ROLE_STATE" != blue ]]; then
  echo "RELEASE_ROLES_FAIL=invalid_state traffic=$TRAFFIC_STATE roles=$ROLE_STATE"
  exit 1
fi
if [[ "$(grep -Rho "127\\.0\\.0\\.1:$GREEN_PORT" /etc/caddy 2>/dev/null | wc -l)" != 1 ]] ||
   ! docker exec "$green" wget -q -O /dev/null http://127.0.0.1:7001/; then
  echo 'RELEASE_ROLES_FAIL=candidate_not_serving_traffic'
  exit 1
fi

horizon_name="xboard-horizon-$RELEASE_ID"
scheduler_name="xboard-scheduler-$RELEASE_ID"
for name in "$horizon_name" "$scheduler_name"; do
  if docker ps -aq --filter "name=^/${name}$" | grep -q .; then
    echo "RELEASE_ROLES_FAIL=role_container_exists name=$name"
    exit 1
  fi
done

role_mode=compose
previous_horizon=''
previous_scheduler=''
if [[ -n "${PREVIOUS_RELEASE_ID:-}" ]]; then
  role_mode=release
  mapfile -t previous_horizon_ids < <(
    docker ps -q \
      --filter label=codex.xboard.release=true \
      --filter label=codex.xboard.release.role=horizon \
      --filter "label=codex.xboard.release.run=$PREVIOUS_RELEASE_ID"
  )
  mapfile -t previous_scheduler_ids < <(
    docker ps -q \
      --filter label=codex.xboard.release=true \
      --filter label=codex.xboard.release.role=scheduler \
      --filter "label=codex.xboard.release.run=$PREVIOUS_RELEASE_ID"
  )
  if ((${#previous_horizon_ids[@]} != 1 || ${#previous_scheduler_ids[@]} != 1)); then
    echo 'RELEASE_ROLES_FAIL=previous_release_roles_missing'
    exit 1
  fi
  previous_horizon=${previous_horizon_ids[0]}
  previous_scheduler=${previous_scheduler_ids[0]}
fi

old_horizon_paused=0
old_scheduler_stopped=0
old_horizon_stopped=0
blue_octane_stopped=0
blue_octane_pid=''
blue_octane_pgid=''
rollback_roles() {
  status=$?
  if ((status != 0)); then
    docker rm -f "$scheduler_name" "$horizon_name" >/dev/null 2>&1 || true
    if [[ "$role_mode" == release ]]; then
      if ((old_scheduler_stopped == 1)); then
        docker start "$previous_scheduler" >/dev/null 2>&1 || true
      fi
      if ((old_horizon_stopped == 1)); then
        docker start "$previous_horizon" >/dev/null 2>&1 || true
      fi
      ((old_horizon_paused == 0)) || docker exec "$previous_horizon" php /www/artisan horizon:continue >/dev/null 2>&1 || true
    else
      if ((blue_octane_stopped == 1)) && [[ "$blue_octane_pgid" =~ ^[1-9][0-9]*$ ]]; then
        docker exec "$blue" php -r 'posix_kill(-((int) $argv[1]), SIGCONT);' "$blue_octane_pgid" >/dev/null 2>&1 || true
      fi
      ((old_horizon_paused == 0)) || docker exec "$blue" php /www/artisan horizon:continue >/dev/null 2>&1 || true
    fi
  fi
  exit "$status"
}
trap rollback_roles EXIT

horizon_owner=$blue
if [[ "$role_mode" == release ]]; then
  horizon_owner=$previous_horizon
fi
docker exec "$horizon_owner" php /www/artisan horizon:pause >/dev/null
old_horizon_paused=1

zero_reserved_samples=0
for attempt in {1..35}; do
  reserved_jobs=$(docker exec "$blue" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $queue = app("queue")->connection("redis");
  $names = ["traffic_fetch", "stat", "user_alive_sync", "default", "order_handle", "send_email", "send_telegram", "send_email_mass", "node_sync"];
  echo array_sum(array_map(fn ($name) => $queue->reservedSize($name), $names));
  ')
  if [[ "$reserved_jobs" == 0 ]]; then
    ((zero_reserved_samples += 1))
    ((zero_reserved_samples >= 3)) && break
  else
    zero_reserved_samples=0
  fi
  sleep 2
done
if ((zero_reserved_samples < 3)); then
  # Redis keeps timed-out jobs in the reserved list until a worker migrates
  # them after retry_after. Pausing Horizon prevents that migration, so the
  # count is diagnostic after the bounded worker-timeout grace period.
  echo "RELEASE_ROLES_WARN=reserved_jobs_remain_after_grace count=$reserved_jobs"
fi

if [[ "$role_mode" == release ]]; then
  docker stop --time 20 "$previous_horizon" >/dev/null
  old_horizon_stopped=1
fi

docker run -d \
  --name "$horizon_name" \
  --hostname "$horizon_name" \
  --label codex.xboard.release=true \
  --label "codex.xboard.release.run=$RELEASE_ID" \
  --label codex.xboard.release.role=horizon \
  --restart unless-stopped \
  --memory 512m \
  --cpus 2 \
  --volumes-from "$blue" \
  -e SKIP_XBOARD_UPDATE=true \
  -e "RUNTIME_INSTANCE_ID=horizon-$RELEASE_ID" \
  -e RESOURCE_PROFILE=minimal \
  -e ENABLE_WEB=false \
  -e ENABLE_HORIZON=true \
  -e ENABLE_REDIS=false \
  -e ENABLE_WS_SERVER=false \
  -e ENABLE_CADDY=false \
  -e ENABLE_SCHEDULER=false \
  "$RELEASE_IMAGE" >/dev/null

horizon_ready_samples=0
for attempt in {1..30}; do
  horizon_supervisor_state=$(docker exec "$horizon_name" supervisorctl status 2>&1 || true)
  if grep -Eq '^horizon:horizon_00[[:space:]]+RUNNING([[:space:]]|$)' <<< "$horizon_supervisor_state"; then
    ((horizon_ready_samples += 1))
    ((horizon_ready_samples >= 3)) && break
  else
    horizon_ready_samples=0
  fi
  sleep 2
done
if ((horizon_ready_samples < 3)); then
  docker logs --tail 100 "$horizon_name" >&2 || true
  echo 'RELEASE_ROLES_FAIL=new_horizon_unhealthy'
  exit 1
fi

if [[ "$role_mode" == release ]]; then
  docker stop --time 20 "$previous_scheduler" >/dev/null
  old_scheduler_stopped=1
else
  blue_octane_pid=$(docker exec "$blue" sh -c '
    for proc in /proc/[0-9]*; do
      [ -r "$proc/cmdline" ] || continue
      executable=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "1p")
      argument1=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "2p")
      argument2=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "3p")
      if [ "${executable##*/}" = php ] && [ "$argument1" = /www/artisan ] && [ "$argument2" = octane:start ]; then
        printf "%s\n" "${proc#/proc/}"
      fi
    done
  ' | head -n 1)
  if [[ ! "$blue_octane_pid" =~ ^[1-9][0-9]*$ ]]; then
    echo 'RELEASE_ROLES_FAIL=blue_octane_master_missing'
    exit 1
  fi
  blue_octane_pgid=$(docker exec "$blue" sh -c 'awk "{print \$5}" "/proc/$1/stat"' sh "$blue_octane_pid")
  if [[ ! "$blue_octane_pgid" =~ ^[1-9][0-9]*$ ]]; then
    echo 'RELEASE_ROLES_FAIL=blue_octane_group_missing'
    exit 1
  fi
  docker exec "$blue" php -r 'exit(posix_kill(-((int) $argv[1]), SIGSTOP) ? 0 : 1);' "$blue_octane_pgid"
  blue_octane_stopped=1
fi

docker run -d \
  --name "$scheduler_name" \
  --hostname "$scheduler_name" \
  --label codex.xboard.release=true \
  --label "codex.xboard.release.run=$RELEASE_ID" \
  --label codex.xboard.release.role=scheduler \
  --restart unless-stopped \
  --memory 256m \
  --cpus 1 \
  --volumes-from "$blue" \
  -e SKIP_XBOARD_UPDATE=true \
  -e "RUNTIME_INSTANCE_ID=scheduler-$RELEASE_ID" \
  -e ENABLE_WEB=false \
  -e ENABLE_HORIZON=false \
  -e ENABLE_REDIS=false \
  -e ENABLE_WS_SERVER=false \
  -e ENABLE_CADDY=false \
  -e ENABLE_SCHEDULER=false \
  --entrypoint /entrypoint.sh \
  "$RELEASE_IMAGE" php /www/artisan schedule:work >/dev/null

scheduler_ready=0
for attempt in {1..15}; do
  if [[ "$(docker inspect -f '{{.State.Running}}' "$scheduler_name")" == true ]] &&
     docker exec "$scheduler_name" php -r 'echo PHP_MAJOR_VERSION;' | grep -q '^8$'; then
    scheduler_ready=1
    break
  fi
  sleep 2
done
if ((scheduler_ready != 1)); then
  docker logs --tail 100 "$scheduler_name" >&2 || true
  echo 'RELEASE_ROLES_FAIL=new_scheduler_unhealthy'
  exit 1
fi

for container in "$horizon_name" "$scheduler_name"; do
  version=$(docker exec "$container" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  echo app()->version();
  ')
  [[ "$version" == 13.* ]] || { echo "RELEASE_ROLES_FAIL=unexpected_framework container=$container"; exit 1; }
done
docker exec "$green" wget -q -O /dev/null http://127.0.0.1:7001/

set_state() {
  local key=$1 value=$2 temporary
  temporary=$(mktemp "${state_file}.XXXXXX")
  awk -F= -v key="$key" '$1 != key {print}' "$state_file" > "$temporary"
  printf '%s=%q\n' "$key" "$value" >> "$temporary"
  chmod 600 "$temporary"
  mv -f -- "$temporary" "$state_file"
}
set_state HORIZON_CONTAINER "$horizon_name"
set_state SCHEDULER_CONTAINER "$scheduler_name"
set_state ROLE_MODE "$role_mode"
set_state PREVIOUS_HORIZON_CONTAINER "$previous_horizon"
set_state PREVIOUS_SCHEDULER_CONTAINER "$previous_scheduler"
set_state BLUE_OCTANE_PID "$blue_octane_pid"
set_state BLUE_OCTANE_PGID "$blue_octane_pgid"
set_state ROLE_STATE green
set_state ROLES_ACTIVATED_AT "$(date -u +%FT%TZ)"

trap - EXIT
echo "RELEASE_ROLES=PASS id=$RELEASE_ID mode=$role_mode horizon=$horizon_name scheduler=$scheduler_name"
