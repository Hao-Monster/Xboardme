#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
work_dir=$(mktemp -d)
trap 'rm -rf -- "$work_dir"' EXIT
jq -n '("a"*64) as $hash | {
  schema_version:1,status:"approved",operation:"v2_finalize",active_release_id:"123-1",
  active_release_sha:("b"*40),resource_fingerprint:$hash,protection_fingerprint:$hash,
  protected_image_revisions:[],protected_image_digests:[],
  review_evidence:{backup_review_sha256:$hash,monitoring_review_sha256:$hash,
    authentication_review_sha256:$hash,isolated_docker_review_sha256:$hash},
  containers:[{id:$hash,snapshot_sha256:$hash,evidence:{release_state_sha256:$hash,
    writable_layer_review_sha256:$hash,startup_reference_review_sha256:$hash}}],images:[]
}' > "$work_dir/review.json"
make_audit() {
  printf '%s\n' 'RETENTION_ACTIVE_RELEASE_ID=123-1' \
    "RETENTION_ACTIVE_REVISION=$(printf '%040d' 0 | tr 0 b)" \
    "RETENTION_PROTECTION_FINGERPRINT=$(printf '%064d' 0 | tr 0 a)" \
    "RETENTION_ACTIVE_TRAFFIC_STATE=$1" "RETENTION_ACTIVE_ROLLBACK_SUPPORTED=$2" \
    'RETENTION_AUDIT=PASS mode=read_only' > "$work_dir/audit"
}
verify() { bash "$script_dir/verify-retention-post-audit.sh" "$work_dir/review.json" "$work_dir/audit"; }
failure() {
  if verify > "$work_dir/result" 2>&1; then echo "Unexpected success: $1" >&2; exit 1; fi
  grep -Fq "RETENTION_POST_AUDIT_FAIL=$1" "$work_dir/result"
}
export MUTATION_RESULT=success BATCH_DISPOSITION=complete
make_audit finalized false
verify
make_audit finalizing false
BATCH_DISPOSITION=pending_batch verify
failure lifecycle_mismatch
MUTATION_RESULT=failure verify
MUTATION_RESULT=cancelled verify
sed -i 's/RETENTION_ACTIVE_RELEASE_ID=123-1/RETENTION_ACTIVE_RELEASE_ID=124-1/' "$work_dir/audit"
MUTATION_RESULT=failure failure active_release_changed
make_audit finalized false
sed -i 's/RETENTION_ACTIVE_REVISION=b/RETENTION_ACTIVE_REVISION=c/' "$work_dir/audit"
MUTATION_RESULT=failure failure active_revision_changed
make_audit finalized false
sed -i 's/RETENTION_PROTECTION_FINGERPRINT=a/RETENTION_PROTECTION_FINGERPRINT=c/' "$work_dir/audit"
MUTATION_RESULT=cancelled failure protected_resources_changed
make_audit finalized false
printf '%s\n' 'RETENTION_ACTIVE_RELEASE_ID=123-1' >> "$work_dir/audit"
failure active_release_changed
make_audit finalized false
sed -i '/RETENTION_AUDIT=PASS/d' "$work_dir/audit"
failure audit_not_complete
make_audit finalized false
BATCH_DISPOSITION='' failure batch_disposition_missing
make_audit finalizing true
BATCH_DISPOSITION=pending_batch failure lifecycle_mismatch
make_audit finalizing false
jq '.operation="retention_cleanup"' "$work_dir/review.json" > "$work_dir/cleanup.json"
mv "$work_dir/cleanup.json" "$work_dir/review.json"
BATCH_DISPOSITION=pending_batch failure lifecycle_mismatch
make_audit finalized false
BATCH_DISPOSITION=pending_batch verify
echo 'RETENTION_POST_AUDIT_TEST=PASS cases=14 production_connections=0'
