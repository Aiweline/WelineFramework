// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

test.describe('WeShop subscription storefront', () => {
  test('subscription route redirects guests to login', async ({ page }) => {
    await gotoFrontend(page, '/subscription', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/login/i, { timeout: 15000 });
  });
});
