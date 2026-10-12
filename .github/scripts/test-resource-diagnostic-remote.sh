#!/usr/bin/env bash
set -Eeuo pipefail
# Execute the real serialized function closure and host program in a private
# fixture. Only SSH, proc-reader command output and interval waits are replaced.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/scripts" "$test_root/release" "$test_root/bin" "$test_root/remote-bin"
for file in run-resource-diagnostic-remote.sh verify-resource-diagnostic-request.sh diagnose-retention-resources.sh retention-continuity.sh; do
  cp "$script_dir/$file" "$test_root/scripts/$file"
done
cat > "$test_root/bin/git" <<'GIT'
#!/bin/bash
set -Eeuo pipefail
case "$1" in
  rev-parse) printf '%s\n' "$GITHUB_SHA" ;;
  diff) exit 0 ;;
  status) printf '%s' "${TEST_STATUS:-}" ;;
  submodule) printf ' clean\n' ;;
  ls-remote) printf '%s\trefs/heads/main\n' "${TEST_REMOTE_HEAD:-$GITHUB_SHA}" ;;
  show) /bin/cat "$TEST_COMMITTED_REVIEW" ;;
  *) exit 97 ;;
esac
GIT
cat > "$test_root/scripts/ssh-with-password.sh" <<'SSH'
#!/bin/bash
set -Eeuo pipefail
printf 'LOCAL_SSH_BOUNDARY\n' >> "$TEST_SSH_LOG"
remote_command=${!#}
[[ "$remote_command" == "env -i PATH=/usr/bin:/bin LC_ALL=C EXPECTED_WORKFLOW_SHA='"* &&
   "$remote_command" == *"' RESOURCE_DIAGNOSTIC_LIMITS='"* &&
   "$remote_command" == *"' timeout --signal=TERM --kill-after=2s 90s bash --noprofile --norc -se" ]] || exit 97
printf '%s\n' "$remote_command" > "$TEST_HOST_CASE/remote-command"
/bin/cat > "$TEST_HOST_CASE/payload"
[[ "$TEST_RESOURCE_MODE" != ssh_failure ]] || exit 255
# Emulate only the receiving host's PATH/proc boundary. The captured production
# command has a clean fixed environment; no fixture settings are sent by it.
remote_command=${remote_command/PATH=\/usr\/bin:\/bin/PATH=\'$TEST_REMOTE_PATH\'}
remote_command=${remote_command/LC_ALL=C/LC_ALL=C TEST_HOST_CASE=\'$TEST_HOST_CASE\' TEST_RESOURCE_MODE=\'$TEST_RESOURCE_MODE\'}
/bin/bash -c "$remote_command" < "$TEST_HOST_CASE/payload"
SSH
cat > "$test_root/remote-bin/timeout" <<'TIMEOUT'
#!/bin/bash
set -Eeuo pipefail
printf '%s\n' "$*" >> "$TEST_HOST_CASE/timeouts"
if [[ "$*" == '--signal=TERM --kill-after=1s 12s sleep 10' ]]; then
  printf 'WAIT_10\n' >> "$TEST_HOST_CASE/waits"
  [[ "$TEST_RESOURCE_MODE" != wait_failure ]] || exit 124
  exit 0
fi
if [[ "$*" == '--signal=TERM --kill-after=2s 90s bash --noprofile --norc -se' && "$TEST_RESOURCE_MODE" == remote_timeout ]]; then exit 124; fi
exec /usr/bin/timeout "$@"
TIMEOUT
cat > "$test_root/remote-bin/head" <<'HEAD'
#!/bin/bash
set -Eeuo pipefail
case "$*" in
  '-n 1 /proc/stat'|'-c 65536 /proc/meminfo'|'-c 4096 /proc/pressure/io'|'-c 4096 /proc/pressure/cpu') ;;
  *) printf 'FORBIDDEN_PROC_READ\n' >> "$TEST_HOST_CASE/forbidden"; exit 97 ;;
esac
path=${!#}
sample=0
if [[ -f "$TEST_HOST_CASE/sample-count" ]]; then read -r sample < "$TEST_HOST_CASE/sample-count"; fi
if [[ "$path" == /proc/stat ]]; then
  ((sample += 1))
  printf '%s\n' "$sample" > "$TEST_HOST_CASE/sample-count"
  if [[ "$TEST_RESOURCE_MODE" == source_timeout ]]; then /bin/sleep 10; exit 98; fi
  if [[ "$TEST_RESOURCE_MODE" == mixed && "$sample" == 2 ]]; then printf 'cpu 1 broken\n'; exit 0; fi
  user=$((90 + sample * 10)); idle=$((800 + sample * 90))
  if [[ "$TEST_RESOURCE_MODE" == failed_baseline ]]; then
    case "$sample" in 1) user=100; idle=890 ;; 2) user=200; idle=890 ;; 3) user=210; idle=980 ;; esac
  fi
  printf 'cpu %s 0 0 %s 10 0 0 0 0 0\n' "$user" "$idle"
elif [[ "$path" == /proc/meminfo ]]; then
  if [[ "$TEST_RESOURCE_MODE" == mixed && "$sample" == 3 ]]; then exit 13; fi
  if [[ "$TEST_RESOURCE_MODE" == mixed && "$sample" == 1 ]] ||
     [[ "$TEST_RESOURCE_MODE" == failed_baseline && "$sample" == 2 ]]; then
    printf 'MemAvailable: 1 kB\n'
  else printf 'MemAvailable: 1048576 kB\n'; fi
elif [[ "$path" == /proc/pressure/io ]]; then printf 'some avg10=0.00 avg60=0.00 avg300=0.00 total=1\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=1\n'
else printf 'some avg10=0.00 avg60=0.00 avg300=0.00 total=1\n'; fi
HEAD
cat > "$test_root/remote-bin/jq" <<'JQ'
#!/bin/bash
set -Eeuo pipefail
if [[ "$TEST_RESOURCE_MODE" == comparison_failure && "${1:-}" == -nr ]]; then exit 7; fi
exec /usr/bin/jq "$@"
JQ
for tool in docker systemctl curl wget sqlite3 php redis-cli mysql psql flock mkdir mktemp rm mv chmod cat ssh ssh-keyscan; do
  cat > "$test_root/remote-bin/$tool" <<'FORBIDDEN'
#!/bin/bash
printf 'FORBIDDEN_COMMAND=%s\n' "${0##*/}" >> "$TEST_HOST_CASE/forbidden"
exit 97
FORBIDDEN
done
for tool in ssh ssh-keyscan docker curl systemctl; do
  cat > "$test_root/bin/$tool" <<'FORBIDDEN'
#!/bin/bash
printf 'FORBIDDEN_RUNNER_COMMAND=%s\n' "${0##*/}" >> "$TEST_HOST_CASE/forbidden"
exit 97
FORBIDDEN
done
chmod +x "$test_root/bin/"* "$test_root/remote-bin/"*
export PATH="$test_root/bin:$PATH"
export TEST_REMOTE_PATH="$test_root/remote-bin:/usr/bin:/bin" TEST_COMMITTED_REVIEW="$test_root/committed.json"
export GITHUB_REPOSITORY=Hao-Monster/Xboardme GITHUB_REF=refs/heads/main GITHUB_EVENT_NAME=workflow_dispatch
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa EXPECTED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export GITHUB_WORKFLOW_SHA=$GITHUB_SHA GITHUB_RUN_ATTEMPT=1
export GITHUB_WORKFLOW_REF=Hao-Monster/Xboardme/.github/workflows/production-resource-diagnostic.yml@refs/heads/main
export DEPLOY_HOST=example.invalid DEPLOY_PORT=22 DEPLOY_USER=fixture
export SSHPASS=fixture-secret-must-not-cross GHCR_TOKEN=fixture-registry-must-not-cross
export RC_CPU_TOTAL=999999999 RC_CPU_BUSY=99999999 RC_CPU_IOWAIT=999999
review="$test_root/release/image-cleanup-review.json"
jq -n '{schema_version:3,status:"blocked",approval_expires_at_epoch:1,monitoring:{resources:{
  max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,
  max_io_full_avg10:1,max_cpu_some_avg10:10}}}' > "$test_root/baseline.json"
new_case() {
  export TEST_RESOURCE_MODE=$1 TEST_HOST_CASE="$test_root/$1" TEST_SSH_LOG="$test_root/$1/ssh.log"
  mkdir -p "$TEST_HOST_CASE"
  : > "$TEST_HOST_CASE/forbidden"
  : > "$TEST_SSH_LOG"
  : > "$TEST_HOST_CASE/waits"
  printf '{"status":"HOLD","failure_reason":"existing_failure"}\n' > "$TEST_HOST_CASE/HOLD.json"
  original_hold=$(sha256sum < "$TEST_HOST_CASE/HOLD.json")
  cp "$test_root/baseline.json" "$review"
  cp "$review" "$TEST_COMMITTED_REVIEW"
  result="$TEST_HOST_CASE/result"
}
assert_untouched() {
  [[ "$(sha256sum < "$TEST_HOST_CASE/HOLD.json")" == "$original_hold" && ! -s "$TEST_HOST_CASE/forbidden" ]]
  ! grep -Eq 'fixture-secret-must-not-cross|fixture-registry-must-not-cross' "$result"
}
invoke() {
  local expected=$1 status
  shift
  if "$@" > "$result" 2>&1; then status=0; else status=$?; fi
  if [[ "$status" != "$expected" ]]; then cat "$result" >&2; echo "RESOURCE_TRANSPORT_TEST_FAIL=unexpected_exit:$status:$expected" >&2; exit 1; fi
  assert_untouched
}
summary() { sed -n 's/^RESOURCE_DIAGNOSTIC_SUMMARY=//p' "$result"; }
samples() { sed -n 's/^RETENTION_RESOURCE_SAMPLE=//p' "$result" | jq -s .; }
transport=(bash "$test_root/scripts/run-resource-diagnostic-remote.sh")
cases=0
new_case pass
invoke 0 "${transport[@]}"
[[ $(wc -l < "$TEST_SSH_LOG") == 1 && $(wc -l < "$TEST_HOST_CASE/waits") == 2 ]]
summary | jq -e '.result=="PASS" and .attempted==3 and .passed==3 and .failed==0 and .reason_counts=={} and .continuation_authorized==false and .lifecycle_gate=="not_accessed"' >/dev/null
samples | jq -e 'length==3 and .[0].phase=="initial" and .[0].cpu_busy_percent==null and .[1].phase=="delta" and .[1].cpu_busy_percent==10 and .[2].cpu_busy_percent==10' >/dev/null
expected_functions=$(printf '%s\n' _rc_run _rc_jq _rc_resource_read _rc_resource_error _rc_resource_limits_valid _rc_resource_sample | sort)
actual_functions=$(awk '/^_rc_[a-z_]+ \(\)/ {print $1}' "$TEST_HOST_CASE/payload" | sort)
[[ "$actual_functions" == "$expected_functions" ]]
! grep -Eq 'retention_continuity_|_rc_paths|_rc_gate|release_state_|RETENTION_REVIEW|image-cleanup|docker |systemctl |curl |\.codex-v2-release|SSHPASS|GHCR_TOKEN' "$TEST_HOST_CASE/payload"
! grep -Eq 'TEST_|SSHPASS|GHCR_TOKEN|fixture-secret|fixture-registry' "$TEST_HOST_CASE/remote-command"
grep -Fxq -- '--signal=TERM --kill-after=2s 90s bash --noprofile --norc -se' "$TEST_HOST_CASE/timeouts"
((cases += 1))

new_case mixed
invoke 1 "${transport[@]}"
summary | jq -e '.attempted==3 and .passed==0 and .failed==3 and .reason_counts=={threshold_exceeded:1,invalid_cpu_stat:1,proc_read_failed:1}' >/dev/null
grep -Fxq 'RETENTION_RESOURCE_ERROR=proc_read_failed source=memory exit_code=13' "$result"
[[ $(wc -l < "$TEST_HOST_CASE/waits") == 2 ]]
((cases += 1))

new_case failed_baseline
invoke 1 "${transport[@]}"
summary | jq -e '.attempted==3 and .passed==2 and .failed==1 and .reason_counts.threshold_exceeded==1' >/dev/null
samples | jq -e 'length==3 and .[1].result=="FAIL" and .[2].phase=="delta" and .[2].cpu_busy_percent==55' >/dev/null
((cases += 1))

# This one boundary uses an actual GNU timeout, not a synthetic return code.
new_case source_timeout
invoke 1 "${transport[@]}"
summary | jq -e '.attempted==3 and .failed==3 and .reason_counts.proc_read_failed==3' >/dev/null
[[ $(grep -Fxc 'RETENTION_RESOURCE_ERROR=proc_read_failed source=stat exit_code=124' "$result") == 3 ]]
((cases += 1))

new_case comparison_failure
invoke 1 "${transport[@]}"
summary | jq -e '.attempted==3 and .failed==3 and .reason_counts.comparison_command_failed==3' >/dev/null
[[ $(grep -Fxc 'RETENTION_RESOURCE_ERROR=comparison_command_failed source=comparison exit_code=7' "$result") == 3 ]]
((cases += 1))

new_case wait_failure
invoke 1 "${transport[@]}"
summary | jq -e '.attempted==1 and .passed==1 and .failed==0 and .wait_failed==1 and .reason_counts.interval_wait_failed==1 and .result=="FAIL"' >/dev/null
[[ $(wc -l < "$TEST_HOST_CASE/waits") == 1 ]]
((cases += 1))

for boundary in ssh_failure remote_timeout; do
  new_case "$boundary"
  status=255
  [[ "$boundary" != remote_timeout ]] || status=124
  invoke "$status" "${transport[@]}"
  [[ $(wc -l < "$TEST_SSH_LOG") == 1 ]]
  ! grep -q '^RESOURCE_DIAGNOSTIC_START=' "$result"
  ((cases += 1))
done
for admission in stale_main wrong_workflow dirty_checkout invalid_host invalid_port invalid_user extra_argument relaxed_limits; do
  new_case "$admission"
  case "$admission" in
    stale_main) invoke 1 env TEST_REMOTE_HEAD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb "${transport[@]}" ;;
    wrong_workflow) invoke 1 env GITHUB_WORKFLOW_REF=other/workflow "${transport[@]}" ;;
    dirty_checkout) invoke 1 env 'TEST_STATUS=?? untracked' "${transport[@]}" ;;
    invalid_host) invoke 1 env "DEPLOY_HOST=example.invalid'bad" "${transport[@]}" ;;
    invalid_port) invoke 1 env DEPLOY_PORT=65536 "${transport[@]}" ;;
    invalid_user) invoke 1 env 'DEPLOY_USER=bad user' "${transport[@]}" ;;
    extra_argument) invoke 1 "${transport[@]}" cleanup ;;
    relaxed_limits)
      jq '.monitoring.resources.max_cpu_busy_percent=86' "$test_root/baseline.json" > "$review"
      cp "$review" "$TEST_COMMITTED_REVIEW"
      invoke 1 "${transport[@]}" ;;
  esac
  [[ ! -s "$TEST_SSH_LOG" && ! -e "$TEST_HOST_CASE/payload" ]]
  ((cases += 1))
done
printf 'RESOURCE_DIAGNOSTIC_REMOTE_TEST=PASS cases=%s real_serialized_functions=true real_resource_timeout=true fixture_proc=true fixture_intervals=true production_connections=0 gate_unchanged=true\n' "$cases"
