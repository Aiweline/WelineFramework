// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop_Social backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test('renders social configuration page without PHP fatal errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Social', 'social');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Social Configuration|社交配置/i);
    await expect(body).toContainText(/Footer Social Links|页脚社交链接/i);
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
