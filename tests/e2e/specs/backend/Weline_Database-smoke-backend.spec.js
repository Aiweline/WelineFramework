/**
 * Weline_Database 数据库管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 数据库管理首页：数据表列表、数据库状态
 * - SQL执行器：SQL查询页面
 *
 * 控制器来源：app/code/Weline/Database/Controller/Backend/Admin.php
 * 模板来源：app/code/Weline/Database/view/backend/templates/admin/index.phtml
 *
 * @weline-e2e-spec { module: Weline_Database, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Database';
const ADMIN_INDEX_ROUTE = buildModuleBackendRoute(MODULE, 'admin', 'index');
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Database 数据库管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'DATABASE-SMOKE-001' },
    '数据库管理首页能够正常加载，显示数据库管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const response = await gotoBackend(page, ADMIN_INDEX_ROUTE, { timeout: 30000 });
      expect(response?.status(), `Unexpected status for ${ADMIN_INDEX_ROUTE}`).toBeLessThan(400);

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('.w-database-admin h2').first();
      await expect(heading).toContainText(/数据库管理|Database|DB/i, { timeout: 10000 });
      await expect(page.locator('#db-select')).toBeVisible();
      await expect(page.locator('#table-select')).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'DATABASE-SMOKE-002' },
    '数据库管理页面包含数据表列表或统计信息',
    async ({ page }) => {
      await loginAsAdmin(page);
      const route = `${ADMIN_INDEX_ROUTE}?tab=sql`;
      const response = await gotoBackend(page, route, { timeout: 30000 });
      expect(response?.status(), `Unexpected status for ${route}`).toBeLessThan(400);

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含数据库相关内容
      const hasDbContent = /表|Table|数据库|Database|SQL/i.test(content);
      expect(hasDbContent).toBe(true);
      await expect(page.locator('#sql-input')).toBeVisible();
      await expect(page.locator('#sql-import-form')).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
