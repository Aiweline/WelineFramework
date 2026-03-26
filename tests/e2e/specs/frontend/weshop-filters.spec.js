// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

async function fetchJson(page, path) {
  return page.evaluate(async (url) => {
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
  }, new URL(path, page.url()).toString());
}

test.describe('WeShop filters storefront', () => {
  test('category pages render indexed results and brand facets', async ({ page }) => {
    await gotoFrontend(page, '/catalog/category/view?id=14', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toContainText(/MacBook|笔记本/i, { timeout: 15000 });
    await expectNoRuntimeError(page);
    await expect(page.locator('[data-filter-code="brand"]')).toContainText(/Apple/i, { timeout: 15000 });
  });

  test('filters clean route exposes dynamic EAV facets after browse indexing', async ({ page }) => {
    await gotoFrontend(page, '/catalog/category/view?id=14', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toContainText(/MacBook|笔记本/i, { timeout: 15000 });
    await expectNoRuntimeError(page);

    const payload = await fetchJson(page, '/filters/filter?category_id=14');

    expect(payload.ok).toBeTruthy();
    expect(payload.status).toBe(200);
    expect(payload.json.success).toBeTruthy();
    expect(Array.isArray(payload.json.data?.products)).toBeTruthy();
    expect(payload.json.data.products.length).toBeGreaterThan(0);
    expect(payload.json.data.products[0].name).toMatch(/MacBook/i);

    const filters = payload.json.data?.filters || [];
    const filterCodes = filters.map((filter) => filter.code);
    expect(filterCodes).toEqual(expect.arrayContaining(['brand', 'color', 'material']));

    const brandFilter = filters.find((filter) => filter.code === 'brand');
    expect(brandFilter).toBeTruthy();
    expect(Array.isArray(brandFilter.options)).toBeTruthy();
    expect(brandFilter.options.length).toBeGreaterThan(0);
    expect(brandFilter.options.some((option) => /Apple/i.test(option.label))).toBeTruthy();
  });
});
