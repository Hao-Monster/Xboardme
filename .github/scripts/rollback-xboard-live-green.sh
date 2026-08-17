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
if [[ "$BLUE_CONTAINER" != "$blue" ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_release_state'
  exit 1
fi

# A cutover can fail after creating the backup but before recording its paths.
# Recover only the deterministic release-local backup and one exact Caddy file.
caddy_backup=${CADDY_BACKUP:-$workdir/.codex-release/$RELEASE_ID/backups/caddy-before-switch.conf}
if [[ ! -f "$caddy_backup" ]] || [[ "$(grep -o '127\.0\.0\.1:7001' "$caddy_backup" | wc -l)" != 1 ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=invalid_caddy_backup'
  exit 1
fi
caddy_config=${CADDY_CONFIG:-}
if [[ -z "$caddy_config" ]]; then
  mapfile -t caddy_candidates < <(
    grep -RIlE --include='*.conf' --include='Caddyfile' \
      -- '127\.0\.0\.1:700[12]' /etc/caddy 2>/dev/null || true
  )
  if ((${#caddy_candidates[@]} != 1)); then
    echo "RELEASE_ROLLBACK_FAIL=ambiguous_caddy_file count=${#caddy_candidates[@]}"
    exit 1
  fi
  caddy_config=${caddy_candidates[0]}
fi

if [[ "$ROLE_STATE" == green ]]; then
  docker rm -f "${SCHEDULER_CONTAINER:?}" "${HORIZON_CONTAINER:?}" >/dev/null 2>&1 || true
  if [[ ! "${BLUE_OCTANE_PGID:-}" =~ ^[1-9][0-9]*$ ]]; then
    echo 'RELEASE_ROLLBACK_FAIL=invalid_blue_octane_group'
    exit 1
  fi
  docker exec "$blue" php -r 'exit(posix_kill(-((int) $argv[1]), SIGCONT) ? 0 : 1);' "$BLUE_OCTANE_PGID"
  docker exec "$blue" php /www/artisan horizon:continue >/dev/null
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
  echo 'RELEASE_ROLLBACK_FAIL=blue_not_healthy'
  exit 1
fi

cp -p -- "$caddy_backup" "$caddy_config"
chmod 0644 "$caddy_config"
caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null
systemctl reload caddy
if [[ "$(grep -o '127\.0\.0\.1:7001' "$caddy_config" | wc -l)" != 1 ]] || \
   grep -q '127\.0\.0\.1:7002' "$caddy_config"; then
  echo 'RELEASE_ROLLBACK_FAIL=caddy_not_blue'
  exit 1
fi

app_url=$(docker exec "$blue" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo rtrim((string) config("app.url"), "/");
')
public_ready=0
for attempt in {1..12}; do
  if curl --silent --show-error --fail --location --max-time 10 --output /dev/null "$app_url/"; then
    public_ready=1
    break
  fi
  sleep 2
done
if ((public_ready != 1)); then
  echo 'RELEASE_ROLLBACK_FAIL=public_blue_unhealthy'
  exit 1
fi

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
set_state CADDY_CONFIG "$caddy_config"
set_state CADDY_BACKUP "$caddy_backup"
set_state ROLLED_BACK_AT "$(date -u +%FT%TZ)"
echo "RELEASE_ROLLBACK=PASS id=$RELEASE_ID upstream=127.0.0.1:7001"
