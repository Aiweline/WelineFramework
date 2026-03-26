// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

test.describe('AI model list page', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('shows the model list and add button', async ({ page }) => {
    await gotoBackend(page, 'ai/backend/model', { timeout: 60000, settleMs: 1000 });

    await expect(page.locator('table').first()).toBeVisible({ timeout: 5000 });

    const addButton = page
      .locator('a[href*="/ai/backend/model/edit"], a:has-text("新增模型"), button:has-text("新增模型")')
      .first();
    await expect(addButton).toBeVisible({ timeout: 5000 });
  });
});
