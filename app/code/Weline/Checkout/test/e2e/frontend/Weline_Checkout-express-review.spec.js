/**
 * Express review light-confirm surface (PDP/checkout defer-capture).
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: feature, layer: frontend }
 */

const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');

moduleDescribe(test, MODULE, 'Express review light confirm', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CHECKOUT-EXPRESS-REVIEW-001' },
    'express-review 页展示尚未扣款与确认并付款',
    async ({ page }) => {
      let lastError = null;
      for (let attempt = 1; attempt <= 3; attempt += 1) {
        try {
          await gotoFrontend(page, '/checkout/express-review?transaction_no=e2e-demo', {
            timeout: 90000,
            settleMs: 1000,
          });
          lastError = null;
          break;
        } catch (error) {
          lastError = error;
          await page.waitForTimeout(2000);
        }
      }
      if (lastError) {
        throw lastError;
      }
      const body = await page.content();
      expect(body).toContain('data-testid="checkout-express-review"');
      expect(body).toContain('确认并付款');
      expect(body).toContain('尚未扣款，确认后向支付商收款');
      expect(body).toContain('请确认收货地址；不对可更换或新增');
      expect(body).toContain('id="checkout-shipping-address"');
      expect(body).not.toContain('支付商带回，只读');
      expect(body).toContain('window.opener');
      expect(body).not.toMatch(/WLS Runtime Error|ParseError|Fatal error/i);
      await expect(page.getByTestId('checkout-express-review')).toBeVisible({ timeout: 45000 });
      await expect(page.getByTestId('express-confirm-pay')).toBeVisible();
      await expect(page.getByTestId('express-cancel')).toBeVisible();
      // Address widget slot from Shipping (same as full checkout).
      const addressSlot = page.locator('#checkout-shipping-address, [data-shipping-checkout-address]');
      await expect(addressSlot.first()).toBeVisible({ timeout: 45000 });
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CHECKOUT-EXPRESS-REVIEW-003' },
    'express-review 前后端通路：JS 含地址切换监听与 shipping_address 回传',
    async () => {
      const js = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/view/statics/js/express-review.js'),
        'utf8',
      );
      expect(js).toContain('weline:checkout:address-updated');
      expect(js).toContain('shipping_address');
      expect(js).toContain('collectShippingAddress');
      const flow = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/Service/ExpressCheckoutFlowService.php'),
        'utf8',
      );
      expect(flow).toContain('extractAddressFromParams');
      expect(flow).toContain("'address_readonly' => false");
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CHECKOUT-EXPRESS-REVIEW-002' },
    '前台模块注册 product-express-pay 与 checkoutExpressReview',
    async () => {
      const frontendModules = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Frontend/view/statics/base/weline.modules.js'),
        'utf8',
      );
      const checkoutModules = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/view/statics/frontend/weline.modules.js'),
        'utf8',
      );
      const paymentModules = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Payment/view/statics/frontend/weline.modules.js'),
        'utf8',
      );
      expect(frontendModules + checkoutModules).toContain('checkoutExpressReview');
      expect(frontendModules + checkoutModules).toContain('express-review.js');
      expect(paymentModules + frontendModules).toContain('product-express-pay');
      const productJs = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Payment/view/statics/js/product-express-pay.js'),
        'utf8',
      );
      expect(productJs).toContain('startExpressCheckout');
      expect(productJs).toContain('weline_express_pay');
      expect(productJs).not.toContain("searchParams.set('express_pay'");
    },
  );
});
