#!/usr/bin/env bash
set -Eeuo pipefail
# This is a runner-side transport, never installed on the production host.
# All payloads are from the admitted checkout. There is no arbitrary path,
# target, command or maintenance-action input.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
operation=${1:?audit, cleanup, post-success or post-failure required}
case "$operation" in audit|cleanup|post-success|post-failure) ;; *) exit 1 ;; esac
bash "$script_dir/verify-images-only-request.sh"
: "${DEPLOY_HOST:?required}" "${DEPLOY_PORT:?required}" "${DEPLOY_USER:?required}"
[[ "$DEPLOY_PORT" =~ ^[0-9]{1,5}$ && "$DEPLOY_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.:-]*$ &&
   "$DEPLOY_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] || exit 1
review="$script_dir/../release/image-cleanup-review.json"
remote_env="EXPECTED_WORKFLOW_SHA='$EXPECTED_SHA'"
if [[ "$IMAGE_CLEANUP_ACTION" == cleanup ]]; then
  review_hash=$(sha256sum < "$review" | awk '{print $1}')
  active_id=$(jq -er .active_release_id "$review")
  active_sha=$(jq -er .active_release_sha "$review")
  resource=$(jq -er .resource_fingerprint "$review")
  remote_env+=" EXPECTED_ACTIVE_RELEASE_ID='$active_id' EXPECTED_ACTIVE_RELEASE_SHA='$active_sha'"
  remote_env+=" EXPECTED_RETENTION_REVIEW_SHA256='$review_hash' EXPECTED_RETENTION_RESOURCE_FINGERPRINT='$resource'"
fi
if [[ "$operation" != audit && "$IMAGE_CLEANUP_ACTION" != cleanup ]]; then exit 1; fi
deadline=300
if [[ "$operation" == cleanup ]]; then
  deadline=2700
  manifest=$(base64 -w0 "$review")
  [[ "$manifest" =~ ^[A-Za-z0-9+/=]+$ ]] || exit 1
  remote_env+=" RETENTION_REVIEW_MANIFEST_B64='$manifest'"
fi
if [[ "$operation" == post-success ]]; then
  [[ "${POST_EVIDENCE_SHA256:-}" =~ ^[a-f0-9]{64}$ ]] || exit 1
  remote_env+=" POST_EVIDENCE_SHA256='$POST_EVIDENCE_SHA256'"
fi
payload() {
  printf 'set -Eeuo pipefail\numask 077\n'
  cat "$script_dir/production-runtime-discovery.sh" "$script_dir/release-state.sh" \
    "$script_dir/retention-safety.sh" "$script_dir/image-startup-references.sh" \
    "$script_dir/images-only-approval.sh" "$script_dir/retention-continuity.sh"
  if [[ "$operation" == audit || "$operation" == cleanup ]]; then
    cat "$script_dir/audit-xboard-images-only.sh"
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
payload | bash "$script_dir/ssh-with-password.sh" \
  -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 \
  -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" \
  "$remote_env timeout --signal=TERM --kill-after=30s ${deadline}s bash -se"
