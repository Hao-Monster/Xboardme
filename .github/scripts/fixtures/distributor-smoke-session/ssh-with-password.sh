#!/usr/bin/env bash
set -euo pipefail

# Synthetic transport only: no SSH executable or network connection is used.
[[ " $* " == *' -N '* ]] || exit 90
printf '%s\n' "$$" > "$SMOKE_FIXTURE_ROOT/tunnel.pid"
[[ "$SMOKE_FIXTURE_CASE" != stubborn-tunnel ]] || trap '' TERM
exec sleep 300
