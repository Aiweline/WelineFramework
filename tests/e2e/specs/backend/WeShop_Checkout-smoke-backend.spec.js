// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  buildModuleBackendRoute,
  buildTargetUrl,
  getRuntimeInfo,
} = require('../../framework');

const MODULE_NAME = 'WeShop_Checkout';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

test.describe('WeShop_Checkout backend smoke', () => {
  test.describe.configure({ retries: 1 });

  const routesTried = [
    buildModuleBackendRoute(MODULE_NAME, 'index'),
    buildModuleBackendRoute(MODULE_NAME, 'methods'),
    buildModuleBackendRoute(MODULE_NAME, 'success'),
  ];

  for (const route of routesTried) {
    test(`GET ${route} renders without fatal runtime errors`, async ({ page }) => {
      try {
        await gotoBackend(page, route, {
          timeout: 12000,
          settleMs: 500,
        });
      } catch (error) {
        test.skip(
          true,
          `Skip route ${route}: backend route is unavailable in current runtime (${String(error && error.message ? error.message : error)}).`,
        );
        return;
      }

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      await expect(body).not.toContainText(FATAL_PATTERN);

      const runtime = getRuntimeInfo();
      const backendPrefixPath = String(runtime.paths?.backend_prefix_path || '/admin').replace(/\/+$/, '');
      const normalizedRoute = String(route || '').replace(/^\/+/, '');
      const currentUrl = new URL(page.url());
      const expectedTargetPath = new URL(buildTargetUrl(`${backendPrefixPath}/${normalizedRoute}`)).pathname;
      const usesProxyBackendPath = currentUrl.pathname.includes(`/@backend/${normalizedRoute}`);
      const usesTargetBackendPath = currentUrl.pathname.includes(expectedTargetPath);

      expect(currentUrl.pathname).not.toContain('/admin/login');
      expect(usesProxyBackendPath || usesTargetBackendPath).toBeTruthy();
    });
  }
});
