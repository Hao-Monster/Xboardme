#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_dir=$(mktemp -d)
docker_bin=${DOCKER_BIN:-docker}
php_bin=${PHP_BIN:-php}

compose() {
    "$docker_bin" compose "$@"
}

application_image='ghcr.io/hao-monster/xboardme@sha256:0000000000000000000000000000000000000000000000000000000000000000'
printf '%s\n' 'APP_ENV=testing' 'APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=' > "$temporary_dir/.env"
printf '%s\n' 'v2TopologyValidationPassword_0123456789abcdef' > "$temporary_dir/redis-password"

export XBOARD_IMAGE=$application_image
export XBOARD_RELEASE_ID=validation-1
export XBOARD_HTTP_PORT=17003
export XBOARD_ENV_FILE=$temporary_dir/.env
export XBOARD_REDIS_PASSWORD_FILE=$temporary_dir/redis-password

cleanup() {
    compose \
        --project-name xboard-v2-validation \
        --file "$repo_root/compose.v2.sample.yaml" \
        down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT

compose \
    --project-name xboard-v2-validation \
    --file "$repo_root/compose.v2.sample.yaml" \
    --profile maintenance \
    --profile owners \
    config --format json > "$temporary_dir/compose.json"

"$php_bin" "$repo_root/.github/scripts/validate-v2-compose.php" \
    "$temporary_dir/compose.json" "$application_image"

for directory in data logs theme knowledge plugins; do
    mkdir -p "$temporary_dir/$directory"
done
export XBOARD_APP_DATA_PATH=$temporary_dir/data
export XBOARD_APP_LOGS_PATH=$temporary_dir/logs
export XBOARD_APP_THEME_PATH=$temporary_dir/theme
export XBOARD_APP_KNOWLEDGE_PATH=$temporary_dir/knowledge
export XBOARD_APP_PLUGINS_PATH=$temporary_dir/plugins
export XBOARD_REDIS_VOLUME_NAME=xboard-v2-validation-external-redis

compose \
    --project-name xboard-v2-validation \
    --file "$repo_root/compose.v2.sample.yaml" \
    --file "$repo_root/compose.v2.production.yaml" \
    --profile maintenance \
    --profile owners \
    config --format json > "$temporary_dir/compose-production.json"

"$php_bin" "$repo_root/.github/scripts/validate-v2-compose.php" \
    "$temporary_dir/compose-production.json" "$application_image" 17003 production

for scheduler_memory_limit in 128m 255m; do
    export XBOARD_SCHEDULER_MEMORY_LIMIT=$scheduler_memory_limit
    compose \
        --project-name xboard-v2-validation \
        --file "$repo_root/compose.v2.sample.yaml" \
        --file "$repo_root/compose.v2.production.yaml" \
        --profile maintenance \
        --profile owners \
        config --format json > "$temporary_dir/compose-production-undersized-scheduler.json"
    if "$php_bin" "$repo_root/.github/scripts/validate-v2-compose.php" \
        "$temporary_dir/compose-production-undersized-scheduler.json" "$application_image" 17003 production; then
        echo "Expected production compose validation to reject scheduler memory limit $scheduler_memory_limit." >&2
        exit 1
    fi
done
unset XBOARD_SCHEDULER_MEMORY_LIMIT

compose \
    --project-name xboard-v2-validation \
    --file "$repo_root/compose.v2.sample.yaml" \
    run --rm --no-deps edge caddy validate --config /etc/caddy/Caddyfile
