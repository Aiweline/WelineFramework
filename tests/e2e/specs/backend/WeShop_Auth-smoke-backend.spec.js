// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('WeShop_Auth backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('TC-01: renders backend two-factor page without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);

    const route = buildModuleBackendRoute('WeShop_Auth', 'security', 'two-factor');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const content = page.locator('.container-fluid').first();
    await expect(content).toBeVisible({ timeout: 15000 });

    const text = await body.innerText();

    // i18n: "Two-Factor Authentication" (en) / "两步验证" (zh)
    expect(text).toMatch(/Two-Factor Authentication|两步验证/i);

    // Enabled/disabled state differs; accept both.
    expect(text).toMatch(/Current Status|当前状态|Set Up an Authenticator App|设置身份验证器应用/i);

    // Server-side fatal errors.
    expect(text).not.toMatch(FATAL_PATTERN);

    // Frontend JS runtime errors.
    expect(errors, errors.join('\n')).toEqual([]);

    await expect(content).toHaveScreenshot('WeShop_Auth-smoke-backend-two-factor.png', {
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
    });
  });
});

