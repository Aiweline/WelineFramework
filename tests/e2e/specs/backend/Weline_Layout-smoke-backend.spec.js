/**
 * Weline_Layout 布局管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 布局管理：页面布局配置
 *
 * 控制器来源：app/code/Weline/Layout/Controller/Backend/Layout.php
 * 模板来源：app/code/Weline/Layout/view/templates/Backend/Layout/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Layout, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Layout';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Layout 布局管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'LAYOUT-SMOKE-001' },
    '布局管理页面能够正常加载，显示布局配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'layout');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含布局相关内容
      const content = await body.innerText();
      expect(content).toMatch(/布局|Layout|页面|Page/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
