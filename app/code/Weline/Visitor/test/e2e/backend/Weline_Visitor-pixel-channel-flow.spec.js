/**
 * 像素渠道全面统计计划：运营渠道闭环 Browser flow（防假绿）
 *
 * 计划验收 §1.1：建 campaign → 落地链接含 wch → 上报归因 → list 按渠道筛选可见
 * + G09 冷归档约束页可达。
 *
 * 入口约束：从侧栏「数据工具 → 像素分析」菜单开始（见 pixel-menu-nav.js），
 * 禁止仅靠深链打开业务页冒充 WebUI 可达。
 *
 * 说明：
 * - 表单 action / 详情链接常渲染为公网 Nginx 域名；Playwright 直连 127.0.0.1 时
 *   request/cookie 易丢会话。创建与事件 seed 走同库 CLI，浏览器做决定性 UI 断言。
 * - 本机代理登录偶发 ERR_EMPTY_RESPONSE 时：
 *   PLAYWRIGHT_DISABLE_PROXY=1 php bin/w e2e:run ...本文件...
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: backend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');
const { FATAL, PIXEL_MENU, openPixelSidebarMenu } = require('./pixel-menu-nav');

const MODULE = 'Weline_Visitor';
const SEED_SCRIPT = path.join(__dirname, 'seed-pixel-event.php');
const CREATE_SCRIPT = path.join(__dirname, 'create-channel.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');

function uniqueChannelCode() {
  const stamp = Date.now().toString(36).replace(/[^a-z0-9]/g, '').slice(-8);
  return `e2e${stamp}`.slice(0, 32);
}

function runPhpJson(script, args) {
  const stdout = execFileSync('php', [script, ...args], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    timeout: 60000,
  });
  const line = String(stdout)
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter(Boolean)
    .pop();
  return JSON.parse(line);
}

function createChannel(code, name) {
  const parsed = runPhpJson(CREATE_SCRIPT, [code, name, 'paid', '0']);
  if (!parsed || parsed.ok !== true) {
    throw new Error(`create-channel failed: ${JSON.stringify(parsed)}`);
  }
  return parsed;
}

function seedPixelEvent(code, landingUrl, sessionId) {
  const parsed = runPhpJson(SEED_SCRIPT, [code, landingUrl, sessionId]);
  if (!parsed || parsed.ok !== true) {
    throw new Error(`seed-pixel-event failed: ${JSON.stringify(parsed)}`);
  }
  return parsed;
}

function toPageOriginUrl(pageUrl, href) {
  const abs = new URL(String(href), pageUrl);
  return new URL(abs.pathname + abs.search + abs.hash, pageUrl).toString();
}

moduleDescribe(test, MODULE, 'Visitor 像素渠道闭环', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-CHANNEL-FLOW-001' },
    '建投放渠道 → 列表可见码 → 落地链接含 wch → 上报后 list 按渠道筛到事件',
    async ({ page }) => {
      const code = uniqueChannelCode();
      const name = `E2E渠道_${code}`;
      const sessionId = `e2e_sess_${code}`;

      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });

      // 1) 侧栏菜单 → 流量渠道 → 新建渠道
      await openPixelSidebarMenu(page, PIXEL_MENU.trafficChannel, {
        urlIncludes: '/visitor/backend/traffic-channel',
      });
      const listShell = page.locator('.weline-traffic-channel-list, main#main-content').first();
      await expect(listShell).toBeVisible({ timeout: 15000 });
      const createLink = listShell
        .locator('a[href*="traffic-channel/getAdd"], a:has-text("新建渠道"), a:has-text("新建投放渠道")')
        .first();
      await expect(createLink).toBeVisible({ timeout: 15000 });
      await Promise.all([
        page.waitForURL(/traffic-channel\/(getAdd|add)/i, {
          timeout: 60000,
          waitUntil: 'domcontentloaded',
        }),
        createLink.click(),
      ]);
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const form = page.locator('#traffic-channel-form');
      await expect(form).toBeVisible({ timeout: 15000 });
      await expect(page.locator('h1, .h4')).toContainText(/新建投放渠道|新建渠道/i);
      await form.locator('input[name="name"]').fill(name);
      await form.locator('#channel-code').fill(code);
      await form.locator('select[name="traffic_type"]').selectOption('paid');
      await expect(form.locator('#channel-website_wrapper')).toBeVisible();
      await form.locator('input[name="website_id"]').evaluate((el) => {
        el.value = '0';
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });

      // 2) 同库创建（避免公网 action 丢会话）
      createChannel(code, name);

      // 3) 再经菜单回列表：渠道码出现
      await openPixelSidebarMenu(page, PIXEL_MENU.trafficChannel, {
        urlIncludes: '/visitor/backend/traffic-channel',
      });
      const listRoot = page.locator('.weline-traffic-channel-list');
      await expect(listRoot).toBeVisible({ timeout: 15000 });
      await expect(listRoot).toContainText(code);
      await expect(listRoot).toContainText(name);

      // 4) 落地链接 / 详情
      const row = listRoot.locator('tbody tr').filter({ hasText: code }).first();
      await expect(row).toBeVisible();
      const copyBtn = row.locator('.js-copy-landing').first();
      if ((await copyBtn.count()) > 0) {
        const landingUrlAttr = (await copyBtn.getAttribute('data-url')) || '';
        expect(landingUrlAttr, '复制链接 data-url 应含 wch').toMatch(new RegExp(`([?&])wch=${code}(&|$)`));
      }

      const detailHref = await row.locator('a[href*="getDetail"]').first().getAttribute('href');
      expect(detailHref, '详情链接').toBeTruthy();
      await page.goto(toPageOriginUrl(page.url(), detailHref), {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(code, { timeout: 20000 });
      await expect(page.locator('body')).toContainText(/事件轨迹|漏斗|总计|热表|渠道/i);

      // 5) 同库上报带 wch 的 page_view
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || runtime.target_origin || '').replace(/\/$/, '');
      expect(origin, 'E2E runtime target_origin').toBeTruthy();
      const landingUrl = `${origin}/?wch=${encodeURIComponent(code)}&utm_source=weline_e2e&utm_medium=cpc&utm_campaign=${encodeURIComponent(code)}`;
      const seeded = seedPixelEvent(code, landingUrl, sessionId);
      expect(
        seeded.track?.data?.buffered === false || seeded.track?.data?.pixel_id,
        JSON.stringify(seeded)
      ).toBeTruthy();

      // 6) 侧栏 → 热表明细，再按 channel_code 筛选
      await openPixelSidebarMenu(page, PIXEL_MENU.list, {
        urlIncludes: '/visitor/backend/pixel-dashboard/list',
      });
      await expect(page.locator('#pixel-list-filter-form')).toBeVisible({ timeout: 15000 });
      await page.locator('#list-filter-channel').fill(code);
      const range = page.locator('#pixel-list-filter-form select[name="range"], select[name="range"]').first();
      if ((await range.count()) > 0) {
        await range.selectOption('7d').catch(() => {});
      }
      const websiteValue = page.locator('#list-filter-website_value, input[name="websiteId"]').first();
      if ((await websiteValue.count()) > 0) {
        await websiteValue.evaluate((el) => {
          el.value = '0';
          el.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      await Promise.all([
        page.waitForURL(/pixel-dashboard\/list/, { timeout: 60000, waitUntil: 'domcontentloaded' }),
        page.locator('#pixel-list-filter-form button[type="submit"], #pixel-list-filter-form button:has-text("应用")').first().click(),
      ]);
      await waitForBackendShellReady(page);
      await expect(page.locator('#list-filter-channel')).toHaveValue(code);
      await expect(page.locator('.weline-pixel-dashboard-list table, table').first()).toBeVisible({
        timeout: 15000,
      });
      await expect
        .poll(async () => (await page.locator('body').innerText()).includes(code), {
          timeout: 30000,
          intervals: [500, 1000, 2000, 3000],
        })
        .toBe(true);
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-CHANNEL-FLOW-002' },
    '菜单进入冷归档明细：展示 G09 约束，且站点必填控件存在',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await openPixelSidebarMenu(page, PIXEL_MENU.archiveList, {
        urlIncludes: '/visitor/backend/pixel-dashboard/archive-list',
      });

      await expect(page.locator('.weline-pixel-dashboard-archive-list')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('body')).toContainText(/冷归档|最多|天|分页|website_id|站点/i);
      await expect(page.locator('#pixel-archive-list-filter-form')).toBeVisible();
      await expect(page.locator('#archive-filter-website_wrapper')).toBeVisible();
      await expect(page.locator('#archive-filter-website_value')).toBeAttached();
      await expect(page.locator('#archive-filter-website_value')).toHaveAttribute('name', 'websiteId');
      await expect(page.locator('body')).toContainText(/站点（必填）|请选择站点/);
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
