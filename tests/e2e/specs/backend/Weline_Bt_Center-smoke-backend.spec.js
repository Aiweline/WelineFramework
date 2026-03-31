/**
 * Weline_Bt_Center 宝塔中心 E2E 冒烟测试
 *
 * 测试范围：
 * - 宝塔中心配置
 *
 * @weline-e2e-spec { module: Weline_Bt_Center, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Bt_Center';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Bt_Center 宝塔中心模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'BTCENTER-SMOKE-001' },
    '宝塔中心页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'bt-center');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
