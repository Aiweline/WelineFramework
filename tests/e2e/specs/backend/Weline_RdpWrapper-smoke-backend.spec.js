/**
 * Weline_RdpWrapper RDP包装器 E2E 冒烟测试
 *
 * 测试范围：
 * - RDP包装器配置：远程桌面连接配置
 *
 * @weline-e2e-spec { module: Weline_RdpWrapper, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_RdpWrapper';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_RdpWrapper RDP包装器模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'RDPWRAPPER-SMOKE-001' },
    'RDP包装器页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'rdp-wrapper');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
