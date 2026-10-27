/**
 * Amayum 10 个公开页面 + 404 的最小 E2E 骨架。
 *
 * @weline-e2e-spec { module: Amayum_Website, type: e2e, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Amayum_Website';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const PREFIX = process.env.AMAYUM_E2E_PREFIX || '/~site/amayum';

const PAGES = [
  '/',
  '/commerce',
  '/case-studies',
  '/journal',
  '/journal/build-platform',
  '/journal/independent-store-seo',
  '/journal/shopify-alternative',
  '/diagnosis',
  '/legal',
  '/sitemap',
];

moduleDescribe(test, MODULE, 'Amayum storefront pages', () => {
  test.setTimeout(180000);

  for (const route of PAGES) {
    const id = 'page-' + (route === '/' ? 'home' : route.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, ''));
    moduleCase(test, { module: MODULE, id }, `${route} returns 200 with one H1 and canonical`, async ({ page }) => {
      const response = await page.goto(`${BASE}${PREFIX}${route}`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
      expect(response.status()).toBe(200);
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:title"]')).toHaveCount(1);
    });
  }

  moduleCase(test, { module: MODULE, id: 'page-404' }, 'unknown route returns HTTP 404', async ({ page }) => {
    const response = await page.goto(`${BASE}${PREFIX}/no-such-page`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });
    expect(response.status()).toBe(404);
    await expect(page.locator('h1')).toHaveCount(1);
  });
});
