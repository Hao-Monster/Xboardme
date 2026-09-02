const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor orders use a compact horizontally scrollable table on desktop and mobile', () => {
  const renderer = source.match(/async function renderOrders\(options = \{\}\)[\s\S]*?function periodLabel/);
  assert.ok(renderer, 'the distributor order renderer should exist');

  assert.match(renderer[0], /class="dist-table-wrap dist-order-list"/);
  assert.match(renderer[0], /class="dist-table-wrap dist-order-list" tabindex="0" aria-label="\$\{t\('orders'\)\}"/);
  assert.match(renderer[0], /class="dist-orders-table"/);
  assert.match(renderer[0], /class="dist-order-identity"/);
  assert.match(renderer[0], /class="dist-order-settlement"/);
  assert.match(renderer[0], /class="dist-order-sequence"/);
  assert.match(renderer[0], /const actionCellClass = hasActions/);
  assert.match(renderer[0], /class="dist-order-type"/);
  assert.match(renderer[0], /class="dist-order-original"/);
  assert.match(renderer[0], /'dist-order-action-cell has-actions'/);
  assert.match(renderer[0], /data-label="\$\{t\('customerName'\)\}"/);
  assert.match(renderer[0], /data-label="\$\{t\('plan'\)\}"/);
  assert.match(renderer[0], /data-label="\$\{t\('period'\)\}"/);
  assert.match(renderer[0], /data-label="\$\{t\('amount'\)\}"/);
  assert.match(renderer[0], /class="dist-order-bound-devices" data-label="\$\{t\('boundDevices'\)\}"/);
  assert.match(renderer[0], /class="dist-order-used-traffic" data-label="\$\{t\('usedTraffic'\)\}"/);
  assert.match(renderer[0], /data-label="\$\{t\('remark'\)\}"/);
  assert.match(renderer[0], /<thead><tr><th>\$\{t\('sequence'\)\}<\/th><th>\$\{t\('actions'\)\}<\/th><th>\$\{t\('orderNo'\)\}<\/th><th>\$\{t\('orderTime'\)\}<\/th>/);

  assert.match(styles, /\.dist-order-list \.dist-orders-table \{[^}]*min-width:1517px[^}]*table-layout:fixed/);
  assert.match(styles, /@media \(max-width:900px\)/);
  assert.match(styles, /\.dist-order-list \.dist-orders-table \{ display:table!important; width:100%; min-width:1517px!important; \}/);
  assert.match(styles, /@media \(max-width:900px\)[\s\S]*?\.dist-order-list \{[^}]*overflow:auto/);
  assert.match(styles, /\.dist-desktop-pagination \{ display:flex; justify-content:flex-start; margin-bottom:12px; \}/);
  assert.match(styles, /\.dist-main \{ margin-left:0; padding-bottom:calc\(64px \+ env\(safe-area-inset-bottom\) \+ 12px\); \}/);
  assert.match(styles, /nth-child\(2\)[^}]*position:sticky; left:36px[^}]*width:128px/);
  assert.match(styles, /\.dist-order-list \.dist-orders-table th[^}]*position:sticky; top:0/);
  assert.match(styles, /\.dist-order-list \.dist-orders-table \.dist-entitlement-row\[hidden\] \{ display:none!important; \}/);
  assert.match(styles, /\.dist-order-list \.dist-orders-table \.dist-orders-empty \{ display:table-row; \}/);
  assert.match(styles, /\.dist-order-list \.dist-orders-table \.dist-order-time:before[\s\S]*content:none/);
  assert.match(styles, /\.dist-order-list \.dist-orders-table \.dist-bound-device-list \{[^}]*white-space:nowrap/);
  assert.match(styles, /border-radius:0/);
});

test('overview analytics and order list controls are wired independently', () => {
  assert.match(source, /\/user\/order\/statistics\?/);
  assert.match(source, /async function renderOverview\(renderContext\)/);
  assert.match(source, /orderSummaryRange/);
  assert.match(source, /orderTrendRange/);
  assert.match(source, /data-trend-preset="\$\{preset\}"/);
  assert.match(source, /renderTrendChart\(daily, 'order_count'/);
  assert.match(source, /renderTrendChart\(daily, 'total_amount'/);
  assert.match(source, /appendOrderFilters\(params\)/);
  assert.match(source, /periods\[\]/);
  assert.match(source, /data-action="apply-order-filters"/);
  assert.doesNotMatch(source, /data-action="load-more-orders"[^}]*dist-load-more/);
  assert.match(source, /data-order-page=/);
  assert.match(source, /id="dist-order-page-size"/);
  assert.match(source, /id="dist-order-page-input" type="number"/);
  assert.match(source, /data-action="jump-order-page"/);
  assert.match(styles, /\.dist-chart-stack/);
  assert.match(styles, /\.dist-desktop-pagination/);
  assert.match(styles, /\.dist-load-more/);
  assert.match(styles, /position:sticky/);
});

test('mobile order actions stay visible, touch-sized, and preserve permission-driven availability', () => {
  assert.match(source, /data-subscription-qr="\$\{escapeHtml\(order\.trade_no\)\}"/);
  assert.match(source, /data-entitlement-toggle="\$\{entitlementTarget\}"/);
  assert.match(source, /data-renew="\$\{escapeHtml\(order\.trade_no\)\}"/);
  assert.match(source, /const hasActions = Boolean\(order\.is_subscription_origin \|\| entitlementAction \|\| renewAction\)/);
  assert.match(source, /class="dist-action-disabled" role="status" aria-disabled="true"/);

  assert.match(styles, /\.dist-orders-table \.dist-order-actions>\* \{[^}]*min-height:27px; height:27px/);
  assert.match(styles, /\.dist-orders-table \.dist-order-actions>\*[^}]*border-radius:0/);
  assert.match(styles, /\.dist-order-actions>\.dist-action-disabled \{[^}]*cursor:not-allowed/);
});

test('mobile QR and renewal dialogs behave as accessible bottom sheets', () => {
  assert.match(source, /class="dist-modal dist-subscription-qr-modal" role="dialog" aria-modal="true"/);
  assert.match(source, /class="dist-modal dist-renewal-modal" role="dialog" aria-modal="true"/);
  assert.match(source, /modalTrigger:/);
  assert.match(source, /focusOrderActionModal\(\)/);
  assert.match(source, /if \(!modalTrigger\) return;/);
  assert.match(source, /event\.key === 'Escape'/);
  assert.match(source, /event\.target\.classList\.contains\('dist-modal-backdrop'\)/);

  assert.match(styles, /\.dist-subscription-qr-modal,\.dist-renewal-modal \{[^}]*border-radius:18px 18px 0 0/);
  assert.match(styles, /max-height:calc\(100dvh - 16px\)/);
  assert.match(styles, /env\(safe-area-inset-bottom\)/);
});
