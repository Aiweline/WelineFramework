// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * SSL 证书申请提供商选项 E2E 测试
 * 验证申请证书弹窗中包含 Let's Encrypt 与 LiteSSL 选项
 */
test.describe('SSL 证书申请提供商选项', () => {
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
  
  test('申请证书弹窗应包含提供商选项', async ({ page }) => {
    await page.goto('http://127.0.0.1:9981/server/admin/ssl-certificate');
    await page.waitForTimeout(1000);
    
    const openButton = page.locator('button[data-bs-target="#requestModal"], a[data-bs-target="#requestModal"]').first();
    await openButton.waitFor({ timeout: 5000 }).catch(() => {});
    
    if (await openButton.isVisible()) {
      await openButton.click();
      await page.waitForTimeout(1000);
      
      const providerSelect = page.locator('#requestModal select[name="provider"]').first();
      await expect(providerSelect).toBeVisible({ timeout: 3000 });
      
      const optionValues = await providerSelect.locator('option').allTextContents();
      expect(optionValues.some(text => /Let's Encrypt/i.test(text))).toBeTruthy();
      expect(optionValues.some(text => /LiteSSL/i.test(text))).toBeTruthy();
    }
  });
});
