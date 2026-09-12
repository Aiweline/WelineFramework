/**
 * Catalog bulk delete uses Theme dialog.confirm (not window.confirm).
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
  waitForBackendShellReady,
  installBackendBrowserGuards,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const ROUTE = 'weline_product/backend/catalog/products';

moduleDescribe(test, MODULE, '商品目录批量删除主题确认框', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'CK-PRODUCT-BULK-THEME-CONFIRM-001' }, '删除弹出主题 w-dialog 且不调用原生 confirm', async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
    await gotoBackend(page, ROUTE, { waitUntil: 'domcontentloaded', useProxy: false, timeout: 90000 });
    await waitForBackendShellReady(page);

    await page.waitForSelector('.w-datatable__row-select', { timeout: 30000 });

    const nativeHits = [];
    await page.exposeFunction('__welineE2eNativeConfirmHit', (msg) => {
      nativeHits.push(String(msg || ''));
    });
    await page.evaluate(() => {
      window.confirm = function (message) {
        if (typeof window.__welineE2eNativeConfirmHit === 'function') {
          window.__welineE2eNativeConfirmHit(String(message || ''));
        }
        return false;
      };
    });

    await page.locator('.w-datatable__row-select').first().click({ force: true });
    const bulkDelete = page.locator('[data-product-bulk-action="archive"]');
    await expect(bulkDelete).toBeEnabled({ timeout: 10000 });
    await bulkDelete.click();

    const dialog = page.locator('dialog.w-dialog[open]');
    await expect(dialog).toBeVisible({ timeout: 10000 });
    await expect(dialog.locator('.w-dialog__title')).toContainText('确定删除选中的');
    await expect(dialog.locator('.w-dialog__title')).toContainText('个商品吗？');
    await expect(dialog.locator('.w-dialog__body')).toContainText('按治理规则将归档（非物理删除）');
    await expect(dialog.locator('.w-dialog__body')).toContainText('归档后不可再编辑');

    await dialog.getByRole('button', { name: '取消' }).click();
    await expect(dialog).toBeHidden({ timeout: 5000 });
    expect(nativeHits, 'native window.confirm must not run').toEqual([]);
    guards.assertClean();
  });
});
