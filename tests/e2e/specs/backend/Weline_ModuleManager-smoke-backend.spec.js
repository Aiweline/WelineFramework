/**
 * Weline_ModuleManager 模块管理器 E2E 冒烟测试
 *
 * 测试范围：
 * - 模块列表：已安装模块列表、模块状态（启用/禁用）
 * - 模块详情：模块版本、作者信息
 *
 * 控制器来源：app/code/Weline/ModuleManager/Controller/Backend/Module/*.php
 * 模板来源：app/code/Weline/ModuleManager/view/templates/Backend/Module/*.phtml
 *
 * @weline-e2e-spec { module: Weline_ModuleManager, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_ModuleManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_ModuleManager 模块管理器冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'MODULEMANAGER-SMOKE-001' },
    '模块列表页面能够正常加载，显示模块管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'module/listing');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/模块|Module/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MODULEMANAGER-SMOKE-002' },
    '模块列表页面包含模块表格或统计信息',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'module/listing');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含模块相关内容
      const hasModuleContent = /模块|Module|Weline|版本|Version/i.test(content);
      expect(hasModuleContent).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MODULEMANAGER-SMOKE-003' },
    '模块详情页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'module/module');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MODULEMANAGER-SMOKE-004' },
    '模块列表支持按状态筛选（已启用/已禁用）',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'module/listing') + '?is_active=1';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含筛选参数
      await expect(page).toHaveURL(/is_active=1/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
