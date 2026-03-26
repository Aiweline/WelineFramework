// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop storefront shell routes', () => {
  test('product detail route stays stable after shared shell-data normalization', async ({ page }) => {
    await gotoFrontend(page, '/product/view?id=1', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expect(page).toHaveURL(/product\/view/i, { timeout: 15000 });
    await expectNoRuntimeError(page);
  });
});
