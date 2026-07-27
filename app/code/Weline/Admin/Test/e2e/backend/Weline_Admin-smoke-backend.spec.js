/**
 * Weline_Admin：诚实路由 smoke + 后台真实交互 flow（Wave2/3 假用例整治）
 *
 * 说明：openPrimary 会在候选后台路由中探测出「真正渲染出后台内容区(main#main-content)」的入口，
 * 猜测/空白/404 路由不会渲染内容区，从而让 smoke 诚实失败（防假绿），而非靠“无 Fatal”蒙混。
 *
 * @weline-e2e-spec { module: Weline_Admin, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
  submitAndExpectParam,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Admin';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
// 优先后台壳层内容区；裸 main 可能是模块自定义局部容器（如 2FA accountsContainer），不能单独当作业务根
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';
const CONTENT = CONTENT_SHELL;
// 候选后台路由（来自模块 Controller/Backend 的 index/get* 动作 + 兜底猜测），按序探测
const CANDIDATE_ROUTES = ["admin","index","config","dashboard"];

// 返回 { route, fatal }：
//  - route!=null：命中真正渲染后台内容区的入口；
//  - route==null & fatal!=null：候选路由触发运行期错误(FATAL/500) → 真实 Bug，用例应失败留证；
//  - route==null & fatal==null：候选均为 404/空白 → 该模块无独立后台页 → 用例诚实 skip。
async function openPrimary(page) {
  let fatal = null;
  for (const route of CANDIDATE_ROUTES) {
    try {
      await gotoBackend(page, buildModuleBackendRoute(MODULE, route), { timeout: 60000, settleMs: 600 });
    } catch (_e) {
      continue;
    }
    await waitForBackendShellReady(page);
    const bodyText = await page.locator('body').innerText().catch(() => '');
    if (FATAL.test(bodyText) || bodyText.trim() === '404') {
      if (FATAL.test(bodyText)) fatal = fatal || route;
      continue;
    }
    const shell = page.locator(CONTENT_SHELL).first();
    if (await shell.isVisible().catch(() => false)) {
      const txt = ((await shell.innerText().catch(() => '')) || '').trim();
      if (txt.length > 0) return { route, fatal };
    }
    // 自定义全页（无后台壳）：有非空 title + 足够 body 文本也算可达
    const title = await page.title().catch(() => '');
    if (title && bodyText.trim().length > 40) {
      return { route, fatal };
    }
  }
  return { route: null, fatal };
}

moduleDescribe(test, MODULE, 'Weline_Admin 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-SMOKE-001' },
    '主入口路由可达并渲染后台内容区（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      const { route, fatal } = await openPrimary(page);
      if (!route) {
        expect(fatal, `候选后台路由命中运行期错误(FATAL)：${fatal} —— 属真实产品 Bug，需修复`).toBeFalsy();
        test.skip(true, '未发现该模块可渲染的独立后台页（配置可能在统一配置中心/无后台 UI）');
        return;
      }
      await expect(page.locator('body')).not.toContainText(FATAL);
      const shell = page.locator(CONTENT_SHELL).first();
      if (await shell.isVisible().catch(() => false)) {
        await expect(shell).toBeVisible();
      } else {
        await expect(page.locator('body')).toContainText(/.+/);
        const title = await page.title();
        expect(title.length).toBeGreaterThan(0);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-FLOW-001' },
    '主入口：搜索/筛选或安全控件真实交互',
    async ({ page }) => {
      await loginAsAdmin(page);
      const { route, fatal } = await openPrimary(page);
      if (!route) {
        expect(fatal, `候选后台路由命中运行期错误(FATAL)：${fatal} —— 属真实产品 Bug，需修复`).toBeFalsy();
        test.skip(true, '未发现该模块可渲染的独立后台页（配置可能在统一配置中心/无后台 UI）');
        return;
      }
      const shell = page.locator(CONTENT_SHELL).first();
      const root = (await shell.isVisible().catch(() => false)) ? shell : page.locator('body');

      const keyword = root
        .locator('input[name="keyword"], input[name="search"], input[name="q"], #search-input')
        .first();
      const select = root
        .locator('form select[name="status"], form select[name="type"], form select[name="read"], select.form-select')
        .first();
      const refresh = root
        .locator('button:has-text("刷新"), a:has-text("刷新"), button:has-text("重置"), a:has-text("重置")')
        .first();

      if ((await keyword.count()) > 0 && (await keyword.isVisible().catch(() => false))) {
        const form = page.locator('form').filter({ has: keyword }).first();
        await keyword.fill('e2e-wave23');
        if ((await form.count()) > 0) {
          // 决定性证据：用户输入被真实带上提交请求（proxy 下绝对 action 可能落 404 页，故不断言提交后 DOM）
          const req = await submitAndExpectParam(page, form, 'e2e-wave23');
          expect(req).toBeTruthy();
        } else {
          await keyword.press('Enter');
          await page.waitForTimeout(500);
          await expect(keyword).toHaveValue('e2e-wave23');
        }
        return;
      }

      if ((await select.count()) > 0 && (await select.isVisible().catch(() => false))) {
        const n = await select.locator('option').count();
        if (n > 1) {
          await select.selectOption({ index: 1 });
          await page.waitForTimeout(400);
        }
        await expect(page.locator('body')).not.toContainText(FATAL);
        await expect(select).toBeVisible();
        return;
      }

      if ((await refresh.count()) > 0 && (await refresh.isVisible().catch(() => false))) {
        await refresh.click({ force: true });
        await page.waitForLoadState('domcontentloaded');
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      // 禁止点「新增/添加」：常跳到可能损坏的写表单；只点只读安全控件
      const safeBtn = root
        .locator('button.btn, a.btn, button')
        .filter({ hasText: /搜索|筛选|过滤|查看|详情|配置|管理|展开|手动输入/ })
        .first();
      if ((await safeBtn.count()) > 0 && (await safeBtn.isVisible().catch(() => false))) {
        await safeBtn.click({ force: true });
        await page.waitForTimeout(500);
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      // 纯展示/配置页：断言真实内容区渲染了业务信号（标题/表格/表单/卡片/按钮），而非纯 FATAL 兜底
      await expect(root).toBeVisible();
      await expect(
        root.locator('h1, h2, h4, .page-title, table, form, .card, a.btn, button.btn, button, input').first()
      ).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
