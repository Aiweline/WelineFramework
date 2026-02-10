// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Terraform 批量绑定页面', () => {
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

    test('页面应显示批量绑定表单', async ({ page }) => {
        await page.goto('http://127.0.0.1:9981/terraform/backend/domain');
        await page.waitForTimeout(1500);

        const form = page.locator('#terraformBatchForm');
        await expect(form).toBeVisible({ timeout: 5000 });

        const provider = page.locator('#cdn_provider_trigger');
        await expect(provider).toBeVisible({ timeout: 5000 });

        const account = page.locator('#cdn_account_trigger');
        await expect(account).toBeVisible({ timeout: 5000 });

        const textarea = page.locator('textarea[name="domains_text"]');
        await expect(textarea).toBeVisible({ timeout: 5000 });
    });
});
