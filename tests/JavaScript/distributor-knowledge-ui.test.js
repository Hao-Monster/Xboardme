const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/distributor.css', 'utf8');

test('distributor navigation exposes documentation after invitations', () => {
  assert.match(source, /invite: '我的邀请', knowledge: '使用文档'/);
  assert.match(source, /\['\/plan', '\/order', '\/invite', '\/knowledge'\]\.includes\(path\)/);
  assert.match(source, /data-nav="\/invite"[\s\S]*data-nav="\/knowledge"/);
  assert.match(source, /page === '\/knowledge'\) await renderKnowledge\(\)/);
});

test('documentation supports search, grouped articles and server-rendered details', () => {
  assert.match(source, /async function renderKnowledge\(\)/);
  assert.match(source, /\/user\/knowledge\/fetch\?\$\{params\}/);
  assert.match(source, /data-knowledge-id/);
  assert.match(source, /new URLSearchParams\(\{ id, language: state\.locale, render: 'html' \}\)/);
  assert.match(source, /dist-knowledge-modal/);
  assert.match(source, /id="dist-knowledge-search"/);
  assert.match(source, /action === 'search-knowledge'/);
  assert.match(source, /event\.target\.id === 'dist-knowledge-search'/);
});

test('every distributor article exposes an isolated permanent share action with success feedback', () => {
  assert.match(source, /article\.share_url \|\| `\$\{window\.location\.origin\}\/guide\/\$\{article\.id\}`/);
  assert.match(source, /class="dist-knowledge-copy"[^>]*data-copy="\$\{escapeHtml\(shareUrl\)\}"[^>]*data-copy-success/);
  assert.match(source, /copyShare: '复制分享'/);
  assert.match(source, /copySuccess: '复制成功'/);
  assert.match(source, /copy\.textContent = t\('copySuccess'\)/);
  assert.match(source, /copy\.classList\.add\('copied'\)/);
  assert.match(source, /copy\.disabled = true/);
  assert.match(styles, /\.dist-knowledge-copy\.copied/);
});

test('documentation styles stay scoped and mobile navigation accommodates four entries', () => {
  assert.match(styles, /\.dist-knowledge-toolbar/);
  assert.match(styles, /\.dist-knowledge-category/);
  assert.match(styles, /\.dist-knowledge-body/);
  assert.match(styles, /\.dist-sidebar nav \{ grid-template-columns:repeat\(4,1fr\); \}/);
});
