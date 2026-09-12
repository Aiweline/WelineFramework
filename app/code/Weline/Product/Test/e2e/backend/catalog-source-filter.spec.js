/**
 * Catalog list source filter (来源).
 *
 * @weline-e2e-spec { module: Weline_Product, type: feature, layer: backend }
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

const MODULE = 'Weline_Product';
const ROUTE = 'weline_product/backend/catalog/products';

moduleDescribe(test, MODULE, '商品目录来源过滤', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'CK-PRODUCT-SOURCE-FILTER-001' }, '筛选栏暴露来源下拉并可提交', async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
    await gotoBackend(page, ROUTE, { waitUntil: 'domcontentloaded', useProxy: false, timeout: 90000 });
    await waitForBackendShellReady(page);

    const form = page.locator('[data-testid="product-filter-form"]');
    await expect(form).toBeVisible({ timeout: 60000 });
    const source = form.locator('[data-testid="product-filter-source"]');
    await expect(source).toBeVisible();
    await expect(source.locator('option[value=""]')).toHaveCount(1);
    await expect(source.locator('option[value="__none__"]')).toHaveCount(1);

    await source.selectOption('__none__');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/[?&]source=__none__/);
    const filteredForm = page.locator('[data-testid="product-filter-form"]');
    await expect(filteredForm).toBeVisible({ timeout: 60000 });
    await expect(filteredForm.locator('[data-testid="product-filter-source"]')).toHaveValue('__none__');
    await expect(page.locator('#product-catalog-list, [data-testid="product-catalog-table"], .w-datatable').first()).toBeVisible({ timeout: 60000 });
    guards.assertClean();
  });
});
