// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_ImportExport';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

test.describe('WeShop ImportExport backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('TC-01: renders import-export index page without fatal errors', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'import-export');
    await gotoBackend(page, route, { timeout: 90000, settleMs: 1200 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('TC-02: export action is reachable (download redirect allowed)', async ({ page }) => {
    const route = `${buildModuleBackendRoute(MODULE_NAME, 'import-export', 'export')}?entity=products`;
    const downloadErrorPattern = /Download is starting/i;

    try {
      await gotoBackend(page, route, { timeout: 90000, settleMs: 1200, readySelector: 'body' });
      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
    } catch (error) {
      const message = String(error && error.message ? error.message : error);
      expect(message).toMatch(downloadErrorPattern);
    }
  });
});
