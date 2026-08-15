const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync('theme/Xboard/assets/distributor-message-guard.js', 'utf8');
const dashboard = fs.readFileSync('theme/Xboard/dashboard.blade.php', 'utf8');

test('distributor access denial is suppressed while unrelated errors still render', () => {
  const calls = [];
  const sandbox = { window: {} };
  vm.runInNewContext(source, sandbox, { filename: 'distributor-message-guard.js' });

  sandbox.window.$message = {
    error(message, options) {
      calls.push([message, options]);
      return 'rendered';
    },
  };

  assert.equal(sandbox.window.$message.error('分销商账号无权访问该功能'), undefined);
  assert.equal(sandbox.window.$message.error('订单加载失败', { duration: 5 }), 'rendered');
  assert.deepEqual(calls, [['订单加载失败', { duration: 5 }]]);
});

test('message guard loads synchronously before the legacy application bundle', () => {
  const guardIndex = dashboard.indexOf('distributor-message-guard.js');
  const applicationIndex = dashboard.indexOf('assets/umi.js');

  assert.ok(guardIndex >= 0);
  assert.ok(applicationIndex > guardIndex);
  assert.doesNotMatch(
    dashboard.slice(dashboard.lastIndexOf('<script', guardIndex), dashboard.indexOf('</script>', guardIndex)),
    /\b(?:async|defer)\b/
  );
});
