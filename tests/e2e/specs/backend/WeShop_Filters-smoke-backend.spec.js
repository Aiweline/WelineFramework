// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const WESHOP_FILTERS_MODULE = 'WeShop_Filters';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

test.describe('WeShop_Filters backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  /**
   * @param {import('@playwright/test').Page} page
   * @param {string[]} routeCandidates
   * @returns {Promise<string>}
   */
  async function gotoFirstHealthyRoute(page, routeCandidates) {
    let lastBody = '';
    let lastError = null;

    for (const route of routeCandidates) {
      try {
        await gotoBackend(page, route, {
          timeout: 90000,
          settleMs: 1200,
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
      lastBody = bodyText;
      if (!FATAL_PATTERN.test(bodyText)) {
        return route;
      }
    }

    throw new Error(
      `WeShop_Filters backend smoke failed. Tried routes: ${JSON.stringify(routeCandidates)}. ` +
      `Last error: ${lastError ? String(lastError && lastError.message ? lastError.message : lastError) : 'none'}. ` +
      `Last body: ${String(lastBody).slice(0, 600)}`
    );
  }

  test('renders filter config pages without PHP fatal errors', async ({ page }) => {
    const moduleRoute = buildModuleBackendRoute(WESHOP_FILTERS_MODULE, 'config');
    const routeCandidates = [
      moduleRoute,
      `${moduleRoute}/index`,
      'weshop/filters/backend/config',
      'weshop/filters/backend/config/index',
      'filters/backend/config',
      'filters/backend/config/index',
      'filters/backend/config/priceRanges',
    ];

    await gotoFirstHealthyRoute(page, routeCandidates);

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
    await expect(page).not.toHaveURL(/\/admin\/login/);

    await expect(body).toHaveScreenshot('WeShop_Filters-smoke-backend-config.png', {
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
    });

    await gotoBackend(page, `${moduleRoute}/priceRanges`, {
      timeout: 90000,
      settleMs: 1200,
    });
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
    await expect(page).not.toHaveURL(/\/admin\/login/);
    await expect(body).toHaveScreenshot('WeShop_Filters-smoke-backend-price-ranges.png', {
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
    });
  });
});
