/**
 * HelpPay payer page billing address must reuse checkout address widget.
 *
 * @weline-e2e-spec { module: Weline_HelpPay, type: smoke, layer: frontend }
 */

const { test, expect, gotoFrontend, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_HelpPay';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(MODULE, 'payer billing address', () => {
  moduleCase('payer page mounts checkout address widget for billing', async ({ page, baseURL }) => {
    const token = '0543d717daebf666defc11f2a7386997';
    const url = `/h/${token}`;
    await gotoFrontend(page, url, { baseURL });
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(FATAL_PATTERN);
    expect(body).not.toMatch(/系统升级维护中/);

    const root = page.locator('[data-testid="help-pay-payer"]');
    await expect(root).toBeVisible();

    const invalid = page.locator('[data-testid="help-pay-invalid"], [data-testid="helppay-link-invalid"]');
    if ((await invalid.count()) > 0 && (await invalid.first().isVisible().catch(() => false))) {
      test.skip(true, 'HelpPay token invalid on this environment');
      return;
    }

    await expect(page.locator('[data-testid="help-pay-billing"]')).toBeVisible();
    await expect(page.locator('[data-testid="helppay-billing-mount"]')).toBeVisible();
    await expect(page.locator('[data-helppay-billing-host], [data-shipping-checkout-address]')).toBeVisible({
      timeout: 15000,
    });
    await expect(page.locator('[data-helppay-billing-line1], input[name="billing_line1"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="help-pay-billing"] [data-billing-section]')).toBeHidden();

    const addNew = page.locator('[data-testid="help-pay-billing"] [data-add-address]');
    const editor = page.locator(
      '[data-testid="help-pay-billing"] [data-address-editor], [data-testid="help-pay-billing"] [data-shipping-checkout-address][data-mode="new"], [data-testid="help-pay-billing"] [data-shipping-checkout-address][data-mode="edit"]'
    );
    await expect(addNew.or(editor).first()).toBeVisible({ timeout: 15000 });

    await expect(page.locator('[data-testid="help-pay-confirm"], [data-helppay-pay]')).toBeVisible();
  });
});
