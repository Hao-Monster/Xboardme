#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

run_case() (
  set -Eeuo pipefail
  local scenario=$1
  RELEASE_ID=444-1
  EXPECTED_RELEASE_SHA=$(printf 'b%.0s' {1..40})
  EXPECTED_PREDECESSOR_SHA=$(printf 'a%.0s' {1..40})
  V2_STATE_FILE=/mock/state.json
  TRAFFIC_STATE=prepared
  recorded_predecessor_sha=$EXPECTED_PREDECESSOR_SHA
  [[ "$scenario" != mismatch ]] || recorded_predecessor_sha=$(printf 'c%.0s' {1..40})

  release_state_get() { printf '%s\n' "$recorded_predecessor_sha"; }
  v2_require_tools() { :; }
  v2_open_release() { :; }
  v2_assert_legacy_identity() { :; }
  v2_legacy_ids() { printf '%s\n' old-web old-worker; }
  v2_container_running() { :; }
  v2_fail() {
    echo "V2_FAIL=$1" >&2
    return 1
  }

  # shellcheck source=verify-xboard-v2-prepared-release.sh
  source "$script_dir/verify-xboard-v2-prepared-release.sh"
)

valid_output=$(run_case valid)
grep -q 'V2_PREPARED_PREDECESSOR=PASS' <<<"$valid_output"

mismatch_output=$(mktemp)
trap 'rm -f -- "$mismatch_output"' EXIT
set +e
run_case mismatch >"$mismatch_output" 2>&1
mismatch_status=$?
set -e
((mismatch_status != 0))
grep -q 'V2_FAIL=prepared_predecessor_sha_mismatch' "$mismatch_output"
rm -f -- "$mismatch_output"
trap - EXIT

echo 'VERIFY_V2_PREPARED_RELEASE_TEST=PASS'
