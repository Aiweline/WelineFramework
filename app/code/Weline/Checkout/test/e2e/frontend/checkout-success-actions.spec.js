/**
 * 结账完成页 CTA 层次：主继续购物 / 次订单详情 / 链订单列表（取消态：返回结账主）。
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: smoke, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const { test, expect, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'checkout success actions', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SUCCESS-CTA-001' },
    'cancel success shows ordered CTAs with primary return-to-checkout',
    async ({ page }) => {
      await page.goto(`${BASE}/checkout/success?outcome=cancel`, {
        waitUntil: 'domcontentloaded',
        timeout: 45000,
      });
      const nav = page.locator('[data-testid="checkout-success"] .amz-order-confirm__actions, .amz-order-confirm__actions').first();
      await expect(nav).toBeVisible({ timeout: 15000 });
      const texts = await nav.locator('a').allTextContents();
      const normalized = texts.map((t) => t.trim()).filter(Boolean);
      expect(normalized[0]).toMatch(/返回结账|Return to checkout/i);
      expect(normalized).toEqual(
        expect.arrayContaining([
          expect.stringMatching(/继续购物|Continue shopping/i),
          expect.stringMatching(/查看订单列表|返回订单列表|order list|orders/i),
        ]),
      );
      await expect(nav.locator('.amz-order-confirm__btn--primary')).toHaveCount(1);
      await expect(nav.locator('.amz-order-confirm__btn--link')).toHaveCount(1);
    }
  );
});
