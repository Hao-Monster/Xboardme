#!/usr/bin/env bash
set -Eeuo pipefail
# Runner admission only; no production connection or credentials are used.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/images-only-approval.sh"
source "$script_dir/retention-continuity.sh"
source "$script_dir/select-images-only-review.sh"
source "$script_dir/retention-predelete-recovery.sh"
fail() { echo "IMAGE_REQUEST_FAIL=$1" >&2; exit 1; }
review=${1:-}
if [[ -z "$review" ]]; then review=$(images_only_selected_review) || fail invalid_review_selection; fi
: "${EXPECTED_SHA:?required}"
: "${IMAGE_CLEANUP_ACTION:?required}"
[[ "${GITHUB_REPOSITORY:-}" == Hao-Monster/Xboardme &&
   "${GITHUB_REF:-}" == refs/heads/main && "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ &&
   "$EXPECTED_SHA" == "${GITHUB_SHA:-}" && "$EXPECTED_SHA" == "${GITHUB_WORKFLOW_SHA:-}" &&
   "${GITHUB_WORKFLOW_REF:-}" == Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main ]] || fail workflow_identity
[[ "$IMAGE_CLEANUP_ACTION" == audit || "$IMAGE_CLEANUP_ACTION" == retirement-audit ||
   "$IMAGE_CLEANUP_ACTION" == gate-audit || "$IMAGE_CLEANUP_ACTION" == cleanup ]] || fail invalid_action
if [[ "$IMAGE_CLEANUP_ACTION" == retirement-audit || "$IMAGE_CLEANUP_ACTION" == gate-audit || "${IMAGE_CLEANUP_REVIEW_ID:-default}" != default ]]; then
  [[ "${GITHUB_EVENT_NAME:-}" == workflow_dispatch && "${GITHUB_RUN_ATTEMPT:-}" == 1 ]] || fail manual_first_attempt_required
fi
[[ "$(git rev-parse HEAD)" == "$EXPECTED_SHA" ]] || fail checkout_mismatch
git diff --quiet --ignore-submodules=none && git diff --cached --quiet || fail dirty_checkout
if [[ "$IMAGE_CLEANUP_ACTION" == retirement-audit || "$IMAGE_CLEANUP_ACTION" == gate-audit || "${IMAGE_CLEANUP_REVIEW_ID:-default}" != default ]]; then
  checkout_status=$(git status --porcelain=v1 --untracked-files=all --ignore-submodules=none) || fail checkout_inventory
  [[ -z "$checkout_status" ]] || fail dirty_checkout
fi
submodules=$(git submodule status --recursive) || fail submodule_inventory
! grep -Eq '^[-+U]' <<< "$submodules" || fail dirty_submodule
remote_main=$(GIT_TERMINAL_PROMPT=0 timeout --signal=TERM --kill-after=2s 30s git ls-remote --exit-code origin refs/heads/main) || fail remote_unavailable
[[ "$remote_main" == "$EXPECTED_SHA"$'\trefs/heads/main' ]] || fail stale_main
if [[ "$IMAGE_CLEANUP_ACTION" == cleanup ]]; then
  retention_images_review_fresh "$review" || fail approved_fresh_review_required
  retention_monitoring_validate "$review" || fail monitoring_review_required
  images_only_sequence_validate "$review" || fail invalid_review_sequence
  if jq -e 'has("recovery")' "$review" >/dev/null; then
    retention_predelete_recovery_schema_validate "$review" || fail invalid_recovery_review
  fi
  if [[ "${IMAGE_CLEANUP_REVIEW_ID:-default}" != default ]]; then
    selected_review=$(images_only_selected_review) || fail invalid_review_selection
    [[ "$review" == "$selected_review" ]] || fail invalid_review_selection
    jq -e --arg index "$IMAGE_CLEANUP_REVIEW_ID" '.sequence.index==$index' "$review" >/dev/null || fail invalid_review_sequence
    if [[ "$IMAGE_CLEANUP_REVIEW_ID" == 001 ]]; then
      jq -e '.recovery.mode=="predelete_hold_v1"' "$review" >/dev/null || fail recovery_review_required
    else
      printf -v previous '%03d' "$((10#$IMAGE_CLEANUP_REVIEW_ID-1))"
      previous_review=$(IMAGE_CLEANUP_REVIEW_ID=$previous images_only_selected_review) || fail predecessor_review_missing
      previous_hash=$(sha256sum < "$previous_review" | awk '{print $1}')
      jq -e --arg previous "$previous_hash" '.sequence.predecessor_review_sha256==$previous and (has("recovery")|not)' "$review" >/dev/null || fail invalid_review_sequence
    fi
  fi
  active=$(jq -er .active_release_sha "$review")
  git merge-base --is-ancestor "$active" "$EXPECTED_SHA" || fail active_not_in_history
  echo "IMAGE_REQUEST_REVIEW_SHA256=$(sha256sum < "$review" | awk '{print $1}')"
  echo "IMAGE_REQUEST_MONITORING_MODE=$(jq -er 'if .schema_version==3 then .monitoring.mode else "legacy_live_v1" end' "$review")"
fi
echo "IMAGE_REQUEST=PASS action=$IMAGE_CLEANUP_ACTION sha=$EXPECTED_SHA"
