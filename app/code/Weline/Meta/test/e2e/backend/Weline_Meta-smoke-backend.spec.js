/**
 * Weline_Meta 元数据管理 E2E 冒烟测试
 *
 * 测试范围：
 * - Meta标签管理：页面Meta配置
 *
 * 控制器来源：app/code/Weline/Meta/Controller/Backend/Meta.php
 * 模板来源：app/code/Weline/Meta/view/templates/Backend/Meta/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Meta, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Meta';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Meta 元数据管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'META-SMOKE-001' },
    'Meta管理页面能够正常加载，显示Meta配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'meta');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含Meta相关内容
      const content = await body.innerText();
      expect(content).toMatch(/Meta|标签|Tag|元数据/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
