// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

test.describe('WeShop search storefront', () => {
  test('search page renders indexed results and suggest endpoint returns structured data', async ({ page }) => {
    await gotoFrontend(page, '/search?q=Apple', {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toContainText(/Search|搜索/i, { timeout: 15000 });
    await expect(page.locator('input[name="q"]')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('body')).toContainText(/Apple/i, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(/Apple Watch/i, { timeout: 20000 });

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
    }, new URL('/search/suggest?q=Apple&limit=5', page.url()).toString());

    expect(suggestPayload.ok).toBeTruthy();
    expect(suggestPayload.status).toBe(200);
    expect(suggestPayload.json.success).toBeTruthy();
    expect(Array.isArray(suggestPayload.json.suggestions)).toBeTruthy();
    expect(Array.isArray(suggestPayload.json.data)).toBeTruthy();
    expect(suggestPayload.json.suggestions.length).toBeGreaterThan(0);
    expect(typeof suggestPayload.json.suggestions[0].text).toBe('string');
    expect(suggestPayload.json.suggestions.some((item) => /Apple/i.test(item.text))).toBeTruthy();
  });

  test('search page exposes dynamic brand facet for searchable EAV queries', async ({ page }) => {
    await gotoFrontend(page, '/search?q=%E5%93%81%E7%89%8C%20Apple', {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toContainText(/Results for|搜索结果|品牌 Apple/i, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(/Apple Watch|MacBook/i, { timeout: 20000 });
    await expect(page.locator('a[href*="brand="]').first()).toBeVisible({ timeout: 15000 });
  });
});
