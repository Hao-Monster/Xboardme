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
