/**
 * Weline_ModuleRouter 模块路由 E2E 冒烟测试
 *
 * 测试范围：
 * - 模块路由配置管理
 *
 * @weline-e2e-spec { module: Weline_ModuleRouter, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_ModuleRouter';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_ModuleRouter 模块路由模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'MODULEROUTER-SMOKE-001' },
    '模块路由页面能够正常加载，显示路由配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'module-router');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含路由相关内容
      const content = await body.innerText();
      expect(content).toMatch(/路由|Route|模块|Module/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
