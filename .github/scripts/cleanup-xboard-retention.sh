#!/usr/bin/env bash
set -Eeuo pipefail

# This script is concatenated after production-runtime-discovery.sh,
# release-state.sh, and audit-xboard-retention.sh in one locked SSH process.

: "${EXPECTED_RETENTION_RESOURCE_FINGERPRINT:?EXPECTED_RETENTION_RESOURCE_FINGERPRINT is required}"
: "${EXPECTED_ACTIVE_RELEASE_ID:?EXPECTED_ACTIVE_RELEASE_ID is required}"
: "${EXPECTED_ACTIVE_RELEASE_SHA:?EXPECTED_ACTIVE_RELEASE_SHA is required}"
: "${RETENTION_IMAGE_MIN_AGE_SECONDS:=604800}"

cleanup_fail() {
  echo "RETENTION_CLEANUP_FAIL=$1" >&2
  return 1
}

[[ "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" =~ ^[a-f0-9]{64}$ ]] || cleanup_fail invalid_resource_fingerprint
[[ "$EXPECTED_ACTIVE_RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]] || cleanup_fail invalid_release_id
[[ "$EXPECTED_ACTIVE_RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]] || cleanup_fail invalid_release_sha
[[ "$RETENTION_IMAGE_MIN_AGE_SECONDS" =~ ^[0-9]+$ ]] || cleanup_fail invalid_image_min_age
((RETENTION_IMAGE_MIN_AGE_SECONDS >= 604800)) || cleanup_fail image_min_age_below_seven_days
[[ "${RETENTION_REQUIRE_FINALIZED:-false}" == true ]] || cleanup_fail finalized_audit_required
[[ "${RETENTION_ACQUIRE_LOCK:-false}" == true ]] || cleanup_fail deployment_lock_required
[[ "${active_release_id:-}" == "$EXPECTED_ACTIVE_RELEASE_ID" ]] || cleanup_fail active_release_id_mismatch
[[ "${active_revision:-}" == "$EXPECTED_ACTIVE_RELEASE_SHA" ]] || cleanup_fail active_release_sha_mismatch
[[ "${active_project:-}" == "xboard-v2-$EXPECTED_ACTIVE_RELEASE_ID" ]] || cleanup_fail active_project_mismatch
[[ "${active_traffic_state:-}" == finalized ]] || cleanup_fail active_release_not_finalized
[[ "${active_rollback_supported:-}" == false ]] || cleanup_fail rollback_support_not_closed
[[ -z "${direct_previous_project:-}" ]] || cleanup_fail direct_rollback_still_present
[[ -n "${active_state_file:-}" && -f "$active_state_file" ]] || cleanup_fail active_state_missing
[[ -n "${inventory:-}" && -f "$inventory" ]] || cleanup_fail audit_inventory_missing

for tool in caddy date docker grep jq sed sort stat tr; do
  command -v "$tool" >/dev/null || cleanup_fail "missing_tool:$tool"
done

recorded_cleanup_fingerprint=$(release_state_get_optional "$active_state_file" retention_cleanup_source_fingerprint)
recorded_cleanup_status=$(release_state_get_optional "$active_state_file" retention_cleanup_status)
if [[ -z "$recorded_cleanup_fingerprint" ]]; then
  [[ "${resource_fingerprint:-}" == "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" ]] || cleanup_fail resource_fingerprint_mismatch
else
  [[ "$recorded_cleanup_fingerprint" == "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" ]] || cleanup_fail cleanup_retry_fingerprint_mismatch
fi
case "$recorded_cleanup_status" in
  ''|running|failed|complete) ;;
  *) cleanup_fail invalid_cleanup_retry_status ;;
esac
if [[ "$recorded_cleanup_status" == complete ]]; then
  echo "RETENTION_CLEANUP=PASS id=$EXPECTED_ACTIVE_RELEASE_ID result=already_complete volumes=preserved directories=preserved"
  exit 0
fi

cleanup_state_started=false
cleanup_on_error() {
  local status=$?
  trap - ERR
  if [[ "$cleanup_state_started" == true ]]; then
    release_state_set "$active_state_file" retention_cleanup_status failed || true
    release_state_set "$active_state_file" retention_cleanup_failed_at "$(date -u +%FT%TZ)" || true
  fi
  exit "$status"
}
trap cleanup_on_error ERR

if [[ -z "$recorded_cleanup_fingerprint" ]]; then
  release_state_set "$active_state_file" retention_cleanup_source_fingerprint "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT"
  release_state_set "$active_state_file" retention_cleanup_workflow_sha "$EXPECTED_WORKFLOW_SHA"
fi
release_state_set "$active_state_file" retention_cleanup_status running
release_state_set "$active_state_file" retention_cleanup_started_at "$(date -u +%FT%TZ)"
cleanup_state_started=true

baseline_active_id=$active_container
baseline_active_upstream=$XBOARD_ACTIVE_UPSTREAM
baseline_app_data_id=$active_app_data_id
baseline_redis_volume=$active_redis_volume
anchor_id=$(docker inspect -f '{{.Id}}' "$XBOARD_ANCHOR_CONTAINER")
anchor_image_id=$(docker inspect -f '{{.Image}}' "$anchor_id")

retention_field() {
  local line=$1 key=$2
  if [[ "$line" =~ (^|[[:space:]])${key}=([^[:space:]]+) ]]; then
    printf '%s\n' "${BASH_REMATCH[2]}"
  else
    cleanup_fail "audit_field_missing:$key"
  fi
}

retention_current_container_row() {
  docker inspect "$1" | jq -r '.[0] | [
    (.Name | ltrimstr("/")),
    .State.Status,
    (.Config.Labels["org.opencontainers.image.revision"] // "none"),
    (.Config.Labels["com.docker.compose.project"] // "none"),
    (.Config.Labels["com.docker.compose.service"] // "none"),
    (.Config.Labels["codex.xboard.v2.release"] // "none"),
    (.Image // ""),
    ([.NetworkSettings.Ports // {} | to_entries[] | .value[]? | ((.HostIp // "") + ":" + (.HostPort // ""))] | join(",") | if . == "" then "none" else . end),
    ([.Mounts[]? | ((.Type // "") + ":" + (.Destination // ""))] | sort | join(",") | if . == "" then "none" else . end)
  ] | @tsv'
}

removed_containers=0
removed_running_maintenance=0
while IFS= read -r line; do
  [[ "$line" == 'RETENTION_CONTAINER '* ]] || continue
  classification=$(retention_field "$line" classification)
  case "$classification" in
    retired_v2_candidate|retired_legacy_candidate) ;;
    *) continue ;;
  esac

  id=$(retention_field "$line" id)
  name=$(retention_field "$line" name)
  status=$(retention_field "$line" status)
  revision=$(retention_field "$line" revision)
  project=$(retention_field "$line" project)
  service=$(retention_field "$line" service)
  maintenance_release=$(retention_field "$line" maintenance_release)
  image_id=$(retention_field "$line" image_id)
  ports=$(retention_field "$line" ports)
  mounts=$(retention_field "$line" mounts)

  [[ -z "${protected_ids[$id]:-}" ]] || cleanup_fail "candidate_became_protected:$name"
  [[ "$id" != "$baseline_active_id" && "$id" != "$anchor_id" ]] || cleanup_fail "protected_identity_in_candidates:$name"
  docker container inspect "$id" >/dev/null 2>&1 || continue

  current_row=$(retention_current_container_row "$id")
  IFS=$'\t' read -r current_name current_status current_revision current_project current_service \
    current_maintenance current_image current_ports current_mounts <<< "$current_row"
  [[ "$current_name" == "$name" && "$current_status" == "$status" &&
     "$current_revision" == "$revision" && "$current_project" == "$project" &&
     "$current_service" == "$service" && "$current_maintenance" == "$maintenance_release" &&
     "$current_image" == "$image_id" && "$current_ports" == "$ports" &&
     "$current_mounts" == "$mounts" ]] || cleanup_fail "candidate_identity_changed:$name"

  case "$status" in
    exited|created) ;;
    running)
      [[ "$classification" == retired_v2_candidate &&
         "$project" == none && "$service" == none &&
         "$maintenance_release" =~ ^[0-9]+-[0-9]+$ &&
         "$ports" =~ ^127\.0\.0\.1:([0-9]+)$ ]] || cleanup_fail "running_candidate_not_maintenance:$name"
      candidate_port=${BASH_REMATCH[1]}
      ((candidate_port >= 7003 && candidate_port <= 7010)) || cleanup_fail "maintenance_port_out_of_range:$name"
      [[ "127.0.0.1:$candidate_port" != "$baseline_active_upstream" ]] || cleanup_fail "maintenance_uses_active_port:$name"
      caddy_references=$(grep -Ec "127\\.0\\.0\\.1:${candidate_port}([^0-9]|$)" "$XBOARD_CADDY_FILE" || true)
      [[ "$caddy_references" == 0 ]] || cleanup_fail "maintenance_referenced_by_caddy:$name"
      docker stop --time 10 "$id" >/dev/null
      ((removed_running_maintenance += 1))
      ;;
    *) cleanup_fail "candidate_status_not_removable:$name:$status" ;;
  esac

  docker container rm "$id" >/dev/null
  docker container inspect "$id" >/dev/null 2>&1 && cleanup_fail "container_still_present:$name"
  ((removed_containers += 1))
  echo "RETENTION_CLEANUP_CONTAINER name=$name id=$id classification=$classification volume_removal=false"
done < "$inventory"

declare -A remaining_image_refs=()
while IFS= read -r remaining_container; do
  [[ -n "$remaining_container" ]] || continue
  remaining_image=$(docker inspect -f '{{.Image}}' "$remaining_container")
  remaining_image_refs["$remaining_image"]=$(( ${remaining_image_refs[$remaining_image]:-0} + 1 ))
done < <(docker ps -aq --no-trunc | sort)

now_epoch=$(date -u +%s)
removed_images=0
removed_image_bytes=0
skipped_young_images=0
while IFS= read -r candidate_image_id; do
  [[ -n "$candidate_image_id" ]] || continue
  [[ "$candidate_image_id" != "$active_image_id" && "$candidate_image_id" != "$anchor_image_id" ]] || continue
  ((${remaining_image_refs[$candidate_image_id]:-0} == 0)) || continue

  image_row=$(docker image inspect "$candidate_image_id" | jq -r '.[0] | [
    (.Size | tostring),
    (.Created // "none"),
    (.Config.Labels["org.opencontainers.image.revision"] // "none"),
    (.Config.Labels["org.opencontainers.image.source"] // "none"),
    (.RepoTags // [] | sort | join(",") | if . == "" then "none" else . end),
    (.RepoDigests // [] | sort | join(",") | if . == "" then "none" else . end)
  ] | @tsv')
  IFS=$'\t' read -r image_size image_created image_revision image_source image_tags image_digests <<< "$image_row"
  case "$image_source" in
    https://github.com/Hao-Monster/Xboardme)
      [[ "$image_digests" == *'ghcr.io/hao-monster/xboardme@sha256:'* ]] || continue
      ;;
    https://github.com/FengHaoyun-MONSTER/Xboardme)
      [[ "$image_digests" == *'ghcr.io/fenghaoyun-monster/xboardme@sha256:'* ]] || continue
      ;;
    *) continue ;;
  esac
  [[ "$image_revision" =~ ^[a-f0-9]{40}$ ]] || continue
  image_references_allowed=true
  while IFS= read -r image_reference; do
    [[ -n "$image_reference" && "$image_reference" != none ]] || continue
    case "$image_source" in
      https://github.com/Hao-Monster/Xboardme)
        case "$image_reference" in
          ghcr.io/hao-monster/xboardme@sha256:*|ghcr.io/hao-monster/xboardme:*) ;;
          *) image_references_allowed=false ;;
        esac
        ;;
      https://github.com/FengHaoyun-MONSTER/Xboardme)
        case "$image_reference" in
          ghcr.io/fenghaoyun-monster/xboardme@sha256:*|ghcr.io/fenghaoyun-monster/xboardme:*|xboard-rollback:*) ;;
          *) image_references_allowed=false ;;
        esac
        ;;
    esac
  done < <(printf '%s\n%s\n' "$image_tags" "$image_digests" | tr ',' '\n' | sort -u)
  [[ "$image_references_allowed" == true ]] || continue
  image_created_epoch=$(date -u -d "$image_created" +%s) || cleanup_fail "image_created_invalid:$candidate_image_id"
  image_age_seconds=$((now_epoch - image_created_epoch))
  ((image_age_seconds >= 0)) || cleanup_fail "image_created_in_future:$candidate_image_id"
  if ((image_age_seconds < RETENTION_IMAGE_MIN_AGE_SECONDS)); then
    ((skipped_young_images += 1))
    continue
  fi

  while IFS= read -r image_reference; do
    [[ -n "$image_reference" && "$image_reference" != none ]] || continue
    docker image rm "$image_reference" >/dev/null
  done < <(printf '%s\n%s\n' "$image_tags" "$image_digests" | tr ',' '\n' | sort -u)
  if docker image inspect "$candidate_image_id" >/dev/null 2>&1; then
    docker image rm "$candidate_image_id" >/dev/null
  fi
  ((removed_images += 1))
  ((removed_image_bytes += image_size))
  echo "RETENTION_CLEANUP_IMAGE id=$candidate_image_id revision=$image_revision source=$image_source age_seconds=$image_age_seconds size_bytes=$image_size force=false"
done < <(docker image ls -q --no-trunc | sort -u)

xboard_find_caddy_upstream || cleanup_fail "caddy_upstream_after_cleanup:$XBOARD_DISCOVERY_ERROR"
[[ "$XBOARD_ACTIVE_UPSTREAM" == "$baseline_active_upstream" ]] || cleanup_fail active_upstream_changed
xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" || cleanup_fail "active_runtime_after_cleanup:$XBOARD_DISCOVERY_ERROR"
[[ "$(docker inspect -f '{{.Id}}' "$XBOARD_ACTIVE_WEB")" == "$baseline_active_id" ]] || cleanup_fail active_container_changed
[[ "$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$active_image_id")" == "$EXPECTED_ACTIVE_RELEASE_SHA" ]] || cleanup_fail active_image_revision_changed
[[ "$(stat -c '%d:%i' "$active_app_data_path")" == "$baseline_app_data_id" ]] || cleanup_fail active_app_data_changed
docker volume inspect "$baseline_redis_volume" >/dev/null || cleanup_fail active_redis_volume_missing
docker container inspect "$anchor_id" >/dev/null || cleanup_fail compose_anchor_missing
caddy validate --config "$XBOARD_CADDY_FILE" --adapter caddyfile >/dev/null

while IFS= read -r active_id; do
  [[ -n "$active_id" ]] || continue
  active_status=$(docker inspect -f '{{.State.Status}}' "$active_id")
  [[ "$active_status" == running ]] || cleanup_fail "active_service_not_running:$active_id:$active_status"
  active_health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$active_id")
  [[ "$active_health" == none || "$active_health" == healthy ]] || cleanup_fail "active_service_unhealthy:$active_id:$active_health"
done < <(docker ps -aq --filter "label=com.docker.compose.project=$active_project")

release_state_set "$active_state_file" retention_cleanup_status complete
release_state_set "$active_state_file" retention_cleanup_completed_at "$(date -u +%FT%TZ)"
release_state_set "$active_state_file" retention_cleanup_removed_containers "$removed_containers"
release_state_set "$active_state_file" retention_cleanup_removed_images "$removed_images"
release_state_set "$active_state_file" retention_cleanup_removed_image_bytes "$removed_image_bytes"
cleanup_state_started=false
trap - ERR

echo "RETENTION_CLEANUP=PASS id=$EXPECTED_ACTIVE_RELEASE_ID removed_containers=$removed_containers removed_running_maintenance=$removed_running_maintenance removed_images=$removed_images removed_image_bytes=$removed_image_bytes skipped_young_images=$skipped_young_images volumes=preserved directories=preserved"
