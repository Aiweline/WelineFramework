/**
 * Weline_AliDdnsServer 阿里云DNS服务 E2E 冒烟测试
 *
 * 测试范围：
 * - DNS记录列表：DNS记录管理
 * - DNS配置：阿里云DNS配置
 *
 * 控制器来源：app/code/Weline/AliDdnsServer/Controller/Backend/DdnsList.php
 * 模板来源：app/code/Weline/AliDdnsServer/view/templates/Backend/*.phtml
 *
 * @weline-e2e-spec { module: Weline_AliDdnsServer, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_AliDdnsServer';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_AliDdnsServer 阿里云DNS服务模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'ALIDDNS-SMOKE-001' },
    'DNS配置页面能够正常加载，显示DNS配置表单',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'ali-ddns-server/config');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/DNS|DDNS|阿里|Ali/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ALIDDNS-SMOKE-002' },
    'DNS记录列表页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'ali-ddns-server/ddns-list');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
