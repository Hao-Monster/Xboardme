const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const adminRoot = 'public/assets/admin';
const manifest = JSON.parse(fs.readFileSync(path.join(adminRoot, 'manifest.json'), 'utf8'));
const bundleRelativePath = manifest['index.html'].file;
const bundle = fs.readFileSync(path.join(adminRoot, bundleRelativePath), 'utf8');

test('plan form persists the distributor order HWID default with a default of one', () => {
  assert.match(bundle, /distributor_hwid_limit:my\(\[dy\(\),cy\(\)\]\)\.nullable\(\)\.optional\(\)/);
  assert.match(bundle, /distributor_hwid_limit:"1"/);
  assert.match(bundle, /name:"distributor_hwid_limit",label:c\("plan\.form\.distributor_hwid\.label"\),type:"number",min:1/);

  const digest = crypto.createHash('sha256').update(bundle).digest('hex').slice(0, 10);
  assert.equal(bundleRelativePath, `assets/index-hwid-${digest}.js`);
  assert.equal(fs.readFileSync(path.join(adminRoot, 'index.html'), 'utf8').includes(`./${bundleRelativePath}`), true);
});

test('all shipped admin locales label the distributor HWID default separately from the user device limit', () => {
  for (const locale of ['zh-CN', 'en-US', 'ru-RU']) {
    const source = fs.readFileSync(path.join(adminRoot, 'locales', `${locale}.js`), 'utf8');
    assert.match(source, /"distributor_hwid":\s*\{/);
  }
});
