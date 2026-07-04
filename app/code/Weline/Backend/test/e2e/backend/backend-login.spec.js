// @weline-e2e-transport proxy
// @weline-e2e-runtime wls
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');
const artifactDir = 'tests/e2e/artifacts/backend-login';

fs.mkdirSync(artifactDir, { recursive: true });

async function assertHealthyBackendPage(page) {
  const response = await page.request.get(page.url(), { failOnStatusCode: false });
  expect(response.ok()).toBeTruthy();
}

test.describe('Backend login', () => {
  test('TC-01: valid credentials can authenticate to backend', async ({ page }) => {
    // Ensure starting from a clean auth state.
    await page.context().clearCookies();

    await gotoBackend(page, 'admin/login', { timeout: 60000, settleMs: 500, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/tc01-login-page.png`, fullPage: true });
    await assertHealthyBackendPage(page);
    await expect(page.locator('form[action*="/admin/login/post"]')).toBeVisible({ timeout: 15000 });

    await loginAsAdmin(page, { timeout: 90000, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/tc01-after-login.png`, fullPage: true });
    await assertHealthyBackendPage(page);

    // After login, we should not still be on the login page.
    expect(page.url()).not.toContain('/admin/login');
    await expect(page.locator('form[action*="/admin/login/post"]')).toHaveCount(0);
  });

  test('TC-02: invalid credentials show error on login page', async ({ page }) => {
    await page.context().clearCookies();

    await gotoBackend(page, 'admin/login', { timeout: 60000, settleMs: 500, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/tc02-login-page.png`, fullPage: true });
    await assertHealthyBackendPage(page);
    await expect(page.locator('form[action*="/admin/login/post"]')).toBeVisible({ timeout: 15000 });

    // If the system enables captcha due to repeated attempts, this UI test would be flaky.
    const captchaVisible = await page
      .locator('input[name="code"]')
      .first()
      .isVisible({ timeout: 1500 })
      .catch(() => false);
    if (captchaVisible) {
      test.skip(true, 'Backend verification code is enabled; skip invalid-login UI test.');
      return;
    }

    const username = process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin';
    const wrongPassword = `wrong_pass_${Date.now()}`;

    await page.locator('#username, input[name="username"], input[type="text"]').first().fill(username);
    await page
      .locator('#userpassword, input[name="password"], input[type="password"]')
      .first()
      .fill(wrongPassword);

    await page.click('button[type="submit"], input[type="submit"]');
    await Promise.race([
      page.waitForURL(url => url.pathname.includes('/admin/login'), { timeout: 20000, waitUntil: 'commit' }),
      page.locator('.weline-admin-login-messages').first().waitFor({ state: 'visible', timeout: 20000 }),
    ]);

    // Login page should still be visible and show some flash message.
    expect(page.url()).toContain('/admin/login');

    const messages = page.locator('.weline-admin-login-messages');
    await expect(messages).toBeVisible({ timeout: 10000 });
    await page.screenshot({ path: `${artifactDir}/tc02-invalid-login-result.png`, fullPage: true });
    await assertHealthyBackendPage(page);
    const text = (await messages.innerText()).trim();
    expect(text.length).toBeGreaterThan(0);
    expect(text).toMatch(/登录|账户|验证码|凭据|错误|失败|禁用|锁定/i);
  });
});

