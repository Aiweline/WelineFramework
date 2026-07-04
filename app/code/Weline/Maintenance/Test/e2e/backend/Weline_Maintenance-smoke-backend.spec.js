/**
 * Weline_Maintenance 维护管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 维护模式配置：站点维护模式开关
 *
 * 控制器来源：app/code/Weline/Maintenance/Controller/Backend/Maintenance.php
 * 模板来源：app/code/Weline/Maintenance/view/templates/Backend/Maintenance/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Maintenance, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Maintenance';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Maintenance 维护管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'MAINTENANCE-SMOKE-001' },
    '维护模式页面能够正常加载，显示维护配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'maintenance');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含维护相关内容
      const content = await body.innerText();
      expect(content).toMatch(/维护|Maintenance|站点|Site/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
