// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop homepage storefront', () => {
  test('homepage loads without runtime failures', async ({ page }) => {
    await gotoFrontend(page, '/weshop', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toMatch(/Shop|Deals|Category|首页|新品|特惠/i);
  });
});
