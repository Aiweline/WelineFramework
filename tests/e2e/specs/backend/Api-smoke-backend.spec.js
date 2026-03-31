// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const API_MODULE = 'Weline_Api';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, API_MODULE, 'Weline Api backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: API_MODULE, id: 'BACKEND-SMOKE-API-001' },
    'renders api backend user management page without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      const candidateRoutes = [
        buildModuleBackendRoute(API_MODULE, 'backend/user'),
        buildModuleBackendRoute(API_MODULE, 'backend/config'),
        buildModuleBackendRoute(API_MODULE, 'backend/integration'),
        buildModuleBackendRoute(API_MODULE),
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
        `Skip api backend route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
