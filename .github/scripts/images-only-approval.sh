#!/usr/bin/env bash
# Schema admission is separate from evidence review. A well-formed hash is not
# evidence of a successful backup restore or a working production monitor.
retention_images_review_validate() {
  [[ -f "$1" && ! -L "$1" && $(stat -c %s -- "$1") -le 262144 ]] || return 1
  jq -e '
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    def sha: type=="string" and test("^[a-f0-9]{40}$");
    def positive: type=="number" and floor==. and .>0;
    def digest: type=="string" and
      test("^ghcr\\.io/(hao-monster|fenghaoyun-monster)/xboardme@sha256:[a-f0-9]{64}$");
    (.schema_version==2 or .schema_version==3) and .status=="approved" and .operation=="image_cleanup" and
    (.active_release_id|type=="string" and test("^[0-9]+-[0-9]+$")) and
    (.active_release_sha|sha) and (.resource_fingerprint|hash) and (.protection_fingerprint|hash) and
    (.protected_image_revisions|type=="array" and length>0 and all(.[];sha)) and
    (.active_release_sha as $active | .protected_image_revisions | index($active)!=null) and
    (.protected_image_digests|type=="array" and length>0 and all(.[];type=="string" and
      test("^ghcr\\.io/(hao-monster|fenghaoyun-monster)/xboardme@sha256:[a-f0-9]{64}$"))) and
    (.review_evidence|.isolated_docker_review_sha256|hash) and
    (if .schema_version==2 then
      (.monitoring|has("mode")|not) and
      (.review_evidence.backup_review_sha256|hash) and
      (.review_evidence.monitoring_review_sha256|hash) and
      (.review_evidence.authentication_review_sha256|hash)
    else
      .monitoring.mode=="passive_runtime_v1" and
      (.monitoring|has("public") or has("origin") or has("live_signals") or has("evidence")|not) and
      (.review_evidence|keys|sort)==["isolated_docker_review_sha256","scope_review_sha256"] and
      (.review_evidence.scope_review_sha256|hash) and
      (.images[0].restore_digest|digest) and (.images[0].revision|sha)
    end) and
    (.audit_completed_at_epoch|positive) and (.approval_expires_at_epoch|positive) and
    .approval_expires_at_epoch>.audit_completed_at_epoch and
    .approval_expires_at_epoch<=.audit_completed_at_epoch+86400 and
    (.max_image_size_bytes|positive) and .max_image_size_bytes<=536870912 and
    .containers==[] and (.images|type=="array" and length==1 and all(.[];
      (.id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
      (.snapshot_sha256|hash) and (.evidence.startup_reference_review_sha256|hash))) and
    (.monitoring|type=="object" and length>0)
  ' "$1" >/dev/null
}

retention_images_review_fresh() {
  local now
  retention_images_review_validate "$1" || return 1
  now=$(date +%s) || return 1
  jq -e --argjson now "$now" '.audit_completed_at_epoch<=$now and $now<.approval_expires_at_epoch' "$1" >/dev/null
}

retention_images_review_load() {
  local destination=$1
  [[ "${EXPECTED_RETENTION_REVIEW_SHA256:-}" =~ ^[a-f0-9]{64}$ &&
     -n "${RETENTION_REVIEW_MANIFEST_B64:-}" && ${#RETENTION_REVIEW_MANIFEST_B64} -le 350000 ]] || return 1
  printf '%s' "$RETENTION_REVIEW_MANIFEST_B64" | base64 -d > "$destination" || return 1
  [[ "$(sha256sum < "$destination" | awk '{print $1}')" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] || return 1
  retention_images_review_fresh "$destination" || return 1
  jq -e --arg id "$EXPECTED_ACTIVE_RELEASE_ID" --arg sha "$EXPECTED_ACTIVE_RELEASE_SHA" \
    --arg fingerprint "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" \
    '.active_release_id==$id and .active_release_sha==$sha and .resource_fingerprint==$fingerprint' \
    "$destination" >/dev/null
}
