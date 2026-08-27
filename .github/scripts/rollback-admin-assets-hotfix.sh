#!/usr/bin/env bash
set -Eeuo pipefail

: "${HOTFIX_ID:?HOTFIX_ID is required}"
if [[ ! "$HOTFIX_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'ADMIN_ASSET_ROLLBACK_FAIL=invalid_run_id'
  exit 1
fi
requested_hotfix_id=$HOTFIX_ID

if ! declare -F xboard_find_compose_anchor >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck disable=SC1091
  source "$script_dir/production-runtime-discovery.sh"
fi

if ! xboard_find_compose_anchor; then
  echo "ADMIN_ASSET_ROLLBACK_FAIL=blue_metadata_container_ambiguous detail=$XBOARD_DISCOVERY_ERROR"
  exit 1
fi
workdir=$XBOARD_ANCHOR_WORKDIR
state_file="$workdir/.codex-admin-hotfix/$HOTFIX_ID/state.env"
if [[ ! -f "$state_file" ]]; then
  echo 'ADMIN_ASSET_ROLLBACK_FAIL=state_missing'
  exit 1
fi
# shellcheck disable=SC1090
source "$state_file"
if [[ "$HOTFIX_ID" != "$requested_hotfix_id" ]] || [[ ! "$ACTIVE_CONTAINER" =~ ^[a-f0-9]{12,64}$ ]]; then
  echo 'ADMIN_ASSET_ROLLBACK_FAIL=invalid_state'
  exit 1
fi
if [[ "$(docker inspect -f '{{.State.Running}}' "$ACTIVE_CONTAINER")" != true ]]; then
  echo 'ADMIN_ASSET_ROLLBACK_FAIL=active_container_missing'
  exit 1
fi
if [[ "$BACKUP_PATH" != "/www/public/assets/.admin-before-$HOTFIX_ID" ]]; then
  echo 'ADMIN_ASSET_ROLLBACK_FAIL=invalid_backup_path'
  exit 1
fi

failed="/www/public/assets/.admin-rolled-back-$HOTFIX_ID"
docker exec -u 0 "$ACTIVE_CONTAINER" sh -eu -c '
  current=$1
  backup=$2
  failed=$3
  previous=$4
  [ -e "$current" ]
  [ ! -e "$failed" ]
  if [ "$previous" = 1 ]; then
    [ -e "$backup" ]
  else
    [ ! -e "$backup" ]
  fi
  mv "$current" "$failed"
  [ "$previous" != 1 ] || mv "$backup" "$current"
' sh /www/public/assets/admin "$BACKUP_PATH" "$failed" "$PREVIOUS_EXISTS"

echo "ROLLED_BACK_AT=$(date -u +%FT%TZ)" >> "$state_file"
echo "ADMIN_ASSET_ROLLBACK=PASS id=$HOTFIX_ID container=$ACTIVE_CONTAINER"
