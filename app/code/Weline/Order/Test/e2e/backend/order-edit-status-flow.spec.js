/**
 * 后台订单顶部状态流转 + 退款一眼可见
 *
 * @weline-e2e-spec { module: Weline_Order, type: e2e, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Order';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ORDER_ID = process.env.WELINE_ORDER_STATUS_FLOW_E2E_ID || '88';

moduleDescribe(test, MODULE, 'Weline_Order 后台订单顶部状态流转', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ORDER-EDIT-STATUS-FLOW-001' },
    '已退款订单：顶栏状态轨与渠道退款号不依赖退款 Tab / 更多',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, `order/backend/order/edit?id=${ORDER_ID}`, {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const flow = page.getByTestId('order-status-flow');
      await expect(flow).toBeVisible({ timeout: 20000 });
      await expect(page.getByTestId('order-status-flow-steps')).toBeVisible();
      await expect(page.getByTestId('order-status-flow-current')).toContainText(/已退款|Refunded/i);
      await expect(page.getByTestId('order-status-flow-refunds')).toBeVisible();
      await expect(page.getByTestId('order-status-flow-refund-id')).toBeVisible();
      await expect(page.getByTestId('order-status-flow-refund-id')).not.toHaveText(/^\s*$/);

      await expect(page.getByTestId('order-edit-more-toggle')).toHaveAttribute('aria-expanded', 'false');
      await expect(page.getByTestId('order-edit-history')).toBeHidden();

      const flowBox = await flow.boundingBox();
      const summaryBox = await page.getByTestId('order-edit-summary').boundingBox();
      expect(flowBox && summaryBox).toBeTruthy();
      expect(flowBox.y).toBeLessThan(summaryBox.y);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-plan-suite' },
    '计划链路：顶栏状态流转 + 管理页富信息合同',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, `order/backend/order/edit?id=${ORDER_ID}`, {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.getByTestId('order-status-flow')).toBeVisible({ timeout: 20000 });
      await expect(page.getByTestId('order-edit-summary')).toBeVisible();
      await expect(page.getByTestId('order-edit-ops-tabs')).toBeVisible();
      await page.getByTestId('order-edit-tab-refund').click();
      await expect(page.getByTestId('order-edit-panel-refund')).toBeVisible({ timeout: 20000 });
      await expect(page.getByTestId('order-edit-refund-status-chip').first()).toBeVisible();
      await expect(page.getByTestId('order-edit-refund-status-chip').first()).toHaveAttribute('data-tone', 'success');
      await expect(page.getByTestId('order-edit-refund-status-chip').first()).toContainText(/成功|Success/i);
      await expect(page.getByTestId('order-edit-refund-status')).not.toContainText(/succeeded/i);
      await expect(page.getByTestId('order-edit-refund-customer-view-chip')).toHaveAttribute('data-tone', 'success');
      await expect(page.getByTestId('order-status-flow-refund-chip').first()).toHaveAttribute('data-tone', 'success');
      await expect(page.getByTestId('order-status-flow-refund-id')).toBeVisible();
    }
  );
});
