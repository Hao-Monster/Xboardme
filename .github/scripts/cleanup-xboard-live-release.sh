#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_get >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_CLEANUP_FAIL=invalid_run_id'
  exit 1
fi
mapfile -t compose_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#compose_ids[@]} != 1)); then
  echo 'RELEASE_CLEANUP_FAIL=compose_base_missing'
  exit 1
fi
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${compose_ids[0]}")
release_dir="$workdir/.codex-release/$RELEASE_ID"
if ! state_file=$(release_state_open "$release_dir"); then
  echo 'RELEASE_CLEANUP_FAIL=state_missing'
  exit 1
fi
STATE_RELEASE_ID=$(release_state_get "$state_file" release_id)
BLUE_PORT=$(release_state_get "$state_file" blue_port)
GREEN_PORT=$(release_state_get "$state_file" green_port)
TRAFFIC_STATE=$(release_state_get "$state_file" traffic_state)
CADDY_CONFIG=$(release_state_get_optional "$state_file" caddy_config)
if [[ "$STATE_RELEASE_ID" != "$RELEASE_ID" ]]; then
  echo 'RELEASE_CLEANUP_FAIL=release_state_identity_mismatch'
  exit 1
fi
caddy_config=${CADDY_CONFIG:-}
if [[ -z "$caddy_config" ]]; then
  mapfile -t caddy_candidates < <(
    grep -RIlE --include='*.conf' --include='Caddyfile' \
      -- "127\\.0\\.0\\.1:$BLUE_PORT" /etc/caddy 2>/dev/null || true
  )
  if ((${#caddy_candidates[@]} != 1)); then
    echo "RELEASE_CLEANUP_FAIL=ambiguous_caddy_file count=${#caddy_candidates[@]}"
    exit 1
  fi
  caddy_config=${caddy_candidates[0]}
fi
if [[ ! -f "$caddy_config" ]]; then
  echo 'RELEASE_CLEANUP_FAIL=caddy_file_missing'
  exit 1
fi
caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null
if [[ "$TRAFFIC_STATE" != blue ]] ||
   [[ "$(grep -o "127\\.0\\.0\\.1:$BLUE_PORT" "$caddy_config" | wc -l)" != 1 ]] ||
   grep -q "127\\.0\\.0\\.1:$GREEN_PORT" "$caddy_config"; then
  echo 'RELEASE_CLEANUP_FAIL=traffic_not_on_blue'
  exit 1
fi

mapfile -t containers < <(
  docker ps -aq \
    --filter label=codex.xboard.release=true \
    --filter "label=codex.xboard.release.run=$RELEASE_ID"
)
for container in "${containers[@]}"; do
  docker rm -f "$container" >/dev/null
done

# Preserve the release state, database backup, Caddy backup and logs as audit
# evidence. Cleanup removes only candidate containers and never production data.
echo "RELEASE_CLEANUP=PASS id=$RELEASE_ID containers=${#containers[@]} evidence=preserved"
