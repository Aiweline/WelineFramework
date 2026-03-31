// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop B2B backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error/i;
  const moduleRootRoute = buildModuleBackendRoute('WeShop_B2B');
  const candidateRoutes = [
    buildModuleBackendRoute('WeShop_B2B', 'company'),
    buildModuleBackendRoute('WeShop_B2B', 'receivable'),
    buildModuleBackendRoute('WeShop_B2B', 'b2bcustomer'),
    buildModuleBackendRoute('WeShop_B2B', 'b2b-customer'),
    buildModuleBackendRoute('WeShop_B2B', 'account'),
    buildModuleBackendRoute('WeShop_B2B', 'credit'),
    buildModuleBackendRoute('WeShop_B2B', 'b2breport'),
    buildModuleBackendRoute('WeShop_B2B', 'b2b-report'),
    moduleRootRoute,
  ];

  test('renders at least one B2B backend page without fatal errors', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });

    /** @type {Array<{route: string, ok: boolean, reason: string}>} */
    const attempts = [];

    for (const route of candidateRoutes) {
      try {
        await gotoBackend(page, route, {
          timeout: 60000,
          settleMs: 1000,
          useProxy: false,
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

    expect(successful.length, `All candidate routes failed: ${JSON.stringify(attempts, null, 2)}`).toBeGreaterThan(0);
  });
});
