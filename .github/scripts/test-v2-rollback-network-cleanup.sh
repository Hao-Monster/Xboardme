#!/usr/bin/env bash
set -Eeuo pipefail

if ((EUID != 0)); then
  command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1 || {
    echo 'V2_ROLLBACK_NETWORK_TEST_FAIL=root_execution_unavailable' >&2
    exit 1
  }
  exec sudo -n bash "$0"
fi

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_dir=$(mktemp -d)
cleanup() { rm -rf -- "$temporary_dir"; }
trap cleanup EXIT

# shellcheck source=release-state.sh
source "$repo_root/.github/scripts/release-state.sh"
# shellcheck source=v2-low-memory-common.sh
source "$repo_root/.github/scripts/v2-low-memory-common.sh"

operation_log="$temporary_dir/operations.log"
PROJECT_NAME=xboard-v2-1234-1

v2_compose() {
  printf 'compose %s\n' "$*" >> "$operation_log"
}

docker() {
  case "$1 $2" in
    'ps -aq'|'network ls')
      return 0
      ;;
    *)
      printf 'docker %s\n' "$*" >> "$operation_log"
      ;;
  esac
}

v2_cleanup_candidate_runtime
grep -Fxq 'compose down --remove-orphans --timeout 60' "$operation_log"
! grep -Fq -- '--volumes' "$operation_log"

PROJECT_NAME=legacy-production
: > "$operation_log"
if v2_cleanup_candidate_runtime 2>"$temporary_dir/invalid-project.log"; then
  echo 'V2_ROLLBACK_NETWORK_TEST_FAIL=invalid_project_was_accepted' >&2
  exit 1
fi
grep -Fxq 'V2_FAIL=invalid_candidate_project_name' "$temporary_dir/invalid-project.log"
! grep -Fq 'compose down' "$operation_log" || {
  echo 'V2_ROLLBACK_NETWORK_TEST_FAIL=invalid_project_triggered_compose' >&2
  exit 1
}

PROJECT_NAME=xboard-v2-1234-1
docker() {
  if [[ "$1 $2" == 'ps -aq' ]]; then
    printf '%s\n' candidate-container
    return 0
  fi
  return 0
}
if v2_cleanup_candidate_runtime 2>"$temporary_dir/residue.log"; then
  echo 'V2_ROLLBACK_NETWORK_TEST_FAIL=container_residue_was_accepted' >&2
  exit 1
fi
grep -Fxq 'V2_FAIL=candidate_containers_remain:candidate-container' "$temporary_dir/residue.log"

echo 'V2_ROLLBACK_NETWORK_CLEANUP=PASS project_guard=true no_volumes=true residue_check=true'
