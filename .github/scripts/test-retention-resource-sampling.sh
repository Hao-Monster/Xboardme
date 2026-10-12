#!/usr/bin/env bash
set -Eeuo pipefail
# Real sampling/parser/comparison code with only the /proc reader substituted.
# The final case restores that reader and samples this Linux host read-only.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-continuity.sh"
fail() { printf 'RETENTION_RESOURCE_TEST=FAIL case=%s reason=%s\n' "${case_name:-setup}" "$1" >&2; exit 1; }
for helper in _rc_resource_read _rc_resource_limits_valid _rc_resource_sample _rc_resources; do
  declare -F "$helper" >/dev/null || fail "missing_function:$helper"
done
for tool in jq timeout head sed realpath grep; do command -v "$tool" >/dev/null || fail "missing_tool:$tool"; done

test_root=$(mktemp -d /tmp/xboard-resource-tests.XXXXXX)
[[ "$test_root" == /tmp/xboard-resource-tests.* && ! -L "$test_root" &&
   "$(realpath -e -- "$test_root")" == "$test_root" ]] || fail unsafe_test_root
cleanup() {
  [[ "$test_root" == /tmp/xboard-resource-tests.* && -d "$test_root" && ! -L "$test_root" &&
     "$(realpath -e -- "$test_root")" == "$test_root" ]] || return 1
  rm -rf -- "$test_root"
}
trap cleanup EXIT
cases=0
output="$test_root/sample.out"
errors="$test_root/sample.err"
calls="$test_root/reader-calls"
limits='{"max_cpu_busy_percent":85,"max_cpu_iowait_percent":10,"min_mem_available_kib":131072,"max_io_full_avg10":1,"max_cpu_some_avg10":10}'
legacy_limits='{"max_cpu_busy_percent":95,"max_cpu_iowait_percent":20,"min_mem_available_kib":65536,"max_io_full_avg10":5,"max_cpu_some_avg10":20}'

# Save source-owned boundaries, not a copied implementation of the sampler.
eval "$(declare -f _rc_resource_read | sed '1s/_rc_resource_read/_test_real_resource_read/')"
eval "$(declare -f _rc_jq | sed '1s/_rc_jq/_test_real_resource_jq/')"
eval "$(declare -f _rc_run | sed '1s/_rc_run/_test_real_resource_run/')"
_rc_run() {
  case "$1" in
    jq|head) _test_real_resource_run "$@" ;;
    *) fail "forbidden_external_command:$1" ;;
  esac
}
_rc_jq() {
  [[ ! -f "$test_root/comparison-timeout" ]] || return 124
  _test_real_resource_jq "$@"
}
_rc_resource_read() {
  printf '%s\n' "$1" >> "$calls"
  [[ "$1" != "${read_failure_source:-}" ]] || return "$read_failure_code"
  case "$1" in
    stat) printf '%s\n' "$stat_text" ;;
    memory) printf '%s\n' "$memory_text" ;;
    io) printf '%s\n' "$io_text" ;;
    cpu)
      printf '%s\n' "$cpu_text"
      [[ "${comparison_timeout:-false}" != true ]] || : > "$test_root/comparison-timeout"
      ;;
    *) fail unknown_reader_source ;;
  esac
}
reset_fixture() {
  case_name=$1
  RC_CPU_TOTAL='' RC_CPU_BUSY='' RC_CPU_IOWAIT=''
  RC_CPU_BUSY_PERCENT='' RC_CPU_IOWAIT_PERCENT=''
  RC_MEMORY_AVAILABLE_KIB='' RC_IO_FULL_AVG10='' RC_CPU_SOME_AVG10=''
  RC_RESOURCE_REASON=''
  stat_text='cpu 100 0 100 700 100 0 0 0 0 0'
  memory_text=$'MemTotal: 524288 kB\nMemAvailable: 131072 kB\nMemFree: 10000 kB'
  io_text=$'some avg10=2.00 avg60=0.10 avg300=0.01 total=10\nfull avg10=1.00 avg60=0.10 avg300=0.01 total=10'
  cpu_text=$'some avg10=10.00 avg60=1.00 avg300=0.10 total=10\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0'
  read_failure_source='' read_failure_code=1 comparison_timeout=false
  : > "$calls"
  rm -f -- "$test_root/comparison-timeout"
}
sample() {
  sample_rc=0
  # No pipeline, command substitution or subshell: CPU state must survive.
  if _rc_resource_sample "${1:-$limits}" > "$output" 2> "$errors"; then :; else sample_rc=$?; fi
}
sample_json() {
  jq -Rse '
    split("\n") | map(select(startswith("RETENTION_RESOURCE_SAMPLE="))) |
    if length!=1 then error("expected exactly one sample")
    else .[0] | ltrimstr("RETENTION_RESOURCE_SAMPLE=") | fromjson end
  ' "$output"
}
assert_sample() {
  local expected_rc=$1 expected_result=$2 phase=$3 violations=$4
  [[ "$sample_rc" == "$expected_rc" ]] || fail "exit:$sample_rc:expected:$expected_rc"
  sample_json | jq -e --arg result "$expected_result" --arg phase "$phase" \
    --argjson limits "$limits" --argjson violations "$violations" '
      .schema_version==1 and .result==$result and .phase==$phase and
      .thresholds==$limits and (.violations|sort)==($violations|sort) and
      (.mem_available_kib|type=="number") and (.io_full_avg10|type=="number") and
      (.cpu_some_avg10|type=="number") and
      (if $phase=="initial" then .cpu_busy_percent==null and .cpu_iowait_percent==null
       else (.cpu_busy_percent|type=="number") and (.cpu_iowait_percent|type=="number") end)
    ' >/dev/null || fail sample_contract
  [[ "$expected_rc" != 1 || "$RC_RESOURCE_REASON" == threshold_exceeded ]] || fail threshold_reason
  [[ "$expected_rc" != 0 || -z "$RC_RESOURCE_REASON" ]] || fail successful_sample_retained_failure_reason
}
assert_error() {
  local reason=$1 source=$2 code=${3:-}
  [[ "$sample_rc" == 1 ]] || fail "error_exit:$sample_rc"
  [[ "$RC_RESOURCE_REASON" == "$reason" ]] || fail "resource_reason:$RC_RESOURCE_REASON:expected:$reason"
  jq -Rse --arg reason "$reason" --arg source "$source" --arg code "$code" '
    split("\n") | map(select(startswith("RETENTION_RESOURCE_ERROR="))) |
    length>=1 and any(.[]; test("^RETENTION_RESOURCE_ERROR="+$reason+" source="+$source+" exit_code=[0-9]+$") and
      ($code=="" or endswith(" exit_code="+$code)))
  ' "$output" "$errors" >/dev/null || fail missing_error_evidence
  ! grep -q '^RETENTION_RESOURCE_SAMPLE=' "$output" || fail malformed_source_reported_as_sample
}
pass_case() { ((cases+=1)); }

case_name=limits_validation
_rc_resource_limits_valid "$limits" || fail passive_limits_rejected
_rc_resource_limits_valid "$legacy_limits" || fail legacy_limits_rejected
pass_case
for change in \
  'del(.max_cpu_busy_percent)' 'del(.max_cpu_iowait_percent)' 'del(.min_mem_available_kib)' \
  'del(.max_io_full_avg10)' 'del(.max_cpu_some_avg10)' '.extra=1' \
  '.max_cpu_busy_percent=96' '.max_cpu_busy_percent=0' '.max_cpu_busy_percent=84.5' \
  '.max_cpu_busy_percent="85"' '.max_cpu_iowait_percent=21' '.max_cpu_iowait_percent=-1' \
  '.min_mem_available_kib=65535' '.min_mem_available_kib=65536.5' \
  '.max_io_full_avg10=5.01' '.max_io_full_avg10=0' '.max_cpu_some_avg10=20.01' \
  '.max_cpu_some_avg10=null' '[]' 'null' 'true'; do
  reset_fixture "invalid-limits:$change"
  invalid_limits=$(jq -c "$change" <<< "$limits")
  if _rc_resource_limits_valid "$invalid_limits"; then fail invalid_limits_accepted; fi
  sample "$invalid_limits"
  assert_error invalid_limits limits
  [[ -z "$RC_CPU_TOTAL" && ! -s "$calls" ]] || fail invalid_limits_read_or_advanced_state
  pass_case
done
reset_fixture invalid-json-limits
sample '{'
assert_error invalid_limits limits
pass_case

reset_fixture initial-equality
sample
assert_sample 0 PASS initial '[]'
sample_json | jq -e '.mem_available_kib==131072 and .io_full_avg10==1 and .cpu_some_avg10==10' >/dev/null || fail initial_values
[[ "$RC_CPU_TOTAL" == 1000 && "$RC_CPU_BUSY" == 200 && "$RC_CPU_IOWAIT" == 100 ]] || fail initial_counters
[[ -z "$RC_CPU_BUSY_PERCENT" && -z "$RC_CPU_IOWAIT_PERCENT" ]] || fail initial_percent_not_unknown
pass_case

case_name=delta-equality
stat_text='cpu 185 0 100 705 110 0 0 0 0 0'
sample
assert_sample 0 PASS delta '[]'
sample_json | jq -e '.cpu_busy_percent==85 and .cpu_iowait_percent==10' >/dev/null || fail delta_values
[[ "$RC_CPU_TOTAL" == 1100 && "$RC_CPU_BUSY" == 285 && "$RC_CPU_IOWAIT" == 110 ]] || fail delta_counters
pass_case

for metric in memory io cpu; do
  reset_fixture "threshold-initial:$metric"
  case "$metric" in
    memory) memory_text='MemAvailable: 131071 kB'; violation=min_mem_available_kib ;;
    io) io_text='full avg10=1.01 avg60=0.00 avg300=0.00 total=0'; violation=max_io_full_avg10 ;;
    cpu) cpu_text='some avg10=10.01 avg60=0.00 avg300=0.00 total=0'; violation=max_cpu_some_avg10 ;;
  esac
  sample
  assert_sample 1 FAIL initial "[\"$violation\"]"
  [[ -z "$RC_CPU_TOTAL" && -z "$RC_CPU_BUSY" && -z "$RC_CPU_IOWAIT" ]] || fail failed_initial_advanced_counters
  pass_case
done

for metric in busy iowait; do
  reset_fixture "threshold-delta:$metric"
  sample
  assert_sample 0 PASS initial '[]'
  case "$metric" in
    busy) stat_text='cpu 186 0 100 704 110 0 0 0 0 0'; violation=max_cpu_busy_percent ;;
    iowait) stat_text='cpu 184 0 100 705 111 0 0 0 0 0'; violation=max_cpu_iowait_percent ;;
  esac
  sample
  assert_sample 1 FAIL delta "[\"$violation\"]"
  [[ "$RC_CPU_TOTAL" == 1000 && "$RC_CPU_BUSY" == 200 && "$RC_CPU_IOWAIT" == 100 ]] || fail failed_delta_advanced_counters
  stat_text='cpu 185 0 100 705 110 0 0 0 0 0'
  sample
  assert_sample 0 PASS delta '[]'
  sample_json | jq -e '.cpu_busy_percent==85 and .cpu_iowait_percent==10' >/dev/null || fail failed_sample_poisoned_next_delta
  pass_case
done

for source in stat memory io cpu; do
  for code in 1 124; do
    reset_fixture "read-failure:$source:$code"
    read_failure_source=$source read_failure_code=$code
    sample
    assert_error proc_read_failed "$source" "$code"
    [[ -z "$RC_CPU_TOTAL" ]] || fail read_failure_advanced_state
    pass_case
  done
done

for malformed in '' 'cpu' 'cpu 100 0 100 700 100 0 0' 'cpu 100 0 nope 700 100 0 0 0' \
  'cpu -1 0 100 700 100 0 0 0' 'cpu 1.5 0 100 700 100 0 0 0' 'cpu0 100 0 100 700 100 0 0 0'; do
  reset_fixture malformed-stat
  stat_text=$malformed
  sample
  assert_error invalid_cpu_stat stat
  [[ -z "$RC_CPU_TOTAL" ]] || fail malformed_stat_advanced_state
  pass_case
done
for malformed in '' 'MemFree: 131072 kB' 'MemAvailable:' 'MemAvailable: nope kB' \
  'MemAvailable: -1 kB' 'MemAvailable: 131072.5 kB' 'MemAvailable: 131072 MB' \
  'MemAvailable: 131072 kB unexpected' $'MemAvailable: 131072 kB\nMemAvailable: 131072 kB'; do
  reset_fixture malformed-memory
  memory_text=$malformed
  sample
  assert_error invalid_mem_available memory
  pass_case
done
for source in io cpu; do
  for value in missing empty nonnumeric negative integer missing-key duplicate; do
    reset_fixture "malformed-pressure:$source:$value"
    [[ "$source" == io ]] && label=full || label=some
    case "$value" in
      missing) pressure='unrelated avg10=0.00 avg60=0.00 avg300=0.00 total=0' ;;
      empty) pressure="$label avg10= avg60=0.00 avg300=0.00 total=0" ;;
      nonnumeric) pressure="$label avg10=nope avg60=0.00 avg300=0.00 total=0" ;;
      negative) pressure="$label avg10=-1.00 avg60=0.00 avg300=0.00 total=0" ;;
      integer) pressure="$label avg10=1 avg60=0.00 avg300=0.00 total=0" ;;
      missing-key) pressure="$label 1.00 avg60=0.00 avg300=0.00 total=0" ;;
      duplicate) pressure="$label avg10=1.00 avg60=0.00 avg300=0.00 total=0"$'\n'"$label avg10=1.00 avg60=0.00 avg300=0.00 total=0" ;;
    esac
    if [[ "$source" == io ]]; then io_text=$pressure; else cpu_text=$pressure; fi
    sample
    assert_error "invalid_${source}_pressure" "$source"
    pass_case
  done
done

for change in total-stalled total-regressed busy-regressed wait-regressed; do
  reset_fixture "counter:$change"
  sample
  assert_sample 0 PASS initial '[]'
  case "$change" in
    total-stalled) : ;;
    total-regressed) stat_text='cpu 100 0 100 699 100 0 0 0' ;;
    busy-regressed) stat_text='cpu 90 0 100 810 100 0 0 0' ;;
    wait-regressed) stat_text='cpu 150 0 100 761 99 0 0 0' ;;
  esac
  sample
  assert_error cpu_counter_regressed stat
  [[ "$RC_CPU_TOTAL" == 1000 && "$RC_CPU_BUSY" == 200 && "$RC_CPU_IOWAIT" == 100 ]] || fail counter_error_advanced_state
  pass_case
done

reset_fixture comparison-timeout
comparison_timeout=true
sample
assert_error comparison_command_failed comparison 124
[[ -z "$RC_CPU_TOTAL" ]] || fail comparison_failure_advanced_state
pass_case

reset_fixture no-stale-values
sample
assert_sample 0 PASS initial '[]'
stat_text='cpu 185 0 100 705 110 0 0 0 0 0'
sample
assert_sample 0 PASS delta '[]'
memory_text='MemAvailable: invalid kB'
sample
assert_error invalid_mem_available memory
[[ "$RC_CPU_TOTAL" == 1100 && "$RC_CPU_BUSY" == 285 && "$RC_CPU_IOWAIT" == 110 ]] || fail parse_failure_advanced_state
[[ -z "$RC_CPU_BUSY_PERCENT" && -z "$RC_CPU_IOWAIT_PERCENT" &&
   -z "$RC_MEMORY_AVAILABLE_KIB" && -z "$RC_IO_FULL_AVG10" && -z "$RC_CPU_SOME_AVG10" ]] || fail failed_sample_retained_metrics
pass_case

reset_fixture real-resources-wrapper
RC_REVIEW="$test_root/review.json"
jq -n --argjson limits "$limits" '{monitoring:{resources:$limits}}' > "$RC_REVIEW"
sample_rc=0
if _rc_resources > "$output" 2> "$errors"; then :; else sample_rc=$?; fi
assert_sample 0 PASS initial '[]'
[[ "$RC_CPU_TOTAL" == 1000 ]] || fail wrapper_did_not_share_sampler
pass_case

case_name=wrapper-invalid-review-no-stale-values
printf '{\n' > "$RC_REVIEW"
sample_rc=0
if _rc_resources > "$output" 2> "$errors"; then :; else sample_rc=$?; fi
assert_error invalid_limits limits
[[ "$RC_CPU_TOTAL" == 1000 && "$RC_CPU_BUSY" == 200 && "$RC_CPU_IOWAIT" == 100 ]] || fail wrapper_error_advanced_state
[[ -z "$RC_CPU_BUSY_PERCENT" && -z "$RC_CPU_IOWAIT_PERCENT" &&
   -z "$RC_MEMORY_AVAILABLE_KIB" && -z "$RC_IO_FULL_AVG10" && -z "$RC_CPU_SOME_AVG10" ]] || fail wrapper_error_retained_metrics
pass_case

reset_fixture real-linux-proc
_rc_resource_read() { _test_real_resource_read "$@"; }
sample
[[ "$sample_rc" == 0 || ( "$sample_rc" == 1 && "$RC_RESOURCE_REASON" == threshold_exceeded ) ]] || fail real_proc_collection_or_parse_failed
sample_json | jq -e --argjson rc "$sample_rc" --argjson limits "$limits" '
  .schema_version==1 and .phase=="initial" and .thresholds==$limits and
  (.mem_available_kib|type=="number" and .>=0) and
  (.io_full_avg10|type=="number" and .>=0) and (.cpu_some_avg10|type=="number" and .>=0) and
  .cpu_busy_percent==null and .cpu_iowait_percent==null and
  (if $rc==0 then .result=="PASS" and .violations==[]
   else .result=="FAIL" and (.violations|length>0) end)
' >/dev/null || fail real_proc_result_contract
printf 'RETENTION_RESOURCE_REAL_PROC=PARSED threshold_result=%s production_evidence=false\n' \
  "$(sample_json | jq -r .result)"
pass_case

printf 'RETENTION_RESOURCE_TEST=PASS cases=%s real_proc_samples=1 docker_connections=0 production_connections=0\n' "$cases"
