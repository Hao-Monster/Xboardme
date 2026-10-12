#!/usr/bin/env bash
set -Eeuo pipefail

# Runner-only admission check. No host connection or credential is needed here.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
fail() { echo "RETENTION_REQUEST_FAIL=$1" >&2; exit 1; }

: "${EXPECTED_SHA:?EXPECTED_SHA is required}"
: "${RELEASE_ID:?RELEASE_ID is required}"
: "${RELEASE_EXPECTED_SHA:?RELEASE_EXPECTED_SHA is required}"
: "${MAINTENANCE_ACTION:?MAINTENANCE_ACTION is required}"
: "${RETENTION_MAX_OBJECTS_PER_RUN:=1}"
: "${RETENTION_IMAGE_MIN_AGE_SECONDS:=604800}"
review=${1:-"$script_dir/../release/retention-review.json"}

[[ "${GITHUB_REF:-}" == refs/heads/main &&
   "${GITHUB_REPOSITORY:-}" == Hao-Monster/Xboardme &&
   "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ &&
   "$EXPECTED_SHA" == "${GITHUB_SHA:-}" ]] || fail workflow_identity_mismatch
[[ "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ &&
   "$RELEASE_EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] || fail invalid_active_identity
[[ "$MAINTENANCE_ACTION" == v2_finalize || "$MAINTENANCE_ACTION" == retention_cleanup ]] || fail invalid_operation
[[ "$RETENTION_MAX_OBJECTS_PER_RUN" =~ ^[1-5]$ ]] || fail invalid_batch_limit
[[ "$RETENTION_IMAGE_MIN_AGE_SECONDS" =~ ^[1-9][0-9]{0,9}$ ]] || fail invalid_image_age
((RETENTION_IMAGE_MIN_AGE_SECONDS >= 604800)) || fail image_age_below_seven_days
[[ -f "$review" && ! -L "$review" ]] || fail review_file_missing
retention_review_validate "$review" || fail reviewed_manifest_required
jq -e --arg operation "$MAINTENANCE_ACTION" --arg id "$RELEASE_ID" --arg sha "$RELEASE_EXPECTED_SHA" \
  '.operation==$operation and .active_release_id==$id and .active_release_sha==$sha' \
  "$review" >/dev/null || fail review_identity_mismatch
if [[ "$MAINTENANCE_ACTION" == retention_cleanup ]]; then
  [[ "${RESOURCE_FINGERPRINT:-}" =~ ^[a-f0-9]{64}$ ]] || fail invalid_resource_fingerprint
  jq -e --arg fingerprint "$RESOURCE_FINGERPRINT" '.resource_fingerprint==$fingerprint' \
    "$review" >/dev/null || fail review_fingerprint_mismatch
else
  jq -e '(.images|length)==0 and (.containers|length)>0' "$review" >/dev/null || fail invalid_finalize_scope
fi
[[ "$(git rev-parse HEAD)" == "$EXPECTED_SHA" ]] || fail checkout_mismatch
git merge-base --is-ancestor "$RELEASE_EXPECTED_SHA" "$GITHUB_SHA" || fail active_not_in_history
remote_main=$(git ls-remote --exit-code origin refs/heads/main) || fail current_main_unavailable
[[ "$remote_main" == "$EXPECTED_SHA"$'\trefs/heads/main' ]] || fail current_main_changed
echo "RETENTION_REQUEST=PASS operation=$MAINTENANCE_ACTION review_sha256=$(retention_hash < "$review") max_objects=$RETENTION_MAX_OBJECTS_PER_RUN"
