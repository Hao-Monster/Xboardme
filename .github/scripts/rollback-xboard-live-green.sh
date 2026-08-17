#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_ID:?RELEASE_ID is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_run_id'
  exit 1
fi

mapfile -t blue_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#blue_ids[@]} != 1)); then
  echo 'RELEASE_ROLLBACK_FAIL=blue_missing'
  exit 1
fi
blue=${blue_ids[0]}
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$blue")
state_file="$workdir/.codex-release/$RELEASE_ID/state.env"
if [[ ! -f "$state_file" ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=state_missing'
  exit 1
fi
# shellcheck disable=SC1090
source "$state_file"
if [[ "$BLUE_CONTAINER" != "$blue" || ! -f "${CADDY_BACKUP:-}" ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_release_state'
  exit 1
fi

if [[ "$ROLE_STATE" == green ]]; then
  docker rm -f "${SCHEDULER_CONTAINER:?}" "${HORIZON_CONTAINER:?}" >/dev/null 2>&1 || true
  docker exec "$blue" supervisorctl start horizon >/dev/null
  docker exec "$blue" supervisorctl start octane >/dev/null
fi
docker exec "$blue" supervisorctl start ws-server >/dev/null 2>&1 || true
docker exec "$blue" supervisorctl start caddy >/dev/null 2>&1 || true

blue_healthy=0
for attempt in {1..30}; do
  if docker exec "$blue" wget -q -O /dev/null http://127.0.0.1:7001/; then
    blue_healthy=1
    break
  fi
  sleep 2
done
if ((blue_healthy != 1)); then
  echo 'RELEASE_ROLLBACK_FAIL=blue_not_healthy'
  exit 1
fi

if [[ "$TRAFFIC_STATE" == green ]]; then
  cp -p -- "$CADDY_BACKUP" "$CADDY_CONFIG"
  caddy validate --config "$CADDY_CONFIG" --adapter caddyfile >/dev/null
  systemctl reload caddy
fi
if [[ "$(grep -Rho '127\.0\.0\.1:7001' /etc/caddy 2>/dev/null | wc -l)" != 1 ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=caddy_not_blue'
  exit 1
fi

app_url=$(docker exec "$blue" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo rtrim((string) config("app.url"), "/");
')
curl --silent --show-error --fail --location --max-time 10 --output /dev/null "$app_url/"

set_state() {
  local key=$1 value=$2 temporary
  temporary=$(mktemp "${state_file}.XXXXXX")
  awk -F= -v key="$key" '$1 != key {print}' "$state_file" > "$temporary"
  printf '%s=%q\n' "$key" "$value" >> "$temporary"
  chmod 600 "$temporary"
  mv -f -- "$temporary" "$state_file"
}
set_state TRAFFIC_STATE blue
set_state ROLE_STATE blue
set_state ROLLED_BACK_AT "$(date -u +%FT%TZ)"
echo "RELEASE_ROLLBACK=PASS id=$RELEASE_ID upstream=127.0.0.1:7001"
