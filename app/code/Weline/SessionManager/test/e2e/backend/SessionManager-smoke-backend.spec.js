/**
 * Weline_SessionManager：诚实 smoke + 会话相关页真实交互
 * （模块本身可能无独立后台页，候选含 Backend access-log / Server session）
 *
 * @weline-e2e-spec { module: Weline_SessionManager, type: flow, layer: backend }
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

const MODULE = 'Weline_SessionManager';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';
const CANDIDATE_ABSOLUTE = [
  () => buildModuleBackendRoute(MODULE, 'session'),
  () => buildModuleBackendRoute(MODULE, 'index'),
  () => buildModuleBackendRoute('Weline_Backend', 'access-log'),
  () => buildModuleBackendRoute('Weline_Server', 'session'),
  () => buildModuleBackendRoute('Weline_Server', 'monitor'),
];

async function openPrimary(page) {
  let fatal = null;
  for (const build of CANDIDATE_ABSOLUTE) {
    let route;
    try {
      route = build();
      await gotoBackend(page, route, { timeout: 60000, settleMs: 600 });
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
    const title = await page.title().catch(() => '');
    if (title && bodyText.trim().length > 40) return { route, fatal };
  }
  return { route: null, fatal };
}

moduleDescribe(test, MODULE, 'Weline_SessionManager 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SESSION-SMOKE-001' },
    '会话相关后台路由可达并渲染内容区（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      const { route, fatal } = await openPrimary(page);
      if (!route) {
        expect(fatal, `候选后台路由命中运行期错误(FATAL)：${fatal}`).toBeFalsy();
        test.skip(true, '未发现会话相关可渲染后台页');
        return;
      }
      await expect(page.locator('body')).not.toContainText(FATAL);
      const shell = page.locator(CONTENT_SHELL).first();
      if (await shell.isVisible().catch(() => false)) {
        await expect(shell).toBeVisible();
      } else {
        const title = await page.title();
        expect(title.length).toBeGreaterThan(0);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SESSION-FLOW-001' },
    '会话相关页：搜索/筛选或安全控件真实交互',
    async ({ page }) => {
      await loginAsAdmin(page);
      const { route, fatal } = await openPrimary(page);
      if (!route) {
        expect(fatal, `候选后台路由命中运行期错误(FATAL)：${fatal}`).toBeFalsy();
        test.skip(true, '未发现会话相关可渲染后台页');
        return;
      }
      const shell = page.locator(CONTENT_SHELL).first();
      const root = (await shell.isVisible().catch(() => false)) ? shell : page.locator('body');

      const keyword = root
        .locator('input[name="keyword"], input[name="search"], input[name="q"], #search-input')
        .first();
      if ((await keyword.count()) > 0 && (await keyword.isVisible().catch(() => false))) {
        const form = page.locator('form').filter({ has: keyword }).first();
        await keyword.fill('e2e-session');
        if ((await form.count()) > 0) {
          const req = await submitAndExpectParam(page, form, 'e2e-session');
          expect(req).toBeTruthy();
        } else {
          await keyword.press('Enter');
          await expect(keyword).toHaveValue('e2e-session');
        }
        return;
      }

      const select = root.locator('form select, select.form-select').first();
      if ((await select.count()) > 0 && (await select.isVisible().catch(() => false))) {
        const n = await select.locator('option').count();
        if (n > 1) await select.selectOption({ index: 1 });
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      const safeBtn = root
        .locator('button.btn, a.btn, button')
        .filter({ hasText: /搜索|筛选|过滤|查看|详情|配置|管理|展开|刷新/ })
        .first();
      if ((await safeBtn.count()) > 0 && (await safeBtn.isVisible().catch(() => false))) {
        await safeBtn.click({ force: true });
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      await expect(root).toBeVisible();
      await expect(
        root.locator('h1, h2, h4, .page-title, table, form, .card, a.btn, button.btn, button, input').first()
      ).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
