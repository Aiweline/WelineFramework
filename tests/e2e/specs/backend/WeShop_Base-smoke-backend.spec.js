// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'WeShop_Base';

// WeShop_Base 主要提供基础能力，可能不存在专属后台页面；404 不视为致命，PHP/JS 崩溃才算失败。
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindRuntimeErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => {
    errors.push(String(error && error.message ? error.message : error));
  });
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    if (/Failed to load resource: the server responded with a status of 404/i.test(text)) {
      return;
    }
    if (/ResizeObserver loop limit exceeded/i.test(text)) {
      return;
    }
    errors.push(text);
  });
  return errors;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {Array<string>} routeCandidates
 */
async function gotoFirstNonFatal(page, routeCandidates) {
  let lastRoute = '';
  let lastBodyText = '';

  for (const route of routeCandidates) {
    lastRoute = route;
    try {
      await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
      });
      const bodyText = await page.locator('body').innerText().catch(() => '');
      lastBodyText = bodyText;
      if (!FATAL_PATTERN.test(bodyText)) {
        return route;
      }
    } catch (e) {
      lastBodyText = String(e && e.message ? e.message : e);
    }
  }

  throw new Error(
    `WeShop_Base backend smoke failed to find non-fatal route. `
    + `lastRoute="${lastRoute}". lastBodyText="${String(lastBodyText).slice(0, 500)}"`
  );
}

test.describe('WeShop_Base backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('TC-01: renders backend entry without fatal errors', async ({ page }, testInfo) => {
    const runtimeErrors = bindRuntimeErrors(page);

    const routeCandidates = [
      buildModuleBackendRoute(MODULE),
      buildModuleBackendRoute(MODULE, 'index'),
      buildModuleBackendRoute(MODULE, 'index', 'index'),
      'base/backend',
      'base/backend/index',
      'base/backend/index/index',
    ];

    await gotoFirstNonFatal(page, routeCandidates);

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(String(text).trim().length).toBeGreaterThan(0);
    const snapshotPath = testInfo.outputPath('WeShop_Base-smoke-backend.png');
    await page.screenshot({
      path: snapshotPath,
      fullPage: true,
    });
    await testInfo.attach('WeShop_Base backend snapshot', {
      path: snapshotPath,
      contentType: 'image/png',
    });
    await expect(body).not.toContainText(FATAL_PATTERN);
    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
  });
});
