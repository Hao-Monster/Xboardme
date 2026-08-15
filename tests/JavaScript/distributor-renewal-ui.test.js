const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');
const adminSource = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');

test('distributor order page renews the stable subscription with an idempotent request', () => {
  assert.match(source, /data-renew="\$\{escapeHtml\(order\.trade_no\)\}"/);
  assert.match(source, /\/user\/order\/renew/);
  assert.match(source, /idempotency_key: state\.modal\.idempotencyKey/);
  assert.match(source, /order\.is_subscription_origin/);
  assert.match(source, /续费后订阅链接、二维码、UUID 和已绑定设备保持不变/);
  assert.match(source, /expired_at_after/);
  assert.match(styles, /\.dist-renewal-modal/);
  assert.match(styles, /\.dist-renew-btn/);
});

test('admin order detail distinguishes renewals and shows the entitlement audit window', () => {
  assert.match(adminSource, /订单类型/);
  assert.match(adminSource, /关联原订单/);
  assert.match(adminSource, /续费前到期/);
  assert.match(adminSource, /续费后到期/);
  assert.match(adminSource, /order\.entitlement_expired_at_before/);
  assert.match(adminSource, /order\.entitlement_expired_at_after/);
});
