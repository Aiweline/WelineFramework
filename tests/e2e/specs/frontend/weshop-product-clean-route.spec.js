// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|ParseError|syntax error|Fatal error|模板文件不存在/i, {
    timeout: 15000,
  });
}

test.describe('WeShop product clean route', () => {
  test('product detail clean route renders without alias-template runtime failures', async ({ page }) => {
    await gotoFrontend(page, '/product/view?id=1', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/product\/view/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('button', { name: /Add to Cart/i })).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
  });
});
