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
  echo 'RELEASE_SWITCH_FAIL=invalid_run_id'
  exit 1
fi

mapfile -t green_ids < <(
  docker ps -q \
    --filter label=codex.xboard.release=true \
    --filter label=codex.xboard.release.role=web \
    --filter "label=codex.xboard.release.run=$RELEASE_ID"
)
if ((${#green_ids[@]} != 1)); then
  echo "RELEASE_SWITCH_FAIL=green_missing count=${#green_ids[@]}"
  exit 1
fi
green=${green_ids[0]}

mapfile -t compose_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#compose_ids[@]} != 1)); then
  echo "RELEASE_SWITCH_FAIL=compose_base_missing count=${#compose_ids[@]}"
  exit 1
fi
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${compose_ids[0]}")
release_dir="$workdir/.codex-release/$RELEASE_ID"
if ! state_file=$(release_state_open "$release_dir"); then
  echo 'RELEASE_SWITCH_FAIL=state_missing'
  exit 1
fi
STATE_RELEASE_ID=$(release_state_get "$state_file" release_id)
RELEASE_IMAGE=$(release_state_get "$state_file" release_image)
RELEASE_SHA=$(release_state_get "$state_file" release_sha)
BLUE_CONTAINER=$(release_state_get "$state_file" blue_container)
BLUE_PORT=$(release_state_get "$state_file" blue_port)
GREEN_CONTAINER=$(release_state_get "$state_file" green_container)
GREEN_PORT=$(release_state_get "$state_file" green_port)
TRAFFIC_STATE=$(release_state_get "$state_file" traffic_state)
if [[ "$STATE_RELEASE_ID" != "$RELEASE_ID" ]]; then
  echo 'RELEASE_SWITCH_FAIL=release_state_identity_mismatch'
  exit 1
fi
blue=$BLUE_CONTAINER
if [[ "$(docker inspect -f '{{.State.Running}}' "$blue" 2>/dev/null || true)" != true ]]; then
  echo 'RELEASE_SWITCH_FAIL=blue_missing'
  exit 1
fi
if [[ "$RELEASE_SHA" != "$EXPECTED_RELEASE_SHA" ]]; then
  echo 'RELEASE_SWITCH_FAIL=release_commit_mismatch'
  exit 1
fi
if [[ "$GREEN_CONTAINER" != "$(docker inspect -f '{{.Name}}' "$green" | sed 's#^/##')" ]] ||
   [[ ! "$BLUE_PORT" =~ ^[0-9]+$ ]] || [[ ! "$GREEN_PORT" =~ ^[0-9]+$ ]] ||
   [[ "$BLUE_PORT" == "$GREEN_PORT" ]]; then
  echo 'RELEASE_SWITCH_FAIL=state_container_mismatch'
  exit 1
fi
if [[ "$TRAFFIC_STATE" != blue ]]; then
  echo "RELEASE_SWITCH_FAIL=unexpected_traffic_state state=$TRAFFIC_STATE"
  exit 1
fi
if [[ "$(docker inspect -f '{{.Config.Image}}' "$green")" != "$RELEASE_IMAGE" ]]; then
  echo 'RELEASE_SWITCH_FAIL=green_image_mismatch'
  exit 1
fi
if ! docker exec "$green" wget -q -O /dev/null http://127.0.0.1:7001/ || \
   ! docker exec "$blue" wget -q -O /dev/null http://127.0.0.1:7001/; then
  echo 'RELEASE_SWITCH_FAIL=blue_or_green_unhealthy'
  exit 1
fi

mapfile -t proxy_files < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- "127\\.0\\.0\\.1:$BLUE_PORT" /etc/caddy 2>/dev/null || true
)
if ((${#proxy_files[@]} != 1)); then
  echo "RELEASE_SWITCH_FAIL=ambiguous_caddy_file count=${#proxy_files[@]}"
  exit 1
fi
proxy_file=${proxy_files[0]}
if [[ "$(grep -o "127\\.0\\.0\\.1:$BLUE_PORT" "$proxy_file" | wc -l)" != 1 ]]; then
  echo 'RELEASE_SWITCH_FAIL=ambiguous_caddy_upstream'
  exit 1
fi

caddy_backup="$release_dir/backups/caddy-before-switch.conf"
if [[ -e "$caddy_backup" ]]; then
  if ! cmp -s -- "$proxy_file" "$caddy_backup"; then
    echo 'RELEASE_SWITCH_FAIL=existing_backup_does_not_match_blue_config'
    exit 1
  fi
else
  cp -p -- "$proxy_file" "$caddy_backup"
  chmod 600 "$caddy_backup"
fi
# Persist the exact recovery paths before the first configuration mutation so
# an independent rollback remains possible even if the cutover exits early.
release_state_set "$state_file" caddy_config "$proxy_file"
release_state_set "$state_file" caddy_backup "$caddy_backup"

candidate=$(mktemp "${proxy_file}.codex-${RELEASE_ID}.XXXXXX")
cleanup_candidate() { rm -f -- "$candidate"; }
trap cleanup_candidate EXIT
cp -p -- "$proxy_file" "$candidate"
sed -i "s/127\\.0\\.0\\.1:$BLUE_PORT/127.0.0.1:$GREEN_PORT/" "$candidate"
if [[ "$(grep -o "127\\.0\\.0\\.1:$GREEN_PORT" "$candidate" | wc -l)" != 1 ]]; then
  echo 'RELEASE_SWITCH_FAIL=candidate_upstream'
  exit 1
fi
caddy validate --config "$candidate" --adapter caddyfile >/dev/null

config_changed=0
rollback_caddy() {
  status=$?
  if ((status != 0 && config_changed == 1)); then
    cp -p -- "$caddy_backup" "$proxy_file"
    chmod 0644 "$proxy_file"
    caddy validate --config "$proxy_file" --adapter caddyfile >/dev/null 2>&1 || true
    systemctl reload caddy >/dev/null 2>&1 || true
  fi
  cleanup_candidate
  exit "$status"
}
trap rollback_caddy EXIT
mv -f -- "$candidate" "$proxy_file"
chmod 0644 "$proxy_file"
config_changed=1
caddy validate --config "$proxy_file" --adapter caddyfile >/dev/null
systemctl reload caddy
if [[ "$(systemctl is-active caddy)" != active ]]; then
  echo 'RELEASE_SWITCH_FAIL=caddy_inactive'
  exit 1
fi

for attempt in {1..12}; do
  docker exec "$green" wget -q -O /dev/null http://127.0.0.1:7001/
  [[ "$(docker inspect -f '{{.State.Running}}' "$green")" == true ]]
  sleep 5
done
if [[ "$(grep -o "127\\.0\\.0\\.1:$GREEN_PORT" "$proxy_file" | wc -l)" != 1 ]] ||
   grep -q "127\\.0\\.0\\.1:$BLUE_PORT" "$proxy_file"; then
  echo 'RELEASE_SWITCH_FAIL=caddy_route_not_committed'
  exit 1
fi

release_state_set "$state_file" switched_at "$(date -u +%FT%TZ)"
release_state_set "$state_file" traffic_state green

config_changed=0
trap - EXIT
echo "RELEASE_SWITCH=PASS id=$RELEASE_ID upstream=127.0.0.1:$GREEN_PORT external_smoke_required"
