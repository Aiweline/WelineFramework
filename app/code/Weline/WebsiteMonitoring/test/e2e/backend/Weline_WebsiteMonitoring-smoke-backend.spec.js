/**
 * Weline_WebsiteMonitoring 网站监控 E2E 冒烟测试
 *
 * 测试范围：
 * - 网站监控：站点状态监控、告警配置
 *
 * 控制器来源：app/code/Weline/WebsiteMonitoring/Controller/Backend/WebsiteMonitoring.php
 * 模板来源：app/code/Weline/WebsiteMonitoring/view/templates/Backend/WebsiteMonitoring/*.phtml
 *
 * @weline-e2e-spec { module: Weline_WebsiteMonitoring, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_WebsiteMonitoring';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_WebsiteMonitoring 网站监控模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'WEBSITMONITORING-SMOKE-001' },
    '网站监控页面能够正常加载，显示监控数据',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'website-monitoring');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含监控相关内容
      const content = await body.innerText();
      expect(content).toMatch(/监控|Monitoring|网站|Website|状态|Status/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
