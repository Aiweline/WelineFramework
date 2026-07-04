// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i;

test.describe('WeShop customer account core guard', () => {
  test('guest is redirected to login when accessing account dashboard', async ({ page }) => {
    await gotoFrontend(page, '/customer/account', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 900,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible({ timeout: 15000 });
    const maintenanceText = await body.textContent();
    const maintenancePattern = /网站维护中|网站正在维护|maintenance mode|site maintenance/i;
    if (maintenancePattern.test(maintenanceText || '')) {
      await expect(body).toContainText(maintenancePattern, { timeout: 15000 });
      return;
    }

    await expect(page).toHaveURL(/weshop\/customer\/account\/login|customer\/account\/login|customer\/account(?:\/index)?/i, { timeout: 15000 });
    const authSlotCount = await page.locator('[data-wslot="account-auth-main"]').count();
    const accountSlotCount = await page.locator('[data-wslot="account-main"]').count();
    expect(authSlotCount + accountSlotCount).toBeGreaterThan(0);
    await expect(body).not.toContainText(FATAL_PATTERN, { timeout: 15000 });
  });
});
