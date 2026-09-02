const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const distributor = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const distributorStyles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');
const admin = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');
const adminStyles = fs.readFileSync('public/assets/admin-distributor.css', 'utf8');

test('distributor order table places order time beside the order number', () => {
  const renderer = distributor.match(/async function renderOrders\(options = \{\}\)[\s\S]*?function periodLabel/);
  assert.ok(renderer, 'distributor order renderer should exist');
  assert.match(renderer[0], /<th>\$\{t\('sequence'\)\}<\/th><th>\$\{t\('actions'\)\}<\/th><th>\$\{t\('orderNo'\)\}<\/th><th>\$\{t\('orderTime'\)\}<\/th><th>\$\{t\('orderType'\)\}<\/th><th>\$\{t\('originalOrder'\)\}<\/th>/);
  assert.match(renderer[0], /<td class="dist-order-identity"><strong>\$\{escapeHtml\(order\.trade_no\)\}<\/strong><\/td>\s*<td class="dist-order-time" data-label="\$\{t\('orderTime'\)\}">\$\{formatTime\(order\.created_at\)\}<\/td>/);
  assert.doesNotMatch(renderer[0], /\$\{orderType\} · \$\{formatTime\(order\.created_at\)\}/);
  assert.match(renderer[0], /colspan="14"/);
  assert.match(distributorStyles, /\.dist-order-time \{[^}]*white-space:nowrap/);
});

test('both administrator distributor tables use an independent order time column', () => {
  const rows = admin.match(/function orderRows\([\s\S]*?\n  }/);
  assert.ok(rows, 'administrator order row renderer should exist');
  assert.match(rows[0], /<td class="admin-dist-order-time">\$\{formatTime\(order\.created_at\)\}<\/td>/);
  assert.doesNotMatch(rows[0], /order_type_label[^\n]*formatTime\(order\.created_at\)/);

  const headers = [...admin.matchAll(/<th>订单号<\/th><th>下单时间<\/th><th>用户名称<\/th>/g)];
  assert.equal(headers.length, 2, 'modal and native administrator tables should both expose order time');
  assert.equal([...admin.matchAll(/colspan="11"/g)].length, 2);
  assert.match(adminStyles, /\.admin-dist-order-time \{[^}]*white-space:nowrap/);
});
