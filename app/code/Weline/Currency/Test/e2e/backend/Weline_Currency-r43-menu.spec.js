/** @weline-e2e-spec { module: Weline_Currency, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Currency';
const PARENT = 'Weline_Backend::currency_group';
const FIXTURE = path.join(__dirname, 'Weline_Currency-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['Weline_Currency::currency_list', '货币列表', 'currency-list-management', 'CK-R43-CURRENCY-001'],
  ['Weline_Currency::currency_config', '货币配置', 'currency-config-management', 'CK-R43-CURRENCY-002'],
];
moduleDescribe(test, MODULE, 'R4.3 货币后台菜单', () => {
  for (const [source, title, anchor, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CURRENCY-WRITE-001' }, '货币列表通过菜单新增货币并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_currency' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[0][0], { parentSources: [PARENT], title: ITEMS[0][1], pageAnchor: `[data-testid="${ITEMS[0][2]}"]` });
      await page.getByTestId('currency-create').click();
      const form = page.getByTestId('currency-editor-form');
      await form.locator('[name="code"]').fill(fixture.code);
      await form.locator('[name="name"]').fill(fixture.name);
      await form.locator('[name="rate"]').fill('1.2345');
      await form.locator('[name="symbol"]').fill('¤');
      await form.locator('[name="base_currency"]').fill('CNY');
      await page.getByTestId('currency-editor-submit').click();
      await page.waitForLoadState('domcontentloaded');
      const persisted = runFixture({ action: 'inspect_currency', code: fixture.code, name: fixture.name });
      expect(persisted.currency_id).toBeGreaterThan(0);
      expect(persisted.rate).toBe(1.2345);
      await page.reload();
      const list = page.getByTestId('currency-list-management');
      expect(Number(await list.getAttribute('data-record-count')), 'currency list query returned no rows').toBeGreaterThan(0);
      await expect(page.locator('body')).toContainText(fixture.name);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_currency', code: fixture.code });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CURRENCY-WRITE-002' }, '货币配置通过菜单切换汇率模式并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_config' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[1][0], { parentSources: [PARENT], title: ITEMS[1][1], pageAnchor: `[data-testid="${ITEMS[1][2]}"]` });
      const form = page.getByTestId('currency-config-form');
      await form.locator(`input[name="rate_mode"][value="${fixture.target_mode}"]`).check();
      const importer = form.locator('input[name="import_enabled"]');
      if (await importer.isChecked()) await importer.uncheck();
      await page.getByTestId('currency-config-submit').click();
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator(`input[name="rate_mode"][value="${fixture.target_mode}"]`)).toBeChecked();
      const persisted = runFixture({ action: 'inspect_config', expected_mode: fixture.target_mode });
      expect(persisted.mode).toBe(fixture.target_mode);
      expect(persisted.import_enabled).toBe(false);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_config', original_mode: fixture.original_mode, original_import_enabled: fixture.original_import_enabled });
    }
  });
});

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`Currency fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 Currency write cases require WELINE_E2E_ISOLATED_DB=1');
}
