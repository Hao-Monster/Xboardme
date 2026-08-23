#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_create >/dev/null || ! declare -F v2_require_tools >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
  # shellcheck source=v2-low-memory-common.sh
  source "$script_dir/v2-low-memory-common.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"
: "${RELEASE_IMAGE:?RELEASE_IMAGE is required}"
: "${MAINTENANCE_PORT:=7003}"

v2_require_tools
v2_validate_release_id
[[ "$EXPECTED_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || v2_fail invalid_expected_sha
[[ "$RELEASE_IMAGE" =~ ^ghcr\.io/[^[:space:]@]+@sha256:[0-9a-f]{64}$ ]] || v2_fail release_image_not_immutable
[[ "$MAINTENANCE_PORT" =~ ^[0-9]+$ ]] && ((MAINTENANCE_PORT >= 1 && MAINTENANCE_PORT <= 65535)) || v2_fail invalid_maintenance_port

v2_find_workdir
v2_acquire_lock

release_root="$V2_WORKDIR/.codex-v2-release"
release_dir="$release_root/$RELEASE_ID"
[[ ! -e "$release_dir" && ! -L "$release_dir" ]] || v2_fail release_already_exists
mkdir -p -- "$release_dir/backups" "$release_dir/.docker/caddy" "$release_dir/.docker/redis"
chmod 700 "$release_dir" "$release_dir/backups" "$release_dir/.docker" "$release_dir/.docker/caddy" "$release_dir/.docker/redis"
cleanup_release=1
payload_container=''
compat_container=''
cleanup_prepare() {
  status=$?
  [[ -z "$payload_container" ]] || docker rm -f "$payload_container" >/dev/null 2>&1 || true
  [[ -z "$compat_container" ]] || docker rm -f "$compat_container" >/dev/null 2>&1 || true
  if ((status != 0 && cleanup_release == 1)); then
    rm -rf -- "$release_dir"
  fi
  exit "$status"
}
trap cleanup_prepare EXIT

docker pull "$RELEASE_IMAGE" >/dev/null
image_revision=$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$RELEASE_IMAGE" 2>/dev/null || true)
[[ "$image_revision" == "$EXPECTED_RELEASE_SHA" ]] || v2_fail release_image_revision_mismatch

mapfile -t caddy_candidates < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' /etc/caddy 2>/dev/null || true
)
((${#caddy_candidates[@]} == 1)) || v2_fail "caddy_config_ambiguous:${#caddy_candidates[@]}"
caddy_config=${caddy_candidates[0]}
[[ -f "$caddy_config" && ! -L "$caddy_config" ]] || v2_fail invalid_caddy_config
caddy_config=$(realpath -e -- "$caddy_config")
mapfile -t active_ports < <(
  grep -Eo 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "$caddy_config" |
    awk '{print $2}' | sed 's/^127\.0\.0\.1://' | sort -u
)
((${#active_ports[@]} == 1)) || v2_fail "active_port_ambiguous:${#active_ports[@]}"
active_port=${active_ports[0]}
[[ "$active_port" != "$MAINTENANCE_PORT" ]] || v2_fail maintenance_port_matches_active
if ss -ltnH | awk '{print $4}' | grep -Eq "(^|:)$MAINTENANCE_PORT$"; then
  v2_fail maintenance_port_in_use
fi

mapfile -t active_web_ids < <(
  for id in $(docker ps -q); do
    if docker port "$id" 7001/tcp 2>/dev/null | grep -qx "127.0.0.1:$active_port"; then
      printf '%s\n' "$id"
    fi
  done
)
((${#active_web_ids[@]} == 1)) || v2_fail "active_web_ambiguous:${#active_web_ids[@]}"
legacy_web_id=$(docker inspect -f '{{.Id}}' "${active_web_ids[0]}")
legacy_anchor_id=$V2_ANCHOR_DISCOVERY_ID
[[ "$legacy_web_id" != "$legacy_anchor_id" ]] || v2_fail split_runtime_foundation_required

mapfile -t legacy_horizon_ids < <(docker ps -q --filter label=codex.xboard.release=true --filter label=codex.xboard.release.role=horizon)
mapfile -t legacy_scheduler_ids < <(docker ps -q --filter label=codex.xboard.release=true --filter label=codex.xboard.release.role=scheduler)
((${#legacy_horizon_ids[@]} == 1)) || v2_fail "legacy_horizon_ambiguous:${#legacy_horizon_ids[@]}"
((${#legacy_scheduler_ids[@]} == 1)) || v2_fail "legacy_scheduler_ambiguous:${#legacy_scheduler_ids[@]}"
legacy_horizon_id=$(docker inspect -f '{{.Id}}' "${legacy_horizon_ids[0]}")
legacy_scheduler_id=$(docker inspect -f '{{.Id}}' "${legacy_scheduler_ids[0]}")

for pair in \
  "$legacy_anchor_id:anchor" \
  "$legacy_web_id:web" \
  "$legacy_horizon_id:horizon" \
  "$legacy_scheduler_id:scheduler"; do
  container_id=${pair%%:*}
  label=${pair#*:}
  [[ "$(docker inspect -f '{{.State.Running}}' "$container_id")" == true ]] || v2_fail "legacy_container_not_running:$label"
done
legacy_release_sha=''
for container_id in "$legacy_web_id" "$legacy_horizon_id" "$legacy_scheduler_id"; do
  revision=$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$container_id")
  [[ "$revision" =~ ^[0-9a-f]{40}$ ]] || v2_fail invalid_legacy_release_revision
  if [[ -z "$legacy_release_sha" ]]; then
    legacy_release_sha=$revision
  fi
  [[ "$revision" == "$legacy_release_sha" ]] || v2_fail legacy_release_revision_mismatch
done

mount_value() {
  local container=$1 destination=$2 field=$3
  docker inspect "$container" | jq -er --arg destination "$destination" --arg field "$field" '
    .[0].Mounts
    | map(select(.Destination == $destination))
    | if length == 1 then .[0][$field] else error("mount mismatch") end
  '
}

env_file=$(mount_value "$legacy_anchor_id" /www/.env Source)
app_data_path=$(mount_value "$legacy_anchor_id" /www/.docker/.data Source)
app_logs_path=$(mount_value "$legacy_anchor_id" /www/storage/logs Source)
app_theme_path=$(mount_value "$legacy_anchor_id" /www/storage/theme Source)
app_knowledge_path=$(mount_value "$legacy_anchor_id" /www/storage/app/knowledge-attachments Source)
app_plugins_path=$(mount_value "$legacy_anchor_id" /www/plugins Source)
redis_mount_type=$(mount_value "$legacy_anchor_id" /data Type)
redis_volume_name=$(mount_value "$legacy_anchor_id" /data Name)
[[ "$redis_mount_type" == volume ]] || v2_fail redis_is_not_a_named_volume
docker volume inspect "$redis_volume_name" >/dev/null

for path in "$app_data_path" "$app_logs_path" "$app_theme_path" "$app_knowledge_path" "$app_plugins_path"; do
  [[ "$path" == /* && -d "$path" && ! -L "$path" ]] || v2_fail invalid_authoritative_directory
done
[[ "$env_file" == /* && -f "$env_file" && ! -L "$env_file" ]] || v2_fail invalid_authoritative_env
env_file=$(realpath -e -- "$env_file")
app_data_path=$(realpath -e -- "$app_data_path")
app_logs_path=$(realpath -e -- "$app_logs_path")
app_theme_path=$(realpath -e -- "$app_theme_path")
app_knowledge_path=$(realpath -e -- "$app_knowledge_path")
app_plugins_path=$(realpath -e -- "$app_plugins_path")
for path in "$V2_WORKDIR" "$caddy_config" "$env_file" "$app_data_path" "$app_logs_path" \
  "$app_theme_path" "$app_knowledge_path" "$app_plugins_path"; do
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || v2_fail unsupported_production_path
done
[[ "$redis_volume_name" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]+$ ]] || v2_fail invalid_redis_volume_name

for container_id in "$legacy_web_id" "$legacy_horizon_id" "$legacy_scheduler_id"; do
  for destination in /www/.env /www/.docker/.data /www/storage/logs /www/storage/theme /www/storage/app/knowledge-attachments /www/plugins; do
    anchor_source=$(mount_value "$legacy_anchor_id" "$destination" Source)
    role_source=$(mount_value "$container_id" "$destination" Source)
    [[ "$anchor_source" == "$role_source" ]] || v2_fail "authoritative_mount_mismatch:$destination"
  done
done

total_memory_kib=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
available_disk_kib=$(df -Pk "$V2_WORKDIR" | awk 'NR == 2 {print $4}')
((total_memory_kib >= 3500000)) || v2_fail insufficient_total_memory
((available_disk_kib >= 5242880)) || v2_fail insufficient_disk_space
docker exec "$legacy_anchor_id" redis-cli -s /data/redis.sock ping | grep -qx PONG
docker exec "$legacy_anchor_id" redis-cli -s /data/redis.sock config get appendonly | tail -1 | grep -qx no

payload_container=$(docker create "$RELEASE_IMAGE")
docker cp "$payload_container:/www/compose.v2.sample.yaml" "$release_dir/compose.v2.sample.yaml"
docker cp "$payload_container:/www/compose.v2.production.yaml" "$release_dir/compose.v2.production.yaml"
docker cp "$payload_container:/www/.docker/caddy/Caddyfile.v2" "$release_dir/.docker/caddy/Caddyfile.v2"
docker cp "$payload_container:/www/.docker/caddy/Caddyfile.maintenance" "$release_dir/.docker/caddy/Caddyfile.maintenance"
docker cp "$payload_container:/www/.docker/redis/run-v2-redis.sh" "$release_dir/.docker/redis/run-v2-redis.sh"
docker cp "$payload_container:/www/.docker/redis/healthcheck-v2-redis.sh" "$release_dir/.docker/redis/healthcheck-v2-redis.sh"
docker cp "$payload_container:/www/.github/scripts/validate-v2-compose.php" "$release_dir/validate-v2-compose.php"
docker rm "$payload_container" >/dev/null
payload_container=''
chmod 600 "$release_dir/compose.v2.sample.yaml" "$release_dir/compose.v2.production.yaml" \
  "$release_dir/.docker/caddy/Caddyfile.v2" "$release_dir/.docker/caddy/Caddyfile.maintenance" \
  "$release_dir/.docker/redis/run-v2-redis.sh" "$release_dir/.docker/redis/healthcheck-v2-redis.sh" \
  "$release_dir/validate-v2-compose.php"

redis_password_file="$release_dir/redis-password"
openssl rand -base64 48 | tr -d '/+=' > "$redis_password_file"
printf '\n' >> "$redis_password_file"
chown 0:1000 "$redis_password_file"
chmod 440 "$redis_password_file"
[[ "$(tr -d '\r\n' < "$redis_password_file")" =~ ^[A-Za-z0-9_-]{32,}$ ]] || v2_fail generated_redis_secret_invalid

project_name="xboard-v2-$RELEASE_ID"
runtime_env="$release_dir/runtime.env"
for value in "$RELEASE_IMAGE" "$RELEASE_ID" "$active_port" "$env_file" "$app_data_path" "$app_logs_path" \
  "$app_theme_path" "$app_knowledge_path" "$app_plugins_path" "$redis_password_file" "$redis_volume_name"; do
  [[ "$value" != *$'\n'* && "$value" != *$'\r'* && "$value" != *'#'* ]] || v2_fail unsafe_runtime_environment_value
done
{
  printf 'XBOARD_IMAGE=%s\n' "$RELEASE_IMAGE"
  printf 'XBOARD_RELEASE_ID=%s\n' "$RELEASE_ID"
  printf 'XBOARD_HTTP_PORT=%s\n' "$active_port"
  printf 'XBOARD_ENV_FILE=%s\n' "$env_file"
  printf 'XBOARD_APP_DATA_PATH=%s\n' "$app_data_path"
  printf 'XBOARD_APP_LOGS_PATH=%s\n' "$app_logs_path"
  printf 'XBOARD_APP_THEME_PATH=%s\n' "$app_theme_path"
  printf 'XBOARD_APP_KNOWLEDGE_PATH=%s\n' "$app_knowledge_path"
  printf 'XBOARD_APP_PLUGINS_PATH=%s\n' "$app_plugins_path"
  printf 'XBOARD_REDIS_PASSWORD_FILE=%s\n' "$redis_password_file"
  printf 'XBOARD_REDIS_VOLUME_NAME=%s\n' "$redis_volume_name"
  printf 'XBOARD_REDIS_APPENDONLY=no\n'
} > "$runtime_env"
chmod 600 "$runtime_env"

# Read by v2_compose from the sourced helper.
# shellcheck disable=SC2034
V2_RELEASE_DIR=$release_dir
# Read by v2_compose from the sourced helper.
# shellcheck disable=SC2034
PROJECT_NAME=$project_name
compose_json=$(mktemp "$release_dir/.compose-validation.XXXXXX")
chmod 600 "$compose_json"
v2_compose config --format json > "$compose_json"
docker run --rm --entrypoint php \
  --volume "$release_dir:/release:ro" \
  "$RELEASE_IMAGE" /release/validate-v2-compose.php "/release/$(basename -- "$compose_json")" "$RELEASE_IMAGE" "$active_port" production

v2_validate_rendered_production_compose \
  "$compose_json" "$env_file" "$app_data_path" "$app_logs_path" "$app_theme_path" \
  "$app_knowledge_path" "$app_plugins_path" "$redis_volume_name" || \
  v2_fail rendered_production_compose_invalid

v2_compose pull --quiet
maintenance_image=$(jq -er '.services.edge.image' "$compose_json")
redis_image=$(jq -er '.services.redis.image' "$compose_json")
legacy_image_id=$(docker inspect -f '{{.Image}}' "$legacy_anchor_id")
[[ "$legacy_image_id" =~ ^sha256:[0-9a-f]{64}$ ]] || v2_fail invalid_legacy_image_id
docker run --rm \
  --network none \
  --read-only \
  --security-opt no-new-privileges:true \
  --user 1000:1000 \
  --memory 64m \
  --pids-limit 32 \
  --volume "$redis_password_file:/run/secrets/xboard_redis_password:ro" \
  --entrypoint /bin/sh \
  "$RELEASE_IMAGE" -eu -c 'test -r /run/secrets/xboard_redis_password; test "$(wc -c < /run/secrets/xboard_redis_password)" -ge 32'

# Prove that the retained legacy Redis binary can read an RDB generated by
# the pinned V2 Redis before production data is ever opened by the new owner.
compat_dir="$release_dir/redis-rdb-compatibility"
compat_container="xboard-v2-rdb-compat-$RELEASE_ID"
mkdir -p -- "$compat_dir"
chmod 700 "$compat_dir"
docker run --detach \
  --name "$compat_container" \
  --network none \
  --read-only \
  --security-opt no-new-privileges:true \
  --memory 128m \
  --pids-limit 64 \
  --tmpfs /tmp:size=8m,mode=1777 \
  --volume "$compat_dir:/data" \
  --entrypoint redis-server \
  "$redis_image" --dir /data --appendonly no --save '' >/dev/null
compat_ready=0
for _ in {1..15}; do
  if docker exec "$compat_container" redis-cli ping 2>/dev/null | grep -qx PONG; then
    compat_ready=1
    break
  fi
  sleep 1
done
((compat_ready == 1)) || v2_fail v2_rdb_generator_unhealthy
docker exec "$compat_container" redis-cli set v2:rdb-compatibility verified >/dev/null
docker exec "$compat_container" redis-cli SAVE | grep -qx OK
docker stop --time 10 "$compat_container" >/dev/null
docker run --rm \
  --network none \
  --read-only \
  --security-opt no-new-privileges:true \
  --memory 128m \
  --pids-limit 64 \
  --volume "$compat_dir:/compat:ro" \
  --entrypoint redis-check-rdb \
  "$legacy_image_id" /compat/dump.rdb >/dev/null
docker rm "$compat_container" >/dev/null
compat_container=''
rm -rf -- "$compat_dir"
rm -f -- "$compose_json"
caddy_backup="$release_dir/backups/caddy-before-v2.conf"
cp -p -- "$caddy_config" "$caddy_backup"
chmod 600 "$caddy_backup"
caddy validate --config "$caddy_backup" --adapter caddyfile >/dev/null

state_file="$release_dir/state.json"
release_state_create "$state_file" \
  v2_schema_version "$V2_RELEASE_STATE_SCHEMA" \
  release_id "$RELEASE_ID" \
  release_sha "$EXPECTED_RELEASE_SHA" \
  release_image "$RELEASE_IMAGE" \
  project_name "$project_name" \
  workdir "$V2_WORKDIR" \
  release_dir "$release_dir" \
  active_port "$active_port" \
  maintenance_port "$MAINTENANCE_PORT" \
  caddy_config "$caddy_config" \
  caddy_backup "$caddy_backup" \
  legacy_anchor_id "$legacy_anchor_id" \
  legacy_web_id "$legacy_web_id" \
  legacy_horizon_id "$legacy_horizon_id" \
  legacy_scheduler_id "$legacy_scheduler_id" \
  legacy_release_sha "$legacy_release_sha" \
  redis_password_file "$redis_password_file" \
  redis_volume_name "$redis_volume_name" \
  app_data_path "$app_data_path" \
  maintenance_image "$maintenance_image" \
  maintenance_container "xboard-v2-maintenance-$RELEASE_ID" \
  rdb_compatibility_verified true \
  rdb_compatibility_verified_at "$(date -u +%FT%TZ)" \
  traffic_state prepared \
  prepared_at "$(date -u +%FT%TZ)"

cleanup_release=0
trap - EXIT
echo "V2_PREPARE=PASS id=$RELEASE_ID sha=$EXPECTED_RELEASE_SHA active_port=$active_port maintenance_port=$MAINTENANCE_PORT traffic_state prepared"
echo 'V2_PREPARE_MUTATION=release_artifacts_image_pull_and_isolated_compatibility_checks_only'
