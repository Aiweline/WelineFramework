/**
 * 账户未付订单「继续支付」壳层冒烟。
 *
 * @weline-e2e-spec { module: Weline_Order, type: smoke, layer: frontend, feature: payment-cancel-continue-pay }
 * @weline-e2e-runtime wls
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Order';
const FATAL = /WLS Runtime Error|ParseError|Fatal error:|Call to undefined method|Class ['"][^'"]+['"] not found/i;

moduleDescribe(test, MODULE, 'account continue pay shell', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'ORDER-ACCOUNT-CONTINUE-PAY-001' },
    'account index orders hash opens without fatal',
    async ({ page }) => {
      await gotoFrontend(page, '/customer/account/index#orders', {
        timeout: 45000,
        settleMs: 500,
      });
      await expect(page.locator('body')).not.toContainText(FATAL);
      const hasOrders = await page.locator('[data-account-orders="true"]').count();
      const hasLogin = await page.locator('form[action*="login"], input[name="email"], input[type="email"]').count();
      expect(hasOrders + hasLogin).toBeGreaterThan(0);
    }
  );
});
