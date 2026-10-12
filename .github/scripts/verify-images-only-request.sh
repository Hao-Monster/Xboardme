#!/usr/bin/env bash
set -Eeuo pipefail
# Runner admission only; no production connection or credentials are used.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/images-only-approval.sh"
source "$script_dir/retention-continuity.sh"
fail() { echo "IMAGE_REQUEST_FAIL=$1" >&2; exit 1; }
review=${1:-"$script_dir/../release/image-cleanup-review.json"}
: "${EXPECTED_SHA:?required}"
: "${IMAGE_CLEANUP_ACTION:?required}"
[[ "${GITHUB_REPOSITORY:-}" == Hao-Monster/Xboardme &&
   "${GITHUB_REF:-}" == refs/heads/main && "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ &&
   "$EXPECTED_SHA" == "${GITHUB_SHA:-}" && "$EXPECTED_SHA" == "${GITHUB_WORKFLOW_SHA:-}" &&
   "${GITHUB_WORKFLOW_REF:-}" == Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main ]] || fail workflow_identity
[[ "$IMAGE_CLEANUP_ACTION" == audit || "$IMAGE_CLEANUP_ACTION" == cleanup ]] || fail invalid_action
[[ "$(git rev-parse HEAD)" == "$EXPECTED_SHA" ]] || fail checkout_mismatch
git diff --quiet --ignore-submodules=none && git diff --cached --quiet || fail dirty_checkout
submodules=$(git submodule status --recursive) || fail submodule_inventory
! grep -Eq '^[-+U]' <<< "$submodules" || fail dirty_submodule
remote_main=$(GIT_TERMINAL_PROMPT=0 timeout --signal=TERM --kill-after=2s 30s git ls-remote --exit-code origin refs/heads/main) || fail remote_unavailable
[[ "$remote_main" == "$EXPECTED_SHA"$'\trefs/heads/main' ]] || fail stale_main
if [[ "$IMAGE_CLEANUP_ACTION" == cleanup ]]; then
  retention_images_review_fresh "$review" || fail approved_fresh_review_required
  retention_monitoring_validate "$review" || fail monitoring_review_required
  active=$(jq -er .active_release_sha "$review")
  git merge-base --is-ancestor "$active" "$EXPECTED_SHA" || fail active_not_in_history
  echo "IMAGE_REQUEST_REVIEW_SHA256=$(sha256sum < "$review" | awk '{print $1}')"
  echo "IMAGE_REQUEST_MONITORING_MODE=$(jq -er 'if .schema_version==3 then .monitoring.mode else "legacy_live_v1" end' "$review")"
fi
echo "IMAGE_REQUEST=PASS action=$IMAGE_CLEANUP_ACTION sha=$EXPECTED_SHA"
