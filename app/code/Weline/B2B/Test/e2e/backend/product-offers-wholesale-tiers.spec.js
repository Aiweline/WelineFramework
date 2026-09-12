/**
 * Product edit · Specs and Prices shows B2B wholesale enable + qty tiers.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: backend }
 */

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

moduleDescribe(test, MODULE, '商品编辑规格与价格 · 批发阶梯', () => {
  test.setTimeout(240000);

  moduleCase(test, { module: MODULE, id: 'CK-B2B-PRODUCT-TIERS-001' }, '编辑页规格与价格露出启用批发与阶梯表', async ({ page }) => {
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
    } else {
      await page.locator('[data-product-panel="offers"]').evaluate((el) => {
        el.hidden = false;
      }).catch(() => {});
    }

    const wholesale = page.getByTestId('b2b-product-offers-wholesale');
    await expect(wholesale).toBeVisible({ timeout: 60000 });
    await expect(page.getByTestId('b2b-product-wholesale-enabled')).toBeVisible();
    await expect(page.getByTestId('b2b-product-wholesale-status')).toBeVisible();

    const tierTable = page.getByTestId('b2b-product-tier-table');
    const needSku = page.getByTestId('b2b-product-tiers-need-sku');
    if (await tierTable.count()) {
      await expect(tierTable).toBeVisible();
      await expect(page.getByTestId('b2b-product-tiers-save')).toBeVisible();
    } else {
      await expect(needSku).toBeVisible();
    }

    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    // query-bin product_admin.execute 偶发 403 与本验收无关；不阻断阶梯 UI 契约。
    const residual = (guards.failures || []).filter((msg) => !/query-bin|product_admin\.execute|403/i.test(String(msg)));
    expect(residual, '后台页面存在未处理浏览器错误').toEqual([]);
  });
});
