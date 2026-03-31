// @weline-e2e-runtime wls
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'Agent_CursorSupervisor';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const UPSTREAM_FAILURE_PATTERN = /upstream_request_failed|Client network socket disconnected before secure TLS connection was established/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => {
    errors.push(String(error && error.message ? error.message : error));
  });
  return errors;
}

test.describe('Agent_CursorSupervisor backend smoke (GET only)', () => {
  // beforeEach loginAsAdmin(90000) + gotoBackend(60000) + settle/assert 必须共享同一 test timeout；
  // 默认 120s 会在慢登录/慢导航时于 gotoUrl 的 settle 阶段误杀（非页面内容错误）。
  test.describe.configure({ retries: 1, timeout: 200_000 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  async function gotoBackendStable(page, route, options = {}) {
    const maxAttempts = options.maxAttempts || 3;
    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      const response = await gotoBackend(page, route, {
        timeout: options.timeout || 60000,
        settleMs: options.settleMs || 1200,
      });
      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      const text = await body.innerText();
      if (!UPSTREAM_FAILURE_PATTERN.test(text)) {
        return { response, text };
      }
      if (attempt < maxAttempts) {
        await page.waitForTimeout(1000 * attempt);
      }
    }
    throw new Error(`Failed to open backend route "${route}" after ${maxAttempts} attempts due to upstream transport errors.`);
  }

  const routesTried = [
    {
      route: buildModuleBackendRoute(MODULE_NAME),
      assertStatusNot: null,
      fallbackRoute: buildModuleBackendRoute(MODULE_NAME, 'dashboard'),
      allowLoginRedirect: true,
    },
    {
      route: buildModuleBackendRoute(MODULE_NAME, 'dashboard'),
      assertStatusNot: 404,
    },
    {
      route: buildModuleBackendRoute(MODULE_NAME, 'dashboard', 'index'),
      assertStatusNot: 404,
    },
  ];

  for (const item of routesTried) {
    test(`GET ${item.route} renders without fatal runtime errors`, async ({ page }) => {
      const pageErrors = bindPageErrors(page);

      let { response, text } = await gotoBackendStable(page, item.route);
      if (item.allowLoginRedirect && /\/admin\/login/i.test(page.url()) && item.fallbackRoute) {
        const fallback = await gotoBackendStable(page, item.fallbackRoute);
        response = fallback.response ?? null;
        text = fallback.text;
      }
      const status = response?.status?.();

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      if (!item.allowLoginRedirect) {
        await expect(page).not.toHaveURL(/\/admin\/login/i);
      }
      expect(text.trim().length).toBeGreaterThan(0);
      expect(text).not.toMatch(FATAL_PATTERN);
      if (item.assertStatusNot !== null && status !== null) {
        expect(status).not.toBe(item.assertStatusNot);
      }
      expect(text).toContain('Cursor 监控面板');
      expect(text).toContain('cursor:supervisor:status');
      expect(text).toContain('CursorSupervisor 后台入口可用');
      await expect(page.locator('[data-testid="cursor-supervisor-command-status"]')).toContainText('cursor:supervisor:status');
      await expect(page.locator('[data-testid="cursor-supervisor-command-start"]')).toContainText('cursor:supervisor:start -d');
      await expect(page.locator('[data-testid="cursor-supervisor-command-stop"]')).toContainText('cursor:supervisor:stop');
      await expect(page.locator('[data-testid="cursor-supervisor-command-watchdog"]')).toContainText('cursor:supervisor:watchdog');
      expect(pageErrors, pageErrors.join('\n')).toEqual([]);
    });
  }
});

