// @weline-e2e-runtime fallback
// @ts-check
const fs = require('node:fs');
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const WESHOP_CMS_MODULE = 'WeShop_Cms';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

test.describe('WeShop_Cms backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }, testInfo) => {
    try {
      await loginAsAdmin(page);
    } catch (error) {
      const loginFailureScreenshot = testInfo.outputPath('beforeEach-login-failed.png');
      const loginFailureHtml = testInfo.outputPath('beforeEach-login-failed.html');
      await page.screenshot({ path: loginFailureScreenshot, fullPage: true }).catch(() => {});
      await page.content()
        .then(html => fs.writeFileSync(loginFailureHtml, html, 'utf8'))
        .catch(() => {});

      await testInfo.attach('beforeEach-login-failed-screenshot', {
        path: loginFailureScreenshot,
        contentType: 'image/png',
      }).catch(() => {});
      await testInfo.attach('beforeEach-login-failed-html', {
        path: loginFailureHtml,
        contentType: 'text/html',
      }).catch(() => {});

      throw error;
    }
  });

  test('TC-01: renders cms page list without PHP fatal errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_CMS_MODULE, 'page');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('TC-02: renders cms page create form without PHP fatal errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_CMS_MODULE, 'page', 'create');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
