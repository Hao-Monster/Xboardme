#!/usr/bin/env bash
# Source-only runner helper. Dispatch selects a fixed committed identifier, not
# an arbitrary path, JSON body, target image or host command.
images_only_selected_review() {
  local directory identifier=${IMAGE_CLEANUP_REVIEW_ID:-default} candidate
  directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd) || return 1
  if [[ "$identifier" == default ]]; then
    candidate="$directory/../release/image-cleanup-review.json"
  else
    [[ "$identifier" =~ ^[0-9]{3}$ && "$identifier" != 000 ]] || return 1
    candidate="$directory/../release/image-cleanup-reviews/$identifier.json"
    [[ -d "$directory/../release/image-cleanup-reviews" &&
       ! -L "$directory/../release/image-cleanup-reviews" ]] || return 1
  fi
  [[ -f "$candidate" && ! -L "$candidate" ]] || return 1
  printf '%s\n' "$candidate"
}

# Sequenced manifests retain the single-image safety contract. The predecessor
# must already have succeeded, including independent post-verification. The
# projected inventory still has to match a new locked host audit exactly.
images_only_sequence_validate() {
  jq -e '
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    if has("sequence") then
      .schema_version==3 and .sequence.schema_version==1 and
      (.sequence|keys|sort)==["audit_artifact_sha256","index","predecessor_review_sha256","schema_version"] and
      (.sequence.audit_artifact_sha256|hash) and
      (.sequence.index|type=="string" and test("^[0-9]{3}$") and .!="000") and
      (if .sequence.index=="001" then .sequence.predecessor_review_sha256==null
       else .sequence.predecessor_review_sha256|hash end)
    else true end
  ' "$1" >/dev/null
}

images_only_sequence_predecessor() {
  local review=$1 gate=$2
  images_only_sequence_validate "$review" || return 1
  jq -e 'has("sequence")' "$review" >/dev/null || return 0
  if [[ $(jq -er .sequence.index "$review") == 001 ]]; then
    # The first manifest must use the separately verified pre-deletion recovery
    # path; it must not become a generic sequence reset after partial cleanup.
    jq -e '.recovery.mode=="predelete_hold_v1"' "$review" >/dev/null
  else
    [[ -f "$gate" && ! -L "$gate" ]] || return 1
    _rc_gate_validate "$gate" || return 1
    jq -e --slurpfile review "$review" '
      .status=="COMPLETE" and .cleanup_validated==true and .post_verification=="passed" and
      .review_sha256==$review[0].sequence.predecessor_review_sha256 and
      .image_id!=$review[0].images[0].id and ($review[0]|has("recovery")|not)
    ' "$gate" >/dev/null
  fi
}
