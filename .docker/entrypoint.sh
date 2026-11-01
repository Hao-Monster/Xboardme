#!/bin/sh
set -e

# V2 roles are opt-in. The legacy default preserves the current all-in-one
# image behaviour until the dedicated runtime Compose definition is deployed.
: "${XBOARD_RUNTIME_ROLE:=legacy}"
case "$XBOARD_RUNTIME_ROLE" in
    legacy) ;;
    web|ws|horizon|scheduler|maintenance)
        ENABLE_WEB=false
        ENABLE_HORIZON=false
        ENABLE_REDIS=false
        ENABLE_WS_SERVER=false
        ENABLE_CADDY=false
        ENABLE_SCHEDULER=false
        : "${LOG_CHANNEL:=stderr}"
        : "${LOG_DEPRECATIONS_CHANNEL:=stderr}"
        case "$XBOARD_RUNTIME_ROLE" in
            web) ENABLE_WEB=true ;;
            ws)
                ENABLE_WS_SERVER=true
                ;;
            horizon) ENABLE_HORIZON=true ;;
        esac
        ;;
    *)
        echo "[entrypoint] Unsupported XBOARD_RUNTIME_ROLE=${XBOARD_RUNTIME_ROLE}." >&2
        exit 64
        ;;
esac
export XBOARD_RUNTIME_ROLE ENABLE_WEB ENABLE_HORIZON ENABLE_REDIS ENABLE_WS_SERVER \
       ENABLE_CADDY ENABLE_SCHEDULER LOG_CHANNEL LOG_DEPRECATIONS_CHANNEL

# Resolve the binding scheme based on whether the embedded Caddy is enabled.
#
# When ENABLE_CADDY=true (default), Caddy owns the public port (7001) and
# dispatches traffic internally; Octane and ws-server bind to localhost only
# so they cannot be reached from outside the container.
#
# When ENABLE_CADDY=false (e.g. an external reverse proxy or split mode),
# Octane takes the public port directly to keep behaviour identical to the
# pre-Caddy releases.
if [ "${ENABLE_CADDY}" = "true" ]; then
    : "${OCTANE_HOST:=127.0.0.1}"
    : "${OCTANE_PORT:=7002}"
    : "${WS_HOST:=127.0.0.1}"
    : "${WS_PORT:=8076}"
    : "${CADDY_LISTEN_PORT:=7001}"
else
    : "${OCTANE_HOST:=0.0.0.0}"
    : "${OCTANE_PORT:=7001}"
    : "${WS_HOST:=0.0.0.0}"
    : "${WS_PORT:=8076}"
fi
export OCTANE_HOST OCTANE_PORT WS_HOST WS_PORT CADDY_LISTEN_PORT
export OCTANE_INTERNAL_PORT="${OCTANE_PORT}"

# Process-control files must be private to each container. Blue/green
# instances intentionally share application data and logs, but never PIDs or
# Octane state because those files are used for process signalling.
: "${RUNTIME_INSTANCE_ID:=default}"
case "${RUNTIME_INSTANCE_ID}" in
    *[!A-Za-z0-9_.-]*) echo "[entrypoint] Invalid RUNTIME_INSTANCE_ID." >&2; exit 1 ;;
esac
: "${OCTANE_STATE_FILE:=/tmp/xboard-octane-${RUNTIME_INSTANCE_ID}.json}"
: "${WS_PID_FILE:=/tmp/xboard-ws-${RUNTIME_INSTANCE_ID}.pid}"
: "${SUPERVISOR_PID_FILE:=/tmp/xboard-supervisord-${RUNTIME_INSTANCE_ID}.pid}"
: "${SUPERVISOR_SOCKET_FILE:=/tmp/xboard-supervisor-${RUNTIME_INSTANCE_ID}.sock}"
export RUNTIME_INSTANCE_ID OCTANE_STATE_FILE WS_PID_FILE SUPERVISOR_PID_FILE SUPERVISOR_SOCKET_FILE
if [ "$XBOARD_RUNTIME_ROLE" = ws ]; then
    # Workerman treats its log target as a regular file: it calls touch/chmod
    # before starting. Character devices such as /dev/stderr therefore abort
    # the dedicated non-root WS role before its health check can become ready.
    : "${WS_LOG_FILE:=/tmp/xboard-ws-${RUNTIME_INSTANCE_ID}.log}"
    export WS_LOG_FILE
fi

if [ "${XBOARD_ENTRYPOINT_SELF_TEST:-}" != "autotune-gate" ]; then
    # Keep knowledge attachments on a private, writable filesystem. The directory
    # is mounted by the Compose templates and remains outside the public web root.
    : "${KNOWLEDGE_ATTACHMENT_ROOT:=/www/storage/app/knowledge-attachments}"
    case "${KNOWLEDGE_ATTACHMENT_ROOT}" in
        /*) ;;
        *) echo "[entrypoint] KNOWLEDGE_ATTACHMENT_ROOT must be an absolute path." >&2; exit 1 ;;
    esac
    case "${KNOWLEDGE_ATTACHMENT_ROOT}" in
        /|/www|/www/storage|/www/storage/app)
            echo "[entrypoint] Refusing unsafe KNOWLEDGE_ATTACHMENT_ROOT=${KNOWLEDGE_ATTACHMENT_ROOT}." >&2
            exit 1
            ;;
    esac
    mkdir -p "${KNOWLEDGE_ATTACHMENT_ROOT}/files" \
             "${KNOWLEDGE_ATTACHMENT_ROOT}/temporary" \
             "${KNOWLEDGE_ATTACHMENT_ROOT}/quarantine"
    chown www:www "${KNOWLEDGE_ATTACHMENT_ROOT}" \
                  "${KNOWLEDGE_ATTACHMENT_ROOT}/files" \
                  "${KNOWLEDGE_ATTACHMENT_ROOT}/temporary" \
                  "${KNOWLEDGE_ATTACHMENT_ROOT}/quarantine"
    chmod 0750 "${KNOWLEDGE_ATTACHMENT_ROOT}" \
               "${KNOWLEDGE_ATTACHMENT_ROOT}/files" \
               "${KNOWLEDGE_ATTACHMENT_ROOT}/temporary" \
               "${KNOWLEDGE_ATTACHMENT_ROOT}/quarantine"
    if ! su-exec www test -r "${KNOWLEDGE_ATTACHMENT_ROOT}" || ! su-exec www test -w "${KNOWLEDGE_ATTACHMENT_ROOT}"; then
        echo "[entrypoint] Knowledge attachment storage is not readable and writable by uid 1000." >&2
        exit 1
    fi
    export KNOWLEDGE_ATTACHMENT_ROOT
    echo "[entrypoint] Knowledge attachment storage ready: ${KNOWLEDGE_ATTACHMENT_ROOT}"
fi

# ---------------------------------------------------------------------------
# Auto-tune worker counts based on the host (CPU + memory).
#
# Heuristic: each PHP worker (Octane/Horizon) costs ~80 MiB. After reserving
# ~300 MiB for the always-on processes (caddy/redis/ws-server/masters), divide
# the remaining budget across roles.  Any user-set ENV wins.
# ---------------------------------------------------------------------------
detect_cpus() {
    if [ -r /sys/fs/cgroup/cpu.max ]; then
        # cgroup v2: "<quota> <period>" or "max <period>"
        read -r q p < /sys/fs/cgroup/cpu.max 2>/dev/null
        if [ "$q" != "max" ] && [ -n "$q" ] && [ -n "$p" ] && [ "$p" -gt 0 ]; then
            echo $(( (q + p - 1) / p ))
            return
        fi
    fi
    nproc 2>/dev/null || echo 1
}

detect_mem_mib() {
    if [ -r /sys/fs/cgroup/memory.max ]; then
        m=$(cat /sys/fs/cgroup/memory.max 2>/dev/null)
        if [ "$m" != "max" ] && [ -n "$m" ]; then
            echo $(( m / 1024 / 1024 ))
            return
        fi
    fi
    # No cgroup limit: avoid over-provisioning on big hosts. Cap the assumed
    # budget to MEM_FALLBACK_MIB (default 1024) unless the user opts out by
    # setting it explicitly. Use whichever is smaller of MemAvailable and cap.
    avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 1024)
    cap=${MEM_FALLBACK_MIB:-1024}
    [ "$avail" -lt "$cap" ] && echo "$avail" || echo "$cap"
}

if [ "${XBOARD_ENTRYPOINT_SELF_TEST:-}" = "autotune-gate" ] && [ -n "${XBOARD_TEST_CPUS:-}" ]; then
    CPUS=${XBOARD_TEST_CPUS}
else
    CPUS=$(detect_cpus)
fi
if [ "${XBOARD_ENTRYPOINT_SELF_TEST:-}" = "autotune-gate" ] && [ -n "${XBOARD_TEST_MEM_MIB:-}" ]; then
    MEM_MIB=${XBOARD_TEST_MEM_MIB}
else
    MEM_MIB=$(detect_mem_mib)
fi

# Resource profile presets. RESOURCE_PROFILE selects ratios for the budget split:
#   minimal     - smallest profile reserves (~200 MiB base, 100 MiB/slot), single octane worker,
#                 horizon maxProcesses set to 1/1/1 (note: balance=auto/simple still maintains
#                 at least 1 worker per configured queue pool).
#   balanced    - default; ~80 MiB per worker, octane gets 25% of slots.
#   performance - larger reserves for opcache/caches, more aggressive horizon caps.
#   auto        - same as balanced.
: "${RESOURCE_PROFILE:=auto}"
case "$RESOURCE_PROFILE" in
    minimal)     RESERVED_MIB=200; SLOT_MIB=100; OCT_NUM=1; OCT_DEN=1; OCT_FORCE=1; auto_horizon_mem=128; auto_octane_gc=64 ;;
    performance) RESERVED_MIB=400; SLOT_MIB=70;  OCT_NUM=1; OCT_DEN=3; OCT_FORCE=0; auto_horizon_mem=384; auto_octane_gc=256 ;;
    balanced|auto|*) RESERVED_MIB=300; SLOT_MIB=80;  OCT_NUM=1; OCT_DEN=4; OCT_FORCE=0; auto_horizon_mem=256; auto_octane_gc=128 ;;
esac

BUDGET=$(( MEM_MIB - RESERVED_MIB ))
[ "$BUDGET" -lt "$SLOT_MIB" ] && BUDGET=$SLOT_MIB
SLOTS=$(( BUDGET / SLOT_MIB ))

clamp() { v=$1; lo=$2; hi=$3; [ "$v" -lt "$lo" ] && v=$lo; [ "$v" -gt "$hi" ] && v=$hi; echo "$v"; }

user_octane_workers="${OCTANE_WORKERS:-}"
case "$user_octane_workers" in
    "")
        resolved_octane_workers=""
        ;;
    auto)
        resolved_octane_workers="$CPUS"
        [ "$resolved_octane_workers" -lt 1 ] && resolved_octane_workers=1
        ;;
    *[!0-9]*|0)
        echo "[entrypoint] Refusing invalid OCTANE_WORKERS='${user_octane_workers}'; expected 'auto' or a positive integer." >&2
        exit 1
        ;;
    *)
        resolved_octane_workers="$user_octane_workers"
        ;;
esac
if [ "$OCT_FORCE" = "1" ]; then
    auto_octane=1
    auto_dp=1; auto_biz=1; auto_notif=1
else
    auto_octane=$(clamp $(( (SLOTS * OCT_NUM) / OCT_DEN )) 1 "$CPUS")
    remaining=$(( SLOTS - auto_octane - 2 ))
    [ "$remaining" -lt 3 ] && remaining=3
    auto_dp=$(clamp $(( remaining / 2 )) 1 $(( CPUS * 2 )))
    auto_biz=$(clamp $(( remaining / 4 )) 1 "$CPUS")
    auto_notif=$(clamp $(( remaining / 4 )) 1 "$CPUS")
fi

# Export Horizon caps before validating config/horizon.php so effective worker pool
# calculations reflect the exact supervisor options Horizon will boot with.
: "${HORIZON_DATA_PIPELINE_MAX:=$auto_dp}"
: "${HORIZON_BUSINESS_MAX:=$auto_biz}"
: "${HORIZON_NOTIFICATION_MAX:=$auto_notif}"
: "${HORIZON_WORKER_MEMORY_MB:=$auto_horizon_mem}"
: "${HORIZON_WORKER_MAX_TIME:=0}"
: "${HORIZON_WORKER_MAX_JOBS:=0}"
export HORIZON_DATA_PIPELINE_MAX HORIZON_BUSINESS_MAX HORIZON_NOTIFICATION_MAX \
       HORIZON_WORKER_MEMORY_MB HORIZON_WORKER_MAX_TIME HORIZON_WORKER_MAX_JOBS \
       RESOURCE_PROFILE

: "${XBOARD_APP_ROOT:=/www}"
: "${PHP_BIN:=php}"
hwid_switch_exports=$(
    XBOARD_APP_ROOT="${XBOARD_APP_ROOT}" \
    XBOARD_ENV_PATH="${XBOARD_ENV_PATH:-}" \
    XBOARD_ENV_FILE="${XBOARD_ENV_FILE:-}" \
    "${PHP_BIN}" -r '
        $root = rtrim((string) getenv("XBOARD_APP_ROOT"), "/\\");
        $envPath = getenv("XBOARD_ENV_PATH");
        $envFile = getenv("XBOARD_ENV_FILE");
        require $root . "/vendor/autoload.php";
        try {
            $flags = App\Support\HwidObservationConfigValidator::resolveAndValidateBootstrapSwitches(
                $root,
                is_string($envPath) && $envPath !== "" ? $envPath : null,
                is_string($envFile) && $envFile !== "" ? $envFile : null
            );
        } catch (Throwable $e) {
            fwrite(STDERR, "[entrypoint] Refusing invalid subscription HWID configuration: " . $e->getMessage() . "\n");
            exit(1);
        }
        printf(
            "SUBSCRIPTION_HWID_ASYNC_RESOLVED=%s\nSUBSCRIPTION_HWID_WORKER_RESOLVED=%s\nSUBSCRIPTION_HWID_REQUIRED_HORIZON_SLOTS=%d\n",
            $flags["hwid_async_metadata"] ? "true" : "false",
            $flags["hwid_observation_worker_enabled"] ? "true" : "false",
            (int) ($flags["required_horizon_workers"] ?? 10)
        );
    '
) || exit 1
eval "$hwid_switch_exports"

if [ "${ENABLE_HORIZON:-true}" = "true" ] && [ "$SUBSCRIPTION_HWID_WORKER_RESOLVED" = "true" ]; then
    required_web_slots=0
    if [ "${ENABLE_WEB:-true}" = "true" ]; then
        required_web_slots=${resolved_octane_workers:-1}
        [ "$required_web_slots" -lt 1 ] && required_web_slots=1
    fi
    required_horizon_slots=${SUBSCRIPTION_HWID_REQUIRED_HORIZON_SLOTS:-10}
    [ "$required_horizon_slots" -lt 1 ] && required_horizon_slots=1
    required_total_mib=$(( RESERVED_MIB + (required_web_slots + required_horizon_slots) * SLOT_MIB ))
    if [ "$MEM_MIB" -lt "$required_total_mib" ]; then
        echo "[entrypoint] Refusing SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true for profile=${RESOURCE_PROFILE}: mem=${MEM_MIB}MiB is below required ${required_total_mib}MiB." >&2
        exit 1
    fi
    if [ -z "$user_octane_workers" ] && [ "${ENABLE_WEB:-true}" = "true" ] && [ "$OCT_FORCE" != "1" ]; then
        avail_web_slots=$(( SLOTS - required_horizon_slots ))
        [ "$avail_web_slots" -lt 1 ] && avail_web_slots=1
        [ "$auto_octane" -gt "$avail_web_slots" ] && auto_octane=$avail_web_slots
    fi
fi

# User-set ENV always wins.
: "${OCTANE_WORKERS:=$auto_octane}"
: "${OCTANE_TASK_WORKERS:=1}"
: "${OCTANE_MAX_REQUESTS:=500}"
: "${OCTANE_GARBAGE_MB:=$auto_octane_gc}"
: "${OCTANE_MAX_EXECUTION_TIME:=60}"

export OCTANE_WORKERS OCTANE_TASK_WORKERS OCTANE_MAX_REQUESTS \
       OCTANE_GARBAGE_MB OCTANE_MAX_EXECUTION_TIME

echo "[entrypoint] Auto-tune (profile=${RESOURCE_PROFILE}): cpus=${CPUS} mem=${MEM_MIB}MiB slots=${SLOTS} -> octane=${OCTANE_WORKERS} horizon(dp/biz/notif)=${HORIZON_DATA_PIPELINE_MAX}/${HORIZON_BUSINESS_MAX}/${HORIZON_NOTIFICATION_MAX} horizon_worker_mem=${HORIZON_WORKER_MEMORY_MB}MB"
echo "[entrypoint] Horizon supervisors with balance=auto/simple maintain at least one worker per queue pool and scale up to maxProcesses under load."

if [ "${XBOARD_ENTRYPOINT_SELF_TEST:-}" = "autotune-gate" ]; then
    exit 0
fi

redis_reachable() {
    local host port
    host=$(grep -E '^REDIS_HOST=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    port=$(grep -E '^REDIS_PORT=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    command -v redis-cli >/dev/null 2>&1 || return 1
    [ -n "$host" ] || return 1
    case "$host" in
        /*) [ -S "$host" ] && redis-cli -s "$host" ping 2>/dev/null | grep -q PONG ;;
        *)  redis-cli -h "$host" -p "${port:-6379}" ping 2>/dev/null | grep -q PONG ;;
    esac
}

if [ "$XBOARD_RUNTIME_ROLE" != legacy ]; then
    echo "[entrypoint] Skipping automatic xboard:update for dedicated role=${XBOARD_RUNTIME_ROLE}."
elif [ "${SKIP_XBOARD_UPDATE:-false}" = "true" ]; then
    echo "[entrypoint] Skipping xboard:update by explicit release-stage request."
elif [ ! -s /www/.env ] || ! grep -qE '^INSTALLED=(1|true)$' /www/.env || echo " $* " | grep -q ' xboard:install '; then
    echo "[entrypoint] Skipping xboard:update (not yet installed or running xboard:install)."
else
    if redis_reachable; then
        echo "[entrypoint] Running xboard:update (redis reachable, real drivers)..."
        php /www/artisan xboard:update --no-interaction || \
            echo "[entrypoint] WARNING: xboard:update failed; continuing so supervisor can boot anyway." >&2
    else
        echo "[entrypoint] Running xboard:update (redis not yet up, using array/sync drivers)..."
        CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
            php /www/artisan xboard:update --no-interaction || \
            echo "[entrypoint] WARNING: xboard:update failed; continuing so supervisor can boot anyway." >&2
    fi
fi

echo "[entrypoint] Starting role=${XBOARD_RUNTIME_ROLE} (caddy=${ENABLE_CADDY} web=${ENABLE_WEB} horizon=${ENABLE_HORIZON} ws=${ENABLE_WS_SERVER} scheduler=${ENABLE_SCHEDULER})..."
# Drop stale Octane/WorkerMan state files so the new master does not signal
# PIDs left over from a previous container run (causes Swoole kill EPERM).
rm -f "${OCTANE_STATE_FILE}" "${WS_PID_FILE}" "${SUPERVISOR_PID_FILE}" "${SUPERVISOR_SOCKET_FILE}" 2>/dev/null || true
for runtime_dir in /www/storage/logs /www/storage/framework /www/storage/theme /www/bootstrap/cache /www/plugins /www/.docker/.data; do
    mkdir -p "$runtime_dir"
    chown -R www:www "$runtime_dir" 2>/dev/null || true
done
if [ -n "${SESSION_FILES_PATH:-}" ]; then
    case "${SESSION_FILES_PATH}" in
        /*) ;;
        *) echo "[entrypoint] SESSION_FILES_PATH must be absolute." >&2; exit 1 ;;
    esac
    mkdir -p "${SESSION_FILES_PATH}"
    chown www:www "${SESSION_FILES_PATH}"
    chmod 0700 "${SESSION_FILES_PATH}"
fi
chown redis:redis /data 2>/dev/null || true
exec "$@"
