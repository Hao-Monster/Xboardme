#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
preflight="$script_dir/preflight-xboard-compose.sh"
revision=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa

XBOARD_PREFLIGHT_SELF_TEST=scheduler-reaper \
  XBOARD_SCHEDULER_REAPER_REQUIRED=true \
  XBOARD_SCHEDULER_INIT=true \
  XBOARD_SCHEDULER_PID1=docker-init \
  XBOARD_SCHEDULER_ZOMBIES=0 \
  XBOARD_SCHEDULER_REVISION="$revision" \
  XBOARD_WEB_REVISION="$revision" \
  bash "$preflight"

if invalid_output=$(XBOARD_PREFLIGHT_SELF_TEST=scheduler-reaper \
  XBOARD_SCHEDULER_REAPER_REQUIRED=true \
  XBOARD_SCHEDULER_INIT=false \
  XBOARD_SCHEDULER_PID1=php \
  XBOARD_SCHEDULER_ZOMBIES=1 \
  XBOARD_SCHEDULER_REVISION="$revision" \
  XBOARD_WEB_REVISION="$revision" \
  bash "$preflight"); then
  echo 'Invalid scheduler reaper state unexpectedly passed.' >&2
  exit 1
fi
test "$invalid_output" = 'PREFLIGHT_FAIL=scheduler_init_or_zombie_reaping'

XBOARD_PREFLIGHT_SELF_TEST=scheduler-reaper \
  XBOARD_SCHEDULER_REAPER_REQUIRED=false \
  XBOARD_SCHEDULER_INIT=false \
  XBOARD_SCHEDULER_PID1=php \
  XBOARD_SCHEDULER_ZOMBIES=200 \
  bash "$preflight"
