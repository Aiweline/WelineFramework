/**
 * Weline_Installer 安装器 E2E 冒烟测试
 *
 * 测试范围：
 * - 安装器后台页面（有限功能）
 *
 * @weline-e2e-spec { module: Weline_Installer, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Installer';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Installer 安装器模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'INSTALLER-SMOKE-001' },
    '安装器页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'installer');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
