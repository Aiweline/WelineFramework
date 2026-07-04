// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

test.describe('SSL certificate provider options', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('shows provider options in the request modal', async ({ page }) => {
    await gotoBackend(page, 'server/backend/ssl-certificate', {
      timeout: 60000,
      settleMs: 1000,
    });

    const openButton = page.locator('button[data-bs-target*="requestModal"], a[data-bs-target*="requestModal"]').first();
    await expect(openButton).toBeVisible({ timeout: 5000 });
    await openButton.click();
    await page.waitForTimeout(1000);

    const providerSelect = page.locator('#requestModal select[name="provider"]').first();
    await expect(providerSelect).toBeVisible({ timeout: 3000 });

    const optionValues = await providerSelect.locator('option').allTextContents();
    expect(optionValues.some(text => /Let's Encrypt/i.test(text))).toBeTruthy();
    expect(optionValues.some(text => /LiteSSL/i.test(text))).toBeTruthy();
  });
});
