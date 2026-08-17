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
], JSON_UNESCAPED_SLASHES);
')

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
echo "PREFLIGHT_CURRENT_IMAGE=$current_image"
echo "PREFLIGHT_RESTART_POLICY=$restart_policy"
echo "PREFLIGHT_PUBLISHED_PORTS=$published_ports"
echo "PREFLIGHT_MOUNTS=$mount_destinations"
echo "PREFLIGHT_RUNTIME=$runtime_json"
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
if [[ "$restart_policy" != always && "$restart_policy" != unless-stopped ]]; then
  echo 'PREFLIGHT_FAIL=unsafe_restart_policy'
  failures=1
fi

if ((failures != 0)); then
  exit 1
fi

echo 'PREFLIGHT_BASELINE=PASS'
