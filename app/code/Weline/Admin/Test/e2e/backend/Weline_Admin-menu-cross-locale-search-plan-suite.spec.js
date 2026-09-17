/**
 * 后台菜单多语言交叉搜索 — 计划收口套件
 *
 * @weline-e2e-spec { module: Weline_Admin, type: plan-suite, layer: backend, feature: backend-menu-cross-locale-search }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  waitForBackendShellReady,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Admin';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, '后台菜单多语言交叉搜索计划套件', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-MENU-XLOCALE-SUITE-001' },
    '侧栏 data-search-text 交叉过滤 + 顶栏 API backend_menu 交叉命中',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, 'admin', { timeout: 90000, settleMs: 800, useProxy: false });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const entries = page.locator('#w-backend-sidebar [data-w-nav-filter-list] .w-backend-nav__entry[data-search-text]');
      await expect(entries.first()).toBeVisible({ timeout: 20000 });
      expect(await entries.count()).toBeGreaterThan(0);

      const product = page.locator(
        '#w-backend-sidebar .w-backend-nav__entry[data-source="Weline_Product::commerce:catalog:products"]',
      );
      const searchText = ((await product.getAttribute('data-search-text')) || '').trim();
      expect(searchText.toLowerCase()).toContain('products');

      const input = page.locator('#w-backend-nav-search');
      await input.fill('Products');
      await page.waitForTimeout(250);
      await expect(product).toBeVisible();

      const apiResult = await page.evaluate(async () => {
        const api = await window.Weline.Api.resource('search');
        const result = await api.search({
          q: 'Products',
          type: 'backend_menu',
          area: 'backend',
          page_size: 10,
        });
        return {
          hit_count: result.hit_count,
          titles: (result.hits || []).map((h) => h.title),
          ids: (result.hits || []).map((h) => h.entity_id),
        };
      });
      expect(apiResult.hit_count).toBeGreaterThan(0);
      expect(apiResult.ids).toContain('Weline_Product::commerce:catalog:products');
    },
  );
});
