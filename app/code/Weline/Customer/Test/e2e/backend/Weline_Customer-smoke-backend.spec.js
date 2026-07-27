/**
 * Weline_Customer：诚实 smoke + 客户列表搜索交互
 *
 * @weline-e2e-spec { module: Weline_Customer, type: flow, layer: backend }
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

const MODULE = 'Weline_Customer';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Customer 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CUSTOMER-SMOKE-001' },
    '客户列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'customer'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('.card-title, h4, .page-title').first()).toContainText(/客户|Customer/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CUSTOMER-FLOW-SEARCH-001' },
    '客户列表：搜索框 fill 后提交并保留 keyword',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'customer'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const form = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
      const keyword = form.locator('input[name="keyword"]');
      await expect(keyword).toBeVisible({ timeout: 15000 });
      await keyword.fill('admin');
      const req = await submitAndExpectParam(page, form, 'keyword=admin');
      expect(req).toBeTruthy();
    }
  );
});
