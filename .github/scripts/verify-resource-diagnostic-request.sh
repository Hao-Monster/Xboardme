#!/usr/bin/env bash
set -Eeuo pipefail
# Runner-only admission. Sourcing this file also exports the validated numeric
# limits to the transport; no cleanup approval or production state is consulted.
resource_diagnostic_fail() { printf 'RESOURCE_DIAGNOSTIC_REQUEST_FAIL=%s\n' "$1" >&2; exit 1; }
[[ $# == 0 ]] || resource_diagnostic_fail unexpected_arguments
resource_diagnostic_script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ "${GITHUB_REPOSITORY:-}" == Hao-Monster/Xboardme &&
   "${GITHUB_REF:-}" == refs/heads/main && "${GITHUB_EVENT_NAME:-}" == workflow_dispatch &&
   "${EXPECTED_SHA:-}" =~ ^[a-f0-9]{40}$ &&
   "$EXPECTED_SHA" == "${GITHUB_SHA:-}" && "$EXPECTED_SHA" == "${GITHUB_WORKFLOW_SHA:-}" &&
   "${GITHUB_WORKFLOW_REF:-}" == Hao-Monster/Xboardme/.github/workflows/production-resource-diagnostic.yml@refs/heads/main ]] || resource_diagnostic_fail workflow_identity
[[ "${GITHUB_RUN_ATTEMPT:-}" == 1 ]] || resource_diagnostic_fail rerun_not_authorized
[[ "$(git rev-parse HEAD)" == "$EXPECTED_SHA" ]] || resource_diagnostic_fail checkout_mismatch
git diff --quiet --ignore-submodules=none && git diff --cached --quiet || resource_diagnostic_fail dirty_checkout
resource_diagnostic_status=$(git status --porcelain=v1 --untracked-files=all --ignore-submodules=none) || resource_diagnostic_fail checkout_inventory
[[ -z "$resource_diagnostic_status" ]] || resource_diagnostic_fail dirty_checkout
resource_diagnostic_submodules=$(git submodule status --recursive) || resource_diagnostic_fail submodule_inventory
! grep -Eq '^[-+U]' <<< "$resource_diagnostic_submodules" || resource_diagnostic_fail dirty_submodule
resource_diagnostic_remote_main=$(GIT_TERMINAL_PROMPT=0 timeout --signal=TERM --kill-after=2s 30s git ls-remote --exit-code origin refs/heads/main) || resource_diagnostic_fail remote_unavailable
[[ "$resource_diagnostic_remote_main" == "$EXPECTED_SHA"$'\trefs/heads/main' ]] || resource_diagnostic_fail stale_main

# This source-only module performs no work on source. Only its pure resource
# helpers are used here, and only six explicit functions may cross SSH.
source "$resource_diagnostic_script_dir/retention-continuity.sh"
resource_diagnostic_review="$resource_diagnostic_script_dir/../release/image-cleanup-review.json"
resource_diagnostic_committed_review=$(git show "$EXPECTED_SHA:.github/release/image-cleanup-review.json") || resource_diagnostic_fail committed_limits_unavailable
RESOURCE_DIAGNOSTIC_LIMITS=$(_rc_jq -ceS 'select(.schema_version == 3) | .monitoring.resources' "$resource_diagnostic_review") || resource_diagnostic_fail invalid_limits
resource_diagnostic_committed_limits=$(_rc_jq -ceS 'select(.schema_version == 3) | .monitoring.resources' <<< "$resource_diagnostic_committed_review") || resource_diagnostic_fail committed_limits_unavailable
[[ "$RESOURCE_DIAGNOSTIC_LIMITS" == "$resource_diagnostic_committed_limits" ]] || resource_diagnostic_fail uncommitted_limits
_rc_resource_limits_valid "$RESOURCE_DIAGNOSTIC_LIMITS" || resource_diagnostic_fail invalid_limits
_rc_jq -e '.max_cpu_busy_percent <= 85 and .max_cpu_iowait_percent <= 10 and
  .min_mem_available_kib >= 131072 and .max_io_full_avg10 <= 1 and .max_cpu_some_avg10 <= 10' \
  <<< "$RESOURCE_DIAGNOSTIC_LIMITS" >/dev/null || resource_diagnostic_fail relaxed_limits
# The exact five keys and number values are validated above. Refuse shell quote
# syntax independently before the JSON is embedded in the fixed remote command.
[[ "$RESOURCE_DIAGNOSTIC_LIMITS" != *"'"* && "$RESOURCE_DIAGNOSTIC_LIMITS" != *$'\n'* ]] || resource_diagnostic_fail unsafe_limits_encoding
printf 'RESOURCE_DIAGNOSTIC_LIMITS=%s\n' "$RESOURCE_DIAGNOSTIC_LIMITS"
printf 'RESOURCE_DIAGNOSTIC_REQUEST=PASS action=resource_diagnostic sha=%s\n' "$EXPECTED_SHA"
