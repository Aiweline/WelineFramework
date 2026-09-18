/**
 * /best-sellers listing uses unified <w:product:card> shelf cards.
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

moduleDescribe(test, MODULE, 'frontend best-sellers unified product card', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-PRODUCT-BEST-SELLERS-CARD-001' },
    'best-sellers page renders ranking shelf with weline-product-card',
    async ({ page }) => {
      await page.goto(`${BASE}/best-sellers?nocache=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });

      const section = page.locator('[data-testid="storefront-best-sellers"]');
      await expect(section).toBeVisible({ timeout: 20000 });

      const grid = page.locator('[data-testid="storefront-best-sellers-grid"]');
      await expect(grid).toBeVisible({ timeout: 15000 });

      const ranked = page.locator('[data-testid="storefront-best-sellers-card"]');
      await expect(ranked.first()).toBeVisible({ timeout: 15000 });
      expect(await ranked.count()).toBeGreaterThan(0);

      const cards = page.locator('[data-testid="storefront-best-sellers-card"] [data-testid="weline-product-card"]');
      await expect(cards.first()).toBeVisible({ timeout: 15000 });
      expect(await cards.count()).toBeGreaterThan(0);

      await expect(page.locator('.best-sellers-page__rank').first()).toBeVisible();
      await expect(page.locator('[data-testid="storefront-best-sellers-podium"]')).toHaveCount(0);
      await expect(page.locator('body')).not.toContainText(/Fatal error|ParseError|WLS Runtime Error/i);
    },
  );
});
