#!/usr/bin/env bash

set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST is required}"
: "${DEPLOY_PORT:?DEPLOY_PORT is required}"
: "${DEPLOY_USER:?DEPLOY_USER is required}"
: "${SSHPASS:?SSHPASS is required}"
: "${TARGET_PORT:=active}"
: "${EXPECTED_ADMIN_ASSET_VERSION:=}"

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ssh_with_password="$script_dir/ssh-with-password.sh"

if [[ "$TARGET_PORT" == active ]]; then
  TARGET_PORT=$(bash "$ssh_with_password" -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" \
    "grep -RhoE --include='*.conf' --include='Caddyfile' 'reverse_proxy[[:space:]]+127\\.0\\.0\\.1:[0-9]{4,5}' /etc/caddy 2>/dev/null | awk '{print \$2}' | sort -u | sed -n 's/.*://p'")
fi
[[ "$TARGET_PORT" =~ ^[0-9]+$ ]]
((TARGET_PORT >= 1 && TARGET_PORT <= 65535))

bash "$ssh_with_password" -N \
  -p "$DEPLOY_PORT" \
  -o ExitOnForwardFailure=yes \
  -L "17001:127.0.0.1:$TARGET_PORT" \
  "$DEPLOY_USER@$DEPLOY_HOST" &
tunnel_pid=$!
cleanup_tunnel() {
  kill "$tunnel_pid" >/dev/null 2>&1 || true
  wait "$tunnel_pid" 2>/dev/null || true
}
trap cleanup_tunnel EXIT

tunnel_ready=0
for attempt in $(seq 1 10); do
  if curl --silent --show-error \
    --output /dev/null --connect-timeout 2 --max-time 3 \
    'http://127.0.0.1:17001/'; then
    tunnel_ready=1
    break
  fi
  sleep 2
done
test "$tunnel_ready" = '1'

work_dir=$(mktemp -d)
cleanup_all() {
  rm -rf -- "$work_dir"
  cleanup_tunnel
}
trap cleanup_all EXIT

curl --silent --show-error --fail --output "$work_dir/dashboard.html" \
  'http://127.0.0.1:17001/'
grep -q 'distributor-message-guard.js' "$work_dir/dashboard.html"

curl --silent --show-error --fail --output "$work_dir/admin-distributor.js" \
  'http://127.0.0.1:17001/assets/admin-distributor.js'
grep -q '下单时间' "$work_dir/admin-distributor.js"

expected_admin_dir=''
if [[ -n "$EXPECTED_ADMIN_ASSET_VERSION" ]]; then
  [[ "$EXPECTED_ADMIN_ASSET_VERSION" =~ ^[a-f0-9]{40}$ ]]
  git show "$EXPECTED_ADMIN_ASSET_VERSION:public/assets/admin-distributor.js" \
    > "$work_dir/expected-admin-distributor.js"
  cmp --silent "$work_dir/expected-admin-distributor.js" "$work_dir/admin-distributor.js"

  admin_sub_sha=$(git ls-tree "$EXPECTED_ADMIN_ASSET_VERSION" public/assets/admin | awk '$2 == "commit" {print $3}')
  if [[ ! "$admin_sub_sha" =~ ^[a-f0-9]{40}$ ]]; then
    echo "Missing public/assets/admin submodule gitlink on commit $EXPECTED_ADMIN_ASSET_VERSION" >&2
    exit 1
  fi
  admin_git_dir=''
  if [[ -d public/assets/admin ]] && [[ "$(cd public/assets/admin && pwd -P)" == "$(git -C public/assets/admin rev-parse --show-toplevel 2>/dev/null || true)" ]]; then
    admin_git_dir='public/assets/admin'
    if ! git -C "$admin_git_dir" cat-file -e "${admin_sub_sha}^{commit}" 2>/dev/null; then
      git -C "$admin_git_dir" fetch --depth=1 origin "$admin_sub_sha" >/dev/null
    fi
  else
    sub_url=$(git show "$EXPECTED_ADMIN_ASSET_VERSION:.gitmodules" | git config --file - --get submodule.public/assets/admin.url)
    [[ -n "$sub_url" ]]
    admin_git_dir="$work_dir/admin-sub-repo"
    git init --quiet "$admin_git_dir"
    auth_header_cfg=$(git config --local --get http.https://github.com/.extraheader 2>/dev/null || true)
    if [[ -n "$auth_header_cfg" ]]; then
      git -C "$admin_git_dir" config --local http.https://github.com/.extraheader "$auth_header_cfg"
    fi
    git -C "$admin_git_dir" fetch --depth=1 "$sub_url" "$admin_sub_sha" >/dev/null
  fi
  git -C "$admin_git_dir" cat-file -e "${admin_sub_sha}^{commit}"
  expected_admin_dir="$work_dir/expected-admin"
  mkdir -p "$expected_admin_dir/locales"
  git -C "$admin_git_dir" show "${admin_sub_sha}:manifest.json" > "$expected_admin_dir/manifest.json"
  expected_entry=$(jq -er '."index.html".file | strings | select(length > 0)' "$expected_admin_dir/manifest.json")
  mkdir -p "$expected_admin_dir/$(dirname -- "$expected_entry")"
  git -C "$admin_git_dir" show "${admin_sub_sha}:${expected_entry}" > "$expected_admin_dir/$expected_entry"
  mapfile -t expected_styles < <(jq -er '."index.html".css[] | strings | select(length > 0)' "$expected_admin_dir/manifest.json")
  for expected_style in "${expected_styles[@]}"; do
    mkdir -p "$expected_admin_dir/$(dirname -- "$expected_style")"
    git -C "$admin_git_dir" show "${admin_sub_sha}:${expected_style}" > "$expected_admin_dir/$expected_style"
  done
  for locale in en-US zh-CN; do
    git -C "$admin_git_dir" show "${admin_sub_sha}:locales/${locale}.js" > "$expected_admin_dir/locales/${locale}.js"
  done
else
  grep -q 'native-dist-settlement-month' "$work_dir/admin-distributor.js"
  grep -q 'expected_total_amount' "$work_dir/admin-distributor.js"
fi

ADMIN_ASSET_EXPECTED_DIR="$expected_admin_dir" bash .github/scripts/smoke-admin-assets.sh

echo 'Remote admin asset smoke test passed.'
