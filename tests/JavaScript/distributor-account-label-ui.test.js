const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync('theme/Xboard/assets/distributor.js', 'utf8');

function accountLabel() {
  const block = source.match(/function distributorAccountLabel\(user\) \{[\s\S]*?\n  \}/);
  assert.ok(block, 'the distributor account label helper should exist');
  return vm.runInNewContext(`${block[0]}; distributorAccountLabel`);
}

test('distributor account label prefers a non-blank distributor name', () => {
  const label = accountLabel();

  assert.equal(label({ distributor_name: '  华东渠道  ', email: 'dealer@example.com' }), '华东渠道');
  assert.equal(label({ distributor_name: '北区合作伙伴', email: 'dealer@example.com' }), '北区合作伙伴');
});

test('distributor account label falls back to email for blank or missing names', () => {
  const label = accountLabel();

  assert.equal(label({ distributor_name: '   ', email: ' dealer@example.com ' }), 'dealer@example.com');
  assert.equal(label({ email: 'legacy@example.com' }), 'legacy@example.com');
  assert.equal(label({ distributor_name: null, email: 'fallback@example.com' }), 'fallback@example.com');
});

test('distributor account label is escaped at its topbar render boundary', () => {
  assert.match(
    source,
    /<span class="dist-account">● \$\{escapeHtml\(distributorAccountLabel\(state\.user\)\)\}<\/span>/
  );
});
