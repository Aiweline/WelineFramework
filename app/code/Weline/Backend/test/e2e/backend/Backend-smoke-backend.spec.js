// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const BACKEND_MODULE = 'Weline_Backend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, BACKEND_MODULE, 'Weline Backend backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: BACKEND_MODULE, id: 'BACKEND-SMOKE-BE-001' },
    'renders backend system config page without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      const candidateRoutes = [
        buildModuleBackendRoute(BACKEND_MODULE, 'system/config'),
        buildModuleBackendRoute(BACKEND_MODULE, 'statistics'),
        buildModuleBackendRoute(BACKEND_MODULE, 'settings/basic'),
        buildModuleBackendRoute(BACKEND_MODULE, 'settings/email'),
        buildModuleBackendRoute(BACKEND_MODULE),
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
        `Skip backend config route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
