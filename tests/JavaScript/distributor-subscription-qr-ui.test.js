const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'theme/Xboard/assets/distributor.js'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'theme/Xboard/assets/distributor.css'), 'utf8');

test('distributor orders display every bound HWID and a separate subscription QR action', () => {
  assert.match(source, /order\.bound_devices/);
  assert.match(source, /dist-bound-device-list/);
  assert.match(source, /尚未绑定设备/);
  assert.match(source, /未启用设备绑定/);
  assert.match(source, /data-subscription-qr/);
  assert.match(source, /订阅尚未生成/);
  assert.match(styles, /\.dist-bound-device-list/);
  assert.match(styles, /\.dist-order-actions/);
});

test('subscription QR preview uses the protected endpoint and composites order plus all HWIDs into one PNG', () => {
  assert.match(source, /\/user\/distributor\/subscription-qr\?trade_no=/);
  assert.match(source, /composeSubscriptionQrPng/);
  assert.match(source, /payload\.hwid_devices\.map/);
  assert.match(source, /premiumCustomerQrTitle/);
  assert.match(source, /高端客户\{customer\}的订阅码/);
  assert.match(source, /高端客户的订阅码/);
  assert.match(source, /payload\.customer_name/);
  assert.match(source, /const titleLines = wrapCanvasText/);
  assert.match(source, /titleLines\.forEach/);
  assert.match(source, /`\$\{t\('orderNo'\)\} \$\{payload\.trade_no\}`/);
  assert.match(source, /canvas\.toDataURL\('image\/png'\)/);
  assert.match(source, /canvasBlob\(canvas\)/);
  assert.match(source, /dist-subscription-qr-preview/);
  assert.doesNotMatch(source, /data-subscribe-url/);
});

test('composited PNG supports image clipboard copy, feedback, download, and unsupported-browser guidance', () => {
  assert.match(source, /new ClipboardItem\(\{ 'image\/png': state\.modal\.blob \}\)/);
  assert.match(source, /state\.modal\.copied = true/);
  assert.match(source, /modal\.copied \? t\('copySuccess'\) : t\('copyImage'\)/);
  assert.match(source, /state\.modal\.payload\?\.trade_no \|\| state\.modal\.delivery\?\.trade_no/);
  assert.match(source, /URL\.createObjectURL\(state\.modal\.blob\)/);
  assert.match(source, /当前浏览器不支持复制图片，请使用下载图片/);
});

test('checkout delivery reuses the personalized PNG with devices, copy, and download while polling remains active', () => {
  assert.match(source, /async function makeDeliveryModal\(delivery\)/);
  assert.match(source, /await composeSubscriptionQrPng\(delivery\)/);
  assert.match(source, /state\.modal = await makeDeliveryModal\(delivery\)/);
  assert.match(source, /pending && modal\.imageUrl/);
  assert.match(source, /\['subscriptionQr', 'delivery'\]\.includes\(state\.modal\.type\)/);
  assert.match(source, /updated\.hwid_devices/);
  assert.match(source, /state\.modal = nextModal/);
  assert.match(styles, /\.dist-qr-modal \{ width:min\(620px,100%\); text-align:center; \}/);
});
