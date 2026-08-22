#!/bin/sh
set -eu

: "${XBOARD_RUNTIME_ROLE:?XBOARD_RUNTIME_ROLE is required}"

case "$XBOARD_RUNTIME_ROLE" in
    web)
        exec su-exec www php /www/artisan octane:start \
            --host="${OCTANE_HOST:-0.0.0.0}" \
            --port="${OCTANE_PORT:-7001}" \
            --workers="${OCTANE_WORKERS:-1}" \
            --task-workers="${OCTANE_TASK_WORKERS:-1}" \
            --max-requests="${OCTANE_MAX_REQUESTS:-500}"
        ;;
    ws)
        exec su-exec www php /www/artisan ws-server start \
            --host="${WS_HOST:-0.0.0.0}" \
            --port="${WS_PORT:-8076}"
        ;;
    horizon)
        exec su-exec www php /www/artisan horizon
        ;;
    scheduler)
        exec su-exec www php /www/artisan schedule:work
        ;;
    maintenance)
        if [ "$#" -eq 0 ]; then
            echo "[run-role] maintenance role requires an explicit command." >&2
            exit 64
        fi
        exec su-exec www "$@"
        ;;
    *)
        echo "[run-role] Unsupported XBOARD_RUNTIME_ROLE=${XBOARD_RUNTIME_ROLE}." >&2
        exit 64
        ;;
esac
