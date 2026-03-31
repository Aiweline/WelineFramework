/**
 * Weline_Bt 宝塔面板集成 E2E 冒烟测试
 *
 * 测试范围：
 * - 宝塔面板集成配置
 *
 * @weline-e2e-spec { module: Weline_Bt, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Bt';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Bt 宝塔面板集成模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'BT-SMOKE-001' },
    '宝塔面板页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'bt');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
