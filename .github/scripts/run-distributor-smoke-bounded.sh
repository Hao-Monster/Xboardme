#!/usr/bin/env bash
set -euo pipefail

: "${SMOKE_TIMEOUT_SECONDS:=300}"

[[ "$SMOKE_TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] || {
  echo 'Distributor smoke timeout must be an integer number of seconds.' >&2
  exit 2
}
((SMOKE_TIMEOUT_SECONDS >= 1 && SMOKE_TIMEOUT_SECONDS <= 900)) || {
  echo 'Distributor smoke timeout must be between 1 and 900 seconds.' >&2
  exit 2
}

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
smoke_script=${1:-"$script_dir/smoke-distributor-remote.sh"}
[[ -f "$smoke_script" ]] || {
  echo 'Distributor smoke script is missing.' >&2
  exit 2
}

set +e
timeout --signal=TERM --kill-after=15s "${SMOKE_TIMEOUT_SECONDS}s" bash "$smoke_script"
status=$?
set -e

if ((status == 124 || status == 137)); then
  echo "DISTRIBUTOR_SMOKE_TIMEOUT=FAIL timeout_seconds=$SMOKE_TIMEOUT_SECONDS" >&2
fi
exit "$status"
