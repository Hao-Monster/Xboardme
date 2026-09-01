#!/usr/bin/env bash
set -Eeuo pipefail

# This script is concatenated after the production runtime, release-state,
# retention audit, and network-debt audit scripts in one locked SSH process.

: "${EXPECTED_NETWORK_DEBT_FINGERPRINT:?EXPECTED_NETWORK_DEBT_FINGERPRINT is required}"
: "${EXPECTED_ACTIVE_RELEASE_ID:?EXPECTED_ACTIVE_RELEASE_ID is required}"
: "${EXPECTED_ACTIVE_RELEASE_SHA:?EXPECTED_ACTIVE_RELEASE_SHA is required}"

network_cleanup_fail() {
  echo "NETWORK_DEBT_CLEANUP_FAIL=$1" >&2
  return 1
}

[[ "$EXPECTED_NETWORK_DEBT_FINGERPRINT" =~ ^[a-f0-9]{64}$ ]] || network_cleanup_fail invalid_fingerprint
[[ "$EXPECTED_ACTIVE_RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]] || network_cleanup_fail invalid_release_id
[[ "$EXPECTED_ACTIVE_RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]] || network_cleanup_fail invalid_release_sha
[[ "${RETENTION_ACQUIRE_LOCK:-false}" == true ]] || network_cleanup_fail deployment_lock_required
[[ "${active_release_id:-}" == "$EXPECTED_ACTIVE_RELEASE_ID" ]] || network_cleanup_fail active_release_id_mismatch
[[ "${active_revision:-}" == "$EXPECTED_ACTIVE_RELEASE_SHA" ]] || network_cleanup_fail active_release_sha_mismatch
[[ "${active_project:-}" == "xboard-v2-$EXPECTED_ACTIVE_RELEASE_ID" ]] || network_cleanup_fail active_project_mismatch
[[ -n "${active_container:-}" && -n "${active_app_data_id:-}" &&
   -n "${active_app_data_path:-}" && -n "${active_redis_volume:-}" &&
   -n "${workdir:-}" ]] || network_cleanup_fail retention_context_missing
case "${active_traffic_state:-}" in
  active_v2)
    if [[ "${active_rollback_supported:-}" == true ]]; then
      [[ -n "${direct_previous_project:-}" ]] || network_cleanup_fail active_v2_rollback_state_invalid
    else
      [[ "${active_rollback_supported:-}" == false && -z "${direct_previous_project:-}" ]] ||
        network_cleanup_fail active_v2_rollback_state_invalid
    fi
    ;;
  finalized)
    [[ "${active_rollback_supported:-}" == false && -z "${direct_previous_project:-}" ]] ||
      network_cleanup_fail finalized_rollback_state_invalid
    ;;
  *) network_cleanup_fail unsupported_active_traffic_state ;;
esac
[[ "${network_debt_fingerprint:-}" == "$EXPECTED_NETWORK_DEBT_FINGERPRINT" ]] || network_cleanup_fail fingerprint_mismatch
[[ -n "${network_inventory:-}" && -f "$network_inventory" ]] || network_cleanup_fail audit_inventory_missing

for tool in caddy docker jq realpath stat; do
  command -v "$tool" >/dev/null || network_cleanup_fail "missing_tool:$tool"
done

# Populated by the concatenated retention and network-debt audits.
# shellcheck disable=SC2154
baseline_active_id=$active_container
baseline_active_upstream=$XBOARD_ACTIVE_UPSTREAM
# shellcheck disable=SC2154
baseline_app_data_id=$active_app_data_id
# shellcheck disable=SC2154
baseline_redis_volume=$active_redis_volume
anchor_id=$(docker inspect -f '{{.Id}}' "$XBOARD_ANCHOR_CONTAINER")

network_debt_field() {
  local line=$1 key=$2
  if [[ "$line" =~ (^|[[:space:]])${key}=([^[:space:]]+) ]]; then
    printf '%s\n' "${BASH_REMATCH[2]}"
  else
    network_cleanup_fail "audit_field_missing:$key"
  fi
}

removed_networks=0
while IFS= read -r line; do
  [[ "$line" == 'NETWORK_DEBT_NETWORK '* ]] || continue
  [[ "$(network_debt_field "$line" classification)" == candidate ]] || continue

  id=$(network_debt_field "$line" id)
  name=$(network_debt_field "$line" name)
  project=$(network_debt_field "$line" project)
  logical=$(network_debt_field "$line" logical)
  driver=$(network_debt_field "$line" driver)
  scope=$(network_debt_field "$line" scope)
  internal=$(network_debt_field "$line" internal)
  release_id=$(network_debt_field "$line" release_id)
  traffic_state=$(network_debt_field "$line" traffic_state)
  revision=$(network_debt_field "$line" revision)

  [[ "$project" != "$active_project" && "$project" != "${direct_previous_project:-}" ]] ||
    network_cleanup_fail "protected_project_in_candidates:$project"
  [[ "$project" == "xboard-v2-$release_id" && "$name" == "${project}_${logical}" ]] ||
    network_cleanup_fail "candidate_name_changed:$name"
  [[ "$driver" == bridge && "$scope" == local ]] ||
    network_cleanup_fail "candidate_driver_changed:$name"
  case "$logical:$internal" in
    edge:true|backplane:true|ingress:false|egress:false) ;;
    *) network_cleanup_fail "candidate_network_policy_changed:$name" ;;
  esac
  case "$traffic_state" in
    rolled_back|finalized|active_v2) ;;
    *) network_cleanup_fail "candidate_state_not_removable:$name:$traffic_state" ;;
  esac

  # shellcheck disable=SC2154
  release_dir="$workdir/.codex-v2-release/$release_id"
  state_file="$release_dir/state.json"
  [[ -d "$release_dir" && ! -L "$release_dir" &&
     "$(realpath -e -- "$release_dir" 2>/dev/null || true)" == "$release_dir" &&
     -f "$state_file" && ! -L "$state_file" ]] || network_cleanup_fail "candidate_state_missing:$name"
  release_state_validate "$state_file" || network_cleanup_fail "candidate_state_invalid:$name"
  [[ "$(release_state_get "$state_file" release_id)" == "$release_id" &&
     "$(release_state_get "$state_file" project_name)" == "$project" &&
     "$(release_state_get "$state_file" traffic_state)" == "$traffic_state" &&
     "$(release_state_get "$state_file" release_sha)" == "$revision" ]] ||
    network_cleanup_fail "candidate_state_changed:$name"

  current_row=$(network_debt_inspect_row "$id") || network_cleanup_fail "candidate_missing:$name"
  IFS=$'\t' read -r current_id current_name current_driver current_scope current_internal current_project current_logical current_endpoints _current_subnets _current_created <<< "$current_row"
  [[ "$current_id" == "$id" && "$current_name" == "$name" &&
     "$current_driver" == "$driver" && "$current_scope" == "$scope" &&
     "$current_internal" == "$internal" && "$current_project" == "$project" &&
     "$current_logical" == "$logical" ]] || network_cleanup_fail "candidate_identity_changed:$name"
  [[ "$current_endpoints" == 0 ]] || network_cleanup_fail "candidate_gained_endpoints:$name:$current_endpoints"

  docker network rm "$id" >/dev/null
  ! docker network inspect "$id" >/dev/null 2>&1 || network_cleanup_fail "network_still_present:$name"
  ((removed_networks += 1))
  echo "NETWORK_DEBT_CLEANUP_NETWORK name=$name id=$id project=$project logical=$logical traffic_state=$traffic_state revision=$revision endpoints=0 volume_removal=false"
done < "$network_inventory"

((removed_networks > 0)) || network_cleanup_fail no_candidates_removed

xboard_find_caddy_upstream || network_cleanup_fail "caddy_upstream_after_cleanup:$XBOARD_DISCOVERY_ERROR"
[[ "$XBOARD_ACTIVE_UPSTREAM" == "$baseline_active_upstream" ]] || network_cleanup_fail active_upstream_changed
xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" || network_cleanup_fail "active_runtime_after_cleanup:$XBOARD_DISCOVERY_ERROR"
[[ "$(docker inspect -f '{{.Id}}' "$XBOARD_ACTIVE_WEB")" == "$baseline_active_id" ]] || network_cleanup_fail active_container_changed
# shellcheck disable=SC2154
[[ "$(stat -c '%d:%i' "$active_app_data_path")" == "$baseline_app_data_id" ]] || network_cleanup_fail active_app_data_changed
docker volume inspect "$baseline_redis_volume" >/dev/null || network_cleanup_fail active_redis_volume_missing
docker container inspect "$anchor_id" >/dev/null || network_cleanup_fail compose_anchor_missing
caddy validate --config "$XBOARD_CADDY_FILE" --adapter caddyfile >/dev/null

while IFS= read -r active_id; do
  [[ -n "$active_id" ]] || continue
  [[ "$(docker inspect -f '{{.State.Status}}' "$active_id")" == running ]] ||
    network_cleanup_fail "active_service_not_running:$active_id"
  active_health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$active_id")
  [[ "$active_health" == none || "$active_health" == healthy ]] ||
    network_cleanup_fail "active_service_unhealthy:$active_id:$active_health"
done < <(docker ps -aq --filter "label=com.docker.compose.project=$active_project")

echo "NETWORK_DEBT_CLEANUP=PASS id=$EXPECTED_ACTIVE_RELEASE_ID removed_networks=$removed_networks containers=preserved volumes=preserved images=preserved directories=preserved"
