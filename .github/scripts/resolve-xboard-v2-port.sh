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

v2_require_tools
v2_open_release
case "$TRAFFIC_STATE" in
  prepared|maintenance|ready|active_v2|finalizing|finalized) ;;
  *) v2_fail "invalid_resolve_port_state:$TRAFFIC_STATE" ;;
esac

printf '%s\n' "$ACTIVE_PORT"
