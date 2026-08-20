const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8').replaceAll('\r\n', '\n');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8').replaceAll('\r\n', '\n');

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
  assert.match(source, /<img class="dist-brand-mark" src="https:\/\/cloud\.thinderbox\.com\/assets\/branding\/thinderbox-logo\.png\?v=39e70a98"/);
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
  assert.match(source, /orderAction: '已确认，直接下单'/);
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

test('period selection changes display state while direct checkout preserves the selected period', () => {
  assert.match(source, /selectedPeriods:\s*\{\}/);
  assert.match(source, /state\.selectedPeriods\[period\.dataset\.planId\]/);

  const purchaseBlock = source.match(/async function purchasePlan\(plan, period, button\)[\s\S]*?\n  }/);
  assert.ok(purchaseBlock, 'direct purchase handler should exist');
  assert.match(purchaseBlock[0], /\/user\/order\/save/);
  assert.match(purchaseBlock[0], /plan_id:\s*plan\.id/);
  assert.match(purchaseBlock[0], /period \}/);
  assert.match(source, /await purchasePlan\(plan, state\.selectedPeriods\[buy\.dataset\.buy\], buy\)/);
  assert.doesNotMatch(source, /confirmPurchase|openPurchaseModal|submitPurchase/);
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
  const compactMarker = '@media (max-width:640px) {\n  .dist-content { padding:0 10px 10px; }';
  const compactStart = styles.indexOf(compactMarker);
  assert.ok(compactStart >= 0, 'compact mobile breakpoint should exist');
  const compactEnd = styles.indexOf('\n}', compactStart);
  assert.ok(compactEnd > compactStart, 'compact mobile breakpoint should be complete');
  const compact = styles.slice(compactStart, compactEnd + 2);
  assert.match(compact, /\.dist-content \{ padding:0 10px 10px; \}/);
  assert.match(compact, /\.dist-catalog-topbar \{ margin-bottom:0; padding:6px 8px;/);
  assert.match(compact, /\.dist-plan-grid \{ gap:0; \}/);
  assert.match(compact, /\.dist-plan-body \{ padding:16px 14px 14px; \}/);
  assert.match(compact, /\.dist-plan-specs \{[^}]*margin:10px 0 0;/);
  assert.match(compact, /\.dist-period-options \{ gap:6px; \}/);
  assert.match(compact, /\.dist-plan-actions \{ padding:6px 14px; \}/);
  assert.match(styles, /@media \(max-width:560px\) \{\s*\.dist-topbar\.has-promo \{ height:64px; \}/);
  assert.match(styles, /\.dist-content \{ max-width:1420px; margin:0 auto; padding:36px; \}/);
});

test('a single desktop plan keeps a readable card width while mobile remains fluid', () => {
  assert.match(styles, /@media \(min-width:901px\) \{ \.dist-plan-grid>\.dist-plan-card:only-child \{ width:min\(680px,100%\); justify-self:start; \} \}/);
  assert.match(styles, /@media \(max-width:560px\) \{[^\n]*\.dist-plan-grid \{ grid-template-columns:1fr; \}/);
});
