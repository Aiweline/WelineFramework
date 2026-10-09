/**
 * 快捷支付结账通路（录制固化）：
 * - 匿名加购 → startExpressCheckout(fake_card) → 产出 order_uuid
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: pathway, layer: frontend, feature: commerce-express }
 * @weline-e2e-runtime wls
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const {
  prepareCustomer,
  addToCartMode,
  runExpressCheckoutCaptureEvidence,
  assertStep,
} = require('./commerce-express-checkout-pathway-helpers');

const MODULE = 'Weline_Checkout';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, '快捷支付结账通路', () => {
  test.setTimeout(360000);

  moduleCase(
    test,
    { module: MODULE, id: 'CR-EXPRESS-CHECKOUT-ORDER' },
    '快捷支付：加购→startExpressCheckout→order_uuid',
    async ({ page }) => {
      const prepared = (prepareCustomer().fixture) || {};
      const pdp = String((prepared && prepared.pdp_path) || process.env.CHECKOUT_PDP_PATH || '').trim()
        || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
      const guestEmail = String((prepared && prepared.guest_email) || `e2e.express.${Date.now()}@example.test`);

      await addToCartMode(page, gotoFrontend, pdp, 'toc');
      await expect(page.locator('body')).not.toContainText(FATAL);

      const evidence = await runExpressCheckoutCaptureEvidence(page, {
        scope: 'express_guest',
        email: guestEmail,
      });
      assertStep(!!evidence.order_uuid, 'express.order_uuid', evidence);
      expect(evidence.order_uuid).toMatch(/[0-9a-f-]{16,}/i);
    }
  );
});
