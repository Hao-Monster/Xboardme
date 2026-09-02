const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8').replaceAll('\r\n', '\n');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8').replaceAll('\r\n', '\n');

function loadEntitlementToggle() {
  const start = source.indexOf('  function toggleEntitlement(entitlementToggle, entitlementRow) {');
  const end = source.indexOf('\n\n  function orderSummaryTitle', start);
  assert.notEqual(start, -1, 'entitlement toggle function should exist');
  assert.notEqual(end, -1, 'entitlement toggle function should be extractable');
  const functionSource = source.slice(start, end).replace(/^  /gm, '');
  return Function('t', `${functionSource}; return toggleEntitlement;`)((key) => key);
}

test('server-paginated orders retain newest-first API order without client regrouping', () => {
  assert.match(source, /const rows = state\.orders\.map\(\(order, index\) =>/);
  assert.doesNotMatch(source, /groupSubscriptionOrders/);
  assert.match(source, /state\.orders = append \? \[\.\.\.state\.orders, \.\.\.fetchedOrders\] : fetchedOrders/);
});

test('entitlement is collapsed by default and has an accessible view-hide toggle', () => {
  const toggleEntitlement = loadEntitlementToggle();
  const attributes = new Map([['aria-expanded', 'false']]);
  const button = {
    textContent: 'viewEntitlement',
    getAttribute: (name) => attributes.get(name),
    setAttribute: (name, value) => attributes.set(name, value),
  };
  const row = { hidden: true };

  toggleEntitlement(button, row);
  assert.equal(attributes.get('aria-expanded'), 'true');
  assert.equal(button.textContent, 'hideEntitlement');
  assert.equal(row.hidden, false);

  toggleEntitlement(button, row);
  assert.equal(attributes.get('aria-expanded'), 'false');
  assert.equal(button.textContent, 'viewEntitlement');
  assert.equal(row.hidden, true);

  assert.match(source, /viewEntitlement: '查看订阅权益'/);
  assert.match(source, /hideEntitlement: '收起订阅权益'/);
  assert.match(source, /class="dist-entitlement-row"[^>]* hidden/);
  assert.match(source, /data-entitlement-toggle="\$\{entitlementTarget\}"/);
  assert.match(source, /aria-expanded="false"/);
  assert.match(source, /toggleEntitlement\(entitlementToggle, entitlementRow\)/);
  assert.match(styles, /\.dist-entitlement-row\[hidden\] \{ display:none; \}/);
});

test('renewal rows use the explicit order type and original-order columns', () => {
  assert.match(source, /dist-renewal-order-row/);
  assert.match(source, /data-subscription-trade-no/);
  assert.match(source, /class="dist-order-type"/);
  assert.match(source, /class="dist-order-original"/);
  assert.match(styles, /dist-renewal-order-row>td:first-child:before \{ content:none!important; \}/);
});
