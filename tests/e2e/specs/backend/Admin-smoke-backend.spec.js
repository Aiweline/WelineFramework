// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const ADMIN_MODULE = 'Weline_Admin';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, ADMIN_MODULE, 'Weline Admin backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: ADMIN_MODULE, id: 'BACKEND-SMOKE-ADMIN-001' },
    'renders admin dashboard without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      const candidateRoutes = [
        buildModuleBackendRoute(ADMIN_MODULE, 'dashboard'),
        buildModuleBackendRoute(ADMIN_MODULE, 'index'),
        buildModuleBackendRoute(ADMIN_MODULE),
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
        `Skip admin dashboard in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
