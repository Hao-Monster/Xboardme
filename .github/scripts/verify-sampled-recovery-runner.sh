#!/usr/bin/env bash
set -Eeuo pipefail
# Runner evidence only. Reuse exact-main/clean-checkout/fresh-review admission.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/select-images-only-review.sh"
operation=verify
if [[ ${1:-} == --check-evidence ]]; then operation=check; shift; fi
[[ $# == 0 ]] || exit 1
review=$(images_only_selected_review)
bash "$script_dir/verify-images-only-request.sh" "$review"
[[ "$IMAGE_CLEANUP_ACTION" == cleanup ]] || exit 1
if ! jq -e '.recovery.schema_version==2 and .recovery.mode=="predelete_sampled_hold_v2"' "$review" >/dev/null; then
  echo 'SAMPLED_RECOVERY_PROOF=NOT_REQUIRED'
  exit 0
fi
timeout --signal=TERM --kill-after=5s 180s node "$script_dir/verify-sampled-recovery-runner.mjs" "$operation" "$review"
