#!/usr/bin/env bash

set -euo pipefail

base_url="${1:-http://127.0.0.1:17001}"
case "$base_url" in
  http://127.0.0.1:[0-9]*) ;;
  *) echo 'Admin asset smoke URL must use a loopback HTTP endpoint.' >&2; exit 1 ;;
esac

: "${ADMIN_ASSET_EXPECTED_DIR:=}"

verify_asset_digest_filename() {
  local asset_path=$1
  local downloaded_file=$2
  local base_name expected_prefix actual_prefix
  base_name=$(basename -- "$asset_path")
  if [[ "$base_name" =~ ^.+[-]([0-9a-f]{10})\.(js|css)$ ]]; then
    expected_prefix="${BASH_REMATCH[1]}"
    actual_prefix=$(sha256sum "$downloaded_file" | awk '{print substr($1, 1, 10)}')
    if [[ "$actual_prefix" != "$expected_prefix" ]]; then
      printf 'Admin asset digest mismatch for %s: expected sha256 prefix %s, got %s\n' \
        "$asset_path" "$expected_prefix" "$actual_prefix" >&2
      exit 1
    fi
  fi
}

verify_expected_bytes() {
  local rel_path=$1
  local downloaded_file=$2
  if [[ -n "$ADMIN_ASSET_EXPECTED_DIR" ]]; then
    local expected_file="$ADMIN_ASSET_EXPECTED_DIR/$rel_path"
    if [[ ! -f "$expected_file" ]]; then
      printf 'Missing expected admin asset reference file: %s\n' "$expected_file" >&2
      exit 1
    fi
    if ! cmp --silent "$expected_file" "$downloaded_file"; then
      printf 'Served admin asset bytes differ from expected reference: %s\n' "$rel_path" >&2
      exit 1
    fi
  fi
}

work_dir=$(mktemp -d)
cleanup() {
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

curl --silent --show-error --fail --output "$work_dir/manifest.json" \
  "$base_url/assets/admin/manifest.json"
verify_expected_bytes "manifest.json" "$work_dir/manifest.json"

entry_asset=$(jq -er '."index.html".file | strings | select(length > 0)' "$work_dir/manifest.json")
case "$entry_asset" in
  /*|*\\*|*..*) echo 'Unsafe admin entry asset path.' >&2; exit 1 ;;
esac
curl --silent --show-error --fail --output "$work_dir/entry.js" \
  "$base_url/assets/admin/$entry_asset"
test -s "$work_dir/entry.js"
verify_asset_digest_filename "$entry_asset" "$work_dir/entry.js"
verify_expected_bytes "$entry_asset" "$work_dir/entry.js"

mapfile -t style_assets < <(jq -er '."index.html".css[] | strings | select(length > 0)' "$work_dir/manifest.json")
test "${#style_assets[@]}" -gt 0
style_idx=0
for style_asset in "${style_assets[@]}"; do
  case "$style_asset" in
    /*|*\\*|*..*) echo 'Unsafe admin stylesheet asset path.' >&2; exit 1 ;;
  esac
  style_idx=$((style_idx + 1))
  style_out="$work_dir/entry-${style_idx}.css"
  curl --silent --show-error --fail --output "$style_out" \
    "$base_url/assets/admin/$style_asset"
  test -s "$style_out"
  verify_asset_digest_filename "$style_asset" "$style_out"
  verify_expected_bytes "$style_asset" "$style_out"
done

for locale in en-US zh-CN; do
  curl --silent --show-error --fail --output "$work_dir/$locale.js" \
    "$base_url/assets/admin/locales/$locale.js"
  test -s "$work_dir/$locale.js"
  verify_expected_bytes "locales/$locale.js" "$work_dir/$locale.js"
done

echo 'Admin asset smoke test passed.'
