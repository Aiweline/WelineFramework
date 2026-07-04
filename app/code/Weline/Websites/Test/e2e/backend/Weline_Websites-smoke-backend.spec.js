/**
 * Weline_Websites 网站管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 网站管理：多站点配置、站点列表
 *
 * 控制器来源：app/code/Weline/Websites/Controller/Backend/Websites.php
 * 模板来源：app/code/Weline/Websites/view/templates/Backend/Websites/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Websites, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Websites 网站管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'WEBSITES-SMOKE-001' },
    '网站管理页面能够正常加载，显示站点列表',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'websites');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含网站相关内容
      const content = await body.innerText();
      expect(content).toMatch(/网站|Website|站点|Site/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
