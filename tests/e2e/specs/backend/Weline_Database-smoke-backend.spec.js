/**
 * Weline_Database 数据库管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 数据库管理首页：数据表列表、数据库状态
 * - SQL执行器：SQL查询页面
 *
 * 控制器来源：app/code/Weline/Database/Controller/Backend/Database.php
 * 模板来源：app/code/Weline/Database/view/templates/Backend/Database/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Database, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Database';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Database 数据库管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'DATABASE-SMOKE-001' },
    '数据库管理首页能够正常加载，显示数据库管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'database');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/数据库|Database|DB/i, { timeout: 10000 });

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
      const url = buildModuleBackendRoute(MODULE, 'database');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含数据库相关内容
      const hasDbContent = /表|Table|数据库|Database|SQL/i.test(content);
      expect(hasDbContent).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
