#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/images-only-approval.sh"
before=${1:?cleanup log required}; after=${2:?independent audit log required}
review=${3:-"$script_dir/../release/image-cleanup-review.json"}
fail() { echo "IMAGE_POST_AUDIT_FAIL=$1" >&2; exit 1; }
retention_images_review_validate "$review" || fail review
field() {
  local file=$1 key=$2 value
  [[ $(grep -c "^$key=" "$file") == 1 ]] || fail "ambiguous_$key"
  value=$(sed -n "s/^$key=//p" "$file")
  [[ -n "$value" ]] || fail "empty_$key"
  printf '%s\n' "$value"
}
for audit in "$before" "$after"; do
  [[ -f "$audit" && ! -L "$audit" && $(stat -c %s "$audit") -le 8388608 ]] || fail audit_file
  [[ "$(field "$audit" IMAGE_AUDIT)" == 'PASS mode=read_only lifecycle=preserved' ]] || fail incomplete_audit
  [[ "$(field "$audit" IMAGE_AUDIT_SCHEMA)" == 1 ]] || fail schema
  for key in RESOURCE_FINGERPRINT INVENTORY_SHA256 ACTIVE_STATE_SHA256; do
    value=$(field "$audit" "IMAGE_AUDIT_$key") || fail "missing_$key"
    [[ "$value" =~ ^[a-f0-9]{64}$ ]] || fail "invalid_$key"
  done
  for key in CONTAINER_COUNT IMAGE_COUNT DISK_AVAILABLE_KIB; do
    value=$(field "$audit" "IMAGE_AUDIT_$key") || fail "missing_$key"
    [[ "$value" =~ ^[0-9]{1,12}$ && "$value" != 0 ]] || fail "invalid_$key"
  done
  [[ "$(field "$audit" IMAGE_AUDIT_CADDY_ActiveState)" == active ]] || fail caddy_inactive
  for key in MainPID ActiveEnterTimestampMonotonic; do
    value=$(field "$audit" "IMAGE_AUDIT_CADDY_$key") || fail "missing_caddy_$key"
    [[ "$value" =~ ^[1-9][0-9]*$ ]] || fail "invalid_caddy_$key"
  done
  value=$(field "$audit" IMAGE_AUDIT_CADDY_InvocationID) || fail missing_caddy_invocation
  [[ "$value" =~ ^[a-f0-9]{32}$ ]] || fail invalid_caddy_invocation
done
for key in WORKFLOW_SHA ACTIVE_RELEASE_ID ACTIVE_REVISION ACTIVE_STATE_SHA256 ACTIVE_UPSTREAM TRAFFIC_STATE ROLLBACK_SUPPORTED PROTECTION_FINGERPRINT STARTUP_SHA256 CADDY_ActiveState CADDY_MainPID CADDY_InvocationID CADDY_ActiveEnterTimestampMonotonic; do
  before_value=$(field "$before" "IMAGE_AUDIT_$key") || fail "before_$key"
  after_value=$(field "$after" "IMAGE_AUDIT_$key") || fail "after_$key"
  [[ "$before_value" == "$after_value" ]] || fail "changed_$key"
done
[[ "$(field "$before" IMAGE_AUDIT_CADDY_MainPID)" == "$(jq -er .monitoring.caddy.main_pid "$review")" &&
   "$(field "$before" IMAGE_AUDIT_CADDY_InvocationID)" == "$(jq -er .monitoring.caddy.invocation_id "$review")" &&
   "$(field "$before" IMAGE_AUDIT_CADDY_ActiveEnterTimestampMonotonic)" == "$(jq -er .monitoring.caddy.active_enter_monotonic_usec "$review")" ]] || fail caddy_review_mismatch
[[ "$(field "$before" IMAGE_AUDIT_WORKFLOW_SHA)" == "${EXPECTED_SHA:?required}" &&
   "$(field "$before" IMAGE_AUDIT_ACTIVE_RELEASE_ID)" == "$(jq -r .active_release_id "$review")" &&
   "$(field "$before" IMAGE_AUDIT_ACTIVE_REVISION)" == "$(jq -r .active_release_sha "$review")" &&
   "$(field "$before" IMAGE_AUDIT_RESOURCE_FINGERPRINT)" == "$(jq -r .resource_fingerprint "$review")" &&
   "$(field "$before" IMAGE_AUDIT_PROTECTION_FINGERPRINT)" == "$(jq -r .protection_fingerprint "$review")" &&
   "$(field "$before" IMAGE_AUDIT_STARTUP_SHA256)" == "$(jq -r .images[0].evidence.startup_reference_review_sha256 "$review")" ]] || fail review_identity
id=$(jq -er '.images[0].id' "$review")
hash=$(sha256sum < "$review" | awk '{print $1}')
[[ "$(field "$before" IMAGE_CLEANUP)" == "PASS result=complete id=$id images=1 containers=preserved volumes=preserved directories=preserved lifecycle=unchanged review_sha256=$hash" ]] || fail incomplete_cleanup
[[ $(field "$after" IMAGE_AUDIT_COMPLETED_AT_EPOCH) =~ ^[0-9]{1,12}$ &&
   $(field "$before" IMAGE_AUDIT_COMPLETED_AT_EPOCH) =~ ^[0-9]{1,12}$ ]] || fail timestamp
(( $(field "$after" IMAGE_AUDIT_COMPLETED_AT_EPOCH) >= $(field "$before" IMAGE_AUDIT_COMPLETED_AT_EPOCH) )) || fail stale_audit
snapshots() {
  local file=$1 type=$2 lines line encoded decoded identity digest result=''
  lines=$(grep "^IMAGE_AUDIT_${type}_SNAPSHOT " "$file") || fail missing_snapshots
  while IFS= read -r line; do
    [[ "$line" =~ snapshot_b64=([A-Za-z0-9+/=]+)$ ]] || fail malformed_snapshot
    encoded=${BASH_REMATCH[1]}
    decoded=$(printf '%s' "$encoded" | base64 -d) || fail invalid_base64
    jq -e 'type=="object" and (.id|type=="string")' <<< "$decoded" >/dev/null || fail invalid_snapshot
    identity=$(jq -er .id <<< "$decoded") || fail invalid_snapshot_id
    if [[ "$type" == CONTAINER ]]; then
      [[ "$identity" =~ ^[a-f0-9]{64}$ && "$line" == "IMAGE_AUDIT_CONTAINER_SNAPSHOT id=$identity snapshot_b64=$encoded" ]] || fail container_line_mismatch
    else
      [[ "$identity" =~ ^sha256:[a-f0-9]{64}$ ]] || fail invalid_image_id
      digest=$(printf '%s\n' "$decoded" | sha256sum | awk '{print $1}')
      [[ "$line" =~ ^IMAGE_AUDIT_IMAGE_SNAPSHOT\ id=$identity\ container_refs=[0-9]+\ snapshot_sha256=$digest\ snapshot_b64=$encoded$ ]] || fail image_line_mismatch
    fi
    result+="$decoded"$'\n'
  done <<< "$lines"
  jq -cse 'sort_by(.id) | if (map(.id)|length)==(map(.id)|unique|length) then . else error("duplicate ids") end' <<< "$result"
}
original_containers=$(snapshots "$before" CONTAINER) || fail before_containers
current_containers=$(snapshots "$after" CONTAINER) || fail after_containers
[[ "$original_containers" == "$current_containers" ]] || fail container_drift
original=$(snapshots "$before" IMAGE) || fail before_images
current=$(snapshots "$after" IMAGE) || fail after_images
verify_inventory() {
  local file=$1 containers=$2 images=$3 inventory_hash
  inventory_hash=$(jq -cnS --argjson containers "$containers" --argjson images "$images" \
    '{containers:$containers,images:$images}' | sha256sum | awk '{print $1}') || fail inventory_hash
  [[ "$inventory_hash" == "$(field "$file" IMAGE_AUDIT_INVENTORY_SHA256)" &&
     "$(jq length <<< "$containers")" == "$(field "$file" IMAGE_AUDIT_CONTAINER_COUNT)" &&
     "$(jq length <<< "$images")" == "$(field "$file" IMAGE_AUDIT_IMAGE_COUNT)" ]] || fail incomplete_inventory
}
verify_inventory "$before" "$original_containers" "$original"
verify_inventory "$after" "$current_containers" "$current"
selected=$(jq -ceS --arg id "$id" '.[]|select(.id==$id)' <<< "$original") || fail selected_image_missing
[[ "$(printf '%s\n' "$selected" | sha256sum | awk '{print $1}')" == "$(jq -er .images[0].snapshot_sha256 "$review")" ]] || fail selected_image_review_mismatch
jq -e --arg id "$id" --argjson current "$current" \
  '([.[]|select(.id==$id)]|length)==1 and ([.[]|select(.id!=$id)]==$current)' \
  <<< "$original" >/dev/null || fail unapproved_image_delta
echo 'IMAGE_POST_AUDIT=PASS exact_one_image_removed all_containers_preserved'
