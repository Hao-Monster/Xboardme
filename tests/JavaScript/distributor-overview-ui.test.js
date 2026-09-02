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

test('overview navigation bypasses the host router fallback and renders the exact hash route', () => {
  const navigateBlock = source.match(/function navigate\(path\)[\s\S]*?\n  }/);
  assert.ok(navigateBlock, 'the distributor route navigator should exist');
  assert.match(navigateBlock[0], /path === '\/overview'/);
  assert.match(navigateBlock[0], /window\.history\.pushState/);
  assert.match(navigateBlock[0], /renderPage\(\)/);
  assert.match(source, /window\.addEventListener\('popstate'/);

  const calls = [];
  const fakeWindow = {
    location: { hash: '#/plan' },
    history: {
      state: { idx: 7 },
      pushState(state, title, hash) {
        calls.push({ state, title, hash });
        fakeWindow.location.hash = hash;
      },
    },
  };
  const navigate = Function('window', 'closePlanPrices', 'renderPage', `${navigateBlock[0]}; return navigate;`)(
    fakeWindow,
    (render) => calls.push({ close: render }),
    () => calls.push({ render: true }),
  );

  navigate('/overview');
  assert.equal(fakeWindow.location.hash, '#/overview');
  assert.deepEqual(calls, [
    { close: false },
    { state: { idx: 7, distributorRoute: '/overview' }, title: '', hash: '#/overview' },
    { render: true },
  ]);
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

test('mobile navigation uses one touch-sized horizontally scrollable row with a one-item next control', () => {
  assert.match(styles, /\.dist-sidebar nav \{[^}]*overflow-x:auto/);
  assert.match(styles, /\.dist-sidebar nav button[^}]*\{[^}]*min-height:44px/);
  assert.doesNotMatch(styles, /\.dist-sidebar nav \{ grid-template-columns:repeat\(5,1fr\); \}/);
  assert.match(source, /class="dist-mobile-nav-next"/);
  assert.match(source, /data-action="scroll-mobile-nav"/);
  assert.match(source, /nav\.scrollBy\(\{ left: step, behavior: 'smooth' \}\)/);
  assert.match(source, /next\.disabled = nav\.scrollLeft \+ nav\.clientWidth >= nav\.scrollWidth - 1/);
  assert.match(styles, /\.dist-mobile-nav-next \{ display:none/);
  assert.match(styles, /@media \(max-width:560px\) \{[^\n]*\.dist-mobile-nav-next \{[^}]*display:flex/);

  const updateBlock = source.match(/function updateMobileNavNextState\(nav[\s\S]*?\n  }/);
  const scrollBlock = source.match(/function scrollMobileNav\(\)[\s\S]*?\n  }/);
  assert.ok(updateBlock && scrollBlock, 'mobile navigation helpers should exist');
  const next = { disabled: false };
  const scrollCalls = [];
  const buttons = [{ offsetLeft: 0, offsetWidth: 78 }, { offsetLeft: 86, offsetWidth: 78 }];
  const nav = {
    scrollLeft: 0,
    clientWidth: 300,
    scrollWidth: 516,
    querySelectorAll: () => buttons,
    scrollBy: (options) => scrollCalls.push(options),
  };
  const fakeDocument = {
    querySelector: (selector) => selector === '.dist-sidebar nav' ? nav : next,
  };
  const updateMobileNavNextState = Function('document', `${updateBlock[0]}; return updateMobileNavNextState;`)(fakeDocument);
  const scrollMobileNav = Function('document', `${scrollBlock[0]}; return scrollMobileNav;`)(fakeDocument);

  updateMobileNavNextState(nav);
  assert.equal(next.disabled, false);
  scrollMobileNav();
  assert.deepEqual(scrollCalls, [{ left: 86, behavior: 'smooth' }]);
  nav.scrollLeft = 216;
  updateMobileNavNextState(nav);
  assert.equal(next.disabled, true);
});
