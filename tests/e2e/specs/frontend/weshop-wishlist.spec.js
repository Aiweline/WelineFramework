// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop wishlist clean routes', () => {
  test('guest wishlist route and ajax actions stay stable', async ({ page }) => {
    await gotoFrontend(page, '/wishlist', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    await expect(page).toHaveURL(/customer\/account\/login|\/wishlist(?:$|[/?#])/i, { timeout: 15000 });

    const addPayload = await page.evaluate(async () => {
      const response = await fetch('/wishlist/add?product_id=1', {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      return {
        ok: response.ok,
        status: response.status,
        json: await response.json(),
      };
    });

    expect(addPayload.ok).toBeTruthy();
    expect(addPayload.status).toBe(200);
    expect(addPayload.json.success).toBeFalsy();
    expect(String(addPayload.json.data?.redirect_url || '')).toMatch(/customer\/account\/login/i);

    const removePayload = await page.evaluate(async () => {
      const response = await fetch('/wishlist/remove?wishlist_id=1', {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      return {
        ok: response.ok,
        status: response.status,
        json: await response.json(),
      };
    });

    expect(removePayload.ok).toBeTruthy();
    expect(removePayload.status).toBe(200);
    expect(removePayload.json.success).toBeFalsy();
    expect(String(removePayload.json.data?.redirect_url || '')).toMatch(/customer\/account\/login/i);
  });
});
