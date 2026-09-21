/**
 * 后台个人中心分栏 — 计划收口套件（完整功能通路）
 *
 * @weline-e2e-spec { module: Weline_Backend, type: plan-suite, layer: backend, feature: backend-personal-center }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport proxy
 */
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const artifactDir = 'tests/e2e/artifacts/backend-personal-center-plan-suite';
fs.mkdirSync(artifactDir, { recursive: true });

test.describe('计划链路/功能链路 e2e组: 后台个人中心分栏', () => {
  test('完整功能通路：登录 → 资料/头像/语言分栏切换', async ({ page }) => {
    await page.context().clearCookies();
    await loginAsAdmin(page, { timeout: 90000, useProxy: true, allowPasswordFallback: true });

    await gotoBackend(page, 'system/backend/profile', { timeout: 60000, settleMs: 800, useProxy: true });
    await expect(page.locator('.w-backend-account__nav-link.is-active')).toContainText(/基本资料|Profile/i);
    await expect(page.locator('input[name="username"]')).toBeVisible({ timeout: 15000 });

    await gotoBackend(page, 'system/backend/profile/avatar', { timeout: 60000, settleMs: 800, useProxy: true });
    await page.screenshot({ path: `${artifactDir}/plan-suite-avatar.png`, fullPage: true });
    await expect(page.locator('#profile-avatar')).toHaveCount(1);
    await expect(page.locator('#backend-profile-avatar-preview-wrap')).toHaveCount(0);
    await expect(page.locator('.w-file-picker, [data-w-component="file-picker"]').first()).toBeVisible({ timeout: 15000 });

    await gotoBackend(page, 'system/backend/profile/language', { timeout: 60000, settleMs: 800, useProxy: true });
    await expect(
      page.locator('#personal-language, select[name="personal_language"], [name="personal_language"]').first()
    ).toBeVisible({ timeout: 15000 });
  });
});
