/**
 * Weline_SystemConfig 系统配置管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 系统配置首页：配置分组列表
 * - 配置编辑：保存配置项
 *
 * 控制器来源：app/code/Weline/SystemConfig/Controller/Backend/SystemConfig.php
 * 模板来源：app/code/Weline/SystemConfig/view/templates/Backend/SystemConfig/*.phtml
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_SystemConfig';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_SystemConfig 系统配置模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'SYSCONFIG-SMOKE-001' },
    '系统配置首页能够正常加载，显示配置分组列表',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'system-config');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/配置|Config|System/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SYSCONFIG-SMOKE-002' },
    '系统配置分组页面能够正常加载配置项表单',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'system-config', { group: 'general' });
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证包含表单元素
      const inputs = page.locator('input, select, textarea');
      expect(await inputs.count()).toBeGreaterThan(0);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
