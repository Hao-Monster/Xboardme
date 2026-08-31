#!/usr/bin/env bash
# Functions and state variables in this harness are consumed indirectly when
# the production resolver is sourced below.
# shellcheck disable=SC2034,SC2317
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT

release_id=333-1
release_sha=$(printf 'a%.0s' {1..40})
workdir="$test_root/workdir"
release_dir="$workdir/.codex-v2-release/$release_id"
state_file="$release_dir/state.json"
mkdir -p "$release_dir"
chmod 700 "$release_dir"

write_legacy_compatible_state() {
  jq -n \
    --arg release_id "$release_id" \
    --arg release_sha "$release_sha" \
    --arg project_name "xboard-v2-$release_id" \
    --arg active_port '7002' \
    --arg workdir "$workdir" \
    --arg release_dir "$release_dir" \
    '{
      schema_version: 2,
      v2_schema_version: "1",
      release_id: $release_id,
      release_sha: $release_sha,
      project_name: $project_name,
      active_port: $active_port,
      traffic_state: "finalized",
      workdir: $workdir,
      release_dir: $release_dir
    }' > "$state_file"
  chmod 600 "$state_file"
}

run_resolver() (
  set -Eeuo pipefail
  RELEASE_ID=$release_id
  EXPECTED_RELEASE_SHA=${1:-$release_sha}
  V2_RELEASE_STATE_SCHEMA=1

  release_state_open() { printf '%s\n' "$state_file"; }
  release_state_get() {
    [[ $2 != database_backup && $2 != database_backup_sha256 ]] || {
      echo "unexpected_new_state_key:$2" >&2
      return 1
    }
    jq -er --arg key "$2" '.[$key]' "$1"
  }
  v2_fail() { echo "V2_FAIL=$1" >&2; return 1; }
  v2_require_tools() { :; }
  v2_validate_release_id() { [[ "$RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]]; }
  v2_find_workdir() { V2_WORKDIR=$workdir; }
  v2_acquire_lock() { :; }
  docker() {
    case "${1:-}" in
      ps)
        if [[ "$*" == *'com.docker.compose.service=web'* ]]; then
          printf 'web-id\n'
        else
          printf 'edge-id\nweb-id\n'
        fi
        ;;
      port)
        [[ ${2:-} == edge-id && ${3:-} == 7001/tcp ]] || return 1
        printf '127.0.0.1:7002\n'
        ;;
      inspect)
        [[ ${2:-} == -f ]]
        case "${3:-}:${4:-}" in
          *com.docker.compose.service*:edge-id) printf 'edge\n' ;;
          *'.Image'*:web-id) printf 'web-image-id\n' ;;
          *) return 1 ;;
        esac
        ;;
      image)
        [[ ${2:-} == inspect && ${3:-} == -f && ${5:-} == web-image-id ]]
        printf '%s\n' "$release_sha"
        ;;
      *) return 1 ;;
    esac
  }

  # shellcheck source=resolve-xboard-v2-port.sh
  source "$script_dir/resolve-xboard-v2-port.sh"
)

write_legacy_compatible_state
[[ "$(run_resolver)" == 7002 ]]

set +e
run_resolver "$(printf 'b%.0s' {1..40})" >"$test_root/mismatch.out" 2>&1
mismatch_status=$?
set -e
((mismatch_status != 0))
grep -Fxq 'V2_FAIL=release_sha_mismatch' "$test_root/mismatch.out"

jq '.project_name = "xboard-v2-untrusted"' "$state_file" > "$state_file.tmp"
mv -- "$state_file.tmp" "$state_file"
chmod 600 "$state_file"
set +e
run_resolver >"$test_root/project.out" 2>&1
project_status=$?
set -e
((project_status != 0))
grep -Fxq 'V2_FAIL=project_identity_mismatch' "$test_root/project.out"

echo 'V2_PORT_RESOLVER_TEST=PASS legacy_state=true identity_guards=true'
