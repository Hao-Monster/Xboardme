#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_get >/dev/null || ! declare -F v2_acquire_lock >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
  # shellcheck source=v2-low-memory-common.sh
  source "$script_dir/v2-low-memory-common.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"

v2_require_tools
v2_validate_release_id
v2_find_workdir
v2_acquire_lock

# Port resolution is intentionally limited to the stable release-state keys.
# A release can remain the active rollback target after newer state fields are
# introduced, so read-only smoke tests must not require fields that did not
# exist when that release was prepared.
V2_RELEASE_DIR="$V2_WORKDIR/.codex-v2-release/$RELEASE_ID"
[[ -d "$V2_RELEASE_DIR" && ! -L "$V2_RELEASE_DIR" ]] || v2_fail release_directory_missing
V2_RELEASE_DIR=$(realpath -e -- "$V2_RELEASE_DIR")
V2_STATE_FILE=$(release_state_open "$V2_RELEASE_DIR") || v2_fail release_state_missing
[[ "$(stat -c '%u:%a' "$V2_RELEASE_DIR")" == 0:700 ]] || v2_fail insecure_release_directory
[[ "$(stat -c '%u:%a' "$V2_STATE_FILE")" == 0:600 ]] || v2_fail insecure_release_state

state_schema_version=$(release_state_get "$V2_STATE_FILE" v2_schema_version)
state_release_id=$(release_state_get "$V2_STATE_FILE" release_id)
release_sha=$(release_state_get "$V2_STATE_FILE" release_sha)
project_name=$(release_state_get "$V2_STATE_FILE" project_name)
active_port=$(release_state_get "$V2_STATE_FILE" active_port)
traffic_state=$(release_state_get "$V2_STATE_FILE" traffic_state)
state_workdir=$(release_state_get "$V2_STATE_FILE" workdir)
state_release_dir=$(release_state_get "$V2_STATE_FILE" release_dir)

[[ "$state_schema_version" == "$V2_RELEASE_STATE_SCHEMA" ]] || v2_fail release_state_schema_mismatch
[[ "$state_release_id" == "$RELEASE_ID" ]] || v2_fail release_identity_mismatch
[[ "$release_sha" == "$EXPECTED_RELEASE_SHA" && "$release_sha" =~ ^[0-9a-f]{40}$ ]] ||
  v2_fail release_sha_mismatch
[[ "$project_name" == "xboard-v2-$RELEASE_ID" ]] || v2_fail project_identity_mismatch
[[ "$state_workdir" == "$V2_WORKDIR" && "$state_release_dir" == "$V2_RELEASE_DIR" ]] ||
  v2_fail release_path_mismatch
[[ "$active_port" =~ ^[0-9]+$ ]] || v2_fail invalid_active_port
((active_port >= 1 && active_port <= 65535)) || v2_fail invalid_active_port
case "$traffic_state" in
  prepared|maintenance|ready|active_v2|rolled_back|finalizing|finalized) ;;
  *) v2_fail "invalid_resolve_port_state:$traffic_state" ;;
esac

mapfile -t project_ids < <(docker ps -q --filter "label=com.docker.compose.project=$project_name")
port_owner_ids=()
for project_id in "${project_ids[@]}"; do
  if docker port "$project_id" 7001/tcp 2>/dev/null | grep -Fxq "127.0.0.1:$active_port"; then
    port_owner_ids+=("$project_id")
  fi
done
((${#port_owner_ids[@]} == 1)) || v2_fail "active_port_owner_count:${#port_owner_ids[@]}"
[[ "$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.service" }}' "${port_owner_ids[0]}")" == edge ]] ||
  v2_fail active_port_owner_not_edge

mapfile -t web_ids < <(
  docker ps -q \
    --filter "label=com.docker.compose.project=$project_name" \
    --filter 'label=com.docker.compose.service=web'
)
((${#web_ids[@]} == 1)) || v2_fail "active_web_count:${#web_ids[@]}"
web_image_id=$(docker inspect -f '{{.Image}}' "${web_ids[0]}")
web_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$web_image_id")
[[ "$web_revision" == "$release_sha" ]] || v2_fail active_web_revision_mismatch

printf '%s\n' "$active_port"
