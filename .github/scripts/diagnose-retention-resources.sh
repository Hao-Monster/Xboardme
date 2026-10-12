#!/usr/bin/env bash
set -Eeuo pipefail
# Streamed after the six pure resource functions, never installed on the host.
# Fixed proc readers only: no service/container/database or lifecycle gate access.
[[ $# == 0 && "${EXPECTED_WORKFLOW_SHA:-}" =~ ^[a-f0-9]{40}$ ]] || {
  echo 'RESOURCE_DIAGNOSTIC_FAIL=invalid_invocation' >&2; exit 1;
}
for helper in _rc_run _rc_jq _rc_resource_read _rc_resource_error _rc_resource_limits_valid _rc_resource_sample; do
  declare -F "$helper" >/dev/null || { echo 'RESOURCE_DIAGNOSTIC_FAIL=resource_helper_missing' >&2; exit 1; }
done
_rc_resource_limits_valid "${RESOURCE_DIAGNOSTIC_LIMITS:-}" &&
  _rc_jq -e '.max_cpu_busy_percent <= 85 and .max_cpu_iowait_percent <= 10 and
    .min_mem_available_kib >= 131072 and .max_io_full_avg10 <= 1 and .max_cpu_some_avg10 <= 10' \
    <<< "$RESOURCE_DIAGNOSTIC_LIMITS" >/dev/null || { echo 'RESOURCE_DIAGNOSTIC_FAIL=invalid_limits' >&2; exit 1; }

RC_CPU_TOTAL='' RC_CPU_BUSY='' RC_CPU_IOWAIT=''
diagnostic_attempted=0 diagnostic_passed=0 diagnostic_failed=0 diagnostic_wait_failed=0
declare -A diagnostic_reason_counts=()
printf 'RESOURCE_DIAGNOSTIC_START=1 sha=%s samples=3 interval_seconds=10 continuation_authorized=false\n' "$EXPECTED_WORKFLOW_SHA"
for diagnostic_index in 1 2 3; do
  ((diagnostic_attempted += 1))
  # Direct, same-shell calls preserve the successful CPU baseline between samples.
  if _rc_resource_sample "$RESOURCE_DIAGNOSTIC_LIMITS"; then
    ((diagnostic_passed += 1))
    printf 'RESOURCE_DIAGNOSTIC_SAMPLE index=%s result=PASS\n' "$diagnostic_index"
  else
    ((diagnostic_failed += 1))
    diagnostic_reason=${RC_RESOURCE_REASON:-unknown_resource_error}
    [[ "$diagnostic_reason" =~ ^[a-z][a-z0-9_]{0,95}$ ]] || diagnostic_reason=unknown_resource_error
    diagnostic_reason_counts["$diagnostic_reason"]=$(( ${diagnostic_reason_counts[$diagnostic_reason]:-0} + 1 ))
    printf 'RESOURCE_DIAGNOSTIC_SAMPLE index=%s result=FAIL reason=%s\n' "$diagnostic_index" "$diagnostic_reason"
  fi
  if ((diagnostic_index < 3)); then
    # The fixed interval is not a resource-read command; it has its own 12s cap.
    # A failed wait stops sampling rather than creating a tight retry loop.
    if ! timeout --signal=TERM --kill-after=1s 12s sleep 10; then
      diagnostic_wait_failed=1
      diagnostic_reason_counts[interval_wait_failed]=1
      break
    fi
  fi
done

diagnostic_result=FAIL
if ((diagnostic_attempted == 3 && diagnostic_passed == 3 && diagnostic_wait_failed == 0)); then diagnostic_result=PASS; fi
# Builtin-only summary still records every category if the jq comparison failed.
printf 'RESOURCE_DIAGNOSTIC_SUMMARY={"schema_version":1,"result":"%s","attempted":%s,"passed":%s,"failed":%s,"wait_failed":%s,"reason_counts":{' \
  "$diagnostic_result" "$diagnostic_attempted" "$diagnostic_passed" "$diagnostic_failed" "$diagnostic_wait_failed"
diagnostic_separator=''
for diagnostic_reason in "${!diagnostic_reason_counts[@]}"; do
  printf '%s"%s":%s' "$diagnostic_separator" "$diagnostic_reason" "${diagnostic_reason_counts[$diagnostic_reason]}"
  diagnostic_separator=,
done
printf '},"continuation_authorized":false,"connection_continuity":"unverified","lifecycle_gate":"not_accessed"}\n'
printf 'RESOURCE_DIAGNOSTIC=%s action=resource_diagnostic\n' "$diagnostic_result"
[[ "$diagnostic_result" == PASS ]]
