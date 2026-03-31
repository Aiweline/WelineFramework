// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'WeShop_GoogleAuth';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('WeShop_GoogleAuth smoke backend', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders Google binding page without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const routesTried = [
      buildModuleBackendRoute(MODULE, 'auth', 'binding'),
      buildModuleBackendRoute(MODULE, 'auth', 'binding', 'index'),
      'weshop_googleauth/backend/auth/binding',
      'weshop_googleauth/backend/auth/binding/index',
    ];
    let visited = false;
    let lastError = null;

    for (const route of routesTried) {
      try {
        await gotoBackend(page, route, {
          timeout: 60000,
          settleMs: 1200,
        });
        visited = true;
        break;
      } catch (error) {
        lastError = error;
      }
    }

    expect(visited, String(lastError || '')).toBe(true);

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Google Account Security|Google Binding|Backend Google Sign-In|Policy/i);

    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
