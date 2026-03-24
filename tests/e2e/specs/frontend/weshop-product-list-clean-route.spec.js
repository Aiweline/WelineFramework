// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|模板文件不存在/i, {
    timeout: 15000,
  });
}

test.describe('WeShop product list clean routes', () => {
  test('root product list route renders the listing layout', async ({ page }) => {
    await gotoFrontend(page, '/product/list', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/product\/list/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
  });

  test('shared weshop product list alias also resolves', async ({ page }) => {
    await gotoFrontend(page, '/weshop/product/list', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/(?:weshop\/product\/list|product\/list)/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
  });
});
