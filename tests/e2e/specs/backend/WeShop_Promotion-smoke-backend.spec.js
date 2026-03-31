// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Promotion';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

test.describe('WeShop Promotion backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, useProxy: false });
  });

  const routes = [
    buildModuleBackendRoute(MODULE_NAME, 'campaign', 'index'),
    `${buildModuleBackendRoute(MODULE_NAME, 'campaign', 'edit')}?id=1`,
    buildModuleBackendRoute(MODULE_NAME, 'coupon', 'index'),
  ];

  for (const route of routes) {
    test(`GET ${route} renders without fatal runtime errors`, async ({ page }) => {
      await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
        useProxy: false,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      await expect(body).not.toContainText(FATAL_PATTERN);
    });
  }
});
