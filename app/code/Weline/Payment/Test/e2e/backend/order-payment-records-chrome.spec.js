/**
 * 后台订单支付记录：支付方式 icon + 状态四色芯片
 *
 * @weline-e2e-spec { module: Weline_Payment, type: e2e, layer: backend }
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

const MODULE = 'Weline_Payment';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ORDER_ID = process.env.WELINE_ORDER_PAYMENT_RECORDS_E2E_ID || '88';

moduleDescribe(test, MODULE, 'Weline_Payment 后台支付记录可视化', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-ORDER-RECORDS-CHROME-001' },
    '支付与沟通：方式 icon + 状态四色芯片（禁止裸 paid）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, `order/backend/order/edit?id=${ORDER_ID}`, {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      await page.getByTestId('order-edit-tab-comms').click();
      const records = page.getByTestId('backend-order-payment-records');
      await expect(records).toBeVisible({ timeout: 20000 });

      const icon = records.getByTestId('payment-record-method-icon').first();
      await expect(icon).toBeVisible();
      await expect(icon).toHaveAttribute('src', /paypal|payment/i);
      await expect(records.getByTestId('payment-record-method-label').first()).toBeVisible();

      const chip = records.getByTestId('payment-record-status-chip').first();
      await expect(chip).toBeVisible();
      await expect(chip).toHaveAttribute('data-tone', /success|info|warning|danger/);
      await expect(chip).not.toHaveText(/^\s*(paid|succeeded|pending|failed|refunded)\s*$/i);
      await expect(records.getByTestId('payment-record-status')).not.toContainText(/\bpaid\b/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-plan-suite' },
    '计划链路：支付记录 chrome 合同',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, `order/backend/order/edit?id=${ORDER_ID}&tab=comms`, {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await page.getByTestId('order-edit-tab-comms').click();
      const records = page.getByTestId('backend-order-payment-records');
      await expect(records).toBeVisible({ timeout: 20000 });
      await expect(records.getByTestId('payment-record-method-icon').first()).toBeVisible();
      await expect(records.getByTestId('payment-record-status-chip').first()).toBeVisible();
    }
  );
});
