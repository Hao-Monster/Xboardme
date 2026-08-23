#!/usr/bin/env bash
set -Eeuo pipefail

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
mkdir -p "$state_release_dir/backups"
chmod 700 "$state_release_dir"
printf '%s\n' 'runtime=true' > "$state_release_dir/runtime.env"
chmod 600 "$state_release_dir/runtime.env"
printf '%s\n' '0123456789abcdef0123456789abcdef' > "$state_redis_password"
chown 0:1000 "$state_redis_password"
chmod 440 "$state_redis_password"
printf '%s\n' ':443 { respond 200 }' > "$state_caddy_backup"
chmod 600 "$state_caddy_backup"
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
release_state_set "$state_file" traffic_state maintenance
release_state_set "$state_file" traffic_state ready
release_state_set "$state_file" traffic_state active_v2
[[ "$(release_state_get "$state_file" traffic_state)" == active_v2 ]]

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
v2_legacy_reserved_jobs() { printf '%s\n' 1; }
if v2_stop_legacy_runtime 2>/dev/null; then
  echo 'V2_COMMON_TEST_FAIL=reserved_jobs_were_abandoned' >&2
  exit 1
fi
! grep -Fq 'stop --time 60 legacy-horizon' "$operation_log"

echo 'V2_LOW_MEMORY_COMMON=PASS caddy_rollback=true lock_exclusive=true queue_drain=true'
