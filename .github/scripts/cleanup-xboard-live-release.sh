#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_ID:?RELEASE_ID is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_CLEANUP_FAIL=invalid_run_id'
  exit 1
fi
mapfile -t caddy_configs < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- '127\.0\.0\.1:700[12]' /etc/caddy 2>/dev/null || true
)
if ((${#caddy_configs[@]} != 1)); then
  echo "RELEASE_CLEANUP_FAIL=ambiguous_caddy_file count=${#caddy_configs[@]}"
  exit 1
fi
caddy_config=${caddy_configs[0]}
caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null
if [[ "$(grep -o '127\.0\.0\.1:7001' "$caddy_config" | wc -l)" != 1 ]] || \
   grep -q '127\.0\.0\.1:7002' "$caddy_config"; then
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
