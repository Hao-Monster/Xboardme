#!/usr/bin/env bash
set -Eeuo pipefail

: "${STAGE_IMAGE:?STAGE_IMAGE is required}"
: "${STAGE_RUN_ID:?STAGE_RUN_ID is required}"
: "${STAGE_DRY_RUN:=false}"

if [[ "$STAGE_DRY_RUN" != true && "$STAGE_DRY_RUN" != false ]]; then
  echo 'STAGE_FAIL=invalid_dry_run_flag'
  exit 1
fi
if [[ ! "$STAGE_RUN_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'STAGE_FAIL=invalid_run_id'
  exit 1
fi
if [[ ! "$STAGE_IMAGE" =~ ^ghcr\.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$ ]]; then
  echo 'STAGE_FAIL=image_must_be_an_immutable_ghcr_digest'
  exit 1
fi

command -v docker >/dev/null
docker info >/dev/null

mapfile -t candidates < <(
  {
    docker ps --format '{{.ID}} {{.Image}}' |
      awk 'tolower($2) ~ /xboard/ {print $1}'
    docker ps -q --filter label=com.docker.compose.service=xboard
  } | sort -u
)
production_candidates=()
for container_id in "${candidates[@]}"; do
  is_stage=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.stage" }}' "$container_id")
  [[ "$is_stage" == true ]] || production_candidates+=("$container_id")
done
if ((${#production_candidates[@]} == 0)); then
  echo 'STAGE_FAIL=no_running_production_container'
  exit 1
fi

declare -A projects=()
for container_id in "${production_candidates[@]}"; do
  project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id")
  workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$container_id")
  if [[ -n "$project" && -n "$workdir" ]]; then
    projects["$project|$workdir"]=1
  fi
done
if ((${#projects[@]} != 1)); then
  echo "STAGE_FAIL=ambiguous_production_project count=${#projects[@]}"
  exit 1
fi

project_key=${!projects[@]}
project=${project_key%%|*}
workdir=${project_key#*|}
primary=''
for container_id in "${production_candidates[@]}"; do
  candidate_project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id")
  if [[ "$candidate_project" == "$project" ]]; then
    primary=$container_id
    break
  fi
done
if [[ -z "$primary" || ! -d "$workdir" ]]; then
  echo 'STAGE_FAIL=invalid_production_project'
  exit 1
fi
cleanup_image=$(docker inspect -f '{{.Image}}' "$primary")

if docker ps -q --filter label=codex.xboard.stage=true | grep -q .; then
  echo 'STAGE_FAIL=another_stage_is_running'
  exit 1
fi
if command -v ss >/dev/null 2>&1 && ss -H -lnt '( sport = :7002 )' 2>/dev/null | grep -q .; then
  echo 'STAGE_FAIL=port_7002_in_use'
  exit 1
fi

available_kib=$(df -Pk "$workdir" | awk 'NR==2 {print $4}')
memory_available_kib=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
if ((available_kib < 3145728)); then
  echo 'STAGE_FAIL=less_than_3GiB_free'
  exit 1
fi
if ((memory_available_kib < 1048576)); then
  echo 'STAGE_FAIL=less_than_1GiB_memory_available'
  exit 1
fi

db_driver=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config("database.default");
')
db_path=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config("database.connections.sqlite.database");
')
journal_mode=$(docker exec "$primary" sqlite3 "$db_path" 'PRAGMA journal_mode;')
if [[ "$db_driver" != sqlite || "$db_path" != /www/.docker/.data/* || "$journal_mode" != wal ]]; then
  echo "STAGE_FAIL=unsupported_database driver=$db_driver journal=$journal_mode"
  exit 1
fi
db_directory=${db_path%/*}
if ! docker exec -u 0 "$primary" sh -c 'test -r "$1" && test -w "$2"' sh "$db_path" "$db_directory"; then
  echo 'STAGE_FAIL=database_snapshot_permissions'
  exit 1
fi

mount_source() {
  local destination=$1
  docker inspect -f "{{range .Mounts}}{{if eq .Destination \"$destination\"}}{{.Source}}{{end}}{{end}}" "$primary"
}

env_source=$(mount_source /www/.env)
theme_source=$(mount_source /www/storage/theme)
plugins_source=$(mount_source /www/plugins)
attachments_source=$(mount_source /www/storage/app/knowledge-attachments)
for required_source in "$env_source" "$theme_source" "$plugins_source" "$attachments_source"; do
  if [[ -z "$required_source" || "$required_source" != /* || "$required_source" == *$'\n'* || "$required_source" == *:* || ! -e "$required_source" ]]; then
    echo 'STAGE_FAIL=required_persistent_mount_missing'
    exit 1
  fi
done

db_size_kib=$(docker exec "$primary" sh -c 'size=$(stat -c %s "$1"); echo $(( (size + 1023) / 1024 ))' sh "$db_path")
session_size_kib=$(docker exec "$primary" du -sk /www/storage/framework/sessions 2>/dev/null | awk '{print $1}')
attachment_size_kib=$(du -sk -- "$attachments_source" | awk '{print $1}')
session_size_kib=${session_size_kib:-0}
# Reserve 2 GiB for the immutable image/layers and host headroom, in addition
# to the exact application data that will be cloned.
required_kib=$((db_size_kib + session_size_kib + attachment_size_kib + 2097152))
if ((available_kib < required_kib)); then
  echo "STAGE_FAIL=insufficient_clone_capacity available_kib=$available_kib required_kib=$required_kib"
  exit 1
fi

proxy_references=$( (grep -RIlE --include='*.conf' --include='Caddyfile' \
  -- '127\.0\.0\.1:7001' /etc/caddy 2>/dev/null || true) | wc -l )
if ! command -v caddy >/dev/null 2>&1 ||
   ! systemctl is-active --quiet caddy 2>/dev/null ||
   ((proxy_references != 1)); then
  echo "STAGE_FAIL=unsupported_caddy_proxy references=$proxy_references"
  exit 1
fi

if [[ "$STAGE_DRY_RUN" == true ]]; then
  echo "STAGE_DRY_RUN=PASS project=$project image=$STAGE_IMAGE"
  echo "STAGE_RESOURCES=available_kib:$available_kib required_kib:$required_kib memory_available_kib:$memory_available_kib"
  echo "STAGE_DATABASE=driver:$db_driver journal:$journal_mode"
  exit 0
fi

stage_root="$workdir/.codex-stage"
stage_dir="$stage_root/$STAGE_RUN_ID"
case "$stage_dir" in
  "$stage_root"/*) ;;
  *) echo 'STAGE_FAIL=unsafe_stage_path'; exit 1 ;;
esac
if [[ -e "$stage_dir" ]]; then
  echo 'STAGE_FAIL=stage_directory_exists'
  exit 1
fi

container_name="xboard-stage-$STAGE_RUN_ID"
snapshot_path="$db_directory/.codex-stage-$STAGE_RUN_ID.sqlite"
if docker exec -u 0 "$primary" test -e "$snapshot_path"; then
  echo 'STAGE_FAIL=snapshot_path_exists'
  exit 1
fi
created_stage_dir=0
stage_started=0
remove_stage_files() {
  [[ -d "$stage_dir" ]] || return 0
  # Container processes change cloned file ownership to their runtime UIDs.
  # Use a root helper constrained to the already validated bind mount, then
  # remove only the empty, run-specific host directory.
  docker run --rm --entrypoint sh -v "$stage_dir:/stage" "$cleanup_image" \
    -c 'find /stage -mindepth 1 -delete' >/dev/null
  rmdir -- "$stage_dir"
}
cleanup_on_error() {
  status=$?
  if ((status != 0)); then
    if ((stage_started == 1)); then
      docker rm -f "$container_name" >/dev/null 2>&1 || true
    fi
    docker exec -u 0 "$primary" rm -f "$snapshot_path" >/dev/null 2>&1 || true
    if ((created_stage_dir == 1)) && [[ -d "$stage_dir" ]]; then
      remove_stage_files || true
    fi
  fi
  exit "$status"
}
trap cleanup_on_error EXIT

mkdir -p "$stage_dir/data/sessions" "$stage_dir/redis" "$stage_dir/logs" "$stage_dir/attachments"
chmod 700 "$stage_root" "$stage_dir" "$stage_dir/data" "$stage_dir/redis" "$stage_dir/logs" "$stage_dir/attachments"
created_stage_dir=1

docker pull "$STAGE_IMAGE"
if ! docker exec -u 0 "$primary" sh -c 'umask 077; : > "$1"' sh "$snapshot_path"; then
  echo 'STAGE_FAIL=snapshot_target_create'
  exit 1
fi
if ! docker exec -u 0 "$primary" sqlite3 "$db_path" ".backup '$snapshot_path'"; then
  source_check=$(docker exec -u 0 "$primary" sqlite3 "$db_path" 'PRAGMA quick_check;' 2>/dev/null || echo failed)
  target_check=$(docker exec -u 0 "$primary" sqlite3 "$snapshot_path" 'PRAGMA quick_check;' 2>/dev/null || echo failed)
  echo "STAGE_FAIL=sqlite_online_backup source_check=$source_check target_check=$target_check"
  exit 1
fi
docker cp "$primary:$snapshot_path" "$stage_dir/data/database.sqlite" >/dev/null
docker exec -u 0 "$primary" rm -f "$snapshot_path"
snapshot_counts=$(docker run --rm --entrypoint sqlite3 \
  -v "$stage_dir/data:/stage:ro" "$STAGE_IMAGE" \
  -separator '|' /stage/database.sqlite \
  'SELECT (SELECT COUNT(*) FROM v2_user), (SELECT COUNT(*) FROM v2_order), (SELECT COUNT(*) FROM v2_plugins);')
docker cp "$primary:/www/storage/framework/sessions/." "$stage_dir/data/sessions/" >/dev/null
cp -a -- "$attachments_source/." "$stage_dir/attachments/"

integrity=$(docker run --rm --entrypoint sqlite3 \
  -v "$stage_dir/data:/stage:ro" "$STAGE_IMAGE" \
  /stage/database.sqlite 'PRAGMA integrity_check;')
if [[ "$integrity" != ok ]]; then
  echo 'STAGE_FAIL=database_integrity_check'
  exit 1
fi

docker run -d \
  --name "$container_name" \
  --hostname "$container_name" \
  --label codex.xboard.stage=true \
  --label "codex.xboard.stage.run=$STAGE_RUN_ID" \
  --restart no \
  --memory 768m \
  --cpus 2 \
  -p 127.0.0.1:7002:7001 \
  -e SKIP_XBOARD_UPDATE=true \
  -e "RUNTIME_INSTANCE_ID=stage-$STAGE_RUN_ID" \
  -e RESOURCE_PROFILE=minimal \
  -e ENABLE_HORIZON=false \
  -e ENABLE_REDIS=true \
  -e ENABLE_WS_SERVER=true \
  -e ENABLE_CADDY=true \
  -e MAIL_MAILER=array \
  -e TELESCOPE_ENABLED=false \
  -e SESSION_DRIVER=file \
  -e SESSION_SERIALIZATION=php \
  -e SESSION_FILES_PATH=/www/.docker/.data/sessions \
  -v "$env_source:/www/.env:ro" \
  -v "$stage_dir/data:/www/.docker/.data" \
  -v "$stage_dir/redis:/data" \
  -v "$stage_dir/logs:/www/storage/logs" \
  -v "$theme_source:/www/storage/theme:ro" \
  -v "$plugins_source:/www/plugins:ro" \
  -v "$stage_dir/attachments:/www/storage/app/knowledge-attachments" \
  "$STAGE_IMAGE" >/dev/null
stage_started=1

healthy=0
for attempt in {1..30}; do
  if docker exec "$container_name" wget -q -O /dev/null http://127.0.0.1:7001/; then
    healthy=1
    break
  fi
  sleep 2
done
if ((healthy != 1)); then
  docker logs --tail 100 "$container_name" >&2 || true
  echo 'STAGE_FAIL=green_http_health'
  exit 1
fi

runtime=$(docker exec "$container_name" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$plugins = App\Models\Plugin::query()->where("is_enabled", true)->orderBy("code")->pluck("code")->all();
echo json_encode([
    "php" => PHP_VERSION,
    "laravel" => app()->version(),
    "plugins" => $plugins,
], JSON_UNESCAPED_SLASHES);
')
if [[ "$runtime" != *'"laravel":"13.'* ]]; then
  echo "STAGE_FAIL=unexpected_runtime $runtime"
  exit 1
fi
if docker exec "$container_name" php /www/artisan migrate:status --no-interaction | grep -q Pending; then
  echo 'STAGE_FAIL=pending_migrations'
  exit 1
fi
docker exec "$container_name" php /www/artisan knowledge-attachments:status --json >/dev/null
post_boot_counts=$(docker exec "$container_name" sqlite3 -separator '|' "$db_path" \
  'SELECT (SELECT COUNT(*) FROM v2_user), (SELECT COUNT(*) FROM v2_order), (SELECT COUNT(*) FROM v2_plugins);')
if [[ "$post_boot_counts" != "$snapshot_counts" ]]; then
  echo "STAGE_FAIL=baseline_row_counts_changed before=$snapshot_counts after=$post_boot_counts"
  exit 1
fi

trap - EXIT
echo "STAGE_BASELINE=PASS project=$project container=$container_name image=$STAGE_IMAGE"
echo "STAGE_RUNTIME=$runtime"
echo 'STAGE_DATA_COUNTS=PASS'
echo "STAGE_DIRECTORY=$stage_dir"
