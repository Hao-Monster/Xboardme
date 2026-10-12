#!/usr/bin/env bash
set -Eeuo pipefail
# This is a runner-side transport, never installed on the production host.
# All payloads are from the admitted checkout. There is no arbitrary path,
# target, command or maintenance-action input.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
operation=${1:?audit, retirement-audit, gate-audit, cleanup, post-success or post-failure required}
case "$operation" in audit|retirement-audit|gate-audit|cleanup|post-success|post-failure) ;; *) exit 1 ;; esac
source "$script_dir/select-images-only-review.sh"
review=$(images_only_selected_review) || exit 1
bash "$script_dir/verify-images-only-request.sh" "$review"
case "$IMAGE_CLEANUP_ACTION:$operation" in
  audit:audit|retirement-audit:retirement-audit|gate-audit:gate-audit|\
  cleanup:audit|cleanup:cleanup|cleanup:post-success|cleanup:post-failure) ;;
  *) exit 1 ;;
esac
if [[ "$IMAGE_CLEANUP_ACTION" == cleanup && "$operation" != post-failure && "$(jq -er .schema_version "$review")" == 3 ]]; then
  # A same-run, immutable registry recovery proof must exist before host access.
  # This check reads runner evidence only; it never carries registry auth remote.
  bash "$script_dir/verify-image-restore.sh" --check-evidence
  if jq -e '.recovery.schema_version==2 and .recovery.mode=="predelete_sampled_hold_v2"' "$review" >/dev/null; then
    bash "$script_dir/verify-sampled-recovery-runner.sh" --check-evidence
  fi
fi
# Failure can only tighten the already bound remote gate to HOLD. Lost positive
# evidence must not prevent that transition; exact-main/fresh review admission
# above and remote workflow/review-hash identity checks remain mandatory.
: "${DEPLOY_HOST:?required}" "${DEPLOY_PORT:?required}" "${DEPLOY_USER:?required}"
[[ "$DEPLOY_PORT" =~ ^[0-9]{1,5}$ && "$DEPLOY_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.:-]*$ &&
   "$DEPLOY_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] || exit 1
remote_env="EXPECTED_WORKFLOW_SHA='$EXPECTED_SHA'"
if [[ "$IMAGE_CLEANUP_ACTION" == cleanup ]]; then
  review_hash=$(sha256sum < "$review" | awk '{print $1}')
  active_id=$(jq -er .active_release_id "$review")
  active_sha=$(jq -er .active_release_sha "$review")
  resource=$(jq -er .resource_fingerprint "$review")
  remote_env+=" EXPECTED_ACTIVE_RELEASE_ID='$active_id' EXPECTED_ACTIVE_RELEASE_SHA='$active_sha'"
  remote_env+=" EXPECTED_RETENTION_REVIEW_SHA256='$review_hash' EXPECTED_RETENTION_RESOURCE_FINGERPRINT='$resource'"
fi
deadline=300
if [[ "$operation" == retirement-audit || "$operation" == gate-audit ]]; then deadline=600; fi
if [[ "$operation" == cleanup ]]; then
  deadline=2700
  manifest=$(base64 -w0 "$review")
  [[ "$manifest" =~ ^[A-Za-z0-9+/=]+$ ]] || exit 1
  remote_env+=" RETENTION_REVIEW_MANIFEST_B64='$manifest'"
  if jq -e '.recovery.schema_version==2 and .recovery.mode=="predelete_sampled_hold_v2"' "$review" >/dev/null; then
    proof=$(base64 -w0 "$RUNNER_TEMP/images-only-sampled-proof.json")
    [[ "$proof" =~ ^[A-Za-z0-9+/=]+$ && ${#proof} -le 21848 ]] || exit 1
    remote_env+=" RETENTION_SAMPLED_PROOF_B64='$proof'"
  fi
fi
if [[ "$operation" == post-success ]]; then
  [[ "${POST_EVIDENCE_SHA256:-}" =~ ^[a-f0-9]{64}$ ]] || exit 1
  remote_env+=" POST_EVIDENCE_SHA256='$POST_EVIDENCE_SHA256'"
fi
payload() {
  printf 'set -Eeuo pipefail\numask 077\n'
  cat "$script_dir/production-runtime-discovery.sh" "$script_dir/release-state.sh" \
    "$script_dir/retention-safety.sh" "$script_dir/images-only-inventory.sh" "$script_dir/image-startup-references.sh" \
    "$script_dir/images-only-approval.sh" "$script_dir/retention-continuity.sh" \
    "$script_dir/select-images-only-review.sh" "$script_dir/retention-sampled-recovery.sh" "$script_dir/retention-predelete-recovery.sh"
  if [[ "$operation" == audit || "$operation" == retirement-audit || "$operation" == gate-audit || "$operation" == cleanup ]]; then
    cat "$script_dir/audit-xboard-images-only.sh"
    if [[ "$operation" == retirement-audit ]]; then
      printf 'retirement_scope=full\n'
      cat "$script_dir/audit-retirement-readiness.sh"
    elif [[ "$operation" == gate-audit ]]; then
      printf 'retirement_scope=gate\n'
      cat "$script_dir/audit-retirement-readiness.sh"
    fi
    if [[ "$operation" == cleanup ]]; then cat "$script_dir/cleanup-xboard-images-only.sh"; fi
  else
    # Failure recording must not depend on a successful current-runtime audit.
    # A lost SSH session or bad host state leaves RUNNING/pending in place.
    cat <<'POST'
xboard_find_compose_anchor
workdir=$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR")
_rc_paths "$workdir"
_rc_secure_file "$RC_ROOT/deploy.lock"
exec 9<>"$RC_ROOT/deploy.lock"
flock -n 9
POST
    if [[ "$operation" == post-success ]]; then
      printf 'retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$POST_EVIDENCE_SHA256"\n'
    else
      printf 'retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" failure\n'
    fi
  fi
}
payload | timeout --signal=TERM --kill-after=30s "$((deadline+60))s" bash "$script_dir/ssh-with-password.sh" \
  -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 \
  -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" \
  "$remote_env timeout --signal=TERM --kill-after=30s ${deadline}s bash -se"
