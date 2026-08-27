#!/usr/bin/env bash
set -Eeuo pipefail

: "${STAGE_RUN_ID:?STAGE_RUN_ID is required}"
if [[ ! "$STAGE_RUN_ID" =~ ^[0-9]+-[0-9]+$ ]]; then
  echo 'STAGE_CLEANUP_FAIL=invalid_run_id'
  exit 1
fi

command -v docker >/dev/null
docker info >/dev/null

mapfile -t containers < <(
  docker ps -aq \
    --filter label=codex.xboard.stage=true \
    --filter "label=codex.xboard.stage.run=$STAGE_RUN_ID"
)
if ((${#containers[@]} > 1)); then
  echo "STAGE_CLEANUP_FAIL=ambiguous_containers count=${#containers[@]}"
  exit 1
fi

if ! declare -F xboard_resolve_active_runtime >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck disable=SC1091
  source "$script_dir/production-runtime-discovery.sh"
fi

if ! xboard_find_compose_anchor; then
  echo "STAGE_CLEANUP_FAIL=production_workdir_missing detail=$XBOARD_DISCOVERY_ERROR"
  exit 1
fi
workdir=$XBOARD_ANCHOR_WORKDIR

if ! xboard_find_caddy_upstream || ! xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT"; then
  echo "STAGE_CLEANUP_FAIL=active_web_discovery detail=$XBOARD_DISCOVERY_ERROR"
  exit 1
fi
primary=$XBOARD_ACTIVE_WEB

stage_root="$workdir/.codex-stage"
stage_dir="$stage_root/$STAGE_RUN_ID"
case "$stage_dir" in
  "$stage_root"/*) ;;
  *) echo 'STAGE_CLEANUP_FAIL=unsafe_stage_path'; exit 1 ;;
esac

cleanup_image=$(docker inspect -f '{{.Image}}' "$primary")
if ((${#containers[@]} == 1)); then
  stage_image=$(docker inspect -f '{{.Image}}' "${containers[0]}")
  docker rm -f "${containers[0]}" >/dev/null
  cleanup_image=$stage_image
fi
if [[ -d "$stage_dir" ]]; then
  docker run --rm --entrypoint sh -v "$stage_dir:/stage" "$cleanup_image" \
    -c 'find /stage -mindepth 1 -delete' >/dev/null
  rmdir -- "$stage_dir"
fi
docker exec -u 0 "$primary" rm -f "/www/.docker/.data/.codex-stage-$STAGE_RUN_ID.sqlite"

echo "STAGE_CLEANUP=PASS run=$STAGE_RUN_ID"
