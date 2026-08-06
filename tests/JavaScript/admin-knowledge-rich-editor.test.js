const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

function loadHelpers() {
  const source = fs.readFileSync('public/assets/admin-knowledge-rich-editor.js', 'utf8');
  const window = {};
  vm.runInNewContext(source, { window, console }, { filename: 'admin-knowledge-rich-editor.js' });
  return window.__xboardKnowledgeRichText;
}

function text(value) {
  return { nodeType: 3, nodeValue: value, textContent: value };
}

function element(tagName, attributes = {}, children = []) {
  return {
    nodeType: 1,
    tagName,
    attributes,
    childNodes: children,
    getAttribute: (name) => attributes[name] ?? null,
    get textContent() {
      return children.map((child) => child.textContent || child.nodeValue || '').join('');
    },
  };
}

test('rich editor serializes supported article structure to Markdown', () => {
  const helpers = loadHelpers();
  const uuid = '11111111-1111-4111-8111-111111111111';
  const root = element('DIV', {}, [
    element('H2', {}, [text('安装教程')]),
    element('P', {}, [text('打开 '), element('STRONG', {}, [text('客户端')])]),
    element('IMG', { src: `knowledge-attachment://${uuid}`, alt: '步骤图' }),
    element('A', { href: `knowledge-attachment://${uuid}` }, [text('下载文件')]),
  ]);
  const markdown = helpers.domToMarkdown(root);
  assert.match(markdown, /^## 安装教程/);
  assert.match(markdown, /\*\*客户端\*\*/);
  assert.match(markdown, /!\[步骤图\]\(knowledge-attachment:\/\//);
  assert.match(markdown, /\[下载文件\]\(knowledge-attachment:\/\//);
});

test('rich editor keeps video placeholders and list semantics', () => {
  const helpers = loadHelpers();
  const uuid = '22222222-2222-4222-8222-222222222222';
  const root = element('DIV', {}, [
    element('VIDEO', { src: `knowledge-attachment://${uuid}` }),
    element('OL', {}, [element('LI', {}, [text('第一步')]), element('LI', {}, [text('第二步')])]),
  ]);
  const markdown = helpers.domToMarkdown(root);
  assert.match(markdown, /<video controls preload="metadata"/);
  assert.match(markdown, /1\. 第一步/);
  assert.match(markdown, /2\. 第二步/);
});

test('rich editor rejects executable URLs and malformed attachment placeholders', () => {
  const helpers = loadHelpers();
  assert.equal(helpers.safeUrl('javascript:alert(1)'), '');
  assert.equal(helpers.safeUrl('data:text/html,boom'), '');
  assert.equal(helpers.safeUrl('knowledge-attachment://not-a-uuid'), '');
  assert.equal(helpers.safeUrl('https://cloud.thinderbox.com/file'), 'https://cloud.thinderbox.com/file');
});

test('rich editor normalizes legacy whitespace without changing content', () => {
  const helpers = loadHelpers();
  assert.equal(helpers.normalizeMarkdown('标题  \n\n\n\n正文\u00a0内容  '), '标题\n\n正文 内容');
});
