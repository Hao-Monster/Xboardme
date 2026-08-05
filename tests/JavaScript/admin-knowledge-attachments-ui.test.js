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

test('knowledge attachment helper keeps upload order markers and removes generated markup', () => {
  const helpers = loadHelpers();
  assert.equal(
    helpers.uploadMarker('local-1'),
    '<!-- xboard-knowledge-upload:local-1 -->',
  );

  const attachment = {
    uuid: '11111111-1111-4111-8111-111111111111',
    original_name: 'guide.png',
    mime_type: 'image/png',
    placeholder: 'knowledge-attachment://11111111-1111-4111-8111-111111111111',
    disposition: 'inline',
  };
  const snippet = helpers.markdownFor(attachment);
  const removed = helpers.removeAttachmentMarkup(`# Guide\n\n${snippet}\n\nEnd`, attachment);
  assert.equal(removed.count, 1);
  assert.doesNotMatch(removed.body, /knowledge-attachment:\/\//);
  assert.match(removed.body, /# Guide/);
  assert.match(removed.body, /End/);
});

test('knowledge attachment helper only accepts pasted clipboard images', () => {
  const helpers = loadHelpers();
  const image = { name: 'capture.png', type: 'image/png', size: 4 };
  const archive = { name: 'files.zip', type: 'application/zip', size: 20 };
  const files = helpers.clipboardImages({
    items: [
      { kind: 'string', type: 'text/plain', getAsFile: () => null },
      { kind: 'file', type: archive.type, getAsFile: () => archive },
      { kind: 'file', type: image.type, getAsFile: () => image },
    ],
  });
  assert.equal(files.length, 1);
  assert.equal(files[0], image);
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
  assert.match(source, /\/knowledge\/attachment\/upload\/\$\{item\.uploadUuid\}\/cancel/);
  assert.match(source, /dataTransfer\?\.files/);
  assert.match(source, /addEventListener\('paste'/);
  assert.match(source, /ensurePreviewVisible/);
  assert.match(source, /knowledge-attachment-preview-delete/);
  assert.match(source, /state\.input\.click\(\)/);
  assert.match(source, /\.rc-md-navigation \.button-wrap/);
  assert.doesNotMatch(source, /popoverMarkup/);
  assert.doesNotMatch(source, /setPopoverOpen/);
  assert.doesNotMatch(styles, /\.knowledge-attachment-panel/);
  assert.match(styles, /\.knowledge-attachment-trigger/);
  assert.doesNotMatch(styles, /\.knowledge-attachment-popover/);
  assert.match(styles, /\.knowledge-attachment-preview:hover \.knowledge-attachment-preview-delete/);
  assert.match(styles, /\.rc-md-editor\.is-attachment-dragging/);
  assert.doesNotMatch(styles, /\.knowledge-attachment-list/);
});
