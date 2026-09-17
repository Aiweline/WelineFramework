// @weline-e2e-runtime wls
// @ts-check
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
  getRuntimeInfo,
} = require('../../../../../../../tests/e2e/framework');

/**
 * [case:theme-preview-publish-and-exit]
 * Storefront preview float → publish-and-exit must not toast
 * theme_editor_raw_context_mismatch:target_type.
 */
test.describe('Theme preview publish-and-exit', () => {
  test.setTimeout(180000);

  test('frontend preview publish-and-exit succeeds without target_type mismatch', async ({ page, context }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}&page_type=checkout`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
      settleMs: 2000,
    });

    // Prefer explicit frontend preview button when present.
    const frontendPreviewBtn = page.locator('#btnFrontendPreview, [data-action="frontend-preview"], button:has-text("前台预览")').first();
    const hasFrontendPreview = await frontendPreviewBtn.isVisible({ timeout: 8000 }).catch(() => false);

    /** @type {import('@playwright/test').Page} */
    let previewPage = page;
    if (hasFrontendPreview) {
      const popupPromise = context.waitForEvent('page', { timeout: 60000 }).catch(() => null);
      await frontendPreviewBtn.click({ timeout: 15000 });
      const popup = await popupPromise;
      if (popup) {
        previewPage = popup;
        await previewPage.waitForLoadState('domcontentloaded', { timeout: 60000 });
      } else {
        // Same-tab redirect into storefront preview.
        await page.waitForURL(/weline_preview_token|theme-preview\/gateway|checkout/, { timeout: 60000 }).catch(() => {});
        previewPage = page;
      }
    } else {
      // Fallback: call start-preview via editorRequest then open redirect.
      const runtime = getRuntimeInfo();
      const origin = String(runtime.origin || runtime.base_url || '').replace(/\/$/, '');
      test.skip(!origin, 'Missing e2e origin');

      const start = await page.evaluate(async ({ themeId }) => {
        const api = window.Weline && window.Weline.Api;
        if (!api || typeof api.resource !== 'function') {
          return { ok: false, message: 'no-api' };
        }
        const resource = await api.resource('theme');
        const result = await resource.editorRequest({
          url: '/theme/backend/theme-editor/start-preview',
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({
            theme_id: themeId,
            page_type: 'checkout',
            editor_area: 'frontend',
            preview_area: 'frontend',
          }),
        });
        return result;
      }, { themeId: activeTheme.id });

      expect(start && (start.success === true || start.data)).toBeTruthy();
      const redirect = start.redirect_url || start.data?.redirect_url || start.data?.preview_url;
      expect(redirect).toBeTruthy();
      previewPage = await context.newPage();
      await previewPage.goto(String(redirect), { waitUntil: 'domcontentloaded', timeout: 60000 });
    }

    const float = previewPage.locator('#weline-preview-exit-float');
    await expect(float).toBeVisible({ timeout: 60000 });
    const publishBtn = previewPage.locator('#weline-preview-publish-btn');
    await expect(publishBtn).toBeVisible();

    // Confirm dialog (Weline.UI.dialog.confirm or fallback overlay).
    await publishBtn.click();
    const confirmOk = previewPage.locator('button:has-text("确认发布"), .w-dialog button:has-text("确认"), [data-action="confirm"]').first();
    if (await confirmOk.isVisible({ timeout: 5000 }).catch(() => false)) {
      await confirmOk.click();
    }

    // Wait for either success navigation away from preview float, or capture error toast text.
    const mismatchToast = previewPage.getByText('theme_editor_raw_context_mismatch:target_type');
    const authToast = previewPage.getByText('不满足操作授权要求');
    const networkToast = previewPage.getByText('网络错误，请重试');

    await Promise.race([
      previewPage.waitForURL((url) => !String(url).includes('weline_preview_token'), { timeout: 90000 }).catch(() => null),
      float.waitFor({ state: 'detached', timeout: 90000 }).catch(() => null),
      mismatchToast.waitFor({ state: 'visible', timeout: 90000 }).catch(() => null),
      authToast.waitFor({ state: 'visible', timeout: 90000 }).catch(() => null),
      networkToast.waitFor({ state: 'visible', timeout: 90000 }).catch(() => null),
      previewPage.waitForTimeout(8000),
    ]);

    await expect(mismatchToast).toHaveCount(0);
    await expect(authToast).toHaveCount(0);
    // After success the float should be gone or page left preview mode.
    const stillMismatch = await mismatchToast.count();
    expect(stillMismatch).toBe(0);

    const bodyText = await previewPage.locator('body').innerText().catch(() => '');
    expect(bodyText).not.toContain('theme_editor_raw_context_mismatch:target_type');
    expect(bodyText).not.toContain('不满足操作授权要求');
  });
});
