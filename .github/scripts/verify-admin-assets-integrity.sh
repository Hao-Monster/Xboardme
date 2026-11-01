#!/usr/bin/env bash

set -euo pipefail

admin_dir="${1:-public/assets/admin}"
manifest_file="$admin_dir/manifest.json"

if [[ ! -f "$manifest_file" ]]; then
  printf 'Missing admin manifest: %s\n' "$manifest_file" >&2
  exit 1
fi

read_manifest_entry() {
  if command -v jq >/dev/null 2>&1; then
    jq -er '."index.html".file | strings | select(length > 0)' "$manifest_file"
  else
    node -e '
      const fs = require("fs");
      const m = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
      const file = m && m["index.html"] && typeof m["index.html"].file === "string" ? m["index.html"].file : "";
      if (!file) process.exit(1);
      process.stdout.write(file + "\n");
    ' "$manifest_file"
  fi
}

read_manifest_styles() {
  if command -v jq >/dev/null 2>&1; then
    jq -er '."index.html".css[] | strings | select(length > 0)' "$manifest_file"
  else
    node -e '
      const fs = require("fs");
      const m = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
      const css = m && m["index.html"] && Array.isArray(m["index.html"].css) ? m["index.html"].css : [];
      const valid = css.filter((item) => typeof item === "string" && item.length > 0);
      if (!valid.length) process.exit(1);
      process.stdout.write(valid.join("\n") + "\n");
    ' "$manifest_file"
  fi
}

check_asset() {
  local rel_path=$1
  case "$rel_path" in
    /*|*\\*|*..*) printf 'Unsafe admin asset path in manifest: %s\n' "$rel_path" >&2; exit 1 ;;
  esac
  local full_path="$admin_dir/$rel_path"
  if [[ ! -s "$full_path" ]]; then
    printf 'Missing or empty admin asset: %s\n' "$full_path" >&2
    exit 1
  fi
  local file_sha git_sha normalized_sha base_name expected_prefix eol_attr autocrlf_val
  file_sha=$(sha256sum "$full_path" | awk '{print $1}')
  base_name=$(basename -- "$rel_path")

  local admin_real_root admin_git_toplevel
  admin_real_root=$(cd "$admin_dir" && pwd -P)
  admin_git_toplevel=$(git -C "$admin_dir" rev-parse --show-toplevel 2>/dev/null || true)
  if [[ -n "$admin_git_toplevel" && "$admin_real_root" == "$admin_git_toplevel" ]]; then
    if git -C "$admin_dir" cat-file -e "HEAD:${rel_path}" 2>/dev/null; then
      git_sha=$(git -C "$admin_dir" show "HEAD:${rel_path}" | sha256sum | awk '{print $1}')
      eol_attr=$(git -C "$admin_dir" check-attr eol -- "$rel_path" | awk -F': ' '{print $NF}' | tr -d '\r')
      autocrlf_val=$(git -C "$admin_dir" config --get core.autocrlf 2>/dev/null | tr '[:upper:]' '[:lower:]' | tr -d '\r' || true)

      if [[ "$base_name" =~ ^.+[-]([0-9a-f]{10})\.(js|css)$ || "$eol_attr" == "lf" || "$autocrlf_val" != "true" ]]; then
        if [[ "$file_sha" != "$git_sha" ]]; then
          printf 'Admin asset working-tree bytes differ from Git HEAD blob (%s): worktree=%s git=%s\n' \
            "$rel_path" "$file_sha" "$git_sha" >&2
          exit 1
        fi
      else
        normalized_sha=$(tr -d '\r' < "$full_path" | sha256sum | awk '{print $1}')
        if [[ "$normalized_sha" != "$git_sha" ]]; then
          printf 'Admin asset normalized content differs from Git HEAD blob (%s): normalized=%s git=%s\n' \
            "$rel_path" "$normalized_sha" "$git_sha" >&2
          exit 1
        fi
      fi

      if [[ "$base_name" =~ ^.+[-]([0-9a-f]{10})\.(js|css)$ ]]; then
        expected_prefix="${BASH_REMATCH[1]}"
        if [[ "${git_sha:0:10}" != "$expected_prefix" ]]; then
          printf 'Admin asset Git blob digest mismatch (%s): expected sha256 prefix %s, got %s\n' \
            "$rel_path" "$expected_prefix" "${git_sha:0:10}" >&2
          exit 1
        fi
      fi
    fi
  fi

  if [[ "$base_name" =~ ^.+[-]([0-9a-f]{10})\.(js|css)$ ]]; then
    expected_prefix="${BASH_REMATCH[1]}"
    if [[ "${file_sha:0:10}" != "$expected_prefix" ]]; then
      printf 'Admin asset digest mismatch (%s): expected sha256 prefix %s, got %s\n' \
        "$rel_path" "$expected_prefix" "${file_sha:0:10}" >&2
      exit 1
    fi
  fi
}

entry_asset=$(read_manifest_entry | tr -d '\r')
check_asset "$entry_asset"

mapfile -t style_assets < <(read_manifest_styles | tr -d '\r')
test "${#style_assets[@]}" -gt 0
for style_asset in "${style_assets[@]}"; do
  check_asset "$style_asset"
done

for locale in en-US zh-CN; do
  check_asset "locales/$locale.js"
done

echo 'Admin asset manifest and digest integrity verified.'
