#!/usr/bin/env bash
set -Eeuo pipefail

: "${HOTFIX_ID:?HOTFIX_ID is required}"
: "${STAGE_RUN_ID:?STAGE_RUN_ID is required}"
: "${RELEASE_SHA:?RELEASE_SHA is required}"

for identifier in "$HOTFIX_ID" "$STAGE_RUN_ID"; do
  if [[ ! "$identifier" =~ ^[0-9]+-[0-9]+$ ]]; then
    echo 'ADMIN_ASSET_HOTFIX_FAIL=invalid_run_id'
    exit 1
  fi
done
if [[ ! "$RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=invalid_commit_sha'
  exit 1
fi

mapfile -t stage_ids < <(
  docker ps -q \
    --filter label=codex.xboard.stage=true \
    --filter "label=codex.xboard.stage.run=$STAGE_RUN_ID"
)
if ((${#stage_ids[@]} != 1)); then
  echo "ADMIN_ASSET_HOTFIX_FAIL=approved_stage_missing count=${#stage_ids[@]}"
  exit 1
fi
stage=${stage_ids[0]}
release_image=$(docker inspect -f '{{.Config.Image}}' "$stage")
if [[ ! "$release_image" =~ ^ghcr\.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$ ]]; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=stage_image_not_immutable'
  exit 1
fi
if [[ "$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$release_image")" != "$RELEASE_SHA" ]]; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=stage_image_revision_mismatch'
  exit 1
fi
docker exec "$stage" php /www/.github/scripts/verify-admin-assets.php >/dev/null

mapfile -t active_ids < <(
  docker ps -q \
    --filter label=codex.xboard.release=true \
    --filter label=codex.xboard.release.role=web
)
if ((${#active_ids[@]} != 1)); then
  echo "ADMIN_ASSET_HOTFIX_FAIL=active_web_ambiguous count=${#active_ids[@]}"
  exit 1
fi
active=${active_ids[0]}
if [[ "$(docker inspect -f '{{.State.Running}}' "$active")" != true ]] || \
   ! docker port "$active" 7001/tcp | grep -Eq '^127\.0\.0\.1:7002$'; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=active_web_not_on_expected_port'
  exit 1
fi

mapfile -t proxy_files < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- '127\.0\.0\.1:7002' /etc/caddy 2>/dev/null || true
)
if ((${#proxy_files[@]} != 1)) || \
   [[ "$(grep -o '127\.0\.0\.1:7002' "${proxy_files[0]}" | wc -l)" != 1 ]]; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=active_caddy_route_ambiguous'
  exit 1
fi

mapfile -t blue_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#blue_ids[@]} != 1)); then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=blue_metadata_container_ambiguous'
  exit 1
fi
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${blue_ids[0]}")
if [[ -z "$workdir" || ! -d "$workdir" ]]; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=invalid_compose_workdir'
  exit 1
fi

hotfix_root="$workdir/.codex-admin-hotfix"
hotfix_dir="$hotfix_root/$HOTFIX_ID"
case "$hotfix_dir" in
  "$hotfix_root"/*) ;;
  *) echo 'ADMIN_ASSET_HOTFIX_FAIL=unsafe_hotfix_path'; exit 1 ;;
esac
if [[ -e "$hotfix_dir" ]]; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=hotfix_state_exists'
  exit 1
fi
mkdir -p "$hotfix_dir/payload"
chmod 700 "$hotfix_root" "$hotfix_dir" "$hotfix_dir/payload"
docker cp "$stage:/www/public/assets/admin/." "$hotfix_dir/payload"
if find "$hotfix_dir/payload" -type l -print -quit | grep -q .; then
  echo 'ADMIN_ASSET_HOTFIX_FAIL=payload_contains_symlink'
  exit 1
fi

candidate="/www/public/assets/.admin-candidate-$HOTFIX_ID"
backup="/www/public/assets/.admin-before-$HOTFIX_ID"
validator="/tmp/verify-admin-assets-$HOTFIX_ID.php"
for path in "$candidate" "$backup" "$validator"; do
  if docker exec "$active" test -e "$path"; then
    echo "ADMIN_ASSET_HOTFIX_FAIL=container_path_exists path=$path"
    exit 1
  fi
done

docker exec -u 0 "$active" mkdir -p /www/public/assets
docker cp "$hotfix_dir/payload" "$active:$candidate"
docker cp "$stage:/www/.github/scripts/verify-admin-assets.php" "$active:$validator"
docker exec "$active" php "$validator" "$candidate" >/dev/null

previous_exists=0
if docker exec "$active" test -e /www/public/assets/admin; then
  previous_exists=1
fi

switched=0
restore_on_error() {
  status=$?
  if ((status != 0 && switched == 1)); then
    docker exec -u 0 "$active" sh -eu -c '
      current=$1
      backup=$2
      failed=$3
      [ ! -e "$failed" ]
      [ -e "$current" ] && mv "$current" "$failed"
      [ ! -e "$backup" ] || mv "$backup" "$current"
    ' sh /www/public/assets/admin "$backup" "/www/public/assets/.admin-failed-$HOTFIX_ID" || true
  fi
  docker exec -u 0 "$active" rm -f "$validator" >/dev/null 2>&1 || true
  exit "$status"
}
trap restore_on_error EXIT

docker exec -u 0 "$active" sh -eu -c '
  current=$1
  candidate=$2
  backup=$3
  previous=$4
  if [ "$previous" = 1 ]; then
    mv "$current" "$backup"
  fi
  if ! mv "$candidate" "$current"; then
    [ "$previous" != 1 ] || mv "$backup" "$current"
    exit 1
  fi
  chown -R www:www "$current"
  find "$current" -type d -exec chmod 0755 {} +
  find "$current" -type f -exec chmod 0644 {} +
' sh /www/public/assets/admin "$candidate" "$backup" "$previous_exists"
switched=1

docker exec "$active" php "$validator" /www/public/assets/admin >/dev/null
docker exec "$active" wget -q -O /dev/null http://127.0.0.1:7001/assets/admin/manifest.json
entry_asset=$(docker exec "$active" php -r '
$manifest = json_decode(file_get_contents("/www/public/assets/admin/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
$entry = $manifest["index.html"]["file"] ?? null;
if (!is_string($entry) || $entry === "" || str_starts_with($entry, "/") || str_contains($entry, "..")) {
    exit(1);
}
echo $entry;
')
docker exec "$active" wget -q -O /dev/null "http://127.0.0.1:7001/assets/admin/$entry_asset"
docker exec "$active" wget -q -O /dev/null http://127.0.0.1:7001/assets/admin/locales/zh-CN.js

state_file="$hotfix_dir/state.env"
{
  printf 'HOTFIX_ID=%q\n' "$HOTFIX_ID"
  printf 'RELEASE_SHA=%q\n' "$RELEASE_SHA"
  printf 'RELEASE_IMAGE=%q\n' "$release_image"
  printf 'STAGE_RUN_ID=%q\n' "$STAGE_RUN_ID"
  printf 'ACTIVE_CONTAINER=%q\n' "$active"
  printf 'BACKUP_PATH=%q\n' "$backup"
  printf 'PREVIOUS_EXISTS=%q\n' "$previous_exists"
  printf 'ENTRY_ASSET=%q\n' "$entry_asset"
  printf 'DEPLOYED_AT=%q\n' "$(date -u +%FT%TZ)"
} > "$state_file"
chmod 600 "$state_file"

docker exec -u 0 "$active" rm -f "$validator"
switched=0
trap - EXIT
echo "ADMIN_ASSET_HOTFIX=PASS id=$HOTFIX_ID container=$active entry=$entry_asset"
echo "ADMIN_ASSET_HOTFIX_ROLLBACK=$state_file"
