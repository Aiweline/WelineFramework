/**
 * Weline_Cron：诚实 smoke + 状态筛选交互（不强制真跑任务）
 *
 * @weline-e2e-spec { module: Weline_Cron, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Cron';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Cron 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CRON-SMOKE-001' },
    '计划任务列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'cron/listing'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/Cron|定时|计划任务/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CRON-FLOW-FILTER-001' },
    '计划任务：切换状态筛选并确认表格区域',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'cron/listing'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const status = page.locator('#weline-cron-status-filter');
      await expect(status).toBeVisible({ timeout: 15000 });
      await Promise.all([
        page.waitForURL(/status=pending/, { timeout: 20000 }),
        status.selectOption('pending'),
      ]);
      await waitForBackendShellReady(page);
      await expect(page.locator('.weline-cron-table, table.table').first()).toBeVisible();
      await expect(page.locator('body')).toContainText(/任务|共|条|模块/i);
    }
  );
});
