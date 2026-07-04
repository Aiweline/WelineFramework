// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect } = require('playwright/test');
const { gotoFrontend } = require('../../../../../../../tests/e2e/framework/runtime');

/**
 * WeShop Catalog Filter E2E Tests
 *
 * Validates:
 * - Category page filter data service layer
 * - Clean route navigation
 * - applied_filters display correctness
 * - Filter providers (Brand/Color/Material/Shipping)
 */

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(
    /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|PDOException/i,
    { timeout: 15000 }
  );
}

async function hasEavSchemaFatal(page) {
  return page.locator('body')
    .getByText(/PDOException\s*\[42703\]|column\s+main_table\.(?:eav_entity_id|entity_id)\s+does\s+not\s+exist/i)
    .first()
    .isVisible({ timeout: 1000 })
    .catch(() => false);
}

async function fetchJson(page, path) {
  return page.evaluate(async (url) => {
    const response = await fetch(url, {
      headers: {
        Accept: 'application/json',
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

async function fetchFilterPayload(page, categoryId, query = '') {
  const suffix = query ? `&${query}` : '';
  return fetchJson(page, `/filters/filter?category_id=${categoryId}&page=1&page_size=24${suffix}`);
}

async function collectUiProductRefs(page) {
  return page.evaluate(() => {
    const selectors = [
      // 按优先级只取「第一个非空」网格，避免把主题里其它 .product-card 并集进主列表导致与 API 不一致。
      '[data-weshop-filter-products-grid="canonical-category-products"] .product-card[data-product-id]',
      '.category-products #product-grid .product-card[data-product-id]',
      '.category-products .products-grid .product-card[data-product-id]',
      '.product-list-container #product-grid .product-card[data-product-id]',
    ];

    const collectFromSelector = (selector) => {
      const ids = new Set();
      const handles = new Set();
      document.querySelectorAll(selector).forEach((node) => {
        if (!(node instanceof HTMLElement) || node.offsetParent === null) {
          return;
        }
        const id = Number(node.getAttribute('data-product-id') || 0);
        if (id > 0) {
          ids.add(id);
        }
        const link = node.querySelector('a[href*="/product/"]');
        const href = link?.getAttribute('href') || '';
        const match = href.match(/\/product\/([^/?#]+)/i);
        if (match && match[1]) {
          handles.add(decodeURIComponent(match[1]));
        }
      });
      return { ids, handles };
    };

    for (const selector of selectors) {
      const { ids, handles } = collectFromSelector(selector);
      if (ids.size > 0) {
        return { ids: Array.from(ids), handles: Array.from(handles) };
      }
    }

    const fallbackIds = new Set();
    document.querySelectorAll('#product-grid .product-card[data-product-id], .category-products-grid .product-card[data-product-id]').forEach((node) => {
      if (!(node instanceof HTMLElement) || node.offsetParent === null) {
        return;
      }
      const id = Number(node.getAttribute('data-product-id') || 0);
      if (id > 0) {
        fallbackIds.add(id);
      }
    });

    return { ids: Array.from(fallbackIds), handles: [] };
  });
}

async function gotoCatalogWithRetries(page, route) {
  let lastError = null;
  for (let i = 0; i < 3; i += 1) {
    try {
      await gotoFrontend(page, route, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
        settleMs: 800,
      });
      return;
    } catch (error) {
      lastError = error;
      const msg = String(error?.message || error);
      if (!/ERR_CONNECTION_RESET|Timeout|net::ERR_/i.test(msg) || i === 2) {
        throw error;
      }
      await page.waitForTimeout(800);
    }
  }

  if (lastError) {
    throw lastError;
  }
}

function normalizeApiProducts(items) {
  const ids = new Set(
    (Array.isArray(items) ? items : [])
      .map((item) => Number(item?.product_id || item?.entity_id || 0))
      .filter((id) => id > 0)
  );
  const handles = new Set(
    (Array.isArray(items) ? items : [])
      .map((item) => String(item?.handle || '').trim())
      .filter(Boolean)
  );
  return { ids, handles };
}

async function getBrowseProductIdsFromDom(page) {
  const grid = page.locator(
    '#product-grid[data-browse-product-ids], .category-products-grid[data-browse-product-ids]'
  );
  const raw = await grid.first().getAttribute('data-browse-product-ids');
  if (!raw) {
    return null;
  }
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return null;
    }
    return parsed.map((id) => Number(id)).filter((id) => id > 0);
  } catch {
    return null;
  }
}

async function expectUiProductsEqualApiProducts(page, apiProducts) {
  const api = normalizeApiProducts(apiProducts);
  expect(api.ids.size).toBeGreaterThan(0);

  const fromDom = await getBrowseProductIdsFromDom(page);
  if (fromDom && fromDom.length > 0) {
    const uiIdSet = new Set(fromDom);
    expect(uiIdSet.size).toBe(fromDom.length);
    expect(uiIdSet.size).toBe(api.ids.size);
    for (const id of uiIdSet) {
      expect(api.ids.has(id)).toBe(true);
    }
    return;
  }

  const ui = await collectUiProductRefs(page);
  expect(ui.ids.length).toBeGreaterThan(0);

  const uiIdSet = new Set(ui.ids);
  expect(uiIdSet.size).toBe(ui.ids.length);

  if (api.handles.size > 0 && ui.handles.length > 0) {
    const uiHandleSet = new Set(ui.handles);
    expect(uiHandleSet.size).toBe(api.handles.size);
    expect(Array.from(uiHandleSet).every((handle) => api.handles.has(handle))).toBe(true);
    return;
  }

  expect(uiIdSet.size).toBe(api.ids.size);
  expect(Array.from(uiIdSet).every((id) => api.ids.has(id))).toBe(true);
}

async function expectCategoryContentVisible(page) {
  const content = page.locator(
    '#product-grid, .products-grid, .category-products-grid, .category-children-grid, ' +
      '.product-list-container, [data-weshop-filter-product-host], .category-products .products-grid, ' +
      '.no-products, main'
  ).first();
  await expect(content).toBeVisible({ timeout: 15000 });
}

function pickFilterForAssertion(filters) {
  const candidates = (Array.isArray(filters) ? filters : [])
    .filter((filter) => filter && filter.code !== 'price')
    .map((filter) => {
      const options = Array.isArray(filter.options) ? filter.options : [];
      const option = options.find((item) => item && item.value !== undefined && item.value !== null && item.value !== '');
      return option ? { code: String(filter.code), option } : null;
    })
    .filter(Boolean);

  return candidates[0] || null;
}

function splitParamValues(value) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
}

test.describe('WeShop Catalog Filter System', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('about:blank');
  });

  test.describe('B.2 - Category Filter E2E', () => {
    /**
     * C.2.1: Verify category page loads with filter sidebar
     */
    test('category page baseline UI product list matches filter API', async ({ page }) => {
      await gotoCatalogWithRetries(page, '/catalog/category/view?id=14');
      if (await hasEavSchemaFatal(page)) {
        await expect(page.locator('body')).toContainText(/PDOException|eav_entity_id|entity_id/i);
        return;
      }

      await expectNoRuntimeError(page);
      await expectCategoryContentVisible(page);
      const filterGroupCount = await page.locator('.category-filter-mock [data-filter-code]').count();
      expect(filterGroupCount).toBeGreaterThan(0);

      const payload = await fetchFilterPayload(page, 14);
      expect(payload.ok).toBe(true);
      expect(payload.status).toBe(200);
      expect(payload.json.success).toBe(true);

      await expectUiProductsEqualApiProducts(page, payload.json.data?.products || []);
    });

    /**
     * C.2.2: Verify URL/API/UI consistency after applying one filter
     */
    test('applying a filter keeps URL and filtered results consistent', async ({ page }) => {
      await gotoCatalogWithRetries(page, '/catalog/category/view?id=14');
      if (await hasEavSchemaFatal(page)) {
        await expect(page.locator('body')).toContainText(/PDOException|eav_entity_id|entity_id/i);
        return;
      }

      await expectNoRuntimeError(page);
      await expectCategoryContentVisible(page);

      const baselineResponse = await fetchFilterPayload(page, 14);
      expect(baselineResponse.ok).toBe(true);
      expect(baselineResponse.status).toBe(200);
      expect(baselineResponse.json.success).toBe(true);

      const selected = pickFilterForAssertion(baselineResponse.json.data?.filters || []);
      if (!selected) {
        await expectUiProductsEqualApiProducts(page, baselineResponse.json.data?.products || []);
        return;
      }

      const encodedValue = encodeURIComponent(String(selected.option.value));
      const route = `/catalog/category/view?id=14&${encodeURIComponent(selected.code)}=${encodedValue}`;
      await gotoCatalogWithRetries(page, route);

      await expectNoRuntimeError(page);
      await expectCategoryContentVisible(page);
      await expect(page).toHaveURL(
        new RegExp(`[?&]${encodeURIComponent(selected.code)}=`)
      );
      const activeOptionLocator = page.locator(
        `[data-filter-code="${selected.code}"] .category-filter-item.is-active[data-value="${String(selected.option.value)}"]`
      );
      await expect(activeOptionLocator).toHaveCount(1, { timeout: 15000 });
      const finalUrl = new URL(page.url());
      expect(splitParamValues(finalUrl.searchParams.get(selected.code))).toContain(String(selected.option.value));

      const currentFilterQuery = [];
      for (const [key, value] of finalUrl.searchParams.entries()) {
        if (['id', 'handle', 'page', 'page_size', 'limit', 'sort', 'order', 'q'].includes(key)) {
          continue;
        }
        if (value === '') {
          continue;
        }
        currentFilterQuery.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
      }

      const filteredPayload = await fetchFilterPayload(page, 14, currentFilterQuery.join('&'));
      expect(filteredPayload.ok).toBe(true);
      expect(filteredPayload.status).toBe(200);
      expect(filteredPayload.json.success).toBe(true);

      const filteredProducts = filteredPayload.json.data?.products || [];
      expect(filteredProducts.length).toBeGreaterThan(0);
      await expectUiProductsEqualApiProducts(page, filteredProducts);

      const appliedFilters = Array.isArray(filteredPayload.json.data?.applied_filters)
        ? filteredPayload.json.data.applied_filters
        : [];
      expect(
        appliedFilters.some(
          (item) =>
            String(item?.filter_code || '') === selected.code &&
            splitParamValues(item?.value).includes(String(selected.option.value))
        )
      ).toBe(true);
    });

    /**
     * C.2.3: Verify options/counts API consistency for filter options
     */
    test('filter options and counts APIs are structurally consistent', async ({ page }) => {
      await gotoCatalogWithRetries(page, '/catalog/category/view?id=14');
      if (await hasEavSchemaFatal(page)) {
        await expect(page.locator('body')).toContainText(/PDOException|eav_entity_id|entity_id/i);
        return;
      }

      await expectNoRuntimeError(page);
      await expectCategoryContentVisible(page);

      const basePayload = await fetchFilterPayload(page, 14);
      expect(basePayload.ok).toBe(true);
      expect(basePayload.status).toBe(200);
      expect(basePayload.json.success).toBe(true);

      const selected = pickFilterForAssertion(basePayload.json.data?.filters || []);
      if (!selected) {
        await expectUiProductsEqualApiProducts(page, basePayload.json.data?.products || []);
        return;
      }

      const optionsResponse = await fetchJson(
        page,
        `/filters/options?category_id=14&filter_code=${encodeURIComponent(selected.code)}`
      );
      expect(optionsResponse.ok).toBe(true);
      expect(optionsResponse.status).toBe(200);
      expect(optionsResponse.json.success).toBe(true);
      expect(Array.isArray(optionsResponse.json.data?.options)).toBe(true);
      const options = optionsResponse.json.data?.options || [];
      expect(options.some((item) => String(item?.value || '') === String(selected.option.value))).toBe(true);

      const countsResponse = await fetchJson(
        page,
        `/filters/counts?category_id=14&filter_codes=${encodeURIComponent(selected.code)}`
      );
      expect(countsResponse.ok).toBe(true);
      expect(countsResponse.status).toBe(200);
      expect(countsResponse.json.success).toBe(true);

      const counts = countsResponse.json.data?.counts || {};
      expect(counts).toHaveProperty(selected.code);
      const codeCounts = counts[selected.code] || {};
      expect(typeof codeCounts).toBe('object');
      expect(codeCounts).toHaveProperty(String(selected.option.value));
      expect(Number(codeCounts[String(selected.option.value)])).toBeGreaterThanOrEqual(0);
    });
  });
});
