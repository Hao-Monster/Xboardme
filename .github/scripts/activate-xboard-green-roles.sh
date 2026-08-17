#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_ROLES_FAIL=invalid_run_id'
  exit 1
fi

mapfile -t blue_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
mapfile -t green_ids < <(
  docker ps -q \
    --filter label=codex.xboard.release=true \
    --filter label=codex.xboard.release.role=web \
    --filter "label=codex.xboard.release.run=$RELEASE_ID"
)
if ((${#blue_ids[@]} != 1 || ${#green_ids[@]} != 1)); then
  echo 'RELEASE_ROLES_FAIL=blue_or_green_missing'
  exit 1
fi
blue=${blue_ids[0]}
green=${green_ids[0]}
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$blue")
state_file="$workdir/.codex-release/$RELEASE_ID/state.env"
if [[ ! -f "$state_file" ]]; then
  echo 'RELEASE_ROLES_FAIL=state_missing'
  exit 1
fi
# shellcheck disable=SC1090
source "$state_file"
if [[ "$RELEASE_SHA" != "$EXPECTED_RELEASE_SHA" ]]; then
  echo 'RELEASE_ROLES_FAIL=release_commit_mismatch'
  exit 1
fi
if [[ "$TRAFFIC_STATE" != green || "$ROLE_STATE" != blue ]]; then
  echo "RELEASE_ROLES_FAIL=invalid_state traffic=$TRAFFIC_STATE roles=$ROLE_STATE"
  exit 1
fi
if [[ "$(grep -Rho '127\.0\.0\.1:7002' /etc/caddy 2>/dev/null | wc -l)" != 1 ]] || \
   ! docker exec "$green" wget -q -O /dev/null http://127.0.0.1:7001/; then
  echo 'RELEASE_ROLES_FAIL=green_not_serving_traffic'
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

blue_horizon_stopped=0
blue_horizon_paused=0
blue_octane_stopped=0
rollback_roles() {
  status=$?
  if ((status != 0)); then
    docker rm -f "$scheduler_name" "$horizon_name" >/dev/null 2>&1 || true
    ((blue_octane_stopped == 0)) || docker exec "$blue" supervisorctl start octane >/dev/null 2>&1 || true
    ((blue_horizon_stopped == 0)) || docker exec "$blue" supervisorctl start horizon >/dev/null 2>&1 || true
    ((blue_horizon_paused == 0)) || docker exec "$blue" supervisorctl signal CONT horizon >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap rollback_roles EXIT

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

horizon_ready=0
for attempt in {1..30}; do
  if docker exec "$horizon_name" supervisorctl status horizon 2>/dev/null | grep -q RUNNING && \
     docker exec "$horizon_name" php /www/artisan horizon:status 2>/dev/null | grep -qi running; then
    horizon_ready=1
    break
  fi
  sleep 2
done
if ((horizon_ready != 1)); then
  docker logs --tail 100 "$horizon_name" >&2 || true
  echo 'RELEASE_ROLES_FAIL=green_horizon_unhealthy'
  exit 1
fi

# Pause only the Laravel 12 Horizon master through its local supervisor. New
# Laravel 13 workers keep consuming queued jobs while old in-flight work gets
# up to the configured 60-second maximum to finish before the old master stops.
docker exec "$blue" supervisorctl signal USR2 horizon >/dev/null
blue_horizon_paused=1
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
docker exec "$blue" supervisorctl stop horizon >/dev/null
blue_horizon_stopped=1
blue_horizon_paused=0

# Stop the Laravel 12 Octane scheduler owner before starting the dedicated
# Laravel 13 scheduler. Public HTTP is already on green, so this has no traffic
# interruption and preserves a single scheduler owner.
docker exec "$blue" supervisorctl stop octane >/dev/null
blue_octane_stopped=1
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
  if [[ "$(docker inspect -f '{{.State.Running}}' "$scheduler_name")" == true ]] && \
     docker exec "$scheduler_name" php -r 'echo PHP_MAJOR_VERSION;' | grep -q '^8$'; then
    scheduler_ready=1
    break
  fi
  sleep 2
done
if ((scheduler_ready != 1)); then
  docker logs --tail 100 "$scheduler_name" >&2 || true
  echo 'RELEASE_ROLES_FAIL=green_scheduler_unhealthy'
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
set_state ROLE_STATE green
set_state ROLES_ACTIVATED_AT "$(date -u +%FT%TZ)"

trap - EXIT
echo "RELEASE_ROLES=PASS id=$RELEASE_ID horizon=$horizon_name scheduler=$scheduler_name"
