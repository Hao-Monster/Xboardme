const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { test, before, after } = require('node:test');
const { expect } = require('playwright/test');
const { launchBrowser } = require('./helpers/browser-runtime.cjs');
const { nativeAdminFixture } = require('./helpers/admin-native-fixture.cjs');

let browser;
before(async () => { browser = await launchBrowser(); });
after(async () => { await browser?.close(); });

for (const change of ['rename', 'revoke']) {
  test(`real native editor in another tab cannot undo a distributor ${change} when only remarks are dirty`, async (t) => {
    const fixture = await nativeAdminFixture(browser, t);
    const oldPage = await fixture.page();
    const editor = await fixture.openNativeEditor(oldPage);
    const currentPage = await fixture.page();
    await fixture.openDistributors(currentPage);

    if (change === 'rename') {
      await currentPage.locator('.admin-dist-user-list [data-distributor-rename="7"]').click();
      await expect(currentPage.locator('#admin-dist-rename-name')).toBeEnabled();
      await currentPage.locator('#admin-dist-rename-name').fill('QA New Merchant');
      await currentPage.locator('#admin-dist-rename [type="submit"]').click();
      await expect(currentPage.locator('#admin-dist-rename')).toHaveCount(0);
      await expect(currentPage.locator('.admin-dist-user-list strong')).toHaveText('QA New Merchant');
      assert.equal(fixture.state.user.distributor_name, 'QA New Merchant');
    } else {
      await currentPage.locator('.admin-dist-user-list [data-user-toggle="7"]').click();
      await expect(currentPage.locator('.admin-dist-user-list')).toHaveText('暂无分销商');
      assert.equal(fixture.state.user.is_distributor, false);
    }

    const identityAfterExplicitChange = {
      distributor_name: fixture.state.user.distributor_name,
      distributor_revision: fixture.state.user.distributor_revision,
      is_distributor: fixture.state.user.is_distributor,
    };
    // Page A deliberately stays stale. Edit and submit using the actual React
    // textarea/button instead of constructing a test-only request by hand.
    await expect(editor.locator('[data-distributor-name-value]')).toHaveText('QA Old Merchant');
    await editor.getByLabel('备注', { exact: true }).fill('QA changed remarks only');
    await editor.getByRole('button', { name: '提交', exact: true }).click();
    await expect(editor).toHaveCount(0);
    const remarkRequest = fixture.state.updates.find((body) => body.remarks === 'QA changed remarks only');
    assert.deepEqual(remarkRequest, { id: 7, remarks: 'QA changed remarks only' });
    assert.deepEqual({
      distributor_name: fixture.state.user.distributor_name,
      distributor_revision: fixture.state.user.distributor_revision,
      is_distributor: fixture.state.user.is_distributor,
    }, identityAfterExplicitChange);
    assert.equal(fixture.state.user.balance, 12345);
    assert.equal(fixture.state.user.commission_balance, 6789);
  });
}

test('late native user refresh preserves an explicit, unsaved distributor-role cancellation', async (t) => {
  const fixture = await nativeAdminFixture(browser, t);
  const page = await fixture.page();
  const editor = await fixture.openNativeEditor(page);
  const checkbox = editor.locator('.xboard-distributor-injected input[type="checkbox"]');
  fixture.state.user.distributor_name = 'QA Read Name';
  fixture.state.user.distributor_revision = randomUUID();
  fixture.state.holdUserFetch = true;
  await page.evaluate(() => {
    window.qaPendingRead = fetch('/api/v2/qa-admin/user/fetch', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ current: 1, pageSize: 20 }),
    }).then((response) => response.json());
  });
  await expect.poll(() => typeof fixture.state.releaseUserFetch).toBe('function');
  await editor.locator('.admin-dist-switch').click();
  await expect(checkbox).not.toBeChecked();
  fixture.state.holdUserFetch = false;
  fixture.state.releaseUserFetch();
  await page.evaluate(() => window.qaPendingRead);
  // Updated name text proves that the delayed cache synchronization actually ran.
  await expect(editor.locator('[data-distributor-name-value]')).toHaveText('QA Read Name');
  await expect(checkbox).not.toBeChecked();
  await editor.getByLabel('备注', { exact: true }).fill('QA intentional role cancellation');
  await editor.getByRole('button', { name: '提交', exact: true }).click();
  await expect(editor).toHaveCount(0);
  assert.deepEqual(fixture.state.updates, [{
    id: 7, remarks: 'QA intentional role cancellation', is_distributor: 0,
  }]);
  assert.equal(fixture.state.user.is_distributor, false);
  assert.equal(fixture.state.user.distributor_name, null);
});

test('native editor remains bound to the user ID when its email is an unsaved draft', async (t) => {
  const fixture = await nativeAdminFixture(browser, t);
  const page = await fixture.page();
  const editor = await fixture.openNativeEditor(page);
  await editor.getByLabel('邮箱', { exact: true }).fill('qa-email-draft@example.invalid');
  fixture.state.user.distributor_name = 'QA Bound ID';
  fixture.state.user.distributor_revision = randomUUID();
  await page.evaluate(async () => {
    const response = await fetch('/api/v2/qa-admin/user/getUserInfoById?id=7');
    await response.json();
  });
  await expect(editor.locator('[data-distributor-name-value]')).toHaveText('QA Bound ID');
  await expect(editor.getByLabel('邮箱', { exact: true })).toHaveValue('qa-email-draft@example.invalid');
  await expect(editor.locator('.xboard-distributor-injected input[type="checkbox"]')).toBeChecked();
  await editor.getByLabel('备注', { exact: true }).fill('QA email draft retained');
  await editor.getByRole('button', { name: '提交', exact: true }).click();
  await expect(editor).toHaveCount(0);
  assert.deepEqual(fixture.state.updates, [{ id: 7, email: 'qa-email-draft@example.invalid', remarks: 'QA email draft retained' }]);
  assert.equal(fixture.state.user.distributor_name, 'QA Bound ID');
  assert.equal(fixture.state.user.is_distributor, true);
  assert.equal(fixture.state.user.email, 'qa-email-draft@example.invalid');
});

test('committed email changes evict the old native cache alias before later identity and role refreshes', async (t) => {
  const fixture = await nativeAdminFixture(browser, t);
  const page = await fixture.page();
  const editor = await fixture.openNativeEditor(page);
  const checkbox = editor.locator('.xboard-distributor-injected input[type="checkbox"]');
  // Unlike an unsaved form draft, this email change has already committed on
  // the server while the native editor still holds the original account data.
  fixture.state.user.email = 'qa-committed-email@example.invalid';
  fixture.state.user.distributor_name = 'QA Moved Name';
  fixture.state.user.distributor_revision = randomUUID();
  async function refreshById() {
    await page.evaluate(async () => {
      const response = await fetch('/api/v2/qa-admin/user/getUserInfoById?id=7');
      await response.json();
    });
  }
  await refreshById();
  await expect(editor.locator('[data-distributor-name-value]')).toHaveText('QA Moved Name');
  await expect(checkbox).toBeChecked();

  fixture.state.user.is_distributor = false;
  fixture.state.user.distributor_name = null;
  fixture.state.user.distributor_revision = randomUUID();
  await refreshById();
  await expect(checkbox).not.toBeChecked();
  await expect(editor.locator('[data-distributor-name-value]')).toHaveText('');
  await editor.getByLabel('备注', { exact: true }).fill('QA committed identity retained');
  await editor.getByRole('button', { name: '提交', exact: true }).click();
  await expect(editor).toHaveCount(0);
  assert.deepEqual(fixture.state.updates, [{ id: 7, remarks: 'QA committed identity retained' }]);
  assert.equal(fixture.state.user.email, 'qa-committed-email@example.invalid');
  assert.equal(fixture.state.user.distributor_name, null);
  assert.equal(fixture.state.user.is_distributor, false);
});
