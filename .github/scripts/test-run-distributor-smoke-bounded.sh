#!/usr/bin/env bash
set -euo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
work_dir=$(mktemp -d)
cleanup() {
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

cat > "$work_dir/quick-smoke.sh" <<'SH'
#!/usr/bin/env bash
exit 0
SH
SMOKE_TIMEOUT_SECONDS=2 bash "$script_dir/run-distributor-smoke-bounded.sh" "$work_dir/quick-smoke.sh"

cat > "$work_dir/hung-smoke.sh" <<'SH'
#!/usr/bin/env bash
sleep 10
SH
set +e
timeout_output=$(
  SMOKE_TIMEOUT_SECONDS=1 \
    bash "$script_dir/run-distributor-smoke-bounded.sh" "$work_dir/hung-smoke.sh" 2>&1
)
timeout_status=$?
set -e
test "$timeout_status" = 124
grep -Fq 'DISTRIBUTOR_SMOKE_TIMEOUT=FAIL timeout_seconds=1' <<< "$timeout_output"

echo 'BOUNDED_DISTRIBUTOR_SMOKE_TEST=PASS'
