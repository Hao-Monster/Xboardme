#!/usr/bin/env bash
set -Eeuo pipefail

command -v docker >/dev/null
command -v caddy >/dev/null
docker info >/dev/null

mapfile -t compose_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#compose_ids[@]} != 1)); then
  echo "PREFLIGHT_FAIL=ambiguous_compose_base count=${#compose_ids[@]}"
  exit 1
fi
compose_base=${compose_ids[0]}
project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$compose_base")
workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$compose_base")
if [[ -z "$project" || -z "$workdir" || ! -d "$workdir" ]]; then
  echo 'PREFLIGHT_FAIL=invalid_compose_metadata'
  exit 1
fi

mapfile -t proxy_files < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' /etc/caddy 2>/dev/null || true
)
if ((${#proxy_files[@]} != 1)); then
  echo "PREFLIGHT_FAIL=ambiguous_caddy_file count=${#proxy_files[@]}"
  exit 1
fi
proxy_file=${proxy_files[0]}
mapfile -t active_upstreams < <(
  grep -Eo 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "$proxy_file" |
    awk '{print $2}' | sort -u
)
if ((${#active_upstreams[@]} != 1)); then
  echo "PREFLIGHT_FAIL=ambiguous_caddy_upstream count=${#active_upstreams[@]}"
  exit 1
fi
active_upstream=${active_upstreams[0]}
active_port=${active_upstream##*:}

mapfile -t web_candidates < <(
  {
    docker ps -q --filter label=com.docker.compose.service=xboard
    docker ps -q --filter label=codex.xboard.release=true --filter label=codex.xboard.release.role=web
  } | sort -u
)
active_web=()
for container_id in "${web_candidates[@]}"; do
  if docker inspect -f '{{range $bindings := .NetworkSettings.Ports}}{{range $bindings}}{{println .HostPort}}{{end}}{{end}}' "$container_id" |
      grep -qx "$active_port"; then
    active_web+=("$container_id")
  fi
done
if ((${#active_web[@]} != 1)); then
  echo "PREFLIGHT_FAIL=ambiguous_active_web port=$active_port count=${#active_web[@]}"
  exit 1
fi
primary=${active_web[0]}
primary_name=$(docker inspect -f '{{.Name}}' "$primary" | sed 's#^/##')
active_release_id=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release.run" }}' "$primary")
if [[ "$active_release_id" == '<no value>' ]]; then
  active_release_id=''
fi

architecture=$(uname -m)
available_kib=$(df -Pk "$workdir" | awk 'NR==2 {print $4}')
memory_available_kib=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
cpu_count=$(getconf _NPROCESSORS_ONLN)
load_average=$(awk '{print $1","$2","$3}' /proc/loadavg)
current_image=$(docker inspect -f '{{.Config.Image}}' "$primary")
current_image_id=$(docker inspect -f '{{.Image}}' "$primary")
current_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$current_image_id")
if [[ "$current_revision" == '<no value>' ]]; then
  current_revision=''
fi
restart_policy=$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$primary")
published_ports=$(docker inspect -f '{{json .NetworkSettings.Ports}}' "$primary")
mount_destinations=$(docker inspect -f '{{range .Mounts}}{{printf "%s:%s " .Type .Destination}}{{end}}' "$primary")

runtime_json=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config("database.default");
$plugins = App\Models\Plugin::query()->orderBy("code")->get(["code", "version", "is_enabled"])->toArray();
$pluginManager = app(App\Services\Plugin\PluginManager::class);
$pluginSources = [];
foreach ($plugins as $plugin) {
    $path = $pluginManager->resolvePluginPath((string) $plugin["code"]);
    $pluginSources[$plugin["code"]] = $path === null
        ? "missing"
        : (str_starts_with($path, base_path("plugins-core")) ? "core" : "user");
}
echo json_encode([
    "php" => PHP_VERSION,
    "laravel" => app()->version(),
    "db_driver" => $db,
    "cache_store" => config("cache.default"),
    "queue_connection" => config("queue.default"),
    "session_driver" => config("session.driver"),
    "session_serialization" => config("session.serialization"),
    "app_key_configured" => is_string(config("app.key")) && config("app.key") !== "",
    "counts" => [
        "users" => App\Models\User::query()->count(),
        "orders" => App\Models\Order::query()->count(),
        "tokens" => Laravel\Sanctum\PersonalAccessToken::query()->count(),
        "plugins" => count($plugins),
    ],
    "plugins" => $plugins,
    "plugin_sources" => $pluginSources,
], JSON_UNESCAPED_SLASHES);
')

db_path=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = (string) config("database.default");
echo (string) config("database.connections.".$connection.".database", "");
')
db_persistent=false
while IFS= read -r mount_destination; do
  if [[ -n "$mount_destination" && ("$db_path" == "$mount_destination" || "$db_path" == "$mount_destination/"*) ]]; then
    db_persistent=true
    break
  fi
done < <(docker inspect -f '{{range .Mounts}}{{println .Destination}}{{end}}' "$primary")

sqlite_journal_mode=not_applicable
sqlite_integrity=not_applicable
if [[ "$runtime_json" == *'"db_driver":"sqlite"'* ]]; then
  sqlite_journal_mode=$(docker exec "$primary" sqlite3 "$db_path" 'PRAGMA journal_mode;')
  sqlite_integrity=$(docker exec "$primary" sqlite3 "$db_path" 'PRAGMA integrity_check;')
fi
redis_version=$(docker exec "$primary" sh -lc \
  'redis-cli -s /data/redis.sock INFO server 2>/dev/null | sed -n "s/^redis_version:\(.*\)\r$/\1/p"' || true)
redis_persistence=$(docker exec "$primary" sh -lc \
  'redis-cli -s /data/redis.sock INFO persistence 2>/dev/null | sed -n "s/^rdb_last_bgsave_status:\(.*\)\r$/\1/p"' || true)
redis_ping=$(docker exec "$primary" redis-cli -s /data/redis.sock ping 2>/dev/null || true)

plugin_user_php_files=$(docker exec "$primary" sh -lc 'find /www/plugins -type f -name "*.php" 2>/dev/null | wc -l')
plugin_core_php_files=$(docker exec "$primary" sh -lc 'find /www/plugins-core -type f -name "*.php" 2>/dev/null | wc -l')
plugin_syntax=pass
if ! docker exec "$primary" sh -lc 'find /www/plugins /www/plugins-core -type f -name "*.php" -exec php -l {} \; >/dev/null'; then
  plugin_syntax=fail
fi

active_health=fail
if docker exec "$primary" wget -q -O /dev/null http://127.0.0.1:7001/; then
  active_health=pass
fi
caddy_health=fail
if systemctl is-active --quiet caddy 2>/dev/null && caddy validate --config "$proxy_file" --adapter caddyfile >/dev/null; then
  caddy_health=pass
fi
pending_migrations=$(docker exec "$primary" php /www/artisan migrate:status --pending --no-ansi --no-interaction 2>/dev/null |
  grep -c 'Pending' || true)
backup_command=$(docker exec "$primary" php /www/artisan list --raw | grep -c '^backup:database' || true)
current_container_cpu=$(docker stats --no-stream --format '{{.CPUPerc}}' "$primary")
current_container_memory=$(docker stats --no-stream --format '{{.MemUsage}}' "$primary")

release_web_count=$(docker ps -q --filter label=codex.xboard.release=true --filter label=codex.xboard.release.role=web | wc -l)
release_horizon_count=$(docker ps -q --filter label=codex.xboard.release=true --filter label=codex.xboard.release.role=horizon | wc -l)
release_scheduler_count=$(docker ps -q --filter label=codex.xboard.release=true --filter label=codex.xboard.release.role=scheduler | wc -l)
candidate_7003_listeners=unknown
if command -v ss >/dev/null 2>&1; then
  candidate_7003_listeners=$(ss -H -lnt '( sport = :7003 )' 2>/dev/null | wc -l)
fi

echo "PREFLIGHT_ARCHITECTURE=$architecture"
echo "PREFLIGHT_PROJECT=$project"
echo "PREFLIGHT_ACTIVE_CONTAINER=$primary_name"
echo "PREFLIGHT_ACTIVE_RELEASE_ID=${active_release_id:-compose}"
echo "PREFLIGHT_ACTIVE_UPSTREAM=$active_upstream"
echo "PREFLIGHT_ACTIVE_IMAGE=$current_image"
echo "PREFLIGHT_ACTIVE_IMAGE_REVISION=${current_revision:-unknown}"
echo "PREFLIGHT_ACTIVE_RESTART_POLICY=${restart_policy:-none}"
echo "PREFLIGHT_ACTIVE_PORTS=$published_ports"
echo "PREFLIGHT_ACTIVE_MOUNTS=$mount_destinations"
echo "PREFLIGHT_RUNTIME=$runtime_json"
echo "PREFLIGHT_DB_PATH=$db_path"
echo "PREFLIGHT_DB_PERSISTENT=$db_persistent"
echo "PREFLIGHT_SQLITE_JOURNAL_MODE=$sqlite_journal_mode"
echo "PREFLIGHT_SQLITE_INTEGRITY=$sqlite_integrity"
echo "PREFLIGHT_REDIS_VERSION=${redis_version:-unavailable}"
echo "PREFLIGHT_REDIS_LAST_BGSAVE=${redis_persistence:-unavailable}"
echo "PREFLIGHT_PLUGIN_CORE_PHP_FILES=$plugin_core_php_files"
echo "PREFLIGHT_PLUGIN_USER_PHP_FILES=$plugin_user_php_files"
echo "PREFLIGHT_PLUGIN_SYNTAX=$plugin_syntax"
echo "PREFLIGHT_RELEASE_WEBS=$release_web_count"
echo "PREFLIGHT_RELEASE_HORIZONS=$release_horizon_count"
echo "PREFLIGHT_RELEASE_SCHEDULERS=$release_scheduler_count"
echo "PREFLIGHT_CANDIDATE_7003_LISTENERS=$candidate_7003_listeners"
echo "PREFLIGHT_AVAILABLE_KIB=$available_kib"
echo "PREFLIGHT_MEMORY_AVAILABLE_KIB=$memory_available_kib"
echo "PREFLIGHT_CPU_COUNT=$cpu_count"
echo "PREFLIGHT_LOAD_AVERAGE=$load_average"
echo "PREFLIGHT_ACTIVE_CONTAINER_CPU=$current_container_cpu"
echo "PREFLIGHT_ACTIVE_CONTAINER_MEMORY=$current_container_memory"
echo "PREFLIGHT_PENDING_MIGRATIONS=$pending_migrations"
echo "PREFLIGHT_BACKUP_COMMANDS=$backup_command"

failures=0
if ((available_kib < 2097152)); then
  echo 'PREFLIGHT_FAIL=less_than_2GiB_free'
  failures=1
fi
if ((memory_available_kib < 1048576)); then
  echo 'PREFLIGHT_FAIL=less_than_1GiB_memory_available'
  failures=1
fi
if [[ "$active_health" != pass ]]; then
  echo 'PREFLIGHT_FAIL=active_web_unhealthy'
  failures=1
fi
if [[ "$caddy_health" != pass ]]; then
  echo 'PREFLIGHT_FAIL=caddy_unhealthy'
  failures=1
fi
if [[ "$runtime_json" != *'"laravel":"13.'* ]]; then
  echo 'PREFLIGHT_FAIL=active_runtime_is_not_laravel_13'
  failures=1
fi
if [[ "$db_persistent" != true || "$sqlite_journal_mode" != wal || "$sqlite_integrity" != ok ]]; then
  echo 'PREFLIGHT_FAIL=sqlite_persistence_wal_or_integrity'
  failures=1
fi
if [[ "$redis_ping" != PONG || "$redis_persistence" != ok ]]; then
  echo 'PREFLIGHT_FAIL=redis_unhealthy_or_not_persisted'
  failures=1
fi
if [[ "$plugin_syntax" != pass ]]; then
  echo 'PREFLIGHT_FAIL=production_plugin_php_syntax'
  failures=1
fi
if ((pending_migrations != 0)); then
  echo 'PREFLIGHT_FAIL=active_runtime_has_pending_migrations'
  failures=1
fi
if ((backup_command != 1)); then
  echo 'PREFLIGHT_FAIL=database_backup_command_unavailable'
  failures=1
fi
if [[ -n "$active_release_id" ]] &&
   ((release_horizon_count != 1 || release_scheduler_count != 1)); then
  echo 'PREFLIGHT_FAIL=release_role_ownership_is_not_unique'
  failures=1
fi
if ((failures != 0)); then
  exit 1
fi

if [[ "$restart_policy" != always && "$restart_policy" != unless-stopped ]]; then
  echo 'PREFLIGHT_WARN=active_web_restart_policy_will_be_repaired_by_next_release'
fi
echo 'PREFLIGHT_BASELINE=PASS'
