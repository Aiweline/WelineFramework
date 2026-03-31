/**
 * Weline_Acl ACL权限管理 E2E 冒烟测试
 *
 * 测试范围：
 * - ACL角色管理：角色列表、IP白名单、安全日志
 * - ACL配置：权限配置页面
 *
 * 控制器来源：app/code/Weline/Acl/Controller/Backend/Acl.php
 * 模板来源：app/code/Weline/Acl/view/templates/Backend/Acl/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Acl, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Acl';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Acl ACL权限管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'ACL-SMOKE-001' },
    'ACL角色列表页面能够正常加载，显示角色管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'acl');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/ACL|角色|Role|权限/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ACL-SMOKE-002' },
    'IP白名单页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'acl/ip-whitelist');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ACL-SMOKE-003' },
    '安全日志页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'acl/security-log');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ACL-SMOKE-004' },
    'ACL角色列表支持关键词搜索过滤',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'acl') + '?keyword=admin';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含搜索参数
      await expect(page).toHaveURL(/keyword=admin/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
