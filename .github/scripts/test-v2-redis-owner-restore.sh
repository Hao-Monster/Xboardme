#!/usr/bin/env bash
set -Eeuo pipefail

: "${IMAGE:?IMAGE is required}"

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=v2-low-memory-common.sh
source "$script_dir/v2-low-memory-common.sh"

suffix="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-$$"
REDIS_VOLUME_NAME="xboard-v2-owner-test-$suffix"
anchor_name="xboard-v2-owner-anchor-$suffix"
LEGACY_ANCHOR_ID=''

cleanup() {
  if [[ -n "$LEGACY_ANCHOR_ID" && "$anchor_name" == xboard-v2-owner-anchor-* ]]; then
    docker rm -f "$LEGACY_ANCHOR_ID" >/dev/null 2>&1 || true
  fi
  if [[ "$REDIS_VOLUME_NAME" == xboard-v2-owner-test-* ]]; then
    docker volume rm "$REDIS_VOLUME_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

docker volume create "$REDIS_VOLUME_NAME" >/dev/null
docker run --rm \
  --network none \
  --user 0:0 \
  --volume "$REDIS_VOLUME_NAME:/data" \
  --entrypoint /bin/sh \
  "$IMAGE" -eu -c '
    printf "%s\n" owner-restore-contract > /data/dump.rdb
    chown 999:1000 /data /data/dump.rdb
    chmod 600 /data/dump.rdb
  '

LEGACY_ANCHOR_ID=$(docker create \
  --name "$anchor_name" \
  --volume "$REDIS_VOLUME_NAME:/data" \
  --entrypoint /bin/sh \
  "$IMAGE" -c true)

before_owner=$(docker run --rm \
  --network none \
  --volume "$REDIS_VOLUME_NAME:/data:ro" \
  --entrypoint stat \
  "$IMAGE" -c '%u:%g' /data/dump.rdb)
before_hash=$(docker run --rm \
  --network none \
  --volume "$REDIS_VOLUME_NAME:/data:ro" \
  --entrypoint sha256sum \
  "$IMAGE" /data/dump.rdb | awk '{print $1}')
[[ "$before_owner" == 999:1000 ]]

v2_assert_legacy_identity() { :; }
v2_restore_legacy_redis_owner

expected_owner=$(docker run --rm --network none --entrypoint /bin/sh "$IMAGE" -eu -c \
  'printf "%s:%s\n" "$(id -u redis)" "$(id -g redis)"')
after_owner=$(docker run --rm \
  --network none \
  --volume "$REDIS_VOLUME_NAME:/data:ro" \
  --entrypoint stat \
  "$IMAGE" -c '%u:%g' /data/dump.rdb)
after_hash=$(docker run --rm \
  --network none \
  --volume "$REDIS_VOLUME_NAME:/data:ro" \
  --entrypoint sha256sum \
  "$IMAGE" /data/dump.rdb | awk '{print $1}')

[[ "$after_owner" == "$expected_owner" ]]
[[ "$after_hash" == "$before_hash" ]]
printf 'V2_REDIS_OWNER_RESTORE=PASS owner=%s hash_unchanged=true\n' "$after_owner"
