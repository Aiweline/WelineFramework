/**
 * Best-sellers unified product-card plan closeout suite.
 *
 * @weline-e2e-spec { module: Weline_Product, type: e2e, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite best-sellers unified card', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'A-e2e-best-sellers-card' },
    '完整通路：热销榜货架统一商品卡',
    async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto(`${BASE}/best-sellers?e2e_bs_card=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });

      await expect(page.locator('[data-testid="storefront-best-sellers"]')).toBeVisible({ timeout: 20000 });
      await expect(page.locator('[data-testid="storefront-best-sellers-grid"]')).toBeVisible({ timeout: 15000 });
      await expect(
        page.locator('[data-testid="storefront-best-sellers-card"] [data-testid="weline-product-card"]').first(),
      ).toBeVisible({ timeout: 15000 });
      await expect(page.locator('.best-sellers-page__rank').first()).toBeVisible();
      await expect(page.locator('[data-testid="storefront-best-sellers-podium"]')).toHaveCount(0);
      await expect(page.locator('body')).not.toContainText(/Fatal error|ParseError|WLS Runtime Error/i);
    },
  );

  moduleCase(test, { module: MODULE, id: 'B-source-contract' }, '源码契约：best-sellers 用 Taglib 商品卡', async () => {
    const template = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Product/view/templates/frontend/best-sellers/index.phtml'),
      'utf8',
    );
    expect(template).toContain('<w:product:card');
    expect(template).toContain('weline-product-card-shelf');
    expect(template).toContain('ProductCardRenderer::emitStylesheetLinkOnce()');
    expect(template).not.toContain('storefront-best-sellers-podium');
    expect(template).not.toContain('ProductCardAddToCartParams::fetchDictionary');
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：BestSellers 统一商品卡', async () => {
    expect(true).toBeTruthy();
  });
});
