const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8').replaceAll('\r\n', '\n');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8').replaceAll('\r\n', '\n');

test('business overview is a first-class route between catalog and orders', () => {
  assert.match(source, /overview: '经营概览'/);
  assert.match(source, /\['\/plan', '\/overview', '\/order', '\/invite', '\/knowledge', '\/clients'\]\.includes\(path\)/);
  assert.match(source, /data-nav="\/plan"[\s\S]*data-nav="\/overview"[\s\S]*data-nav="\/order"/);
  assert.match(source, /page === '\/overview'\) await renderOverview\(renderContext\)/);
  assert.match(source, /async function renderOverview\(renderContext\)/);
  assert.match(source, /<h1 id="dist-order-overview-title">\$\{t\('orderOverview'\)\}<\/h1>/);
});

test('orders no longer render the former heading or fetch analytics', () => {
  const renderer = source.match(/async function renderOrders\(options = \{\}\)[\s\S]*?function periodLabel/);
  assert.ok(renderer, 'the distributor order renderer should exist');
  assert.doesNotMatch(renderer[0], /ensureOrderAnalytics|renderOrderAnalytics|dist-page-head/);
  assert.match(renderer[0], /dist-order-list/);
});

test('overview requests are atomic and stale route or range responses are ignored', () => {
  assert.match(source, /renderGeneration:\s*0/);
  assert.match(source, /analyticsRequestToken:\s*0/);
  assert.match(source, /function beginRouteRender\(route\)/);
  assert.match(source, /function isCurrentRouteRender\(renderContext\)/);
  assert.match(source, /const requestToken = \+\+state\.analyticsRequestToken/);
  assert.match(source, /if \(requestToken !== state\.analyticsRequestToken \|\| !isCurrentRouteRender\(renderContext\)\) return false/);
  assert.match(source, /const \[summary, trend\] = await Promise\.all/);
  assert.match(source, /function invalidateOrderAnalytics\(\)/);
  assert.match(source, /await purchasePlan[\s\S]*invalidateOrderAnalytics\(\)/);
  assert.match(source, /action === 'confirm-renewal'[\s\S]*invalidateOrderAnalytics\(\)/);
});

test('mobile navigation uses one touch-sized horizontally scrollable row for all six routes', () => {
  assert.match(styles, /\.dist-sidebar nav \{[^}]*overflow-x:auto/);
  assert.match(styles, /\.dist-sidebar nav button[^}]*\{[^}]*min-height:44px/);
  assert.doesNotMatch(styles, /\.dist-sidebar nav \{ grid-template-columns:repeat\(5,1fr\); \}/);
});
