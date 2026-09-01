#!/usr/bin/env bash
set -Eeuo pipefail

if ! declare -F release_state_get >/dev/null || ! declare -F v2_open_release >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
  # shellcheck source=v2-low-memory-common.sh
  source "$script_dir/v2-low-memory-common.sh"
fi

: "${RELEASE_ID:?RELEASE_ID is required}"
: "${EXPECTED_RELEASE_SHA:?EXPECTED_RELEASE_SHA is required}"
: "${EXPECTED_PREDECESSOR_SHA:?EXPECTED_PREDECESSOR_SHA is required}"
[[ "$EXPECTED_PREDECESSOR_SHA" =~ ^[0-9a-f]{40}$ ]] || v2_fail invalid_expected_predecessor_sha
[[ "$EXPECTED_PREDECESSOR_SHA" != "$EXPECTED_RELEASE_SHA" ]] || v2_fail predecessor_matches_candidate

v2_require_tools
v2_open_release
[[ "$TRAFFIC_STATE" == prepared ]] || v2_fail "invalid_prepared_verification_state:$TRAFFIC_STATE"
recorded_predecessor_sha=$(release_state_get "$V2_STATE_FILE" legacy_release_sha)
[[ "$recorded_predecessor_sha" == "$EXPECTED_PREDECESSOR_SHA" ]] || v2_fail prepared_predecessor_sha_mismatch
v2_assert_legacy_identity
while IFS= read -r id; do
  v2_container_running "$id" || v2_fail prepared_predecessor_not_running
done < <(v2_legacy_ids)

echo "V2_PREPARED_PREDECESSOR=PASS id=$RELEASE_ID candidate=$EXPECTED_RELEASE_SHA predecessor=$EXPECTED_PREDECESSOR_SHA"
