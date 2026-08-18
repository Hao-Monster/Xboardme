#!/usr/bin/env bash

set -euo pipefail

base_url="${1:-http://127.0.0.1:17001}"
case "$base_url" in
  http://127.0.0.1:[0-9]*) ;;
  *) echo 'Admin asset smoke URL must use a loopback HTTP endpoint.' >&2; exit 1 ;;
esac

work_dir=$(mktemp -d)
cleanup() {
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

curl --silent --show-error --fail --output "$work_dir/manifest.json" \
  "$base_url/assets/admin/manifest.json"

entry_asset=$(jq -er '."index.html".file | strings | select(length > 0)' "$work_dir/manifest.json")
case "$entry_asset" in
  /*|*\\*|*..*) echo 'Unsafe admin entry asset path.' >&2; exit 1 ;;
esac
curl --silent --show-error --fail --output "$work_dir/entry.js" \
  "$base_url/assets/admin/$entry_asset"
test -s "$work_dir/entry.js"

mapfile -t style_assets < <(jq -er '."index.html".css[] | strings | select(length > 0)' "$work_dir/manifest.json")
test "${#style_assets[@]}" -gt 0
for style_asset in "${style_assets[@]}"; do
  case "$style_asset" in
    /*|*\\*|*..*) echo 'Unsafe admin stylesheet asset path.' >&2; exit 1 ;;
  esac
  curl --silent --show-error --fail --output "$work_dir/entry.css" \
    "$base_url/assets/admin/$style_asset"
  test -s "$work_dir/entry.css"
done

for locale in en-US zh-CN; do
  curl --silent --show-error --fail --output "$work_dir/$locale.js" \
    "$base_url/assets/admin/locales/$locale.js"
  test -s "$work_dir/$locale.js"
done

echo 'Admin asset smoke test passed.'
