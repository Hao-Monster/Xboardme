#!/usr/bin/env bash
set -Eeuo pipefail

if ((EUID != 0)); then
  command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1 || {
    echo 'V2_COMMON_TEST_FAIL=root_execution_unavailable' >&2
    exit 1
  }
  exec sudo -n bash "$0"
fi

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_dir=$(mktemp -d)
cleanup() { rm -rf -- "$temporary_dir"; }
trap cleanup EXIT

mkdir -p "$temporary_dir/bin" "$temporary_dir/work"
cat > "$temporary_dir/bin/caddy" <<'SH'
#!/bin/sh
[ "$1" = validate ]
exit 0
SH
cat > "$temporary_dir/bin/systemctl" <<'SH'
#!/bin/sh
if [ "$1" = is-active ]; then
  printf '%s\n' active
  exit 0
fi
if [ "$1" = reload ] && [ "${FAIL_RELOAD:-0}" = 1 ]; then
  exit 1
fi
exit 0
SH
chmod +x "$temporary_dir/bin/caddy" "$temporary_dir/bin/systemctl"
PATH="$temporary_dir/bin:$PATH"

# shellcheck source=release-state.sh
source "$repo_root/.github/scripts/release-state.sh"
# shellcheck source=v2-low-memory-common.sh
source "$repo_root/.github/scripts/v2-low-memory-common.sh"

backup_release_id=5678-1
backup_release_dir="$temporary_dir/backup-release/$backup_release_id"
backup_path="$backup_release_dir/backups/database.sqlite"
backup_log="$temporary_dir/backup-operations.log"
mkdir -p "$backup_release_dir/backups"
export RELEASE_ID=$backup_release_id
export V2_RELEASE_DIR=$backup_release_dir
export MOCK_DB_PATH=/www/.docker/.data/database.sqlite
docker() {
  printf '%s\n' "$*" >> "$backup_log"
  if [[ "$1" == exec && "$3" == php ]]; then
    printf '%s\n' "$MOCK_DB_PATH"
  elif [[ "$1" == exec && "$3" == test ]]; then
    return 1
  elif [[ "$1" == exec && "$3" == sqlite3 && "$5" == 'PRAGMA journal_mode;' ]]; then
    printf '%s\n' wal
  elif [[ "$1" == exec && "$3" == sqlite3 && "$5" == 'PRAGMA integrity_check;' ]]; then
    printf '%s\n' "${MOCK_INTEGRITY:-ok}"
  elif [[ "$1" == cp ]]; then
    printf '%s\n' consistent-sqlite-backup > "$3"
  fi
}
backup_sha256=$(v2_backup_sqlite_database source-web "$backup_path")
[[ "$backup_sha256" == "$(sha256sum "$backup_path" | awk '{print $1}')" ]]
[[ "$(stat -c '%a' "$backup_path")" == 600 ]]
grep -Fq "exec source-web sqlite3 /www/.docker/.data/database.sqlite .backup '/www/.docker/.data/.codex-v2-release-$backup_release_id.sqlite'" "$backup_log"
grep -Fq "exec source-web sqlite3 /www/.docker/.data/.codex-v2-release-$backup_release_id.sqlite PRAGMA integrity_check;" "$backup_log"
grep -Fq "exec source-web rm -f -- /www/.docker/.data/.codex-v2-release-$backup_release_id.sqlite" "$backup_log"

rm -f -- "$backup_path"
MOCK_INTEGRITY=corrupt
if v2_backup_sqlite_database source-web "$backup_path" 2>"$temporary_dir/corrupt-backup-error.log"; then
  echo 'V2_COMMON_TEST_FAIL=corrupt_database_backup_was_accepted' >&2
  exit 1
fi
grep -Fxq 'V2_FAIL=sqlite_backup_integrity_failed' "$temporary_dir/corrupt-backup-error.log"
[[ ! -e "$backup_path" ]]
unset MOCK_INTEGRITY

MOCK_DB_PATH=/tmp/database.sqlite
if v2_backup_sqlite_database source-web "$backup_release_dir/backups/invalid.sqlite" 2>"$temporary_dir/invalid-backup-error.log"; then
  echo 'V2_COMMON_TEST_FAIL=invalid_database_path_was_accepted' >&2
  exit 1
fi
grep -Fxq 'V2_FAIL=invalid_sqlite_database_path' "$temporary_dir/invalid-backup-error.log"

env_file="$temporary_dir/work/.env"
data_path="$temporary_dir/work/data"
logs_path="$temporary_dir/work/logs"
theme_path="$temporary_dir/work/theme"
knowledge_path="$temporary_dir/work/knowledge"
plugins_path="$temporary_dir/work/plugins"
redis_volume_name=xboard_redis
compose_json="$temporary_dir/rendered-compose.json"
jq -n \
  --arg env "$env_file" \
  --arg data "$data_path" \
  --arg logs "$logs_path" \
  --arg theme "$theme_path" \
  --arg knowledge "$knowledge_path" \
  --arg plugins "$plugins_path" \
  --arg redis_volume "$redis_volume_name" '
    def volumes: [
      {type:"bind", source:$env, target:"/www/.env", read_only:true},
      {type:"bind", source:$data, target:"/www/.docker/.data"},
      {type:"bind", source:$logs, target:"/www/storage/logs"},
      {type:"bind", source:$theme, target:"/www/storage/theme"},
      {type:"bind", source:$knowledge, target:"/www/storage/app/knowledge-attachments"},
      {type:"bind", source:$plugins, target:"/www/plugins"}
    ];
    {
      services: {
        web: {volumes: volumes},
        ws: {volumes: volumes},
        horizon: {volumes: volumes},
        scheduler: {volumes: volumes},
        maintenance: {volumes: volumes},
        redis: {environment: {XBOARD_REDIS_APPENDONLY: "no"}}
      },
      volumes: {redis_data: {name: $redis_volume, external: true}}
    }
  ' > "$compose_json"
v2_validate_rendered_production_compose \
  "$compose_json" "$env_file" "$data_path" "$logs_path" "$theme_path" \
  "$knowledge_path" "$plugins_path" "$redis_volume_name"
jq 'del(.services.scheduler.volumes[0])' "$compose_json" > "$temporary_dir/invalid-compose.json"
if v2_validate_rendered_production_compose \
  "$temporary_dir/invalid-compose.json" "$env_file" "$data_path" "$logs_path" "$theme_path" \
  "$knowledge_path" "$plugins_path" "$redis_volume_name" 2>/dev/null; then
  echo 'V2_COMMON_TEST_FAIL=invalid_production_mounts_were_accepted' >&2
  exit 1
fi

state_release_id=1234-1
state_release_sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
state_workdir="$temporary_dir/state-workdir"
state_release_dir="$state_workdir/.codex-v2-release/$state_release_id"
state_file="$state_release_dir/state.json"
state_redis_password="$state_release_dir/redis-password"
state_caddy_backup="$state_release_dir/backups/caddy.conf"
state_database_backup="$state_release_dir/backups/database.sqlite"
privileged=()
if ((EUID != 0)); then
  sudo -n true >/dev/null 2>&1 || {
    echo 'V2_COMMON_TEST_FAIL=privileged_fixture_setup_unavailable' >&2
    exit 1
  }
  privileged=(sudo -n)
fi
mkdir -p "$state_release_dir/backups"
chmod 700 "$state_release_dir"
printf '%s\n' 'runtime=true' > "$state_release_dir/runtime.env"
chmod 600 "$state_release_dir/runtime.env"
printf '%s\n' '0123456789abcdef0123456789abcdef' > "$state_redis_password"
"${privileged[@]}" chown 0:1000 "$state_redis_password"
"${privileged[@]}" chmod 440 "$state_redis_password"
printf '%s\n' ':443 { respond 200 }' > "$state_caddy_backup"
chmod 600 "$state_caddy_backup"
printf '%s\n' consistent-database > "$state_database_backup"
chmod 600 "$state_database_backup"
state_database_backup_sha256=$(sha256sum "$state_database_backup" | awk '{print $1}')
"${privileged[@]}" chown 0:0 "$state_release_dir/runtime.env" "$state_caddy_backup" "$state_database_backup"
release_state_create "$state_file" \
  v2_schema_version "$V2_RELEASE_STATE_SCHEMA" \
  release_id "$state_release_id" \
  release_sha "$state_release_sha" \
  release_image "ghcr.io/hao-monster/xboardme@sha256:$(printf 'b%.0s' {1..64})" \
  project_name "xboard-v2-$state_release_id" \
  workdir "$state_workdir" \
  release_dir "$state_release_dir" \
  active_port 7002 \
  maintenance_port 7003 \
  caddy_config "$state_workdir/Caddyfile" \
  caddy_backup "$state_caddy_backup" \
  database_backup "$state_database_backup" \
  database_backup_sha256 "$state_database_backup_sha256" \
  legacy_anchor_id legacy-anchor \
  legacy_web_id legacy-web \
  legacy_horizon_id legacy-horizon \
  legacy_scheduler_id legacy-scheduler \
  redis_password_file "$state_redis_password" \
  redis_volume_name xboard_redis \
  app_data_path "$state_workdir/data" \
  maintenance_image caddy:immutable \
  maintenance_container "xboard-v2-maintenance-$state_release_id" \
  traffic_state prepared
jq -e '.schema_version == 2 and .v2_schema_version == "1"' "$state_file" >/dev/null
export V2_WORKDIR=$state_workdir
export V2_RELEASE_DIR=$state_release_dir
export V2_STATE_FILE=$state_file
export RELEASE_ID=$state_release_id
export EXPECTED_RELEASE_SHA=$state_release_sha
v2_load_state
[[ "$STATE_SCHEMA_VERSION" == "$V2_RELEASE_STATE_SCHEMA" ]]
[[ "$STATE_RELEASE_ID" == "$state_release_id" ]]
[[ "$TRAFFIC_STATE" == prepared ]]
[[ "$LEGACY_TOPOLOGY" == legacy ]]
[[ -z "$LEGACY_WS_ID" && -z "$LEGACY_EDGE_ID" ]]
printf '%s\n' tampered-database > "$state_database_backup"
if v2_load_state 2>"$temporary_dir/tampered-state-error.log"; then
  echo 'V2_COMMON_TEST_FAIL=tampered_database_backup_was_accepted' >&2
  exit 1
fi
grep -Fxq 'V2_FAIL=database_backup_checksum_mismatch' "$temporary_dir/tampered-state-error.log"
printf '%s\n' consistent-database > "$state_database_backup"
release_state_set "$state_file" legacy_topology v2
release_state_set "$state_file" legacy_ws_id legacy-ws
release_state_set "$state_file" legacy_edge_id legacy-edge
v2_load_state
[[ "$LEGACY_TOPOLOGY" == v2 ]]
[[ "$LEGACY_WS_ID" == legacy-ws && "$LEGACY_EDGE_ID" == legacy-edge ]]
release_state_set "$state_file" legacy_topology legacy
release_state_set "$state_file" legacy_ws_id ''
release_state_set "$state_file" legacy_edge_id ''
v2_load_state
release_state_set "$state_file" traffic_state maintenance
release_state_set "$state_file" traffic_state ready
release_state_set "$state_file" traffic_state active_v2
[[ "$(release_state_get "$state_file" traffic_state)" == active_v2 ]]

active_v2_root="$temporary_dir/active-v2-root"
active_v2_release_id=4321-2
active_v2_release_dir="$active_v2_root/.codex-v2-release/$active_v2_release_id"
mkdir -p "$active_v2_release_dir"
docker() {
  if [[ "$1" == ps && "$2" == -q ]]; then
    printf '%s\n' active-v2-edge
  elif [[ "$1" == inspect && "$3" == *working_dir* ]]; then
    printf '%s\n' "$active_v2_release_dir"
  elif [[ "$1" == inspect && "$3" == *com.docker.compose.project* ]]; then
    printf 'xboard-v2-%s\n' "$active_v2_release_id"
  elif [[ "$1" == inspect ]]; then
    printf '%s\n' "$4"
  fi
}
V2_ANCHOR_DISCOVERY_ID=stale
v2_find_workdir
[[ "$V2_WORKDIR" == "$active_v2_root" ]]
[[ -z "$V2_ANCHOR_DISCOVERY_ID" ]]

CADDY_CONFIG="$temporary_dir/Caddyfile"
CADDY_BACKUP="$temporary_dir/Caddyfile.backup"
ACTIVE_PORT=7002
MAINTENANCE_PORT=7003
printf '%s\n' ':443 {' '  reverse_proxy 127.0.0.1:7002' '}' > "$CADDY_CONFIG"
cp "$CADDY_CONFIG" "$CADDY_BACKUP"

v2_replace_caddy_upstream "$ACTIVE_PORT" "$MAINTENANCE_PORT"
grep -q '127.0.0.1:7003' "$CADDY_CONFIG"
! grep -q '127.0.0.1:7002' "$CADDY_CONFIG"
v2_restore_caddy_backup
cmp -s "$CADDY_CONFIG" "$CADDY_BACKUP"

if FAIL_RELOAD=1 v2_replace_caddy_upstream "$ACTIVE_PORT" "$MAINTENANCE_PORT" 2>/dev/null; then
  echo 'V2_COMMON_TEST_FAIL=reload_failure_was_accepted' >&2
  exit 1
fi
cmp -s "$CADDY_CONFIG" "$CADDY_BACKUP"

V2_WORKDIR="$temporary_dir/work"
v2_acquire_lock
if flock -n "$V2_WORKDIR/.codex-v2-release/deploy.lock" true; then
  echo 'V2_COMMON_TEST_FAIL=overlapping_lock_was_accepted' >&2
  exit 1
fi
exec 9>&-
flock -n "$V2_WORKDIR/.codex-v2-release/deploy.lock" true

operation_log="$temporary_dir/operations.log"
reserved_counter="$temporary_dir/reserved-counter"
printf '%s\n' 0 > "$reserved_counter"
docker() { printf '%s\n' "$*" >> "$operation_log"; }
v2_assert_legacy_identity() { :; }
v2_legacy_redis_save() { printf '%s\n' redis-save >> "$operation_log"; }
v2_container_running() { return 1; }
sleep() { :; }
export LEGACY_SCHEDULER_ID=legacy-scheduler
export LEGACY_HORIZON_ID=legacy-horizon
export LEGACY_WEB_ID=legacy-web
export LEGACY_ANCHOR_ID=legacy-anchor
export LEGACY_TOPOLOGY=legacy
export LEGACY_WS_ID=''
export LEGACY_EDGE_ID=''
v2_legacy_reserved_jobs() {
  count=$(<"$reserved_counter")
  printf '%s\n' "$((count + 1))" > "$reserved_counter"
  if ((count < 2)); then
    printf '%s\n' 1
  else
    printf '%s\n' 0
  fi
}
v2_stop_legacy_runtime
cat > "$temporary_dir/expected-operations.log" <<'EOF'
stop --time 30 legacy-scheduler
exec legacy-horizon php /www/artisan horizon:pause
stop --time 60 legacy-horizon
stop --time 30 legacy-web
redis-save
stop --time 30 legacy-anchor
EOF
cmp -s "$operation_log" "$temporary_dir/expected-operations.log"

: > "$operation_log"
LEGACY_TOPOLOGY=v2
LEGACY_WS_ID=legacy-ws
LEGACY_EDGE_ID=legacy-edge
v2_legacy_reserved_jobs() { printf '%s\n' 0; }
v2_stop_legacy_runtime
cat > "$temporary_dir/expected-v2-operations.log" <<'EOF'
stop --time 30 legacy-scheduler
exec legacy-horizon php /www/artisan horizon:pause
stop --time 60 legacy-horizon
stop --time 30 legacy-edge legacy-ws
stop --time 30 legacy-web
redis-save
stop --time 30 legacy-anchor
EOF
cmp -s "$operation_log" "$temporary_dir/expected-v2-operations.log"
mapfile -t recorded_v2_ids < <(v2_legacy_ids)
[[ "${recorded_v2_ids[*]}" == 'legacy-anchor legacy-web legacy-horizon legacy-scheduler legacy-ws legacy-edge' ]]

: > "$operation_log"
v2_legacy_redis_ping() { return 0; }
v2_container_running() { return 0; }
v2_legacy_horizon_running() { return 0; }
curl() { return 0; }
docker() { printf '%s\n' "$*" >> "$operation_log"; }
v2_start_legacy_runtime
for expected_start in legacy-anchor legacy-web legacy-ws legacy-edge legacy-horizon legacy-scheduler; do
  grep -Fxq "start $expected_start" "$operation_log"
done
redis_start_line=$(grep -nFx 'start legacy-anchor' "$operation_log" | cut -d: -f1)
web_start_line=$(grep -nFx 'start legacy-web' "$operation_log" | cut -d: -f1)
edge_start_line=$(grep -nFx 'start legacy-edge' "$operation_log" | cut -d: -f1)
scheduler_start_line=$(grep -nFx 'start legacy-scheduler' "$operation_log" | cut -d: -f1)
((redis_start_line < web_start_line && web_start_line < edge_start_line && edge_start_line < scheduler_start_line))

docker() {
  if [[ "$1" == inspect && "$3" == *com.docker.compose.project* ]]; then
    printf '%s\n' old-v2-project
  elif [[ "$1" == inspect ]]; then
    printf '%s\n' "$4"
  elif [[ "$1" == ps ]]; then
    for argument in "$@"; do
      case "$argument" in
        label=com.docker.compose.service=*)
          service=${argument##*=}
          printf 'old-%s\n' "$service"
          [[ "${DUPLICATE_V2_SERVICE:-}" != "$service" ]] || printf 'duplicate-%s\n' "$service"
          ;;
      esac
    done
  fi
}
v2_discover_active_v2_runtime old-edge
[[ "$V2_DISCOVERED_PROJECT" == old-v2-project ]]
[[ "$V2_DISCOVERED_REDIS_ID" == old-redis ]]
[[ "$V2_DISCOVERED_WEB_ID" == old-web ]]
[[ "$V2_DISCOVERED_WS_ID" == old-ws ]]
[[ "$V2_DISCOVERED_EDGE_ID" == old-edge ]]
[[ "$V2_DISCOVERED_HORIZON_ID" == old-horizon ]]
[[ "$V2_DISCOVERED_SCHEDULER_ID" == old-scheduler ]]
if DUPLICATE_V2_SERVICE=ws v2_discover_active_v2_runtime old-edge 2>"$temporary_dir/discovery-error.log"; then
  echo 'V2_COMMON_TEST_FAIL=ambiguous_v2_service_was_accepted' >&2
  exit 1
fi
grep -Fxq 'V2_FAIL=active_v2_service_ambiguous:ws:2' "$temporary_dir/discovery-error.log"

: > "$operation_log"
v2_legacy_reserved_jobs() { printf '%s\n' 1; }
docker() { printf '%s\n' "$*" >> "$operation_log"; }
v2_container_running() { return 1; }
if v2_stop_legacy_runtime 2>/dev/null; then
  echo 'V2_COMMON_TEST_FAIL=reserved_jobs_were_abandoned' >&2
  exit 1
fi
! grep -Fq 'stop --time 60 legacy-horizon' "$operation_log"

: > "$operation_log"
REDIS_VOLUME_NAME=xboard_redis
LEGACY_TOPOLOGY=legacy
LEGACY_WS_ID=''
LEGACY_EDGE_ID=''
v2_legacy_redis_ping() {
  docker exec "$LEGACY_ANCHOR_ID" redis-cli -s /data/redis.sock ping | grep -qx PONG
}
expected_legacy_image_id="sha256:$(printf 'c%.0s' {1..64})"
anchor_running=1
v2_container_running() { [[ "$1" == "$LEGACY_ANCHOR_ID" && "$anchor_running" == 1 ]]; }
docker() {
  if [[ "$1" == inspect ]]; then
    printf '%s\n' "$expected_legacy_image_id"
    return 0
  fi
  if [[ "$1" == stop && "$4" == "$LEGACY_ANCHOR_ID" ]]; then
    anchor_running=0
  fi
  printf '%s\n' "$*" >> "$operation_log"
}
v2_restore_legacy_redis_owner
grep -Fxq 'stop --time 30 legacy-anchor' "$operation_log"
grep -Fq "run --rm --network none --read-only --security-opt no-new-privileges:true --user 0:0 --memory 64m --pids-limit 32 --volume $REDIS_VOLUME_NAME:/data --entrypoint /bin/sh $expected_legacy_image_id" "$operation_log"
stop_line=$(grep -nF 'stop --time 30 legacy-anchor' "$operation_log" | cut -d: -f1)
owner_line=$(grep -nF 'run --rm --network none' "$operation_log" | cut -d: -f1)
((stop_line < owner_line))

: > "$operation_log"
anchor_running=1
docker() {
  if [[ "$1" == exec ]]; then
    printf '%s\n' PONG
    return 0
  fi
  printf '%s\n' "$*" >> "$operation_log"
}
v2_restore_legacy_redis_owner
[[ ! -s "$operation_log" ]]

echo 'V2_LOW_MEMORY_COMMON=PASS database_backup=true caddy_rollback=true lock_exclusive=true queue_drain=true v2_upgrade=true redis_owner_restore=true redis_owner_retry=true'
