/**
 * 配置中心应用默认 CSP 提示：默认折叠，可展开查看全文。
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: e2e, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SystemConfig';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error|Call to undefined function/i;

function configCenterPath(query = '') {
  const base = buildModuleBackendRoute(MODULE, 'config');
  return query ? `${base}?${query}` : base;
}

moduleDescribe(test, MODULE, 'Weline_SystemConfig CSP 提示折叠', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'SYSCONFIG-CSP-HINT-001' },
    '应用默认 CSP 提示默认折叠且可展开',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, configCenterPath('module=Weline_Framework&area=backend&search=security'), {
        timeout: 60000,
        settleMs: 1000,
      });
      await waitForBackendShellReady(page);

      const root = page.locator('[data-testid="system-config-management"]');
      await expect(root).toBeVisible({ timeout: 20000 });
      await expect(root).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/统一配置中心|System Config|配置中心/i);

      const hint = root.locator('[data-testid="system-config-collapsible-hint"]').first();
      await expect(hint).toBeVisible({ timeout: 20000 });
      await expect(hint.locator('.w-system-config__hint-summary-title')).toContainText(/应用默认 CSP|Application default CSP/i);

      const details = hint.locator('details.w-system-config__hint-details').first();
      await expect(details).toBeVisible();
      await expect.poll(async () => details.evaluate((el) => el.open)).toBe(false);

      const body = details.locator('.w-system-config__hint-body');
      await expect(body).toBeHidden();

      await details.locator('summary').click();
      await expect.poll(async () => details.evaluate((el) => el.open)).toBe(true);
      await expect(body).toBeVisible();
      await expect(body).toContainText(/connect-src|font-src|frame-src/i);

      await details.locator('summary').click();
      await expect.poll(async () => details.evaluate((el) => el.open)).toBe(false);
      await expect(body).toBeHidden();
    }
  );
});
