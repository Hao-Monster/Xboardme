#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${STAGE_RUN_ID:?STAGE_RUN_ID is required}"
: "${RELEASE_SHA:?RELEASE_SHA is required}"

if [[ ! "$RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'RELEASE_PREPARE_FAIL=invalid_commit_sha'
  exit 1
fi
for identifier in "$RELEASE_ID" "$STAGE_RUN_ID"; do
  if [[ ! "$identifier" =~ ^[0-9]+-[0-9]+$ ]]; then
    echo 'RELEASE_PREPARE_FAIL=invalid_run_id'
    exit 1
  fi
done

command -v docker >/dev/null
docker info >/dev/null

mapfile -t stage_ids < <(
  docker ps -q \
    --filter label=codex.xboard.stage=true \
    --filter "label=codex.xboard.stage.run=$STAGE_RUN_ID"
)
if ((${#stage_ids[@]} != 1)); then
  echo "RELEASE_PREPARE_FAIL=approved_stage_missing count=${#stage_ids[@]}"
  exit 1
fi
stage=${stage_ids[0]}
RELEASE_IMAGE=$(docker inspect -f '{{.Config.Image}}' "$stage")
if [[ ! "$RELEASE_IMAGE" =~ ^ghcr\.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$ ]]; then
  echo 'RELEASE_PREPARE_FAIL=stage_image_is_not_an_immutable_ghcr_digest'
  exit 1
fi
image_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$RELEASE_IMAGE")
if [[ "$image_revision" != "$RELEASE_SHA" ]]; then
  echo 'RELEASE_PREPARE_FAIL=stage_image_revision_mismatch'
  exit 1
fi
stage_runtime=$(docker exec "$stage" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app()->version();
')
if [[ "$stage_runtime" != 13.* ]] || ! docker exec "$stage" wget -q -O /dev/null http://127.0.0.1:7001/; then
  echo 'RELEASE_PREPARE_FAIL=approved_stage_unhealthy'
  exit 1
fi

mapfile -t candidates < <(
  {
    docker ps --format '{{.ID}} {{.Image}}' | awk 'tolower($2) ~ /xboard/ {print $1}'
    docker ps -q --filter label=com.docker.compose.service=xboard
  } | sort -u
)
production=()
for container_id in "${candidates[@]}"; do
  is_stage=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.stage" }}' "$container_id")
  is_release=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release" }}' "$container_id")
  [[ "$is_stage" == true || "$is_release" == true ]] || production+=("$container_id")
done
if ((${#production[@]} != 1)); then
  echo "RELEASE_PREPARE_FAIL=ambiguous_blue count=${#production[@]}"
  exit 1
fi
blue=${production[0]}
project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$blue")
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$blue")
if [[ -z "$project" || -z "$workdir" || ! -d "$workdir" ]]; then
  echo 'RELEASE_PREPARE_FAIL=invalid_blue_compose_metadata'
  exit 1
fi

if docker ps -aq --filter label=codex.xboard.release=true | grep -q .; then
  echo 'RELEASE_PREPARE_FAIL=another_release_exists'
  exit 1
fi

session_middleware=$(docker exec "$blue" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$reflection = new ReflectionObject($kernel);
$property = $reflection->getProperty("middlewareGroups");
$property->setAccessible(true);
$groups = $property->getValue($kernel);
$enabled = false;
foreach (["web", "api"] as $group) {
    $enabled = $enabled || in_array(Illuminate\Session\Middleware\StartSession::class, $groups[$group] ?? [], true);
}
echo $enabled ? "enabled" : "disabled";
')
if [[ "$session_middleware" != disabled ]]; then
  echo 'RELEASE_PREPARE_FAIL=file_sessions_are_active_and_not_shared'
  exit 1
fi

db_path=$(docker exec "$blue" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config("database.connections.sqlite.database");
')
if [[ "$db_path" != /www/.docker/.data/* ]] || \
   [[ "$(docker exec "$blue" sqlite3 "$db_path" 'PRAGMA journal_mode;')" != wal ]]; then
  echo 'RELEASE_PREPARE_FAIL=sqlite_wal_required'
  exit 1
fi
db_dir=${db_path%/*}

required_mounts=(/www/.env /www/.docker/.data /data /www/storage/theme /www/plugins /www/storage/app/knowledge-attachments)
for destination in "${required_mounts[@]}"; do
  source_path=$(docker inspect -f "{{range .Mounts}}{{if eq .Destination \"$destination\"}}{{.Source}}{{end}}{{end}}" "$blue")
  if [[ -z "$source_path" ]]; then
    echo "RELEASE_PREPARE_FAIL=missing_shared_mount destination=$destination"
    exit 1
  fi
done
if ! docker exec "$blue" redis-cli -s /data/redis.sock ping | grep -q PONG; then
  echo 'RELEASE_PREPARE_FAIL=blue_redis_unreachable'
  exit 1
fi
if ! docker exec "$blue" redis-cli -s /data/redis.sock BGSAVE >/dev/null 2>&1; then
  bgsave_reply=$(docker exec "$blue" redis-cli -s /data/redis.sock BGSAVE 2>&1 || true)
  [[ "$bgsave_reply" == *'Background save already in progress'* ]] || {
    echo 'RELEASE_PREPARE_FAIL=redis_bgsave_start'
    exit 1
  }
fi
for attempt in {1..30}; do
  redis_save_status=$(docker exec "$blue" redis-cli -s /data/redis.sock INFO persistence | tr -d '\r' | sed -n 's/^rdb_last_bgsave_status://p')
  redis_save_active=$(docker exec "$blue" redis-cli -s /data/redis.sock INFO persistence | tr -d '\r' | sed -n 's/^rdb_bgsave_in_progress://p')
  [[ "$redis_save_status" == ok && "$redis_save_active" == 0 ]] && break
  sleep 1
done
if [[ "${redis_save_status:-}" != ok || "${redis_save_active:-}" != 0 ]]; then
  echo 'RELEASE_PREPARE_FAIL=redis_bgsave_incomplete'
  exit 1
fi

release_root="$workdir/.codex-release"
release_dir="$release_root/$RELEASE_ID"
case "$release_dir" in
  "$release_root"/*) ;;
  *) echo 'RELEASE_PREPARE_FAIL=unsafe_release_path'; exit 1 ;;
esac
if [[ -e "$release_dir" ]]; then
  echo 'RELEASE_PREPARE_FAIL=release_directory_exists'
  exit 1
fi
mkdir -p "$release_dir/backups"
chmod 700 "$release_root" "$release_dir" "$release_dir/backups"

snapshot_path="$db_dir/.codex-release-$RELEASE_ID.sqlite"
docker exec -u 0 "$blue" sh -c 'umask 077; : > "$1"' sh "$snapshot_path"
docker exec -u 0 "$blue" sqlite3 "$db_path" ".backup '$snapshot_path'"
if [[ "$(docker exec -u 0 "$blue" sqlite3 "$snapshot_path" 'PRAGMA integrity_check;')" != ok ]]; then
  docker exec -u 0 "$blue" rm -f "$snapshot_path"
  echo 'RELEASE_PREPARE_FAIL=backup_integrity'
  exit 1
fi
docker cp "$blue:$snapshot_path" "$release_dir/backups/database.sqlite" >/dev/null
docker exec -u 0 "$blue" rm -f "$snapshot_path"
chmod 600 "$release_dir/backups/database.sqlite"
backup_sha256=$(sha256sum "$release_dir/backups/database.sqlite" | awk '{print $1}')

state_file="$release_dir/state.env"
blue_image_id=$(docker inspect -f '{{.Image}}' "$blue")
blue_image_name=$(docker inspect -f '{{.Config.Image}}' "$blue")
{
  printf 'RELEASE_ID=%q\n' "$RELEASE_ID"
  printf 'RELEASE_IMAGE=%q\n' "$RELEASE_IMAGE"
  printf 'RELEASE_SHA=%q\n' "$RELEASE_SHA"
  printf 'APPROVED_STAGE_RUN_ID=%q\n' "$STAGE_RUN_ID"
  printf 'PROJECT=%q\n' "$project"
  printf 'WORKDIR=%q\n' "$workdir"
  printf 'BLUE_CONTAINER=%q\n' "$blue"
  printf 'BLUE_IMAGE_ID=%q\n' "$blue_image_id"
  printf 'BLUE_IMAGE_NAME=%q\n' "$blue_image_name"
  printf 'DATABASE_BACKUP=%q\n' "$release_dir/backups/database.sqlite"
  printf 'DATABASE_BACKUP_SHA256=%q\n' "$backup_sha256"
  printf 'PREPARED_AT=%q\n' "$(date -u +%FT%TZ)"
  printf 'TRAFFIC_STATE=%q\n' blue
  printf 'ROLE_STATE=%q\n' blue
} > "$state_file"
chmod 600 "$state_file"

# The approved clone has completed its purpose. Remove only that exact stage
# so the live candidate can bind the already validated loopback port.
stage_dir="$workdir/.codex-stage/$STAGE_RUN_ID"
docker rm -f "$stage" >/dev/null
if [[ -d "$stage_dir" ]]; then
  docker run --rm --entrypoint sh -v "$stage_dir:/stage" "$RELEASE_IMAGE" \
    -c 'find /stage -mindepth 1 -delete' >/dev/null
  rmdir -- "$stage_dir"
fi

green_name="xboard-green-$RELEASE_ID"
green_started=0
cleanup_on_error() {
  status=$?
  if ((status != 0 && green_started == 1)); then
    docker rm -f "$green_name" >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup_on_error EXIT

docker pull "$RELEASE_IMAGE"
docker run -d \
  --name "$green_name" \
  --hostname "$green_name" \
  --label codex.xboard.release=true \
  --label "codex.xboard.release.run=$RELEASE_ID" \
  --label codex.xboard.release.role=web \
  --restart no \
  --memory 768m \
  --cpus 2 \
  -p 127.0.0.1:7002:7001 \
  --volumes-from "$blue" \
  -e SKIP_XBOARD_UPDATE=true \
  -e "RUNTIME_INSTANCE_ID=green-$RELEASE_ID" \
  -e RESOURCE_PROFILE=minimal \
  -e ENABLE_HORIZON=false \
  -e ENABLE_REDIS=false \
  -e ENABLE_SCHEDULER=false \
  -e ENABLE_WS_SERVER=true \
  -e ENABLE_CADDY=true \
  -e SESSION_SERIALIZATION=php \
  "$RELEASE_IMAGE" >/dev/null
green_started=1

healthy=0
for attempt in {1..45}; do
  if docker exec "$green_name" wget -q -O /dev/null http://127.0.0.1:7001/; then
    healthy=1
    break
  fi
  sleep 2
done
if ((healthy != 1)); then
  docker logs --tail 150 "$green_name" >&2 || true
  echo 'RELEASE_PREPARE_FAIL=green_http_health'
  exit 1
fi

for destination in "${required_mounts[@]}"; do
  blue_source=$(docker inspect -f "{{range .Mounts}}{{if eq .Destination \"$destination\"}}{{.Source}}{{end}}{{end}}" "$blue")
  green_source=$(docker inspect -f "{{range .Mounts}}{{if eq .Destination \"$destination\"}}{{.Source}}{{end}}{{end}}" "$green_name")
  if [[ "$blue_source" != "$green_source" ]]; then
    echo "RELEASE_PREPARE_FAIL=shared_mount_mismatch destination=$destination"
    exit 1
  fi
done

runtime=$(docker exec "$green_name" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$redis = app("redis")->connection()->ping();
echo json_encode([
    "php" => PHP_VERSION,
    "laravel" => app()->version(),
    "scheduler" => config("app.scheduler_enabled"),
    "redis" => $redis === true || $redis === "+PONG" || $redis === "PONG",
    "db" => config("database.default"),
], JSON_UNESCAPED_SLASHES);
')
if [[ "$runtime" != *'"laravel":"13.'* || "$runtime" != *'"scheduler":false'* || "$runtime" != *'"redis":true'* ]]; then
  echo "RELEASE_PREPARE_FAIL=green_runtime $runtime"
  exit 1
fi
continuity_script='
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode([
    "app_key_sha256" => hash("sha256", (string) config("app.key")),
    "app_url" => config("app.url"),
    "database" => config("database.connections.sqlite.database"),
    "redis_host" => config("database.redis.default.host"),
    "redis_prefix" => config("database.redis.options.prefix"),
    "cache_prefix" => config("cache.prefix"),
    "session_cookie" => config("session.cookie"),
    "sanctum_expiration" => config("sanctum.expiration"),
    "sanctum_token_prefix" => config("sanctum.token_prefix"),
    "enabled_plugins" => App\Models\Plugin::query()->where("is_enabled", true)->orderBy("code")->pluck("code")->all(),
], JSON_UNESCAPED_SLASHES);
'
blue_continuity=$(docker exec "$blue" php -r "$continuity_script")
green_continuity=$(docker exec "$green_name" php -r "$continuity_script")
if [[ "$blue_continuity" != "$green_continuity" ]]; then
  echo 'RELEASE_PREPARE_FAIL=configuration_or_plugin_continuity'
  exit 1
fi
if docker exec "$green_name" php /www/artisan migrate:status --no-interaction | grep -q Pending; then
  echo 'RELEASE_PREPARE_FAIL=pending_migrations'
  exit 1
fi
if [[ "$(docker exec "$green_name" sqlite3 "$db_path" 'PRAGMA integrity_check;')" != ok ]]; then
  echo 'RELEASE_PREPARE_FAIL=live_database_integrity'
  exit 1
fi
# supervisorctl intentionally returns a non-zero status for STOPPED programs;
# capture its text and assert the exact desired state below instead of letting
# errexit mistake a disabled state owner for a script failure.
redis_supervisor_state=$(docker exec "$green_name" supervisorctl status redis 2>&1 || true)
horizon_supervisor_state=$(docker exec "$green_name" supervisorctl status horizon 2>&1 || true)
if [[ "$redis_supervisor_state" != *STOPPED* || "$horizon_supervisor_state" != *STOPPED* ]] || \
   docker exec "$green_name" sh -c 'pgrep redis-server >/dev/null || pgrep -f "artisan horizon" >/dev/null'; then
  echo 'RELEASE_PREPARE_FAIL=duplicate_state_owner'
  exit 1
fi

printf 'GREEN_CONTAINER=%q\n' "$green_name" >> "$state_file"
printf 'GREEN_RUNTIME=%q\n' "$runtime" >> "$state_file"
printf 'CONTINUITY_SHA256=%q\n' "$(printf '%s' "$green_continuity" | sha256sum | awk '{print $1}')" >> "$state_file"
trap - EXIT
echo "RELEASE_PREPARE=PASS id=$RELEASE_ID image=$RELEASE_IMAGE blue=$blue green=$green_name"
echo "RELEASE_BACKUP=PASS sha256=$backup_sha256"
echo "RELEASE_RUNTIME=$runtime"
