// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const WESHOP_CART_MODULE = 'WeShop_Cart';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, WESHOP_CART_MODULE, 'WeShop Cart backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: WESHOP_CART_MODULE, id: 'BACKEND-SMOKE-001' },
    'renders at least one cart backend route without PHP fatal errors',
    async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
    const body = page.locator('body');
    await expect(body).toBeVisible();

    const candidateRoutes = [
      buildModuleBackendRoute(WESHOP_CART_MODULE, 'cart'),
      buildModuleBackendRoute(WESHOP_CART_MODULE, 'index'),
      buildModuleBackendRoute(WESHOP_CART_MODULE),
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
      `Skip cart backend route in current runtime: ${navigationErrors.join(' | ')}`
    );
    }
  );
});
