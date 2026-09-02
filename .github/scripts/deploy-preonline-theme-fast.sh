#!/usr/bin/env bash
set -Eeuo pipefail

: "${ASSET_SHA:?ASSET_SHA is required}"
: "${BASE_RELEASE_SHA:?BASE_RELEASE_SHA is required}"

if [[ ! "$ASSET_SHA" =~ ^[a-f0-9]{40}$ ]] || [[ ! "$BASE_RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'PREONLINE_THEME_FAST_FAIL=invalid_revision'
  exit 1
fi

deploy_root=/home/bingo/apps/xboardme-pre-online
active=xboardme-preonline
incoming="$deploy_root/incoming/theme-$ASSET_SHA.tar.gz"
incoming_rollback="$deploy_root/incoming/rollback-theme-$ASSET_SHA.sh"
release_root="$deploy_root/fast-theme-releases"
release_dir="$release_root/$ASSET_SHA"
state_file="$release_dir/state.env"
current_state="$release_root/current.env"
short_sha=${ASSET_SHA:0:12}
candidate="/www/theme/.Xboard-candidate-$short_sha"
backup="/www/theme/.Xboard-before-$short_sha"
failed="/www/theme/.Xboard-failed-$short_sha"
public_current="/www/public/theme/Xboard"
public_candidate="/www/public/theme/.Xboard-candidate-$short_sha"
public_backup="/www/public/theme/.Xboard-before-$short_sha"
public_failed="/www/public/theme/.Xboard-failed-$short_sha"

test -f "$deploy_root/release-state.env"
# shellcheck disable=SC1090
source "$deploy_root/release-state.env"
if [[ "$RELEASE_SHA" != "$BASE_RELEASE_SHA" ]]; then
  echo "PREONLINE_THEME_FAST_FAIL=base_release_mismatch active=$RELEASE_SHA expected=$BASE_RELEASE_SHA"
  exit 1
fi
if [[ "$(docker inspect -f '{{.State.Running}}' "$active")" != true ]] ||
   [[ "$(docker inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$active")" != "$BASE_RELEASE_SHA" ]]; then
  echo 'PREONLINE_THEME_FAST_FAIL=active_runtime_mismatch'
  exit 1
fi

if [[ -f "$current_state" ]]; then
  # shellcheck disable=SC1090
  source "$current_state"
  if [[ "${CURRENT_ASSET_SHA:-}" == "$ASSET_SHA" ]]; then
    echo "PREONLINE_THEME_FAST=NOOP asset_sha=$ASSET_SHA"
    exit 0
  fi
fi
if [[ -e "$release_dir" ]]; then
  echo 'PREONLINE_THEME_FAST_FAIL=release_state_exists'
  exit 1
fi
if [[ ! -f "$incoming" ]]; then
  echo 'PREONLINE_THEME_FAST_FAIL=payload_missing'
  exit 1
fi
test -f "$incoming_rollback"

mkdir -p "$release_dir/payload"
chmod 700 "$release_root" "$release_dir" "$release_dir/payload"
mv "$incoming" "$release_dir/payload.tar.gz"

if tar -tzf "$release_dir/payload.tar.gz" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
  echo 'PREONLINE_THEME_FAST_FAIL=unsafe_archive_path'
  exit 1
fi
if tar -tzf "$release_dir/payload.tar.gz" | grep -Ev '^theme/Xboard(/|$)' | grep -q .; then
  echo 'PREONLINE_THEME_FAST_FAIL=archive_outside_theme_scope'
  exit 1
fi
tar -xzf "$release_dir/payload.tar.gz" -C "$release_dir/payload"
theme="$release_dir/payload/theme/Xboard"
manifest="$theme/assets/release-manifest.json"
for required in dashboard.blade.php assets/distributor.css assets/distributor.js assets/release-manifest.json; do
  test -s "$theme/$required" || { echo "PREONLINE_THEME_FAST_FAIL=missing_file file=$required"; exit 1; }
done
if find "$release_dir/payload" -type l -print -quit | grep -q .; then
  echo 'PREONLINE_THEME_FAST_FAIL=payload_contains_symlink'
  exit 1
fi
if find "$release_dir/payload" ! -type f ! -type d -print -quit | grep -q .; then
  echo 'PREONLINE_THEME_FAST_FAIL=unsupported_payload_entry'
  exit 1
fi
if find "$release_dir/payload" -type f ! -path "$theme/*" -print -quit | grep -q .; then
  echo 'PREONLINE_THEME_FAST_FAIL=payload_outside_theme_scope'
  exit 1
fi
if [[ "$(jq -er '.schema' "$manifest")" != 1 ]] ||
   [[ "$(jq -er '.revision' "$manifest")" != "$ASSET_SHA" ]]; then
  echo 'PREONLINE_THEME_FAST_FAIL=manifest_identity_mismatch'
  exit 1
fi
while IFS=$'\t' read -r asset expected_hash expected_bytes; do
  if [[ ! "$asset" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "PREONLINE_THEME_FAST_FAIL=invalid_manifest_asset asset=$asset"
    exit 1
  fi
  path="$theme/assets/$asset"
  test -f "$path"
  [[ "$(sha256sum "$path" | cut -d' ' -f1)" == "$expected_hash" ]]
  [[ "$(stat -c %s "$path")" == "$expected_bytes" ]]
done < <(jq -r '.assets | to_entries[] | [.key, .value.sha256, .value.bytes] | @tsv' "$manifest")
[[ "$(grep -oF "?v=$ASSET_SHA" "$theme/dashboard.blade.php" | wc -l)" == 7 ]]

for path in "$candidate" "$backup" "$failed" "$public_candidate" "$public_backup" "$public_failed"; do
  if docker exec "$active" test -e "$path"; then
    echo "PREONLINE_THEME_FAST_FAIL=container_path_exists path=$path"
    exit 1
  fi
done
docker exec -u 0 "$active" test -d "$public_current"
docker exec -u 0 "$active" mkdir -p "$candidate" "$public_candidate"
docker cp "$theme/." "$active:$candidate/"
docker cp "$theme/." "$active:$public_candidate/"
docker exec -u 0 "$active" sh -eu -c '
  candidate=$1
  public_candidate=$2
  find "$candidate" -type d -exec chmod 0755 {} +
  find "$candidate" -type f -exec chmod 0644 {} +
  find "$public_candidate" -type d -exec chmod 0755 {} +
  find "$public_candidate" -type f -exec chmod 0644 {} +
  chown -R www:www "$candidate" "$public_candidate"
' sh "$candidate" "$public_candidate"

for root in "$candidate" "$public_candidate"; do
  while IFS=$'\t' read -r asset expected_hash expected_bytes; do
    actual_hash=$(docker exec "$active" sha256sum "$root/assets/$asset" | cut -d' ' -f1)
    actual_bytes=$(docker exec "$active" stat -c %s "$root/assets/$asset")
    [[ "$actual_hash" == "$expected_hash" ]] && [[ "$actual_bytes" == "$expected_bytes" ]]
  done < <(jq -r '.assets | to_entries[] | [.key, .value.sha256, .value.bytes] | @tsv' "$manifest")
done

switched=0
restore_on_error() {
  status=$?
  trap - ERR
  if ((switched == 1)); then
    docker exec -u 0 "$active" sh -eu -c '
      restore_pair() {
        current=$1
        backup=$2
        failed=$3
        [ ! -e "$failed" ]
        if [ -e "$backup" ]; then
          [ -e "$current" ] && mv "$current" "$failed"
          mv "$backup" "$current"
        fi
      }
      restore_pair "$1" "$2" "$3"
      restore_pair "$4" "$5" "$6"
    ' sh /www/theme/Xboard "$backup" "$failed" "$public_current" "$public_backup" "$public_failed" || true
    docker exec "$active" php /www/artisan view:clear >/dev/null 2>&1 || true
    docker exec -u 0 "$active" rm -rf -- "$candidate" "$public_candidate" >/dev/null 2>&1 || true
  else
    docker exec -u 0 "$active" rm -rf -- "$candidate" "$public_candidate" >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap restore_on_error ERR

switched=1
docker exec -u 0 "$active" sh -eu -c '
  current=$1
  candidate=$2
  backup=$3
  public_current=$4
  public_candidate=$5
  public_backup=$6
  mv "$current" "$backup"
  if ! mv "$candidate" "$current"; then
    mv "$backup" "$current"
    exit 1
  fi
  if ! mv "$public_current" "$public_backup"; then
    mv "$current" "$candidate"
    mv "$backup" "$current"
    exit 1
  fi
  if ! mv "$public_candidate" "$public_current"; then
    mv "$public_backup" "$public_current"
    mv "$current" "$candidate"
    mv "$backup" "$current"
    exit 1
  fi
' sh /www/theme/Xboard "$candidate" "$backup" "$public_current" "$public_candidate" "$public_backup"

docker exec "$active" php /www/artisan view:clear >/dev/null
dashboard=$(docker exec "$active" wget -q -O - http://127.0.0.1:7001/)
[[ "$(grep -oF "?v=$ASSET_SHA" <<<"$dashboard" | wc -l)" == 7 ]]
served_css=$(docker exec "$active" wget -q -O - "http://127.0.0.1:7001/theme/Xboard/assets/distributor.css?v=$ASSET_SHA")
served_css_hash=$(printf '%s' "$served_css" | sha256sum | cut -d' ' -f1)
public_css_hash=$(docker exec "$active" sha256sum "$public_current/assets/distributor.css" | cut -d' ' -f1)
[[ "$served_css_hash" == "$public_css_hash" ]]

container_id=$(docker inspect -f '{{.Id}}' "$active")
previous_asset_sha=$BASE_RELEASE_SHA
if [[ -f "$current_state" ]]; then
  # shellcheck disable=SC1090
  source "$current_state"
  previous_asset_sha=${CURRENT_ASSET_SHA:-$BASE_RELEASE_SHA}
fi
docker commit --pause=false \
  --change "LABEL codex.preonline.asset-revision=$ASSET_SHA" \
  "$active" "xboardme-preonline:fast-$ASSET_SHA" >/dev/null
{
  printf 'ASSET_SHA=%q\n' "$ASSET_SHA"
  printf 'BASE_RELEASE_SHA=%q\n' "$BASE_RELEASE_SHA"
  printf 'ACTIVE_CONTAINER_ID=%q\n' "$container_id"
  printf 'BACKUP_PATH=%q\n' "$backup"
  printf 'PUBLIC_BACKUP_PATH=%q\n' "$public_backup"
  printf 'PREVIOUS_ASSET_SHA=%q\n' "$previous_asset_sha"
  printf 'DEPLOYED_AT=%q\n' "$(date -u +%FT%TZ)"
} > "$state_file"
chmod 600 "$state_file"
install -m 750 "$incoming_rollback" "$release_dir/rollback.sh"
{
  printf 'CURRENT_ASSET_SHA=%q\n' "$ASSET_SHA"
  printf 'CURRENT_RELEASE_STATE=%q\n' "$state_file"
} > "$current_state.tmp"
chmod 600 "$current_state.tmp"
mv "$current_state.tmp" "$current_state"
rm -f -- "$incoming_rollback"
rm -f -- "$deploy_root/incoming/deploy-theme-$ASSET_SHA.sh"

switched=0
trap - ERR
printf 'PREONLINE_THEME_FAST=PASS\n'
printf 'BASE_RELEASE_SHA=%s\n' "$BASE_RELEASE_SHA"
printf 'ASSET_SHA=%s\n' "$ASSET_SHA"
printf 'ROLLBACK_STATE=%s\n' "$state_file"
