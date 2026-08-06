const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const distributor = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const admin = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');
const exporter = fs.readFileSync('app/Services/DistributorOrderExportService.php', 'utf8');

test('checkout delivery closes locally without confirmation, server mutation, recovery, or unload interception', () => {
  assert.match(distributor, /closePopup: '关闭弹窗'/);
  assert.match(distributor, /buyAgain: '再次购买该套餐'/);
  assert.match(distributor, /data-modal-action="close-delivery"/);
  assert.match(distributor, /action === 'close-delivery'\) closeModal\(\)/);
  assert.doesNotMatch(distributor, /\/user\/distributor\/delivery\/close/);
  assert.doesNotMatch(distributor, /closeArmed|closeWarning|closeAgain|recoverPendingDelivery|beforeunload/);
  assert.doesNotMatch(distributor, /dismissed_deliveries|dismissedDeliveries/);
});

test('buy again reopens confirmation for the current plan and period with a blank required customer name', () => {
  const repurchase = distributor.match(/async function repurchaseDelivery\(\)[\s\S]*?\n  }/);
  assert.ok(repurchase, 'repurchase handler should exist');
  assert.match(repurchase[0], /delivery\.plan_id/);
  assert.match(repurchase[0], /delivery\.period/);
  assert.match(repurchase[0], /openPurchaseModal\(plan, delivery\.period\)/);
  assert.match(distributor, /customerName: ''/);
  assert.match(distributor, /customerNameRequired/);
  assert.match(distributor, /planUnavailable: '套餐已下架或当前周期不可购买'/);
});

test('delivery status is absent from distributor and admin order lists while technical detail states remain', () => {
  const distributorOrders = distributor.match(/async function renderOrders\(\)[\s\S]*?\n  }/);
  assert.ok(distributorOrders, 'distributor order renderer should exist');
  assert.doesNotMatch(distributorOrders[0], /t\('delivery'\)|delivery-\$\{|data-delivery/);
  assert.match(distributorOrders[0], /t\('settlement'\)/);

  const adminRows = admin.match(/function orderRows\([\s\S]*?\n  }/);
  assert.ok(adminRows, 'admin order renderer should exist');
  assert.doesNotMatch(adminRows[0], /delivery_status|admin-dist-connected|admin-dist-waiting/);
  assert.doesNotMatch(admin, /<th>交付状态<\/th>|<th>交付<\/th>/);
  assert.match(admin, /<dt>配置下发<\/dt>/);
  assert.match(admin, /<dt>接入状态<\/dt>/);
});

test('both xlsx exports omit delivery status and retain settlement status', () => {
  assert.doesNotMatch(exporter, /'交付状态'/);
  assert.doesNotMatch(exporter, /deliveryLabel/);
  assert.match(exporter, /'结算状态'/);
  assert.match(exporter, /settlementLabel/);
});
