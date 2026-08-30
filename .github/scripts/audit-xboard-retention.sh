#!/usr/bin/env bash
set -Eeuo pipefail

: "${EXPECTED_WORKFLOW_SHA:?EXPECTED_WORKFLOW_SHA is required}"
: "${RETENTION_REQUIRE_FINALIZED:=false}"
: "${RETENTION_REQUIRED_FREE_PORT:=}"
[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[a-f0-9]{40}$ ]] || {
  echo 'RETENTION_AUDIT_FAIL=invalid_workflow_sha'
  exit 1
}
case "$RETENTION_REQUIRE_FINALIZED" in
  true|false) ;;
  *) echo 'RETENTION_AUDIT_FAIL=invalid_require_finalized'; exit 1 ;;
esac
if [[ -n "$RETENTION_REQUIRED_FREE_PORT" ]] &&
   { [[ ! "$RETENTION_REQUIRED_FREE_PORT" =~ ^[0-9]+$ ]] ||
     ((RETENTION_REQUIRED_FREE_PORT < 1 || RETENTION_REQUIRED_FREE_PORT > 65535)); }; then
  echo 'RETENTION_AUDIT_FAIL=invalid_required_free_port'
  exit 1
fi

for tool in awk basename caddy chmod df docker du find grep jq mktemp realpath sed sha256sum sort ss stat tee tr wc; do
  command -v "$tool" >/dev/null || {
    echo "RETENTION_AUDIT_FAIL=missing_tool:$tool"
    exit 1
  }
done
if ! declare -F xboard_find_compose_anchor >/dev/null ||
   ! declare -F xboard_find_caddy_upstream >/dev/null ||
   ! declare -F xboard_resolve_active_runtime >/dev/null ||
   ! declare -F release_state_validate >/dev/null; then
  echo 'RETENTION_AUDIT_FAIL=helpers_missing'
  exit 1
fi

xboard_find_compose_anchor || {
  echo "RETENTION_AUDIT_FAIL=compose_anchor:$XBOARD_DISCOVERY_ERROR"
  exit 1
}
xboard_find_caddy_upstream || {
  echo "RETENTION_AUDIT_FAIL=caddy_upstream:$XBOARD_DISCOVERY_ERROR"
  exit 1
}
xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" || {
  echo "RETENTION_AUDIT_FAIL=active_runtime:$XBOARD_DISCOVERY_ERROR"
  exit 1
}

workdir=$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR")
active_container=$(docker inspect -f '{{.Id}}' "$XBOARD_ACTIVE_WEB")
active_name=$(docker inspect -f '{{.Name}}' "$active_container" | sed 's#^/##')
active_project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$active_container")
active_image_id=$(docker inspect -f '{{.Image}}' "$active_container")
active_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$active_image_id")
[[ "$active_revision" =~ ^[a-f0-9]{40}$ ]] || {
  echo 'RETENTION_AUDIT_FAIL=active_revision_missing'
  exit 1
}
caddy validate --config "$XBOARD_CADDY_FILE" --adapter caddyfile >/dev/null

active_release_id=''
active_state_file=''
active_traffic_state=legacy
active_rollback_supported=unknown
active_maintenance_container=''
active_redis_volume=unknown
active_app_data_id=unknown
direct_previous_project=''
direct_previous_maintenance=''
declare -A protected_ids=()
protected_ids["$(docker inspect -f '{{.Id}}' "$XBOARD_ANCHOR_CONTAINER")"]=anchor
protected_ids["$active_container"]=active

if [[ -n "$active_project" && "$active_project" != '<no value>' ]]; then
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    protected_ids["$(docker inspect -f '{{.Id}}' "$id")"]=active
  done < <(docker ps -aq --filter "label=com.docker.compose.project=$active_project")
fi
active_legacy_run=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.release.run" }}' "$active_container")
if [[ "$active_legacy_run" != '<no value>' && -n "$active_legacy_run" ]]; then
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    protected_ids["$(docker inspect -f '{{.Id}}' "$id")"]=active
  done < <(docker ps -aq --filter "label=codex.xboard.release.run=$active_legacy_run")
fi

if [[ "$XBOARD_ACTIVE_TOPOLOGY" == v2 ]]; then
  [[ "$active_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]] || {
    echo 'RETENTION_AUDIT_FAIL=active_v2_project_invalid'
    exit 1
  }
  active_release_id=${BASH_REMATCH[1]}
  active_release_dir="$workdir/.codex-v2-release/$active_release_id"
  active_state_file="$active_release_dir/state.json"
  release_state_validate "$active_state_file" || {
    echo 'RETENTION_AUDIT_FAIL=active_state_missing'
    exit 1
  }
  [[ "$(release_state_get "$active_state_file" release_id)" == "$active_release_id" ]] || {
    echo 'RETENTION_AUDIT_FAIL=active_state_id_mismatch'
    exit 1
  }
  [[ "$(release_state_get "$active_state_file" release_sha)" == "$active_revision" ]] || {
    echo 'RETENTION_AUDIT_FAIL=active_state_revision_mismatch'
    exit 1
  }
  active_traffic_state=$(release_state_get "$active_state_file" traffic_state)
  active_rollback_supported=$(release_state_get_optional "$active_state_file" rollback_supported)
  [[ -n "$active_rollback_supported" ]] || active_rollback_supported=unknown
  active_maintenance_container=$(release_state_get_optional "$active_state_file" maintenance_container)
  if [[ -n "$active_maintenance_container" ]] &&
     docker container inspect "$active_maintenance_container" >/dev/null 2>&1; then
    protected_ids["$(docker inspect -f '{{.Id}}' "$active_maintenance_container")"]=active
  fi
  active_redis_volume=$(release_state_get "$active_state_file" redis_volume_name)
  docker volume inspect "$active_redis_volume" >/dev/null
  active_app_data_path=$(release_state_get "$active_state_file" app_data_path)
  [[ "$active_app_data_path" == /* && -d "$active_app_data_path" && ! -L "$active_app_data_path" ]] || {
    echo 'RETENTION_AUDIT_FAIL=active_app_data_invalid'
    exit 1
  }
  active_app_data_id=$(stat -c '%d:%i' "$active_app_data_path")

  for state_key in legacy_anchor_id legacy_web_id legacy_ws_id legacy_edge_id legacy_horizon_id legacy_scheduler_id; do
    id=$(release_state_get_optional "$active_state_file" "$state_key")
    [[ -n "$id" ]] || continue
    if docker container inspect "$id" >/dev/null 2>&1; then
      id=$(docker inspect -f '{{.Id}}' "$id")
      protected_ids["$id"]=direct_rollback
      project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$id")
      if [[ "$project" =~ ^xboard-v2-[0-9]+-[0-9]+$ ]]; then
        if [[ -z "$direct_previous_project" ]]; then
          direct_previous_project=$project
        elif [[ "$direct_previous_project" != "$project" ]]; then
          echo 'RETENTION_AUDIT_FAIL=direct_previous_project_mismatch'
          exit 1
        fi
      fi
    fi
  done

  if [[ "$direct_previous_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]]; then
    direct_previous_maintenance="xboard-v2-maintenance-${BASH_REMATCH[1]}"
    if docker container inspect "$direct_previous_maintenance" >/dev/null 2>&1; then
      protected_ids["$(docker inspect -f '{{.Id}}' "$direct_previous_maintenance")"]=direct_rollback
    fi
  fi
fi

inventory=$(mktemp)
trap 'rm -f -- "$inventory"' EXIT
chmod 600 "$inventory"

emit() {
  printf '%s\n' "$*" | tee -a "$inventory"
}

emit 'RETENTION_AUDIT_SCHEMA=1'
emit "RETENTION_WORKFLOW_SHA=$EXPECTED_WORKFLOW_SHA"
emit "RETENTION_ACTIVE_TOPOLOGY=$XBOARD_ACTIVE_TOPOLOGY"
emit "RETENTION_ACTIVE_PROJECT=$active_project"
emit "RETENTION_ACTIVE_RELEASE_ID=${active_release_id:-legacy}"
emit "RETENTION_ACTIVE_TRAFFIC_STATE=$active_traffic_state"
emit "RETENTION_ACTIVE_ROLLBACK_SUPPORTED=$active_rollback_supported"
emit "RETENTION_ACTIVE_REVISION=$active_revision"
emit "RETENTION_ACTIVE_CONTAINER=$active_name"
emit "RETENTION_ACTIVE_UPSTREAM=$XBOARD_ACTIVE_UPSTREAM"
emit "RETENTION_ACTIVE_REDIS_VOLUME=$active_redis_volume"
emit "RETENTION_ACTIVE_APP_DATA_ID=$active_app_data_id"
emit "RETENTION_DIRECT_PREVIOUS_PROJECT=${direct_previous_project:-none}"
emit "RETENTION_DIRECT_PREVIOUS_MAINTENANCE=${direct_previous_maintenance:-none}"
emit "RETENTION_COMPOSE_ANCHOR=$(docker inspect -f '{{.Name}}' "$XBOARD_ANCHOR_CONTAINER" | sed 's#^/##')"
emit "RETENTION_DISK_AVAILABLE_KIB=$(df -Pk "$workdir" | awk 'NR == 2 {print $4}')"

container_count=0
protected_count=0
candidate_count=0
unrelated_count=0
maintenance_count=0
stage_count=0
declare -A image_ref_counts=()
while IFS= read -r listed_id; do
  [[ -n "$listed_id" ]] || continue
  id=$(docker inspect -f '{{.Id}}' "$listed_id")
  row=$(docker inspect "$id" | jq -r '.[0] | [
    (.Name | ltrimstr("/")),
    .State.Status,
    (.Config.Image // ""),
    (.Image // ""),
    (.Config.Labels["org.opencontainers.image.revision"] // "none"),
    (.Config.Labels["com.docker.compose.project"] // "none"),
    (.Config.Labels["com.docker.compose.service"] // "none"),
    (.Config.Labels["codex.xboard.v2.release"] // "none"),
    (.Config.Labels["codex.xboard.release"] // "none"),
    (.Config.Labels["codex.xboard.release.run"] // "none"),
    (.Config.Labels["codex.xboard.release.role"] // "none"),
    (.Config.Labels["codex.xboard.stage"] // "none"),
    (.Config.Labels["codex.xboard.stage.run"] // "none"),
    ([.NetworkSettings.Ports // {} | to_entries[] | .value[]? | ((.HostIp // "") + ":" + (.HostPort // ""))] | join(",") | if . == "" then "none" else . end),
    ([.Mounts[]? | ((.Type // "") + ":" + (.Destination // ""))] | sort | join(",") | if . == "" then "none" else . end)
  ] | @tsv')
  IFS=$'\t' read -r name status image image_id revision project service maintenance_release legacy_release legacy_release_run legacy_release_role stage stage_run ports mounts <<< "$row"
  protection=${protected_ids[$id]:-}
  if [[ -z "$protection" ]]; then
    if [[ "$project" =~ ^xboard-v2-[0-9]+-[0-9]+$ || "$maintenance_release" =~ ^[0-9]+-[0-9]+$ ]]; then
      protection=retired_v2_candidate
    elif [[ "$legacy_release" == true && "$legacy_release_run" != none ]]; then
      protection=retired_legacy_candidate
    elif [[ "$stage" == true && "$stage_run" != none ]]; then
      protection=stale_stage_candidate
    else
      protection=unrelated
    fi
  fi
  ((container_count += 1))
  case "$protection" in
    anchor|active|direct_rollback)
      ((protected_count += 1))
      ;;
    *_candidate)
      ((candidate_count += 1))
      ;;
    unrelated)
      ((unrelated_count += 1))
      ;;
    *)
      echo "RETENTION_AUDIT_FAIL=unknown_classification:$protection"
      exit 1
      ;;
  esac
  [[ "$maintenance_release" == none ]] || ((maintenance_count += 1))
  [[ "$stage_run" == none ]] || ((stage_count += 1))
  image_ref_counts["$image_id"]=$(( ${image_ref_counts[$image_id]:-0} + 1 ))
  emit "RETENTION_CONTAINER name=$name id=$id status=$status classification=$protection project=$project service=$service maintenance_release=$maintenance_release legacy_release_run=$legacy_release_run legacy_release_role=$legacy_release_role stage_run=$stage_run revision=$revision image_id=$image_id ports=${ports:-none} mounts=${mounts:-none}"
done < <(docker ps -aq --no-trunc | sort)

emit "RETENTION_CONTAINER_COUNT=$container_count"
emit "RETENTION_PROTECTED_CONTAINER_COUNT=$protected_count"
emit "RETENTION_CANDIDATE_CONTAINER_COUNT=$candidate_count"
emit "RETENTION_UNRELATED_CONTAINER_COUNT=$unrelated_count"
emit "RETENTION_MAINTENANCE_CONTAINER_COUNT=$maintenance_count"
emit "RETENTION_STAGE_CONTAINER_COUNT=$stage_count"

for port in {7002..7010}; do
  listeners=$(ss -H -lnt "( sport = :$port )" 2>/dev/null | wc -l | tr -d '[:space:]')
  emit "RETENTION_LISTENER port=$port count=$listeners"
done

if [[ "$RETENTION_REQUIRE_FINALIZED" == true ]]; then
  if [[ "$active_traffic_state" != finalized ]]; then
    echo "RETENTION_AUDIT_FAIL=active_release_not_finalized:$active_traffic_state"
    exit 1
  fi
  if [[ "$active_rollback_supported" != false ]]; then
    echo "RETENTION_AUDIT_FAIL=rollback_support_not_closed:$active_rollback_supported"
    exit 1
  fi
fi
if [[ -n "$RETENTION_REQUIRED_FREE_PORT" ]]; then
  required_port_listeners=$(ss -H -lnt "( sport = :$RETENTION_REQUIRED_FREE_PORT )" 2>/dev/null | wc -l | tr -d '[:space:]')
  if [[ "$required_port_listeners" != 0 ]]; then
    echo "RETENTION_AUDIT_FAIL=required_port_in_use:$RETENTION_REQUIRED_FREE_PORT:$required_port_listeners"
    exit 1
  fi
fi

for root_and_kind in \
  "$workdir/.codex-v2-release:v2_release" \
  "$workdir/.codex-release:legacy_release" \
  "$workdir/.codex-stage:stage" \
  "$workdir/.codex-admin-hotfix:admin_hotfix"; do
  root=${root_and_kind%:*}
  kind=${root_and_kind##*:}
  [[ -d "$root" && ! -L "$root" ]] || continue
  while IFS= read -r path; do
    [[ -d "$path" && ! -L "$path" ]] || continue
    name=$(basename -- "$path")
    size_kib=$(du -sk "$path" | awk '{print $1}')
    state=unknown
    revision=unknown
    secret_present=false
    state_file="$path/state.json"
    if [[ -f "$state_file" ]] && release_state_validate "$state_file" 2>/dev/null; then
      state=$(release_state_get_optional "$state_file" traffic_state)
      revision=$(release_state_get_optional "$state_file" release_sha)
      [[ -n "$state" ]] || state=unknown
      [[ -n "$revision" ]] || revision=unknown
    fi
    [[ ! -f "$path/redis-password" ]] || secret_present=true
    emit "RETENTION_DIRECTORY kind=$kind name=$name size_kib=$size_kib state=$state revision=$revision secret_present=$secret_present"
  done < <(find "$root" -mindepth 1 -maxdepth 1 -type d -print | sort)
done

while IFS= read -r image_id; do
  [[ -n "$image_id" ]] || continue
  refs=${image_ref_counts[$image_id]:-0}
  image_row=$(docker image inspect "$image_id" | jq -r '.[0] | [
    (.Size | tostring),
    (.Config.Labels["org.opencontainers.image.revision"] // "none"),
    (.Config.Labels["org.opencontainers.image.source"] // "none"),
    (.RepoTags // [] | sort | join(",") | if . == "" then "none" else . end),
    (.RepoDigests // [] | sort | join(",") | if . == "" then "none" else . end)
  ] | @tsv')
  IFS=$'\t' read -r size revision source tags digests <<< "$image_row"
  emit "RETENTION_IMAGE id=$image_id size_bytes=$size container_refs=$refs revision=$revision source=$source tags=$tags digests=$digests"
done < <(docker image ls -q --no-trunc | sort -u)

identity_fingerprint=$(
  grep -E '^(RETENTION_ACTIVE_|RETENTION_DIRECT_PREVIOUS_PROJECT=|RETENTION_COMPOSE_ANCHOR=|RETENTION_CONTAINER |RETENTION_LISTENER )' "$inventory" |
    sort |
    sha256sum |
    awk '{print $1}'
)
fingerprint=$(sort "$inventory" | sha256sum | awk '{print $1}')
echo "RETENTION_IDENTITY_FINGERPRINT=$identity_fingerprint"
echo "RETENTION_AUDIT_FINGERPRINT=$fingerprint"
echo 'RETENTION_AUDIT=PASS mode=read_only'
