const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function load() {
  const source = fs.readFileSync('public/assets/knowledge-share.js', 'utf8');
  class Xhr {}
  Xhr.prototype.open = function() {};
  Xhr.prototype.send = function() {};
  class Observer { observe() {} }
  const document = {
    documentElement: { classList: { contains: () => false } },
    querySelectorAll: () => [],
    createElement: () => ({ style: {}, setAttribute() {}, addEventListener() {}, select() {}, remove() {} }),
    body: { appendChild() {} },
    execCommand() {},
  };
  const window = {
    location: { hash: '#/knowledge' },
    setTimeout: () => 1,
    addEventListener() {},
    XMLHttpRequest: Xhr,
    MutationObserver: Observer,
  };
  const sandbox = { window, document, navigator: {}, XMLHttpRequest: Xhr, MutationObserver: Observer, console };
  vm.runInNewContext(source, sandbox, { filename: 'knowledge-share.js' });
  return { helpers: window.__xboardKnowledgeShare, source };
}

test('share helper collects permanent article URLs from API payloads', () => {
  const { helpers } = load();
  helpers.collect({ data: [{ id: 12, title: 'Guide', show: true, share_url: 'https://example.test/guide/12/guide' }] });
  assert.equal(helpers.getArticle(12).shareUrl, 'https://example.test/guide/12/guide');
  assert.equal(helpers.getArticle(12).show, true);
});

test('share enhancement covers admin list and regular user detail', () => {
  const { source } = load();
  assert.match(source, /\/config\/knowledge/);
  assert.match(source, /复制分享链接/);
  assert.match(source, /enhanceAdminList/);
  assert.match(source, /enhanceUserDetail/);
  const admin = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
  const dashboard = fs.readFileSync('theme/Xboard/dashboard.blade.php', 'utf8');
  assert.match(admin, /knowledge-share\.js/);
  assert.match(dashboard, /knowledge-share\.js/);
});

test('distributor article modal has a native share action', () => {
  const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
  assert.match(source, /class="dist-knowledge-share"/);
  assert.match(source, /article\.share_url/);
  assert.match(source, /复制分享链接/);
});

test('public page includes non-blocking login and register card', () => {
  const blade = fs.readFileSync('resources/views/knowledge/public.blade.php', 'utf8');
  const styles = fs.readFileSync('public/assets/public-knowledge.css', 'utf8');
  assert.match(blade, /public-auth-card/);
  assert.match(blade, /\/#\/login/);
  assert.match(blade, /\/#\/register/);
  assert.match(styles, /position:\s*fixed/);
  assert.match(styles, /@media \(max-width: 640px\)/);
});
