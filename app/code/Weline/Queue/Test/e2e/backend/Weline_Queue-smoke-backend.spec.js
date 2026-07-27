/**
 * Weline_Queue：诚实 smoke + biz_key 筛选交互
 *
 * @weline-e2e-spec { module: Weline_Queue, type: flow, layer: backend }
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
  submitAndExpectParam,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Queue';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Queue 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'QUEUE-SMOKE-001' },
    '队列列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'queue'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/队列|Queue/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'QUEUE-FLOW-FILTER-001' },
    '队列列表：业务键筛选提交',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'queue'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const form = page.locator('form').filter({ has: page.locator('input[name="biz_key"]') }).first();
      const bizKey = form.locator('input[name="biz_key"]');
      await expect(bizKey).toBeVisible({ timeout: 15000 });
      await bizKey.fill('e2e-filter-key');
      const req = await submitAndExpectParam(page, form, 'biz_key=e2e-filter-key');
      expect(req).toBeTruthy();
    }
  );
});
