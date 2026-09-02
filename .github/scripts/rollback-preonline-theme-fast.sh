#!/usr/bin/env bash
set -Eeuo pipefail

: "${ASSET_SHA:?ASSET_SHA is required}"
if [[ ! "$ASSET_SHA" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'PREONLINE_THEME_ROLLBACK_FAIL=invalid_revision'
  exit 1
fi

deploy_root=/home/bingo/apps/xboardme-pre-online
active=xboardme-preonline
release_root="$deploy_root/fast-theme-releases"
state_file="$release_root/$ASSET_SHA/state.env"
current_state="$release_root/current.env"
test -f "$state_file"
test -f "$current_state"
# shellcheck disable=SC1090
source "$state_file"
# shellcheck disable=SC1090
source "$current_state"
actual_container_id=$(docker inspect -f '{{.Id}}' "$active")
actual_asset_label=$(docker inspect -f '{{index .Config.Labels "codex.preonline.asset-revision"}}' "$active")
if [[ "$CURRENT_ASSET_SHA" != "$ASSET_SHA" ]] ||
   { [[ "$ACTIVE_CONTAINER_ID" != "$actual_container_id" ]] && [[ "$actual_asset_label" != "$ASSET_SHA" ]]; } ||
   [[ "$BACKUP_PATH" != "/www/theme/.Xboard-before-${ASSET_SHA:0:12}" ]]; then
  echo 'PREONLINE_THEME_ROLLBACK_FAIL=state_mismatch'
  exit 1
fi

failed="/www/theme/.Xboard-rolled-back-${ASSET_SHA:0:12}"
docker exec -u 0 "$active" sh -eu -c '
  current=$1
  backup=$2
  failed=$3
  [ -e "$current" ]
  [ -e "$backup" ]
  [ ! -e "$failed" ]
  mv "$current" "$failed"
  if ! mv "$backup" "$current"; then
    mv "$failed" "$current"
    exit 1
  fi
' sh /www/theme/Xboard "$BACKUP_PATH" "$failed"

docker exec "$active" php /www/artisan view:clear >/dev/null
dashboard=$(docker exec "$active" wget -q -O - http://127.0.0.1:7001/)
if [[ "$PREVIOUS_ASSET_SHA" != "$BASE_RELEASE_SHA" ]]; then
  grep -Fq "?v=$PREVIOUS_ASSET_SHA" <<<"$dashboard"
else
  grep -Fq "?v=$BASE_RELEASE_SHA" <<<"$dashboard"
fi

{
  printf 'CURRENT_ASSET_SHA=%q\n' "$PREVIOUS_ASSET_SHA"
  printf 'ROLLED_BACK_FROM=%q\n' "$ASSET_SHA"
  printf 'ROLLED_BACK_AT=%q\n' "$(date -u +%FT%TZ)"
} > "$current_state.tmp"
chmod 600 "$current_state.tmp"
mv "$current_state.tmp" "$current_state"
printf 'PREONLINE_THEME_ROLLBACK=PASS asset_sha=%s restored=%s\n' "$ASSET_SHA" "$PREVIOUS_ASSET_SHA"
