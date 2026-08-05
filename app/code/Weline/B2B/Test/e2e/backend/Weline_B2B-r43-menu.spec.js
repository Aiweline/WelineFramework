/** @weline-e2e-spec { module: Weline_B2B, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_B2B';
const PARENT = 'Weline_B2B::commerce:partner:control-center';
const FIXTURE = path.join(__dirname, 'Weline_B2B-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['groups', '客户组', 'CK-R43-B2B-001'], ['price-lists', '价目表', 'CK-R43-B2B-002'],
  ['quotes', '报价令牌', 'CK-R43-B2B-003'], ['snapshots', '订单价格快照', 'CK-R43-B2B-004'],
  ['migration', '迁移状态', 'CK-R43-B2B-005'],
];
moduleDescribe(test, MODULE, 'R4.3 B2B 后台菜单', () => {
  for (const [code, title, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, `Weline_B2B::commerce:partner:${code}`, {
      parentSources: [PARENT], title, pageAnchor: `[data-testid="b2b-${code}-management"]`,
    });
    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-B2B-WRITE-001' }, '通过菜单创建客户组并回查 PostgreSQL', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_group' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[0], 'groups');
      const form = page.getByTestId('b2b-groups-write-form');
      await form.locator('[name="group_id"]').fill(fixture.group_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await form.locator('[name="code"]').fill(fixture.group_code);
      await form.locator('[name="status"]').selectOption('active');
      await page.getByTestId('b2b-groups-submit').click();
      await expect(page.locator('body')).toContainText(fixture.group_id);
      expect(runFixture({ action: 'inspect_group', token: fixture.token }).group_id).toBe(fixture.group_id);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-B2B-WRITE-002' }, '通过菜单创建版本化价目表及 SKU 金额', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_price_list' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[1], 'price-lists');
      const form = page.getByTestId('b2b-price-lists-write-form');
      await form.locator('[name="list_id"]').fill(fixture.list_id);
      await form.locator('[name="group_id"]').fill(fixture.group_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await form.locator('[name="version"]').fill('1');
      await form.locator('[name="sku"]').fill(fixture.sku);
      await form.locator('[name="amount_minor"]').fill(String(fixture.amount_minor));
      await page.getByTestId('b2b-price-lists-submit').click();
      await expect(page.locator('body')).toContainText(fixture.list_id);
      expect(runFixture({ action: 'inspect_price_list', token: fixture.token }).amount_minor).toBe(fixture.amount_minor);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-B2B-WRITE-003' }, '通过菜单审批报价并生成不可变订单价格快照', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_quote' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[2], 'quotes');
      const form = page.getByTestId('b2b-quotes-write-form');
      await form.locator('[name="customer_id"]').fill(fixture.customer_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await form.locator('[name="sku"]').fill(fixture.sku);
      await form.locator('[name="retail_amount_minor"]').fill(String(fixture.retail_amount_minor));
      await form.locator('[name="order_ref"]').fill(fixture.order_ref);
      await page.getByTestId('b2b-quotes-submit').click();
      await expect(page.locator('body')).toContainText(fixture.order_ref);
      const persisted = runFixture({ action: 'inspect_quote', token: fixture.token });
      expect(persisted.snapshot_amount_minor).toBe(fixture.amount_minor);
      await openBackendMenuBySource(page, 'Weline_B2B::commerce:partner:snapshots', {
        parentSources: [PARENT], title: ITEMS[3][1], pageAnchor: '[data-testid="b2b-snapshots-management"]',
      });
      await expect(page.locator('body')).toContainText(fixture.order_ref);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });
});

async function openWorkspace(page, item, code) {
  await loginAsAdmin(page);
  await openBackendMenuBySource(page, `Weline_B2B::commerce:partner:${code}`, {
    parentSources: [PARENT], title: item[1], pageAnchor: `[data-testid="b2b-${code}-management"]`,
  });
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
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 B2B write cases require WELINE_E2E_ISOLATED_DB=1');
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`B2B fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}
