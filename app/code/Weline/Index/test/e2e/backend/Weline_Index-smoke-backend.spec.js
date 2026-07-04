/**
 * Weline_Index 后台首页 E2E 冒烟测试
 *
 * 测试范围：
 * - 后台首页 /admin/index：加载、认证状态、内容展示
 *
 * @weline-e2e-spec { module: Weline_Index, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Index';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Index 后台首页冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'INDEX-SMOKE-001' },
    '后台首页能够正常加载，显示管理后台内容',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, 'admin/index', { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证不是登录页
      const isLoginPage = await page.locator('form[action*="/admin/login/post"]').isVisible().catch(() => false);
      expect(isLoginPage).toBe(false);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'INDEX-SMOKE-002' },
    '后台首页包含导航菜单或快捷入口',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, 'admin/index', { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面有菜单或快捷入口
      const menuOrShortcut = page.locator('.nav, .sidebar, .quick-entry, [class*="menu"]').first();
      await expect(menuOrShortcut).toBeVisible({ timeout: 5000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
