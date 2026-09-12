/**
 * HelpPay quick-pay short link must render Theme default shell + dual share CTA.
 *
 * @weline-e2e-spec { module: Weline_HelpPay, type: smoke, layer: frontend }
 */

const { test, expect, gotoFrontend, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_HelpPay';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(MODULE, 'quick-pay storefront shell', () => {
  moduleCase('quick pay page uses theme chrome and share dual delivery', async ({ page, baseURL }) => {
    // Token from local PaymentLink store used in Browser acceptance; skip if 404/invalid.
    const token = '1cc04c171a0cfb54a9b7e696b58de9fa';
    const url = `/q/${token}`;
    await gotoFrontend(page, url, { baseURL });
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(FATAL_PATTERN);
    expect(body).not.toMatch(/系统升级维护中/);

    const shell = page.locator('.w-frontend-shell');
    await expect(shell).toHaveCount(1);

    const root = page.locator('[data-testid="quick-pay-self"]');
    await expect(root).toBeVisible();
    await expect(page.locator('[data-testid="help-pay-share-result"]')).toBeVisible();
    await expect(page.locator('[data-testid="help-pay-copy-url"]')).toBeVisible();
    await expect(page.locator('[data-testid="help-pay-copy-qr"]')).toBeVisible();
    await expect(page.locator('[data-testid="quick-pay-submit"], [data-helppay-pay]')).toBeVisible();

    await expect(page.locator('link[data-helppay-share-css], link[href*="helppay-share.css"]')).toHaveCount(1, {
      timeout: 10000,
    });
  });
});
