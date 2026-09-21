// @weline-e2e-transport proxy
// @weline-e2e-runtime wls
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const artifactDir = 'tests/e2e/artifacts/backend-personal-profile';
fs.mkdirSync(artifactDir, { recursive: true });

test.describe('Backend personal center', () => {
  test('TC-01: split nav switches profile/avatar/language panels', async ({ page }) => {
    await page.context().clearCookies();
    await loginAsAdmin(page, { timeout: 90000, useProxy: true, allowPasswordFallback: true });

    await gotoBackend(page, 'system/backend/profile', { timeout: 60000, settleMs: 800, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/tc01-profile.png`, fullPage: true });
    expect(page.url()).not.toContain('/admin/login');
    await expect(page.locator('.w-backend-account__nav')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('input[name="username"]')).toBeVisible();
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('select[name="personal_language"], [name="personal_language"]')).toHaveCount(0);

    await gotoBackend(page, 'system/backend/profile/avatar', { timeout: 60000, settleMs: 800, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/tc01-avatar.png`, fullPage: true });
    await expect(page.locator('#profile-avatar')).toHaveCount(1);
    await expect(page.locator('#backend-profile-avatar-preview-wrap')).toHaveCount(0);
    await expect(
      page.locator('.w-file-picker, [data-w-component="file-picker"]').first()
    ).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-w-file-preview], .w-file-preview').first()).toBeVisible({ timeout: 15000 });

    await gotoBackend(page, 'system/backend/profile/language', { timeout: 60000, settleMs: 800, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/tc01-language.png`, fullPage: true });
    await expect(
      page.locator('#personal-language, select[name="personal_language"], [name="personal_language"]').first()
    ).toBeVisible({ timeout: 15000 });
  });
});
