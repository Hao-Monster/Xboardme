#!/usr/bin/env bash
set -euo pipefail

: "${SSHPASS:?SSHPASS is required}"

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
askpass="$script_dir/ssh-askpass.sh"

test -f "$askpass"
chmod 700 "$askpass"
command -v ssh >/dev/null

export SSH_ASKPASS="$askpass"
export SSH_ASKPASS_REQUIRE=force
export DISPLAY=${DISPLAY:-xboard-deploy:0}

exec ssh \
  -o BatchMode=no \
  -o NumberOfPasswordPrompts=1 \
  -o PreferredAuthentications=password,keyboard-interactive \
  -o PubkeyAuthentication=no \
  -o StrictHostKeyChecking=yes \
  "$@"
