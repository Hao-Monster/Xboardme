const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

function loadHelpers() {
  const source = fs.readFileSync('public/assets/admin-knowledge-attachments.js', 'utf8');
  const storage = new Map([['XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer token' })]]);
  const window = {
    settings: { secure_path: 'admin-path' },
    fetch: async () => { throw new Error('unexpected request'); },
    addEventListener() {},
  };
  class XMLHttpRequestMock {
    addEventListener() {}
  }
  XMLHttpRequestMock.prototype.open = function () {};
  XMLHttpRequestMock.prototype.send = function (body) { return body; };
  const sandbox = {
    window,
    document: {
      readyState: 'complete',
      documentElement: {},
      querySelectorAll: () => [],
      addEventListener() {},
    },
    location: { hash: '#/config/knowledge', origin: 'https://example.test' },
    localStorage: { getItem: (key) => storage.get(key) || null },
    XMLHttpRequest: XMLHttpRequestMock,
    MutationObserver: class { observe() {} },
    FormData,
    URLSearchParams,
    crypto: require('node:crypto').webcrypto,
    Uint8Array,
    setTimeout,
    clearTimeout,
    console,
  };
  sandbox.globalThis = sandbox;
  vm.runInNewContext(source, sandbox, { filename: 'admin-knowledge-attachments.js' });
  return window.__xboardKnowledgeAttachments;
}

test('knowledge attachment helper creates strong draft tokens and appends them to saves', () => {
  const helpers = loadHelpers();
  const token = helpers.createDraftToken();
  assert.match(token, /^[a-f0-9]{64}$/);
  assert.equal(new Set(Array.from({ length: 10 }, () => helpers.createDraftToken())).size, 10);

  const json = JSON.parse(helpers.appendDraftToken(JSON.stringify({ title: 'Guide' }), token));
  assert.equal(json.draft_token, token);
  const form = new URLSearchParams('title=Guide');
  helpers.appendDraftToken(form, token);
  assert.equal(form.get('draft_token'), token);
});

test('knowledge attachment helper emits safe snippets by disposition and MIME', () => {
  const helpers = loadHelpers();
  const base = {
    original_name: 'guide [final].png',
    placeholder: 'knowledge-attachment://11111111-1111-4111-8111-111111111111',
    disposition: 'inline',
  };
  assert.match(helpers.markdownFor({ ...base, mime_type: 'image/png' }), /^!\[/);
  assert.match(helpers.markdownFor({ ...base, mime_type: 'video/mp4' }), /^<video controls/);
  assert.match(helpers.markdownFor({ ...base, mime_type: 'image/svg+xml', disposition: 'attachment' }), /^\[/);
  assert.doesNotMatch(helpers.markdownFor({ ...base, mime_type: 'image/svg+xml', disposition: 'attachment' }), /^!\[/);
});

test('knowledge attachment assets are mounted independently from the compiled admin bundle', () => {
  const blade = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
  const styles = fs.readFileSync('public/assets/admin-knowledge-attachments.css', 'utf8');
  const source = fs.readFileSync('public/assets/admin-knowledge-attachments.js', 'utf8');
  assert.match(blade, /admin-knowledge-attachments\.css/);
  assert.match(blade, /admin-knowledge-attachments\.js/);
  assert.match(source, /\.rc-md-editor/);
  assert.match(source, /\/knowledge\/attachment\/upload\/initialize/);
  assert.match(source, /\/knowledge\/attachment\/upload\/\$\{item\.uploadUuid\}\/chunk/);
  assert.match(source, /dataTransfer\?\.files/);
  assert.match(styles, /\.knowledge-attachment-dropzone\.is-dragging/);
  assert.match(styles, /\.knowledge-attachment-progress/);
});
