/**
 * video-carousel plan-suite（对齐冻结红灯骨架）
 *
 * 权威 UC：app/code/Weline/Theme/doc/开发/team/video-carousel/meetings/对齐冻结.md
 * 套件 id：video-carousel-plan-suite
 *
 * 状态：骨架草稿。可测面未就绪前保持 test.fixme / 显式失败占位；
 * 禁止改生产 PHP/模板/CSS 骗绿。施工就绪后按 UC-1…UC-4 摘除 fixme。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: e2e, layer: frontend, suite: video-carousel-plan-suite }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite video-carousel', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'UC-1' }, '首页默认渲染 video-carousel', async ({ page }) => {
    test.fixme(true, '红灯骨架：待 D6 layout + D4 部件可测面就绪后实现（见 meetings/对齐冻结.md UC-1）');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.locator('#homepage-videos').scrollIntoViewIfNeeded();
    await expect(page.locator('#homepage-videos [data-site-block="video-carousel"]')).toBeAttached({
      timeout: 20000,
    });
  });

  moduleCase(test, { module: MODULE, id: 'UC-2' }, '多视频项轮播切换', async ({ page }) => {
    test.fixme(true, '红灯骨架：待 D7 轮播 JS + ≥2 项造数就绪（UC-2）');
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-site-block="video-carousel"]')).toBeAttached({ timeout: 20000 });
  });

  moduleCase(test, { module: MODULE, id: 'UC-3' }, '关联商品 Weline.UI.dialog 多卡', async ({ page }) => {
    test.fixme(true, '红灯骨架：待 D5 cardsByIds + D8 dialog；禁止壳层冒烟替代（UC-3）');
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-site-block="video-carousel"]')).toBeAttached({ timeout: 20000 });
  });

  moduleCase(test, { module: MODULE, id: 'UC-4' }, '空 product_ids 诚实行为', async ({ page }) => {
    test.fixme(true, '红灯骨架：待空关联造数 + CTA/空态分支（UC-4）');
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-site-block="video-carousel"]')).toBeAttached({ timeout: 20000 });
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：video-carousel', async () => {
    test.fixme(true, '红灯骨架：UC-1…UC-4 全绿前本汇总不得假绿');
    expect(true).toBeTruthy();
  });
});
