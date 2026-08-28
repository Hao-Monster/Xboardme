# shellcheck shell=bash
# Shared helpers for the maintenance-window V2 activation. This file is
# concatenated after release-state.sh and before an action script over SSH.

# release-state.sh owns the numeric JSON envelope schema_version. This
# separate string version guards the V2 payload contract inside that envelope.
readonly V2_RELEASE_STATE_SCHEMA=1

v2_fail() {
  local reason=$1
  echo "V2_FAIL=$reason" >&2
  return 1
}

v2_require_tools() {
  local tool
  ((EUID == 0)) || v2_fail root_required || return 1
  for tool in caddy curl docker flock jq openssl realpath ss; do
    command -v "$tool" >/dev/null 2>&1 || v2_fail "tool_missing:$tool" || return 1
  done
  docker compose version >/dev/null 2>&1 || v2_fail compose_plugin_missing || return 1
  release_state_require_tool
}

v2_validate_release_id() {
  [[ "${RELEASE_ID:-}" =~ ^[0-9]+-[0-9]+$ ]] || v2_fail invalid_release_id
}

v2_find_workdir() {
  local -a anchor_ids v2_edge_ids v2_workdirs
  local candidate project release_workdir active_release_id
  mapfile -t anchor_ids < <(docker ps -aq --filter label=com.docker.compose.service=xboard)
  if ((${#anchor_ids[@]} == 1)); then
    candidate=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${anchor_ids[0]}")
    V2_ANCHOR_DISCOVERY_ID=$(docker inspect -f '{{.Id}}' "${anchor_ids[0]}")
  elif ((${#anchor_ids[@]} == 0)); then
    mapfile -t v2_edge_ids < <(docker ps -q --filter label=com.docker.compose.service=edge)
    ((${#v2_edge_ids[@]} == 1)) || {
      v2_fail "active_v2_edge_ambiguous:${#v2_edge_ids[@]}"
      return 1
    }
    project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "${v2_edge_ids[0]}")
    mapfile -t v2_workdirs < <(
      docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "${v2_edge_ids[0]}"
    )
    ((${#v2_workdirs[@]} == 1)) || {
      v2_fail release_workdir_ambiguous
      return 1
    }
    release_workdir=${v2_workdirs[0]}
    active_release_id=$(basename -- "$release_workdir")
    [[ "$active_release_id" =~ ^[0-9]+-[0-9]+$ &&
       "$project" == "xboard-v2-$active_release_id" &&
       "$(basename -- "$(dirname -- "$release_workdir")")" == .codex-v2-release ]] || {
      v2_fail invalid_release_workdir
      return 1
    }
    candidate=$(dirname -- "$(dirname -- "$release_workdir")")
    # Consumed by prepare after sourcing this helper.
    # shellcheck disable=SC2034
    V2_ANCHOR_DISCOVERY_ID=''
  else
    v2_fail "legacy_anchor_ambiguous:${#anchor_ids[@]}"
    return 1
  fi
  [[ "$candidate" == /* && -d "$candidate" && ! -L "$candidate" ]] || {
    v2_fail invalid_workdir
    return 1
  }
  V2_WORKDIR=$(realpath -e -- "$candidate")
}

v2_acquire_lock() {
  local lock_root="$V2_WORKDIR/.codex-v2-release"
  mkdir -p -- "$lock_root"
  chmod 700 "$lock_root"
  exec 9>"$lock_root/deploy.lock"
  chmod 600 "$lock_root/deploy.lock"
  flock -n 9 || {
    v2_fail deployment_locked
    return 1
  }
}

v2_open_release() {
  v2_validate_release_id
  v2_find_workdir
  v2_acquire_lock

  V2_RELEASE_DIR="$V2_WORKDIR/.codex-v2-release/$RELEASE_ID"
  [[ -d "$V2_RELEASE_DIR" && ! -L "$V2_RELEASE_DIR" ]] || {
    v2_fail release_directory_missing
    return 1
  }
  V2_RELEASE_DIR=$(realpath -e -- "$V2_RELEASE_DIR")
  V2_STATE_FILE=$(release_state_open "$V2_RELEASE_DIR") || {
    v2_fail release_state_missing
    return 1
  }
  [[ "$(stat -c '%u:%a' "$V2_RELEASE_DIR")" == 0:700 ]] || {
    v2_fail insecure_release_directory
    return 1
  }
  [[ "$(stat -c '%u:%a' "$V2_STATE_FILE")" == 0:600 ]] || {
    v2_fail insecure_release_state
    return 1
  }
  v2_load_state
}

v2_load_state() {
  STATE_SCHEMA_VERSION=$(release_state_get "$V2_STATE_FILE" v2_schema_version)
  STATE_RELEASE_ID=$(release_state_get "$V2_STATE_FILE" release_id)
  RELEASE_SHA=$(release_state_get "$V2_STATE_FILE" release_sha)
  RELEASE_IMAGE=$(release_state_get "$V2_STATE_FILE" release_image)
  PROJECT_NAME=$(release_state_get "$V2_STATE_FILE" project_name)
  ACTIVE_PORT=$(release_state_get "$V2_STATE_FILE" active_port)
  MAINTENANCE_PORT=$(release_state_get "$V2_STATE_FILE" maintenance_port)
  CADDY_CONFIG=$(release_state_get "$V2_STATE_FILE" caddy_config)
  CADDY_BACKUP=$(release_state_get "$V2_STATE_FILE" caddy_backup)
  LEGACY_ANCHOR_ID=$(release_state_get "$V2_STATE_FILE" legacy_anchor_id)
  LEGACY_WEB_ID=$(release_state_get "$V2_STATE_FILE" legacy_web_id)
  LEGACY_HORIZON_ID=$(release_state_get "$V2_STATE_FILE" legacy_horizon_id)
  LEGACY_SCHEDULER_ID=$(release_state_get "$V2_STATE_FILE" legacy_scheduler_id)
  LEGACY_TOPOLOGY=$(release_state_get_optional "$V2_STATE_FILE" legacy_topology)
  LEGACY_WS_ID=$(release_state_get_optional "$V2_STATE_FILE" legacy_ws_id)
  LEGACY_EDGE_ID=$(release_state_get_optional "$V2_STATE_FILE" legacy_edge_id)
  [[ -n "$LEGACY_TOPOLOGY" ]] || LEGACY_TOPOLOGY=legacy
  REDIS_PASSWORD_FILE=$(release_state_get "$V2_STATE_FILE" redis_password_file)
  REDIS_VOLUME_NAME=$(release_state_get "$V2_STATE_FILE" redis_volume_name)
  APP_DATA_PATH=$(release_state_get "$V2_STATE_FILE" app_data_path)
  TRAFFIC_STATE=$(release_state_get "$V2_STATE_FILE" traffic_state)
  MAINTENANCE_CONTAINER=$(release_state_get "$V2_STATE_FILE" maintenance_container)

  [[ "$STATE_SCHEMA_VERSION" == "$V2_RELEASE_STATE_SCHEMA" ]] || v2_fail release_state_schema_mismatch
  [[ "$STATE_RELEASE_ID" == "$RELEASE_ID" ]] || v2_fail release_identity_mismatch
  [[ "$V2_WORKDIR" == "$(release_state_get "$V2_STATE_FILE" workdir)" ]] || v2_fail workdir_mismatch
  [[ "$V2_RELEASE_DIR" == "$(release_state_get "$V2_STATE_FILE" release_dir)" ]] || v2_fail release_directory_mismatch
  [[ "$ACTIVE_PORT" =~ ^[0-9]+$ && "$MAINTENANCE_PORT" =~ ^[0-9]+$ && "$ACTIVE_PORT" != "$MAINTENANCE_PORT" ]] || v2_fail invalid_ports
  [[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || v2_fail invalid_release_sha
  [[ "$RELEASE_IMAGE" =~ ^[^[:space:]@]+@sha256:[0-9a-f]{64}$ ]] || v2_fail invalid_release_image
  case "$LEGACY_TOPOLOGY" in
    legacy)
      [[ -z "$LEGACY_WS_ID" && -z "$LEGACY_EDGE_ID" ]] || {
        v2_fail invalid_legacy_topology_state
        return 1
      }
      ;;
    v2)
      [[ -n "$LEGACY_WS_ID" && -n "$LEGACY_EDGE_ID" ]] || {
        v2_fail incomplete_v2_legacy_state
        return 1
      }
      ;;
    *)
      v2_fail invalid_legacy_topology
      return 1
      ;;
  esac
  [[ -f "$REDIS_PASSWORD_FILE" && ! -L "$REDIS_PASSWORD_FILE" ]] || v2_fail redis_secret_missing
  [[ "$(stat -c '%u:%g:%a' "$REDIS_PASSWORD_FILE")" == 0:1000:440 ]] || v2_fail redis_secret_permissions
  [[ "$(stat -c '%u:%a' "$V2_RELEASE_DIR/runtime.env")" == 0:600 ]] || v2_fail runtime_environment_permissions
  [[ "$(stat -c '%u:%a' "$CADDY_BACKUP")" == 0:600 ]] || v2_fail caddy_backup_permissions
  if [[ -n "${EXPECTED_RELEASE_SHA:-}" && "$EXPECTED_RELEASE_SHA" != "$RELEASE_SHA" ]]; then
    v2_fail release_sha_mismatch
    return 1
  fi
}

v2_compose() {
  docker compose \
    --project-name "$PROJECT_NAME" \
    --project-directory "$V2_RELEASE_DIR" \
    --env-file "$V2_RELEASE_DIR/runtime.env" \
    --file "$V2_RELEASE_DIR/compose.v2.sample.yaml" \
    --file "$V2_RELEASE_DIR/compose.v2.production.yaml" \
    --profile maintenance \
    --profile owners \
    "$@"
}

v2_validate_rendered_production_compose() {
  local compose_json=$1 env_file=$2 data_path=$3 logs_path=$4 theme_path=$5
  local knowledge_path=$6 plugins_path=$7 redis_volume_name=$8

  jq -e \
    --arg env "$env_file" \
    --arg data "$data_path" \
    --arg logs "$logs_path" \
    --arg theme "$theme_path" \
    --arg knowledge "$knowledge_path" \
    --arg plugins "$plugins_path" \
    --arg redis_volume "$redis_volume_name" '
      def expected: [
        {type:"bind", source:$env, target:"/www/.env", read_only:true},
        {type:"bind", source:$data, target:"/www/.docker/.data"},
        {type:"bind", source:$logs, target:"/www/storage/logs"},
        {type:"bind", source:$theme, target:"/www/storage/theme"},
        {type:"bind", source:$knowledge, target:"/www/storage/app/knowledge-attachments"},
        {type:"bind", source:$plugins, target:"/www/plugins"}
      ];
      . as $root
      | (
          all(
            ["web","ws","horizon","scheduler","maintenance"][];
            . as $role
            | ($root.services[$role].volumes
                | map({type, source, target, read_only})
                | map(with_entries(select(.value != null)))
                | sort_by(.target)) == (expected | sort_by(.target))
          )
          and ($root.services.redis.environment.XBOARD_REDIS_APPENDONLY == "no")
          and ([$root.volumes[] | select(.name == $redis_volume and .external == true)] | length == 1)
        )
    ' "$compose_json" >/dev/null
}

v2_service_id() {
  local id
  id=$(v2_compose ps --quiet "$1")
  [[ -n "$id" ]] || return 0
  docker inspect -f '{{.Id}}' "$id"
}

v2_service_healthy() {
  local service=$1 id state health revision
  id=$(v2_service_id "$service")
  [[ -n "$id" ]] || return 1
  state=$(docker inspect -f '{{.State.Running}}' "$id" 2>/dev/null || true)
  [[ "$state" == true ]] || return 1
  health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id")
  [[ "$health" == healthy ]] || return 1
  revision=$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$id")
  if [[ "$service" != edge && "$service" != redis && "$revision" != "$RELEASE_SHA" ]]; then
    return 1
  fi
}

v2_loopback_http_ready() {
  local port=$1
  curl --silent --show-error --fail --max-time 3 "http://127.0.0.1:$port/" >/dev/null
}

v2_wait_loopback_http() {
  local port=$1 attempts=${2:-30}
  local attempt
  for ((attempt = 1; attempt <= attempts; attempt++)); do
    if v2_loopback_http_ready "$port"; then
      return 0
    fi
    sleep 2
  done
  v2_fail edge_loopback_unhealthy
}

v2_wait_service_healthy() {
  local service=$1 attempts=${2:-30}
  local attempt
  for ((attempt = 1; attempt <= attempts; attempt++)); do
    if v2_service_healthy "$service"; then
      return 0
    fi
    sleep 2
  done
  v2_fail "service_unhealthy:$service"
}

v2_assert_recorded_container() {
  local id=$1 label=$2
  [[ -n "$id" && "$(docker inspect -f '{{.Id}}' "$id" 2>/dev/null || true)" == "$id" ]] || {
    v2_fail "recorded_container_missing:$label"
    return 1
  }
}

v2_discover_active_v2_runtime() {
  local active_edge_id=$1 project service service_id
  local -a service_ids discovered_ids unique_ids

  project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$active_edge_id")
  [[ -n "$project" && "$project" != '<no value>' ]] || {
    v2_fail active_v2_project_missing
    return 1
  }
  for service in redis web ws edge horizon scheduler; do
    mapfile -t service_ids < <(
      docker ps -q \
        --filter "label=com.docker.compose.project=$project" \
        --filter "label=com.docker.compose.service=$service"
    )
    ((${#service_ids[@]} == 1)) || {
      v2_fail "active_v2_service_ambiguous:$service:${#service_ids[@]}"
      return 1
    }
    service_id=$(docker inspect -f '{{.Id}}' "${service_ids[0]}")
    case "$service" in
      redis) V2_DISCOVERED_REDIS_ID=$service_id ;;
      web) V2_DISCOVERED_WEB_ID=$service_id ;;
      ws) V2_DISCOVERED_WS_ID=$service_id ;;
      edge) V2_DISCOVERED_EDGE_ID=$service_id ;;
      horizon) V2_DISCOVERED_HORIZON_ID=$service_id ;;
      scheduler) V2_DISCOVERED_SCHEDULER_ID=$service_id ;;
    esac
  done
  discovered_ids=(
    "$V2_DISCOVERED_REDIS_ID" "$V2_DISCOVERED_WEB_ID" "$V2_DISCOVERED_WS_ID"
    "$V2_DISCOVERED_EDGE_ID" "$V2_DISCOVERED_HORIZON_ID" "$V2_DISCOVERED_SCHEDULER_ID"
  )
  mapfile -t unique_ids < <(printf '%s\n' "${discovered_ids[@]}" | sort -u)
  ((${#unique_ids[@]} == ${#discovered_ids[@]})) || {
    v2_fail active_v2_service_identity_collision
    return 1
  }
  [[ "$V2_DISCOVERED_EDGE_ID" == "$active_edge_id" ]] || {
    v2_fail active_v2_edge_identity_mismatch
    return 1
  }
  V2_DISCOVERED_PROJECT=$project
}

v2_assert_legacy_identity() {
  v2_assert_recorded_container "$LEGACY_ANCHOR_ID" anchor || return 1
  v2_assert_recorded_container "$LEGACY_WEB_ID" web || return 1
  v2_assert_recorded_container "$LEGACY_HORIZON_ID" horizon || return 1
  v2_assert_recorded_container "$LEGACY_SCHEDULER_ID" scheduler || return 1
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    v2_assert_recorded_container "$LEGACY_WS_ID" ws || return 1
    v2_assert_recorded_container "$LEGACY_EDGE_ID" edge || return 1
  fi
}

v2_legacy_ids() {
  printf '%s\n' "$LEGACY_ANCHOR_ID" "$LEGACY_WEB_ID" "$LEGACY_HORIZON_ID" "$LEGACY_SCHEDULER_ID"
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    printf '%s\n' "$LEGACY_WS_ID" "$LEGACY_EDGE_ID"
  fi
}

v2_container_running() {
  [[ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || true)" == true ]]
}

v2_validate_caddy_file() {
  local path=$1
  [[ "$path" == /* && -f "$path" && ! -L "$path" ]] || return 1
  caddy validate --config "$path" --adapter caddyfile >/dev/null
}

v2_caddy_reference_count() {
  local path=$1 port=$2
  { grep -o "127\\.0\\.0\\.1:$port" "$path" || true; } | wc -l | tr -d '[:space:]'
}

v2_replace_caddy_upstream() {
  local from_port=$1 to_port=$2 source_count target_count candidate original status=0
  source_count=$(v2_caddy_reference_count "$CADDY_CONFIG" "$from_port")
  target_count=$(v2_caddy_reference_count "$CADDY_CONFIG" "$to_port")
  [[ "$source_count" == 1 && "$target_count" == 0 ]] || {
    v2_fail "caddy_upstream_mismatch:from=$source_count,to=$target_count"
    return 1
  }

  candidate=$(mktemp "${CADDY_CONFIG}.v2-candidate.XXXXXX")
  original=$(mktemp "${CADDY_CONFIG}.v2-original.XXXXXX")
  cp -p -- "$CADDY_CONFIG" "$candidate"
  cp -p -- "$CADDY_CONFIG" "$original"
  sed -i "s/127\\.0\\.0\\.1:$from_port/127.0.0.1:$to_port/" "$candidate"
  v2_validate_caddy_file "$candidate" || status=$?
  if ((status == 0)); then
    chmod 0644 "$candidate"
    mv -f -- "$candidate" "$CADDY_CONFIG"
    if ! systemctl reload caddy || [[ "$(systemctl is-active caddy)" != active ]]; then
      status=1
    fi
  fi
  if ((status != 0)); then
    cp -p -- "$original" "$CADDY_CONFIG"
    chmod 0644 "$CADDY_CONFIG"
    caddy validate --config "$CADDY_CONFIG" --adapter caddyfile >/dev/null 2>&1 || true
    systemctl reload caddy >/dev/null 2>&1 || true
  fi
  rm -f -- "$candidate" "$original"
  ((status == 0)) || {
    v2_fail caddy_switch_failed
    return 1
  }
}

v2_restore_caddy_backup() {
  local candidate original status=0
  [[ -f "$CADDY_BACKUP" && ! -L "$CADDY_BACKUP" ]] || {
    v2_fail caddy_backup_missing
    return 1
  }
  [[ "$(v2_caddy_reference_count "$CADDY_BACKUP" "$ACTIVE_PORT")" == 1 ]] || {
    v2_fail caddy_backup_invalid
    return 1
  }
  candidate=$(mktemp "${CADDY_CONFIG}.v2-restore.XXXXXX")
  original=$(mktemp "${CADDY_CONFIG}.v2-current.XXXXXX")
  cp -p -- "$CADDY_BACKUP" "$candidate"
  cp -p -- "$CADDY_CONFIG" "$original"
  v2_validate_caddy_file "$candidate" || status=$?
  if ((status == 0)); then
    chmod 0644 "$candidate"
    mv -f -- "$candidate" "$CADDY_CONFIG"
    if ! systemctl reload caddy || [[ "$(systemctl is-active caddy)" != active ]]; then
      status=1
    fi
  fi
  if ((status != 0)); then
    cp -p -- "$original" "$CADDY_CONFIG"
    chmod 0644 "$CADDY_CONFIG"
    systemctl reload caddy >/dev/null 2>&1 || true
  fi
  rm -f -- "$candidate" "$original"
  ((status == 0)) || {
    v2_fail caddy_restore_failed
    return 1
  }
}

v2_start_maintenance() {
  local maintenance_image existing_image existing_release existing_port existing_config
  maintenance_image=$(release_state_get "$V2_STATE_FILE" maintenance_image)
  if docker container inspect "$MAINTENANCE_CONTAINER" >/dev/null 2>&1; then
    existing_image=$(docker inspect -f '{{.Config.Image}}' "$MAINTENANCE_CONTAINER")
    existing_release=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.v2.release" }}' "$MAINTENANCE_CONTAINER")
    existing_port=$(docker inspect -f '{{(index (index .HostConfig.PortBindings "7001/tcp") 0).HostPort}}' "$MAINTENANCE_CONTAINER")
    existing_config=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/etc/caddy/Caddyfile"}}{{.Source}}{{end}}{{end}}' "$MAINTENANCE_CONTAINER")
    [[ "$existing_image" == "$maintenance_image" && "$existing_release" == "$RELEASE_ID" &&
       "$existing_port" == "$MAINTENANCE_PORT" &&
       "$existing_config" == "$V2_RELEASE_DIR/.docker/caddy/Caddyfile.maintenance" ]] || {
      v2_fail maintenance_identity_mismatch
      return 1
    }
    [[ "$(docker inspect -f '{{.State.Running}}' "$MAINTENANCE_CONTAINER")" == true ]] || docker start "$MAINTENANCE_CONTAINER" >/dev/null
  else
    docker run --detach \
      --name "$MAINTENANCE_CONTAINER" \
      --label codex.xboard.v2.maintenance=true \
      --label "codex.xboard.v2.release=$RELEASE_ID" \
      --init \
      --restart unless-stopped \
      --read-only \
      --security-opt no-new-privileges:true \
      --memory 64m \
      --cpus 0.25 \
      --pids-limit 64 \
      --publish "127.0.0.1:$MAINTENANCE_PORT:7001" \
      --volume "$V2_RELEASE_DIR/.docker/caddy/Caddyfile.maintenance:/etc/caddy/Caddyfile:ro" \
      --tmpfs /config:size=8m,mode=0700 \
      --tmpfs /data:size=8m,mode=0700 \
      "$maintenance_image" >/dev/null
  fi
  for attempt in {1..15}; do
    if curl --silent --show-error --fail --max-time 3 "http://127.0.0.1:$MAINTENANCE_PORT/health" >/dev/null; then
      return 0
    fi
    sleep 1
  done
  v2_fail maintenance_unhealthy
}

v2_legacy_redis_save() {
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    docker exec "$LEGACY_ANCHOR_ID" sh -eu -c \
      'REDISCLI_AUTH=$(cat /run/secrets/xboard_redis_password); export REDISCLI_AUTH; exec redis-cli --no-auth-warning SAVE' |
      grep -qx OK
  else
    docker exec "$LEGACY_ANCHOR_ID" redis-cli -s /data/redis.sock SAVE | grep -qx OK
  fi
}

v2_legacy_redis_ping() {
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    docker exec "$LEGACY_ANCHOR_ID" sh -eu -c \
      'REDISCLI_AUTH=$(cat /run/secrets/xboard_redis_password); export REDISCLI_AUTH; exec redis-cli --no-auth-warning ping' |
      grep -qx PONG
  else
    docker exec "$LEGACY_ANCHOR_ID" redis-cli -s /data/redis.sock ping | grep -qx PONG
  fi
}

v2_redis_save() {
  v2_compose exec --no-TTY redis sh -eu -c \
    'REDISCLI_AUTH=$(cat /run/secrets/xboard_redis_password); export REDISCLI_AUTH; exec redis-cli --no-auth-warning SAVE' |
    grep -qx OK
}

v2_reserved_jobs() {
  local horizon_container=$1
  docker exec "$horizon_container" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $queues = [];
  foreach ((array) config("horizon.environments.".app()->environment(), []) as $supervisor) {
      foreach ((array) ($supervisor["queue"] ?? []) as $queue) {
          $queues[] = (string) $queue;
      }
  }
  $queues = array_values(array_unique(array_filter($queues, static fn (string $queue): bool => $queue !== "")));
  if ($queues === []) {
      fwrite(STDERR, "No Horizon queues were configured.\n");
      exit(2);
  }
  $redis = app("redis")->connection("default");
  $reserved = 0;
  foreach ($queues as $queue) {
      $reserved += (int) $redis->zcard("queues:{$queue}:reserved");
  }
  echo $reserved;
  '
}

v2_legacy_reserved_jobs() {
  v2_reserved_jobs "$LEGACY_HORIZON_ID"
}

v2_stop_legacy_runtime() {
  local attempt reserved_jobs=''
  v2_assert_legacy_identity
  docker stop --time 30 "$LEGACY_SCHEDULER_ID" >/dev/null
  docker exec "$LEGACY_HORIZON_ID" php /www/artisan horizon:pause >/dev/null 2>&1 || true
  for attempt in {1..45}; do
    reserved_jobs=$(v2_legacy_reserved_jobs)
    [[ "$reserved_jobs" =~ ^[0-9]+$ ]] || {
      v2_fail invalid_legacy_reserved_job_count
      return 1
    }
    ((reserved_jobs == 0)) && break
    sleep 2
  done
  [[ "$reserved_jobs" == 0 ]] || {
    v2_fail "legacy_jobs_did_not_drain:$reserved_jobs"
    return 1
  }
  docker stop --time 60 "$LEGACY_HORIZON_ID" >/dev/null
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    docker stop --time 30 "$LEGACY_EDGE_ID" "$LEGACY_WS_ID" >/dev/null
  fi
  docker stop --time 30 "$LEGACY_WEB_ID" >/dev/null
  v2_legacy_redis_save
  docker stop --time 30 "$LEGACY_ANCHOR_ID" >/dev/null
  while IFS= read -r id; do
    ! v2_container_running "$id" || {
      v2_fail legacy_runtime_did_not_stop
      return 1
    }
  done < <(v2_legacy_ids)
}

v2_restore_legacy_redis_owner() {
  local legacy_image_id

  v2_assert_legacy_identity || return 1
  if v2_container_running "$LEGACY_ANCHOR_ID" && v2_legacy_redis_ping 2>/dev/null; then
    return 0
  fi
  if v2_container_running "$LEGACY_ANCHOR_ID"; then
    docker stop --time 30 "$LEGACY_ANCHOR_ID" >/dev/null || {
      v2_fail legacy_anchor_stop_before_owner_restore_failed
      return 1
    }
  fi
  ! v2_container_running "$LEGACY_ANCHOR_ID" || {
    v2_fail legacy_anchor_running_during_owner_restore
    return 1
  }

  legacy_image_id=$(docker inspect -f '{{.Image}}' "$LEGACY_ANCHOR_ID")
  [[ "$legacy_image_id" =~ ^sha256:[0-9a-f]{64}$ ]] || {
    v2_fail invalid_legacy_image_id_for_owner_restore
    return 1
  }

  docker run --rm \
    --network none \
    --read-only \
    --security-opt no-new-privileges:true \
    --user 0:0 \
    --memory 64m \
    --pids-limit 32 \
    --volume "$REDIS_VOLUME_NAME:/data" \
    --entrypoint /bin/sh \
    "$legacy_image_id" -eu -c '
      test -d /data && test ! -L /data
      test -f /data/dump.rdb && test ! -L /data/dump.rdb
      before=$(sha256sum /data/dump.rdb)
      before=${before%% *}
      chown redis:redis /data /data/dump.rdb
      after=$(sha256sum /data/dump.rdb)
      after=${after%% *}
      test "$before" = "$after"
      test "$(stat -c %u:%g /data)" = "$(id -u redis):$(id -g redis)"
      test "$(stat -c %u:%g /data/dump.rdb)" = "$(id -u redis):$(id -g redis)"
    ' >/dev/null || {
      v2_fail legacy_redis_owner_restore_failed
      return 1
    }
}

v2_start_legacy_runtime() {
  local attempt redis_ready=0 runtime_ready=0 horizon_ready_samples=0
  v2_assert_legacy_identity || return 1
  docker start "$LEGACY_ANCHOR_ID" >/dev/null || {
    v2_fail legacy_anchor_start_failed
    return 1
  }
  for attempt in {1..30}; do
    if v2_legacy_redis_ping 2>/dev/null; then
      redis_ready=1
      break
    fi
    sleep 2
  done
  ((redis_ready == 1)) || {
    v2_fail legacy_redis_unhealthy
    return 1
  }
  docker start "$LEGACY_WEB_ID" >/dev/null || {
    v2_fail legacy_web_start_failed
    return 1
  }
  if [[ "$LEGACY_TOPOLOGY" == v2 ]]; then
    docker start "$LEGACY_WS_ID" >/dev/null || {
      v2_fail legacy_ws_start_failed
      return 1
    }
    docker start "$LEGACY_EDGE_ID" >/dev/null || {
      v2_fail legacy_edge_start_failed
      return 1
    }
  fi
  docker start "$LEGACY_HORIZON_ID" >/dev/null || {
    v2_fail legacy_horizon_start_failed
    return 1
  }
  for attempt in {1..30}; do
    if curl --silent --show-error --fail --max-time 3 "http://127.0.0.1:$ACTIVE_PORT/" >/dev/null &&
       v2_container_running "$LEGACY_HORIZON_ID" &&
       { [[ "$LEGACY_TOPOLOGY" != v2 ]] ||
         { v2_container_running "$LEGACY_WS_ID" && v2_container_running "$LEGACY_EDGE_ID"; }; }; then
      runtime_ready=1
      break
    fi
    sleep 2
  done
  ((runtime_ready == 1)) || {
    v2_fail legacy_web_or_horizon_unhealthy
    return 1
  }
  docker start "$LEGACY_SCHEDULER_ID" >/dev/null || {
    v2_fail legacy_scheduler_start_failed
    return 1
  }
  for attempt in {1..20}; do
    docker exec "$LEGACY_HORIZON_ID" php /www/artisan horizon:continue >/dev/null 2>&1 || true
    if v2_legacy_horizon_running "$LEGACY_HORIZON_ID"; then
      ((horizon_ready_samples += 1))
      ((horizon_ready_samples >= 3)) && break
    else
      horizon_ready_samples=0
    fi
    sleep 2
  done
  ((horizon_ready_samples >= 3)) || {
    v2_fail legacy_horizon_not_running
    return 1
  }
}

v2_legacy_horizon_running() {
  local container=$1
  docker exec "$container" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $basename = Laravel\Horizon\MasterSupervisor::basename();
  $matching = array_values(array_filter(
      app(Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all(),
      static fn ($master): bool => str_starts_with($master->name, $basename."-")
  ));
  if (count($matching) !== 1) {
      exit(1);
  }
  $master = $matching[0];
  exit($master->status === "running" && count($master->supervisors) > 0 ? 0 : 1);
  '
}

v2_assert_v2_owners() {
  local service id restart_count oom_killed listed_id container_id data_source redis_volume processes
  local horizon_count=0 scheduler_count=0 redis_count=0
  local expected_horizon expected_scheduler expected_redis
  for service in edge web ws redis horizon scheduler; do
    id=$(v2_service_id "$service")
    [[ -n "$id" ]] || {
      v2_fail "owner_missing:$service"
      return 1
    }
    restart_count=$(docker inspect -f '{{.RestartCount}}' "$id")
    oom_killed=$(docker inspect -f '{{.State.OOMKilled}}' "$id")
    [[ "$restart_count" == 0 && "$oom_killed" == false ]] || {
      v2_fail "runtime_restart_or_oom:$service"
      return 1
    }
  done
  while IFS= read -r id; do
    ! v2_container_running "$id" || {
      v2_fail legacy_owner_still_running
      return 1
    }
  done < <(v2_legacy_ids)

  expected_horizon=$(v2_service_id horizon)
  expected_scheduler=$(v2_service_id scheduler)
  expected_redis=$(v2_service_id redis)
  for listed_id in $(docker ps -q); do
    container_id=$(docker inspect -f '{{.Id}}' "$listed_id")
    data_source=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/www/.docker/.data"}}{{.Source}}{{end}}{{end}}' "$container_id")
    if [[ "$data_source" == "$APP_DATA_PATH" ]]; then
      processes=$(docker exec "$container_id" sh -c '
        for proc in /proc/[0-9]*; do
          [ -r "$proc/cmdline" ] || continue
          executable=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "1p")
          argument1=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "2p")
          argument2=$(tr "\000" "\n" < "$proc/cmdline" | sed -n "3p")
          if [ "${executable##*/}" = php ] && [ "$argument1" = /www/artisan ]; then
            case "$argument2" in
              horizon) printf "%s\n" horizon ;;
              schedule:work) printf "%s\n" scheduler ;;
            esac
          fi
        done
      ' 2>/dev/null || true)
      while IFS= read -r process; do
        case "$process" in
          horizon)
            ((horizon_count += 1))
            [[ "$container_id" == "$expected_horizon" ]] || {
              v2_fail unexpected_horizon_owner
              return 1
            }
            ;;
          scheduler)
            ((scheduler_count += 1))
            [[ "$container_id" == "$expected_scheduler" ]] || {
              v2_fail unexpected_scheduler_owner
              return 1
            }
            ;;
        esac
      done <<< "$processes"
    fi
    redis_volume=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Name}}{{end}}{{end}}' "$container_id")
    if [[ "$redis_volume" == "$REDIS_VOLUME_NAME" ]]; then
      ((redis_count += 1))
      [[ "$container_id" == "$expected_redis" ]] || {
        v2_fail unexpected_redis_owner
        return 1
      }
    fi
  done
  [[ "$horizon_count" == 1 && "$scheduler_count" == 1 && "$redis_count" == 1 ]] || {
    v2_fail "owner_count_mismatch:horizon=$horizon_count,scheduler=$scheduler_count,redis=$redis_count"
    return 1
  }
}

v2_scheduler_zombie_count() {
  local scheduler_id
  scheduler_id=$(v2_service_id scheduler)
  docker exec "$scheduler_id" sh -c '
    count=0
    for proc in /proc/[0-9]*; do
      [ -r "$proc/stat" ] || continue
      IFS= read -r stat_line < "$proc/stat" || continue
      stat_tail=${stat_line##*) }
      state=${stat_tail%% *}
      [ "$state" != Z ] || count=$((count + 1))
    done
    printf "%s\n" "$count"
  '
}

v2_rollback_runtime() {
  local active_references maintenance_references redis_id service service_id horizon_id attempt
  local reserved_jobs=''

  active_references=$(v2_caddy_reference_count "$CADDY_CONFIG" "$ACTIVE_PORT")
  maintenance_references=$(v2_caddy_reference_count "$CADDY_CONFIG" "$MAINTENANCE_PORT")
  if [[ "$active_references" == 1 && "$maintenance_references" == 0 ]]; then
    v2_start_maintenance || return 1
    v2_replace_caddy_upstream "$ACTIVE_PORT" "$MAINTENANCE_PORT" || return 1
    release_state_set "$V2_STATE_FILE" traffic_state maintenance || return 1
    TRAFFIC_STATE=maintenance
  elif [[ "$active_references" != 0 || "$maintenance_references" != 1 ]]; then
    v2_fail rollback_caddy_state_ambiguous
    return 1
  fi

  v2_compose stop --timeout 30 scheduler >/dev/null 2>&1 || true
  horizon_id=$(v2_service_id horizon 2>/dev/null || true)
  if [[ -n "$horizon_id" ]] && v2_container_running "$horizon_id"; then
    docker exec "$horizon_id" php /www/artisan horizon:pause >/dev/null 2>&1 || true
    for attempt in {1..45}; do
      reserved_jobs=$(v2_reserved_jobs "$horizon_id" 2>/dev/null || printf '%s\n' unknown)
      [[ "$reserved_jobs" =~ ^[0-9]+$ ]] || break
      ((reserved_jobs == 0)) && break
      sleep 2
    done
    if [[ "$reserved_jobs" != 0 ]]; then
      echo "V2_WARN=rollback_jobs_not_drained:$reserved_jobs" >&2
    fi
  fi
  v2_compose stop --timeout 60 horizon >/dev/null 2>&1 || true
  v2_compose stop --timeout 30 edge web ws >/dev/null 2>&1 || true
  for service in scheduler horizon edge web ws; do
    service_id=$(v2_service_id "$service" 2>/dev/null || true)
    if [[ -n "$service_id" ]] && v2_container_running "$service_id"; then
      v2_fail "v2_service_did_not_stop:$service"
      return 1
    fi
  done

  redis_id=$(v2_service_id redis 2>/dev/null || true)
  if [[ -n "$redis_id" ]] && v2_container_running "$redis_id"; then
    v2_redis_save || {
      v2_fail v2_redis_save_failed
      return 1
    }
    v2_compose stop redis >/dev/null || {
      v2_fail v2_redis_stop_failed
      return 1
    }
    ! v2_container_running "$redis_id" || {
      v2_fail v2_redis_did_not_stop
      return 1
    }
  fi

  v2_restore_legacy_redis_owner || return 1
  v2_start_legacy_runtime || return 1
  v2_restore_caddy_backup || return 1
  docker rm -f "$MAINTENANCE_CONTAINER" >/dev/null 2>&1 || true
  release_state_set "$V2_STATE_FILE" traffic_state rolled_back || return 1
  release_state_set "$V2_STATE_FILE" rolled_back_at "$(date -u +%FT%TZ)" || return 1
  # Consumed by the calling phase.
  # shellcheck disable=SC2034
  TRAFFIC_STATE=rolled_back
}
