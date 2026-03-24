// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

test.describe('WeShop analytics backend', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders analytics management dashboard', async ({ page }) => {
    await gotoBackend(page, 'analytics/backend/analytics', {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Analytics Management/i);
    await expect(body).toContainText(/Google Analytics/i);
    await expect(body).toContainText(/Facebook Pixel/i);
    await expect(body).not.toContainText(/WLS Runtime Error|ParseError|syntax error|Fatal error/i);
  });
});
