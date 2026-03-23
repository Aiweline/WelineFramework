// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

test.describe('Terraform batch bind page', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders the batch bind form', async ({ page }) => {
    await gotoBackend(page, 'terraform/backend/domain');
    await page.waitForTimeout(1500);

    await expect(page.locator('#terraformBatchForm')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('#cdn_provider_trigger')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('#cdn_account_trigger')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('textarea[name="domains_text"]')).toBeVisible({ timeout: 5000 });
  });
});
