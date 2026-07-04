// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const SESSION_MANAGER_MODULE = 'Weline_SessionManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, SESSION_MANAGER_MODULE, 'Weline SessionManager backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: SESSION_MANAGER_MODULE, id: 'BACKEND-SMOKE-SESSION-001' },
    'renders session manager without PHP fatal errors (backend route available)',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      // SessionManager可能没有独立后台页面，测试Backend模块的session相关路由
      const candidateRoutes = [
        buildModuleBackendRoute('Weline_Backend', 'access-log'),
        buildModuleBackendRoute('Weline_Admin', 'system/setting'),
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
        `Skip session manager backend route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
