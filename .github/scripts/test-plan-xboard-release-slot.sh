#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

run_plan_case() (
  set -Eeuo pipefail
  local scenario=$1

  EXPECTED_CANDIDATE_SHA=$(printf 'b%.0s' {1..40})
  PREFERRED_STAGE_PORT=7004
  XBOARD_ACTIVE_TOPOLOGY=v2
  XBOARD_ACTIVE_PORT=7002
  XBOARD_CADDY_FILE=/mock/Caddyfile
  active_release_id=333-1
  active_revision=$(printf 'a%.0s' {1..40})
  active_state_file=/mock/state.json
  active_traffic_state=active_v2
  active_rollback_supported=true
  finalize_reason=''
  superseded_by_sha=''

  case "$scenario" in
    rotate)
      ;;
    finalized)
      active_traffic_state=finalized
      active_rollback_supported=false
      ;;
    resume_rotation)
      active_traffic_state=finalizing
      finalize_reason=superseded
      superseded_by_sha=$EXPECTED_CANDIDATE_SHA
      ;;
    incompatible_finalize)
      active_traffic_state=finalizing
      finalize_reason=superseded
      superseded_by_sha=$(printf 'c%.0s' {1..40})
      ;;
    same_candidate)
      EXPECTED_CANDIDATE_SHA=$active_revision
      ;;
    invalid_state)
      active_traffic_state=maintenance
      ;;
    *)
      echo "RELEASE_SLOT_TEST_FAIL=unknown_scenario:$scenario" >&2
      return 1
      ;;
  esac

  release_state_get_optional() {
    case "$2" in
      maintenance_port) printf '7004\n' ;;
      finalize_reason) printf '%s\n' "$finalize_reason" ;;
      superseded_by_sha) printf '%s\n' "$superseded_by_sha" ;;
      *) return 1 ;;
    esac
  }
  release_slot_listener_count() {
    [[ "$1" == 7003 ]] && printf '1\n' || printf '0\n'
  }
  release_slot_caddy_reference_count() {
    [[ "$1" == 7002 ]] && printf '1\n' || printf '0\n'
  }
  release_slot_fail() {
    echo "RELEASE_SLOT_FAIL=$1" >&2
    return 1
  }

  # shellcheck source=plan-xboard-release-slot.sh
  source "$script_dir/plan-xboard-release-slot.sh"
)

rotate_output=$(run_plan_case rotate)
grep -qx 'RELEASE_SLOT_ROTATION_REQUIRED=true' <<<"$rotate_output"
grep -qx 'RELEASE_SLOT_ACTIVE_RELEASE_ID=333-1' <<<"$rotate_output"
grep -qx 'RELEASE_SLOT_STAGE_PORT=7005' <<<"$rotate_output"

finalized_output=$(run_plan_case finalized)
grep -qx 'RELEASE_SLOT_ROTATION_REQUIRED=false' <<<"$finalized_output"
grep -qx 'RELEASE_SLOT_STAGE_PORT=7005' <<<"$finalized_output"

resume_output=$(run_plan_case resume_rotation)
grep -qx 'RELEASE_SLOT_ROTATION_REQUIRED=true' <<<"$resume_output"
grep -qx 'RELEASE_SLOT_STAGE_PORT=7005' <<<"$resume_output"

for scenario in same_candidate invalid_state incompatible_finalize; do
  output=$(mktemp)
  trap 'rm -f -- "$output"' EXIT
  set +e
  run_plan_case "$scenario" >"$output" 2>&1
  status=$?
  set -e
  ((status != 0))
  case "$scenario" in
    same_candidate) grep -q 'RELEASE_SLOT_FAIL=candidate_matches_active' "$output" ;;
    invalid_state) grep -q 'RELEASE_SLOT_FAIL=unsupported_active_lifecycle' "$output" ;;
    incompatible_finalize) grep -q 'RELEASE_SLOT_FAIL=incompatible_finalize_in_progress' "$output" ;;
  esac
  rm -f -- "$output"
  trap - EXIT
done

echo 'PLAN_XBOARD_RELEASE_SLOT_TEST=PASS'
