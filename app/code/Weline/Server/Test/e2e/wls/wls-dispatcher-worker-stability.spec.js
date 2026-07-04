/**
 * WLS：Dispatcher/Worker 稳定性高覆盖回归
 *
 * 覆盖目标：
 * 1) 后台登录与管理页可达（鉴权 + Worker 响应）
 * 2) 前台并发短请求稳定返回（首包/握手链路）
 * 3) 后台并发请求在已登录会话下稳定返回
 * 4) 页面不出现致命错误关键字
 *
 * 运行方式（建议）：
 * - 设置 WLS_STABILITY_E2E=1 后执行该文件
 * - 建议指定 PLAYWRIGHT_INSTANCE_NAME 对齐目标实例
 *
 * @weline-e2e-spec { module: Weline_Server, type: integration, layer: wls, case: WLS-STABILITY-001 }
 */

const { test, expect } = require('playwright/test');
const {
  gotoFrontend,
  gotoBackend,
  loginAsAdmin,
  getRuntimeInfo,
  buildModuleBackendRoute,
} = require('../../../../../../../tests/e2e/framework/runtime');

const RUN = process.env.WLS_STABILITY_E2E === '1';
const DIRECT_WLS = { useProxy: false };
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

async function burstFetch(page, urlPath, count, timeoutMs) {
  return page.evaluate(
    async ({ path, n, timeout }) => {
      const tasks = Array.from({ length: n }).map(async (_, i) => {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeout);
        try {
          const sep = path.includes('?') ? '&' : '?';
          const r = await fetch(`${path}${sep}__wlsProbe=${i + 1}`, {
            credentials: 'same-origin',
            signal: ctrl.signal,
          });
          return { ok: r.status === 200, status: r.status };
        } catch {
          return { ok: false, status: 0 };
        } finally {
          clearTimeout(timer);
        }
      });
      return Promise.all(tasks);
    },
    { path: urlPath, n: count, timeout: timeoutMs }
  );
}

function assertSuccessRatio(results, minRatio, label) {
  const total = results.length;
  const ok = results.filter(item => item.ok).length;
  const ratio = total > 0 ? ok / total : 0;
  expect(ratio, `${label} success ratio ${ok}/${total}`).toBeGreaterThanOrEqual(minRatio);
}

test.describe('WLS Dispatcher/Worker 稳定性', () => {
  test.describe.configure({ timeout: 240000, retries: process.env.CI ? 2 : 1 });

  test.beforeAll(() => {
    if (!RUN) {
      return;
    }
    const info = getRuntimeInfo({ refresh: true });
    const origin = info?.runtime?.target_origin ?? '(unknown)';
    const reachable = Boolean(info?.runtime?.reachable);
    test.skip(
      !reachable,
      `WLS E2E 目标不可达: ${origin}。请设置 PLAYWRIGHT_INSTANCE_NAME 或 PLAYWRIGHT_TARGET_ORIGIN。`
    );
  });

  test('高覆盖稳定性回归（登录 + 前后台并发 + 致命错误扫描）', async ({ browser }) => {
    test.skip(!RUN, '设置 WLS_STABILITY_E2E=1 后才执行本套件');

    const context = await browser.newContext();
    const backendPage = await context.newPage();
    const frontendPage = await context.newPage();

    try {
      await loginAsAdmin(backendPage, { timeout: 90000, ...DIRECT_WLS });

      const dashboardRoute = buildModuleBackendRoute('Weline_Backend', 'dashboard');
      await gotoBackend(backendPage, dashboardRoute, { timeout: 60000, settleMs: 500, ...DIRECT_WLS });
      await expect(backendPage.locator('body')).toBeVisible();
      await expect(backendPage.locator('body')).not.toContainText(FATAL_PATTERN);

      await gotoFrontend(frontendPage, '/', { timeout: 60000, settleMs: 500, ...DIRECT_WLS });
      await expect(frontendPage.locator('body')).toBeVisible();
      await expect(frontendPage.locator('body')).not.toContainText(FATAL_PATTERN);

      // 前台并发短请求：重点覆盖 Dispatcher -> Worker 首包稳定性
      const frontendBurst = await burstFetch(frontendPage, '/', 40, 10000);
      assertSuccessRatio(frontendBurst, 0.95, 'frontend burst');

      // 后台并发短请求：覆盖鉴权 session + 后台路由稳定性
      const backendPath = new URL(backendPage.url()).pathname;
      const backendBurst = await burstFetch(backendPage, backendPath, 25, 12000);
      assertSuccessRatio(backendBurst, 0.95, 'backend burst');

      // 额外页面巡检，确保不出现显式致命错误
      await gotoBackend(
        backendPage,
        buildModuleBackendRoute('Weline_Server', 'server-manager'),
        { timeout: 60000, settleMs: 400, ...DIRECT_WLS }
      );
      await expect(backendPage.locator('body')).not.toContainText(FATAL_PATTERN);
    } finally {
      await context.close();
    }
  });
});

