const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');

test('branch hooks allow develop commits and pushes but reject main, detached and deletion operations', () => {
  const repository = fs.mkdtempSync(path.join(os.tmpdir(), 'xboard-branch-hooks-'));
  const hooks = path.resolve('.githooks');
  const git = (...args) => {
    const result = spawnSync('git', args, { cwd: repository, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.trim();
  };
  try {
    git('init', '--initial-branch=main');
    const bash = process.platform === 'win32'
      ? path.resolve(git('--exec-path'), '../../../bin/bash.exe')
      : 'bash';
    const run = (hook, args = [], input = '') => spawnSync(bash, [path.join(hooks, hook), ...args], {
      cwd: repository, encoding: 'utf8', input,
    });
    assert.equal(run('pre-commit').status, 1, 'main commits must be rejected');
    assert.equal(run('pre-merge-commit').status, 1, 'main merge commits must be rejected');
    git('switch', '-c', 'develop');
    assert.equal(run('pre-commit').status, 0, 'develop commits must be accepted');
    assert.equal(run('pre-merge-commit').status, 0, 'develop merge commits must be accepted');
    git('-c', 'user.name=Hook Test', '-c', 'user.email=hook@example.invalid',
      '-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', 'fixture');
    git('switch', '--detach');
    assert.equal(run('pre-commit').status, 1, 'detached commits must be rejected');
    const sha = 'a'.repeat(40);
    const zero = '0'.repeat(40);
    const valid = `refs/heads/develop ${sha} refs/heads/develop ${zero}\n`;
    assert.equal(run('pre-push', ['origin'], valid).status, 0);
    assert.equal(run('pre-push', ['upstream'], valid).status, 1);
    assert.equal(run('pre-push', ['origin'], `refs/heads/develop ${sha} refs/heads/main ${sha}\n`).status, 1);
    assert.equal(run('pre-push', ['origin'], `refs/heads/topic ${sha} refs/heads/topic ${zero}\n`).status, 1);
    assert.equal(run('pre-push', ['origin'], `(delete) ${zero} refs/heads/develop ${sha}\n`).status, 1);
  } finally {
    fs.rmSync(repository, { recursive: true, force: true });
  }
});
