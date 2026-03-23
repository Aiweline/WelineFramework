// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

test.describe('AI model list page', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('shows the model list and add button', async ({ page }) => {
    await gotoBackend(page, 'ai/backend/model');
    await page.waitForTimeout(1000);

    const title = page.locator('text=/模型列表|AI模型列表|AI模型管理/i');
    await expect(title).toBeVisible({ timeout: 5000 });

    const addButton = page.locator('a:has-text("新增模型"), button:has-text("新增模型")');
    await expect(addButton).toBeVisible({ timeout: 5000 });
  });
});
