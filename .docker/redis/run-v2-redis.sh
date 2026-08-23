#!/bin/sh
set -eu

secret_file=${REDIS_PASSWORD_FILE:-/run/secrets/xboard_redis_password}
maxmemory=${XBOARD_REDIS_MAXMEMORY:-256mb}
appendonly=${XBOARD_REDIS_APPENDONLY:-yes}

if [ ! -r "$secret_file" ]; then
    echo "[redis] Password secret is missing or unreadable." >&2
    exit 78
fi

password=$(cat "$secret_file")
if [ "${#password}" -lt 32 ]; then
    echo "[redis] Password secret must contain at least 32 characters." >&2
    exit 78
fi
case "$password" in
    *[!A-Za-z0-9_-]*)
        echo "[redis] Password secret must be URL-safe base64 text." >&2
        exit 78
        ;;
esac
case "$maxmemory" in
    *[kKmMgG][bB]) maxmemory_number=${maxmemory%??} ;;
    *[kKmMgG]) maxmemory_number=${maxmemory%?} ;;
    *) maxmemory_number=$maxmemory ;;
esac
case "$maxmemory_number" in
    ''|*[!0-9]*)
        echo "[redis] XBOARD_REDIS_MAXMEMORY must be a Redis memory value." >&2
        exit 78
        ;;
esac
case "$appendonly" in
    yes|no) ;;
    *)
        echo "[redis] XBOARD_REDIS_APPENDONLY must be yes or no." >&2
        exit 78
        ;;
esac

umask 077
config_file=/tmp/xboard-redis.conf
{
    printf '%s\n' \
        'bind 0.0.0.0' \
        'protected-mode yes' \
        'port 6379' \
        'dir /data' \
        "appendonly $appendonly" \
        'appendfsync everysec' \
        'save 900 1' \
        'save 300 10' \
        'save 60 10000' \
        "maxmemory $maxmemory" \
        'maxmemory-policy noeviction' \
        "requirepass $password"
} > "$config_file"
chown redis:redis "$config_file"

exec /usr/local/bin/docker-entrypoint.sh redis-server "$config_file"
