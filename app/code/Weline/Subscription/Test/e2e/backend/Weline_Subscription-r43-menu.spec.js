/** @weline-e2e-spec { module: Weline_Subscription, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Subscription';
const PARENT = 'Weline_Subscription::commerce:partner:control-center';
const FIXTURE = path.join(__dirname, 'Weline_Subscription-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['subscriptions', '订阅', 'CK-R43-SUBSCRIPTION-001'], ['periods', '订阅周期', 'CK-R43-SUBSCRIPTION-002'],
  ['renewals', '续费调度', 'CK-R43-SUBSCRIPTION-003'], ['attempts', '续费尝试', 'CK-R43-SUBSCRIPTION-004'],
  ['missed-watermarks', '失败水位', 'CK-R43-SUBSCRIPTION-005'], ['migration', '迁移状态', 'CK-R43-SUBSCRIPTION-006'],
];
moduleDescribe(test, MODULE, 'R4.3 订阅后台菜单', () => {
  for (const [code, title, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, `Weline_Subscription::commerce:partner:${code}`, {
      parentSources: [PARENT], title, pageAnchor: `[data-testid="subscription-${code}-management"]`,
    });
    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-SUBSCRIPTION-WRITE-001' }, '通过菜单创建订阅并在周期工作台看到首个周期', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_subscription' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[0], 'subscriptions');
      const form = page.getByTestId('subscription-subscriptions-write-form');
      await form.locator('[name="subscription_id"]').fill(fixture.subscription_id);
      await form.locator('[name="customer_id"]').fill(fixture.customer_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await form.locator('[name="store_id"]').fill(String(fixture.store_id));
      await form.locator('[name="provider_code"]').fill(fixture.provider_code);
      await form.locator('[name="plan_code"]').fill(fixture.plan_code);
      await form.locator('[name="idempotency_key"]').fill(fixture.idempotency_key);
      await page.getByTestId('subscription-subscriptions-submit').click();
      await expect(page.locator('body')).toContainText(fixture.subscription_id);
      const persisted = runFixture({ action: 'inspect_subscription', token: fixture.token });
      expect(persisted.period_key).toContain('|p1|');
      await openBackendMenuBySource(page, 'Weline_Subscription::commerce:partner:periods', {
        parentSources: [PARENT], title: ITEMS[1][1], pageAnchor: '[data-testid="subscription-periods-management"]',
      });
      await expect(page.locator('body')).toContainText(fixture.subscription_id);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-SUBSCRIPTION-WRITE-002' }, '通过菜单执行续费并在尝试工作台看到 Order/sandbox Payment 结果', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_renewal' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[2], 'renewals');
      const form = page.getByTestId('subscription-renewals-write-form');
      await form.locator('[name="subscription_id"]').fill(fixture.subscription_id);
      await form.locator('[name="worker_id"]').fill(fixture.worker_id);
      await page.getByTestId('subscription-renewals-submit').click();
      const persisted = runFixture({ action: 'inspect_renewal', token: fixture.token });
      expect(persisted.attempt.order_ref).toBeTruthy();
      expect(persisted.attempt.status).toBe('succeeded');
      expect(persisted.attempt.payment_status).toBe('succeeded');
      expect(persisted.payment_intent).toMatchObject({
        status: 'succeeded', environment: 'sandbox', method_code: 'fake_card', payable_id: persisted.attempt.order_ref,
      });
      expect(persisted.payment_intent.intent_code).toBeTruthy();
      expect(persisted.payment_attempt).toMatchObject({
        status: 'succeeded', environment: 'sandbox', method_code: 'fake_card', payable_id: persisted.attempt.order_ref,
      });
      expect(persisted.payment_attempt.attempt_code).toBeTruthy();
      await openBackendMenuBySource(page, 'Weline_Subscription::commerce:partner:attempts', {
        parentSources: [PARENT], title: ITEMS[3][1], pageAnchor: '[data-testid="subscription-attempts-management"]',
      });
      await expect(page.locator('body')).toContainText(fixture.subscription_id);
      await expect(page.locator('body')).toContainText(persisted.attempt.order_ref);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });
});

async function openWorkspace(page, item, code) {
  await loginAsAdmin(page);
  await openBackendMenuBySource(page, `Weline_Subscription::commerce:partner:${code}`, {
    parentSources: [PARENT], title: item[1], pageAnchor: `[data-testid="subscription-${code}-management"]`,
  });
}

function cleanupFixture(fixture) {
  const cleaned = runFixture({
    action: 'cleanup',
    token: fixture.token,
    rollout_before: fixture.rollout_before,
    payment_method_before: fixture.payment_method_before,
  });
  expectCleanupContract(cleaned, Boolean(fixture.payment_method_before));
}

function expectCleanupContract(cleaned, paymentRestoreExpected) {
  expect(cleaned.cleaned).toBe(true);
  expect(cleaned.rollout_restore?.business_state_restored).toBe(true);
  expect(cleaned.rollout_restore?.audit?.monotonic_audit_pass).toBe(true);
  if (paymentRestoreExpected) {
    expect(cleaned.payment_method_restore?.restored_hash).toBe(cleaned.payment_method_restore?.preimage_hash);
  }
  const reloadExpected = Boolean(process.env.WELINE_E2E_WLS_INSTANCE);
  const reload = cleaned.rollout_restore?.audit?.wls_reload;
  expect(reload?.configured).toBe(reloadExpected);
  expect(reload?.reloaded).toBe(reloadExpected);
  if (reloadExpected) {
    expect(reload.instance).toBe(process.env.WELINE_E2E_WLS_INSTANCE);
    expect(reload.port).toBeGreaterThanOrEqual(9502);
    expect(reload.port).not.toBe(9501);
    expect(reload.exit_code).toBe(0);
    expect(reload.stdout_sha256).toMatch(/^[a-f0-9]{64}$/);
  }
}

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 Subscription write cases require WELINE_E2E_ISOLATED_DB=1');
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`Subscription fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}
