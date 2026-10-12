#!/usr/bin/env bash
set -Eeuo pipefail

# Real Docker API semantics, using only resources made by this test. This is
# not an end-to-end production cleanup, nor proof of production mount ownership.
[[ "${RETENTION_DOCKER_TEST_ALLOW_LOCAL:-false}" == true ]] || {
  echo 'RETENTION_DOCKER_TEST=NOT_RUN explicit_local_test_opt_in_required' >&2
  exit 2
}
[[ -z "${DOCKER_HOST:-}" ]] || {
  echo 'RETENTION_DOCKER_TEST=FAIL DOCKER_HOST_override_forbidden' >&2
  exit 2
}
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
case "$endpoint" in unix:///*) ;; *) echo 'RETENTION_DOCKER_TEST=FAIL non_local_daemon' >&2; exit 2 ;; esac
docker info --format '{{.ID}}' >/dev/null
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
work_dir=$(mktemp -d)
label="retention-test-$(date +%s)-$$-${RANDOM}"
containers=() images=() volume=''
cleanup() {
  local status=$? id index
  trap - EXIT
  for id in "${containers[@]}"; do
    if docker container inspect "$id" >/dev/null 2>&1; then
      [[ $(docker inspect -f '{{ index .Config.Labels "codex.retention.test" }}' "$id") == "$label" ]] || exit 1
      docker container rm "$id" >/dev/null || status=1
    fi
  done
  if [[ -n "$volume" ]] && docker volume inspect "$volume" >/dev/null 2>&1; then
    [[ $(docker volume inspect -f '{{ index .Labels "codex.retention.test" }}' "$volume") == "$label" ]] || exit 1
    docker volume rm "$volume" >/dev/null || status=1
  fi
  for ((index=${#images[@]}-1; index>=0; index--)); do
    id=${images[index]}
    if docker image inspect "$id" >/dev/null 2>&1; then
      [[ $(docker image inspect -f '{{ index .Config.Labels "codex.retention.test" }}' "$id") == "$label" ]] || exit 1
      # Only this test's exact references, never daemon-wide housekeeping.
      while IFS= read -r ref; do
        [[ "$ref" == "$label:"* ]] || { status=1; continue; }
        if docker image inspect "$ref" >/dev/null 2>&1; then
          docker image rm --no-prune "$ref" >/dev/null || status=1
        fi
      done < <(docker image inspect "$id" | jq -r '.[0].RepoTags[]?')
      if docker image inspect "$id" >/dev/null 2>&1; then docker image rm --no-prune "$id" >/dev/null || status=1; fi
    fi
  done
  rm -rf -- "$work_dir"
  exit "$status"
}
trap cleanup EXIT
mkdir "$work_dir/rootfs" "$work_dir/bind"
printf 'synthetic persistent record\n' > "$work_dir/bind/record"
printf 'synthetic layer\n' > "$work_dir/rootfs/record"
base=$(tar -C "$work_dir/rootfs" -cf - . | docker image import --change "LABEL codex.retention.test=$label" - "$label:base")
images+=("$base")
volume=$(docker volume create --label "codex.retention.test=$label" "$label-data")
protected=$(docker create --label "codex.retention.test=$label" \
  --mount "type=bind,src=$work_dir/bind,dst=/data" \
  --mount "type=volume,src=$volume,dst=/redis" "$base" /not-executed)
containers+=("$protected")
before=$(retention_container_snapshot "$protected")
volume_before=$(docker volume inspect "$volume" | jq -cS '.[0]|{Name,Mountpoint,Driver,Labels}')
# Commit a stopped test container to create another image sharing its base layer.
old=$(docker commit --change "LABEL codex.retention.test=$label" "$protected" "$label:old-a")
images+=("$old")
docker tag "$old" "$label:old-b"
retired=$(docker create --label "codex.retention.test=$label" "$old" /not-executed)
containers+=("$retired")
docker container rm "$retired" >/dev/null
docker image rm --no-prune "$label:old-a" >/dev/null
[[ $(docker image inspect -f '{{.Id}}' "$label:old-b") == "$old" ]]
# Fresh inspection tolerates a reference already removed by the preceding step.
if docker image inspect "$label:old-a" >/dev/null 2>&1; then exit 1; fi
docker image rm --no-prune "$label:old-b" >/dev/null
if docker image inspect "$old" >/dev/null 2>&1; then exit 1; fi
[[ $(docker image inspect -f '{{.Id}}' "$base") == "$base" ]]
if docker image rm --no-prune "$base" > "$work_dir/conflict" 2>&1; then
  echo 'RETENTION_DOCKER_TEST=FAIL referenced_image_removed' >&2
  exit 1
fi
[[ $(retention_container_snapshot "$protected") == "$before" ]]
[[ $(docker volume inspect "$volume" | jq -cS '.[0]|{Name,Mountpoint,Driver,Labels}') == "$volume_before" ]]
[[ $(< "$work_dir/bind/record") == 'synthetic persistent record' ]]
echo 'RETENTION_DOCKER_TEST=PASS multi_tag=true missing_reference=true shared_layer_preserved=true referenced_image_refused=true bind_data_preserved=true volume_preserved=true running_containers_created=0'
