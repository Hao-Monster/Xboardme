#!/usr/bin/env bash
set -Eeuo pipefail

: "${DEPLOY_IMAGE:?DEPLOY_IMAGE is required}"
: "${DEPLOY_RUN_ID:?DEPLOY_RUN_ID is required}"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on the deployment server." >&2
  exit 1
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE=(docker-compose)
else
  echo "Docker Compose is not installed on the deployment server." >&2
  exit 1
fi

mapfile -t candidate_ids < <(
  docker ps --format '{{.ID}} {{.Image}}' \
    | awk 'tolower($2) ~ /xboard/ {print $1}'
)

if ((${#candidate_ids[@]} == 0)); then
  mapfile -t candidate_ids < <(
    docker ps -q --filter label=com.docker.compose.service=xboard
  )
fi

if ((${#candidate_ids[@]} == 0)); then
  echo "No running Xboard Compose container was found; deployment stopped before changing anything." >&2
  exit 1
fi

declare -A project_keys=()
for container_id in "${candidate_ids[@]}"; do
  project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id")
  workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$container_id")
  if [[ -n "$project" && -n "$workdir" ]]; then
    project_keys["$project|$workdir"]=1
  fi
done

if ((${#project_keys[@]} != 1)); then
  echo "Expected exactly one running Xboard Compose project, found ${#project_keys[@]}; deployment stopped." >&2
  exit 1
fi

project_key=${!project_keys[@]}
PROJECT=${project_key%%|*}
WORKDIR=${project_key#*|}

if [[ ! -d "$WORKDIR" ]]; then
  echo "Compose working directory does not exist: $WORKDIR" >&2
  exit 1
fi

cd "$WORKDIR"

mapfile -t project_container_ids < <(
  docker ps -q --filter "label=com.docker.compose.project=$PROJECT"
)

declare -A selected_services=()
for container_id in "${project_container_ids[@]}"; do
  image=$(docker inspect -f '{{.Config.Image}}' "$container_id")
  service=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.service" }}' "$container_id")
  if [[ "${image,,}" == *xboard* && "$service" =~ ^[A-Za-z0-9_.-]+$ ]]; then
    selected_services["$service"]=$container_id
  fi
done

if ((${#selected_services[@]} == 0)); then
  echo "The detected Compose project has no Xboard image services." >&2
  exit 1
fi

mapfile -t SERVICES < <(printf '%s\n' "${!selected_services[@]}" | sort)
PRIMARY_SERVICE=${SERVICES[0]}
for preferred in xboard web; do
  if [[ -n "${selected_services[$preferred]:-}" ]]; then
    PRIMARY_SERVICE=$preferred
    break
  fi
done
PRIMARY_CONTAINER=${selected_services[$PRIMARY_SERVICE]}

CONFIG_FILES=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.config_files" }}' "$PRIMARY_CONTAINER")
COMPOSE_FILES=()
if [[ -n "$CONFIG_FILES" ]]; then
  IFS=',' read -r -a raw_config_files <<< "$CONFIG_FILES"
  for config_file in "${raw_config_files[@]}"; do
    [[ "$config_file" == *'.codex-distributor.override.yml' ]] && continue
    [[ "$config_file" != /* ]] && config_file="$WORKDIR/$config_file"
    if [[ ! -f "$config_file" ]]; then
      echo "Compose configuration file does not exist: $config_file" >&2
      exit 1
    fi
    COMPOSE_FILES+=(-f "$config_file")
  done
fi

if ((${#COMPOSE_FILES[@]} == 0)); then
  for config_file in compose.yaml compose.yml docker-compose.yaml docker-compose.yml; do
    if [[ -f "$WORKDIR/$config_file" ]]; then
      COMPOSE_FILES=(-f "$WORKDIR/$config_file")
      break
    fi
  done
fi

if ((${#COMPOSE_FILES[@]} == 0)); then
  echo "Unable to resolve the active Compose configuration." >&2
  exit 1
fi

ATTACHMENT_DEST=/www/storage/app/knowledge-attachments
ATTACHMENT_MOUNT=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/www/storage/app/knowledge-attachments"}}{{.Type}}|{{.Source}}|{{.Name}}{{end}}{{end}}' "$PRIMARY_CONTAINER")
ATTACHMENT_NEEDS_REOWN=0
if [[ -n "$ATTACHMENT_MOUNT" ]]; then
  IFS='|' read -r attachment_mount_type attachment_mount_source attachment_mount_name <<< "$ATTACHMENT_MOUNT"
  case "$attachment_mount_type" in
    bind) ATTACHMENT_VOLUME_SOURCE=$attachment_mount_source ;;
    volume) ATTACHMENT_VOLUME_SOURCE=$attachment_mount_name ;;
    *) echo "Unsupported knowledge attachment mount type: $attachment_mount_type" >&2; exit 1 ;;
  esac
  echo "Reusing knowledge attachment storage: $attachment_mount_type $ATTACHMENT_VOLUME_SOURCE"
else
  ATTACHMENT_VOLUME_SOURCE="$WORKDIR/storage/knowledge-attachments"
  mkdir -p "$ATTACHMENT_VOLUME_SOURCE"
  chmod 750 "$ATTACHMENT_VOLUME_SOURCE"
  if docker exec "$PRIMARY_CONTAINER" test -d "$ATTACHMENT_DEST"; then
    echo "Migrating knowledge attachments from the current container into persistent storage..."
    docker cp "$PRIMARY_CONTAINER:$ATTACHMENT_DEST/." "$ATTACHMENT_VOLUME_SOURCE/"
  fi
  ATTACHMENT_NEEDS_REOWN=1
  echo "Created persistent knowledge attachment storage: $ATTACHMENT_VOLUME_SOURCE"
fi
if [[ -z "$ATTACHMENT_VOLUME_SOURCE" || "$ATTACHMENT_VOLUME_SOURCE" == *$'\n'* || "$ATTACHMENT_VOLUME_SOURCE" == *'"'* || "$ATTACHMENT_VOLUME_SOURCE" == *':'* || "$ATTACHMENT_VOLUME_SOURCE" == *'$'* ]]; then
  echo "Unsafe knowledge attachment volume source: $ATTACHMENT_VOLUME_SOURCE" >&2
  exit 1
fi

DEPLOY_DIR="$WORKDIR/.codex-deploy"
BACKUP_DIR="$DEPLOY_DIR/backups"
STATE_DIR="$DEPLOY_DIR/releases"
OVERRIDE_FILE="$WORKDIR/.codex-distributor.override.yml"
mkdir -p "$BACKUP_DIR" "$STATE_DIR"
chmod 700 "$DEPLOY_DIR" "$BACKUP_DIR" "$STATE_DIR"

echo "Backing up the current database before migration..."
docker exec "$PRIMARY_CONTAINER" sh -lc \
  'mkdir -p /www/storage/backup && php /www/artisan backup:database >/tmp/codex-db-backup.log 2>&1'
BACKUP_SOURCE=$(docker exec "$PRIMARY_CONTAINER" sh -lc \
  'ls -1t /www/storage/backup/*.gz 2>/dev/null | head -n 1')
if [[ -z "$BACKUP_SOURCE" ]]; then
  echo "Database backup did not produce a compressed backup file." >&2
  docker exec "$PRIMARY_CONTAINER" sh -lc 'cat /tmp/codex-db-backup.log' >&2 || true
  exit 1
fi
BACKUP_TARGET="$BACKUP_DIR/${DEPLOY_RUN_ID}-$(basename "$BACKUP_SOURCE")"
docker cp "$PRIMARY_CONTAINER:$BACKUP_SOURCE" "$BACKUP_TARGET" >/dev/null
chmod 600 "$BACKUP_TARGET"
echo "Database backup saved on the server: $BACKUP_TARGET"

STATE_FILE="$STATE_DIR/$DEPLOY_RUN_ID.env"
: > "$STATE_FILE"
chmod 600 "$STATE_FILE"

declare -A rollback_images=()
for service in "${SERVICES[@]}"; do
  container_id=${selected_services[$service]}
  old_image_id=$(docker inspect -f '{{.Image}}' "$container_id")
  rollback_tag="xboard-rollback:${DEPLOY_RUN_ID}-${service}"
  docker image tag "$old_image_id" "$rollback_tag"
  rollback_images["$service"]=$rollback_tag
  printf 'SERVICE_%s=%q\n' "${service//[^A-Za-z0-9]/_}" "$rollback_tag" >> "$STATE_FILE"
done
printf 'DATABASE_BACKUP=%q\nDEPLOY_IMAGE=%q\nKNOWLEDGE_ATTACHMENT_VOLUME=%q\n' "$BACKUP_TARGET" "$DEPLOY_IMAGE" "$ATTACHMENT_VOLUME_SOURCE" >> "$STATE_FILE"

write_override() {
  local mode=$1
  {
    echo 'services:'
    for service in "${SERVICES[@]}"; do
      echo "  $service:"
      if [[ "$mode" == rollback ]]; then
        echo "    image: ${rollback_images[$service]}"
      else
        echo "    image: $DEPLOY_IMAGE"
      fi
      echo '    volumes:'
      echo "      - \"$ATTACHMENT_VOLUME_SOURCE:$ATTACHMENT_DEST\""
    done
  } > "$OVERRIDE_FILE"
  chmod 600 "$OVERRIDE_FILE"
}

deployment_started=0
rollback_on_error() {
  status=$?
  if ((status != 0 && deployment_started == 1)); then
    echo "Deployment failed; restoring previous container images..." >&2
    write_override rollback
    "${COMPOSE[@]}" -p "$PROJECT" "${COMPOSE_FILES[@]}" -f "$OVERRIDE_FILE" \
      up -d --no-deps --force-recreate "${SERVICES[@]}" || true
  fi
  exit "$status"
}
trap rollback_on_error EXIT

echo "Pulling immutable image: $DEPLOY_IMAGE"
docker pull "$DEPLOY_IMAGE"
if ((ATTACHMENT_NEEDS_REOWN == 1)); then
  echo "Normalizing migrated knowledge attachment ownership and permissions..."
  docker run --rm --entrypoint sh -v "$ATTACHMENT_VOLUME_SOURCE:$ATTACHMENT_DEST" "$DEPLOY_IMAGE" -lc \
    "mkdir -p '$ATTACHMENT_DEST/files' '$ATTACHMENT_DEST/temporary' '$ATTACHMENT_DEST/quarantine' && chown -R 1000:1000 '$ATTACHMENT_DEST' && find '$ATTACHMENT_DEST' -type d -exec chmod 0750 {} + && find '$ATTACHMENT_DEST' -type f -exec chmod 0640 {} +"
fi
write_override deploy
deployment_started=1

"${COMPOSE[@]}" -p "$PROJECT" "${COMPOSE_FILES[@]}" -f "$OVERRIDE_FILE" \
  up -d --no-deps --force-recreate "${SERVICES[@]}"

for attempt in {1..30}; do
  all_running=1
  for service in "${SERVICES[@]}"; do
    container_id=$("${COMPOSE[@]}" -p "$PROJECT" "${COMPOSE_FILES[@]}" -f "$OVERRIDE_FILE" ps -q "$service")
    if [[ -z "$container_id" || "$(docker inspect -f '{{.State.Running}}' "$container_id")" != true ]]; then
      all_running=0
      break
    fi
  done
  ((all_running == 1)) && break
  sleep 4
done

if ((all_running != 1)); then
  echo "One or more Xboard services did not reach the running state." >&2
  exit 1
fi

PRIMARY_CONTAINER=$("${COMPOSE[@]}" -p "$PROJECT" "${COMPOSE_FILES[@]}" -f "$OVERRIDE_FILE" ps -q "$PRIMARY_SERVICE")
docker exec "$PRIMARY_CONTAINER" php /www/artisan migrate --force --no-interaction
docker exec \
  -e CACHE_DRIVER=array \
  -e CACHE_SETTINGS_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  "$PRIMARY_CONTAINER" php /www/artisan optimize:clear
docker exec -u 1000:1000 "$PRIMARY_CONTAINER" php /www/artisan knowledge-attachments:status --json

if [[ "$PRIMARY_SERVICE" == xboard || "$PRIMARY_SERVICE" == web ]]; then
  docker exec "$PRIMARY_CONTAINER" sh -lc '
    for attempt in $(seq 1 30); do
      wget -q -O /dev/null http://127.0.0.1:7001/ && exit 0
      sleep 4
    done
    exit 1
  '
fi

for service in "${SERVICES[@]}"; do
  container_id=$("${COMPOSE[@]}" -p "$PROJECT" "${COMPOSE_FILES[@]}" -f "$OVERRIDE_FILE" ps -q "$service")
  running_image=$(docker inspect -f '{{.Config.Image}}' "$container_id")
  if [[ "$running_image" != "$DEPLOY_IMAGE" ]]; then
    echo "Service $service is running unexpected image $running_image" >&2
    exit 1
  fi
done

trap - EXIT
echo "Deployment completed successfully. Project=$PROJECT Services=${SERVICES[*]} Image=$DEPLOY_IMAGE"
