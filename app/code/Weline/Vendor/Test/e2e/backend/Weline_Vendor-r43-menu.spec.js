/** @weline-e2e-spec { module: Weline_Vendor, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Vendor';
const PARENT = 'Weline_Vendor::commerce:partner:control-center';
const FIXTURE = path.join(__dirname, 'Weline_Vendor-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['vendors', '商家档案', 'CK-R43-VENDOR-001'], ['authorizations', '站点授权', 'CK-R43-VENDOR-002'],
  ['product-bindings', '商品绑定', 'CK-R43-VENDOR-003'], ['split-rules', '拆分规则', 'CK-R43-VENDOR-004'],
  ['payouts', '结算账本', 'CK-R43-VENDOR-005'], ['reversals', '退款冲正', 'CK-R43-VENDOR-006'],
  ['migration', '迁移状态', 'CK-R43-VENDOR-007'],
];
moduleDescribe(test, MODULE, 'R4.3 商家后台菜单', () => {
  for (const [code, title, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, `Weline_Vendor::commerce:partner:${code}`, {
      parentSources: [PARENT], title, pageAnchor: `[data-testid="vendor-${code}-management"]`,
    });
    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-VENDOR-WRITE-001' }, '通过菜单创建商家档案并回查 PostgreSQL', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_vendor' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[0], 'vendors');
      const form = page.getByTestId('vendor-vendors-write-form');
      await form.locator('[name="code"]').fill(fixture.code);
      await form.locator('[name="legal_name"]').fill(fixture.legal_name);
      await form.locator('[name="environment"]').selectOption(fixture.environment);
      await page.getByTestId('vendor-vendors-submit').click();
      await expect(page.locator('body')).toContainText(fixture.legal_name);
      expect(runFixture({ action: 'inspect_vendor', token: fixture.token }).vendor_id).toMatch(/^vnd_/);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-VENDOR-WRITE-002' }, '通过菜单授权商家站点并回查授权记录', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_authorization' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[1], 'authorizations');
      const form = page.getByTestId('vendor-authorizations-write-form');
      await form.locator('[name="vendor_id"]').fill(fixture.vendor_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await page.getByTestId('vendor-authorizations-submit').click();
      await expect(page.locator('body')).toContainText(fixture.vendor_id);
      expect(runFixture({ action: 'inspect_authorization', token: fixture.token, website_id: fixture.website_id }).row.status).toBe('authorized');
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-VENDOR-WRITE-003' }, '通过菜单绑定真实 SKU 与商家并回查绑定', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_product' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[2], 'product-bindings');
      const form = page.getByTestId('vendor-product-bindings-write-form');
      await form.locator('[name="vendor_id"]').fill(fixture.vendor_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await form.locator('[name="store_id"]').fill(String(fixture.store_id));
      await form.locator('[name="product_sku"]').fill(fixture.sku);
      await page.getByTestId('vendor-product-bindings-submit').click();
      await expect(page.locator('body')).toContainText(fixture.sku);
      expect(runFixture({ action: 'inspect_product', token: fixture.token, sku: fixture.sku }).row.status).toBe('bound');
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-VENDOR-WRITE-004' }, '通过菜单保存拆分规则并回查版本化规则', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_split' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[3], 'split-rules');
      const form = page.getByTestId('vendor-split-rules-write-form');
      await form.locator('[name="vendor_id"]').fill(fixture.vendor_id);
      await form.locator('[name="website_id"]').fill(String(fixture.website_id));
      await form.locator('[name="commission_bps"]').fill(String(fixture.commission_bps));
      await form.locator('[name="currency"]').fill(fixture.currency);
      await form.locator('[name="legal_entity"]').fill(fixture.legal_entity);
      await page.getByTestId('vendor-split-rules-submit').click();
      await expect(page.locator('body')).toContainText(String(fixture.commission_bps));
      expect(runFixture({ action: 'inspect_split', token: fixture.token }).row.commission_bps).toBe(fixture.commission_bps);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-VENDOR-WRITE-005' }, '通过菜单从不可变快照调度内部结算账本', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_payout' });
    const guards = installBackendBrowserGuards(page);
    try {
      await openWorkspace(page, ITEMS[4], 'payouts');
      const form = page.getByTestId('vendor-payouts-write-form');
      await form.locator('[name="snapshot_id"]').fill(fixture.snapshot_id);
      await form.locator('[name="idempotency_key"]').fill(fixture.idempotency_key);
      await page.getByTestId('vendor-payouts-submit').click();
      await expect(page.locator('body')).toContainText(fixture.snapshot_id);
      const persisted = runFixture({ action: 'inspect_payout', token: fixture.token, snapshot_id: fixture.snapshot_id, idempotency_key: fixture.idempotency_key });
      expect(persisted.row.amount_minor).toBe(fixture.expected_amount_minor);
      guards.assertClean();
    } finally { cleanupFixture(fixture); }
  });
});

async function openWorkspace(page, item, code) {
  await loginAsAdmin(page);
  await openBackendMenuBySource(page, `Weline_Vendor::commerce:partner:${code}`, {
    parentSources: [PARENT], title: item[1], pageAnchor: `[data-testid="vendor-${code}-management"]`,
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
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 Vendor write cases require WELINE_E2E_ISOLATED_DB=1');
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`Vendor fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}
