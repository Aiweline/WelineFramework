// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

test.describe('Terraform batch bind page', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('renders the batch bind form', async ({ page }) => {
    await gotoBackend(page, 'terraform/backend/domain/index', {
      timeout: 60000,
      settleMs: 1500,
    });

    const form = page.locator('#terraformBatchForm');
    const providerSelect = page.locator('#cdn_provider_trigger');
    const accountSelect = page.locator('#cdn_account_trigger');
    const domainsTextarea = page.locator('textarea[name="domains_text"]');

    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(providerSelect).toBeVisible({ timeout: 10000 });
    await expect(accountSelect).toBeVisible({ timeout: 10000 });
    await expect(domainsTextarea).toBeVisible({ timeout: 10000 });
    // Backend 页面常驻轮询会导致 networkidle 无法达成，改用短暂稳定等待避免截图回归。
    await page.waitForTimeout(800);

    await expect(form).toHaveScreenshot('terraform-batch-bind-backend.png', {
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
    });
  });
});
