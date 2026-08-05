/**
 * 像素详情页 F01–F04b + D07 报表卡片可达性（防截图「暂不可用」模块无 e2e）。
 *
 * 入口约束：侧栏「事件看板」→ website:select 选站 → 「详情报表」，禁止仅深链打开 detail。
 * 断言：detail 六块壳存在且不 Fatal。数据就绪出数属集成/DB 范围，
 * 由 Unit（成功/失败路径）+ 契约测试覆盖；本流不做假绿「必须有数」。
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
const { FATAL, PIXEL_MENU, openPixelSidebarMenu } = require('./pixel-menu-nav');

const MODULE = 'Weline_Visitor';

moduleDescribe(test, MODULE, 'Visitor 像素详情报表', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-DETAIL-REPORTS-001' },
    '菜单进入事件看板 → 详情报表挂载电商/路径/留存/引擎卡片壳',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await openPixelSidebarMenu(page, PIXEL_MENU.index, {
        urlIncludes: '/visitor/backend/pixel-dashboard/index',
      });

      // website:select（禁止原生 select）选默认站 websiteId=0，使「详情报表」入口出现
      await expect(page.locator('#index-filter-website_trigger, .weline-website-trigger').first()).toBeVisible({
        timeout: 15000,
      });
      await expect(page.locator('form.weline-pixel-toolbar select[name="websiteId"]')).toHaveCount(0);
      const websiteValue = page.locator('#index-filter-website_value, input[name="websiteId"][data-website-select-value]').first();
      await expect(websiteValue).toBeAttached();
      await websiteValue.evaluate((el) => {
        el.value = '0';
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
      await Promise.all([
        page.waitForURL(/pixel-dashboard\/index/, {
          timeout: 60000,
          waitUntil: 'domcontentloaded',
        }),
        page
          .locator('#pixel-index-filter-form button[type="submit"], form.weline-pixel-toolbar button:has-text("应用")')
          .first()
          .click(),
      ]);
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const detailLink = page.locator('a:has-text("详情报表"), a[href*="pixel-dashboard/detail"]').first();
      await expect(detailLink, '选站后应出现详情报表入口').toBeVisible({ timeout: 15000 });
      await Promise.all([
        page.waitForURL(/pixel-dashboard\/detail/, {
          timeout: 60000,
          waitUntil: 'domcontentloaded',
        }),
        detailLink.click(),
      ]);
      await waitForBackendShellReady(page);

      await expect(page.locator('body')).toContainText(/像素|详情|报表|站点/i, { timeout: 20000 });
      await expect(page.locator('#ecommerce-funnel')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('#ecommerce-revenue')).toBeVisible();
      await expect(page.locator('#ecommerce-items')).toBeVisible();
      await expect(page.locator('#path-exploration')).toBeVisible();
      await expect(page.locator('#retention')).toBeVisible();
      await expect(page.locator('body')).toContainText(/引擎报表/);

      // 卡片要么出数，要么明确告警/空态文案（不得空白消失）
      const funnel = page.locator('#ecommerce-funnel');
      await expect(funnel).toContainText(/电商漏斗|暂不可用|暂无浏览商品会话/);
      const revenue = page.locator('#ecommerce-revenue');
      await expect(revenue).toContainText(/购成与收入|购成收入|暂不可用|purchase_revenue/);
      const items = page.locator('#ecommerce-items');
      await expect(items).toContainText(/商品表现|暂不可用|暂无/);
      const pathBlock = page.locator('#path-exploration');
      await expect(pathBlock).toContainText(/路径探索|暂不可用|暂无/);
      const retention = page.locator('#retention');
      await expect(retention).toContainText(/留存分析|暂不可用|暂无/);

      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
