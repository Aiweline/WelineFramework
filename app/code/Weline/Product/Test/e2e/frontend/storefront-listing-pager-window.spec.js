/**
 * Storefront listing pager: sliding window + ellipsis (not every page link).
 *
 * @weline-e2e-spec { module: Weline_Product, type: feature, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'frontend storefront listing pager window', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-PRODUCT-LISTING-PAGER-WINDOW-001' },
    'catalog pager uses ellipsis window instead of every page',
    async ({ page }) => {
      await page.goto(`${BASE}/products?nocache=1`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const pager = page.locator('[data-testid="storefront-products-pager"]');
      await expect(pager).toBeVisible({ timeout: 20000 });

      const labels = await pager.locator('.product-storefront__pager-item').allTextContents();
      const normalized = labels.map((t) => t.trim()).filter(Boolean);

      expect(normalized).toContain('1');
      expect(normalized.some((t) => t === '…' || t === '...')).toBeTruthy();
      expect(normalized).toContain('上一页');
      expect(normalized).toContain('下一页');

      // Must not render a long consecutive run of every page number for large totals.
      const numeric = normalized.filter((t) => /^\d+$/.test(t)).map((t) => Number(t));
      expect(numeric.length).toBeLessThanOrEqual(8);
      expect(Math.max(...numeric)).toBeGreaterThan(numeric.length);

      await page.goto(`${BASE}/products?page=7&nocache=1`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const midPager = page.locator('[data-testid="storefront-products-pager"]');
      await expect(midPager).toBeVisible({ timeout: 20000 });
      const midText = ((await midPager.innerText()) || '').replace(/\s+/g, ' ');
      expect(midText).toMatch(/1/);
      expect(midText).toMatch(/…|\.\.\./);
      expect(midText).toMatch(/\b7\b/);
    },
  );
});
