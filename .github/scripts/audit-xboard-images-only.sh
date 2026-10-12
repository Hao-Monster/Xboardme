#!/usr/bin/env bash
set -Eeuo pipefail
# Streamed after discovery, release-state, retention-safety and startup helpers.
# No production database query, plugin execution, recursive du or login occurs.
: "${EXPECTED_WORKFLOW_SHA:?required}"
[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[a-f0-9]{40}$ ]] || exit 1
image_audit_fail() { echo "IMAGE_AUDIT_FAIL=$1" >&2; exit 1; }
for tool in awk base64 date df docker find flock jq mktemp realpath sha256sum stat timeout; do
  command -v "$tool" >/dev/null || image_audit_fail "missing_tool:$tool"
done
# Bound metadata inspection too. Killing this read client does not mutate Docker.
docker() { command timeout --signal=TERM --kill-after=2s 10s docker "$@"; }
xboard_find_compose_anchor || image_audit_fail anchor_discovery
workdir=$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR")
lock_root="$workdir/.codex-v2-release"
[[ -d "$lock_root" && ! -L "$lock_root" && "$(realpath -e -- "$lock_root")" == "$lock_root" &&
   "$(stat -c '%u:%a' -- "$lock_root")" == "$EUID:700" &&
   -f "$lock_root/deploy.lock" && ! -L "$lock_root/deploy.lock" &&
   "$(stat -c '%u:%a:%h' -- "$lock_root/deploy.lock")" == "$EUID:600:1" ]] || image_audit_fail unsafe_lock_root
exec 9<>"$lock_root/deploy.lock"
flock -n 9 || image_audit_fail deployment_locked
RETENTION_ACQUIRE_LOCK=true
RETENTION_REQUIRE_FINALIZED=false
xboard_find_compose_anchor && xboard_find_caddy_upstream && xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT" || image_audit_fail live_discovery
[[ "$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR")" == "$workdir" && "$XBOARD_ACTIVE_TOPOLOGY" == v2 ]] || image_audit_fail topology_changed
active_container=$(docker inspect -f '{{.Id}}' "$XBOARD_ACTIVE_WEB")
active_project=$(xboard_container_label "$active_container" com.docker.compose.project)
[[ "$active_project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]] || image_audit_fail invalid_active_project
active_release_id=${BASH_REMATCH[1]}
active_state_file="$lock_root/$active_release_id/state.json"
[[ -f "$active_state_file" && ! -L "$active_state_file" && "$(realpath -e -- "$active_state_file")" == "$active_state_file" ]] || image_audit_fail unsafe_active_state
release_state_validate "$active_state_file" || image_audit_fail invalid_active_state
active_image_id=$(docker inspect -f '{{.Image}}' "$active_container")
active_revision=$(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$active_image_id")
[[ "$active_revision" =~ ^[a-f0-9]{40}$ && "$(release_state_get "$active_state_file" release_id)" == "$active_release_id" &&
   "$(release_state_get "$active_state_file" release_sha)" == "$active_revision" ]] || image_audit_fail active_identity_mismatch
active_traffic_state=$(release_state_get "$active_state_file" traffic_state)
active_rollback_supported=$(release_state_get "$active_state_file" rollback_supported)
[[ "$active_traffic_state" == active_v2 || "$active_traffic_state" == finalized ]] || image_audit_fail transitional_runtime
[[ "$active_rollback_supported" == true || "$active_rollback_supported" == false ]] || image_audit_fail unknown_rollback_state
active_app_data_path=$(release_state_get "$active_state_file" app_data_path)
active_redis_volume=$(release_state_get "$active_state_file" redis_volume_name)
[[ "$active_app_data_path" == /* && -d "$active_app_data_path" && ! -L "$active_app_data_path" ]] || image_audit_fail invalid_data_path
if [[ -n "${EXPECTED_ACTIVE_RELEASE_ID:-}" ]]; then
  [[ "$active_release_id" == "$EXPECTED_ACTIVE_RELEASE_ID" && "$active_revision" == "$EXPECTED_ACTIVE_RELEASE_SHA" ]] || image_audit_fail unexpected_active_release
fi
retention_snapshot_file=$(mktemp "$lock_root/.image-inventory.XXXXXX")
retention_startup_file=$(mktemp "$lock_root/.image-startup.XXXXXX")
inventory=$(mktemp "$lock_root/.image-audit.XXXXXX")
chmod 600 "$retention_snapshot_file" "$retention_startup_file" "$inventory"
trap 'rm -f -- "$retention_snapshot_file" "$retention_startup_file" "$inventory"' EXIT
images_only_all_snapshots > "$retention_snapshot_file" || image_audit_fail inventory_unavailable
protection_snapshot=$(retention_live_protection) || image_audit_fail protection_invalid
protection_fingerprint=$(printf '%s\n' "$protection_snapshot" | retention_hash)
retention_startup_references > "$retention_startup_file" || image_audit_fail startup_references_unresolved
resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file" "$protection_snapshot")
emit() { printf '%s\n' "$*" | tee -a "$inventory"; }
emit 'IMAGE_AUDIT_SCHEMA=1'
emit "IMAGE_AUDIT_WORKFLOW_SHA=$EXPECTED_WORKFLOW_SHA"
emit "IMAGE_AUDIT_ACTIVE_RELEASE_ID=$active_release_id"
emit "IMAGE_AUDIT_ACTIVE_REVISION=$active_revision"
emit "IMAGE_AUDIT_ACTIVE_STATE_SHA256=$(retention_hash < "$active_state_file")"
emit "IMAGE_AUDIT_ACTIVE_UPSTREAM=$XBOARD_ACTIVE_UPSTREAM"
emit "IMAGE_AUDIT_TRAFFIC_STATE=$active_traffic_state"
emit "IMAGE_AUDIT_ROLLBACK_SUPPORTED=$active_rollback_supported"
emit "IMAGE_AUDIT_RESOURCE_FINGERPRINT=$resource_fingerprint"
emit "IMAGE_AUDIT_INVENTORY_SHA256=$(retention_hash < "$retention_snapshot_file")"
emit "IMAGE_AUDIT_CONTAINER_COUNT=$(jq '.containers|length' "$retention_snapshot_file")"
emit "IMAGE_AUDIT_IMAGE_COUNT=$(jq '.images|length' "$retention_snapshot_file")"
emit "IMAGE_AUDIT_PROTECTION_FINGERPRINT=$protection_fingerprint"
emit "IMAGE_AUDIT_PROTECTION_B64=$(printf '%s' "$protection_snapshot" | base64 -w0)"
emit "IMAGE_AUDIT_STARTUP_SHA256=$(retention_hash < "$retention_startup_file")"
emit "IMAGE_AUDIT_STARTUP_B64=$(base64 -w0 "$retention_startup_file")"
emit "IMAGE_AUDIT_DISK_AVAILABLE_KIB=$(df -Pk "$workdir" | awk 'NR==2 {print $4}')"
while IFS= read -r snapshot; do
  emit "IMAGE_AUDIT_CONTAINER_SNAPSHOT id=$(jq -r .id <<< "$snapshot") snapshot_b64=$(printf '%s' "$snapshot" | base64 -w0)"
done < <(jq -cS '.containers[]' "$retention_snapshot_file")
while IFS= read -r snapshot; do
  image_id=$(jq -r .id <<< "$snapshot")
  reference_count=$(jq --arg id "$image_id" '[.containers[]|select(.image_id==$id)]|length' "$retention_snapshot_file")
  emit "IMAGE_AUDIT_IMAGE_SNAPSHOT id=$image_id container_refs=$reference_count snapshot_sha256=$(printf '%s\n' "$snapshot" | retention_hash) snapshot_b64=$(printf '%s' "$snapshot" | base64 -w0)"
done < <(jq -cS '.images[]' "$retention_snapshot_file")
# Capabilities only: never dump journals, live signal contents or config secrets.
if command -v systemctl >/dev/null; then
  timeout --signal=TERM --kill-after=2s 5s systemctl show caddy.service \
    -p ActiveState -p MainPID -p InvocationID -p ActiveEnterTimestampMonotonic |
    while IFS= read -r property; do emit "IMAGE_AUDIT_CADDY_$property"; done
fi
signals="$lock_root/image-cleanup-live-signals.json"
if [[ -f "$signals" && ! -L "$signals" ]]; then emit 'IMAGE_AUDIT_LIVE_SIGNALS=present_unverified'
else emit 'IMAGE_AUDIT_LIVE_SIGNALS=missing'; fi
emit "IMAGE_AUDIT_COMPLETED_AT_EPOCH=$(date +%s)"
emit 'IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved'
