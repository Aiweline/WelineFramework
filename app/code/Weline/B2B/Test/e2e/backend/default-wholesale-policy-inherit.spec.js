/**
 * Product offers inherit default wholesale policy + guard reject fixture.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: backend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const CATALOG = 'weline_product/backend/catalog/products';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'default-wholesale-policy-inherit-fixture.php');

function runFixture() {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  return JSON.parse(last);
}

moduleDescribe(test, MODULE, '商品批发 · 继承模板与护栏', () => {
  test.setTimeout(300000);

  moduleCase(test, { module: MODULE, id: 'CK-B2B-DEFAULT-POLICY-INHERIT-001' }, '仅启用批发无手填档可继承；超折扣拒写；未tob不套', async ({ page }) => {
    const fixture = runFixture();
    expect(fixture.ok, JSON.stringify(fixture)).toBeTruthy();
    expect(fixture.inherit_source).toBe('b2b_default_policy');
    expect(fixture.inherit_amount_minor).toBe(9500);
    expect(fixture.tob_off_source).toBe('retail');
    expect(fixture.guard_rejected).toBeTruthy();

    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
    await gotoBackend(page, CATALOG + '?website_id=0', {
      waitUntil: 'domcontentloaded',
      useProxy: false,
      timeout: 90000,
    });
    await waitForBackendShellReady(page);

    const editLink = page.locator('a[href*="edit-product"], a[href*="global_product_uuid"]').first();
    await expect(editLink).toBeVisible({ timeout: 60000 });
    await editLink.click();
    await page.waitForLoadState('domcontentloaded');
    await waitForBackendShellReady(page);

    const offersTab = page.locator('[data-product-panel-focus="offers"], [data-product-tab="offers"], button:has-text("规格与价格"), a:has-text("规格与价格")').first();
    if (await offersTab.count()) {
      await offersTab.click();
    }

    const wholesale = page.getByTestId('b2b-product-offers-wholesale');
    await expect(wholesale).toBeVisible({ timeout: 60000 });
    await expect(page.getByTestId('b2b-product-wholesale-enabled')).toBeVisible();
    await expect(page.getByTestId('b2b-product-wholesale-status')).toBeVisible();

    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    const residual = (guards.failures || []).filter((msg) => !/query-bin|product_admin\.execute|403/i.test(String(msg)));
    expect(residual, '后台页面存在未处理浏览器错误').toEqual([]);
  });
});
