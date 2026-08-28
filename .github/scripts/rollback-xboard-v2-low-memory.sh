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
  maintenance|ready|active_v2) ;;
  prepared)
    v2_assert_legacy_identity
    while IFS= read -r id; do
      v2_container_running "$id" || v2_fail prepared_runtime_not_running
    done < <(v2_legacy_ids)
    cmp -s -- "$CADDY_CONFIG" "$CADDY_BACKUP" || v2_fail prepared_caddy_changed
    release_state_set "$V2_STATE_FILE" traffic_state rolled_back
    release_state_set "$V2_STATE_FILE" rolled_back_at "$(date -u +%FT%TZ)"
    echo "V2_ROLLBACK=PASS id=$RELEASE_ID state=prepared_noop external_smoke_required"
    exit 0
    ;;
  rolled_back)
    v2_assert_legacy_identity
    while IFS= read -r id; do
      v2_container_running "$id" || v2_fail rolled_back_runtime_not_running
    done < <(v2_legacy_ids)
    echo "V2_ROLLBACK=PASS id=$RELEASE_ID state=already_rolled_back external_smoke_required"
    exit 0
    ;;
  *) v2_fail "invalid_rollback_state:$TRAFFIC_STATE" ;;
esac

v2_rollback_runtime

echo "V2_ROLLBACK=PASS id=$RELEASE_ID upstream=127.0.0.1:$ACTIVE_PORT external_smoke_required"
