/**
 * Campaign form shows website-TZ hint; start/end fields present.
 * Uses UI login against the live WLS host (avoids broken FPM session bootstrap).
 *
 * @weline-e2e-spec { module: Weline_Marketing, type: flow, layer: backend }
 */
const { test, expect } = require('@playwright/test');

const PREFIX = process.env.PLAYWRIGHT_BACKEND_PREFIX || 'jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH';
const ORIGIN = process.env.PLAYWRIGHT_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const USER = process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin';
const PASS = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

test.describe('Marketing schedule UTC window @backend', () => {
  test('campaign add form exposes timezone-aware window fields', async ({ page }) => {
    test.setTimeout(120000);
    await page.goto(`${ORIGIN}/${PREFIX}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const user = page.locator('input[name="username"], input[name="login"], input[type="text"]').first();
    const pass = page.locator('input[name="password"], input[type="password"]').first();
    if (await user.isVisible().catch(() => false)) {
      await user.fill(USER);
      await pass.fill(PASS);
      await Promise.all([
        page.waitForURL((url) => !String(url).includes('/admin/login'), { timeout: 60000 }).catch(() => null),
        page.locator('button[type="submit"], input[type="submit"]').first().click(),
      ]);
    }

    await page.goto(`${ORIGIN}/${PREFIX}/marketing/backend/campaign/add`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });
    const bodyText = await page.locator('body').innerText().catch(() => '');
    expect(FATAL.test(bodyText)).toBeFalsy();

    const start = page.locator('[data-testid="marketing-campaign-start"]');
    const end = page.locator('[data-testid="marketing-campaign-end"]');
    await expect(start).toBeVisible({ timeout: 20000 });
    await expect(end).toBeVisible({ timeout: 20000 });
    await expect(page.locator('body')).toContainText(/按站点时区|UTC/i);
  });
});
