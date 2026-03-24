// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

test.describe('WeShop search storefront', () => {
  test('search page and suggest endpoint are available', async ({ page }) => {
    await gotoFrontend(page, '/search', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 500,
    });

    await expect(page.locator('body')).toContainText(/Search|搜索/i, { timeout: 15000 });
    await expect(page.locator('input[name="q"]')).toBeVisible({ timeout: 15000 });

    const suggestPayload = await page.evaluate(async (url) => {
      const response = await fetch(url, {
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
    }, new URL('/search/suggest?q=bag&limit=3', page.url()).toString());

    expect(suggestPayload.ok).toBeTruthy();
    expect(suggestPayload.status).toBe(200);
    expect(suggestPayload.json.success).toBeTruthy();
    expect(Array.isArray(suggestPayload.json.suggestions)).toBeTruthy();
    expect(Array.isArray(suggestPayload.json.data)).toBeTruthy();
  });
});
