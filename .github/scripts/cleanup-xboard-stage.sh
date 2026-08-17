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

mapfile -t production_ids < <(
  docker ps --format '{{.ID}} {{.Image}}' |
    awk 'tolower($2) ~ /xboard/ {print $1}'
)
workdir=''
for container_id in "${production_ids[@]}"; do
  is_stage=$(docker inspect -f '{{ index .Config.Labels "codex.xboard.stage" }}' "$container_id")
  [[ "$is_stage" == true ]] && continue
  candidate_workdir=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$container_id")
  if [[ -n "$candidate_workdir" ]]; then
    if [[ -n "$workdir" && "$workdir" != "$candidate_workdir" ]]; then
      echo 'STAGE_CLEANUP_FAIL=ambiguous_production_workdir'
      exit 1
    fi
    workdir=$candidate_workdir
  fi
done
if [[ -z "$workdir" || ! -d "$workdir" ]]; then
  echo 'STAGE_CLEANUP_FAIL=production_workdir_missing'
  exit 1
fi

stage_root="$workdir/.codex-stage"
stage_dir="$stage_root/$STAGE_RUN_ID"
case "$stage_dir" in
  "$stage_root"/*) ;;
  *) echo 'STAGE_CLEANUP_FAIL=unsafe_stage_path'; exit 1 ;;
esac

cleanup_image=$(docker inspect -f '{{.Image}}' "${production_ids[0]}")
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

echo "STAGE_CLEANUP=PASS run=$STAGE_RUN_ID"
