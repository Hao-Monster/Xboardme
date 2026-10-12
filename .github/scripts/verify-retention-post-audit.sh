#!/usr/bin/env bash
set -Eeuo pipefail

# Local verification of a completed, lock-protected remote audit. The identity
# comparison is unconditional: a failed mutation is not a reason to skip it.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
review=${1:?review path is required}
audit=${2:?audit path is required}
: "${MUTATION_RESULT:?mutation result is required}"
: "${BATCH_DISPOSITION:=}"
fail() { echo "RETENTION_POST_AUDIT_FAIL=$1" >&2; exit 1; }
retention_review_validate "$review" || fail review_not_approved
[[ -f "$audit" && ! -L "$audit" ]] || fail audit_missing
[[ $(grep -Fxc 'RETENTION_AUDIT=PASS mode=read_only' "$audit") == 1 ]] || fail audit_not_complete
audit_value() {
  local key=$1 values
  values=$(grep -F "$key=" "$audit" | grep "^$key=") || return 1
  [[ "$values" != *$'\n'* ]] || return 1
  printf '%s' "${values#*=}"
}
[[ $(audit_value RETENTION_ACTIVE_RELEASE_ID) == "$(jq -r .active_release_id "$review")" ]] || fail active_release_changed
[[ $(audit_value RETENTION_ACTIVE_REVISION) == "$(jq -r .active_release_sha "$review")" ]] || fail active_revision_changed
[[ $(audit_value RETENTION_PROTECTION_FINGERPRINT) == "$(jq -r .protection_fingerprint "$review")" ]] || fail protected_resources_changed
state=$(audit_value RETENTION_ACTIVE_TRAFFIC_STATE) || fail lifecycle_missing
rollback=$(audit_value RETENTION_ACTIVE_ROLLBACK_SUPPORTED) || fail rollback_state_missing
case "$MUTATION_RESULT" in
  success)
    case "$BATCH_DISPOSITION" in complete|pending_batch) ;; *) fail batch_disposition_missing ;; esac
    expected_state=finalized
    if [[ $(jq -r .operation "$review") == v2_finalize && "$BATCH_DISPOSITION" == pending_batch ]]; then
      expected_state=finalizing
    fi
    [[ "$state" == "$expected_state" && "$rollback" == false ]] || fail lifecycle_mismatch
    ;;
  failure|cancelled) ;; # Evidence only; the failed parent job remains failed.
  *) fail mutation_result_invalid ;;
esac
echo "RETENTION_POST_AUDIT=PASS protected_resources=unchanged mutation=$MUTATION_RESULT disposition=${BATCH_DISPOSITION:-unknown} traffic_state=$state rollback_supported=$rollback"
