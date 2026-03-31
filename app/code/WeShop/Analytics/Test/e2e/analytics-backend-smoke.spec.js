// @weline-e2e-runtime fallback
// @ts-check

const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
} = require('../../../../../../tests/e2e/framework');

const MODULE_NAME = 'WeShop_Analytics';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error/i;

test.describe('WeShop Analytics module e2e smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('analytics backend page renders provider form safely', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'analytics');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Analytics Management/i);
    await expect(body).not.toContainText(FATAL_PATTERN);

    const providerForm = page.locator('form[data-analytics-provider-form]');
    await expect(providerForm).toBeVisible();
    await expect(providerForm).toHaveAttribute('action', /analytics\/save/i);
  });
});
