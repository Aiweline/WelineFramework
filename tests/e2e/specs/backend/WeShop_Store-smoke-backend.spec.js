// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop Store backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  /**
   * @param {import('@playwright/test').Page} page
   */
  async function loginWithRetry(page) {
    let lastError = null;
    for (let i = 0; i < 3; i += 1) {
      try {
        await loginAsAdmin(page, { timeout: 90000, settleMs: 1000 });
        return;
      } catch (error) {
        lastError = error;
        const message = String(error && error.message ? error.message : error);
        if (!/ERR_CONNECTION_REFUSED/i.test(message)) {
          throw error;
        }
        await page.waitForTimeout(1500);
      }
    }
    throw lastError || new Error('loginWithRetry failed');
  }

  test.beforeEach(async ({ page }) => {
    await loginWithRetry(page);
  });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
  const moduleRootRoute = buildModuleBackendRoute('WeShop_Store');
  const candidateRoutes = [
    buildModuleBackendRoute('WeShop_Store', 'store'),
    buildModuleBackendRoute('WeShop_Store', 'store', 'index'),
    moduleRootRoute,
  ];

  test('renders at least one store backend route without fatal runtime errors', async ({ page }) => {
    /** @type {Array<{route: string, ok: boolean, reason: string}>} */
    const attempts = [];
    for (const route of candidateRoutes) {
      try {
        await gotoBackend(page, route, {
          timeout: 60000,
          settleMs: 1000,
        });
        const body = page.locator('body');
        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        attempts.push({ route, ok: true, reason: 'ok' });
      } catch (error) {
        const reason = error instanceof Error ? error.message : String(error);
        attempts.push({ route, ok: false, reason });
      }
    }
    const successful = attempts.filter(item => item.ok);
    test.info().annotations.push({
      type: 'routesTried',
      description: JSON.stringify(attempts),
    });
    expect(successful.length, `All store routes failed: ${JSON.stringify(attempts, null, 2)}`).toBeGreaterThan(0);
  });
});
