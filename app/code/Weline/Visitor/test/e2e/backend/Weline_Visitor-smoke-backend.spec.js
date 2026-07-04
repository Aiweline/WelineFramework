/**
 * Weline_Visitor 访客追踪 E2E 冒烟测试
 *
 * 测试范围：
 * - 访客统计：UV/PV数据展示
 *
 * 控制器来源：app/code/Weline/Visitor/Controller/Backend/Visitor.php
 * 模板来源：app/code/Weline/Visitor/view/templates/Backend/Visitor/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Visitor 访客追踪模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-SMOKE-001' },
    '访客统计页面能够正常加载，显示访客数据',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'visitor');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含访客相关内容
      const content = await body.innerText();
      expect(content).toMatch(/访客|Visitor|统计|Stats|UV|PV/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
