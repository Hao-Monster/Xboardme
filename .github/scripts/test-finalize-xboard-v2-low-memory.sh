#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

run_finalize_case() (
  set -Eeuo pipefail
  local scenario=$1
  declare -A state=()
  declare -A present=()

  RELEASE_ID=333-1
  EXPECTED_RELEASE_SHA=$(printf 'a%.0s' {1..40})
  V2_STATE_FILE=/mock/state.json
  ACTIVE_PORT=7002
  MAINTENANCE_PORT=7006
  CADDY_CONFIG=/mock/Caddyfile
  MAINTENANCE_CONTAINER=xboard-v2-maintenance-333-1
  LEGACY_ANCHOR_ID=old-redis
  LEGACY_WEB_ID=old-web
  LEGACY_HORIZON_ID=old-horizon
  LEGACY_SCHEDULER_ID=old-scheduler
  LEGACY_WS_ID=old-ws
  LEGACY_EDGE_ID=old-edge
  state[traffic_state]=active_v2
  state[switched_at]=2020-01-01T00:00:00Z
  unset V2_FINALIZE_REASON SUPERSEDING_RELEASE_SHA

  case "$scenario" in
    legacy|too_early)
      LEGACY_TOPOLOGY=legacy
      LEGACY_ANCHOR_ID=compose-anchor
      for id in compose-anchor old-web old-horizon old-scheduler "$MAINTENANCE_CONTAINER"; do
        present[$id]=true
      done
      [[ "$scenario" != too_early ]] || state[switched_at]=$(date -u +%FT%TZ)
      ;;
    v2|superseded|same_candidate)
      LEGACY_TOPOLOGY=v2
      for id in old-redis old-web old-horizon old-scheduler old-ws old-edge \
        xboard-v2-maintenance-111-1 "$MAINTENANCE_CONTAINER"; do
        present[$id]=true
      done
      if [[ "$scenario" == superseded || "$scenario" == same_candidate ]]; then
        state[switched_at]=$(date -u +%FT%TZ)
        V2_FINALIZE_REASON=superseded
        SUPERSEDING_RELEASE_SHA=$(printf 'b%.0s' {1..40})
        [[ "$scenario" != same_candidate ]] || SUPERSEDING_RELEASE_SHA=$EXPECTED_RELEASE_SHA
      fi
      ;;
    retry|retry_superseded|retry_superseded_mismatch)
      LEGACY_TOPOLOGY=v2
      state[traffic_state]=finalizing
      state[previous_release_retiring_id]=111-1
      present[xboard-v2-maintenance-111-1]=true
      present[$MAINTENANCE_CONTAINER]=true
      if [[ "$scenario" == retry_superseded || "$scenario" == retry_superseded_mismatch ]]; then
        V2_FINALIZE_REASON=superseded
        SUPERSEDING_RELEASE_SHA=$(printf 'b%.0s' {1..40})
        state[finalize_reason]=superseded
        state[superseded_by_sha]=$SUPERSEDING_RELEASE_SHA
        [[ "$scenario" != retry_superseded_mismatch ]] || state[superseded_by_sha]=$(printf 'c%.0s' {1..40})
      fi
      ;;
    *)
      echo "FINALIZE_TEST_FAIL=unknown_scenario:$scenario" >&2
      return 1
      ;;
  esac

  release_state_get() {
    printf '%s\n' "${state[$2]:?missing mock state key $2}"
  }
  release_state_get_optional() {
    printf '%s\n' "${state[$2]:-}"
  }
  release_state_set() {
    state[$2]=$3
  }
  v2_require_tools() { :; }
  v2_open_release() { TRAFFIC_STATE=${state[traffic_state]}; }
  v2_service_healthy() { :; }
  v2_assert_v2_owners() { :; }
  v2_caddy_reference_count() {
    if [[ "$2" == "$ACTIVE_PORT" ]]; then printf '1\n'; else printf '0\n'; fi
  }
  v2_assert_recorded_container() {
    [[ ${present[$1]:-false} == true ]]
  }
  v2_container_running() { return 1; }
  v2_legacy_ids() {
    printf '%s\n' "$LEGACY_ANCHOR_ID" "$LEGACY_WEB_ID" "$LEGACY_HORIZON_ID" "$LEGACY_SCHEDULER_ID"
    [[ "$LEGACY_TOPOLOGY" != v2 ]] || printf '%s\n' "$LEGACY_WS_ID" "$LEGACY_EDGE_ID"
  }
  v2_fail() {
    echo "V2_FAIL=$1" >&2
    return 1
  }
  docker() {
    local command=${1:-}
    shift || true
    case "$command" in
      container)
        [[ ${1:-} == inspect && ${present[${2:-}]:-false} == true ]]
        ;;
      inspect)
        [[ ${1:-} == -f ]]
        local format=$2 id=$3
        case "$format" in
          *com.docker.compose.project*)
            [[ "$scenario" != retry && "$scenario" != retry_superseded && "$scenario" != retry_superseded_mismatch ]] || {
              echo 'FINALIZE_TEST_FAIL=retry_recomputed_previous_identity' >&2
              return 1
            }
            [[ "$id" == old-redis ]]
            printf 'xboard-v2-111-1\n'
            ;;
          *codex.xboard.v2.release*)
            case "$id" in
              xboard-v2-maintenance-111-1) printf '111-1\n' ;;
              "$MAINTENANCE_CONTAINER") printf '%s\n' "$RELEASE_ID" ;;
              *) return 1 ;;
            esac
            ;;
          *PortBindings*) printf '7004\n' ;;
          *) return 1 ;;
        esac
        ;;
      rm)
        [[ ${1:-} != -f ]] || shift
        local id=${1:?missing mock rm id}
        [[ ${present[$id]:-false} == true ]]
        present[$id]=false
        printf '%s\n' "$id" >> "$FINALIZE_TEST_LOG"
        ;;
      *)
        echo "FINALIZE_TEST_FAIL=unexpected_docker_command:$command $*" >&2
        return 1
        ;;
    esac
  }

  # shellcheck source=finalize-xboard-v2-low-memory.sh
  source "$script_dir/finalize-xboard-v2-low-memory.sh"

  [[ ${state[traffic_state]} == finalized ]]
  [[ ${state[rollback_supported]} == false ]]
  case "$scenario" in
    legacy)
      [[ ${present[compose-anchor]} == true ]]
      grep -qx old-web "$FINALIZE_TEST_LOG"
      grep -qx old-horizon "$FINALIZE_TEST_LOG"
      grep -qx old-scheduler "$FINALIZE_TEST_LOG"
      ! grep -qx compose-anchor "$FINALIZE_TEST_LOG"
      [[ ${state[compose_anchor_preserved]} == compose-anchor ]]
      ;;
    v2|retry|retry_superseded|superseded)
      [[ ${state[previous_release_retired_id]} == 111-1 ]]
      grep -qx xboard-v2-maintenance-111-1 "$FINALIZE_TEST_LOG"
      [[ ${state[compose_anchor_preserved]} == external ]]
      ;;
  esac
  if [[ "$scenario" == superseded ]]; then
    [[ ${state[finalize_reason]} == superseded ]]
    [[ ${state[superseded_by_sha]} == "$SUPERSEDING_RELEASE_SHA" ]]
  fi
  grep -qx "$MAINTENANCE_CONTAINER" "$FINALIZE_TEST_LOG"
)

for scenario in legacy v2 retry retry_superseded superseded; do
  FINALIZE_TEST_LOG=$(mktemp)
  export FINALIZE_TEST_LOG
  trap 'rm -f -- "$FINALIZE_TEST_LOG"' EXIT
  run_finalize_case "$scenario" >/dev/null
  rm -f -- "$FINALIZE_TEST_LOG"
  trap - EXIT
done

FINALIZE_TEST_LOG=$(mktemp)
too_early_output=$(mktemp)
export FINALIZE_TEST_LOG
trap 'rm -f -- "$FINALIZE_TEST_LOG" "$too_early_output"' EXIT
set +e
run_finalize_case too_early >"$too_early_output" 2>&1
too_early_status=$?
set -e
if ((too_early_status == 0)); then
  echo 'FINALIZE_TEST_FAIL=rollback_window_was_not_enforced' >&2
  exit 1
fi
grep -q 'V2_FAIL=rollback_window_open:' "$too_early_output"
[[ ! -s "$FINALIZE_TEST_LOG" ]]
rm -f -- "$FINALIZE_TEST_LOG" "$too_early_output"
trap - EXIT

FINALIZE_TEST_LOG=$(mktemp)
same_candidate_output=$(mktemp)
export FINALIZE_TEST_LOG
trap 'rm -f -- "$FINALIZE_TEST_LOG" "$same_candidate_output"' EXIT
set +e
run_finalize_case same_candidate >"$same_candidate_output" 2>&1
same_candidate_status=$?
set -e
if ((same_candidate_status == 0)); then
  echo 'FINALIZE_TEST_FAIL=same_candidate_was_accepted' >&2
  exit 1
fi
grep -q 'V2_FAIL=superseding_release_matches_active' "$same_candidate_output"
[[ ! -s "$FINALIZE_TEST_LOG" ]]
rm -f -- "$FINALIZE_TEST_LOG" "$same_candidate_output"
trap - EXIT

FINALIZE_TEST_LOG=$(mktemp)
retry_mismatch_output=$(mktemp)
export FINALIZE_TEST_LOG
trap 'rm -f -- "$FINALIZE_TEST_LOG" "$retry_mismatch_output"' EXIT
set +e
run_finalize_case retry_superseded_mismatch >"$retry_mismatch_output" 2>&1
retry_mismatch_status=$?
set -e
if ((retry_mismatch_status == 0)); then
  echo 'FINALIZE_TEST_FAIL=retry_candidate_change_was_accepted' >&2
  exit 1
fi
grep -q 'V2_FAIL=superseding_release_changed_during_retry' "$retry_mismatch_output"
[[ ! -s "$FINALIZE_TEST_LOG" ]]
rm -f -- "$FINALIZE_TEST_LOG" "$retry_mismatch_output"
trap - EXIT

echo 'FINALIZE_V2_LOW_MEMORY_TEST=PASS'
