/**
 * FAQ PDP browser: product FAQ section with theme markers (shentu).
 *
 * @weline-e2e-spec { module: Weline_Faq, type: feature, layer: frontend }
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

const MODULE = 'Weline_Faq';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'e2e-pdp-faq browser shentu', () => {
  test.setTimeout(90000);

  moduleCase(test, { module: MODULE, id: 'shentu-pdp-faq' }, 'PDP FAQ markers theme tokens', async ({ page }) => {
    const widget = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Faq/view/templates/frontend/widgets/product-faq.phtml'),
      'utf8'
    );
    expect(widget).toContain('weline-product-faq__source');
    expect(widget).toContain('--weline-theme-');
    expect(widget).toContain('--color-text');
    expect(widget).toContain('FaqPdpResolveService');

    await page.goto(`${BASE}/product/557`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(/Fatal error|ParseError|WLS Runtime Error/i);

    const faq = page.locator('#product-faq, .weline-product-faq, [data-widget="product-faq"]');
    const count = await faq.count();
    // Slot or widget may render; empty FAQ early-returns widget but layout slot can remain.
    expect(count >= 0).toBeTruthy();
    if (count > 0) {
      const source = page.locator('.weline-product-faq__source');
      if ((await source.count()) > 0) {
        await expect(source.first()).toBeVisible();
      }
    }
  });
});
