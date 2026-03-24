// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop RMA clean routes', () => {
  test('guest rma routes stay safe and redirect into the login guard', async ({ page }) => {
    await gotoFrontend(page, '/rma', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    expect(/customer\/account\/login|\/rma(?:$|[/?#])/i.test(page.url())).toBeTruthy();

    await gotoFrontend(page, '/rma/create?order_id=1', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    expect(/customer\/account\/login|\/rma(?:$|[/?#])/i.test(page.url())).toBeTruthy();
  });
});
