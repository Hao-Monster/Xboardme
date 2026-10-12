#!/usr/bin/env bash
set -euo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
work_dir=$(mktemp -d)
cleanup() {
  local pid_file
  for pid_file in "$work_dir"/*/tunnel.pid; do
    [[ ! -f "$pid_file" ]] || kill "$(< "$pid_file")" 2>/dev/null || true
  done
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

mkdir -p "$work_dir/bin" "$work_dir/repo/.github/scripts"
cp "$script_dir/fixtures/distributor-smoke-session/curl" "$work_dir/bin/curl"
chmod 700 "$work_dir/bin/curl"
cp "$script_dir/smoke-distributor-remote.sh" "$script_dir/smoke-admin-assets.sh" \
  "$script_dir/run-distributor-smoke-bounded.sh" "$work_dir/repo/.github/scripts/"
cp "$script_dir/fixtures/distributor-smoke-session/ssh-with-password.sh" "$work_dir/repo/.github/scripts/"

run_case() (
  scenario=$1
  expected_status=$2
  expected_cleanup=$3
  timeout_seconds=30
  [[ "$scenario" != timeout ]] || timeout_seconds=1
  case_dir="$work_dir/$scenario"
  mkdir -p "$case_dir/tmp"
  printf '%s\n' preexisting > "$case_dir/sessions"
  : > "$case_dir/revocations.log"
  cd "$work_dir/repo"
  set +e
  PATH="$work_dir/bin:$PATH" TMPDIR="$case_dir/tmp" \
    SMOKE_FIXTURE_ROOT="$case_dir" SMOKE_FIXTURE_CASE="$scenario" \
    DEPLOY_HOST=synthetic.invalid DEPLOY_PORT=22 DEPLOY_USER=fixture SSHPASS=synthetic \
    DISTRIBUTOR_EMAIL=fixture.invalid DISTRIBUTOR_PASSWORD=synthetic-password \
    TARGET_PORT=7002 SMOKE_VALIDATION_MODE=rollback SMOKE_VERIFY_PUBLIC_ROUTE=false \
    SMOKE_TIMEOUT_SECONDS="$timeout_seconds" \
    bash "$work_dir/repo/.github/scripts/run-distributor-smoke-bounded.sh" \
      "$work_dir/repo/.github/scripts/smoke-distributor-remote.sh" \
      > "$case_dir/output" 2>&1
  result=$?
  set -e
  if [[ "$result" != "$expected_status" ]]; then
    printf 'Unexpected status: case=%s expected=%s actual=%s\n' "$scenario" "$expected_status" "$result" >&2
    return 1
  fi
  if ! grep -Fq "DISTRIBUTOR_SMOKE_SESSION_CLEANUP=$expected_cleanup" "$case_dir/output"; then
    printf 'Missing session cleanup result: case=%s expected=%s\n' "$scenario" "$expected_cleanup" >&2
    return 1
  fi
  grep -qx preexisting "$case_dir/sessions"
  if [[ "$expected_cleanup" == PASS ]]; then
    ! grep -qx current "$case_dir/sessions"
    [[ "$(wc -l < "$case_dir/revocations.log")" == 1 ]]
    grep -qx 'only-current-token' "$case_dir/revocations.log"
  elif [[ "$expected_cleanup" == NOT_CREATED ]]; then
    [[ ! -s "$case_dir/revocations.log" ]]
    [[ "$(wc -l < "$case_dir/sessions")" == 1 ]]
  else
    grep -qx current "$case_dir/sessions"
  fi
  # The only permitted mutation is login plus this run's current-token logout.
  ! grep '^POST ' "$case_dir/requests.log" | grep -Ev '/api/v1/(passport/auth/login|user/logout)$'
  ! grep -Eq 'syntheticSmokeTokenOnly|synthetic-password' "$case_dir/output"
  [[ ! -e login.json ]]
  [[ -z "$(find "$case_dir/tmp" -mindepth 1 -print -quit)" ]]
  if [[ -f "$case_dir/tunnel.pid" ]]; then
    ! kill -0 "$(< "$case_dir/tunnel.pid")" 2>/dev/null
  fi
  if [[ -f "$case_dir/request.pid" ]]; then
    ! kill -0 "$(< "$case_dir/request.pid")" 2>/dev/null
  fi
  if [[ "$expected_status" != 0 ]]; then
    ! grep -Fq 'Distributor production smoke test passed.' "$case_dir/output"
  fi
  if [[ "$expected_cleanup" == INCOMPLETE ]]; then
    grep -Fq 'DISTRIBUTOR_SMOKE_SESSION_RECONCILE run_id=local attempt=local login_started_at=' "$case_dir/output"
  fi
  printf 'SMOKE_SESSION_CASE=%s PASS\n' "$scenario"
)

run_case success 0 PASS
run_case stubborn-tunnel 0 PASS
run_case postlogin-failed 42 PASS
run_case login-rejected 1 NOT_CREATED
run_case wrong-role 1 PASS
run_case revoke-failed 1 INCOMPLETE
run_case revoke-false 1 INCOMPLETE
run_case revoke-not-effective 1 INCOMPLETE
run_case both-failed 42 INCOMPLETE
run_case login-response-lost 28 INCOMPLETE
run_case timeout 124 PASS
echo 'DISTRIBUTOR_SMOKE_SESSION_TEST=PASS cases=11'
