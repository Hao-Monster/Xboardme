#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_ID:?RELEASE_ID is required}"
if [[ ! "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'RELEASE_CLEANUP_FAIL=invalid_run_id'
  exit 1
fi
if [[ "$(grep -Rho '127\.0\.0\.1:7001' /etc/caddy 2>/dev/null | wc -l)" != 1 ]]; then
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
