const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const source = fs.readFileSync('public/assets/admin-client-catalog.js', 'utf8');
const styles = fs.readFileSync('public/assets/admin-client-catalog.css', 'utf8');
const blade = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
const workflow = fs.readFileSync('.github/workflows/docker-publish.yml', 'utf8');

test('admin loads the client catalog enhancement and validates it in CI', () => {
  assert.match(blade, /admin-client-catalog\.css/);
  assert.match(blade, /admin-client-catalog\.js/);
  assert.match(workflow, /node --check public\/assets\/admin-client-catalog\.js/);
});

test('client management menu is cloned directly after knowledge management', () => {
  assert.match(source, /knowledge\.insertAdjacentElement\('afterend', item\)/);
  assert.match(source, /item\.classList\.add\('xboard-client-catalog-nav'\)/);
  assert.match(source, /客户端管理/);
  assert.match(source, /client-catalog=1/);
});

test('admin editor exposes four links for every supported platform and saves all clients', () => {
  assert.match(source, /\['direct', '直接下载'/);
  assert.match(source, /\['qr', '扫码下载'/);
  assert.match(source, /\['cloud', '网盘下载'/);
  assert.match(source, /\['tutorial', '使用教程'/);
  assert.match(source, /data-acc-platform/);
  assert.match(source, /request\('\/client-catalog\/save'/);
  assert.match(styles, /\.acc-fields/);
});
