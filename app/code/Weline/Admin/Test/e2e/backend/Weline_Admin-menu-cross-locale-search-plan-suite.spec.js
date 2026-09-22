/**
 * 后台菜单多语言交叉搜索 — 计划收口套件（DB 索引）
 *
 * @weline-e2e-spec { module: Weline_Admin, type: plan-suite, layer: backend, feature: backend-menu-cross-locale-search }
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
const FATAL = /WLS Runtime Error|ParseError|Fatal error:|Call to undefined method|Class ['"][^'"]+['"] not found/i;

moduleDescribe(test, MODULE, '后台菜单多语言交叉搜索计划套件', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-MENU-XLOCALE-SUITE-001' },
    '侧栏无 data-search-text + 顶栏/侧栏 API backend_menu 交叉命中',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, 'admin', { timeout: 90000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      expect(
        await page.locator('#w-backend-sidebar [data-w-nav-filter-list] .w-backend-nav__entry[data-search-text]').count(),
      ).toBe(0);

      const product = page.locator(
        '#w-backend-sidebar .w-backend-nav__entry[data-source="Weline_Product::commerce:catalog:products"]',
      );
      if ((await product.count()) > 0) {
        const input = page.locator('#w-backend-nav-search');
        await input.fill('Products');
        await page.waitForTimeout(700);
        const hit = product.first();
        await expect(hit).toBeVisible();
        const ancestorHidden = await hit.evaluate((el) => {
          let node = el.parentElement;
          while (node) {
            if (node.classList && node.classList.contains('w-backend-nav__entry') && node.hidden) {
              return true;
            }
            if (node.getAttribute && node.getAttribute('data-w-nav-filter-list') !== null) {
              break;
            }
            node = node.parentElement;
          }
          return false;
        });
        expect(ancestorHidden, 'Products 命中后祖先不得 hidden').toBe(false);
      }

      const apiResult = await page.evaluate(async () => {
        const api = await window.Weline.Api.resource('search');
        const result = await api.search({
          q: 'Products',
          type: 'backend_menu',
          area: 'backend',
          page_size: 10,
        });
        return {
          hit_count: result.hit_count || (result.hits || []).length,
          titles: (result.hits || []).map((h) => h.title),
          ids: (result.hits || []).map((h) => h.entity_id),
        };
      });
      expect(apiResult.hit_count).toBeGreaterThan(0);
      expect(
        apiResult.ids.includes('Weline_Product::commerce:catalog:products')
          || apiResult.ids.some((id) => String(id).includes('Product')),
      ).toBeTruthy();
    },
  );
});
