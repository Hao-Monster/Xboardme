#!/usr/bin/env bash
set -Eeuo pipefail
# No operation, path, interval, command or threshold is caller-selectable.
[[ $# == 0 ]] || { echo 'RESOURCE_DIAGNOSTIC_TRANSPORT_FAIL=unexpected_arguments' >&2; exit 1; }
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/verify-resource-diagnostic-request.sh"
: "${DEPLOY_HOST:?required}" "${DEPLOY_PORT:?required}" "${DEPLOY_USER:?required}"
[[ "$DEPLOY_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.:-]*$ &&
   "$DEPLOY_PORT" =~ ^[1-9][0-9]{0,4}$ && "$DEPLOY_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] &&
  ((DEPLOY_PORT <= 65535)) || { echo 'RESOURCE_DIAGNOSTIC_TRANSPORT_FAIL=invalid_target' >&2; exit 1; }
for helper in _rc_run _rc_jq _rc_resource_read _rc_resource_error _rc_resource_limits_valid _rc_resource_sample; do
  declare -F "$helper" >/dev/null || { echo 'RESOURCE_DIAGNOSTIC_TRANSPORT_FAIL=resource_helper_missing' >&2; exit 1; }
done
payload() {
  printf 'set -Eeuo pipefail\n'
  declare -f _rc_run _rc_jq _rc_resource_read _rc_resource_error _rc_resource_limits_valid _rc_resource_sample
  cat "$script_dir/diagnose-retention-resources.sh"
}
# Only the exact SHA and validated five-number JSON cross the host boundary.
# A clean environment prevents inherited RC state or path/test overrides.
remote_command="env -i PATH=/usr/bin:/bin LC_ALL=C EXPECTED_WORKFLOW_SHA='$EXPECTED_SHA' RESOURCE_DIAGNOSTIC_LIMITS='$RESOURCE_DIAGNOSTIC_LIMITS' timeout --signal=TERM --kill-after=2s 90s bash --noprofile --norc -se"
payload | timeout --signal=TERM --kill-after=2s 120s bash "$script_dir/ssh-with-password.sh" \
  -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 \
  -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" "$remote_command"
