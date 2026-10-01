const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
function harness(api, current = true) {
  const slot = {};
  const context = vm.createContext({
    state: { notices: [], noticeIndex: 0, locale: 'zh-CN' }, api,
    isCurrentRouteRender: () => current,
    document: { getElementById: () => slot },
    startNoticeRotation: () => {},
    escapeHtml: (s) => String(s).replace(/</g, '&lt;').replace(/>/g, '&gt;'),
    t: () => '重试',
  });
  vm.runInContext(source.slice(source.indexOf('  async function loadNotices('), source.indexOf('  function safeNoticeContent(')) +
    source.slice(source.indexOf('  function renderNoticeBar('), source.indexOf('  function advanceNotice(')), context);
  return { context, slot };
}
test('loads all pages in backend order and updates only the notice slot', async () => {
  const calls = [];
  const { context, slot } = harness(async (url) => {
    calls.push(url);
    return { data: calls.length === 1 ? [{ title: 'one' }] : [{ title: 'two' }], total: 2 };
  });
  await context.loadNotices({});
  assert.equal(calls.length, 2);
  assert.equal(context.state.notices[1].title, 'two');
  assert.match(slot.innerHTML, /one/);
  assert.match(slot.innerHTML, /1\/2/);
});
test('empty notices collapse and a single title is escaped without rotation control', async () => {
  const { context, slot } = harness(async () => ({ data: [], total: 0 }));
  await context.loadNotices({});
  assert.equal(slot.innerHTML, '');
  context.state.notices = [{ title: '<img onerror=evil()>' }];
  assert.match(context.renderNoticeBar(), /&lt;img/);
  assert.doesNotMatch(context.renderNoticeBar(), /next-notice/);
});
test('failure exposes retry and a later successful response recovers', async () => {
  let fail = true;
  const { context, slot } = harness(async () => {
    if (fail) throw new Error('offline');
    return { data: [{ title: 'recovered' }], total: 1 };
  });
  await context.loadNotices({});
  assert.match(slot.innerHTML, /retry-notices/);
  fail = false;
  await context.loadNotices({});
  assert.match(slot.innerHTML, /recovered/);
  assert.equal(context.state.noticeError, false);
});
test('a response from a previous route cannot overwrite the current view', async () => {
  const { context, slot } = harness(async () => ({ data: [{ title: 'stale' }], total: 1 }), false);
  await context.loadNotices({});
  assert.equal(slot.innerHTML, undefined);
  assert.equal(context.state.notices.length, 0);
});
