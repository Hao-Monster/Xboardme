const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

function loadGroupingFunction() {
  const start = source.indexOf('  function groupSubscriptionOrders(orders) {');
  const end = source.indexOf('\n\n  async function renderOrders()', start);
  assert.notEqual(start, -1, 'subscription order grouping function should exist');
  assert.notEqual(end, -1, 'subscription order grouping function should be extractable');
  const functionSource = source.slice(start, end).replace(/^  /gm, '');
  return Function(`${functionSource}; return groupSubscriptionOrders;`)();
}

function loadEntitlementToggle() {
  const start = source.indexOf('  function toggleEntitlement(entitlementToggle, entitlementRow) {');
  const end = source.indexOf('\n\n  async function renderOrders()', start);
  assert.notEqual(start, -1, 'entitlement toggle function should exist');
  assert.notEqual(end, -1, 'entitlement toggle function should be extractable');
  const functionSource = source.slice(start, end).replace(/^  /gm, '');
  return Function('t', `${functionSource}; return toggleEntitlement;`)((key) => key);
}

test('original order is rendered before all renewals while groups retain latest-activity order', () => {
  const groupSubscriptionOrders = loadGroupingFunction();
  const orders = [
    { trade_no: 'A-R2', subscription_trade_no: 'A-ROOT', is_subscription_origin: false },
    { trade_no: 'B-ROOT', subscription_trade_no: 'B-ROOT', is_subscription_origin: true },
    { trade_no: 'A-R1', subscription_trade_no: 'A-ROOT', is_subscription_origin: false },
    { trade_no: 'A-ROOT', subscription_trade_no: 'A-ROOT', is_subscription_origin: true },
    { trade_no: 'B-R1', subscription_trade_no: 'B-ROOT', is_subscription_origin: false },
  ];

  assert.deepEqual(
    groupSubscriptionOrders(orders).map((order) => order.trade_no),
    ['A-ROOT', 'A-R2', 'A-R1', 'B-ROOT', 'B-R1'],
  );
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

test('renewal rows share one aligned child indentation style', () => {
  assert.match(source, /dist-renewal-order-row/);
  assert.match(source, /data-subscription-trade-no/);
  assert.match(styles, /\.dist-renewal-order-row td:first-child \{[^}]*padding-left:48px/);
  assert.match(styles, /\.dist-renewal-order-row td:first-child:before \{ content:'↳'/);
});
