#!/usr/bin/env bash
set -Eeuo pipefail

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
state_file="$workdir/.codex-release/$RELEASE_ID/state.env"
if [[ ! -f "$state_file" ]]; then
  echo 'RELEASE_ROLLBACK_FAIL=state_missing'
  exit 1
fi
# shellcheck disable=SC1090
source "$state_file"
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

if [[ "$ROLE_STATE" == green ]]; then
  docker rm -f "${SCHEDULER_CONTAINER:?}" "${HORIZON_CONTAINER:?}" >/dev/null 2>&1 || true
  if [[ "${ROLE_MODE:-compose}" == release ]]; then
    : "${PREVIOUS_HORIZON_CONTAINER:?}"
    : "${PREVIOUS_SCHEDULER_CONTAINER:?}"
    docker start "$PREVIOUS_SCHEDULER_CONTAINER" >/dev/null
    docker start "$PREVIOUS_HORIZON_CONTAINER" >/dev/null
    previous_roles_ready=0
    for attempt in {1..20}; do
      horizon_state=$(docker exec "$PREVIOUS_HORIZON_CONTAINER" supervisorctl status 2>&1 || true)
      if grep -Eq '^horizon:horizon_00[[:space:]]+RUNNING([[:space:]]|$)' <<< "$horizon_state" &&
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
    docker exec "$PREVIOUS_HORIZON_CONTAINER" php /www/artisan horizon:continue >/dev/null
  else
    if [[ ! "${BLUE_OCTANE_PGID:-}" =~ ^[1-9][0-9]*$ ]]; then
      echo 'RELEASE_ROLLBACK_FAIL=invalid_blue_octane_group'
      exit 1
    fi
    docker exec "$blue" php -r 'exit(posix_kill(-((int) $argv[1]), SIGCONT) ? 0 : 1);' "$BLUE_OCTANE_PGID"
    docker exec "$blue" php /www/artisan horizon:continue >/dev/null
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
echo "RELEASE_ROLLBACK=PASS id=$RELEASE_ID upstream=127.0.0.1:$BLUE_PORT"
