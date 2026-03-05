// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('AI模型列表页面', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('http://127.0.0.1:9981/admin');
        await page.waitForSelector('input[name="username"], input[type="text"]', { timeout: 5000 }).catch(() => {});

        const usernameInput = await page.$('input[name="username"], input[type="text"]');
        if (usernameInput) {
            await page.fill('input[name="username"], input[type="text"]', 'admin');
            await page.fill('input[name="password"], input[type="password"]', 'admin');
            await page.click('button[type="submit"], input[type="submit"]');
            await page.waitForTimeout(2000);
        }
    });

    test('应该显示模型列表页面和新增模型按钮', async ({ page }) => {
        await page.goto('http://127.0.0.1:9981/ai/backend/model');
        await page.waitForTimeout(1000);

        const title = page.locator('text=/模型列表|AI模型列表|AI模型管理/i');
        await expect(title).toBeVisible({ timeout: 5000 });

        const addButton = page.locator('a:has-text("新增模型"), button:has-text("新增模型")');
        await expect(addButton).toBeVisible({ timeout: 5000 });
    });
});
