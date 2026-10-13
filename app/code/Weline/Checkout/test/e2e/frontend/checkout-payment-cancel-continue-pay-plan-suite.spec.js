/**
 * 支付取消后继续支付 · 全路径计划套件入口
 *
 * Playwright 禁止在同一次 `test` 运行中 `require` 其它 `*.spec.js`。
 * 本计划组覆盖拆到独立通路，由 commerce-release 清单并列收录：
 * - `checkout-success-actions.spec.js`（取消态 CTA smoke）
 * - `checkout-continue-pay-real-pathway.spec.js`（建单→续付→order_uuid）
 *
 * 单独跑本文件时仍执行 CTA smoke，避免空套件。
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: plan-suite, layer: frontend, feature: payment-cancel-continue-pay }
 * @weline-e2e-runtime wls
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const { gotoStorefront } = require('./release-paypal-account-lifecycle-helpers');

const MODULE = 'Weline_Checkout';

moduleDescribe(test, MODULE, 'payment cancel continue-pay plan suite', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-CANCEL-CONTINUE-PLAN-001' },
    'cancel success without order shows cart primary CTA not bare checkout',
    async ({ page }) => {
      // Prefer public HTTPS host — proxy/127.0.0.1 can miss published cancel CTA chrome.
      try {
        await gotoStorefront(page, '/checkout/success?outcome=cancel');
      } catch (_) {
        await gotoFrontend(page, '/checkout/success?outcome=cancel', {
          timeout: 45000,
          settleMs: 400,
        });
      }
      await expect(page.locator('[data-testid="checkout-success"]')).toBeVisible({ timeout: 20000 });
      const nav = page.locator('[data-testid="checkout-success"] .amz-order-confirm__actions, .amz-order-confirm__actions').first();
      await expect(nav).toBeAttached({ timeout: 15000 });
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
    { module: MODULE, id: 'CHECKOUT-CANCEL-CONTINUE-PLAN-002' },
    'cancel success template exposes cancel outcome shell',
    async ({ page }) => {
      try {
        await gotoStorefront(page, '/checkout/success?outcome=cancel&cancel_state=done');
      } catch (_) {
        await gotoFrontend(page, '/checkout/success?outcome=cancel&cancel_state=done', {
          timeout: 45000,
          settleMs: 400,
        });
      }
      await expect(page.locator('[data-testid="checkout-success"]')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-payment-outcome="cancel"]')).toBeVisible();
    }
  );
});
