#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_dir=$(mktemp -d)
project_name="xboard-v2-redis-test-$$"
password='v2RedisIntegrationPassword_0123456789abcdef'
docker_bin=${DOCKER_BIN:-docker}
redis_image='redis:8.4.2-alpine@sha256:e1b6db24cb4fdd89f4bc9be09f671ea3bec92fbd7042554f76c34aa2be9b59ad'

compose() {
    "$docker_bin" compose "$@"
}

cleanup() {
    compose \
        --project-name "$project_name" \
        --file "$repo_root/compose.v2.sample.yaml" \
        down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT

printf '%s\n' 'APP_ENV=testing' 'APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=' > "$temporary_dir/.env"
printf '%s\n' "$password" > "$temporary_dir/redis-password"

export XBOARD_IMAGE='ghcr.io/hao-monster/xboardme@sha256:0000000000000000000000000000000000000000000000000000000000000000'
export XBOARD_RELEASE_ID=redis-test-1
export XBOARD_HTTP_PORT=17004
export XBOARD_ENV_FILE=$temporary_dir/.env
export XBOARD_REDIS_PASSWORD_FILE=$temporary_dir/redis-password

if ! "$docker_bin" image inspect "$redis_image" >/dev/null 2>&1; then
    "$docker_bin" pull "$redis_image" >/dev/null
fi

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    up --detach --wait --pull never redis

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning config get appendonly | tail -1 | grep -qx yes

redis_container=$(compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    ps --quiet redis)
if [ -z "$redis_container" ] ||
   [ "$("$docker_bin" inspect "$redis_container" --format '{{.HostConfig.ReadonlyRootfs}}')" != true ]; then
    echo 'V2_REDIS_FAIL=root_filesystem_not_read_only' >&2
    exit 1
fi
redis_user=$("$docker_bin" top "$redis_container" -eo pid,user,comm,args |
    awk '$3 == "redis-server" { print $2; exit }')
if [ -z "$redis_user" ] || [ "$redis_user" = root ]; then
    echo 'V2_REDIS_FAIL=redis_process_privilege' >&2
    exit 1
fi

unauthenticated_result=$(compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY redis redis-cli ping 2>&1 || true)
if [ "$unauthenticated_result" = PONG ]; then
    echo 'V2_REDIS_FAIL=unauthenticated_access' >&2
    exit 1
fi

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning set v2:persistence-check ready EX 60 >/dev/null

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    restart redis >/dev/null

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    up --detach --wait --pull never redis >/dev/null

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning get v2:persistence-check | grep -qx ready

compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    down --volumes --remove-orphans >/dev/null

export XBOARD_REDIS_APPENDONLY=no
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    up --detach --wait --pull never redis
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning config get appendonly | tail -1 | grep -qx no
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning set v2:rdb-compatibility ready >/dev/null
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning save | grep -qx OK
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    restart redis >/dev/null
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    up --detach --wait --pull never redis >/dev/null
compose \
    --project-name "$project_name" \
    --file "$repo_root/compose.v2.sample.yaml" \
    exec --no-TTY --env "REDISCLI_AUTH=$password" redis \
    redis-cli --no-auth-warning get v2:rdb-compatibility | grep -qx ready

echo "V2_REDIS=PASS auth=required aof=verified rdb_compatibility=verified user=$redis_user readonly=true"
