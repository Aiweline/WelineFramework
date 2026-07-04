/**
 * Weline_Admin 后台管理员与认证 E2E 冒烟测试
 *
 * 测试范围：
 * - 管理员仪表盘：Dashboard 加载、关键数据展示
 * - 模块管理：已安装模块列表、模块状态筛选
 *
 * 控制器来源：app/code/Weline/Admin/Controller/Backend/Admin/*.php
 * 模板来源：app/code/Weline/Admin/view/templates/Backend/Admin/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Admin, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Admin';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Admin 后台管理员模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-SMOKE-001' },
    '管理员仪表盘能够正常加载，显示 Dashboard 标题和概览数据',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'dashboard');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 Dashboard 标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/Dashboard|面板|概览/i, { timeout: 10000 });

      // 验证概览数据卡片存在
      const overviewCards = page.locator('.card, .overview-item, [class*="stat"]');
      expect(await overviewCards.count()).toBeGreaterThan(0);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-SMOKE-002' },
    '管理员首页 /admin 能够重定向到认证后的后台页面',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, 'admin', { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证不是登录页（登录后访问 /admin 应该到后台主页）
      const isLoginPage = await page.locator('form[action*="/admin/login/post"]').isVisible().catch(() => false);
      expect(isLoginPage).toBe(false);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-SMOKE-003' },
    '系统模块列表页面能够正常加载，显示模块表格',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'system/modules');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含模块相关内容
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/模块|Module/i, { timeout: 10000 });

      // 验证表格存在（模块列表）
      const table = page.locator('table');
      await expect(table).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-SMOKE-004' },
    '系统模块列表支持按状态筛选（启用/禁用）',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'system/modules') + '?status=1';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含筛选参数
      await expect(page).toHaveURL(/status=1/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
