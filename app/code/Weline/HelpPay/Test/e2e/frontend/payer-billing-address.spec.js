/**
 * HelpPay payer billing appears only when payment method requires it.
 *
 * @weline-e2e-spec { module: Weline_HelpPay, type: smoke, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const { test, expect, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_HelpPay';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const TOKEN = '0543d717daebf666defc11f2a7386997';

moduleDescribe(test, MODULE, 'payer billing address', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'HELPPAY-PAYER-BILLING-001' },
    'payer billing gated by payment method requires_billing',
    async ({ page }) => {
      await page.goto(`${BASE}/h/${TOKEN}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(800);

      const body = await page.locator('body').innerText();
      expect(body).not.toMatch(FATAL_PATTERN);
      expect(body).not.toMatch(/系统升级维护中/);

      const root = page.locator('[data-testid="help-pay-payer"]');
      await expect(root).toBeVisible({ timeout: 20000 });

      const invalid = page.locator('[data-testid="help-pay-invalid"], [data-testid="helppay-link-invalid"]');
      if ((await invalid.count()) > 0 && (await invalid.first().isVisible().catch(() => false))) {
        test.skip(true, 'HelpPay token invalid on this environment');
        return;
      }

      await expect(page.locator('[data-testid="help-pay-payment-methods"]')).toBeVisible();
      await expect(page.locator('[data-helppay-payment-method]').first()).toBeVisible();

      const noBilling = page.locator('[data-helppay-payment-method][data-requires-billing="0"]').first();
      const needsBilling = page.locator('[data-helppay-payment-method][data-requires-billing="1"]').first();
      const billingPanel = page.locator('[data-testid="help-pay-billing"], [data-helppay-billing-panel]');

      if ((await noBilling.count()) > 0) {
        await noBilling.check({ force: true });
        await expect(billingPanel).toBeHidden();
      }

      if ((await needsBilling.count()) > 0) {
        await needsBilling.check({ force: true });
        await expect(billingPanel).toBeVisible();
        await expect(page.locator('[data-helppay-billing-host], [data-shipping-checkout-address]').first()).toBeVisible({
          timeout: 15000,
        });
        await expect(page.locator('[data-helppay-billing-line1], input[name="billing_line1"]')).toHaveCount(0);
      }

      await expect(page.locator('[data-testid="help-pay-confirm"], [data-helppay-pay]')).toBeVisible();
    }
  );
});
