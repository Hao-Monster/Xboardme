#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

run_network_audit_case() (
  set -Eeuo pipefail
  local scenario=$1

  EXPECTED_WORKFLOW_SHA=$(printf 'c%.0s' {1..40})
  inventory=$(mktemp "$NETWORK_AUDIT_TEST_ROOT/retention-inventory.XXXXXX")
  workdir=$NETWORK_AUDIT_TEST_WORKDIR
  active_project=xboard-v2-333-1
  active_release_id=333-1
  active_revision=$(printf 'b%.0s' {1..40})
  active_app_data_id=1:2
  active_redis_volume=xboard_redis-data
  direct_previous_project=''
  XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002

  release_state_validate() {
    [[ -f $1 && ! -L $1 ]]
  }
  release_state_get() {
    local release_id
    release_id=$(basename -- "$(dirname -- "$1")")
    case $2 in
      release_id) printf '%s\n' "$release_id" ;;
      project_name) printf 'xboard-v2-%s\n' "$release_id" ;;
      traffic_state) printf 'rolled_back\n' ;;
      release_sha) printf '%s\n' "$(printf 'e%.0s' {1..40})" ;;
      *) return 1 ;;
    esac
  }
  network_json() {
    local id=$1 name=$2 project=$3 logical=$4 internal=$5 endpoints=$6
    printf '[{"Id":"%s","Name":"%s","Driver":"bridge","Scope":"local","Internal":%s,"Labels":{"com.docker.compose.project":"%s","com.docker.compose.network":"%s"},"Containers":%s,"IPAM":{"Config":[{"Subnet":"172.31.0.0/16"}]},"Created":"2026-01-01T00:00:00Z"}]\n' \
      "$id" "$name" "$internal" "$project" "$logical" "$endpoints"
  }
  docker() {
    [[ ${1:-} == network ]]
    case ${2:-} in
      ls)
        case "$scenario" in
          happy) printf 'active-id\nold-id\nunrelated-id\n' ;;
          zero) printf 'active-id\nunrelated-id\n' ;;
          invalid) printf 'active-id\ninvalid-id\n' ;;
          *) return 1 ;;
        esac
        ;;
      inspect)
        case ${3:-} in
          active-id) network_json active-id xboard-v2-333-1_egress xboard-v2-333-1 egress false '{"active-endpoint":{}}' ;;
          old-id) network_json old-id xboard-v2-111-1_egress xboard-v2-111-1 egress false '{}' ;;
          unrelated-id) network_json unrelated-id bridge none none false '{}' ;;
          invalid-id) network_json invalid-id xboard-v2-222-1_egress xboard-v2-222-1 egress false '{}' ;;
          *) return 1 ;;
        esac
        ;;
      *) return 1 ;;
    esac
  }

  # shellcheck source=audit-xboard-network-debt.sh
  source "$script_dir/audit-xboard-network-debt.sh"
)

NETWORK_AUDIT_TEST_ROOT=$(mktemp -d)
NETWORK_AUDIT_TEST_WORKDIR=$NETWORK_AUDIT_TEST_ROOT/workdir
export NETWORK_AUDIT_TEST_ROOT NETWORK_AUDIT_TEST_WORKDIR
trap 'rm -rf -- "$NETWORK_AUDIT_TEST_ROOT"' EXIT
mkdir -p "$NETWORK_AUDIT_TEST_WORKDIR/.codex-v2-release/111-1"
: > "$NETWORK_AUDIT_TEST_WORKDIR/.codex-v2-release/111-1/state.json"

run_network_audit_case happy >"$NETWORK_AUDIT_TEST_ROOT/happy.out"
grep -Fxq 'NETWORK_DEBT_CANDIDATE_COUNT=1' "$NETWORK_AUDIT_TEST_ROOT/happy.out"
grep -q 'name=xboard-v2-333-1_egress .*classification=protected reason=active_project' "$NETWORK_AUDIT_TEST_ROOT/happy.out"
grep -q 'name=xboard-v2-111-1_egress .*classification=candidate reason=empty_retired_release_network' "$NETWORK_AUDIT_TEST_ROOT/happy.out"
grep -q 'name=bridge .*classification=unrelated reason=not_xboard_v2' "$NETWORK_AUDIT_TEST_ROOT/happy.out"
grep -Eq '^NETWORK_DEBT_FINGERPRINT=[a-f0-9]{64}$' "$NETWORK_AUDIT_TEST_ROOT/happy.out"
grep -Fxq 'NETWORK_DEBT_AUDIT=PASS mode=read_only' "$NETWORK_AUDIT_TEST_ROOT/happy.out"

run_network_audit_case zero >"$NETWORK_AUDIT_TEST_ROOT/zero.out"
grep -Fxq 'NETWORK_DEBT_CANDIDATE_COUNT=0' "$NETWORK_AUDIT_TEST_ROOT/zero.out"
grep -Eq '^NETWORK_DEBT_FINGERPRINT=[a-f0-9]{64}$' "$NETWORK_AUDIT_TEST_ROOT/zero.out"

invalid_output=$NETWORK_AUDIT_TEST_ROOT/invalid.out
set +e
run_network_audit_case invalid >"$invalid_output" 2>&1
invalid_status=$?
set -e
((invalid_status != 0))
grep -q 'classification=invalid_xboard reason=identity_or_state_invalid' "$invalid_output"
grep -q 'NETWORK_DEBT_AUDIT_FAIL=invalid_xboard_networks:1' "$invalid_output"

echo 'NETWORK_DEBT_AUDIT_TEST=PASS'
