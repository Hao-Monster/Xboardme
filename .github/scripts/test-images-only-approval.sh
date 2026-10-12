#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/images-only-approval.sh"
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT
now=$(date +%s)
jq -n --argjson now "$now" '{
  schema_version:2,status:"approved",operation:"image_cleanup",
  active_release_id:"123-1",active_release_sha:("a"*40),
  resource_fingerprint:("b"*64),protection_fingerprint:("c"*64),
  protected_image_revisions:[("a"*40)],
  protected_image_digests:[("ghcr.io/hao-monster/xboardme@sha256:"+("d"*64))],
  review_evidence:{backup_review_sha256:("e"*64),monitoring_review_sha256:("e"*64),
    authentication_review_sha256:("e"*64),isolated_docker_review_sha256:("e"*64)},
  audit_completed_at_epoch:($now-10),approval_expires_at_epoch:($now+600),
  max_image_size_bytes:536870912,containers:[],
  images:[{id:("sha256:"+("f"*64)),snapshot_sha256:("e"*64),
    evidence:{startup_reference_review_sha256:("e"*64)}}],
  monitoring:{baseline_seconds:600,post_delete_observe_seconds:900}
}' > "$temporary/valid.json"
retention_images_review_validate "$temporary/valid.json"
retention_images_review_fresh "$temporary/valid.json"
cases=2
for invalid in '.status="blocked"' '.operation="retention_cleanup"' '.schema_version=1' \
  '.containers=[{id:("1"*64)}]' '.images=[]' '.images+=.images' \
  '.max_image_size_bytes=536870913' '.protected_image_revisions=[]' \
  '.protected_image_digests=[]' '.review_evidence.monitoring_review_sha256=("0"*64)' \
  '.approval_expires_at_epoch=(.audit_completed_at_epoch+86401)' '.monitoring={}' \
  '.images[0].evidence.startup_reference_review_sha256="not-evidence"'; do
  jq "$invalid" "$temporary/valid.json" > "$temporary/invalid.json"
  if retention_images_review_validate "$temporary/invalid.json"; then
    echo "IMAGE_APPROVAL_TEST_FAIL=accepted:$invalid" >&2
    exit 1
  fi
  ((cases+=1))
done
jq --argjson now "$now" '.approval_expires_at_epoch=$now-1' "$temporary/valid.json" > "$temporary/expired.json"
if retention_images_review_fresh "$temporary/expired.json"; then exit 1; fi
((cases+=1))
jq --argjson now "$now" '.audit_completed_at_epoch=$now+60' "$temporary/valid.json" > "$temporary/future.json"
if retention_images_review_fresh "$temporary/future.json"; then exit 1; fi
((cases+=1))
ln -s "$temporary/valid.json" "$temporary/link.json"
if retention_images_review_validate "$temporary/link.json"; then exit 1; fi
((cases+=1))
jq '.schema_version=3 | .monitoring={mode:"passive_runtime_v1"} |
  .review_evidence={scope_review_sha256:("e"*64),isolated_docker_review_sha256:("e"*64)} |
  .images[0].revision=("b"*40) |
  .images[0].restore_digest=("ghcr.io/hao-monster/xboardme@sha256:"+("f"*64))' \
  "$temporary/valid.json" > "$temporary/passive.json"
retention_images_review_validate "$temporary/passive.json"
retention_images_review_fresh "$temporary/passive.json"
((cases+=2))
for invalid in '.schema_version=4' '.monitoring.mode="unknown"' '.schema_version=2' \
  '.review_evidence.scope_review_sha256=("0"*64)' 'del(.review_evidence.isolated_docker_review_sha256)' \
  '.images[0].revision="short"' '.images[0].restore_digest="ghcr.io/hao-monster/xboardme:latest"' \
  '.images[0].restore_digest=("ghcr.io/other/xboardme@sha256:"+("f"*64))' \
  '.monitoring.public={}' '.monitoring.origin={}' '.monitoring.live_signals={}' '.monitoring.evidence={}'; do
  jq "$invalid" "$temporary/passive.json" > "$temporary/invalid.json"
  if retention_images_review_validate "$temporary/invalid.json"; then
    echo "IMAGE_APPROVAL_TEST_FAIL=accepted_passive:$invalid" >&2; exit 1
  fi
  ((cases+=1))
done
for mode in '"passive_runtime_v1"' '"legacy_live_v1"' null; do
  jq ".monitoring.mode=$mode" "$temporary/valid.json" > "$temporary/invalid.json"
  if retention_images_review_validate "$temporary/invalid.json"; then exit 1; fi
  ((cases+=1))
done
printf 'IMAGE_APPROVAL_TEST=PASS cases=%s production_connections=0\n' "$cases"
