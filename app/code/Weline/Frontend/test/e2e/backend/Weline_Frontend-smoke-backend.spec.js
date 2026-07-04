/**
 * Weline_Frontend 前端管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 维护模式：站点维护模式配置
 * - 主题配置：前端主题设置
 *
 * 控制器来源：app/code/Weline/Frontend/Controller/Backend/Maintenance.php, ThemeConfig.php
 * 模板来源：app/code/Weline/Frontend/view/templates/Backend/Maintenance/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Frontend, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Frontend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Frontend 前端管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'FRONTEND-SMOKE-001' },
    '维护模式页面能够正常加载，显示维护模式配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'frontend/maintenance');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含维护相关内容
      const content = await body.innerText();
      expect(content).toMatch(/维护|Maintenance/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FRONTEND-SMOKE-002' },
    '主题配置页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'frontend/theme-config/set');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
