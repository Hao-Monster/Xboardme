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

test('catalog shows HWID-only clients with platform filters and direct or QR downloads', () => {
  assert.match(source, /✓ HWID/);
  assert.match(source, /data-platform-filter/);
  assert.match(source, /data-direct-download/);
  assert.match(source, /data-qr-download/);
  assert.match(source, /\/user\/client-catalog\/qr\?client=/);
  assert.match(source, /二维码将直接进入官方安装包或官方应用商店/);
  assert.match(styles, /\.xcc-grid/);
  assert.match(styles, /\.xcc-backdrop/);
});

test('normal accounts receive a client download menu and distributor accounts use a native route', () => {
  assert.match(source, /window\.location\.hash = '#\/knowledge\?client-center=1'/);
  assert.match(source, /item\.textContent = '▦  客户端下载'/);
  assert.match(distributor, /clients: '客户端下载'/);
  assert.match(distributor, /data-nav="\/clients"/);
  assert.match(distributor, /async function renderClients\(\)/);
  assert.match(distributor, /XBoardClientCenter\.mount/);
});
