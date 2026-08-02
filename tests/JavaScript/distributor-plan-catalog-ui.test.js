const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor plan catalog exposes the new commerce-oriented page structure', () => {
  assert.match(source, /dist-catalog-topbar/);
  assert.match(source, /dist-plan-filters/);
  assert.match(source, /data-plan-filter/);
  assert.match(source, /dist-plan-specs/);
  assert.match(source, /role="radiogroup"/);
  assert.match(source, /data-plan-period/);
  assert.doesNotMatch(source, /dist-plan-checkout-summary|generateQrHint|确认后将生成客户专属领取二维码/);
  assert.doesNotMatch(source, /dist-catalog-hero|dist-delivery-guide|选择套餐，免支付快速交付|选择要交付的订阅套餐/);
});

test('distributor shell uses the published site logo instead of the text mark', () => {
  assert.match(source, /<img class="dist-brand-mark" src="https:\/\/cloud\.thinderbox\.com\/assets\/branding\/thinderbox-logo\.png"/);
  assert.doesNotMatch(source, /<span class="dist-brand-mark">X<\/span>/);
  assert.match(styles, /\.dist-brand-mark \{[^}]*width:32px[^}]*height:32px[^}]*object-fit:cover/);
});

test('order button combines original price, calculated saving and emphasized action', () => {
  const savingBlock = source.match(/const periodSavings = \(plan, period\) => \{[\s\S]*?\n  };/);
  assert.ok(savingBlock, 'period saving calculator should exist');
  assert.match(savingBlock[0], /monthlyPrice \* months/);
  assert.match(savingBlock[0], /Math\.max\(0/);
  assert.match(source, /const selectedSaving = periodSavings\(plan, selectedPeriod\)/);
  assert.match(source, /<span>\$\{t\('original'\)\} \$\{money\(selectedPrice\)\}<\/span><span>\$\{t\('saved'\)\} \$\{money\(selectedSaving\)\}<\/span><strong>\$\{t\('orderAction'\)\}<\/strong>/);
  assert.match(source, /saved: '已省'/);
  assert.match(source, /orderAction: '下单'/);
  assert.match(styles, /\.dist-plan-actions button strong \{[^}]*font-size:18px/);
});

test('plan route renders the slogan and compact delivery steps inside dist-topbar', () => {
  const shellBlock = source.match(/function shell\(content\)[\s\S]*?\n  }/);
  assert.ok(shellBlock, 'distributor shell should exist');
  assert.match(shellBlock[0], /page === '\/plan'/);
  assert.match(shellBlock[0], /dist-topbar-promo/);
  assert.match(shellBlock[0], /dist-topbar-steps/);
  assert.match(shellBlock[0], /dist-topbar \$\{page === '\/plan' \? 'has-promo' : ''\}/);
  assert.match(shellBlock[0], /<small><b>0\$\{index \+ 1\}<\/b>\$\{title\}<\/small>/);
  assert.match(source, /promoStable: '稳定'/);
  assert.match(source, /promoFast: '高速'/);
  assert.match(source, /promoCompensation: '慢必赔'/);
  assert.match(styles, /\.dist-topbar-promo/);
  assert.match(styles, /\.dist-topbar-steps small/);
  assert.doesNotMatch(source, /dist-delivery-strip|dist-delivery-step/);
  assert.doesNotMatch(styles, /\.dist-delivery-strip|\.dist-delivery-step/);
});

test('every period price carries its own gold monthly-equivalent insight', () => {
  const periodButton = source.match(/const periodButtons = prices\.map[\s\S]*?\.join\(''\);/);
  assert.ok(periodButton, 'period price buttons should exist');
  assert.match(periodButton[0], /<strong>\$\{money\(plan\[key\]\)\}<\/strong><small>\$\{periodInsight\(plan, key\)\}<\/small>/);
  assert.match(source, /perMonth: '折合 \{price\}\/月'/);
  assert.match(source, /save: '省 \{percent\}%'/);
  assert.match(styles, /\.dist-period-options button small \{[^}]*color:#b7791f/);
});

test('period selection changes display state while checkout keeps the existing order contract', () => {
  assert.match(source, /selectedPeriods:\s*\{\}/);
  assert.match(source, /state\.selectedPeriods\[period\.dataset\.planId\]/);

  const confirmBlock = source.match(/function confirmPurchase\(planId, planName\)[\s\S]*?\n  }/);
  assert.ok(confirmBlock, 'purchase confirmation should exist');
  assert.match(confirmBlock[0], /state\.selectedPeriods\[planId\]/);
  assert.match(confirmBlock[0], /plan\[period\]/);

  const submitBlock = source.match(/async function submitPurchase\(\)[\s\S]*?\n  }/);
  assert.ok(submitBlock, 'purchase submission should exist');
  assert.match(submitBlock[0], /\/user\/order\/save/);
  assert.match(submitBlock[0], /plan_id:\s*modal\.planId/);
  assert.match(submitBlock[0], /period:\s*modal\.period/);
});

test('catalog remains isolated behind distributor detection and scoped styles', () => {
  assert.match(source, /if \(user\?\.is_distributor\) activate\(user\)/);
  assert.match(source, /normalApp\.style\.display = 'none'/);
  assert.match(source, /document\.documentElement\.classList\.add\('distributor-mode'\)/);
  assert.match(styles, /\.dist-catalog-topbar/);
  assert.match(styles, /\.dist-plan-card/);
  assert.doesNotMatch(styles, /\.dist-catalog-hero|\.dist-delivery-guide/);
  assert.doesNotMatch(styles, /(^|[,{])\s*\.plan-card\b/m);
});

test('distributor mode restores document scrolling and modal state locks it deliberately', () => {
  assert.match(styles, /html\.distributor-mode \{[^}]*height:auto!important[^}]*overflow-y:auto!important/);
  assert.match(styles, /html\.distributor-mode body \{[^}]*height:auto!important[^}]*overflow:visible!important/);
  assert.match(styles, /html\.distributor-mode\.dist-modal-open,html\.distributor-mode\.dist-modal-open body \{ overflow:hidden!important; \}/);

  const modalBlock = source.match(/function renderModal\(\)[\s\S]*?\n  }/);
  assert.ok(modalBlock, 'modal renderer should exist');
  assert.match(modalBlock[0], /document\.documentElement\.classList\.add\('dist-modal-open'\)/);
  assert.match(modalBlock[0], /document\.documentElement\.classList\.remove\('dist-modal-open'\)/);
});

test('mobile catalog uses compact spacing without changing desktop defaults', () => {
  const compactStart = styles.lastIndexOf('@media (max-width:640px)');
  assert.ok(compactStart >= 0, 'compact mobile breakpoint should exist');
  const compact = styles.slice(compactStart);
  assert.match(compact, /\.dist-content \{ padding:0 10px 10px; \}/);
  assert.match(compact, /\.dist-catalog-topbar \{ margin-bottom:0; padding:6px 8px;/);
  assert.match(compact, /\.dist-plan-grid \{ gap:0; \}/);
  assert.match(compact, /\.dist-plan-body \{ padding:16px 14px 14px; \}/);
  assert.match(compact, /\.dist-plan-specs \{[^}]*margin:10px 0 0;/);
  assert.match(compact, /\.dist-period-options \{ gap:6px; \}/);
  assert.match(compact, /\.dist-plan-actions \{ padding:6px 14px; \}/);
  assert.match(compact, /\.dist-topbar\.has-promo \{ height:64px; \}/);
  assert.match(styles, /\.dist-content \{ max-width:1420px; margin:0 auto; padding:36px; \}/);
});
