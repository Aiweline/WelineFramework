/**
 * Weline_BackendThemeUpzet 后台主题 E2E 冒烟测试
 *
 * 测试范围：
 * - 主题配置：Upzet主题设置
 *
 * @weline-e2e-spec { module: Weline_BackendThemeUpzet, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_BackendThemeUpzet';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_BackendThemeUpzet 后台主题模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'BACKENDTHEMEUPZET-SMOKE-001' },
    'Upzet主题页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'backend-theme-upzet');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
