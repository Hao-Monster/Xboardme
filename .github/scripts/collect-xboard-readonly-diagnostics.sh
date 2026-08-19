#!/usr/bin/env bash
set -Eeuo pipefail

: "${DIAG_START_UTC:?DIAG_START_UTC is required}"
: "${DIAG_END_UTC:?DIAG_END_UTC is required}"
: "${DIAG_JOB_ID:?DIAG_JOB_ID is required}"

timestamp_pattern='^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$'
job_id_pattern='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
[[ "$DIAG_START_UTC" =~ $timestamp_pattern ]]
[[ "$DIAG_END_UTC" =~ $timestamp_pattern ]]
[[ "$DIAG_JOB_ID" =~ $job_id_pattern ]]

start_epoch=$(date -u -d "$DIAG_START_UTC" +%s)
end_epoch=$(date -u -d "$DIAG_END_UTC" +%s)
now_epoch=$(date -u +%s)
((start_epoch < end_epoch))
((end_epoch - start_epoch <= 21600))
((end_epoch <= now_epoch + 300))

command -v caddy >/dev/null
command -v docker >/dev/null
docker info >/dev/null

mapfile -t proxy_files < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' /etc/caddy 2>/dev/null || true
)
if ((${#proxy_files[@]} != 1)); then
  echo "DIAGNOSTICS_FAIL=ambiguous_caddy_file count=${#proxy_files[@]}" >&2
  exit 1
fi

mapfile -t active_upstreams < <(
  grep -Eo 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "${proxy_files[0]}" |
    awk '{print $2}' | sort -u
)
if ((${#active_upstreams[@]} != 1)); then
  echo "DIAGNOSTICS_FAIL=ambiguous_caddy_upstream count=${#active_upstreams[@]}" >&2
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
  echo "DIAGNOSTICS_FAIL=ambiguous_active_web port=$active_port count=${#active_web[@]}" >&2
  exit 1
fi
primary=${active_web[0]}
active_release_id=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release.run" }}' "$primary")
[[ "$active_release_id" != '<no value>' ]] || active_release_id=''

find_release_role() {
  local role=$1
  local container_id candidate_release
  local -a matches=()
  while IFS= read -r container_id; do
    [[ -n "$container_id" ]] || continue
    candidate_release=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release.run" }}' "$container_id")
    if [[ -z "$active_release_id" || "$candidate_release" == "$active_release_id" ]]; then
      matches+=("$container_id")
    fi
  done < <(docker ps -q --filter label=codex.xboard.release=true --filter "label=codex.xboard.release.role=$role")

  if ((${#matches[@]} == 1)); then
    printf '%s' "${matches[0]}"
  fi
}

horizon_container=$(find_release_role horizon)
scheduler_container=$(find_release_role scheduler)
[[ -n "$horizon_container" ]] || horizon_container=$primary
[[ -n "$scheduler_container" ]] || scheduler_container=$primary

app_timezone=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) config("app.timezone", "UTC");
' 2>/dev/null || true)
if [[ ! "$app_timezone" =~ ^[A-Za-z0-9_+./-]+$ ]]; then
  app_timezone=UTC
fi
start_local=$(TZ="$app_timezone" date -d "@$start_epoch" '+%F %T')
end_local=$(TZ="$app_timezone" date -d "@$end_epoch" '+%F %T')

sanitize() {
  LC_ALL=C sed -E \
    -e $'s/\x1B\[[0-9;]*[[:alpha:]]//g' \
    -e 's/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/\1***@\2/g' \
    -e 's/(([0-9]{1,3}\.){3}[0-9]{1,3})/[REDACTED_IP]/g' \
    -e 's/([?&](token|key|password|auth|email)=)[^&[:space:]]+/\1[REDACTED_SECRET]/Ig' \
    -e 's/(Authorization:|Cookie:|Set-Cookie:)[[:space:]]*.*/\1 [REDACTED_SECRET]/Ig' \
    -e 's/(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+\/-]+=*/\1 [REDACTED_SECRET]/Ig' \
    -e 's/((password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["]?[[:space:]]*[:=][[:space:]]*)[^,;[:space:]]+/\1[REDACTED_SECRET]/Ig' \
    -e 's/(StatUserJob failed for user)[[:space:]]+[0-9]+/\1 [REDACTED_ID]/g' \
    -e 's/(["]?(user_id|uid)["]?[[:space:]]*(=|:)[[:space:]]*)[0-9]+/\1[REDACTED_ID]/Ig' \
    -e 's/[A-Za-z0-9+_\/-]{80,}={0,2}/[REDACTED_LONG_VALUE]/g'
}

collect_diagnostics() {
  primary_name=$(docker inspect -f '{{.Name}}' "$primary" | sed 's#^/##')
  horizon_name=$(docker inspect -f '{{.Name}}' "$horizon_container" | sed 's#^/##')
  scheduler_name=$(docker inspect -f '{{.Name}}' "$scheduler_container" | sed 's#^/##')

  echo '=== DIAGNOSTIC SCOPE ==='
  echo "GENERATED_AT_UTC=$(date -u +%FT%TZ)"
  echo "START_UTC=$DIAG_START_UTC"
  echo "END_UTC=$DIAG_END_UTC"
  echo "APP_TIMEZONE=$app_timezone"
  echo "START_LOCAL=$start_local"
  echo "END_LOCAL=$end_local"
  echo "TARGET_JOB_ID=$DIAG_JOB_ID"

  echo '=== ACTIVE RUNTIME ==='
  echo "ACTIVE_UPSTREAM=$active_upstream"
  echo "ACTIVE_WEB=$primary_name"
  echo "ACTIVE_HORIZON=$horizon_name"
  echo "ACTIVE_SCHEDULER=$scheduler_name"
  echo "ACTIVE_RELEASE_ID=${active_release_id:-compose}"
  for container_id in "$primary" "$horizon_container" "$scheduler_container"; do
    docker inspect -f 'container={{.Name}} image={{.Config.Image}} image_id={{.Image}} started={{.State.StartedAt}} status={{.State.Status}} restarts={{.RestartCount}}' "$container_id"
  done
  docker stats --no-stream --format 'container={{.Name}} cpu={{.CPUPerc}} memory={{.MemUsage}} pids={{.PIDs}}' \
    "$primary" "$horizon_container" "$scheduler_container" || true

  echo '=== HORIZON PROCESS STATE ==='
  docker exec "$horizon_container" supervisorctl status 2>&1 || true
  docker exec "$horizon_container" php /www/artisan horizon:status --no-ansi 2>&1 || true

  echo '=== QUEUE AND FAILED JOB SNAPSHOT ==='
  docker exec -i \
    -e DIAG_START_EPOCH="$start_epoch" \
    -e DIAG_END_EPOCH="$end_epoch" \
    -e DIAG_JOB_ID="$DIAG_JOB_ID" \
    "$primary" php <<'PHP'
<?php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$start = (float) getenv('DIAG_START_EPOCH');
$end = (float) getenv('DIAG_END_EPOCH');
$targetId = (string) getenv('DIAG_JOB_ID');
$queue = app('queue')->connection('redis');
$supervisors = (array) config('horizon.environments.production', []);
$queueNames = [];
foreach ($supervisors as $supervisor) {
    foreach ((array) ($supervisor['queue'] ?? []) as $name) {
        $queueNames[(string) $name] = true;
    }
}
ksort($queueNames);
$queueState = [];
foreach (array_keys($queueNames) as $name) {
    try {
        $queueState[$name] = [
            'ready' => $queue->size($name),
            'reserved' => method_exists($queue, 'reservedSize') ? $queue->reservedSize($name) : null,
            'delayed' => method_exists($queue, 'delayedSize') ? $queue->delayedSize($name) : null,
        ];
    } catch (Throwable $exception) {
        $queueState[$name] = ['error' => get_class($exception)];
    }
}

$repository = app(Laravel\Horizon\Contracts\JobRepository::class);
$groups = [];
$examples = [];
$windowCount = 0;
$scanned = 0;
$cursor = null;
do {
    $batch = $repository->getFailed($cursor);
    if ($batch->isEmpty()) {
        break;
    }
    foreach ($batch as $job) {
        $scanned++;
        $failedAt = (float) ($job->failed_at ?? 0);
        if ($failedAt < $start || $failedAt > $end) {
            continue;
        }
        $windowCount++;
        $firstLine = strtok((string) ($job->exception ?? ''), "\n") ?: '';
        $exceptionClass = strstr($firstLine, ':', true) ?: $firstLine;
        $key = (string) ($job->name ?? 'unknown').' | '.$exceptionClass;
        $groups[$key] = ($groups[$key] ?? 0) + 1;
        if (count($examples[$key] ?? []) < 5) {
            $examples[$key][] = [
                'id' => $job->id ?? null,
                'failed_at' => $job->failed_at ?? null,
                'queue' => $job->queue ?? null,
                'first_line' => mb_substr($firstLine, 0, 2000),
            ];
        }
    }
    $cursor = (int) $batch->last()->index;
} while ($batch->count() === 51 && $scanned < 10000);

arsort($groups);
$target = $repository->findFailed($targetId);
$targetSummary = null;
if ($target !== null) {
    $payload = json_decode((string) ($target->payload ?? ''), true);
    $targetSummary = [
        'id' => $target->id ?? null,
        'name' => $target->name ?? null,
        'status' => $target->status ?? null,
        'connection' => $target->connection ?? null,
        'queue' => $target->queue ?? null,
        'failed_at' => $target->failed_at ?? null,
        'attempts' => is_array($payload) ? ($payload['attempts'] ?? null) : null,
        'payload_bytes' => strlen((string) ($target->payload ?? '')),
        'payload_sha256' => hash('sha256', (string) ($target->payload ?? '')),
        'exception' => mb_substr((string) ($target->exception ?? ''), 0, 12000),
    ];
}

echo json_encode([
    'queue_state' => $queueState,
    'horizon_supervisors' => $supervisors,
    'failed_total' => $repository->totalFailed(),
    'failed_count_retention_window' => $repository->countFailed(),
    'failed_scanned' => $scanned,
    'failed_in_requested_window' => $windowCount,
    'failed_groups' => $groups,
    'failed_examples' => $examples,
    'target_job' => $targetSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
PHP

  echo '=== SQLITE READ-ONLY STATE ==='
  db_json=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = (string) config("database.default");
echo json_encode([
    "connection" => $connection,
    "database" => config("database.connections.".$connection.".database"),
    "busy_timeout" => config("database.connections.".$connection.".busy_timeout"),
    "journal_mode" => config("database.connections.".$connection.".journal_mode"),
    "synchronous" => config("database.connections.".$connection.".synchronous"),
], JSON_UNESCAPED_SLASHES);
')
  echo "$db_json"
  db_path=$(docker exec "$primary" php -r '
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = (string) config("database.default");
echo (string) config("database.connections.".$connection.".database", "");
')
  if [[ "$db_json" == *'"connection":"sqlite"'* && -n "$db_path" ]]; then
    docker exec "$primary" sqlite3 -readonly "$db_path" \
      'PRAGMA query_only=ON; PRAGMA journal_mode; PRAGMA locking_mode; PRAGMA wal_autocheckpoint; PRAGMA integrity_check;' || true
    docker exec "$primary" sh -lc \
      'for path in "$1" "$1-wal" "$1-shm"; do if [ -e "$path" ]; then stat -c "file=%n bytes=%s modified=%y" "$path"; fi; done' \
      sh "$db_path" || true
  fi

  echo '=== REDIS READ-ONLY STATE ==='
  docker exec "$primary" sh -lc \
    'redis-cli -s /data/redis.sock INFO server clients memory persistence stats 2>/dev/null | grep -E "^(redis_version|uptime_in_seconds|connected_clients|blocked_clients|used_memory_human|maxmemory_human|rdb_last_bgsave_status|aof_last_bgrewrite_status|instantaneous_ops_per_sec|total_error_replies|rejected_connections):"' || true

  echo '=== RELEVANT LARAVEL LOG EVENTS ==='
  docker exec \
    -e DIAG_START_LOCAL="$start_local" \
    -e DIAG_END_LOCAL="$end_local" \
    -e DIAG_JOB_ID="$DIAG_JOB_ID" \
    "$primary" sh -lc '
set -eu
find /www/storage/logs -maxdepth 1 -type f -name "*.log" -mtime -2 -print 2>/dev/null |
while IFS= read -r file; do
  echo "--- FILE: ${file##*/} ---"
  tail -c 52428800 "$file" 2>/dev/null |
    awk -v start="$DIAG_START_LOCAL" -v end="$DIAG_END_LOCAL" '\''
      /^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\]/ {
        timestamp = substr($0, 2, 19)
        keep = timestamp >= start && timestamp <= end
      }
      keep { print }
    '\'' |
    grep -Ei -B 3 -A 60 "StatUserJob|TrafficFetchJob|StatServerJob|PDOException|SQLSTATE|database( is)? locked|MaxAttemptsExceededException|$DIAG_JOB_ID" |
    sed -n "1,30000p" || true
done
' || true

  echo '=== RELEVANT CONTAINER LOG EVENTS ==='
  declare -A seen=()
  for container_id in "$primary" "$horizon_container" "$scheduler_container"; do
    [[ -z "${seen[$container_id]:-}" ]] || continue
    seen[$container_id]=1
    container_name=$(docker inspect -f '{{.Name}}' "$container_id" | sed 's#^/##')
    echo "--- CONTAINER: $container_name ---"
    docker logs --since "$DIAG_START_UTC" --until "$DIAG_END_UTC" --timestamps "$container_id" 2>&1 |
      grep -Ei -B 3 -A 60 'StatUserJob|TrafficFetchJob|StatServerJob|PDOException|SQLSTATE|database( is)? locked|MaxAttemptsExceededException|horizon|queue' |
      sed -n '1,30000p' || true
  done
}

collect_diagnostics 2>&1 | sanitize
