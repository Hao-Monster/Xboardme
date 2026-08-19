#!/usr/bin/env bash
set -euo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
wrapper="$script_dir/ssh-with-password.sh"
work_dir=$(mktemp -d)
trap 'rm -rf -- "$work_dir"' EXIT

fake_bin="$work_dir/bin"
mkdir -p "$fake_bin"
cat > "$fake_bin/ssh" <<'FAKE_SSH'
#!/usr/bin/env bash
set -euo pipefail

test "$SSH_ASKPASS_REQUIRE" = force
test -n "$DISPLAY"
test -x "$SSH_ASKPASS"
test "$("$SSH_ASKPASS" "deploy@example.test's password:")" = "$SSHPASS"
if "$SSH_ASKPASS" 'Are you sure you want to continue connecting?' >/dev/null 2>&1; then
  echo 'The askpass helper accepted a host-key prompt.' >&2
  exit 90
fi

printf '%s\n' "$@" > "$TEST_ARGS_FILE"
cat > "$TEST_STDIN_FILE"
exit "${TEST_SSH_EXIT_CODE:-0}"
FAKE_SSH
chmod 700 "$fake_bin/ssh"

args_file="$work_dir/args"
stdin_file="$work_dir/stdin"
secret='deployment-password-not-for-logs'

printf '%s' 'remote-input' | \
  PATH="$fake_bin:$PATH" \
  SSHPASS="$secret" \
  TEST_ARGS_FILE="$args_file" \
  TEST_STDIN_FILE="$stdin_file" \
  bash "$wrapper" -p 2222 deploy@example.test 'bash -s'

test "$(cat "$stdin_file")" = remote-input
grep -Fxq -- '-o' "$args_file"
grep -Fxq -- 'BatchMode=no' "$args_file"
grep -Fxq -- 'NumberOfPasswordPrompts=1' "$args_file"
grep -Fxq -- 'PreferredAuthentications=password,keyboard-interactive' "$args_file"
grep -Fxq -- 'PubkeyAuthentication=no' "$args_file"
grep -Fxq -- 'StrictHostKeyChecking=yes' "$args_file"
grep -Fxq -- '-p' "$args_file"
grep -Fxq -- '2222' "$args_file"
grep -Fxq -- 'deploy@example.test' "$args_file"
grep -Fxq -- 'bash -s' "$args_file"
if grep -Fq -- "$secret" "$args_file" || grep -Fq -- "$secret" "$stdin_file"; then
  echo 'The SSH password leaked into arguments or standard input.' >&2
  exit 1
fi

if PATH="$fake_bin:$PATH" \
  TEST_ARGS_FILE="$args_file" \
  TEST_STDIN_FILE="$stdin_file" \
  bash "$wrapper" deploy@example.test true >/dev/null 2>&1; then
  echo 'The wrapper accepted an empty SSH password.' >&2
  exit 1
fi

set +e
PATH="$fake_bin:$PATH" \
SSHPASS="$secret" \
TEST_ARGS_FILE="$args_file" \
TEST_STDIN_FILE="$stdin_file" \
TEST_SSH_EXIT_CODE=37 \
bash "$wrapper" deploy@example.test true
status=$?
set -e
test "$status" = 37

echo 'Password SSH wrapper tests passed.'
