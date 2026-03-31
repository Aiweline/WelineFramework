/**
 * Weline_Cdn CDN管理 E2E 冒烟测试
 *
 * 测试范围：
 * - CDN配置页面：CDN设置、表单提交
 *
 * 控制器来源：app/code/Weline/Cdn/Controller/Backend/Cdn.php
 * 模板来源：app/code/Weline/Cdn/view/templates/Backend/Cdn/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Cdn, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Cdn';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Cdn CDN管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'CDN-SMOKE-001' },
    'CDN配置页面能够正常加载，显示CDN配置标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'cdn');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/CDN|内容分发/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CDN-SMOKE-002' },
    'CDN配置页面包含配置表单元素',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'cdn');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证包含表单元素（输入框或选择框）
      const inputs = page.locator('input, select');
      expect(await inputs.count()).toBeGreaterThan(0);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
