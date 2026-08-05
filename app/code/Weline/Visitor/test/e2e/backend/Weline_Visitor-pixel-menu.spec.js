/**
 * 像素分析：从后台侧栏菜单级入口开始的 WebUI e2e（防深链假绿）。
 *
 * 覆盖：数据工具 → 像素分析 → 事件看板 / 热表明细 / 冷归档明细 / 流量渠道
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');
const {
  FATAL,
  PIXEL_MENU,
  revealPixelMenuGroup,
  openPixelSidebarMenu,
} = require('./pixel-menu-nav');

const MODULE = 'Weline_Visitor';

moduleDescribe(test, MODULE, 'Visitor 像素侧栏菜单入口', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PIXEL-MENU-001' },
    '侧栏可见「数据工具 → 像素分析」及四叶子菜单',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await waitForBackendShellReady(page);

      await revealPixelMenuGroup(page);

      for (const source of [
        PIXEL_MENU.index,
        PIXEL_MENU.list,
        PIXEL_MENU.archiveList,
        PIXEL_MENU.trafficChannel,
      ]) {
        const link = page.locator(`#side-menu a[data-source="${source}"]`).first();
        await expect(link, source).toBeVisible({ timeout: 10000 });
        const href = (await link.getAttribute('href')) || '';
        expect(href, `${source} href`).toMatch(/\/visitor\/backend\//);
        expect(href, `${source} 必须含 backend`).toMatch(/\/backend\//);
      }

      await expect(page.locator('#side-menu')).toContainText(/像素分析/);
      await expect(page.locator('#side-menu')).toContainText(/事件看板/);
      await expect(page.locator('#side-menu')).toContainText(/热表明细/);
      await expect(page.locator('#side-menu')).toContainText(/冷归档明细/);
      await expect(page.locator('#side-menu')).toContainText(/流量渠道/);
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PIXEL-MENU-002' },
    '菜单进入事件看板并渲染多站点监听页',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await openPixelSidebarMenu(page, PIXEL_MENU.index, {
        urlIncludes: '/visitor/backend/pixel-dashboard/index',
      });

      await expect(page.locator('body')).toContainText(/事件看板|多站点事件监听看板/i, {
        timeout: 20000,
      });
      await expect(page.locator('main#main-content, main.backend-main-content').first()).toBeVisible();
      await expect(page.locator('#index-filter-website_trigger, form.weline-pixel-toolbar .weline-website-trigger').first()).toBeVisible();
      await expect(page.locator('form.weline-pixel-toolbar select[name="websiteId"]')).toHaveCount(0);
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PIXEL-MENU-003' },
    '菜单进入热表明细 / 冷归档明细 / 流量渠道',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });

      await openPixelSidebarMenu(page, PIXEL_MENU.list, {
        urlIncludes: '/visitor/backend/pixel-dashboard/list',
      });
      await expect(page.locator('.weline-pixel-dashboard-list, #pixel-list-filter-form').first()).toBeVisible({
        timeout: 15000,
      });
      await expect(page.locator('body')).toContainText(/热表明细|像素事件列表/i);

      await openPixelSidebarMenu(page, PIXEL_MENU.archiveList, {
        urlIncludes: '/visitor/backend/pixel-dashboard/archive-list',
      });
      await expect(page.locator('.weline-pixel-dashboard-archive-list')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('#pixel-archive-list-filter-form')).toBeVisible();
      await expect(page.locator('body')).toContainText(/冷归档|站点（必填）|请选择站点/i);

      await openPixelSidebarMenu(page, PIXEL_MENU.trafficChannel, {
        urlIncludes: '/visitor/backend/traffic-channel',
      });
      await expect(page.locator('.weline-traffic-channel-list, body').first()).toContainText(/流量渠道|投放渠道|新建/i, {
        timeout: 15000,
      });
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
