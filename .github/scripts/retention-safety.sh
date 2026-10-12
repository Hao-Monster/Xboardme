#!/usr/bin/env bash
# Pure read helpers shared by the streamed retention audit and cleanup. Never
# print Config.Env, volume Options, or raw inspect output: those may be secrets.

retention_hash() { sha256sum | awk '{print $1}'; }

retention_container_snapshot() {
  local inspected config_hash host_hash
  inspected=$(docker inspect "$1") || return 1
  config_hash=$(jq -cS '.[0].Config' <<< "$inspected" | retention_hash) || return 1
  host_hash=$(jq -cS '.[0].HostConfig' <<< "$inspected" | retention_hash) || return 1
  jq -cS --arg config_hash "$config_hash" --arg host_hash "$host_hash" '.[0] | {
    id:.Id, name:(.Name | ltrimstr("/")), image_id:.Image,
    status:.State.Status, started_at:.State.StartedAt,
    restart_count:.RestartCount, health:(.State.Health.Status // "none"),
    project:(.Config.Labels["com.docker.compose.project"] // "none"),
    service:(.Config.Labels["com.docker.compose.service"] // "none"),
    revision:(.Config.Labels["org.opencontainers.image.revision"] // "none"),
    maintenance_release:(.Config.Labels["codex.xboard.v2.release"] // "none"),
    config_sha256:$config_hash, host_config_sha256:$host_hash,
    ports:(.NetworkSettings.Ports // {}),
    networks:((.NetworkSettings.Networks // {}) | with_entries(.value |= {
      NetworkID,EndpointID,IPAddress,GlobalIPv6Address,Aliases})),
    mounts:([.Mounts[]? | {Type,Source,Destination,Name:(.Name // ""),RW,
      Mode:(.Mode // ""),Propagation:(.Propagation // "")}] | sort_by(.Destination))
  }' <<< "$inspected"
}

retention_image_snapshot() {
  docker image inspect "$1" | jq -ceS '.[0] | {
    id:.Id, size_bytes:.Size, created_at:.Created,
    revision:(.Config.Labels["org.opencontainers.image.revision"] // "none"),
    source:(.Config.Labels["org.opencontainers.image.source"] // "none"),
    tags:((.RepoTags // []) | sort), digests:((.RepoDigests // []) | sort)
  }'
}

retention_all_snapshots() {
  local id snapshot containers='' images='' ids
  # Do not hide a failed docker listing in process substitution.
  ids=$(docker ps -aq --no-trunc | sort -u) || return 1
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    snapshot=$(retention_container_snapshot "$id") || return 1
    containers+="$snapshot"$'\n'
  done <<< "$ids"
  ids=$(docker image ls -aq --no-trunc | sort -u) || return 1
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    snapshot=$(retention_image_snapshot "$id") || return 1
    images+="$snapshot"$'\n'
  done <<< "$ids"
  jq -cnS --argjson containers "$(jq -s 'sort_by(.id)' <<< "$containers")" \
    --argjson images "$(jq -s 'sort_by(.id)' <<< "$images")" \
    '{containers:$containers,images:$images}'
}

retention_mount_identities() {
  local snapshot=$1 mount source volume identity result=''
  while IFS= read -r mount; do
    source=$(jq -r .Source <<< "$mount")
    volume=''
    case "$(jq -r .Type <<< "$mount")" in
      bind|volume)
        [[ "$source" == /* ]] || return 1
        identity=$(stat -Lc '%d:%i' -- "$source") || return 1
        if [[ $(jq -r .Type <<< "$mount") == volume ]]; then
          volume=$(docker volume inspect "$(jq -r .Name <<< "$mount")" | jq -cS \
            '.[0] | {Name,Driver,Mountpoint,Options,Labels,Scope}' | retention_hash) || return 1
        fi ;;
      tmpfs)
        # A tmpfs has no host source or persistent volume. Its configuration is
        # protected separately; do not invent a filesystem identity for it.
        [[ -z "$source" && $(jq -r .Name <<< "$mount") == '' ]] || return 1
        identity=tmpfs ;;
      *) return 1 ;;
    esac
    result+="$(jq -cS --arg identity "$identity" --arg volume "$volume" \
      '. + {source_identity:$identity,volume_identity_sha256:$volume}' <<< "$mount")"$'\n'
  done < <(jq -c '.mounts[]' <<< "$snapshot")
  jq -cs 'sort_by(.Destination)' <<< "$result"
}

retention_live_protection() {
  local root anchor id ids snapshot members='' maintenance project web image_revision
  xboard_find_compose_anchor || return 1
  root=$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR") || return 1
  [[ "$root" == "$workdir" ]] || return 1
  xboard_find_caddy_upstream || return 1
  xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" || return 1
  web=$(docker inspect -f '{{.Id}}' "$XBOARD_ACTIVE_WEB") || return 1
  project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$web") || return 1
  [[ "$project" == "$active_project" && "$web" == "$active_container" ]] || return 1
  image_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$active_image_id") || return 1
  [[ "$image_revision" == "$active_revision" ]] || return 1
  anchor=$(docker inspect -f '{{.Id}}' "$XBOARD_ANCHOR_CONTAINER") || return 1
  ids=$(docker ps -aq --no-trunc --filter "label=com.docker.compose.project=$project") || return 1
  ids+=$'\n'"$anchor"
  maintenance=$(release_state_get_optional "$active_state_file" maintenance_container) || return 1
  if [[ -n "$maintenance" ]]; then
    id=$(docker inspect -f '{{.Id}}' "$maintenance") || return 1
    ids+=$'\n'"$id"
  fi
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    snapshot=$(retention_container_snapshot "$id") || return 1
    if [[ "$id" != "$anchor" ]]; then
      jq -e '.status == "running" and (.health == "none" or .health == "healthy")' \
        <<< "$snapshot" >/dev/null || return 1
    fi
    snapshot=$(jq -cS --argjson mounts "$(retention_mount_identities "$snapshot")" \
      '.mounts=$mounts' <<< "$snapshot") || return 1
    members+="$snapshot"$'\n'
  done < <(sort -u <<< "$ids")
  # Exactly one live owner for each role inside the discovered active project.
  jq -se --arg project "$project" '
    [.[] | select(.project==$project)] as $roles |
    ($roles|length)==6 and
    (["redis","web","ws","edge","horizon","scheduler"] | all(.[]; . as $role |
      [$roles[] | select(.service==$role)] | length==1))' <<< "$members" >/dev/null || return 1
  jq -cnS --arg upstream "$XBOARD_ACTIVE_UPSTREAM" --arg caddy_file "$XBOARD_CADDY_FILE" \
    --arg caddy_hash "$(retention_hash < "$XBOARD_CADDY_FILE")" \
    --arg app_data_identity "$(stat -Lc '%d:%i' -- "$active_app_data_path")" \
    --arg redis_volume "$active_redis_volume" --argjson containers "$(jq -s 'sort_by(.id)' <<< "$members")" \
    '{upstream:$upstream,caddy_file:$caddy_file,caddy_sha256:$caddy_hash,
      app_data_identity:$app_data_identity,redis_volume:$redis_volume,containers:$containers}'
}

# The resource fingerprint is reusable by finalize *after* it owns the release
# lock. It deliberately excludes cleanup's mutable ledger/status fields.
retention_resource_fingerprint() {
  local snapshots=$1 protection=${2:-}
  [[ -n "$protection" ]] || protection=$(retention_live_protection) || return 1
  jq -cnS --slurpfile inventory "$snapshots" --slurpfile state "$active_state_file" \
    --argjson protection "$protection" '{inventory:$inventory[0],protection:$protection,
      lifecycle:($state[0]|{release_id,release_sha,traffic_state,rollback_supported,
        legacy_release_sha,legacy_anchor_id,legacy_web_id,legacy_ws_id,legacy_edge_id,
        legacy_horizon_id,legacy_scheduler_id,maintenance_container})}' | retention_hash
}

retention_review_validate() {
  jq -e '
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=([range(64)|"0"]|join(""));
    def sha: type=="string" and test("^[a-f0-9]{40}$");
    .schema_version==1 and .status=="approved" and
    (.operation=="v2_finalize" or .operation=="retention_cleanup") and
    (.active_release_id|type=="string" and test("^[0-9]+-[0-9]+$")) and
    (.active_release_sha|sha) and (.resource_fingerprint|hash) and (.protection_fingerprint|hash) and
    (.protected_image_revisions|type=="array" and all(.[];sha)) and
    (.protected_image_digests|type=="array" and all(.[];type=="string" and
      test("^ghcr\\.io/(hao-monster|fenghaoyun-monster)/xboardme@sha256:[a-f0-9]{64}$"))) and
    (.review_evidence|.backup_review_sha256|hash) and
    (.review_evidence|.monitoring_review_sha256|hash) and
    (.review_evidence|.authentication_review_sha256|hash) and
    (.review_evidence|.isolated_docker_review_sha256|hash) and
    (.containers|type=="array" and all(.[];
      (.id|hash) and (.snapshot_sha256|hash) and
      (.evidence.release_state_sha256|hash) and
      (.evidence.writable_layer_review_sha256|hash) and
      (.evidence.startup_reference_review_sha256|hash))) and
    (.images|type=="array" and all(.[];
      (.id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
      (.snapshot_sha256|hash) and (.evidence.startup_reference_review_sha256|hash))) and
    ([.containers[].id]|length== (unique|length)) and
    ([.images[].id]|length==(unique|length)) and
    ((.containers|length)+(.images|length)>0)
  ' "$1" >/dev/null
}

retention_load_review() {
  local destination=$1 operation=$2
  [[ "${EXPECTED_RETENTION_REVIEW_SHA256:-}" =~ ^[a-f0-9]{64}$ &&
     -n "${RETENTION_REVIEW_MANIFEST_B64:-}" ]] || return 1
  printf '%s' "$RETENTION_REVIEW_MANIFEST_B64" | base64 -d > "$destination" || return 1
  [[ "$(retention_hash < "$destination")" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] || return 1
  retention_review_validate "$destination" || return 1
  jq -e --arg operation "$operation" --arg id "$EXPECTED_ACTIVE_RELEASE_ID" \
    --arg sha "$EXPECTED_ACTIVE_RELEASE_SHA" --arg fingerprint "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" \
    '.operation==$operation and .active_release_id==$id and .active_release_sha==$sha and
      .resource_fingerprint==$fingerprint' "$destination" >/dev/null
}

retention_verify_reviewed_containers() {
  local review=$1 expected_ids=$2 id entry snapshot project release_id state_file state_hash
  [[ "$(jq -r '.containers[].id' "$review" | sort)" == "$(sort -u <<< "$expected_ids")" ]] || return 1
  while IFS= read -r entry; do
    id=$(jq -r .id <<< "$entry")
    snapshot=$(retention_container_snapshot "$id") || return 1
    [[ "$(printf '%s\n' "$snapshot" | retention_hash)" == "$(jq -r .snapshot_sha256 <<< "$entry")" ]] || return 1
    [[ $(jq -r .status <<< "$snapshot") == exited ]] || return 1
    project=$(jq -r .project <<< "$snapshot")
    [[ "$project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ && "$project" != "$active_project" ]] || return 1
    release_id=${BASH_REMATCH[1]}
    state_file="$workdir/.codex-v2-release/$release_id/state.json"
    [[ -f "$state_file" && ! -L "$state_file" &&
       "$(realpath -e -- "$state_file")" == "$state_file" ]] || return 1
    release_state_validate "$state_file" || return 1
    state_hash=$(retention_hash < "$state_file") || return 1
    [[ "$state_hash" == "$(jq -r .evidence.release_state_sha256 <<< "$entry")" ]] || return 1
    [[ "$(release_state_get "$state_file" release_id)" == "$release_id" &&
       "$(release_state_get "$state_file" project_name)" == "$project" ]] || return 1
    case "$(release_state_get "$state_file" traffic_state)" in
      finalized|rolled_back) ;;
      active_v2)
        [[ $(jq -r .operation "$review") == v2_finalize &&
           "$(release_state_get "$active_state_file" legacy_release_sha)" == "$(release_state_get "$state_file" release_sha)" ]] || return 1 ;;
      *) return 1 ;;
    esac
  done < <(jq -c '.containers[]' "$review")
}

retention_image_eligible() {
  local snapshot=$1 manifest=$2 now=$3 min_age=$4 created source reference
  jq -e --slurpfile review "$manifest" --arg workflow "$EXPECTED_WORKFLOW_SHA" '
    (.revision|test("^[a-f0-9]{40}$")) and
    .revision!=$workflow and
    (.revision as $r | $review[0].protected_image_revisions | index($r)==null) and
    ([.digests[] as $d | $review[0].protected_image_digests | index($d) | select(.!=null)]|length==0)
  ' <<< "$snapshot" >/dev/null || return 1
  source=$(jq -r .source <<< "$snapshot")
  case "$source" in
    https://github.com/Hao-Monster/Xboardme)
      jq -e 'any(.digests[];test("^ghcr\\.io/hao-monster/xboardme@sha256:[a-f0-9]{64}$"))' <<< "$snapshot" >/dev/null || return 1 ;;
    https://github.com/FengHaoyun-MONSTER/Xboardme)
      jq -e 'any(.digests[];test("^ghcr\\.io/fenghaoyun-monster/xboardme@sha256:[a-f0-9]{64}$"))' <<< "$snapshot" >/dev/null || return 1 ;;
    *) return 1 ;;
  esac
  while IFS= read -r reference; do
    case "$source:$reference" in
      https://github.com/Hao-Monster/Xboardme:ghcr.io/hao-monster/xboardme:*|https://github.com/Hao-Monster/Xboardme:ghcr.io/hao-monster/xboardme@sha256:*) ;;
      https://github.com/FengHaoyun-MONSTER/Xboardme:ghcr.io/fenghaoyun-monster/xboardme:*|https://github.com/FengHaoyun-MONSTER/Xboardme:ghcr.io/fenghaoyun-monster/xboardme@sha256:*|https://github.com/FengHaoyun-MONSTER/Xboardme:xboard-rollback:*|https://github.com/FengHaoyun-MONSTER/Xboardme:xboard-rollback@sha256:*) ;;
      *) return 1 ;;
    esac
  done < <(jq -r '(.tags+.digests)[]' <<< "$snapshot")
  created=$(date -u -d "$(jq -r .created_at <<< "$snapshot")" +%s) || return 1
  ((now >= created && now-created >= min_age))
}

# All objects absent from the immutable removal list must be byte-identical.
# Reviewed images may lose original aliases after a partial deletion, but may
# never gain aliases. No new object, including a new eligible old image, passes.
retention_inventory_within_plan() {
  jq -e --slurpfile current "$2" '
    . as $plan | $current[0] as $live |
    ($live.containers | all(.[]; . as $item |
      any($plan.inventory.containers[]; .==$item))) and
    ($plan.inventory.containers | all(.[]; . as $item |
      any($live.containers[]; .==$item) or any($plan.containers[]; .id==$item.id))) and
    ($live.images | all(.[]; . as $item |
      any($plan.inventory.images[]; . as $old | .id==$item.id and
        (if any($plan.images[]; .id==$item.id) then
          (del(.tags,.digests)==($item|del(.tags,.digests))) and
          (($item.tags-$old.tags)|length==0) and (($item.digests-$old.digests)|length==0)
        else .==$item end)))) and
    ($plan.inventory.images | all(.[]; . as $item |
      any($live.images[]; .id==$item.id) or any($plan.images[]; .id==$item.id)))
  ' "$1" >/dev/null
}
