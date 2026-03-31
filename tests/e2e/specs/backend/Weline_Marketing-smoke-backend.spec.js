/**
 * Weline_Marketing 营销管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 营销管理：营销工具和配置
 *
 * 控制器来源：app/code/Weline/Marketing/Controller/Backend/Marketing.php
 * 模板来源：app/code/Weline/Marketing/view/templates/Backend/Marketing/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Marketing, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Marketing';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Marketing 营销管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-SMOKE-001' },
    '营销管理页面能够正常加载，显示营销内容',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'marketing');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含营销相关内容
      const content = await body.innerText();
      expect(content).toMatch(/营销|Marketing|推广|Promotion/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
