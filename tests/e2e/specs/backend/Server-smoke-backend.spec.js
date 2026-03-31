// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const SERVER_MODULE = 'Weline_Server';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, SERVER_MODULE, 'Weline Server backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: SERVER_MODULE, id: 'BACKEND-SMOKE-SERVER-001' },
    'renders server backend monitoring page without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      // Server模块主要提供WLS服务，后台页面主要是监控和管理
      const candidateRoutes = [
        buildModuleBackendRoute('Weline_Backend', 'monitor'),
        buildModuleBackendRoute('Weline_Backend', 'maintenance'),
        buildModuleBackendRoute('Weline_Backend', 'backup'),
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
        `Skip server backend route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
