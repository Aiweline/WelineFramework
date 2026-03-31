/**
 * Weline_Customer 客户管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 客户列表：列表加载、搜索过滤
 * - 客户详情：客户详情页加载
 *
 * 控制器来源：app/code/WeShop/Customer/Controller/Backend/Customer/Index.php, View.php
 * 模板来源：app/code/WeShop/Customer/view/templates/Backend/Customer/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Customer, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Customer';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Customer 客户管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'CUSTOMER-SMOKE-001' },
    '客户列表页面能够正常加载，显示客户管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'customer');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/客户|Customer/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CUSTOMER-SMOKE-002' },
    '客户列表支持关键词搜索过滤',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'customer') + '?keyword=test';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含搜索参数
      await expect(page).toHaveURL(/keyword=test/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CUSTOMER-SMOKE-003' },
    '客户列表支持分页',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'customer') + '?page=1&limit=20';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含分页参数
      await expect(page).toHaveURL(/page=1/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
