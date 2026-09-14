/**
 * Imported Hanfu PDP: selecting a color must select that color's variant image.
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
const PDP = '/product/hua-chao-ji-ling-yun-yuan-chuang-ming-zhi-fei-yu-fu-jin-yi-060613aa';

moduleDescribe(test, MODULE, 'Hanfu variant image selection', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-ch1-full' },
    '颜色规格切换后主图跟随切换',
    async ({ page }) => {
      await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const primary = page.locator('.product-native-detail__primary-image');
      const black = page.getByRole('button', { name: '黑色', exact: true });
      const red = page.getByRole('button', { name: '红色', exact: true });
      const redSwatch = red.locator('img');
      await expect(primary).toBeVisible();
      await expect(redSwatch).toBeVisible();

      const redSrc = await redSwatch.getAttribute('src');
      expect(redSrc).toBeTruthy();

      await black.click();
      await page.waitForTimeout(3000);
      const blackSrc = await primary.getAttribute('src');
      expect(redSrc).not.toBe(blackSrc);

      await red.click();
      await expect(page).toHaveURL(/\bcolor=red\b/);
      await page.waitForTimeout(6000);
      await expect(primary).toHaveAttribute('src', redSrc);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-plan-suite' },
    '汉服商品详情、EAV 规格、短 SKU、本地图片完整通路',
    async ({ page }) => {
      await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await expect(page.locator('[data-testid="storefront-product-detail"]')).toBeVisible();
      await expect(page.locator('[data-testid="product-variant-axes"]')).toBeVisible();
      await expect(page.getByText('关于该商品', { exact: true })).toBeVisible();
      expect(await page.locator('.product-native-detail__description-body img').count()).toBeGreaterThan(0);
      const sku = (await page.locator('.product-native-detail__sku').innerText()).replace(/^SKU:\s*/, '').trim();
      expect(sku.length).toBeGreaterThan(0);
      expect(sku.length).toBeLessThanOrEqual(40);
      const primary = page.locator('.product-native-detail__primary-image');
      const black = page.getByRole('button', { name: '黑色', exact: true });
      const red = page.getByRole('button', { name: '红色', exact: true });
      const redSrc = await red.locator('img').getAttribute('src');
      await black.click();
      await page.waitForTimeout(3000);
      await red.click();
      await page.waitForTimeout(6000);
      await expect(primary).toHaveAttribute('src', redSrc);
    }
  );
});
