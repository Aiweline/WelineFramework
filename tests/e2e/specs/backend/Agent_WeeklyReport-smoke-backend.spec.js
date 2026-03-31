// @weline-e2e-runtime auto
// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'Agent_WeeklyReport';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const UPSTREAM_FAILURE_PATTERN = /upstream_request_failed|Client network socket disconnected before secure TLS connection was established/i;
const SCREENSHOT_DIR = path.resolve(__dirname, '../../artifacts/backend/Agent_WeeklyReport');

/** 仅收集未捕获的页面异常；忽略 console.error（后台静态资源/第三方脚本易产生噪声，与 WeShop_Search 等 smoke 一致）。 */
function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function ensureScreenshotDir() {
  await fs.promises.mkdir(SCREENSHOT_DIR, { recursive: true });
}

async function captureCaseScreenshot(page, name) {
  await ensureScreenshotDir();
  const safeName = String(name).replace(/[^\w.-]+/g, '-');
  const screenshotPath = path.join(SCREENSHOT_DIR, `${safeName}.png`);
  await page.screenshot({ path: screenshotPath, fullPage: true });
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

  throw new Error(`Failed to open backend route "${route}" after ${maxAttempts} attempts due to upstream transport errors.`);
}

test.describe('Agent_WeeklyReport backend smoke', () => {
  test.describe.configure({ retries: 1, timeout: 120000 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  test('TC-01: renders weekly report backend page without fatal errors', async ({ page }) => {
    const runtimeErrors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'report');

    const text = await gotoBackendStable(page, route);

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(page).not.toHaveURL(/\/admin\/login/i);
    expect(text.trim().length).toBeGreaterThan(0);

    expect(text).not.toMatch(FATAL_PATTERN);
    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
    await captureCaseScreenshot(page, 'TC-01-weekly-report');
  });

  test('TC-02: renders weekly report page with query params without fatal errors', async ({ page }) => {
    const runtimeErrors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'report', 'index');

    const text = await gotoBackendStable(page, `${route}?week=1&year=2026`);

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(page).not.toHaveURL(/\/admin\/login/i);
    expect(text.trim().length).toBeGreaterThan(0);

    expect(text).not.toMatch(FATAL_PATTERN);
    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
    await captureCaseScreenshot(page, 'TC-02-weekly-report-query');
  });
});
