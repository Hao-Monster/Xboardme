const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const distributorSource = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const adminSource = fs.readFileSync('public/assets/admin-distributor.js', 'utf8');
const subscriptionName = 'GZXBL小北Mustafa-261006-A7K9Q2';
const tradeNo = '20261006160123456789012345';
const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

function functions(source, names, context = {}) {
  const snippets = names.map((name) => {
    const match = source.match(new RegExp(`^  (?:async )?function ${name}\\([\\s\\S]*?^  }`, 'm'));
    assert.ok(match, `${name} must exist`);
    return match[0];
  }).join('\n');
  return vm.runInNewContext(`${snippets}; ({${names.join(',')}})`, context);
}

test('QR image prints the subscription name without an order prefix and preserves device details', async () => {
  const printed = [];
  const context = {
    measureText: (text) => ({ width: text.length * 10 }),
    fillText: (text) => printed.push(text),
    fillRect() {}, drawImage() {},
  };
  const canvas = { getContext: () => context, toDataURL: () => 'data:image/png;fixture' };
  const { composeSubscriptionQrPng } = functions(distributorSource,
    ['subscriptionLabel', 'wrapCanvasText', 'composeSubscriptionQrPng'], {
      loadImage: async () => ({}), canvasBlob: async () => new Blob(['png']),
      document: { createElement: () => canvas },
      t: (key) => ({ premiumCustomerQrTitleFallback: '客户订阅码', subscriptionBoundDevice: '订阅已绑定设备' }[key] || key),
    });
  await composeSubscriptionQrPng({
    subscription_name: subscriptionName, trade_no: tradeNo,
    qr_code: 'fixture', hwid_enabled: true, hwid_devices: ['pixel-123', 'vivo-456'],
  });
  assert.ok(printed.includes(subscriptionName));
  assert.ok(printed.includes('订阅已绑定设备 pixel-123'));
  assert.ok(printed.includes('订阅已绑定设备 vivo-456'));
  assert.equal(printed.some((text) => text.includes(tradeNo) || /订单号/.test(text)), false);
});

test('subscription label uses the saved name and keeps older API responses readable', () => {
  const { subscriptionLabel } = functions(distributorSource, ['subscriptionLabel']);
  assert.equal(subscriptionLabel({ subscription_name: subscriptionName, trade_no: tradeNo }), subscriptionName);
  assert.equal(subscriptionLabel({ trade_no: tradeNo }), tradeNo);
  assert.equal(subscriptionLabel(null), '');
});

test('distributor order list shows the name and retains the individual transaction number', async () => {
  let html = '';
  const state = { orders: [], orderPage: 1, orderPerPage: 20, orderSettlementStatus: '', orderSearch: '', locale: 'zh-CN' };
  const { renderOrders } = functions(distributorSource, ['subscriptionLabel', 'renderOrders'], {
    state, URLSearchParams, escapeHtml, window: { scrollY: 0 },
    beginRouteRender: () => ({}), isCurrentRouteRender: () => true,
    appendOrderFilters() {}, renderOrderFilters: () => '', renderBoundDevices: () => '',
    t: (key) => key, money: String, formatTraffic: String, formatTime: String, periodLabel: String,
    setContent: (content) => { html = content; },
    api: async () => ({ data: [
      { id: 1, subscription_name: subscriptionName, trade_no: tradeNo, type: 1 },
      { id: 2, subscription_name: subscriptionName, trade_no: 'RENEW-TRANSACTION', type: 2 },
      { id: 3, subscription_name: '<script>alert(1)</script>', trade_no: 'ESCAPED' },
    ] }),
  });
  await renderOrders();
  assert.equal(html.split(`<strong>${subscriptionName}</strong>`).length - 1, 2);
  assert.ok(html.includes(`<small>orderNo：${tradeNo}</small>`));
  assert.ok(html.includes('<small>orderNo：RENEW-TRANSACTION</small>'));
  assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
  assert.equal(html.includes('<script>'), false);
});

test('admin order list renders the subscription name safely and retains the original trade number', () => {
  const { orderRows } = functions(adminSource, ['orderRows'], {
    state: { orders: [{ id: 1, subscription_name: subscriptionName, trade_no: tradeNo }] },
    escapeHtml, money: String, formatTraffic: String, formatTime: String, renderBoundDevices: () => '',
  });
  const html = orderRows();
  assert.ok(html.includes(`<strong>${subscriptionName}</strong>`));
  assert.ok(html.includes(`<small>订单号：${tradeNo}</small>`));
});

test('merchant-name validation trims values and enforces the same UTF-16 limit as the inputs', () => {
  const { validateDistributorName } = functions(adminSource, ['validateDistributorName']);
  assert.equal(validateDistributorName(' GZXBL小北Mustafa '), 'GZXBL小北Mustafa');
  assert.equal(validateDistributorName('甲'.repeat(16)), '甲'.repeat(16));
  assert.equal(validateDistributorName('A'.repeat(16)), 'A'.repeat(16));
  assert.equal(validateDistributorName('A B'), 'A B');
  assert.equal(validateDistributorName('😀'.repeat(8)), '😀'.repeat(8));
  for (const name of ['', '   ', '甲'.repeat(17), 'A'.repeat(17), '😀'.repeat(9), 'a\nb', 'a\u200bb', 'a\u0000b']) {
    assert.throws(() => validateDistributorName(name));
  }
});

test('native admin request bridge validates before sending and preserves valid saved names', () => {
  const notifications = [];
  const field = { dataset: {}, querySelector: () => ({ value: ' A'.repeat(17) }) };
  const checkbox = { checked: true, closest: () => field };
  const { appendDistributorField } = functions(adminSource, ['validateDistributorName', 'appendDistributorField'], {
    activeInjectedSwitch: () => checkbox, FormData, URLSearchParams,
    toast: (message) => notifications.push(message),
  });
  assert.throws(() => appendDistributorField(JSON.stringify({ id: 7 })), /16/);
  assert.equal(notifications.length, 1);
  field.dataset.distributorName = '小北';
  assert.deepEqual(JSON.parse(appendDistributorField(JSON.stringify({ id: 7 }))), {
    id: 7, is_distributor: 1, distributor_name: '小北',
  });
  checkbox.checked = false;
  assert.equal(JSON.parse(appendDistributorField('{}')).distributor_name, '');
});

test('creation, existing-user conversion and native admin bridge reject email merchant names before requests', async () => {
  const requests = [];
  const values = {
    'admin-dist-create-email': 'fixture@example.invalid',
    'admin-dist-create-name': ' a@b.co ',
    'admin-dist-user-name': ' a@b.co ',
  };
  let nativeName = ' a@b.co ';
  const checkbox = { checked: true, closest: () => ({
    dataset: {}, querySelector: () => ({ value: nativeName }),
  }) };
  const { createUser, toggleUser, appendDistributorField } = functions(adminSource,
    ['validateDistributorName', 'createUser', 'toggleUser', 'appendDistributorField'], {
      document: { getElementById: (id) => ({ value: values[id] || '' }) },
      api: async (path, options) => { requests.push({ path, ...options }); },
      loadDistributors: async () => {}, renderUsers() {}, toast() {},
      activeInjectedSwitch: () => checkbox, FormData, URLSearchParams,
    });
  for (const name of [' a@b.co ', 'A+B@EXAMPLE.COM', 'user@shop.co.uk']) {
    values['admin-dist-create-name'] = name;
    values['admin-dist-user-name'] = name;
    nativeName = name;
    await assert.rejects(createUser(), /不要使用邮箱/);
    await assert.rejects(toggleUser(7, false), /不要使用邮箱/);
    assert.throws(() => appendDistributorField('{"id":7}'), /不要使用邮箱/);
    assert.equal(requests.length, 0);
  }
  values['admin-dist-create-name'] = ' 小北商户 ';
  values['admin-dist-user-name'] = ' 小北商户 ';
  nativeName = ' 小北商户 ';
  await createUser();
  await toggleUser(7, false);
  assert.equal(requests.length, 2);
  assert.equal(requests[0].data.distributor_name, '小北商户');
  assert.equal(requests[1].data.distributor_name, '小北商户');
  assert.equal(JSON.parse(appendDistributorField('{"id":7}')).distributor_name, '小北商户');
});
