#!/usr/bin/env bash
set -euo pipefail

: "${DISTRIBUTOR_CSS_URL:?DISTRIBUTOR_CSS_URL is required}"
: "${DISTRIBUTOR_JS_URL:?DISTRIBUTOR_JS_URL is required}"
: "${EXPECTED_ASSET_VERSION:?EXPECTED_ASSET_VERSION is required}"

if [[ ! "$EXPECTED_ASSET_VERSION" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'MOBILE_ASSET_SMOKE=FAIL invalid_release_version' >&2
  exit 1
fi
asset_url_pattern='^https?://[A-Za-z0-9._~:/?%+&=-]+$'
for asset_url in "$DISTRIBUTOR_CSS_URL" "$DISTRIBUTOR_JS_URL"; do
  if [[ ! "$asset_url" =~ $asset_url_pattern ]] || [[ "$asset_url" != *"?v=$EXPECTED_ASSET_VERSION" ]]; then
    echo 'MOBILE_ASSET_SMOKE=FAIL invalid_asset_url' >&2
    exit 1
  fi
done

browser=${CHROME_BIN:-}
if [[ -n "$browser" && ! -x "$browser" ]]; then
  echo 'MOBILE_ASSET_SMOKE=FAIL configured_browser_unavailable' >&2
  exit 1
fi
if [[ -z "$browser" ]]; then
  for candidate in google-chrome google-chrome-stable chromium chromium-browser; do
    if command -v "$candidate" >/dev/null 2>&1; then
      browser=$(command -v "$candidate")
      break
    fi
  done
fi
if [[ -z "$browser" ]]; then
  echo 'MOBILE_ASSET_SMOKE=FAIL browser_unavailable' >&2
  exit 1
fi
command -v python3 >/dev/null 2>&1 || {
  echo 'MOBILE_ASSET_SMOKE=FAIL python_unavailable' >&2
  exit 1
}

work_dir=$(mktemp -d)
fixture_server_pid=''
cleanup() {
  if [[ -n "$fixture_server_pid" ]]; then
    kill "$fixture_server_pid" >/dev/null 2>&1 || true
    wait "$fixture_server_pid" 2>/dev/null || true
  fi
  rm -rf -- "$work_dir"
}
trap cleanup EXIT
fixture="$work_dir/mobile-order-smoke.html"

cat > "$fixture" <<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="$DISTRIBUTOR_CSS_URL">
</head>
<body>
  <div id="app"></div>
  <pre id="mobile-smoke-result">MOBILE_ASSET_SMOKE=PENDING</pre>
  <script>
    window.routerBase = '/';
    window.settings = { title: 'XBoard release smoke' };
    window.location.hash = '#/order';
    window.localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer release-smoke', expire: null }));
    const releaseOrder = {
      id: 70001,
      trade_no: 'RELEASE-SMOKE-ORDER-70001',
      type: 1,
      settlement_status: 0,
      subscription_trade_no: 'RELEASE-SMOKE-ORDER-70001',
      is_subscription_origin: true,
      original_trade_no: null,
      customer_name: '移动端验收用户',
      created_at: 1787142600,
      total_amount: 0,
      period: 'month_price',
      remark: '发布资源完整性验收',
      can_view_subscription_qr: true,
      can_renew: true,
      hwid_enabled: true,
      bound_devices: [],
      plan: { id: 1, name: '移动端验收套餐', month_price: 1000 },
      subscription_entitlement: {
        plan_name: '移动端验收套餐',
        transfer_enable: 107374182400,
        used_traffic: 0,
        remaining_traffic: 107374182400,
        expired_at: 1818678600,
        speed_limit: 0,
        device_limit: 0
      }
    };
    window.fetch = async function (input) {
      const url = String(input);
      const data = url.includes('/user/info')
        ? { email: 'release-smoke@example.invalid', distributor_name: 'Release Smoke', is_distributor: true }
        : url.includes('/user/order/fetch') ? [releaseOrder] : [];
      return new Response(JSON.stringify({ status: 'success', data }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' }
      });
    };

    const startedAt = Date.now();
    const verifier = window.setInterval(function () {
      const row = document.querySelector('.dist-origin-order-row');
      if (!row && Date.now() - startedAt < 6000) return;
      window.clearInterval(verifier);

      const result = document.getElementById('mobile-smoke-result');
      const table = document.querySelector('.dist-orders-table');
      const heading = document.querySelector('.dist-orders-table thead');
      const settlement = document.querySelector('.dist-order-settlement');
      const actions = Array.from(document.querySelectorAll('.dist-order-actions button'));
      const failures = [];
      if (!window.matchMedia('(max-width:640px), (max-width:900px) and (hover:none) and (pointer:coarse)').matches) failures.push('mobile_media_query');
      if (!row) failures.push('mobile_order_row');
      if (!table) failures.push('orders_table');
      if (!heading || getComputedStyle(heading).position !== 'absolute') failures.push('desktop_heading_visible');
      if (!row || getComputedStyle(row).display !== 'grid') failures.push('card_grid');
      if (!settlement || settlement.getBoundingClientRect().width < 1) failures.push('settlement_status');
      if (actions.length !== 3) failures.push('action_count');
      if (!document.querySelector('[data-subscription-qr]')) failures.push('data-subscription-qr');
      if (!document.querySelector('[data-entitlement-toggle]')) failures.push('data-entitlement-toggle');
      if (!document.querySelector('[data-renew]')) failures.push('data-renew');
      if (actions.some((button) => button.getBoundingClientRect().height < 44)) failures.push('touch_target');
      if (row && row.scrollWidth > row.clientWidth + 1) failures.push('card_overflow');
      if (document.documentElement.scrollWidth > window.innerWidth + 1) failures.push('page_overflow');
      result.textContent = failures.length
        ? 'MOBILE_ASSET_SMOKE=FAIL ' + failures.join(',')
        : 'MOBILE_ASSET_SMOKE=PASS';
    }, 100);
  </script>
  <script src="$DISTRIBUTOR_JS_URL"></script>
</body>
</html>
HTML

python3 -m http.server 17002 --bind 127.0.0.1 --directory "$work_dir" \
  >"$work_dir/http-server.log" 2>&1 &
fixture_server_pid=$!
fixture_ready=false
for attempt in $(seq 1 10); do
  if curl --silent --show-error --fail --output /dev/null \
    'http://127.0.0.1:17002/mobile-order-smoke.html' 2>/dev/null; then
    fixture_ready=true
    break
  fi
  sleep 1
done
if [[ "$fixture_ready" != true ]]; then
  cat "$work_dir/http-server.log" >&2
  echo 'MOBILE_ASSET_SMOKE=FAIL fixture_server_unavailable' >&2
  exit 1
fi

browser_output=$(
  "$browser" \
    --headless=new \
    --disable-gpu \
    --no-sandbox \
    --no-proxy-server \
    --user-data-dir="$work_dir/chrome-profile" \
    --window-size=412,924 \
    --virtual-time-budget=8000 \
    --dump-dom 'http://127.0.0.1:17002/mobile-order-smoke.html' 2>&1
)

if ! grep -Fq 'MOBILE_ASSET_SMOKE=PASS' <<< "$browser_output"; then
  printf '%s\n' "$browser_output" >&2
  echo 'MOBILE_ASSET_SMOKE=FAIL browser_assertion' >&2
  exit 1
fi

echo 'MOBILE_ASSET_SMOKE=PASS'
