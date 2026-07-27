/**
 * Weline_Order：诚实 smoke + 列表筛选表单真实提交
 *
 * @weline-e2e-spec { module: Weline_Order, type: flow, layer: backend }
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

const MODULE = 'Weline_Order';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

async function openOrderList(page) {
  const candidates = [
    buildModuleBackendRoute(MODULE, 'order'),
    'order/backend/order',
    'weline_order/backend/order',
  ];
  let lastError = null;
  for (const route of candidates) {
    try {
      await gotoBackend(page, route, { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      const keyword = page.locator('form input[name="keyword"]').first();
      if ((await keyword.count()) > 0) {
        return;
      }
    } catch (error) {
      lastError = error;
    }
  }
  if (lastError) {
    throw lastError;
  }
}

moduleDescribe(test, MODULE, 'Weline_Order 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ORDER-SMOKE-001' },
    '订单列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openOrderList(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/订单|Order/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ORDER-FLOW-FILTER-001' },
    '订单列表：填写关键词与状态后点搜索',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openOrderList(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const form = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
      const keyword = form.locator('input[name="keyword"]');
      const status = form.locator('select[name="status"]');
      await expect(keyword).toBeVisible({ timeout: 15000 });
      await keyword.fill('TEST001');
      await status.selectOption('pending');
      // 决定性证据：真实提交把用户输入 keyword+status 带进了请求（去掉 fill/select 即不成立）
      const req = await submitAndExpectParam(page, form, 'keyword=TEST001');
      expect(decodeURIComponent(req.url())).toContain('status=pending');
    }
  );
});
