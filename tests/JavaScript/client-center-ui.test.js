const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('theme/Xboard/assets/client-center.js', 'utf8');
const styles = fs.readFileSync('theme/Xboard/assets/client-center.css', 'utf8');
const distributor = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');
const dashboard = fs.readFileSync('theme/Xboard/dashboard.blade.php', 'utf8');

test('shared client center is loaded by the XBoard theme', () => {
  assert.match(dashboard, /client-center\.css/);
  assert.match(dashboard, /client-center\.js/);
  assert.match(source, /window\.XBoardClientCenter = \{ mount, refreshNormal: schedule \}/);
});

test('catalog shows four per-platform actions while hiding optional empty links', () => {
  assert.match(source, /✓ HWID/);
  assert.match(source, /data-platform-filter/);
  assert.match(source, /data-direct-download/);
  assert.match(source, /data-qr-download/);
  assert.match(source, /data-cloud-download/);
  assert.match(source, /data-tutorial/);
  assert.match(source, /download\.cloud_url \|\| ''/);
  assert.match(source, /download\.tutorial_url \|\| ''/);
  assert.match(source, /target\.addEventListener\('change'/);
  assert.match(source, /\/user\/client-catalog\/qr\?client=/);
  assert.match(source, /未单独配置扫码链接时，将使用直接下载地址/);
  assert.match(styles, /\.xcc-grid/);
  assert.match(styles, /\.xcc-buttons/);
  assert.match(styles, /\.xcc-backdrop/);
});

test('requested client priority is defined by the server catalog order', () => {
  const catalog = fs.readFileSync('config/client_catalog.php', 'utf8');
  const ids = [...catalog.matchAll(/'id'\s*=>\s*'([^']+)'/g)].map((match) => match[1]);
  assert.deepEqual(ids.slice(0, 4), ['karing', 'happ', 'clash-mi', 'koalaclash']);
});

test('normal accounts receive a client download menu and distributor accounts use a native route', () => {
  assert.match(source, /window\.location\.hash = '#\/knowledge\?client-center=1'/);
  assert.match(source, /item\.textContent = '▦  客户端下载'/);
  assert.match(distributor, /clients: '客户端下载'/);
  assert.match(distributor, /data-nav="\/clients"/);
  assert.match(distributor, /async function renderClients\(\)/);
  assert.match(distributor, /XBoardClientCenter\.mount/);
});

test('distributor client navigation rides the existing knowledge route without being reset by the SPA router', () => {
  assert.match(distributor, /path === '\/knowledge' && new URLSearchParams\(query\)\.get\('client-center'\) === '1'\) return '\/clients'/);
  assert.match(distributor, /path === '\/clients' \? '#\/knowledge\?client-center=1'/);
  assert.match(distributor, /function isCurrentRouteCanonical\(page\)/);
  assert.match(distributor, /if \(!isCurrentRouteCanonical\(page\)\)/);
});
