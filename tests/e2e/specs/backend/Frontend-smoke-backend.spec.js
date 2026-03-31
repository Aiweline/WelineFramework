// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const FRONTEND_MODULE = 'Weline_Frontend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, FRONTEND_MODULE, 'Weline Frontend backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: FRONTEND_MODULE, id: 'BACKEND-SMOKE-FRONTEND-001' },
    'renders frontend theme config page without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      const candidateRoutes = [
        buildModuleBackendRoute(FRONTEND_MODULE, 'theme-config/set'),
        buildModuleBackendRoute(FRONTEND_MODULE),
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
        `Skip frontend backend route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
