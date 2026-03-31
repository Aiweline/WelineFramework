// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop Subscription backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  test('renders subscription list page without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Subscription', 'subscription');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('renders subscription module root page without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Subscription');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('renders subscription plan page without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Subscription', 'plan');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
