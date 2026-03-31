// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop Wishlist backend (smoke)', () => {
  test.describe.configure({ retries: 0 });
  test.setTimeout(180000);

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test('renders wishlist management index without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Wishlist', 'wishlist');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('redirects customer wishlist page to index without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Wishlist', 'wishlist', 'view');

    // customer_id<=0 时控制器会重定向回列表；这里验证该链路无 PHP 致命错误。
    await gotoBackend(page, `${route}?customer_id=0`, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
