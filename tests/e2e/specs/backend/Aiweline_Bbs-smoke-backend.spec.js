// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  getModuleBackendRouter,
  getRuntimeInfo,
  loginAsAdmin,
} = require('../../framework');

/** 与 Aiweline_Community smoke 对齐：直连目标源、120s 描述级超时、主内容区断言（避免登录页无 .container-fluid 误判）。 */
const FATAL_PATTERN =
  /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

/** @type {string} */
let bbsRouter = 'aiweline_bbs';
try {
  bbsRouter = getModuleBackendRouter('Aiweline_Bbs');
} catch {
  // 模块未进 E2E runtime 时保留历史默认前缀
}

const ADMIN_CONTROLLERS = ['dashboard', 'forum', 'setting', 'thread', 'upload', 'user'];
const ROUTES = ADMIN_CONTROLLERS.map(c => `${bbsRouter}/admin/${c}`);

test.describe('Aiweline_Bbs backend smoke', () => {
  test.describe.configure({ retries: 1, timeout: 120000 });

  test.beforeAll(() => {
    getRuntimeInfo({ refresh: true });
  });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, {
      settleMs: 1200,
      timeout: 120000,
    });
  });

  for (const route of ROUTES) {
    test(`renders ${route} without PHP fatal errors`, async ({ page }, testInfo) => {
      await gotoBackend(page, route, {
        timeout: 120000,
        settleMs: 1200,
        allowLoadStateTimeout: true,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible();
      const bodyText = await body.innerText();
      expect(bodyText, `Empty body after gotoBackend(${route})`).not.toBe('');
      expect(page.url(), `Still on login after gotoBackend(${route})`).not.toContain('/admin/login');

      const main = page.locator('main.backend-main-content').first();
      await expect(main, `Missing main content after gotoBackend(${route})`).toBeVisible({ timeout: 15000 });
      await expect(main).not.toContainText(FATAL_PATTERN, { timeout: 15000 });
      await expect(main).toContainText(/BBS backend admin page|BBS 后台管理页/i);

      const safeRoute = route.replace(/[^a-z0-9-_/]/gi, '_').replace(/\//g, '__');
      await page.screenshot({
        path: testInfo.outputPath(`${safeRoute}.png`),
        fullPage: true,
      });
    });
  }
});
