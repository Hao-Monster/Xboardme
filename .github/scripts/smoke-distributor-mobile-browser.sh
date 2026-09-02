#!/usr/bin/env bash
set -euo pipefail

: "${DISTRIBUTOR_CSS_URL:?DISTRIBUTOR_CSS_URL is required}"
: "${DISTRIBUTOR_JS_URL:?DISTRIBUTOR_JS_URL is required}"
: "${EXPECTED_ASSET_VERSION:?EXPECTED_ASSET_VERSION is required}"
: "${BROWSER_SMOKE_TIMEOUT_SECONDS:=30}"

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
[[ "$BROWSER_SMOKE_TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] || {
  echo 'MOBILE_ASSET_SMOKE=FAIL invalid_browser_timeout' >&2
  exit 1
}
((BROWSER_SMOKE_TIMEOUT_SECONDS >= 1 && BROWSER_SMOKE_TIMEOUT_SECONDS <= 120)) || {
  echo 'MOBILE_ASSET_SMOKE=FAIL invalid_browser_timeout' >&2
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
      bound_devices: ['vivo V2227A ntqwnji2mzky', 'Pixel 10 secondary-hwid', 'iPhone 18 third-hwid', 'Galaxy S26 fourth-hwid'],
      bound_device_count: 4,
      used_traffic: 5368709120,
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
      if (url.includes('/user/order/fetch')) {
        return new Response(JSON.stringify({ total: 1, current_page: 1, per_page: 20, last_page: 1, data: [releaseOrder] }), {
          status: 200, headers: { 'Content-Type': 'application/json' }
        });
      }
      const data = url.includes('/user/info')
        ? { email: 'release-smoke@example.invalid', distributor_name: 'Release Smoke', is_distributor: true }
        : url.includes('/user/plan/fetch') ? [{
          id: 1, name: '移动端验收套餐', content: '套餐说明原文：客户活动价￥999 保持可见', transfer_enable: 100,
          speed_limit: 0, device_limit: 0, reset_traffic_method: null, capacity_limit: null,
          month_price: 1000, quarter_price: 2700, half_year_price: null, year_price: null,
          two_year_price: null, three_year_price: null, onetime_price: null
        }]
        : url.includes('/user/order/statistics') ? {
          range: { start_date: '2026-08-30', end_date: '2026-08-30', days: 1 },
          summary: { order_count: 1, total_amount: 1200 },
          daily: [{ date: '2026-08-30', order_count: 1, total_amount: 1200 }]
        } : [];
      return new Response(JSON.stringify({ status: 'success', data: data }), {
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
      const wrapper = document.querySelector('.dist-order-list');
      const table = document.querySelector('.dist-orders-table');
      const heading = document.querySelector('.dist-orders-table thead');
      const sequenceCell = row?.querySelector('.dist-order-sequence');
      const actionCell = row?.querySelector('.dist-order-action-cell');
      const orderIdentity = row?.querySelector('.dist-order-identity');
      const hiddenEntitlement = document.querySelector('.dist-entitlement-row[hidden]');
      const settlement = document.querySelector('.dist-order-settlement');
      const boundDevices = document.querySelector('.dist-order-bound-devices');
      const usedTraffic = document.querySelector('.dist-order-used-traffic');
      const actions = Array.from(document.querySelectorAll('.dist-order-actions button'));
      const failures = [];
      const mobile = window.innerWidth <= 640;
      if (mobile && !window.matchMedia('(max-width:640px), (max-width:900px) and (hover:none) and (pointer:coarse)').matches) failures.push('mobile_media_query');
      if (!row) failures.push('mobile_order_row');
      if (!wrapper) failures.push('order_table_wrapper');
      if (!table) failures.push('orders_table');
      if (mobile && (!heading || getComputedStyle(heading).position !== 'sticky')) failures.push('sticky_heading');
      if (mobile && (!row || getComputedStyle(row).display !== 'table-row')) failures.push('compact_table_row');
      if (mobile && (!wrapper || getComputedStyle(wrapper).overflowY === 'visible')) failures.push('mobile_order_overflow_container');
      if (mobile && (!wrapper || table.scrollWidth <= wrapper.clientWidth)) failures.push('horizontal_order_scroll');
      if (mobile && wrapper && orderIdentity) {
        const orderLeftBeforeScroll = orderIdentity.getBoundingClientRect().left;
        wrapper.scrollLeft = 240;
        if (wrapper.scrollLeft < 1 || orderIdentity.getBoundingClientRect().left >= orderLeftBeforeScroll) failures.push('horizontal_scroll_movement');
        wrapper.scrollLeft = 0;
      }
      if (!mobile && (!heading || getComputedStyle(heading).position === 'absolute')) failures.push('desktop_heading_hidden');
      if (!mobile && row && getComputedStyle(row).display === 'grid') failures.push('desktop_row_layout');
      if (!sequenceCell || getComputedStyle(sequenceCell).position !== 'sticky') failures.push('sticky_sequence');
      if (!actionCell || getComputedStyle(actionCell).position !== 'sticky') failures.push('sticky_actions');
      if (!orderIdentity || getComputedStyle(orderIdentity).position === 'sticky') failures.push('order_number_not_scrollable');
      if (!hiddenEntitlement || getComputedStyle(hiddenEntitlement).display !== 'none') failures.push('hidden_entitlement_visible');
      const settlementFilter = document.querySelector('#dist-order-filters #dist-order-settlement');
      if (!settlementFilter) failures.push('settlement_not_in_advanced_filters');
      if (document.querySelector('.dist-order-toolbar > label #dist-order-settlement')) failures.push('settlement_still_in_toolbar');
      if (!settlement || settlement.getBoundingClientRect().width < 1) failures.push('settlement_status');
      if (!boundDevices || boundDevices.getBoundingClientRect().width < 1 || !boundDevices.textContent.includes('vivo V2227A ntqwnji2mzky')) failures.push('bound_device_hwid');
      if (!document.querySelector('[data-device-toggle]')) failures.push('bound_device_expand');
      if (!usedTraffic || usedTraffic.getBoundingClientRect().width < 1 || !usedTraffic.textContent.includes('5 GB')) failures.push('used_traffic');
      if (actions.length !== 3) failures.push('action_count');
      if (!document.querySelector('[data-subscription-qr]')) failures.push('data-subscription-qr');
      if (!document.querySelector('[data-entitlement-toggle]')) failures.push('data-entitlement-toggle');
      if (!document.querySelector('[data-renew]')) failures.push('data-renew');
      if (actions.some((button) => button.getBoundingClientRect().height < 27)) failures.push('compact_action_height');
      if (document.querySelector('.dist-page-head')) failures.push('removed_order_heading');
      if (document.querySelector('.dist-order-insights')) failures.push('analytics_still_on_orders');
      if (!mobile) {
        if (wrapper && actionCell && actionCell.getBoundingClientRect().right > wrapper.getBoundingClientRect().right + 1) failures.push('actions_clipped');
      }
      if (document.documentElement.scrollWidth > window.innerWidth + 1) failures.push('order_page_overflow');

      window.location.hash = '#/overview';
      window.setTimeout(function () {
        const navItems = Array.from(document.querySelectorAll('.dist-sidebar nav button'));
        if (!document.querySelector('#dist-order-overview-title')) failures.push('overview_heading');
        if (!document.querySelector('.dist-order-summary-cards')) failures.push('summary_cards');
        if (document.querySelectorAll('.dist-chart').length !== 2) failures.push('trend_charts');
        if (navItems.length !== 6) failures.push('six_nav_items');
        if (mobile && navItems.some((button) => button.getBoundingClientRect().height < 44)) failures.push('nav_touch_target');
        if (document.documentElement.scrollWidth > window.innerWidth + 1) failures.push('overview_page_overflow');
        window.location.hash = '#/plan';
        window.setTimeout(function () {
          const toggle = document.querySelector('[data-action="toggle-plan-prices"]');
          const action = document.querySelector('[data-buy]');
          const pricesHidden = !document.querySelector('.dist-plan-current-price')
            && !document.querySelector('.dist-period-options strong')
            && !document.querySelector('.dist-period-options small')
            && !document.querySelector('.dist-plan-actions span');
          if (!toggle || toggle.getAttribute('aria-pressed') !== 'false') failures.push('price_toggle_closed');
          if (!pricesHidden) failures.push('prices_visible_by_default');
          if (!document.querySelector('.dist-plan-heading')?.textContent.includes('客户活动价￥999')) failures.push('plan_copy_price_hidden');
          if (!action || !action.textContent.includes('已确认，直接下单')) failures.push('order_action_copy');
          toggle?.click();
          if (!document.querySelector('.dist-plan-current-price') || !document.querySelector('.dist-period-options strong') || !document.querySelector('.dist-plan-actions span')) failures.push('prices_not_restored');
          window.setTimeout(function () {
            if (document.querySelector('.dist-plan-current-price') || document.querySelector('.dist-period-options strong') || document.querySelector('.dist-plan-actions span')) failures.push('prices_not_auto_hidden');
            result.textContent = failures.length
              ? 'MOBILE_ASSET_SMOKE=FAIL ' + failures.join(',')
              : 'MOBILE_ASSET_SMOKE=PASS';
          }, 10100);
        }, 300);
      }, 500);
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

for viewport in 360,800 390,844 412,924 430,932 1366,900 1440,900 1920,1080; do
  profile_name=${viewport/,/-}
  set +e
  browser_output=$(
    timeout --signal=TERM --kill-after=5s "${BROWSER_SMOKE_TIMEOUT_SECONDS}s" \
      "$browser" \
      --headless=new \
      --disable-gpu \
      --no-sandbox \
      --no-proxy-server \
      --user-data-dir="$work_dir/chrome-profile-$profile_name" \
      --window-size="$viewport" \
      --virtual-time-budget=14000 \
      --dump-dom 'http://127.0.0.1:17002/mobile-order-smoke.html' 2>&1
  )
  browser_status=$?
  set -e

  if ((browser_status == 124 || browser_status == 137)); then
    echo "MOBILE_ASSET_SMOKE=FAIL browser_timeout viewport=$viewport timeout_seconds=$BROWSER_SMOKE_TIMEOUT_SECONDS" >&2
    exit 1
  fi
  if ((browser_status != 0)); then
    printf '%s\n' "$browser_output" >&2
    echo "MOBILE_ASSET_SMOKE=FAIL browser_exit viewport=$viewport status=$browser_status" >&2
    exit 1
  fi

  if ! grep -Fq 'MOBILE_ASSET_SMOKE=PASS' <<< "$browser_output"; then
    printf '%s\n' "$browser_output" >&2
    echo "MOBILE_ASSET_SMOKE=FAIL browser_assertion viewport=$viewport" >&2
    exit 1
  fi
done

echo 'MOBILE_ASSET_SMOKE=PASS'
