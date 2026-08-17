#!/usr/bin/env bash
set -Eeuo pipefail

command -v docker >/dev/null
docker info >/dev/null

mapfile -t candidates < <(
  docker ps --format '{{.ID}} {{.Image}}' |
    awk 'tolower($2) ~ /xboard/ {print $1}'
)
if ((${#candidates[@]} == 0)); then
  mapfile -t candidates < <(
    docker ps -q --filter label=com.docker.compose.service=xboard
  )
fi
if ((${#candidates[@]} == 0)); then
  echo 'PREFLIGHT_FAIL=no_running_xboard_container'
  exit 1
fi

declare -A projects=()
for container_id in "${candidates[@]}"; do
  project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id")
  workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$container_id")
  if [[ -n "$project" && -n "$workdir" ]]; then
    projects["$project|$workdir"]=1
  fi
done
if ((${#projects[@]} != 1)); then
  echo "PREFLIGHT_FAIL=ambiguous_compose_projects count=${#projects[@]}"
  exit 1
fi

project_key=${!projects[@]}
project=${project_key%%|*}
workdir=${project_key#*|}
primary=${candidates[0]}

mapfile -t project_containers < <(
  docker ps -q --filter "label=com.docker.compose.project=$project"
)
project_xboard=()
web_instances=0
service_inventory=()
for container_id in "${project_containers[@]}"; do
  image=$(docker inspect -f '{{.Config.Image}}' "$container_id")
  service=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.service" }}' "$container_id")
  if [[ "${image,,}" == *xboard* ]]; then
    project_xboard+=("$container_id")
    service_inventory+=("$service")
    if [[ "$service" == xboard || "$service" == web ]]; then
      ((web_instances += 1))
    fi
  fi
done

architecture=$(uname -m)
available_kib=$(df -Pk "$workdir" | awk 'NR==2 {print $4}')
current_image=$(docker inspect -f '{{.Config.Image}}' "$primary")
restart_policy=$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$primary")
published_ports=$(docker inspect -f '{{json .NetworkSettings.Ports}}' "$primary")
mount_destinations=$(docker inspect -f '{{range .Mounts}}{{printf "%s:%s " .Type .Destination}}{{end}}' "$primary")

runtime_json=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config("database.default");
$dbConfig = (array) config("database.connections.".$db, []);
$redisHost = (string) config("database.redis.default.host", "");
$plugins = App\Models\Plugin::query()->orderBy("code")->get(["code", "version", "is_enabled"])->toArray();
$pluginManager = app(App\Services\Plugin\PluginManager::class);
$pluginSources = [];
foreach ($plugins as $plugin) {
    $path = $pluginManager->resolvePluginPath((string) $plugin["code"]);
    $pluginSources[$plugin["code"]] = $path === null
        ? "missing"
        : (str_starts_with($path, base_path("plugins-core")) ? "core" : "user");
}
$counts = [
    "users" => App\Models\User::query()->count(),
    "orders" => App\Models\Order::query()->count(),
    "tokens" => Laravel\Sanctum\PersonalAccessToken::query()->count(),
    "plugins" => count($plugins),
];
echo json_encode([
    "php" => PHP_VERSION,
    "laravel" => app()->version(),
    "db_driver" => $db,
    "db_external" => !in_array((string) ($dbConfig["host"] ?? ""), ["", "127.0.0.1", "localhost"], true),
    "redis_external" => !in_array($redisHost, ["", "127.0.0.1", "localhost", "/data/redis.sock"], true),
    "cache_store" => config("cache.default"),
    "queue_connection" => config("queue.default"),
    "session_driver" => config("session.driver"),
    "session_serialization" => config("session.serialization"),
    "app_key_configured" => is_string(config("app.key")) && config("app.key") !== "",
    "counts" => $counts,
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
  if [[ -n "$mount_destination" &&
        ("$db_path" == "$mount_destination" || "$db_path" == "$mount_destination/"*) ]]; then
    db_persistent=true
    break
  fi
done < <(docker inspect -f '{{range .Mounts}}{{println .Destination}}{{end}}' "$primary")

sqlite_journal_mode=not_applicable
if [[ "$runtime_json" == *'"db_driver":"sqlite"'* ]]; then
  sqlite_journal_mode=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) Illuminate\Support\Facades\DB::selectOne("PRAGMA journal_mode")->journal_mode;
')
fi

redis_version=$(docker exec "$primary" sh -lc \
  'redis-cli -s /data/redis.sock INFO server 2>/dev/null | sed -n "s/^redis_version:\\(.*\\)\\r$/\\1/p"' || true)
redis_persistence=$(docker exec "$primary" sh -lc \
  'redis-cli -s /data/redis.sock INFO persistence 2>/dev/null | sed -n "s/^rdb_last_bgsave_status:\\(.*\\)\\r$/\\1/p"' || true)

plugin_user_php_files=$(docker exec "$primary" sh -lc \
  'find /www/plugins -type f -name "*.php" 2>/dev/null | wc -l')
plugin_core_php_files=$(docker exec "$primary" sh -lc \
  'find /www/plugins-core -type f -name "*.php" 2>/dev/null | wc -l')
plugin_php_files=$((plugin_user_php_files + plugin_core_php_files))
plugin_syntax=pass
if ! docker exec "$primary" sh -lc \
  'find /www/plugins /www/plugins-core -type f -name "*.php" -exec php -l {} \; >/dev/null'; then
  plugin_syntax=fail
fi

cpu_count=$(getconf _NPROCESSORS_ONLN)
memory_available_kib=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
load_average=$(awk '{print $1","$2","$3}' /proc/loadavg)
port_7002_listeners=unknown
if command -v ss >/dev/null 2>&1; then
  port_7002_listeners=$(ss -H -lnt '( sport = :7002 )' 2>/dev/null | wc -l)
fi
proxy_processes=$(ps -eo comm= | awk '
  /^(nginx|openresty|caddy|cloudflared)$/ {seen[$1]=1}
  END {for (process in seen) printf "%s ", process}
')
active_proxy_units=()
if command -v systemctl >/dev/null 2>&1; then
  for proxy_unit in nginx openresty caddy cloudflared; do
    if systemctl is-active --quiet "$proxy_unit" 2>/dev/null; then
      active_proxy_units+=("$proxy_unit")
    fi
  done
fi
frontend_images=$(docker ps --format '{{.Image}}|{{.Ports}}' | awk -F'|' \
  '$2 ~ /(^|[, ])(0\.0\.0\.0:|\[::\]:)?(80|443)->/ {print $1}' | sort -u | tr '\n' ' ')
port_80_443_listeners=unknown
if command -v ss >/dev/null 2>&1; then
  port_80_443_listeners=$(ss -H -lnt 2>/dev/null | awk '$4 ~ /:(80|443)$/ {count++} END {print count+0}')
fi
current_container_cpu=$(docker stats --no-stream --format '{{.CPUPerc}}' "$primary")
current_container_memory=$(docker stats --no-stream --format '{{.MemUsage}}' "$primary")
proxy_reference_count=0
for proxy_root in /etc/caddy /etc/nginx /www/server/panel/vhost/nginx /opt/1panel/apps/openresty/openresty/conf; do
  if [[ -d "$proxy_root" ]]; then
    count=$( (grep -RIl --include='*.conf' -- '127.0.0.1:7001' "$proxy_root" 2>/dev/null || true) | wc -l )
    ((proxy_reference_count += count))
  fi
done

pending_migrations=$(docker exec "$primary" php /www/artisan migrate:status --no-interaction |
  grep -c 'Pending' || true)
backup_command=$(docker exec "$primary" php /www/artisan list --raw |
  grep -c '^backup:database' || true)

echo "PREFLIGHT_ARCHITECTURE=$architecture"
echo "PREFLIGHT_PROJECT=$project"
echo "PREFLIGHT_XBOARD_CONTAINERS=${#project_xboard[@]}"
echo "PREFLIGHT_WEB_INSTANCES=$web_instances"
echo "PREFLIGHT_XBOARD_SERVICES=${service_inventory[*]}"
echo "PREFLIGHT_AVAILABLE_KIB=$available_kib"
echo "PREFLIGHT_CPU_COUNT=$cpu_count"
echo "PREFLIGHT_MEMORY_AVAILABLE_KIB=$memory_available_kib"
echo "PREFLIGHT_LOAD_AVERAGE=$load_average"
echo "PREFLIGHT_CURRENT_IMAGE=$current_image"
echo "PREFLIGHT_RESTART_POLICY=$restart_policy"
echo "PREFLIGHT_PUBLISHED_PORTS=$published_ports"
echo "PREFLIGHT_MOUNTS=$mount_destinations"
echo "PREFLIGHT_RUNTIME=$runtime_json"
echo "PREFLIGHT_DB_PATH=$db_path"
echo "PREFLIGHT_DB_PERSISTENT=$db_persistent"
echo "PREFLIGHT_SQLITE_JOURNAL_MODE=$sqlite_journal_mode"
echo "PREFLIGHT_REDIS_VERSION=${redis_version:-unavailable}"
echo "PREFLIGHT_REDIS_LAST_BGSAVE=${redis_persistence:-unavailable}"
echo "PREFLIGHT_PLUGIN_PHP_FILES=$plugin_php_files"
echo "PREFLIGHT_PLUGIN_CORE_PHP_FILES=$plugin_core_php_files"
echo "PREFLIGHT_PLUGIN_USER_PHP_FILES=$plugin_user_php_files"
echo "PREFLIGHT_PLUGIN_SYNTAX=$plugin_syntax"
echo "PREFLIGHT_PORT_7002_LISTENERS=$port_7002_listeners"
echo "PREFLIGHT_PORT_80_443_LISTENERS=$port_80_443_listeners"
echo "PREFLIGHT_PROXY_PROCESSES=${proxy_processes:-none}"
echo "PREFLIGHT_ACTIVE_PROXY_UNITS=${active_proxy_units[*]:-none}"
echo "PREFLIGHT_FRONTEND_IMAGES=${frontend_images:-none}"
echo "PREFLIGHT_PROXY_REFERENCE_COUNT=$proxy_reference_count"
echo "PREFLIGHT_CURRENT_CONTAINER_CPU=$current_container_cpu"
echo "PREFLIGHT_CURRENT_CONTAINER_MEMORY=$current_container_memory"
echo "PREFLIGHT_PENDING_MIGRATIONS=$pending_migrations"
echo "PREFLIGHT_BACKUP_COMMANDS=$backup_command"

failures=0
if ((available_kib < 2097152)); then
  echo 'PREFLIGHT_FAIL=less_than_2GiB_free'
  failures=1
fi
if ((pending_migrations != 0)); then
  echo 'PREFLIGHT_FAIL=pending_migrations'
  failures=1
fi
if ((backup_command != 1)); then
  echo 'PREFLIGHT_FAIL=database_backup_command_unavailable'
  failures=1
fi
if ((web_instances < 2)); then
  echo 'PREFLIGHT_FAIL=zero_downtime_requires_two_web_instances'
  failures=1
fi
if [[ "$runtime_json" != *'"db_external":true'* ]]; then
  echo 'PREFLIGHT_FAIL=zero_downtime_requires_external_database'
  failures=1
fi
if [[ "$runtime_json" != *'"redis_external":true'* ]]; then
  echo 'PREFLIGHT_FAIL=zero_downtime_requires_external_redis'
  failures=1
fi
if [[ "$db_persistent" != true ]]; then
  echo 'PREFLIGHT_FAIL=database_not_persisted_outside_container'
  failures=1
fi
if [[ "$plugin_syntax" != pass ]]; then
  echo 'PREFLIGHT_FAIL=production_plugin_php_syntax'
  failures=1
fi
if [[ "$restart_policy" != always && "$restart_policy" != unless-stopped ]]; then
  echo 'PREFLIGHT_FAIL=unsafe_restart_policy'
  failures=1
fi

if ((failures != 0)); then
  exit 1
fi

echo 'PREFLIGHT_BASELINE=PASS'
