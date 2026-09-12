/**
 * Catalog category tree: parent nodes collapse / expand.
 *
 * @weline-e2e-spec { module: Weline_Catalog, type: feature, layer: backend }
 *
 * Run tip (TLS SNI): when proxy upstream https://127.0.0.1:9555 ECONNRESET,
 * use FQDN direct:
 * PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_TARGET_ORIGIN='https://p05113ef3.test.weline.com:9555' \
 *   php bin/w e2e:run app/code/Weline/Catalog/Test/e2e/backend/category-tree-collapse.spec.js --project=chromium --workers=1
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

const MODULE = 'Weline_Catalog';
const ROUTE = 'weline_catalog/backend/category/index?website_id=0&scope_level=website&space=product';

moduleDescribe(test, MODULE, '分类树折叠', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CATALOG-TREE-COLLAPSE-001' },
    '父节点 caret 可收起子树并再展开',
    async ({ page }) => {
      installBackendBrowserGuards(page);
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
      await gotoBackend(page, ROUTE, { waitUntil: 'domcontentloaded', useProxy: false, timeout: 90000 });
      await waitForBackendShellReady(page);

      const tree = page.locator('[data-testid="catalog-category-tree"]');
      await expect(tree).toBeVisible({ timeout: 30000 });

      const firstExpanded = tree.locator('[data-category-node][aria-expanded="true"]').first();
      await expect(firstExpanded).toBeVisible({ timeout: 15000 });
      const parentId = await firstExpanded.getAttribute('data-id');
      expect(parentId).toBeTruthy();
      const parent = tree.locator(`[data-category-node][data-id="${parentId}"]`);

      const childList = parent.locator(':scope > [data-category-tree-list]');
      await expect(childList).toBeVisible();

      const toggle = parent.locator(':scope > .w-catalog-tree__row > [data-catalog-tree-toggle]');
      await toggle.click();
      await expect(parent).toHaveAttribute('aria-expanded', 'false');
      await expect(childList).toBeHidden();

      await toggle.click();
      await expect(parent).toHaveAttribute('aria-expanded', 'true');
      await expect(childList).toBeVisible();
    },
  );
});
