/**
 * PDP 常显猜你喜欢 / 最近浏览槽（布局通路）。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: feature, layer: frontend }
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

const MODULE = 'Weline_Theme';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.WELINE_E2E_PDP_PATH || '/product/192';

moduleDescribe(test, MODULE, 'e2e-layout PDP personalization slots pathway', () => {
  test.setTimeout(90000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-layout' }, '完整通路 Playwright：PDP 底部常显猜你喜欢与最近浏览前后端', async ({ page }) => {
    const layout = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/layouts/product/default.phtml'),
      'utf8'
    );
    expect(layout).toContain('id="product-you-may-like"');
    expect(layout).toContain('id="product-recently-viewed"');
    expect(layout).toContain('product-detail-layout__personalization');
    const gate = layout.match(/condition="meta\.showRelatedProducts"[\s\S]*?<\/if>/);
    expect(gate).toBeTruthy();
    expect(gate[0]).not.toContain('id="product-you-may-like"');
    expect(gate[0]).not.toContain('id="product-recently-viewed"');
    expect(layout.indexOf('product-detail-layout__personalization')).toBeGreaterThan(
      layout.indexOf('condition="meta.showRelatedProducts"')
    );

    await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(/Fatal error|ParseError|WLS Runtime Error/i);

    await expect(page.locator('[data-wslot="product-you-may-like"]').first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-widget-code="you-may-like"]').first()).toBeVisible();
    await expect(page.locator('[data-wslot="product-recently-viewed"]').first()).toBeVisible();
    await expect(page.locator('[data-widget-code="recently-viewed"]').first()).toBeVisible();
  });
});
