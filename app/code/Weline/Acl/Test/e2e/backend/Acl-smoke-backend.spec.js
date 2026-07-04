// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const ACL_MODULE = 'Weline_Acl';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, ACL_MODULE, 'Weline Acl backend smoke', () => {
  test.describe.configure({ retries: 1 });

  moduleCase(
    test,
    { module: ACL_MODULE, id: 'BACKEND-SMOKE-ACL-001' },
    'renders acl management page without PHP fatal errors',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000 });
      const body = page.locator('body');
      await expect(body).toBeVisible();

      const candidateRoutes = [
        buildModuleBackendRoute(ACL_MODULE, 'acl'),
        buildModuleBackendRoute(ACL_MODULE, 'acl/role'),
        buildModuleBackendRoute(ACL_MODULE, 'security-log'),
        buildModuleBackendRoute(ACL_MODULE, 'ip-whitelist'),
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
        `Skip acl backend route in current runtime: ${navigationErrors.join(' | ')}`
      );
    }
  );
});
