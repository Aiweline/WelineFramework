/** @weline-e2e-spec { module: Weline_CustomerAsset, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_CustomerAsset';
const PARENT = 'Weline_CustomerAsset::commerce:partner:control-center';
const FIXTURE = path.join(__dirname, 'Weline_CustomerAsset-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['accounts', '资产账户', 'CK-R43-ASSET-001'], ['ledger', '资产账本', 'CK-R43-ASSET-002'],
  ['reservations', '资产预留', 'CK-R43-ASSET-003'], ['settlements', '资产结算', 'CK-R43-ASSET-004'],
  ['returns', '资产退回', 'CK-R43-ASSET-005'], ['exceptions', '一致性异常', 'CK-R43-ASSET-006'],
  ['migration', '迁移状态', 'CK-R43-ASSET-007'],
];
moduleDescribe(test, MODULE, 'R4.3 客户资产后台菜单', () => {
  for (const [code, title, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, `Weline_CustomerAsset::commerce:partner:${code}`, {
      parentSources: [PARENT], title, pageAnchor: `[data-testid="customer-asset-${code}-management"]`,
    });
    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-ASSET-WRITE-001' }, '通过菜单向 sandbox 资产账户入账并回查追加账本', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_credit' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[0], 'accounts');
      await fillIdentityAmountForm(page.getByTestId('customer-asset-accounts-write-form'), fixture);
      await page.getByTestId('customer-asset-accounts-submit').click();
      await expect(page.locator('body')).toContainText(fixture.customer_id);
      const persisted = runFixture({ action: 'inspect_credit', token: fixture.token });
      expect(persisted.account.available_minor).toBe(fixture.amount_minor);
      expect(persisted.ledger.event_type).toBe('credit');
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-ASSET-WRITE-002' }, '通过菜单预留资产并回查余额与预留记录', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_reserve' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[2], 'reservations');
      await fillIdentityAmountForm(page.getByTestId('customer-asset-reservations-write-form'), fixture);
      await page.getByTestId('customer-asset-reservations-submit').click();
      await expect(page.locator('body')).toContainText(fixture.customer_id);
      const persisted = runFixture({ action: 'inspect_reserve', token: fixture.token });
      expect(persisted.reservation.status).toBe('reserved');
      expect(persisted.account.reserved_minor).toBe(fixture.amount_minor);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-ASSET-WRITE-003' }, '通过菜单结算预留并回查 committed 状态与账本', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_commit' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[3], 'settlements');
      const form = page.getByTestId('customer-asset-settlements-write-form');
      await form.locator('[name="reservation_id"]').fill(fixture.reservation_id);
      await form.locator('[name="event_id"]').fill(fixture.event_id);
      await page.getByTestId('customer-asset-settlements-submit').click();
      await expect(page.locator('body')).toContainText(fixture.reservation_id);
      const persisted = runFixture({ action: 'inspect_commit', token: fixture.token, reservation_id: fixture.reservation_id });
      expect(persisted.reservation.status).toBe('committed');
      expect(persisted.ledger.event_type).toBe('commit');
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-ASSET-WRITE-004' }, '通过菜单退回已结算资产并在账本工作台显示结果', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_return' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[4], 'returns');
      const form = page.getByTestId('customer-asset-returns-write-form');
      await form.locator('[name="reservation_id"]').fill(fixture.reservation_id);
      await form.locator('[name="amount_minor"]').fill(String(fixture.amount_minor));
      await form.locator('[name="event_id"]').fill(fixture.event_id);
      await page.getByTestId('customer-asset-returns-submit').click();
      await expect(page.locator('body')).toContainText(fixture.event_id);
      const persisted = runFixture({ action: 'inspect_return', token: fixture.token, reservation_id: fixture.reservation_id });
      expect(persisted.reservation.returned_amount_minor).toBe(fixture.amount_minor);
      expect(persisted.ledger.event_type).toBe('return');
      await openBackendMenuBySource(page, 'Weline_CustomerAsset::commerce:partner:ledger', {
        parentSources: [PARENT], title: ITEMS[1][1], pageAnchor: '[data-testid="customer-asset-ledger-management"]',
      });
      await expect(page.locator('body')).toContainText(fixture.event_id);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });
});

async function openWorkspace(page, item, code) {
  await loginAsAdmin(page);
  await openBackendMenuBySource(page, `Weline_CustomerAsset::commerce:partner:${code}`, {
    parentSources: [PARENT], title: item[1], pageAnchor: `[data-testid="customer-asset-${code}-management"]`,
  });
}

async function fillIdentityAmountForm(form, fixture) {
  await form.locator('[name="customer_id"]').fill(fixture.customer_id);
  await form.locator('[name="website_id"]').fill(String(fixture.website_id));
  await form.locator('[name="asset_code"]').fill(fixture.asset_code);
  await form.locator('[name="namespace"]').selectOption(fixture.namespace);
  await form.locator('[name="amount_minor"]').fill(String(fixture.amount_minor));
  await form.locator('[name="event_id"]').fill(fixture.event_id);
}

function cleanupFixture(fixture) {
  const cleaned = runFixture({ action: 'cleanup', token: fixture.token, rollout_before: fixture.rollout_before });
  expectCleanupContract(cleaned);
}

function expectCleanupContract(cleaned) {
  expect(cleaned.cleaned).toBe(true);
  expect(cleaned.rollout_restore?.business_state_restored).toBe(true);
  expect(cleaned.rollout_restore?.audit?.monotonic_audit_pass).toBe(true);
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
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 CustomerAsset write cases require WELINE_E2E_ISOLATED_DB=1');
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`CustomerAsset fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}
