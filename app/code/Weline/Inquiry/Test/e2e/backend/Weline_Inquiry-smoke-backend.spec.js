/**
 * @weline-e2e-spec { module: Weline_Inquiry, type: smoke, layer: backend }
 */
const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase, waitForBackendShellReady } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Inquiry';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Inquiry 后台 smoke', () => {
  moduleCase(test, { module: MODULE, id: 'INQUIRY-BACKEND-001' }, '表单管理页渲染且无运行时错误', async ({ page }) => {
    await loginAsAdmin(page);
    await gotoBackend(page, buildModuleBackendRoute(MODULE, 'inquiry'), { timeout: 60000, settleMs: 600 });
    await waitForBackendShellReady(page);
    await expect(page.locator('body')).not.toContainText(FATAL);
    await expect(page.locator('[data-testid="inquiry-management"]')).toBeVisible();
  });
});
