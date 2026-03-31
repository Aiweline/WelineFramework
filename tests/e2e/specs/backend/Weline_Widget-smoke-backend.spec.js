/**
 * Weline_Widget 小组件管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 小组件管理：页面小组件配置
 *
 * 控制器来源：app/code/Weline/Widget/Controller/Backend/Widget.php
 * 模板来源：app/code/Weline/Widget/view/templates/Backend/Widget/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Widget, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Widget';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Widget 小组件管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'WIDGET-SMOKE-001' },
    '小组件管理页面能够正常加载，显示小组件配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'widget');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含小组件相关内容
      const content = await body.innerText();
      expect(content).toMatch(/小组件|Widget|组件|Component/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
