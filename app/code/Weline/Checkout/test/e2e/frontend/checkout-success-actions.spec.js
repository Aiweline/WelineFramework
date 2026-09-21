/**
 * 结账完成页 CTA：取消态主 CTA 为继续支付或返回购物车，禁止裸 /checkout。
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: smoke, layer: frontend, feature: payment-cancel-continue-pay }
 * @weline-e2e-runtime wls
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';

moduleDescribe(test, MODULE, 'checkout success actions', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SUCCESS-CTA-001' },
    'cancel success without order shows cart primary CTA not bare checkout',
    async ({ page }) => {
      await gotoFrontend(page, '/checkout/success?outcome=cancel', {
        timeout: 45000,
        settleMs: 400,
      });
      const nav = page.locator('[data-testid="checkout-success"] .amz-order-confirm__actions, .amz-order-confirm__actions').first();
      await expect(nav).toBeVisible({ timeout: 15000 });
      const texts = await nav.locator('a').allTextContents();
      const normalized = texts.map((t) => t.trim()).filter(Boolean);
      expect(normalized[0]).toMatch(/返回购物车|Back to cart|继续支付|Continue to pay|Continue payment/i);
      expect(normalized).toEqual(
        expect.arrayContaining([
          expect.stringMatching(/继续购物|Continue shopping/i),
          expect.stringMatching(/查看订单列表|返回订单列表|order list|orders/i),
        ]),
      );
      await expect(nav.locator('.amz-order-confirm__btn--primary')).toHaveCount(1);
      await expect(nav.locator('.amz-order-confirm__btn--link')).toHaveCount(1);
      const primaryHref = await nav.locator('.amz-order-confirm__btn--primary').getAttribute('href');
      expect(primaryHref || '').not.toMatch(/\/checkout\/?$/);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SUCCESS-CTA-002' },
    'cancel success template exposes cancel outcome shell',
    async ({ page }) => {
      await gotoFrontend(page, '/checkout/success?outcome=cancel&cancel_state=done', {
        timeout: 45000,
        settleMs: 400,
      });
      await expect(page.locator('[data-testid="checkout-success"]')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-payment-outcome="cancel"]')).toBeVisible();
    }
  );
});
