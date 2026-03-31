// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop Customer backend (smoke)', () => {
  test.describe.configure({ retries: 1 });
  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders customer management index without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Customer', 'customer', 'index');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });
    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('renders customer detail page (id=1) without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Customer', 'customer', 'view', 'index');
    await gotoBackend(page, `${route}?id=1`, {
      timeout: 60000,
      settleMs: 1000,
    });
    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
