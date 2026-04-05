/**
 * WLS：SSE 挂起 Fiber + 并发短请求 / 后台（omit 白名单回归）
 *
 * 使用 **后台** `Weline_Server` → `sse-test` 页（与 `Weline_Server-smoke-backend` 一致）。
 * 多站/环境下前台 `/server/sse-test/*` 可能未注册导致 404，故不依赖前台路径。
 *
 * 多实例环境须指定与 `php bin/w server:status` 一致的实例：
 *
 * ```powershell
 * cd tests/e2e
 * $env:WLS_FIBER_SSE_E2E='1'
 * $env:PLAYWRIGHT_INSTANCE_NAME='你的实例名'
 * $env:PLAYWRIGHT_TEST_FILES='["tests/e2e/specs/wls/wls-fiber-sse-concurrency.spec.js"]'
 * npx playwright test -c playwright.config.js
 * ```
 *
 * 亦可 `PLAYWRIGHT_TARGET_ORIGIN=https://主机:端口`（见 framework/runtime-info.php）。
 *
 * @weline-e2e-spec { module: Weline_Framework, type: integration, layer: wls }
 */

const { test, expect } = require('playwright/test');
const {
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
  getRuntimeInfo,
} = require('../../framework/runtime');

const RUN = process.env.WLS_FIBER_SSE_E2E === '1';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

/** 直连 runtime-info 的 target_origin，避免本地 3999 代理未就绪或转发慢导致超时 */
const DIRECT_WLS = { useProxy: false };

function sseTestBackendRoute() {
  return buildModuleBackendRoute('Weline_Server', 'sse-test');
}

test.describe('WLS Fiber：SSE 与并发 / 后台', () => {
  test.describe.configure({ timeout: 180000, retries: process.env.CI ? 2 : 1 });

  test.beforeAll(() => {
    if (!RUN) {
      return;
    }
    const info = getRuntimeInfo({ refresh: true });
    const origin = info?.runtime?.target_origin ?? '(unknown)';
    const reachable = Boolean(info?.runtime?.reachable);
    test.skip(
      !reachable,
      `WLS E2E 目标不可达: ${origin}。请设置 PLAYWRIGHT_INSTANCE_NAME（与 server:status 实例名一致）或 PLAYWRIGHT_TARGET_ORIGIN。`
    );
  });

  test('后台 SSE 页 + 另一标签页并发 fetch（不同 Page，避免同页抢连接）', async ({ browser }) => {
    test.skip(!RUN, '设置 WLS_FIBER_SSE_E2E=1 后才执行本套件');

    const context = await browser.newContext();
    const pSse = await context.newPage();
    const pFetch = await context.newPage();

    try {
      await loginAsAdmin(pSse, { timeout: 90000, ...DIRECT_WLS });
      const ssePath = sseTestBackendRoute();
      await gotoBackend(pSse, ssePath, { timeout: 60000, settleMs: 300, ...DIRECT_WLS });
      await gotoBackend(pFetch, 'admin', { timeout: 60000, settleMs: 200, ...DIRECT_WLS });

      await pSse.locator('#startBtn').click();
      await pSse.waitForTimeout(500);

      const fetchMs = 20000;
      const statuses = await pFetch.evaluate(
        async ({ timeoutMs }) => {
          const origin = window.location.origin;
          const adminPath = window.location.pathname || '/';
          const paths = [
            `${adminPath}${adminPath.includes('?') ? '&' : '?'}__wlsFiberProbe=1`,
            `${adminPath}${adminPath.includes('?') ? '&' : '?'}__wlsFiberProbe=2`,
          ];

          async function timedStatus(path) {
            const url = origin + path;
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), timeoutMs);
            try {
              const r = await fetch(url, { credentials: 'same-origin', signal: ctrl.signal });
              return r.status;
            } catch {
              return 0;
            } finally {
              clearTimeout(timer);
            }
          }

          return Promise.all(paths.map((p) => timedStatus(p)));
        },
        { timeoutMs: fetchMs }
      );

      expect(statuses.every((s) => s === 200)).toBeTruthy();

      await expect(pSse.locator('#output')).toContainText(/进度|处理第|任务开始/, { timeout: 45000 });

      await pSse.locator('#stopBtn').click();
      await pSse.waitForTimeout(200);
    } finally {
      await context.close();
    }
  });

  test('SSE 挂起期间另一标签页可进后台（同 BrowserContext）', async ({ browser }) => {
    test.skip(!RUN, '设置 WLS_FIBER_SSE_E2E=1 后才执行本套件');

    const context = await browser.newContext();
    const pSse = await context.newPage();
    const pAdmin = await context.newPage();

    try {
      await loginAsAdmin(pAdmin, { timeout: 90000, ...DIRECT_WLS });

      const ssePath = sseTestBackendRoute();
      await gotoBackend(pSse, ssePath, { timeout: 60000, settleMs: 300, ...DIRECT_WLS });
      await pSse.locator('#startBtn').click();
      await pSse.waitForTimeout(500);

      await gotoBackend(pAdmin, 'admin', { timeout: 60000, settleMs: 800, ...DIRECT_WLS });

      const body = pAdmin.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
      expect(pAdmin.url()).not.toContain('/admin/login');

      await pSse.locator('#stopBtn').click();
    } finally {
      await context.close();
    }
  });

  test('系统维护 / 监控 / Server 管理 后台页可打开其一（需管理员）', async ({ page }) => {
    test.skip(!RUN, '设置 WLS_FIBER_SSE_E2E=1 后才执行本套件');

    await loginAsAdmin(page, { timeout: 90000, ...DIRECT_WLS });

    const candidates = [
      buildModuleBackendRoute('Weline_Backend', 'maintenance'),
      buildModuleBackendRoute('Weline_Backend', 'monitor'),
      buildModuleBackendRoute('Weline_Backend', 'backup'),
      buildModuleBackendRoute('Weline_Server', 'server-manager'),
      buildModuleBackendRoute('Weline_Server', 'server-monitor'),
      buildModuleBackendRoute('Weline_Server', 'ssl-certificate'),
    ];

    const body = page.locator('body');
    let matched = false;

    for (const url of candidates) {
      try {
        await gotoBackend(page, url, { timeout: 60000, settleMs: 500, ...DIRECT_WLS });
        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        if ((page.url() || '').includes('/admin/login')) {
          continue;
        }
        const text = (await body.innerText()).trim();
        if (text.length < 20) {
          continue;
        }
        matched = true;
        break;
      } catch {
        // 下一候选路由（与 Server-smoke-backend 容错一致）
      }
    }

    expect(matched).toBeTruthy();
  });
});
