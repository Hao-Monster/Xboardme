#!/usr/bin/env bash
set -Eeuo pipefail

# Local process/lock integration only: no Docker daemon or SSH connection.
for tool in bash flock timeout; do command -v "$tool" >/dev/null; done
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
cat > "$test_root/worker.sh" <<'WORKER'
set -Eeuo pipefail
test_dir=$1
exec 9>"$test_dir/deploy.lock"
flock -n 9
trap 'printf "terminated\n" > "$test_dir/failed-ledger"; exit 143' TERM HUP INT
printf 'approved-id\n' > "$test_dir/durable-intent"
printf 'locked\n' > "$test_dir/started"
# The subprocess inherits fd 9. The remote outer timeout must manage the whole
# process group, not just kill the shell/runner and leave its child holding it.
sleep 30 &
wait $!
WORKER
timeout --signal=TERM --kill-after=1s 2s bash "$test_root/worker.sh" "$test_root" > "$test_root/output" 2>&1 &
timeout_pid=$!
for ((attempt=0;attempt<30;attempt++)); do
  [[ ! -f "$test_root/started" ]] || break
  sleep 0.05
done
[[ -f "$test_root/started" ]]
if flock -n "$test_root/deploy.lock" true; then
  echo 'RETENTION_TIMEOUT_TEST=FAIL lock_not_held' >&2
  exit 1
fi
set +e
wait "$timeout_pid"
status=$?
set -e
[[ $status == 124 ]]
grep -qx approved-id "$test_root/durable-intent"
grep -qx terminated "$test_root/failed-ledger"
flock -n "$test_root/deploy.lock" true
echo 'RETENTION_TIMEOUT_TEST=PASS cases=1 group_terminated=true lock_released=true intent_preserved=true real_docker=false ssh=false'
