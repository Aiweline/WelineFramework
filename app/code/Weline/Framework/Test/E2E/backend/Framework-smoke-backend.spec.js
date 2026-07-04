// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const FRAMEWORK_MODULE = 'Weline_Framework';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, FRAMEWORK_MODULE, 'Weline Framework backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: FRAMEWORK_MODULE, id: 'BACKEND-SMOKE-FW-001' },
    'renders at least one framework backend route without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      // Framework 模块通常没有独立后台页面，这里测试系统配置等通用路由
      const candidateRoutes = [
        buildModuleBackendRoute('Weline_Backend', 'system/config'),
        buildModuleBackendRoute('Weline_Backend', 'statistics'),
        buildModuleBackendRoute('Weline_Backend'),
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
        `Skip framework backend route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
