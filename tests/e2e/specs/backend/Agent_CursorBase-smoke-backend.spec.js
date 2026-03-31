// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'Agent_CursorBase';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const UPSTREAM_FAILURE_PATTERN = /upstream_request_failed|Client network socket disconnected before secure TLS connection was established/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function gotoBackendStable(page, route, options = {}) {
  const maxAttempts = options.maxAttempts || 3;
  const gotoOptions = {
    timeout: options.timeout || 60000,
    settleMs: options.settleMs || 1200,
  };

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    await gotoBackend(page, route, gotoOptions);
    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    if (!UPSTREAM_FAILURE_PATTERN.test(text)) {
      return text;
    }
    if (attempt < maxAttempts) {
      await page.waitForTimeout(1000 * attempt);
    }
  }

  const fallbackRoute = options.fallbackRoute || '';
  if (fallbackRoute) {
    await gotoBackend(page, fallbackRoute, gotoOptions);
    const fallbackBody = page.locator('body');
    await expect(fallbackBody).toBeVisible();
    return await fallbackBody.innerText();
  }

  throw new Error(`Failed to open backend route "${route}" after ${maxAttempts} attempts due to upstream transport errors.`);
}

test.describe('Agent_CursorBase backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('TC-01: renders workspace page without fatal errors (workspace)', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'workspace');

    const text = await gotoBackendStable(page, route, {
      fallbackRoute: buildModuleBackendRoute(MODULE_NAME, 'workspace', 'index'),
    });
    await expect(page.locator('body')).toBeVisible();
    await expect(page).not.toHaveURL(/\/admin\/login/i);
    expect(text.trim().length).toBeGreaterThan(0);
    expect(text).toContain('Cursor 工作台');
    expect(text).toContain('CursorBase 后台入口可用');
    await expect(page.locator('[data-testid="cursor-base-monitoring-hint"]')).toContainText('cursor:supervisor:status');

    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-02: renders workspace index page without fatal errors (workspace/index)', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'workspace', 'index');

    const text = await gotoBackendStable(page, route);
    await expect(page.locator('body')).toBeVisible();
    await expect(page).not.toHaveURL(/\/admin\/login/i);
    expect(text.trim().length).toBeGreaterThan(0);
    expect(text).toContain('Cursor 工作台');
    expect(text).toContain('CursorBase 后台入口可用');
    await expect(page.locator('[data-testid="cursor-base-monitoring-hint"]')).toContainText('cursor:supervisor:status');

    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
