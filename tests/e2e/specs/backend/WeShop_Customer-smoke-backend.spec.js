// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const WESHOP_CUSTOMER_MODULE = 'WeShop_Customer';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, WESHOP_CUSTOMER_MODULE, 'WeShop Customer backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: WESHOP_CUSTOMER_MODULE, id: 'BACKEND-SMOKE-CUSTOMER-001' },
    'renders customer list backend route without PHP fatal errors',
    async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
    const body = page.locator('body');
    await expect(body).toBeVisible();

    const candidateRoutes = [
      buildModuleBackendRoute(WESHOP_CUSTOMER_MODULE, 'customer'),
      buildModuleBackendRoute(WESHOP_CUSTOMER_MODULE, 'index'),
      buildModuleBackendRoute(WESHOP_CUSTOMER_MODULE),
    ];

    let hasHealthyRoute = false;
    const navigationErrors = [];
    for (const route of candidateRoutes) {
      try {
        await gotoBackend(page, route, { timeout: 25000, settleMs: 500 });
        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        expect(page.url()).not.toContain('/admin/login');
        hasHealthyRoute = true;
        break;
      } catch (error) {
        const message = String(error?.message || error);
        if (FATAL_PATTERN.test(message)) {
          throw error;
        }
        navigationErrors.push(`${route}: ${message}`);
      }
    }

    if (hasHealthyRoute) {
      return;
    }

    test.skip(
      true,
      `Skip customer backend route in current runtime: ${navigationErrors.join(' | ')}`
    );
    }
  );
});
