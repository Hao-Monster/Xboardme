#!/usr/bin/env bash
set -euo pipefail
# Authentication responses and temporary credentials must never enter xtrace or
# the checkout/artifacts. Login still updates the dedicated account's last login
# and invokes configured hooks; operational review of those hooks is required.
set +x
umask 077

: "${DEPLOY_HOST:?DEPLOY_HOST is required}"
: "${DEPLOY_PORT:?DEPLOY_PORT is required}"
: "${DEPLOY_USER:?DEPLOY_USER is required}"
: "${SSHPASS:?SSHPASS is required}"
: "${DISTRIBUTOR_EMAIL:?DISTRIBUTOR_EMAIL is required}"
: "${DISTRIBUTOR_PASSWORD:?DISTRIBUTOR_PASSWORD is required}"
: "${TARGET_PORT:=}"
: "${SMOKE_VALIDATION_MODE:=release}"
: "${SMOKE_VERIFY_PUBLIC_ASSETS:=false}"
: "${SMOKE_VERIFY_PUBLIC_ROUTE:=true}"
: "${V2_RELEASE_ID:=}"
: "${V2_EXPECTED_ASSET_VERSION:=}"

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ssh_with_password="$script_dir/ssh-with-password.sh"

case "$SMOKE_VALIDATION_MODE" in
  release|rollback) ;;
  *) echo 'Invalid smoke validation mode.' >&2; exit 1 ;;
esac
case "$SMOKE_VERIFY_PUBLIC_ASSETS" in
  true|false) ;;
  *) echo 'Invalid public asset validation flag.' >&2; exit 1 ;;
esac
case "$SMOKE_VERIFY_PUBLIC_ROUTE" in
  true|false) ;;
  *) echo 'Invalid public route validation flag.' >&2; exit 1 ;;
esac
if [[ "$SMOKE_VERIFY_PUBLIC_ASSETS" == true && "$SMOKE_VERIFY_PUBLIC_ROUTE" != true ]]; then
  echo 'Public asset validation requires public route validation.' >&2
  exit 1
fi
if [[ "$SMOKE_VALIDATION_MODE" == release ]] && [[ ! "${EXPECTED_ASSET_VERSION:-}" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'Expected asset version must be the full release commit SHA.' >&2
  exit 1
fi

if [[ -n "$V2_RELEASE_ID" ]]; then
  [[ "$V2_RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]] || {
    echo 'Invalid V2 release identifier.' >&2
    exit 1
  }
  [[ "$SMOKE_VALIDATION_MODE" == release ]] || {
    echo 'V2 port resolution is only valid for release smoke tests.' >&2
    exit 1
  }
  [[ "$V2_EXPECTED_ASSET_VERSION" =~ ^[a-f0-9]{40}$ ]] || {
    echo 'Invalid V2 expected asset version.' >&2
    exit 1
  }
  [[ "${GITHUB_SHA:-}" =~ ^[a-f0-9]{40}$ ]] || {
    echo 'Invalid current workflow commit.' >&2
    exit 1
  }
  git cat-file -e "$V2_EXPECTED_ASSET_VERSION^{commit}" 2>/dev/null || {
    echo 'V2 candidate commit is unavailable in the trusted production history.' >&2
    exit 1
  }
  git merge-base --is-ancestor "$V2_EXPECTED_ASSET_VERSION" "$GITHUB_SHA" || {
    echo 'V2 candidate commit is not an ancestor of the current production workflow.' >&2
    exit 1
  }
  EXPECTED_ASSET_VERSION=$V2_EXPECTED_ASSET_VERSION
  TARGET_PORT=$(
    cat "$script_dir/release-state.sh" \
        "$script_dir/v2-low-memory-common.sh" \
        "$script_dir/resolve-xboard-v2-port.sh" |
      bash "$ssh_with_password" -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" \
        "RELEASE_ID='$V2_RELEASE_ID' EXPECTED_RELEASE_SHA='$EXPECTED_ASSET_VERSION' bash -s"
  )
fi

[[ "$TARGET_PORT" =~ ^[0-9]+$ ]] || {
  echo 'Target port must be numeric.' >&2
  exit 1
}
((TARGET_PORT >= 1 && TARGET_PORT <= 65535))

command -v setsid >/dev/null
command -v timeout >/dev/null
tunnel_timeout_seconds=${SMOKE_TIMEOUT_SECONDS:-300}
[[ "$tunnel_timeout_seconds" =~ ^[0-9]+$ ]] && \
  ((tunnel_timeout_seconds >= 1 && tunnel_timeout_seconds <= 900)) || {
    echo 'Invalid smoke tunnel lifetime.' >&2
    exit 2
  }
tunnel_timeout_seconds=$((tunnel_timeout_seconds + 15))
asset_work_dir=$(mktemp -d)
tunnel_pid=''
login_started=false
login_started_at=''
login_status=''
smoke_passed=false
auth_header="$asset_work_dir/auth.header"
login_response="$asset_work_dir/login.json"

smoke_api_curl() {
  # Never inherit a curlrc proxy/redirect/debug setting or send this token to a
  # public-origin fallback. Only the original loopback tunnel is authenticated.
  [[ -n "$tunnel_pid" ]] && kill -0 "$tunnel_pid" 2>/dev/null || return 1
  curl --disable --noproxy '*' --silent --show-error \
    --connect-timeout 3 --max-time 30 "$@"
}

capture_smoke_session() {
  local token
  [[ -s "$login_response" ]] || return 1
  token=$(jq -er '.data.auth_data | strings | select(test("^Bearer [A-Za-z0-9._~-]{40,}$"))' \
    "$login_response" 2>/dev/null) || return 1
  printf 'Authorization: %s\n' "$token" > "$auth_header"
}

cleanup_smoke() {
  local original_status=$1
  local cleanup_status=NOT_CREATED
  local reason=''
  local revoke_status=''
  local check_status=''
  local workflow_run=${GITHUB_RUN_ID:-local}
  local workflow_attempt=${GITHUB_RUN_ATTEMPT:-local}
  trap - EXIT
  trap '' HUP INT TERM
  set +e

  # Capture a token even if a successful login is followed by invalid role data,
  # a later assertion failure, or interrupted response-status processing.
  if [[ -s "$auth_header" ]] || capture_smoke_session; then
    cleanup_status=INCOMPLETE
    reason=revoke_request_failed
    if revoke_status=$(smoke_api_curl --max-time 5 \
      --output "$asset_work_dir/logout.json" --write-out '%{http_code}' \
      --request POST --header "@$auth_header" \
      'http://127.0.0.1:17001/api/v1/user/logout'); then
      if [[ "$revoke_status" == 200 ]] && \
        jq -e '(.status == "success") and (.data == true)' "$asset_work_dir/logout.json" >/dev/null 2>&1; then
        reason=revocation_not_verified
        if check_status=$(smoke_api_curl --max-time 5 \
          --output "$asset_work_dir/logged-out.json" --write-out '%{http_code}' \
          --header "@$auth_header" \
          'http://127.0.0.1:17001/api/v1/user/checkLogin') && \
          [[ "$check_status" == 401 || "$check_status" == 403 ]]; then
          cleanup_status=PASS
          reason=''
        fi
      fi
    fi
  elif [[ "$login_started" == true ]]; then
    case "$login_status" in
      400|401|403|422|429) ;;
      *) cleanup_status=INCOMPLETE; reason=login_token_unavailable ;;
    esac
  fi
  printf 'DISTRIBUTOR_SMOKE_SESSION_CLEANUP=%s%s\n' "$cleanup_status" "${reason:+ reason=$reason}"
  if [[ "$cleanup_status" == INCOMPLETE ]]; then
    [[ "$workflow_run" =~ ^[0-9]+$ ]] || workflow_run=local
    [[ "$workflow_attempt" =~ ^[0-9]+$ ]] || workflow_attempt=local
    printf 'DISTRIBUTOR_SMOKE_SESSION_RECONCILE run_id=%s attempt=%s login_started_at=%s original_status=%s\n' \
      "$workflow_run" "$workflow_attempt" "${login_started_at:-not_started}" "$original_status" >&2
  fi
  if [[ "$cleanup_status" == INCOMPLETE && "$original_status" == 0 ]]; then
    original_status=1
  fi
  # A lost connection, SIGKILL, or lost runner cannot guarantee remote revocation.
  # Do not compensate by logging out other sessions or revealing the token.
  if [[ -n "$tunnel_pid" ]]; then
    kill "$tunnel_pid" >/dev/null 2>&1 || true
    # The isolated timeout supervisor escalates its entire process group after
    # two seconds, including a transport which ignores TERM.
    wait "$tunnel_pid" 2>/dev/null || true
  fi
  if ! rm -rf -- "$asset_work_dir"; then
    echo 'DISTRIBUTOR_SMOKE_PRIVATE_FILES_CLEANUP=INCOMPLETE' >&2
    [[ "$original_status" != 0 ]] || original_status=1
  fi
  if [[ "$original_status" == 0 && "$smoke_passed" == true ]]; then
    echo 'Distributor production smoke test passed.'
  fi
  exit "$original_status"
}
trap 'cleanup_smoke $?' EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
trap 'exit 129' HUP

# Keep the tunnel alive while the bounded runner sends TERM to the smoke process
# group. EXIT revokes the new session before it stops this isolated transport.
# Its own deadline also bounds a leftover tunnel if the parent is forcibly killed.
setsid timeout --signal=TERM --kill-after=2s "${tunnel_timeout_seconds}s" \
  bash "$ssh_with_password" -N \
  -p "$DEPLOY_PORT" \
  -o ExitOnForwardFailure=yes \
  -L "17001:127.0.0.1:$TARGET_PORT" \
  "$DEPLOY_USER@$DEPLOY_HOST" &
tunnel_pid=$!

required_theme_assets=(
  auth-session.js
  client-center.css
  client-center.js
  distributor-message-guard.js
  distributor.css
  distributor.js
  umi.js
)

assert_release_dashboard() {
  local dashboard_file=$1
  grep -Fq "<meta name=\"xboard-release\" content=\"$EXPECTED_ASSET_VERSION\"" "$dashboard_file"
  if grep -Eqi 'xboard-release[^>]+unknown|\?v=[^"[:space:]]*unknown' "$dashboard_file"; then
    echo 'Dashboard contains an unknown release identifier.' >&2
    return 1
  fi
  for asset in "${required_theme_assets[@]}"; do
    grep -Fq "/theme/Xboard/assets/$asset?v=$EXPECTED_ASSET_VERSION" "$dashboard_file"
  done
}

verify_release_assets() {
  local origin=${1%/}
  local label=$2
  local manifest="$asset_work_dir/$label-release-manifest.json"
  local downloaded
  local local_path
  local local_hash
  local local_bytes
  local asset_version=$EXPECTED_ASSET_VERSION
  local expected_subscription_names=false
  local expected_distributor="$asset_work_dir/$label-expected-distributor-contract.js"
  git show "$EXPECTED_ASSET_VERSION:theme/Xboard/assets/distributor.js" > "$expected_distributor"
  if grep -Fq 'subscription_name' "$expected_distributor"; then
    expected_subscription_names=true
  fi

  curl --silent --show-error --fail --location \
    --header 'Cache-Control: no-cache' \
    --output "$manifest" \
    "$origin/theme/Xboard/assets/release-manifest.json?v=$EXPECTED_ASSET_VERSION"
  jq -e --arg revision "$EXPECTED_ASSET_VERSION" \
    '(.schema == 1) and (.revision == $revision) and (.assets | type == "object")' \
    "$manifest" >/dev/null

  for asset in "${required_theme_assets[@]}"; do
    if [[ -n "$V2_RELEASE_ID" ]]; then
      local_path="$asset_work_dir/expected-$asset"
      git show "$V2_EXPECTED_ASSET_VERSION:theme/Xboard/assets/$asset" > "$local_path"
    else
      local_path="$GITHUB_WORKSPACE/theme/Xboard/assets/$asset"
    fi
    test -s "$local_path"
    local_hash=$(sha256sum "$local_path" | awk '{print $1}')
    local_bytes=$(wc -c < "$local_path" | tr -d '[:space:]')
    jq -e --arg asset "$asset" --arg hash "$local_hash" --argjson bytes "$local_bytes" \
      '.assets[$asset].sha256 == $hash and .assets[$asset].bytes == $bytes' \
      "$manifest" >/dev/null

    downloaded="$asset_work_dir/$label-$asset"
    curl --silent --show-error --fail --location \
      --header 'Cache-Control: no-cache' \
      --output "$downloaded" \
      "$origin/theme/Xboard/assets/$asset?v=$EXPECTED_ASSET_VERSION"
    test "$(sha256sum "$downloaded" | awk '{print $1}')" = "$local_hash"
  done

  DISTRIBUTOR_CSS_URL="$origin/theme/Xboard/assets/distributor.css?v=$asset_version" \
  DISTRIBUTOR_JS_URL="$origin/theme/Xboard/assets/distributor.js?v=$asset_version" \
  EXPECTED_ASSET_VERSION="$asset_version" \
  EXPECTED_SUBSCRIPTION_NAMES="$expected_subscription_names" \
    bash "$script_dir/smoke-distributor-mobile-browser.sh"
}

tunnel_ready=0
for _ in $(seq 1 10); do
  if curl --silent --show-error \
    --output /dev/null --connect-timeout 2 --max-time 3 \
    'http://127.0.0.1:17001/'; then
    tunnel_ready=1
    break
  fi
  sleep 2
done
test "$tunnel_ready" = '1'

curl --silent --show-error --fail --output dashboard.html \
  'http://127.0.0.1:17001/'
grep -q 'distributor-message-guard.js' dashboard.html
if [[ "$SMOKE_VALIDATION_MODE" == release ]]; then
  assert_release_dashboard dashboard.html
  verify_release_assets 'http://127.0.0.1:17001' candidate
fi

printf '%s' "$DISTRIBUTOR_EMAIL" > "$asset_work_dir/login.email"
printf '%s' "$DISTRIBUTOR_PASSWORD" > "$asset_work_dir/login.password"
login_started=true
login_started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
login_status=$(smoke_api_curl \
  --output "$login_response" --write-out '%{http_code}' \
  --request POST 'http://127.0.0.1:17001/api/v1/passport/auth/login' \
  --data-urlencode "email@$asset_work_dir/login.email" \
  --data-urlencode "password@$asset_work_dir/login.password")
capture_smoke_session || true
test "$login_status" = '200'
jq -e '(.status == "success") and (.data.is_distributor == true) and (.data.auth_data | type == "string")' "$login_response" >/dev/null
test -s "$auth_header"

info_status=$(smoke_api_curl \
  --output "$asset_work_dir/info.json" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/info')
test "$info_status" = '200'
jq -e '(.status == "success") and (.data.is_distributor == true) and (.data | has("distributor_name"))' "$asset_work_dir/info.json" >/dev/null

plans_status=$(smoke_api_curl \
  --output "$asset_work_dir/plans.json" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/plan/fetch')
test "$plans_status" = '200'

orders_status=$(smoke_api_curl \
  --output "$asset_work_dir/orders.json" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/order/fetch')
test "$orders_status" = '200'
jq -e 'all(.data[]; has("remark") and has("order_type_label") and has("subscription_trade_no"))' "$asset_work_dir/orders.json" >/dev/null

knowledge_status=$(smoke_api_curl \
  --output "$asset_work_dir/knowledge.json" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/knowledge/fetch?language=zh-CN')
test "$knowledge_status" = '200'
jq -e '.status == "success"' "$asset_work_dir/knowledge.json" >/dev/null

clients_status=$(smoke_api_curl \
  --output "$asset_work_dir/clients.json" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/client-catalog')
test "$clients_status" = '200'
jq -e '((.data[0:4] | map(.id)) == ["karing", "happ", "clash-mi", "koalaclash"])
  and all(.data[]; all(.downloads[]; has("download_url") and has("cloud_url") and has("tutorial_url")))' "$asset_work_dir/clients.json" >/dev/null

curl --silent --show-error --fail --output admin-client-catalog.js \
  'http://127.0.0.1:17001/assets/admin-client-catalog.js'
grep -q '客户端管理' admin-client-catalog.js
curl --silent --show-error --fail --output admin-distributor.js \
  'http://127.0.0.1:17001/assets/admin-distributor.js'
grep -q '下单时间' admin-distributor.js
if [[ -n "$V2_RELEASE_ID" ]]; then
  git show "$V2_EXPECTED_ASSET_VERSION:public/assets/admin-distributor.js" \
    > "$asset_work_dir/expected-admin-distributor.js"
  cmp --silent "$asset_work_dir/expected-admin-distributor.js" admin-distributor.js
else
  grep -q 'native-dist-settlement-month' admin-distributor.js
  grep -q 'expected_total_amount' admin-distributor.js
fi
if [ "$SMOKE_VALIDATION_MODE" = 'release' ]; then
  curl --silent --show-error --fail --output admin-realtime-status.js \
    'http://127.0.0.1:17001/assets/admin-realtime-status.js'
  grep -q 'getRealtimeStats' admin-realtime-status.js
  curl --silent --show-error --fail --output admin-realtime-status.css \
    'http://127.0.0.1:17001/assets/admin-realtime-status.css'
  grep -q 'xboard-realtime-grid' admin-realtime-status.css
  curl --silent --show-error --fail --output admin-node-activation-schedule.js \
    'http://127.0.0.1:17001/assets/admin-node-activation-schedule.js'
  grep -q 'dropActivationSchedule' admin-node-activation-schedule.js
  grep -q "schedule_type: 'daily'" admin-node-activation-schedule.js
  grep -q 'Asia/Singapore' admin-node-activation-schedule.js
  curl --silent --show-error --fail --output admin-node-activation-schedule.css \
    'http://127.0.0.1:17001/assets/admin-node-activation-schedule.css'
  grep -q 'xboard-node-schedule-dialog' admin-node-activation-schedule.css
fi

bash .github/scripts/smoke-admin-assets.sh

asset_version_query=''
if [[ "$SMOKE_VALIDATION_MODE" == release ]]; then
  asset_version_query="?v=$EXPECTED_ASSET_VERSION"
fi
curl --silent --show-error --fail --output client-center.js \
  "http://127.0.0.1:17001/theme/Xboard/assets/client-center.js$asset_version_query"
grep -q '网盘下载' client-center.js
curl --silent --show-error --fail --output distributor.js \
  "http://127.0.0.1:17001/theme/Xboard/assets/distributor.js$asset_version_query"
grep -q '/user/order/renew' distributor.js
grep -q 'data-entitlement-toggle' distributor.js
grep -q 'orderTime' distributor.js
grep -q 'distributorAccountLabel' distributor.js
curl --silent --show-error --fail --output distributor-message-guard.js \
  "http://127.0.0.1:17001/theme/Xboard/assets/distributor-message-guard.js$asset_version_query"
grep -q 'DISTRIBUTOR_ACCESS_DENIED' distributor-message-guard.js

order_count=$(jq '.data | length' "$asset_work_dir/orders.json")
export_status=$(smoke_api_curl \
  --dump-header "$asset_work_dir/export.headers" \
  --output "$asset_work_dir/distributor-orders.xlsx" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/order/export')
if [ "$order_count" -gt 0 ]; then
  test "$export_status" = '200'
  grep -qi 'content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' "$asset_work_dir/export.headers"
  grep -qi 'content-disposition: attachment' "$asset_work_dir/export.headers"
  unzip -t "$asset_work_dir/distributor-orders.xlsx" >/dev/null
  unzip -p "$asset_work_dir/distributor-orders.xlsx" xl/worksheets/sheet1.xml > "$asset_work_dir/distributor-orders-sheet.xml"
  grep -q '备注' "$asset_work_dir/distributor-orders-sheet.xml"
else
  test "$export_status" = '422'
  jq -e '(.status == "fail") and (.message == "当前筛选条件下没有可导出的订单")' "$asset_work_dir/distributor-orders.xlsx" >/dev/null
fi

restricted_status=$(smoke_api_curl \
  --output "$asset_work_dir/restricted.json" --write-out '%{http_code}' \
  --header "@$auth_header" \
  'http://127.0.0.1:17001/api/v1/user/getSubscribe')
test "$restricted_status" = '403'
jq -e '.message == "分销商账号无权访问该功能"' "$asset_work_dir/restricted.json" >/dev/null

if [[ "$SMOKE_VERIFY_PUBLIC_ROUTE" == true ]]; then
  public_url=$(bash "$ssh_with_password" -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" \
    'bash -s' < .github/scripts/resolve-xboard-public-url.sh)
  case "$public_url" in
    http://*|https://*) ;;
    *) echo 'Invalid public application URL.' >&2; exit 1 ;;
  esac
  public_ready=0
  for _ in $(seq 1 6); do
    if curl --silent --show-error --fail --location --max-time 15 \
      --output /dev/null "$public_url/"; then
      public_ready=1
      break
    fi
    sleep 3
  done
  test "$public_ready" = '1'
  if [[ "$SMOKE_VALIDATION_MODE" == release && "$SMOKE_VERIFY_PUBLIC_ASSETS" == true ]]; then
    public_url=${public_url%/}
    curl --silent --show-error --fail --location \
      --header 'Cache-Control: no-cache' \
      --output "$asset_work_dir/public-dashboard.html" "$public_url/"
    assert_release_dashboard "$asset_work_dir/public-dashboard.html"
    verify_release_assets "$public_url" public
  fi
fi

smoke_passed=true
