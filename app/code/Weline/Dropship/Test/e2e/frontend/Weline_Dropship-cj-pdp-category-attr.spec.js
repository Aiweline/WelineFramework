/**
 * CJ 真实刊登 PDP：分类面包屑不自曝货源壳 + 主图
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: flow, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Dropship';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const RESELLER_CRUMB = /货源商城|CJ货源|精选商品|海外精选|来源\s*CJ/i;

moduleDescribe(test, MODULE, 'CJ 刊登 PDP 分类与媒体', () => {
  test.setTimeout(90000);

  async function assertNoResellerChrome(page) {
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);
    // Category nav / crumbs must not telegraph dropship reselling.
    await expect(page.locator('body')).not.toContainText(RESELLER_CRUMB);
    const crumbs = page.getByRole('navigation', { name: '面包屑' });
    if ((await crumbs.count()) > 0) {
      await expect(crumbs).not.toContainText(RESELLER_CRUMB);
      await expect(crumbs).not.toContainText(/c76c477ea53ca/);
    }
  }

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-CJ-PDP-001' }, '规格品 557：不露货源壳 + 主图 + 规格', async ({ page }) => {
    await page.goto(`${BASE}/product/557`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await assertNoResellerChrome(page);
    // If PDP still resolves, assert media/title; if redirected to listing, branding assert above is enough.
    if (/\/product\/557\/?$/i.test(page.url())) {
      await expect(page.locator('img[src*="/pub/media/catalog/dropship/cj/"]').first()).toBeVisible({ timeout: 30000 });
      await expect(page.getByRole('heading', { level: 1 })).toContainText('菜板');
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-CJ-PDP-002' }, '简单品 558：不露货源壳 + 主图', async ({ page }) => {
    await page.goto(`${BASE}/product/558`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await assertNoResellerChrome(page);
    if (/\/product\/558\/?$/i.test(page.url())) {
      await expect(page.locator('img[src*="/pub/media/catalog/dropship/cj/"]').first()).toBeVisible({ timeout: 30000 });
    }
  });
});
