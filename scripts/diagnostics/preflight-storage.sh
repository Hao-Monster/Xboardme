#!/usr/bin/env bash
# Pre-allocate and verify the 30 GiB ext4 loop storage image for Customer Connection Diagnostics Pilot (D6.2).
# Hard safety rules:
# - Never auto-create an unmounted directory on the root filesystem for VictoriaLogs.
# - Refuse sparse files; require full 30 GiB physical block reservation (`fallocate` or `dd`).
# - Verify root filesystem free space after 30 GiB allocation >= max(50 GiB, 20% of root total).
# - Refuse to overwrite an existing image or format any existing block device.
# - Verify mount point, filesystem type (`ext4`), UUID, and read/write permissions before container startup.

set -euo pipefail

MODE=""
TARGET_DIR=""
EXPECTED_UUID=""
AUTHORIZE_CREATE="0"

usage() {
  cat <<'EOF'
Usage:
  preflight-storage.sh --verify-mounted --directory <dir> [--expected-uuid <uuid>]
  preflight-storage.sh --dry-run --directory <dir>
  preflight-storage.sh --provision --authorize-pilot-storage --directory <dir>
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --verify-mounted)
      MODE="verify-mounted"
      shift
      ;;
    --dry-run)
      MODE="dry-run"
      shift
      ;;
    --provision)
      MODE="provision"
      shift
      ;;
    --authorize-pilot-storage)
      AUTHORIZE_CREATE="1"
      shift
      ;;
    --directory)
      TARGET_DIR="${2:-}"
      shift 2
      ;;
    --expected-uuid)
      EXPECTED_UUID="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "error: unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$MODE" || -z "$TARGET_DIR" ]]; then
  echo "error: --directory and a mode (--verify-mounted | --dry-run | --provision) are required" >&2
  exit 2
fi

IMAGE_BYTES=$((30 * 1024 * 1024 * 1024))
MIN_ROOT_FREE_FIXED=$((50 * 1024 * 1024 * 1024))
IMAGE_PATH="${TARGET_DIR%/}/storage.ext4"
MOUNT_PATH="${TARGET_DIR%/}/data"
META_PATH="${TARGET_DIR%/}/state/mount-identity.json"

check_root_budget() {
  local need_extra_bytes="$1"
  local df_line
  df_line="$(df -B1 --output=size,avail / | tail -n 1)"
  local root_total root_avail
  read -r root_total root_avail <<<"$df_line"

  local twenty_pct=$((root_total / 5))
  local required_floor="$MIN_ROOT_FREE_FIXED"
  if [[ "$twenty_pct" -gt "$required_floor" ]]; then
    required_floor="$twenty_pct"
  fi

  local projected_free=$((root_avail - need_extra_bytes))
  if [[ "$projected_free" -lt "$required_floor" ]]; then
    echo "error: root disk free space after allocation (${projected_free} B) would fall below floor (${required_floor} B)" >&2
    exit 1
  fi
}

verify_non_sparse_image() {
  if [[ ! -f "$IMAGE_PATH" || -L "$IMAGE_PATH" ]]; then
    echo "error: storage image missing or is a symlink: $IMAGE_PATH" >&2
    exit 1
  fi
  local logical_size allocated_blocks allocated_bytes
  logical_size="$(stat -c '%s' "$IMAGE_PATH")"
  allocated_blocks="$(stat -c '%b' "$IMAGE_PATH")"
  allocated_bytes=$((allocated_blocks * 512))

  if [[ "$logical_size" -lt "$IMAGE_BYTES" ]]; then
    echo "error: storage image logical size (${logical_size} B) is smaller than 30 GiB (${IMAGE_BYTES} B)" >&2
    exit 1
  fi
  # Require at least 99% physical block allocation to reject sparse files.
  local min_allocated=$((IMAGE_BYTES * 99 / 100))
  if [[ "$allocated_bytes" -lt "$min_allocated" ]]; then
    echo "error: storage image is sparse (allocated=${allocated_bytes} B, required>=${min_allocated} B)" >&2
    exit 1
  fi
}

verify_mounted_filesystem() {
  verify_non_sparse_image
  check_root_budget 0

  if [[ ! -d "$MOUNT_PATH" || -L "$MOUNT_PATH" ]]; then
    echo "error: mount directory missing or is a symlink: $MOUNT_PATH" >&2
    exit 1
  fi

  if ! mountpoint -q "$MOUNT_PATH"; then
    echo "error: $MOUNT_PATH is not an active mountpoint; refusing to fall back to root disk directory" >&2
    exit 1
  fi

  local fstype
  fstype="$(findmnt -n -o FSTYPE --target "$MOUNT_PATH" || true)"
  if [[ "$fstype" != "ext4" ]]; then
    echo "error: expected ext4 filesystem on $MOUNT_PATH, got '${fstype}'" >&2
    exit 1
  fi

  local actual_uuid
  actual_uuid="$(findmnt -n -o UUID --target "$MOUNT_PATH" || true)"
  if [[ -n "$EXPECTED_UUID" && "$actual_uuid" != "$EXPECTED_UUID" ]]; then
    echo "error: filesystem UUID mismatch on $MOUNT_PATH (expected=${EXPECTED_UUID}, actual=${actual_uuid})" >&2
    exit 1
  fi

  if [[ ! -w "$MOUNT_PATH" ]]; then
    echo "error: mounted filesystem $MOUNT_PATH is not writable" >&2
    exit 1
  fi

  echo "{\"status\":\"mounted_ready\",\"mount_path\":\"${MOUNT_PATH}\",\"fs_type\":\"${fstype}\",\"uuid\":\"${actual_uuid}\"}"
}

case "$MODE" in
  dry-run)
    check_root_budget "$IMAGE_BYTES"
    echo "{\"status\":\"dry_run_ok\",\"target_image\":\"${IMAGE_PATH}\",\"target_mount\":\"${MOUNT_PATH}\",\"planned_bytes\":${IMAGE_BYTES}}"
    ;;
  verify-mounted)
    verify_mounted_filesystem
    ;;
  provision)
    if [[ "$AUTHORIZE_CREATE" != "1" ]]; then
      echo "error: refusing to allocate or format storage image without --authorize-pilot-storage" >&2
      exit 1
    fi
    if [[ -e "$IMAGE_PATH" || -L "$IMAGE_PATH" ]]; then
      echo "error: target storage image already exists; refusing to overwrite: $IMAGE_PATH" >&2
      exit 1
    fi
    if [[ -e "$MOUNT_PATH" ]] && mountpoint -q "$MOUNT_PATH"; then
      echo "error: $MOUNT_PATH is already mounted; refusing to provision" >&2
      exit 1
    fi
    check_root_budget "$IMAGE_BYTES"
    mkdir -p -m 0700 "$TARGET_DIR" "${TARGET_DIR%/}/state"
    mkdir -p -m 0700 "$MOUNT_PATH"
    fallocate -l "$IMAGE_BYTES" "$IMAGE_PATH"
    chmod 0600 "$IMAGE_PATH"
    verify_non_sparse_image
    # The default mkfs discard punches holes in the preallocated regular file.
    mkfs.ext4 -F -E nodiscard -m 1 -L "xboard_diag_30g" "$IMAGE_PATH" >/dev/null
    verify_non_sparse_image
    FS_UUID="$(blkid -s UUID -o value "$IMAGE_PATH")"
    printf '{\n  "schema_version": 1,\n  "image_path": "%s",\n  "mount_path": "%s",\n  "fs_type": "ext4",\n  "fs_uuid": "%s",\n  "size_bytes": %d\n}\n' \
      "$IMAGE_PATH" "$MOUNT_PATH" "$FS_UUID" "$IMAGE_BYTES" >"$META_PATH"
    chmod 0600 "$META_PATH"
    echo "{\"status\":\"provisioned\",\"uuid\":\"${FS_UUID}\"}"
    ;;
esac
