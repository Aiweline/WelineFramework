// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Review';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('WeShop_Review backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('TC-01: renders review index page without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'review');

    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-02: renders review view fallback route without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'review', 'view');

    await gotoBackend(page, `${route}?id=0`, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
