const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8').replaceAll('\r\n', '\n');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8').replaceAll('\r\n', '\n');

test('plan catalog exposes free numeric quantity input and 5/10 quick-select buttons without confirmation dialogs', () => {
  assert.match(source, /QUICK_ORDER_QUANTITIES = \[5, 10\]/);
  assert.match(source, /MAX_ORDER_QUANTITY = 500/);
  assert.match(source, /selectedQuantities:\s*\{\}/);
  assert.match(source, /dist-plan-quantity-row/);
  assert.match(source, /data-plan-qty-preset="\$\{preset\}"/);
  assert.match(source, /data-plan-qty-step="-1"/);
  assert.match(source, /data-plan-qty-step="1"/);
  assert.match(source, /data-plan-qty-input="\$\{plan\.id\}"/);
  assert.match(source, /quickQuantity: '一次\{count\}个'/);
  assert.match(source, /orderActionBatch: '已确认，直接下单（\{count\}个）'/);
  assert.doesNotMatch(source, /confirmPurchase|openPurchaseModal|submitPurchase/);
  assert.match(styles, /\.dist-plan-quantity-row/);
  assert.match(styles, /\.dist-qty-preset\.active/);
  assert.match(styles, /\.dist-qty-stepper/);
});

test('batch checkout sends quantity and idempotency key, resets quantity to 1, and respects price privacy', () => {
  assert.match(source, /const batchPriceDetails = state\.planPricesVisible/);
  assert.match(source, /extendPlanPriceTimerIfVisible\(\)/);
  assert.match(source, /data: \{ plan_id: plan\.id, period, quantity, idempotency_key: idempotencyKey \}/);
  assert.match(source, /async function resumeUncertainPurchase\(\)/);
  assert.match(source, /data-action="resume-purchase"/);
  assert.match(source, /state\.selectedQuantities\[plan\.id\] = 1/);
  assert.match(source, /await openBatchDelivery\(tradeNos, plan, period\)/);
  assert.match(source, /剩余可售容量仅剩\\s\*\(\\d\+\)\\s\*个/);
});

test('batch delivery modal renders all QR cards without auto-download, downloads individual PNGs on click, and polls in one batch request', () => {
  assert.doesNotMatch(source, /createStoredZipBlob|application\/zip|download-batch-qr-zip|autoDownloaded/);
  assert.match(source, /function buildBatchDeliveryDownloads\(items\)/);
  assert.match(source, /async function downloadBatchDeliveryImages\(batchModal\)/);
  assert.match(source, /state\.modal === batchModal\s*&& !state\.resetModal\s*&& batchModal\.imagesReady/);
  assert.match(source, /Math\.min\(80, Math\.max\(8, Math\.floor\(3200 \/ \(entries\.length - 1\)\)\)\)/);
  assert.doesNotMatch(source, /initialAssetGeneration/);
  assert.match(source, /\/user\/distributor\/delivery\/batch/);
  assert.match(source, /data: \{ trade_nos: activeTradeNos\.join\(','\), include_qr: 0 \}/);
  assert.match(source, /data-modal-action="download-batch-qr-images"/);
  assert.match(source, /data-modal-action="copy-batch-item-qr"/);
  assert.match(source, /data-modal-action="download-batch-item-qr"/);
  assert.match(source, /let pollInFlight = false;/);
  assert.match(source, /imagesReady: false/);
  assert.match(source, /maxlength="6"/);
  assert.match(styles, /\.dist-batch-qr-modal/);
  assert.match(styles, /\.dist-batch-qr-grid/);
  assert.match(styles, /\.dist-batch-download-btn/);
});

test('batch QR PNG draws top-left #index, subscription label, and order number while single QR PNG remains unchanged', async () => {
  const calls = [];
  const context = {
    textAlign: 'left',
    textBaseline: 'top',
    font: '',
    fillStyle: '',
    measureText: (text) => ({ width: String(text).length * 11 }),
    fillText(text, x, y) {
      calls.push({ text, x, y, align: this.textAlign });
    },
    fillRect() { },
    drawImage() { },
  };
  const canvas = { width: 0, height: 0, getContext: () => context, toDataURL: () => 'data:image/png;fixture' };
  const snippets = ['subscriptionLabel', 'wrapCanvasText', 'composeSubscriptionQrPng'].map((name) => {
    const match = source.match(new RegExp(`^  (?:async )?function ${name}\\([\\s\\S]*?^  }`, 'm'));
    assert.ok(match, `${name} must exist`);
    return match[0];
  }).join('\n');
  const { composeSubscriptionQrPng } = vm.runInNewContext(
    `${snippets}; ({ composeSubscriptionQrPng })`,
    {
      loadImage: async () => ({}),
      canvasBlob: async () => new Blob(['png']),
      document: { createElement: () => canvas },
      t: (key) => ({
        orderNo: '订单号',
        premiumCustomerQrTitleFallback: '专属订阅二维码',
        subscriptionUnboundDevice: '暂未绑定设备',
        subscriptionHwidDisabled: '不限制设备',
      }[key] || key),
    }
  );

  await composeSubscriptionQrPng(
    { subscription_name: 'NW1017', trade_no: '202610311017', qr_code: 'fixture', hwid_enabled: true, hwid_devices: [] },
    { batchIndex: 2 }
  );

  const badgeCall = calls.find((entry) => entry.text === '#2');
  const nameCall = calls.find((entry) => entry.text === 'NW1017' && entry.align === 'left');
  const orderCall = calls.find((entry) => entry.text === '订单号：202610311017');
  assert.ok(badgeCall, 'batch PNG must draw #2 badge');
  assert.ok(nameCall, 'batch PNG must draw left-aligned subscription name NW1017');
  assert.ok(orderCall, 'batch PNG must draw 订单号：202610311017');
  assert.ok(badgeCall.x < canvas.width / 3 && badgeCall.y < 100, '#2 badge must be in top-left corner');
  assert.ok(nameCall.x < canvas.width / 2 && nameCall.y < 100, 'NW1017 must be in top-left corner');
  assert.ok(orderCall.x < canvas.width / 2 && orderCall.y > nameCall.y && orderCall.y < 130, 'order number must sit directly beneath NW1017 in top-left corner');
});

test('purchase recovery guards auth-expiry/rate-limit 4xx, revokes stale ObjectURLs, and updates single copied card without full modal rerender', () => {
  assert.match(source, /&& \[400, 404, 422\]\.includes\(status\)/);
  assert.match(source, /!isConfirmedAuthFailure\(status, error\?\.rawMessage \|\| error\?\.message\)/);
  assert.match(source, /function revokeTrackedObjectUrl\(objectUrls, url\)/);
  assert.match(source, /if \(state\.purchaseRecovery && state\.modal === originModal\)\s*\{\s*closeModal\(\);/);
  assert.match(source, /if \(!syncBatchDeliveryProgressDom\(activeBatchModal, \[index\]\)\) renderModal\(\);/);
});

test('uncertain purchases are stored per account in localStorage, written ahead, kept on logout, and abandoned only after a safe empty check', () => {
  assert.doesNotMatch(source, /sessionStorage/, 'purchase recovery must not depend on per-tab sessionStorage');
  assert.doesNotMatch(source, /markStoredPurchaseRecoveryAuthExpired/);
  assert.match(source, /return accountId \? `\$\{PURCHASE_RECOVERY_STORAGE_KEY\}:\$\{accountId\}` : '';/);
  assert.match(source, /localStorage\.setItem\(key, JSON\.stringify\(\{\s*schema: PURCHASE_RECOVERY_SCHEMA,/);
  assert.match(source, /state\.inFlightPurchaseKey = idempotencyKey;\s*recordPurchaseIntent\(requestSnapshot\);/);
  assert.match(source, /PURCHASE_ABANDON_SAFE_DELAY_MS = 180000/);
  assert.match(source, /data-action="confirm-abandon-purchase"/);
  assert.match(source, /startsWith\(`\$\{PURCHASE_RECOVERY_STORAGE_KEY\}:`\)/);
  const logoutBranch = source.slice(source.indexOf("if (action === 'logout')"), source.indexOf("} else if (action === 'toggle-plan-prices')"));
  assert.doesNotMatch(logoutBranch, /PURCHASE_RECOVERY_STORAGE_KEY|purchaseRecoveryStorageKey|persistPurchaseRecovery/, 'logout must keep the uncertain purchase record');
});
