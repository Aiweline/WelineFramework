// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop customer public auth routes', () => {
  test('public login, register, forgot-password, and challenge routes stay reachable', async ({ page }) => {
    await gotoFrontend(page, '/customer/account/login', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/login/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/customer/account/register', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/register/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/customer/account/forgot-password', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/forgot-password/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/customer/account/challenge?challenge_token=invalid', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    expect(/customer\/account\/login|customer\/account\/challenge/i.test(page.url())).toBeTruthy();
  });
});
