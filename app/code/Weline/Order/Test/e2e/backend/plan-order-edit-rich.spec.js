/**
 * 后台订单管理页：紧凑 disclosure + 办理 Tab + 地址按需展开
 *
 * @weline-e2e-spec { module: Weline_Order, type: plan, layer: backend }
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
const ORDER_ID = process.env.WELINE_ORDER_EDIT_E2E_ID || '59';

moduleDescribe(test, MODULE, 'Weline_Order 后台订单管理富信息', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ORDER-EDIT-RICH-001' },
    '管理订单页紧凑原型：摘要/办理 Tab/客户与更多默认收起/地址按需展开',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, `order/backend/order/edit?id=${ORDER_ID}`, {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page).toHaveURL(new RegExp(`[?&]id=${ORDER_ID}\\b`));
      await expect(page.locator('body')).toContainText(/管理订单|订单摘要/);

      await expect(page.getByTestId('order-status-flow')).toBeVisible({ timeout: 20000 });
      await expect(page.getByTestId('order-status-flow-steps')).toBeVisible();
      const flowBox = await page.getByTestId('order-status-flow').boundingBox();
      const summaryBox = await page.getByTestId('order-edit-summary').boundingBox();
      expect(flowBox && summaryBox).toBeTruthy();
      expect(flowBox.y).toBeLessThan(summaryBox.y);

      await expect(page.getByTestId('order-edit-summary')).toBeVisible({ timeout: 20000 });
      await expect(page.getByTestId('order-edit-summary-grid')).toBeVisible();
      await expect(page.getByTestId('order-edit-totals')).toBeVisible();
      await expect(page.getByTestId('order-edit-line-count')).toBeVisible();
      await expect(page.getByTestId('order-edit-lines-qty')).toBeVisible();
      await expect(page.getByTestId('order-edit-lines-subtotal')).toBeVisible();
      await expect(page.getByTestId('order-edit-summary')).toContainText(/商品行累计|商品小计|运费|订单总额|运输方式|优惠项目/);
      await expect(page.getByTestId('order-edit-shipping-method-label')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-method-label')).not.toHaveText(/SEED_LANE_DOMESTIC/);
      await expect(page.getByTestId('order-edit-shipping-fee')).toBeVisible();
      await expect(page.getByTestId('order-edit-discount-items')).toBeVisible();
      await expect(page.getByTestId('order-edit-discount-items')).toContainText(/无优惠项目|优惠|折扣|信用/);
      await expect(page.locator('[data-testid="order-edit-summary"] table.w-table')).toHaveCount(0);
      await expect(page.getByTestId('order-edit-ops-tabs')).toBeVisible();
      await expect(page.getByTestId('order-edit-tab-shipment')).toBeVisible();
      await expect(page.getByTestId('order-edit-tab-refund')).toBeVisible();
      await expect(page.getByTestId('order-edit-tab-comms')).toBeVisible();
      await expect(page.getByTestId('order-edit-panel-shipment')).toBeVisible({ timeout: 20000 });

      await page.getByTestId('order-edit-tab-refund').click();
      await expect(page.getByTestId('order-edit-panel-refund')).toBeVisible({ timeout: 20000 });

      await page.getByTestId('order-edit-tab-customer').click();
      await expect(page.getByTestId('order-edit-ops-panel-customer')).toBeVisible();
      await expect(page.getByTestId('order-edit-writable')).toBeVisible();
      await expect(page.getByTestId('order-edit-writable-toggle')).toHaveCount(0);
      await expect(page.getByTestId('order-edit-customer-select')).toBeVisible();
      await expect(page.getByTestId('order-edit-addresses')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-card')).toBeVisible();
      await expect(page.getByTestId('order-edit-billing-card')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-toggle')).toHaveAttribute('aria-expanded', 'false');
      await expect(page.getByTestId('order-edit-billing-toggle')).toHaveAttribute('aria-expanded', 'false');
      await expect(page.getByTestId('order-edit-shipping-address')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-form')).toBeHidden();
      await expect(page.getByTestId('order-edit-billing-form')).toBeHidden();

      await expect(page.getByTestId('order-edit-payment-card')).toBeVisible();
      await expect(page.getByTestId('order-edit-payment-summary')).toBeVisible();
      await expect(page.getByTestId('order-edit-payment-summary')).not.toHaveText(/^\s*$/);
      await expect(page.getByTestId('order-edit-payment-summary')).toContainText(/未指定|支付|部分|待支付|已支付|fake|PayPal|卡/);
      await expect(page.getByTestId('order-edit-payment-toggle')).toHaveAttribute('aria-expanded', 'false');
      await page.getByTestId('order-edit-payment-toggle').click();
      await expect(page.getByTestId('order-edit-payment-method')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-method-card')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-method-summary')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-method-summary')).not.toHaveText(/^\s*SEED_LANE_DOMESTIC\s*$/);
      await page.getByTestId('order-edit-shipping-method-toggle').click();
      await expect(page.getByTestId('order-edit-shipping-method-name')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-method-fee')).toBeVisible();
      await expect(page.getByTestId('order-edit-shipping-method')).toBeVisible();

      await page.getByTestId('order-edit-shipping-toggle').click();
      await expect(page.getByTestId('order-edit-shipping-toggle')).toHaveAttribute('aria-expanded', 'true');
      await expect(page.getByTestId('order-edit-shipping-form')).toBeVisible();
      await expect(page.locator('input[name="shipping_name"]')).toBeVisible();
      await expect(page.locator('input[name="shipping_address1"]')).toBeVisible();
      expect(await page.locator('[data-w-address], [data-address-code="order-edit-shipping"]').count()).toBeGreaterThanOrEqual(1);

      await expect(page.getByTestId('order-edit-notes')).toBeVisible();
      await expect(page.getByTestId('order-edit-save')).toBeVisible();

      await expect(page.getByTestId('order-edit-more-toggle')).toHaveAttribute('aria-expanded', 'false');
      await expect(page.getByTestId('order-edit-history')).toBeHidden();
      await page.getByTestId('order-edit-more-toggle').click();
      await expect(page.getByTestId('order-edit-more-toggle')).toHaveAttribute('aria-expanded', 'true');
      await expect(page.getByTestId('order-edit-history')).toBeVisible();
      await expect(page.locator('body')).toContainText(/订单办理|客户调整|收货地址|选择客户/);

      await expect(page.getByTestId('order-edit-items')).toContainText(/DS-CJ|跨境|SKU|商品|价格快照|活动/);
      await expect(page.getByTestId('order-edit-item-price')).toBeVisible();
      await expect(page.getByTestId('order-edit-item-campaign')).toBeVisible();
      await expect(page.getByTestId('order-edit-item-image')).toBeVisible();
      await expect(page.locator('input[name^="items["]')).toHaveCount(0);
    }
  );
});
