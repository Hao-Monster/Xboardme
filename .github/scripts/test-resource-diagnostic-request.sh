#!/usr/bin/env bash
set -Eeuo pipefail
# Offline admission tests. Git and every production boundary are local fixtures.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/bin" "$test_root/scripts" "$test_root/release"
cp "$script_dir/verify-resource-diagnostic-request.sh" "$script_dir/retention-continuity.sh" "$test_root/scripts/"
cat > "$test_root/bin/git" <<'GIT'
#!/usr/bin/env bash
set -Eeuo pipefail
case "$1" in
  rev-parse) printf '%s\n' "${TEST_HEAD:-$GITHUB_SHA}" ;;
  diff)
    if [[ "${2:-}" == --cached ]]; then [[ "${TEST_STAGED:-false}" == false ]]
    else [[ "${TEST_UNSTAGED:-false}" == false ]]; fi ;;
  status) [[ ${TEST_STATUS_FAILED:-false} == false ]] || exit 9; printf '%s' "${TEST_STATUS:-}" ;;
  submodule) [[ ${TEST_SUBMODULE_FAILED:-false} == false ]] || exit 9; printf '%s\n' "${TEST_SUBMODULE:- clean}" ;;
  ls-remote)
    [[ ${TEST_REMOTE_FAILED:-false} == false ]] || exit 9
    printf '%s\trefs/heads/main\n' "${TEST_REMOTE:-$GITHUB_SHA}"
    [[ ${TEST_REMOTE_DUPLICATE:-false} == false ]] || printf '%s\trefs/heads/main\n' "$GITHUB_SHA" ;;
  show) [[ ${TEST_SHOW_FAILED:-false} == false ]] || exit 9; cat "$TEST_COMMITTED_REVIEW" ;;
  *) exit 97 ;;
esac
GIT
for tool in ssh ssh-keyscan docker curl wget systemctl sqlite3 php redis-cli mysql psql flock; do
  cat > "$test_root/bin/$tool" <<'FORBIDDEN'
#!/usr/bin/env bash
printf 'FORBIDDEN_BOUNDARY=%s\n' "${0##*/}" >> "$TEST_FORBIDDEN_LOG"
exit 97
FORBIDDEN
done
chmod +x "$test_root/bin/"*
export PATH="$test_root/bin:$PATH"
export TEST_FORBIDDEN_LOG="$test_root/forbidden.log" TEST_COMMITTED_REVIEW="$test_root/committed.json"
: > "$TEST_FORBIDDEN_LOG"
export GITHUB_REPOSITORY=Hao-Monster/Xboardme GITHUB_REF=refs/heads/main GITHUB_EVENT_NAME=workflow_dispatch
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa EXPECTED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export GITHUB_WORKFLOW_SHA=$GITHUB_SHA GITHUB_RUN_ATTEMPT=1
export GITHUB_WORKFLOW_REF=Hao-Monster/Xboardme/.github/workflows/production-resource-diagnostic.yml@refs/heads/main
review="$test_root/release/image-cleanup-review.json"
jq -n '{schema_version:3,status:"blocked",approval_expires_at_epoch:1,monitoring:{resources:{
  max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,
  max_io_full_avg10:1,max_cpu_some_avg10:10}}}' > "$test_root/baseline.json"
reset_review() { cp "$test_root/baseline.json" "$review"; cp "$review" "$TEST_COMMITTED_REVIEW"; }
reset_review
cases=0
failure() {
  local expected=$1
  shift
  if "$@" > "$test_root/result" 2>&1; then echo "RESOURCE_REQUEST_TEST_FAIL=unexpected_success:$expected" >&2; exit 1; fi
  grep -Fxq "RESOURCE_DIAGNOSTIC_REQUEST_FAIL=$expected" "$test_root/result"
  [[ ! -s "$TEST_FORBIDDEN_LOG" ]]
  ((cases += 1))
}
request=(bash "$test_root/scripts/verify-resource-diagnostic-request.sh")
"${request[@]}" > "$test_root/result"
grep -Fxq "RESOURCE_DIAGNOSTIC_REQUEST=PASS action=resource_diagnostic sha=$GITHUB_SHA" "$test_root/result"
# Expired/blocked cleanup metadata neither blocks diagnosis nor changes authority.
[[ ! -s "$TEST_FORBIDDEN_LOG" ]]
((cases += 1))
failure workflow_identity env GITHUB_REPOSITORY=other/repo "${request[@]}"
failure workflow_identity env GITHUB_REF=refs/heads/develop "${request[@]}"
failure workflow_identity env GITHUB_EVENT_NAME=push "${request[@]}"
failure workflow_identity env GITHUB_WORKFLOW_REF=Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main "${request[@]}"
failure workflow_identity env GITHUB_WORKFLOW_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb "${request[@]}"
failure workflow_identity env EXPECTED_SHA=shortsha "${request[@]}"
failure workflow_identity env GITHUB_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb "${request[@]}"
failure rerun_not_authorized env GITHUB_RUN_ATTEMPT=2 "${request[@]}"
failure checkout_mismatch env TEST_HEAD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb "${request[@]}"
failure dirty_checkout env TEST_UNSTAGED=true "${request[@]}"
failure dirty_checkout env TEST_STAGED=true "${request[@]}"
failure dirty_checkout env 'TEST_STATUS=?? private-untracked-file' "${request[@]}"
! grep -Fq private-untracked-file "$test_root/result"
failure checkout_inventory env TEST_STATUS_FAILED=true "${request[@]}"
for state in '+changed' '-uninitialized' 'Uconflicted'; do failure dirty_submodule env "TEST_SUBMODULE=$state" "${request[@]}"; done
failure submodule_inventory env TEST_SUBMODULE_FAILED=true "${request[@]}"
failure stale_main env TEST_REMOTE=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb "${request[@]}"
failure stale_main env TEST_REMOTE_DUPLICATE=true "${request[@]}"
failure remote_unavailable env TEST_REMOTE_FAILED=true "${request[@]}"
failure committed_limits_unavailable env TEST_SHOW_FAILED=true "${request[@]}"
failure unexpected_arguments "${request[@]}" cleanup
jq '.monitoring.resources.max_cpu_busy_percent=84' "$test_root/baseline.json" > "$review"
failure uncommitted_limits "${request[@]}"
for mutation in '.schema_version=2' '.monitoring.resources.extra=1' '.monitoring.resources.max_cpu_busy_percent="85"' '.monitoring.resources.max_io_full_avg10=0'; do
  jq "$mutation" "$test_root/baseline.json" > "$review"
  cp "$review" "$TEST_COMMITTED_REVIEW"
  failure invalid_limits "${request[@]}"
done
for mutation in '.monitoring.resources.max_cpu_busy_percent=86' '.monitoring.resources.max_cpu_iowait_percent=11' \
  '.monitoring.resources.min_mem_available_kib=131071' '.monitoring.resources.max_io_full_avg10=1.1' '.monitoring.resources.max_cpu_some_avg10=10.1'; do
  jq "$mutation" "$test_root/baseline.json" > "$review"
  cp "$review" "$TEST_COMMITTED_REVIEW"
  failure relaxed_limits "${request[@]}"
done
reset_review
"${request[@]}" > "$test_root/result"
[[ ! -s "$TEST_FORBIDDEN_LOG" ]]
printf 'RESOURCE_DIAGNOSTIC_REQUEST_TEST=PASS cases=%s production_connections=0 cleanup_metadata_ignored=true\n' "$cases"
