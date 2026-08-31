#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

run_network_cleanup_case() (
  set -Eeuo pipefail
  local scenario=$1
  local network_present=true
  local network_endpoints=0

  EXPECTED_NETWORK_DEBT_FINGERPRINT=$(printf 'a%.0s' {1..64})
  EXPECTED_ACTIVE_RELEASE_ID=333-1
  EXPECTED_ACTIVE_RELEASE_SHA=$(printf 'b%.0s' {1..40})
  EXPECTED_WORKFLOW_SHA=$(printf 'c%.0s' {1..40})
  RETENTION_REQUIRE_FINALIZED=true
  RETENTION_ACQUIRE_LOCK=true
  network_debt_fingerprint=$EXPECTED_NETWORK_DEBT_FINGERPRINT
  active_release_id=$EXPECTED_ACTIVE_RELEASE_ID
  active_revision=$EXPECTED_ACTIVE_RELEASE_SHA
  active_project=xboard-v2-333-1
  active_traffic_state=finalized
  active_rollback_supported=false
  direct_previous_project=''
  active_container=active-id
  active_redis_volume=xboard_redis-data
  active_app_data_path=$NETWORK_TEST_DATA
  active_app_data_id=$(stat -c '%d:%i' "$active_app_data_path")
  XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002
  XBOARD_ACTIVE_PORT=7002
  XBOARD_ACTIVE_WEB=active-web
  XBOARD_ANCHOR_CONTAINER=anchor-id
  XBOARD_CADDY_FILE=$NETWORK_TEST_CADDY
  workdir=$NETWORK_TEST_WORKDIR
  network_inventory=$NETWORK_TEST_INVENTORY

  if [[ "$scenario" == mismatch ]]; then
    network_debt_fingerprint=$(printf 'd%.0s' {1..64})
  elif [[ "$scenario" == gained-endpoints ]]; then
    network_endpoints=1
  elif [[ "$scenario" == protected-project ]]; then
    sed 's/project=xboard-v2-111-1/project=xboard-v2-333-1/' "$NETWORK_TEST_INVENTORY" > "$NETWORK_TEST_INVENTORY.tmp"
    network_inventory=$NETWORK_TEST_INVENTORY.tmp
  fi

  release_state_validate() { [[ $1 == "$NETWORK_TEST_STATE" ]]; }
  release_state_get() {
    case $2 in
      release_id) printf '111-1\n' ;;
      project_name) printf 'xboard-v2-111-1\n' ;;
      traffic_state) printf 'rolled_back\n' ;;
      release_sha) printf '%s\n' "$(printf 'e%.0s' {1..40})" ;;
      *) return 1 ;;
    esac
  }
  network_debt_inspect_row() {
    [[ "$network_present" == true && $1 == old-network-id ]]
    printf 'old-network-id\txboard-v2-111-1_egress\tbridge\tlocal\tfalse\txboard-v2-111-1\tegress\t%s\t172.31.0.0/16\t2026-01-01T00:00:00Z\n' "$network_endpoints"
  }
  xboard_find_caddy_upstream() { XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002; }
  xboard_resolve_active_runtime() { XBOARD_ACTIVE_WEB=active-web; }
  docker() {
    local command=${1:-}
    shift || true
    case "$command" in
      network)
        local operation=${1:-} id=${2:-}
        case "$operation" in
          rm)
            [[ "$id" == old-network-id && "$network_present" == true ]]
            network_present=false
            printf 'network-rm %s\n' "$id" >> "$NETWORK_TEST_LOG"
            ;;
          inspect) [[ "$id" == old-network-id && "$network_present" == true ]] ;;
          *) return 1 ;;
        esac
        ;;
      inspect)
        [[ ${1:-} == -f ]]
        local format=$2 id=$3
        case "$format:$id" in
          *'.Id'*:anchor-id) printf 'anchor-id\n' ;;
          *'.Id'*:active-web) printf 'active-id\n' ;;
          *'.State.Status'*:active-id) printf 'running\n' ;;
          *'.State.Health'*:active-id) printf 'healthy\n' ;;
          *) return 1 ;;
        esac
        ;;
      ps) printf 'active-id\n' ;;
      volume) [[ ${1:-} == inspect && ${2:-} == xboard_redis-data ]] ;;
      container) [[ ${1:-} == inspect && ${2:-} == anchor-id ]] ;;
      *) return 1 ;;
    esac
  }
  caddy() { :; }

  # shellcheck source=cleanup-xboard-network-debt.sh
  source "$script_dir/cleanup-xboard-network-debt.sh"

  [[ "$network_present" == false ]]
)

NETWORK_TEST_ROOT=$(mktemp -d)
NETWORK_TEST_WORKDIR=$NETWORK_TEST_ROOT/workdir
NETWORK_TEST_DATA=$NETWORK_TEST_WORKDIR/data
NETWORK_TEST_STATE=$NETWORK_TEST_WORKDIR/.codex-v2-release/111-1/state.json
NETWORK_TEST_CADDY=$NETWORK_TEST_ROOT/Caddyfile
NETWORK_TEST_INVENTORY=$NETWORK_TEST_ROOT/network-inventory.txt
NETWORK_TEST_LOG=$NETWORK_TEST_ROOT/mutations.log
export NETWORK_TEST_WORKDIR NETWORK_TEST_DATA NETWORK_TEST_STATE NETWORK_TEST_CADDY NETWORK_TEST_INVENTORY NETWORK_TEST_LOG
trap 'rm -rf -- "$NETWORK_TEST_ROOT"' EXIT
mkdir -p "$NETWORK_TEST_DATA" "$(dirname -- "$NETWORK_TEST_STATE")"
: > "$NETWORK_TEST_STATE"
: > "$NETWORK_TEST_CADDY"
: > "$NETWORK_TEST_LOG"
cat > "$NETWORK_TEST_INVENTORY" <<'INVENTORY'
NETWORK_DEBT_NETWORK name=xboard-v2-111-1_egress id=old-network-id classification=candidate reason=empty_retired_release_network project=xboard-v2-111-1 logical=egress driver=bridge scope=local internal=false endpoints=0 release_id=111-1 traffic_state=rolled_back revision=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee subnets=172.31.0.0/16 created_at=2026-01-01T00:00:00Z
INVENTORY

mismatch_output=$NETWORK_TEST_ROOT/mismatch.out
set +e
run_network_cleanup_case mismatch >"$mismatch_output" 2>&1
mismatch_status=$?
set -e
((mismatch_status != 0))
grep -q 'NETWORK_DEBT_CLEANUP_FAIL=fingerprint_mismatch' "$mismatch_output"
[[ ! -s "$NETWORK_TEST_LOG" ]]

endpoints_output=$NETWORK_TEST_ROOT/endpoints.out
set +e
run_network_cleanup_case gained-endpoints >"$endpoints_output" 2>&1
endpoints_status=$?
set -e
((endpoints_status != 0))
grep -q 'NETWORK_DEBT_CLEANUP_FAIL=candidate_gained_endpoints' "$endpoints_output"
[[ ! -s "$NETWORK_TEST_LOG" ]]

protected_output=$NETWORK_TEST_ROOT/protected.out
set +e
run_network_cleanup_case protected-project >"$protected_output" 2>&1
protected_status=$?
set -e
((protected_status != 0))
grep -q 'NETWORK_DEBT_CLEANUP_FAIL=protected_project_in_candidates' "$protected_output"
[[ ! -s "$NETWORK_TEST_LOG" ]]

run_network_cleanup_case happy >"$NETWORK_TEST_ROOT/happy.out"
grep -Fxq 'network-rm old-network-id' "$NETWORK_TEST_LOG"
grep -q 'NETWORK_DEBT_CLEANUP=PASS id=333-1 removed_networks=1 containers=preserved volumes=preserved images=preserved directories=preserved' "$NETWORK_TEST_ROOT/happy.out"

echo 'NETWORK_DEBT_CLEANUP_TEST=PASS'
