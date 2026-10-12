#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
export EXPECTED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
id="sha256:$(printf 'b%.0s' {1..64})"
hash=$(printf 'c%.0s' {1..64})
selected=$(jq -cnS --arg id "$id" '{id:$id,revision:("b"*40),digests:["ghcr.io/hao-monster/xboardme@"+$id]}')
container_snapshot=$(jq -cnS '{id:("e"*64),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}')
selected_hash=$(printf '%s\n' "$selected" | sha256sum | awk '{print $1}')
jq -n --arg id "$id" --arg hash "$hash" --arg sha "$EXPECTED_SHA" --arg selected "$selected_hash" '{
  schema_version:2,status:"approved",operation:"image_cleanup",active_release_id:"123-1",active_release_sha:$sha,
  resource_fingerprint:$hash,protection_fingerprint:$hash,
  protected_image_revisions:[$sha],protected_image_digests:[("ghcr.io/hao-monster/xboardme@sha256:"+$hash)],
  review_evidence:{backup_review_sha256:$hash,monitoring_review_sha256:$hash,authentication_review_sha256:$hash,isolated_docker_review_sha256:$hash},
  audit_completed_at_epoch:1,approval_expires_at_epoch:86400,max_image_size_bytes:100,
  containers:[],images:[{id:$id,snapshot_sha256:$selected,evidence:{startup_reference_review_sha256:$hash}}],
  monitoring:{caddy:{main_pid:42,invocation_id:("f"*32),active_enter_monotonic_usec:1000}}
}' > "$test_root/review.json"
review_hash=$(sha256sum < "$test_root/review.json" | awk '{print $1}')
snapshot() {
  local decoded identity extra=''
  decoded=$(jq -cS . <<< "$2"); identity=$(jq -r .id <<< "$decoded")
  if [[ "$1" == IMAGE ]]; then extra=" container_refs=0 snapshot_sha256=$(printf '%s\n' "$decoded" | sha256sum | awk '{print $1}')"; fi
  printf 'IMAGE_AUDIT_%s_SNAPSHOT id=%s%s snapshot_b64=%s\n' "$1" "$identity" "$extra" "$(printf '%s' "$decoded" | base64 -w0)"
}
audit() {
  local phase=$1 containers images
  containers=$(jq -cnS --argjson container "$container_snapshot" '[$container]')
  images=$(jq -cnS --argjson selected "$selected" --arg phase "$phase" '[{id:("sha256:"+("d"*64)),source:"https://example.com/~~~"}] + (if $phase=="before" then [$selected] else [] end)|sort_by(.id)')
  printf 'IMAGE_AUDIT_SCHEMA=1\nIMAGE_AUDIT_DISK_AVAILABLE_KIB=10000\nIMAGE_AUDIT_ACTIVE_STATE_SHA256=%s\n' "$hash"
  printf 'IMAGE_AUDIT_CONTAINER_COUNT=1\nIMAGE_AUDIT_IMAGE_COUNT=%s\n' "$(jq length <<< "$images")"
  printf 'IMAGE_AUDIT_INVENTORY_SHA256=%s\n' "$(jq -cnS --argjson containers "$containers" --argjson images "$images" '{containers:$containers,images:$images}' | sha256sum | awk '{print $1}')"
  printf 'IMAGE_AUDIT_CADDY_ActiveState=active\nIMAGE_AUDIT_CADDY_MainPID=42\nIMAGE_AUDIT_CADDY_InvocationID=%s\nIMAGE_AUDIT_CADDY_ActiveEnterTimestampMonotonic=1000\n' "$(printf 'f%.0s' {1..32})"
  printf 'IMAGE_AUDIT_WORKFLOW_SHA=%s\nIMAGE_AUDIT_ACTIVE_RELEASE_ID=123-1\nIMAGE_AUDIT_ACTIVE_REVISION=%s\n' "$EXPECTED_SHA" "$EXPECTED_SHA"
  printf 'IMAGE_AUDIT_ACTIVE_UPSTREAM=127.0.0.1:7002\nIMAGE_AUDIT_TRAFFIC_STATE=active_v2\nIMAGE_AUDIT_ROLLBACK_SUPPORTED=true\n'
  printf 'IMAGE_AUDIT_RESOURCE_FINGERPRINT=%s\nIMAGE_AUDIT_PROTECTION_FINGERPRINT=%s\nIMAGE_AUDIT_STARTUP_SHA256=%s\n' "$hash" "$hash" "$hash"
  snapshot CONTAINER "$(jq -c '.[0]' <<< "$containers")"
  snapshot IMAGE "$(jq -cn '{id:("sha256:"+("d"*64)),source:"https://example.com/~~~"}')"
  if [[ "$phase" == before ]]; then snapshot IMAGE "$selected"; fi
  printf 'IMAGE_AUDIT_COMPLETED_AT_EPOCH=100\nIMAGE_AUDIT=PASS mode=read_only lifecycle=preserved\n'
  if [[ "$phase" == before ]]; then
    printf 'IMAGE_CLEANUP=PASS result=complete id=%s images=1 containers=preserved volumes=preserved directories=preserved lifecycle=unchanged review_sha256=%s\n' "$id" "$review_hash"
  fi
}
audit before > "$test_root/before"
audit after > "$test_root/after"
grep '^IMAGE_AUDIT_IMAGE_SNAPSHOT ' "$test_root/after" | grep -Fq '+'
verify() { bash "$script_dir/verify-images-only-post-audit.sh" "$test_root/before" "$test_root/after" "$test_root/review.json"; }
verify
cp "$test_root/after" "$test_root/good"
reject() { if verify > "$test_root/result" 2>&1; then echo 'unexpected post-audit success' >&2; exit 1; fi; cp "$test_root/good" "$test_root/after"; }
sed -i 's/7002/7004/' "$test_root/after"; reject
sed -i 's/ROLLBACK_SUPPORTED=true/ROLLBACK_SUPPORTED=false/' "$test_root/after"; reject
sed -i '/IMAGE_AUDIT_PROTECTION_FINGERPRINT=/d' "$test_root/after"; reject
printf 'IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved\n' >> "$test_root/after"; reject
sed -i 's/COMPLETED_AT_EPOCH=100/COMPLETED_AT_EPOCH=99/' "$test_root/after"; reject
snapshot IMAGE "{\"id\":\"$id\"}" >> "$test_root/after"; reject
snapshot IMAGE '{"id":"unexpected"}' >> "$test_root/after"; reject
sed -i '/IMAGE_AUDIT_CONTAINER_SNAPSHOT/d' "$test_root/after"; reject
snapshot CONTAINER '{"id":"protected","status":"running"}' >> "$test_root/after"; reject
sed -i 's/snapshot_b64=.*/snapshot_b64=INVALID/' "$test_root/after"; reject
sed -i 's/CADDY_ActiveState=active/CADDY_ActiveState=inactive/' "$test_root/after"; reject
sed -i 's/CADDY_MainPID=42/CADDY_MainPID=43/' "$test_root/after"; reject
sed -i 's/CADDY_ActiveEnterTimestampMonotonic=1000/CADDY_ActiveEnterTimestampMonotonic=1001/' "$test_root/after"; reject
sed -i '/CADDY_InvocationID=/d' "$test_root/after"; reject
printf 'IMAGE_AUDIT_RESOURCE_FINGERPRINT=%s\n' "$hash" >> "$test_root/after"; reject
sed -i 's/IMAGE_COUNT=1/IMAGE_COUNT=2/' "$test_root/after"; reject
# Both malformed sides must not compare as the same empty command substitution.
sed -i '/IMAGE_AUDIT_CONTAINER_SNAPSHOT/d' "$test_root/before"
sed -i '/IMAGE_AUDIT_CONTAINER_SNAPSHOT/d' "$test_root/after"; reject
jq --arg id "$id" --arg hash "$hash" --argjson container "$container_snapshot" '
  .schema_version=3 | .monitoring.mode="passive_runtime_v1" | .monitoring.protected_containers=[$container] |
  .review_evidence={scope_review_sha256:$hash,isolated_docker_review_sha256:$hash} |
  .images[0].revision=("b"*40) | .images[0].restore_digest="ghcr.io/hao-monster/xboardme@"+$id' \
  "$test_root/review.json" > "$test_root/passive.json"
cp "$test_root/passive.json" "$test_root/review.json"
review_hash=$(sha256sum < "$test_root/review.json" | awk '{print $1}')
audit before > "$test_root/before"
audit after > "$test_root/after"
verify
for fault in monitor digest revision; do
  case "$fault" in
    monitor) expression='.monitoring.protected_containers=[]'; expected=monitoring_inventory_mismatch ;;
    digest) expression='.images[0].restore_digest="ghcr.io/hao-monster/xboardme@sha256:"+("9"*64)'; expected=restore_identity_not_in_audit ;;
    revision) expression='.images[0].revision=("9"*40)'; expected=restore_identity_not_in_audit ;;
  esac
  jq "$expression" "$test_root/passive.json" > "$test_root/review.json"
  review_hash=$(sha256sum < "$test_root/review.json" | awk '{print $1}')
  audit before > "$test_root/before"
  if verify > "$test_root/result" 2>&1; then echo "unexpected passive post-audit success:$fault" >&2; exit 1; fi
  grep -Fxq "IMAGE_POST_AUDIT_FAIL=$expected" "$test_root/result"
done
echo 'IMAGE_POST_AUDIT_TEST=PASS cases=22 production_connections=0'
