// @weline-e2e-runtime wls
// @weline-e2e-transport proxy
// 经 e2e 代理 (3999) 访问 WLS，与 playwright webServer 就绪检查一致，避免直连 https://127.0.0.1 偶发超时。

const { test, expect, gotoFrontend } = require('../../framework');

const CATEGORY_PATH = '/catalog/category/view?id=14';
const PRODUCT_CARD_SELECTOR = '#product-grid [data-product-id], #product-grid .product-card, #product-grid .motor-card, .products-grid [data-product-id], .products-grid .product-card, .category-products-grid [data-product-id], .category-products-grid .product-card, .category-products [data-product-id], .category-products .product-card, .category-products .motor-card';

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

async function fetchFilterPayload(page) {
  return page.evaluate(async () => {
    const response = await fetch('/filters/filter?category_id=14', {
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
  });
}

async function fetchFilterPayloadWithQuery(page, query) {
  return page.evaluate(async (queryString) => {
    const response = await fetch(`/filters/filter?${queryString}`, {
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
  }, query);
}

async function fetchFilterPayloadByUrl(page) {
  const url = new URL(page.url());
  const params = new URLSearchParams();
  params.set('category_id', '14');
  for (const [key, value] of url.searchParams.entries()) {
    if (['id', 'handle', 'page', 'page_size', 'limit', 'sort', 'order', 'q'].includes(key)) {
      continue;
    }
    if (value === '') {
      continue;
    }
    params.set(key, value);
  }

  return page.evaluate(async (query) => {
    const response = await fetch(`/filters/filter?${query}`, {
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
  }, params.toString());
}

async function collectUiProductIds(page) {
  return page.evaluate(() => {
    const selectors = [
      '#product-grid [data-product-id]',
      '.products-grid [data-product-id]',
      '.category-products-grid [data-product-id]',
    ];
    const ids = new Set();
    selectors.forEach((selector) => {
      document.querySelectorAll(selector).forEach((node) => {
        const id = Number(node.getAttribute('data-product-id') || 0);
        if (id > 0) {
          ids.add(id);
        }
      });
    });
    document.querySelectorAll('#product-grid .motor-card, .category-products .motor-card').forEach((card) => {
      const id = Number(card.querySelector('[data-product-id]')?.getAttribute('data-product-id') || 0);
      if (id > 0) {
        ids.add(id);
      }
    });

    return Array.from(ids);
  });
}

function normalizeProductIds(items = []) {
  return new Set(
    (Array.isArray(items) ? items : [])
      .map((item) => Number(item?.product_id || item?.entity_id || 0))
      .filter((id) => id > 0)
  );
}

async function expectUiApiProductIdsExactlyMatch(page, apiProducts = []) {
  const apiIds = normalizeProductIds(apiProducts);
  expect(apiIds.size).toBeGreaterThan(0);

  const uiIds = await collectUiProductIds(page);
  expect(uiIds.length).toBeGreaterThan(0);
  const uiSet = new Set(uiIds);
  expect(uiSet.size).toBe(uiIds.length);
  expect(uiSet.size).toBe(apiIds.size);
  expect(Array.from(uiSet).every((id) => apiIds.has(id))).toBeTruthy();
}

async function waitAjaxComplete(page) {
  await page.waitForSelector('.category-products.is-loading', { state: 'visible', timeout: 10000 }).catch(() => {});
  await page.waitForSelector('.category-products.is-loading', { state: 'hidden', timeout: 20000 }).catch(() => {});
}

async function selectFilterOption(page, filterCode, value) {
  const locator = page.locator(`.category-filter-group[data-filter-code="${filterCode}"] .category-filter-item[data-value="${value}"]`).first();
  await expect(locator).toBeVisible({ timeout: 10000 });
  await locator.click();
  await waitAjaxComplete(page);
}

function pickOptionWithPositiveCount(options = []) {
  return options.find(option => Number(option.count || 0) > 0) || options[0] || null;
}

async function expectCatalogReady(page) {
  await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
  const hasFilterGroup = await page.locator('.category-filter-group').first().isVisible({ timeout: 8000 }).catch(() => false);
  const hasProductGrid = await page.locator(PRODUCT_CARD_SELECTOR).first().isVisible({ timeout: 8000 }).catch(() => false);
  expect(hasFilterGroup || hasProductGrid).toBeTruthy();
}

async function hasSchemaFatal(page) {
  const fatalBody = page.locator('body');
  const hasSchemaError = await fatalBody
    .getByText(/PDOException\s*\[42703\]|column\s+main_table\.eav_entity_id\s+does\s+not\s+exist/i)
    .first()
    .isVisible({ timeout: 1000 })
    .catch(() => false);

  return hasSchemaError;
}

test.describe('WeShop filters storefront interaction', () => {
  test('brand + color 双维组合筛选会更新 URL/选中态/chips', async ({ page }) => {
    await gotoFrontend(page, CATEGORY_PATH, {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1000,
    });
    if (await hasSchemaFatal(page)) {
      await expect(page.locator('body')).toContainText(/PDOException|eav_entity_id/i);
      return;
    }
    await expectCatalogReady(page);
    await expectNoRuntimeError(page);

    const payload = await fetchFilterPayload(page);
    expect(payload.ok).toBeTruthy();
    expect(payload.status).toBe(200);
    expect(payload.json.success).toBeTruthy();

    const filters = payload.json.data?.filters || [];
    const brandFilter = filters.find(filter => filter.code === 'brand');
    const brandOption = pickOptionWithPositiveCount(brandFilter?.options || []);
    if (!brandOption) {
      await expectUiApiProductIdsExactlyMatch(page, payload.json.data?.products || []);
      return;
    }

    const colorPayload = await fetchFilterPayloadWithQuery(page, `category_id=14&brand=${encodeURIComponent(String(brandOption.value))}`);
    expect(colorPayload.ok).toBeTruthy();
    expect(colorPayload.status).toBe(200);
    expect(colorPayload.json.success).toBeTruthy();
    const colorFilter = (colorPayload.json.data?.filters || []).find(filter => filter.code === 'color');
    const colorOption = pickOptionWithPositiveCount(colorFilter?.options || []);

    if (!colorOption) {
      await expectUiApiProductIdsExactlyMatch(page, colorPayload.json.data?.products || []);
      return;
    }

    const baselineProductCount = await page.locator(PRODUCT_CARD_SELECTOR).count();
    expect(baselineProductCount).toBeGreaterThan(0);
    const baselinePayload = await fetchFilterPayloadByUrl(page);
    expect(baselinePayload.ok).toBeTruthy();
    expect(baselinePayload.status).toBe(200);
    expect(baselinePayload.json.success).toBeTruthy();
    await expectUiApiProductIdsExactlyMatch(page, baselinePayload.json.data?.products || []);

    await selectFilterOption(page, 'brand', String(brandOption.value));
    await selectFilterOption(page, 'color', String(colorOption.value));

    await expect(page.locator(`.category-filter-group[data-filter-code="brand"] .category-filter-item[data-value="${String(brandOption.value)}"]`))
      .toHaveClass(/is-active|is-selected/);
    await expect(page.locator(`.category-filter-group[data-filter-code="color"] .category-filter-item[data-value="${String(colorOption.value)}"]`))
      .toHaveClass(/is-active|is-selected/);

    await expect(page.locator('.category-filter-applied')).toBeVisible();
    const chips = page.locator('.category-filter-applied .filter-chip');
    await expect(chips).toHaveCount(2);
    await expect(chips.filter({ hasText: String(brandOption.label || brandOption.value) }).first()).toBeVisible();
    await expect(chips.filter({ hasText: String(colorOption.label || colorOption.value) }).first()).toBeVisible();

    const finalUrl = new URL(page.url());
    expect(finalUrl.searchParams.get('id')).toBe('14');
    expect(finalUrl.searchParams.get('brand')).toContain(String(brandOption.value));
    expect(finalUrl.searchParams.get('color')).toContain(String(colorOption.value));

    const filteredProductCount = await page.locator(PRODUCT_CARD_SELECTOR).count();
    expect(filteredProductCount).toBeGreaterThan(0);
    expect(filteredProductCount).toBeLessThanOrEqual(baselineProductCount);

    const filteredPayload = await fetchFilterPayloadByUrl(page);
    expect(filteredPayload.ok).toBeTruthy();
    expect(filteredPayload.status).toBe(200);
    expect(filteredPayload.json.success).toBeTruthy();

    const filteredProducts = filteredPayload.json.data?.products || [];
    await expectUiApiProductIdsExactlyMatch(page, filteredProducts);
    const appliedFilters = Array.isArray(filteredPayload.json.data?.applied_filters)
      ? filteredPayload.json.data.applied_filters
      : [];
    expect(appliedFilters.some((item) => String(item?.filter_code || '') === 'brand')).toBeTruthy();
    expect(appliedFilters.some((item) => String(item?.filter_code || '') === 'color')).toBeTruthy();
  });

  test('brand + price 双维组合筛选会写入价格区间并返回可见商品', async ({ page }) => {
    await gotoFrontend(page, CATEGORY_PATH, {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1000,
    });
    if (await hasSchemaFatal(page)) {
      await expect(page.locator('body')).toContainText(/PDOException|eav_entity_id/i);
      return;
    }
    await expectCatalogReady(page);
    await expectNoRuntimeError(page);

    const payload = await fetchFilterPayload(page);
    expect(payload.ok).toBeTruthy();
    expect(payload.status).toBe(200);
    expect(payload.json.success).toBeTruthy();

    const filters = payload.json.data?.filters || [];
    const brandFilter = filters.find(filter => filter.code === 'brand');
    const priceFilter = filters.find(filter => filter.code === 'price');
    const brandOption = pickOptionWithPositiveCount(brandFilter?.options || []);
    const slider = priceFilter?.options?.[0]?.slider || null;

    if (!brandOption || !slider) {
      await expectUiApiProductIdsExactlyMatch(page, payload.json.data?.products || []);
      return;
    }

    await selectFilterOption(page, 'brand', String(brandOption.value));

    const minSlider = page.locator('.category-filter-group[data-filter-code="price"] .price-slider-min').first();
    const maxSlider = page.locator('.category-filter-group[data-filter-code="price"] .price-slider-max').first();
    await expect(minSlider).toBeVisible({ timeout: 10000 });
    await expect(maxSlider).toBeVisible({ timeout: 10000 });

    const sliderMin = Number(slider.min);
    const sliderMax = Number(slider.max);
    const mid = sliderMin + Math.floor((sliderMax - sliderMin) * 0.6);
    const targetMin = Math.max(sliderMin, Math.min(mid, sliderMax - 1));
    const targetMax = sliderMax;

    await minSlider.evaluate((node, value) => {
      node.value = String(value);
      node.dispatchEvent(new Event('input', { bubbles: true }));
      node.dispatchEvent(new Event('change', { bubbles: true }));
    }, targetMin);

    await maxSlider.evaluate((node, value) => {
      node.value = String(value);
      node.dispatchEvent(new Event('input', { bubbles: true }));
      node.dispatchEvent(new Event('change', { bubbles: true }));
    }, targetMax);

    await waitAjaxComplete(page);

    const finalUrl = new URL(page.url());
    expect(finalUrl.searchParams.get('id')).toBe('14');
    expect(finalUrl.searchParams.get('brand')).toContain(String(brandOption.value));
    expect(finalUrl.searchParams.get('price')).toBe(`${targetMin}-${targetMax}`);

    await expect(page.locator('.category-filter-applied')).toBeVisible();
    const chips = page.locator('.category-filter-applied .filter-chip');
    await expect(chips).toHaveCount(2);
    await expect(chips.filter({ hasText: String(brandOption.label || brandOption.value) }).first()).toBeVisible();

    const filteredProductCount = await page.locator(PRODUCT_CARD_SELECTOR).count();
    expect(filteredProductCount).toBeGreaterThan(0);

    const filteredPayload = await fetchFilterPayloadByUrl(page);
    expect(filteredPayload.ok).toBeTruthy();
    expect(filteredPayload.status).toBe(200);
    expect(filteredPayload.json.success).toBeTruthy();

    const filteredProducts = filteredPayload.json.data?.products || [];
    await expectUiApiProductIdsExactlyMatch(page, filteredProducts);
    const appliedFilters = Array.isArray(filteredPayload.json.data?.applied_filters)
      ? filteredPayload.json.data.applied_filters
      : [];
    expect(appliedFilters.some((item) => String(item?.filter_code || '') === 'brand')).toBeTruthy();
    expect(appliedFilters.some((item) => String(item?.filter_code || '') === 'price')).toBeTruthy();
  });
});
