// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'WeShop_Product';
const FATAL_PATTERN =
  /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

/**
 * @param {import('@playwright/test').Page} page
 * @param {string[]} routeCandidates
 */
async function gotoFirstNonFatal(page, routeCandidates) {
  let lastBodyText = '';
  let lastError = null;

  for (const route of routeCandidates) {
    try {
      await gotoBackend(page, route, {
        timeout: 10000,
        settleMs: 800,
      });
    } catch (error) {
      lastError = error;
      const message = String(error && error.message ? error.message : error);
      if (FATAL_PATTERN.test(message)) {
        throw error;
      }
      continue;
    }

    const bodyText = await page.locator('body').innerText().catch(() => '');
    lastBodyText = bodyText;
    if (!FATAL_PATTERN.test(bodyText)) {
      return;
    }
  }

  throw new Error(
    `WeShop_Product backend smoke failed without a non-fatal route. ` +
      `Tried routes: ${JSON.stringify(routeCandidates)}. ` +
      `Last error: ${lastError ? String(lastError && lastError.message ? lastError.message : lastError) : 'none'}. ` +
      `Last body (trim): ${String(lastBodyText).slice(0, 500)}`
  );
}

test.describe('WeShop_Product backend smoke', () => {
  test.describe.configure({ retries: 0 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders at least one backend route without fatal runtime errors', async ({ page }) => {
    test.setTimeout(45000);
    const moduleRoot = buildModuleBackendRoute(MODULE);
    const routesTried = [
      moduleRoot,
      `${moduleRoot}/index`,
      buildModuleBackendRoute(MODULE, 'product'),
      buildModuleBackendRoute(MODULE, 'category'),
      'product/backend/product',
      'product/backend/category',
    ];

    await gotoFirstNonFatal(page, routesTried);

    const body = page.locator('body');
    const bodyText = await body.innerText().catch(() => '');
    await expect(body).toBeVisible();
    expect(bodyText).not.toMatch(FATAL_PATTERN);
    expect(page.url()).not.toContain('/admin/login');
  });
});
