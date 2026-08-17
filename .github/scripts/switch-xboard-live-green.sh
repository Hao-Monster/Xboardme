#!/usr/bin/env bash
set -Eeuo pipefail

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

mapfile -t blue_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#blue_ids[@]} != 1)); then
  echo "RELEASE_SWITCH_FAIL=blue_missing count=${#blue_ids[@]}"
  exit 1
fi
blue=${blue_ids[0]}
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$blue")
release_dir="$workdir/.codex-release/$RELEASE_ID"
state_file="$release_dir/state.env"
if [[ ! -f "$state_file" ]]; then
  echo 'RELEASE_SWITCH_FAIL=state_missing'
  exit 1
fi
# shellcheck disable=SC1090
source "$state_file"
if [[ "$RELEASE_SHA" != "$EXPECTED_RELEASE_SHA" ]]; then
  echo 'RELEASE_SWITCH_FAIL=release_commit_mismatch'
  exit 1
fi
if [[ "$BLUE_CONTAINER" != "$blue" || "$GREEN_CONTAINER" != "$(docker inspect -f '{{.Name}}' "$green" | sed 's#^/##')" ]]; then
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
    -- '127\.0\.0\.1:7001' /etc/caddy 2>/dev/null || true
)
if ((${#proxy_files[@]} != 1)); then
  echo "RELEASE_SWITCH_FAIL=ambiguous_caddy_file count=${#proxy_files[@]}"
  exit 1
fi
proxy_file=${proxy_files[0]}
if [[ "$(grep -o '127\.0\.0\.1:7001' "$proxy_file" | wc -l)" != 1 ]]; then
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
set_state() {
  local key=$1 value=$2 temporary
  temporary=$(mktemp "${state_file}.XXXXXX")
  awk -F= -v key="$key" '$1 != key {print}' "$state_file" > "$temporary"
  printf '%s=%q\n' "$key" "$value" >> "$temporary"
  chmod 600 "$temporary"
  mv -f -- "$temporary" "$state_file"
}
# Persist the exact recovery paths before the first configuration mutation so
# an independent rollback remains possible even if the cutover exits early.
set_state CADDY_CONFIG "$proxy_file"
set_state CADDY_BACKUP "$caddy_backup"

candidate=$(mktemp "${proxy_file}.codex-${RELEASE_ID}.XXXXXX")
cleanup_candidate() { rm -f -- "$candidate"; }
trap cleanup_candidate EXIT
cp -p -- "$proxy_file" "$candidate"
sed -i 's/127\.0\.0\.1:7001/127.0.0.1:7002/' "$candidate"
if [[ "$(grep -o '127\.0\.0\.1:7002' "$candidate" | wc -l)" != 1 ]]; then
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

app_url=$(docker exec "$green" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo rtrim((string) config("app.url"), "/");
')
case "$app_url" in
  http://*|https://*) ;;
  *) echo 'RELEASE_SWITCH_FAIL=invalid_app_url'; exit 1 ;;
esac

for attempt in {1..12}; do
  docker exec "$green" wget -q -O /dev/null http://127.0.0.1:7001/
  public_ready=0
  for public_attempt in {1..3}; do
    if curl --silent --show-error --fail --location --max-time 10 --output /dev/null "$app_url/"; then
      public_ready=1
      break
    fi
    sleep 1
  done
  [[ "$public_ready" == 1 ]]
  [[ "$(docker inspect -f '{{.State.Running}}' "$green")" == true ]]
  sleep 5
done
if [[ "$(grep -o '127\.0\.0\.1:7002' "$proxy_file" | wc -l)" != 1 ]] || \
   grep -q '127\.0\.0\.1:7001' "$proxy_file"; then
  echo 'RELEASE_SWITCH_FAIL=caddy_route_not_committed'
  exit 1
fi

set_state SWITCHED_AT "$(date -u +%FT%TZ)"
set_state TRAFFIC_STATE green

config_changed=0
trap - EXIT
echo "RELEASE_SWITCH=PASS id=$RELEASE_ID upstream=127.0.0.1:7002 app_url=$app_url"
