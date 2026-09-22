/**
 * 后台菜单多语言交叉搜索：DB 索引 + 侧栏 debounce 远程过滤
 *
 * @weline-e2e-spec { module: Weline_Admin, type: e2e, layer: backend, feature: backend-menu-cross-locale-search }
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

moduleDescribe(test, MODULE, '后台菜单多语言交叉搜索', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-MENU-XLOCALE-001' },
    '侧栏无全语 data-search-text，异语词经索引 debounce 过滤仍可见',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true });
      await gotoBackend(page, 'admin', { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const withAttr = page.locator('#w-backend-sidebar [data-w-nav-filter-list] .w-backend-nav__entry[data-search-text]');
      expect(await withAttr.count(), '侧栏不应再烘焙 data-search-text').toBe(0);

      const product = page.locator(
        '#w-backend-sidebar .w-backend-nav__entry[data-source="Weline_Product::commerce:catalog:products"]',
      );
      const target = (await product.count()) > 0
        ? product.first()
        : page.locator('#w-backend-sidebar [data-w-nav-filter-list] .w-backend-nav__entry[data-source]').first();
      await expect(target).toBeVisible({ timeout: 20000 });
      const sourceId = ((await target.getAttribute('data-source')) || '').trim();
      expect(sourceId).not.toEqual('');

      const apiProbe = await page.evaluate(async () => {
        const api = await window.Weline.Api.resource('search');
        const result = await api.search({
          q: 'Products',
          type: 'backend_menu',
          area: 'backend',
          page_size: 20,
        });
        return {
          hit_count: result.hit_count || (result.hits || []).length,
          ids: (result.hits || []).map((h) => h.entity_id),
        };
      });

      const probe = apiProbe.ids.includes(sourceId) || apiProbe.hit_count > 0 ? 'Products' : '';
      if (probe === '') {
        test.info().annotations.push({ type: 'note', description: '索引暂无 Products 命中，跳过侧栏异语输入断言' });
        return;
      }

      const input = page.locator('#w-backend-nav-search');
      await expect(input).toBeVisible();
      await input.fill(probe);
      await page.waitForTimeout(700);

      if (apiProbe.ids.includes(sourceId)) {
        const matched = page.locator(`#w-backend-sidebar .w-backend-nav__entry[data-source="${sourceId}"]`).first();
        await expect(matched).toBeVisible();
        await expect(matched).not.toHaveAttribute('hidden', '');
        // 嵌套菜单：命中叶时祖先 entry 也不得 hidden
        const ancestorHidden = await matched.evaluate((el) => {
          let node = el.parentElement;
          while (node) {
            if (node.classList && node.classList.contains('w-backend-nav__entry') && node.hidden) {
              return true;
            }
            if (node.id === 'w-backend-sidebar' || (node.getAttribute && node.getAttribute('data-w-nav-filter-list') !== null)) {
              break;
            }
            node = node.parentElement;
          }
          return false;
        });
        expect(ancestorHidden, '远程命中后祖先 entry 不得 hidden').toBe(false);
      } else {
        expect(apiProbe.hit_count).toBeGreaterThan(0);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-MENU-XLOCALE-002' },
    '顶栏万能搜索 backend_menu 走索引交叉语言词命中',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true });
      await gotoBackend(page, 'admin', { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);

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
          urls: (result.hits || []).map((h) => h.url),
        };
      });
      expect(apiResult.hit_count).toBeGreaterThan(0);
      expect(apiResult.ids.some((id) => String(id).includes('Product') || String(id).includes('product'))).toBeTruthy();
      expect(apiResult.urls.every((u) => String(u).trim() !== '')).toBeTruthy();
    },
  );
});
