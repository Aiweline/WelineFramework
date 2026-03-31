// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Price';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

test.describe('WeShop_Price backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  const routesTried = [
    buildModuleBackendRoute(MODULE_NAME, 'price'),
    `${buildModuleBackendRoute(MODULE_NAME, 'price', 'config')}?product_id=1`,
  ];

  for (const route of routesTried) {
    test(`GET ${route} renders without fatal runtime errors`, async ({ page }) => {
      await gotoBackend(page, route, {
        timeout: 60000,
        settleMs: 1200,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      await expect(body).not.toContainText(FATAL_PATTERN);
    });
  }
});
