#!/usr/bin/env bash
set -Eeuo pipefail

retain_online_fail() {
  echo "RETAIN_ONLINE_CLEANUP_FAIL=$1" >&2
  exit 1
}

: "${EXPECTED_WORKFLOW_SHA:?EXPECTED_WORKFLOW_SHA is required}"
: "${CLEANUP_MODE:=audit}"
: "${CLEANUP_TEST_MODE:=false}"

[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[a-f0-9]{40}$ ]] || retain_online_fail invalid_workflow_sha
case "$CLEANUP_MODE" in
  audit|cleanup) ;;
  *) retain_online_fail "invalid_cleanup_mode:$CLEANUP_MODE" ;;
esac
case "$CLEANUP_TEST_MODE" in
  true|false) ;;
  *) retain_online_fail "invalid_test_mode:$CLEANUP_TEST_MODE" ;;
esac

if [[ "$CLEANUP_TEST_MODE" != true ]]; then
  ((EUID == 0)) || retain_online_fail root_required
fi

for tool in awk basename caddy chmod curl df docker du find flock grep jq mktemp realpath rm sed sha256sum sort ss stat timeout tr wc; do
  command -v "$tool" >/dev/null 2>&1 || declare -F "$tool" >/dev/null 2>&1 || retain_online_fail "missing_tool:$tool"
done

if ! declare -F retain_online_timeout >/dev/null; then
  retain_online_timeout() {
    timeout --signal=TERM --kill-after=10s "$@"
  }
fi

if ! declare -F retain_online_flock >/dev/null; then
  retain_online_flock() {
    flock "$@"
  }
fi

if ! declare -F xboard_find_compose_anchor >/dev/null ||
   ! declare -F xboard_find_caddy_upstream >/dev/null ||
   ! declare -F xboard_resolve_active_runtime >/dev/null ||
   ! declare -F release_state_validate >/dev/null ||
   ! declare -F release_state_set_many >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=production-runtime-discovery.sh
  source "$script_dir/production-runtime-discovery.sh"
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
fi

xboard_find_compose_anchor || retain_online_fail "compose_anchor:${XBOARD_DISCOVERY_ERROR:-unknown}"
xboard_find_caddy_upstream || retain_online_fail "caddy_upstream:${XBOARD_DISCOVERY_ERROR:-unknown}"
xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" || retain_online_fail "active_runtime:${XBOARD_DISCOVERY_ERROR:-unknown}"

workdir=$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR") || retain_online_fail invalid_workdir
[[ "$workdir" == /* && -d "$workdir" && ! -L "$workdir" ]] || retain_online_fail unsafe_workdir
lock_root="$workdir/.codex-v2-release"
[[ -d "$lock_root" && ! -L "$lock_root" ]] || retain_online_fail lock_root_missing

exec 9>"$lock_root/deploy.lock"
chmod 600 "$lock_root/deploy.lock"
retain_online_flock -n 9 || retain_online_fail deployment_locked

xboard_find_compose_anchor &&
  xboard_find_caddy_upstream &&
  xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" ||
  retain_online_fail post_lock_discovery_failed
[[ "$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR")" == "$workdir" ]] || retain_online_fail post_lock_workdir_changed
[[ "$XBOARD_ACTIVE_TOPOLOGY" == v2 ]] || retain_online_fail "active_topology_not_v2:$XBOARD_ACTIVE_TOPOLOGY"

caddy validate --config "$XBOARD_CADDY_FILE" --adapter caddyfile >/dev/null || retain_online_fail caddy_config_invalid
curl --silent --show-error --fail --max-time 5 "http://127.0.0.1:$XBOARD_ACTIVE_PORT/" >/dev/null ||
  retain_online_fail "active_loopback_http_unhealthy:$XBOARD_ACTIVE_PORT"

anchor_id=$(docker inspect -f '{{.Id}}' "$XBOARD_ANCHOR_CONTAINER") || retain_online_fail anchor_inspect_failed
[[ "$anchor_id" =~ ^[0-9a-f]{64}$ ]] || retain_online_fail anchor_id_invalid
[[ "$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.service" }}' "$anchor_id")" == xboard ]] ||
  retain_online_fail anchor_service_label_mismatch

active_container=$(docker inspect -f '{{.Id}}' "$XBOARD_ACTIVE_WEB") || retain_online_fail active_web_inspect_failed
active_project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$active_container")
[[ "$active_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]] || retain_online_fail "active_v2_project_invalid:$active_project"
active_release_id=${BASH_REMATCH[1]}
active_release_dir="$lock_root/$active_release_id"
active_state_file="$active_release_dir/state.json"

[[ -d "$active_release_dir" && ! -L "$active_release_dir" &&
   "$(realpath -e -- "$active_release_dir")" == "$active_release_dir" ]] ||
  retain_online_fail active_release_dir_invalid
release_state_validate "$active_state_file" || retain_online_fail active_state_invalid

[[ "$(release_state_get "$active_state_file" release_id)" == "$active_release_id" ]] ||
  retain_online_fail active_state_release_id_mismatch
[[ "$(release_state_get "$active_state_file" project_name)" == "$active_project" ]] ||
  retain_online_fail active_state_project_mismatch

active_image_id=$(docker inspect -f '{{.Image}}' "$active_container")
active_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$active_image_id")
[[ "$active_revision" =~ ^[0-9a-f]{40}$ ]] || retain_online_fail active_revision_invalid
[[ "$(release_state_get "$active_state_file" release_sha)" == "$active_revision" ]] ||
  retain_online_fail active_state_revision_mismatch

active_redis_volume=$(release_state_get "$active_state_file" redis_volume_name)
docker volume inspect "$active_redis_volume" >/dev/null || retain_online_fail active_redis_volume_missing

active_app_data_path=$(release_state_get "$active_state_file" app_data_path)
[[ "$active_app_data_path" == /* && -d "$active_app_data_path" && ! -L "$active_app_data_path" ]] ||
  retain_online_fail active_app_data_invalid

declare -A protected_containers=()
protected_containers["$anchor_id"]="anchor:xboard"

assert_active_runtime_healthy() {
  local service role_id role_running role_health role_restarts role_oom
  local -a role_ids
  for service in redis web ws edge horizon scheduler; do
    mapfile -t role_ids < <(
      docker ps -q --no-trunc \
        --filter "label=com.docker.compose.project=$active_project" \
        --filter "label=com.docker.compose.service=$service"
    )
    ((${#role_ids[@]} == 1)) || retain_online_fail "active_service_count_invalid:$service:${#role_ids[@]}"
    role_id=$(docker inspect -f '{{.Id}}' "${role_ids[0]}")
    [[ "$role_id" =~ ^[0-9a-f]{64}$ ]] || retain_online_fail "active_service_id_invalid:$service"
    role_running=$(docker inspect -f '{{.State.Running}}' "$role_id")
    role_health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$role_id")
    role_restarts=$(docker inspect -f '{{.RestartCount}}' "$role_id")
    role_oom=$(docker inspect -f '{{.State.OOMKilled}}' "$role_id")
    [[ "$role_running" == true && "$role_health" == healthy && "$role_restarts" == 0 && "$role_oom" == false ]] ||
      retain_online_fail "active_service_unhealthy:$service:$role_running:$role_health:$role_restarts:$role_oom"
    protected_containers["$role_id"]="active:$service"
  done

  maintenance_name=$(release_state_get "$active_state_file" maintenance_container)
  maintenance_id=$(docker inspect -f '{{.Id}}' "$maintenance_name" 2>/dev/null || true)
  [[ "$maintenance_id" =~ ^[0-9a-f]{64}$ ]] || retain_online_fail "active_maintenance_missing:$maintenance_name"
  [[ "$(docker inspect -f '{{.State.Running}}' "$maintenance_id")" == true &&
     "$(docker inspect -f '{{.State.OOMKilled}}' "$maintenance_id")" == false ]] ||
    retain_online_fail "active_maintenance_not_running:$maintenance_name"
  protected_containers["$maintenance_id"]="active:maintenance"
}

assert_active_runtime_healthy
((${#protected_containers[@]} == 8)) || retain_online_fail "protected_container_count_invalid:${#protected_containers[@]}"

declare -A protected_images=()
for cid in "${!protected_containers[@]}"; do
  img_id=$(docker inspect -f '{{.Image}}' "$cid")
  [[ "$img_id" =~ ^sha256:[0-9a-f]{64}$ ]] || retain_online_fail "protected_image_id_invalid:$cid"
  docker image inspect "$img_id" >/dev/null || retain_online_fail "protected_image_missing:$img_id"
  protected_images["$img_id"]=1
done

declare -A protected_networks=(
  [bridge]=1
  [host]=1
  [none]=1
  [xboard_default]=1
)
while IFS= read -r net_id; do
  [[ -n "$net_id" ]] || continue
  protected_networks["$net_id"]=1
done < <(docker network ls --no-trunc -q --filter "label=com.docker.compose.project=$active_project")

for role_suffix in backplane edge egress ingress; do
  protected_networks["${active_project}_${role_suffix}"]=1
done

for cid in "${!protected_containers[@]}"; do
  while IFS= read -r net_ref; do
    [[ -n "$net_ref" ]] || continue
    protected_networks["$net_ref"]=1
  done < <(docker inspect "$cid" | jq -r '.[0].NetworkSettings.Networks // {} | to_entries[] | .key, .value.NetworkID')
done

previous_release_id=$(release_state_get_optional "$active_state_file" previous_release_retired_id)
if [[ -z "$previous_release_id" ]]; then
  previous_release_id=$(release_state_get_optional "$active_state_file" previous_release_retiring_id)
fi
if [[ -z "$previous_release_id" ]]; then
  legacy_anchor_recorded=$(release_state_get_optional "$active_state_file" legacy_anchor_id)
  if [[ -n "$legacy_anchor_recorded" ]] && docker container inspect "$legacy_anchor_recorded" >/dev/null 2>&1; then
    legacy_project=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$legacy_anchor_recorded" 2>/dev/null || true)
    if [[ "$legacy_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]]; then
      previous_release_id=${BASH_REMATCH[1]}
    fi
  fi
fi
[[ -n "$previous_release_id" ]] || previous_release_id=retired

declare -a candidate_containers=()
while IFS= read -r cid; do
  [[ -n "$cid" ]] || continue
  cid=$(docker inspect -f '{{.Id}}' "$cid")
  if [[ -n "${protected_containers[$cid]:-}" ]]; then
    continue
  fi
  c_running=$(docker inspect -f '{{.State.Running}}' "$cid")
  c_status=$(docker inspect -f '{{.State.Status}}' "$cid")
  [[ "$c_running" == false && "$c_status" == exited ]] ||
    retain_online_fail "non_protected_container_not_exited:$cid:$c_status"
  candidate_containers+=("$cid")
done < <(docker ps -aq --no-trunc | sort -u)

declare -a candidate_networks=()
while IFS=$'\t' read -r net_id net_name; do
  [[ -n "$net_id" && -n "$net_name" ]] || continue
  if [[ -n "${protected_networks[$net_id]:-}" || -n "${protected_networks[$net_name]:-}" ]]; then
    continue
  fi
  if [[ "$net_name" =~ ^xboard-v2-([0-9]+-[0-9]+)_(backplane|edge|egress|ingress)$ ]] &&
     [[ "${BASH_REMATCH[1]}" != "$active_release_id" ]]; then
    endpoint_count=$(docker network inspect "$net_id" | jq -r '.[0].Containers | length')
    [[ "$endpoint_count" == 0 ]] || retain_online_fail "candidate_network_has_endpoints:$net_name:$endpoint_count"
    candidate_networks+=("$net_id:$net_name")
  else
    retain_online_fail "unexpected_unprotected_network:$net_name"
  fi
done < <(docker network ls --no-trunc --format '{{.ID}}\t{{.Name}}' | sort -u)

declare -a candidate_dirs=()
while IFS= read -r dir_path; do
  [[ -n "$dir_path" && -d "$dir_path" && ! -L "$dir_path" ]] || continue
  dir_name=$(basename -- "$dir_path")
  [[ "$dir_name" != "$active_release_id" ]] || continue
  candidate_dirs+=("$dir_path")
done < <(find "$lock_root" -mindepth 1 -maxdepth 1 -type d -print | sort)

for hist_root in "$workdir/.codex-release" "$workdir/.codex-stage" "$workdir/.codex-admin-hotfix" "$workdir/.codex-deploy"; do
  [[ -d "$hist_root" && ! -L "$hist_root" ]] || continue
  while IFS= read -r dir_path; do
    [[ -n "$dir_path" && -d "$dir_path" && ! -L "$dir_path" ]] || continue
    candidate_dirs+=("$dir_path")
  done < <(find "$hist_root" -mindepth 1 -maxdepth 1 -type d -print | sort)
done

declare -a candidate_images=()
while IFS= read -r img_id; do
  [[ -n "$img_id" ]] || continue
  if [[ -n "${protected_images[$img_id]:-}" ]]; then
    continue
  fi
  candidate_images+=("$img_id")
done < <(docker image ls -aq --no-trunc | sort -u)

disk_before_kib=$(df -Pk "$workdir" | awk 'NR == 2 {print $4}')
echo "RETAIN_ONLINE_CLEANUP_SCHEMA=1"
echo "RETAIN_ONLINE_WORKFLOW_SHA=$EXPECTED_WORKFLOW_SHA"
echo "RETAIN_ONLINE_MODE=$CLEANUP_MODE"
echo "RETAIN_ONLINE_ACTIVE_PROJECT=$active_project"
echo "RETAIN_ONLINE_ACTIVE_RELEASE_ID=$active_release_id"
echo "RETAIN_ONLINE_ACTIVE_REVISION=$active_revision"
echo "RETAIN_ONLINE_PROTECTED_CONTAINERS=${#protected_containers[@]}"
echo "RETAIN_ONLINE_PROTECTED_IMAGES=${#protected_images[@]}"
echo "RETAIN_ONLINE_CANDIDATE_CONTAINERS=${#candidate_containers[@]}"
echo "RETAIN_ONLINE_CANDIDATE_NETWORKS=${#candidate_networks[@]}"
echo "RETAIN_ONLINE_CANDIDATE_DIRECTORIES=${#candidate_dirs[@]}"
echo "RETAIN_ONLINE_CANDIDATE_IMAGES=${#candidate_images[@]}"
echo "RETAIN_ONLINE_DISK_BEFORE_KIB=$disk_before_kib"

if [[ "$CLEANUP_MODE" == audit ]]; then
  echo "RETAIN_ONLINE_CLEANUP=PASS mode=audit active_release=$active_release_id protected_containers=${#protected_containers[@]} protected_images=${#protected_images[@]} candidate_containers=${#candidate_containers[@]} candidate_networks=${#candidate_networks[@]} candidate_directories=${#candidate_dirs[@]} candidate_images=${#candidate_images[@]}"
  exit 0
fi

removed_containers=0
for cid in "${candidate_containers[@]}"; do
  [[ -z "${protected_containers[$cid]:-}" ]] || retain_online_fail "attempted_protected_container:$cid"
  [[ "$(docker inspect -f '{{.State.Running}}' "$cid")" == false ]] || retain_online_fail "container_became_running:$cid"
  retain_online_timeout 60s docker container rm "$cid" >/dev/null
  ! docker container inspect "$cid" >/dev/null 2>&1 || retain_online_fail "container_remove_failed:$cid"
  ((removed_containers += 1))
done

release_state_set_many "$active_state_file" \
  traffic_state finalized \
  finalized_at "$(date -u +%FT%TZ)" \
  rollback_supported false \
  finalize_reason retention_window \
  finalize_status complete \
  previous_release_retired_id "$previous_release_id" \
  previous_maintenance_retired none \
  compose_anchor_preserved "$anchor_id" \
  current_maintenance_preserved "$maintenance_id"

rm -f -- \
  "$lock_root/image-cleanup-gate.json" \
  "$lock_root/image-cleanup-gate.lock" \
  "$lock_root/image-cleanup-live-signals.json"
find "$lock_root" -maxdepth 1 -type f \
  \( -name 'image-cleanup-plan-*.json' -o -name 'image-cleanup-proof-*.json' -o -name 'image-cleanup-gate-history-*.json' \) \
  -exec rm -f -- {} +

removed_dirs=0
for dir_path in "${candidate_dirs[@]}"; do
  [[ "$dir_path" != "$active_release_dir" && "$dir_path" != "$workdir" && "$dir_path" == "$workdir/"* && ! -L "$dir_path" ]] ||
    retain_online_fail "unsafe_candidate_dir:$dir_path"
  rm -rf -- "$dir_path"
  ((removed_dirs += 1))
done

removed_networks=0
for net_entry in "${candidate_networks[@]}"; do
  net_id=${net_entry%%:*}
  net_name=${net_entry#*:}
  [[ -z "${protected_networks[$net_id]:-}" && -z "${protected_networks[$net_name]:-}" ]] ||
    retain_online_fail "attempted_protected_network:$net_name"
  endpoint_count=$(docker network inspect "$net_id" | jq -r '.[0].Containers | length')
  [[ "$endpoint_count" == 0 ]] || retain_online_fail "network_gained_endpoints:$net_name"
  retain_online_timeout 30s docker network rm "$net_id" >/dev/null
  ! docker network inspect "$net_id" >/dev/null 2>&1 || retain_online_fail "network_remove_failed:$net_name"
  ((removed_networks += 1))
done

removed_images=0
for img_id in "${candidate_images[@]}"; do
  [[ -z "${protected_images[$img_id]:-}" ]] || retain_online_fail "attempted_protected_image:$img_id"
  if ! docker image inspect "$img_id" >/dev/null 2>&1; then
    ((removed_images += 1))
    continue
  fi
  while IFS= read -r ref; do
    [[ -n "$ref" ]] || continue
    if ! docker image inspect "$img_id" >/dev/null 2>&1; then
      break
    fi
    resolved=$(docker image inspect -f '{{.Id}}' "$ref" 2>/dev/null || true)
    if [[ "$resolved" == "$img_id" ]]; then
      retain_online_timeout 90s docker image rm "$ref" >/dev/null
    fi
  done < <(docker image inspect "$img_id" | jq -r '.[0] | ((.RepoTags // []) + (.RepoDigests // [])) | unique[]')
  if docker image inspect "$img_id" >/dev/null 2>&1; then
    retain_online_timeout 90s docker image rm "$img_id" >/dev/null
  fi
  ! docker image inspect "$img_id" >/dev/null 2>&1 || retain_online_fail "image_remove_failed:$img_id"
  ((removed_images += 1))
done

assert_active_runtime_healthy
docker container inspect "$anchor_id" >/dev/null || retain_online_fail anchor_missing_after_cleanup
for img_id in "${!protected_images[@]}"; do
  docker image inspect "$img_id" >/dev/null || retain_online_fail "protected_image_missing_after_cleanup:$img_id"
done
caddy validate --config "$XBOARD_CADDY_FILE" --adapter caddyfile >/dev/null || retain_online_fail caddy_invalid_after_cleanup
curl --silent --show-error --fail --max-time 5 "http://127.0.0.1:$XBOARD_ACTIVE_PORT/" >/dev/null ||
  retain_online_fail active_loopback_http_unhealthy_after_cleanup
release_assert_cleanup_admission "$workdir" || retain_online_fail cleanup_admission_still_blocked

remaining_containers=$(docker ps -aq --no-trunc | sort -u | wc -l | tr -d '[:space:]')
remaining_images=$(docker image ls -aq --no-trunc | sort -u | wc -l | tr -d '[:space:]')
[[ "$remaining_containers" == "${#protected_containers[@]}" ]] ||
  retain_online_fail "remaining_container_count_mismatch:$remaining_containers:${#protected_containers[@]}"
[[ "$remaining_images" == "${#protected_images[@]}" ]] ||
  retain_online_fail "remaining_image_count_mismatch:$remaining_images:${#protected_images[@]}"

disk_after_kib=$(df -Pk "$workdir" | awk 'NR == 2 {print $4}')
reclaimed_kib=$((disk_after_kib - disk_before_kib))

echo "RETAIN_ONLINE_REMOVED_CONTAINERS=$removed_containers"
echo "RETAIN_ONLINE_REMOVED_NETWORKS=$removed_networks"
echo "RETAIN_ONLINE_REMOVED_DIRECTORIES=$removed_dirs"
echo "RETAIN_ONLINE_REMOVED_IMAGES=$removed_images"
echo "RETAIN_ONLINE_REMAINING_CONTAINERS=$remaining_containers"
echo "RETAIN_ONLINE_REMAINING_IMAGES=$remaining_images"
echo "RETAIN_ONLINE_DISK_AFTER_KIB=$disk_after_kib"
echo "RETAIN_ONLINE_RECLAIMED_KIB=$reclaimed_kib"
echo "RETAIN_ONLINE_CLEANUP=PASS mode=cleanup active_release=$active_release_id removed_containers=$removed_containers removed_networks=$removed_networks removed_directories=$removed_dirs removed_images=$removed_images remaining_containers=$remaining_containers remaining_images=$remaining_images reclaimed_kib=$reclaimed_kib"
