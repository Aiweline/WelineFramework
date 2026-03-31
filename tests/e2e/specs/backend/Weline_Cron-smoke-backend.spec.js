/**
 * Weline_Cron 定时任务 E2E 冒烟测试
 *
 * 测试范围：
 * - 定时任务列表：任务列表、任务调度状态
 * - 定时任务配置：Cron表达式配置
 *
 * 控制器来源：app/code/Weline/Cron/Controller/Backend/Cron.php
 * 模板来源：app/code/Weline/Cron/view/templates/Backend/Cron/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Cron, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Cron';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Cron 定时任务模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'CRON-SMOKE-001' },
    '定时任务列表页面能够正常加载，显示任务管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'cron');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/Cron|定时任务|计划任务/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CRON-SMOKE-002' },
    '定时任务列表包含任务表格或统计信息',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'cron');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含任务相关内容
      const hasTaskContent = /任务|Task|Job|调度|Schedule/i.test(content);
      expect(hasTaskContent).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
