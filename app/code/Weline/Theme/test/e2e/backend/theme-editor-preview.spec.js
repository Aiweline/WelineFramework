// @weline-e2e-runtime wls
// @ts-check
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

test.describe('Theme editor iframe preview integration', () => {
  test.setTimeout(120000);

  test('layout preview iframe loads themed assets with explicit preview context and no 404s', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    const failedThemeResponses = [];
    page.on('response', (response) => {
      const url = response.url();
      if ((url.includes('/view/theme/') || url.includes('/layouts/')) && response.status() >= 400) {
        failedThemeResponses.push({
          url,
          status: response.status(),
        });
      }
    });

    await loginAsAdmin(page, {
      timeout: 60000,
      settleMs: 1000,
    });

    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
      settleMs: 2000,
    });

    const previewFrame = page.locator('#previewFrame');
    await expect(previewFrame).toHaveAttribute('src', /theme-preview\/content|layout-preview/);
    await expect(previewFrame).toHaveAttribute('src', /editor_mode=1/);
    await expect(previewFrame).toHaveAttribute('src', /preview_area=frontend/);

    const frame = page.frameLocator('#previewFrame');
    await frame.locator('html').first().waitFor({
      state: 'attached',
      timeout: 60000,
    });
    await expect(frame.locator('body')).toContainText(/WeShop|Ride|Category|FREE SHIPPING/i, { timeout: 60000 });
    await expect(frame.locator('.page-sidebar,.left-side-menu,.sidebar-menu,.vertical-menu,.navbar-menu')).toHaveCount(0);
    expect(failedThemeResponses).toEqual([]);
  });

  test('editor iframe homepage preview keeps selected theme layout and no orphan warnings (regression)', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await loginAsAdmin(page, {
      timeout: 60000,
      settleMs: 1000,
    });

    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}&page_type=homepage`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
      settleMs: 2000,
    });

    const frame = page.frameLocator('#previewFrame');
    await frame.locator('html').first().waitFor({
      state: 'attached',
      timeout: 60000,
    });

    await expect(frame.locator('[data-wslot="homepage-hero"]')).toHaveCount(1);
    await expect(frame.locator('#motor-welcome-modal')).toHaveCount(1);
    await expect(frame.locator('#orphan-widgets-warning')).toHaveCount(0);

    const themeAssets = await frame.locator('link[href], script[src]').evaluateAll((nodes) => nodes
      .map((node) => node.getAttribute('href') || node.getAttribute('src') || '')
      .filter((url) => url.includes('/view/theme/') || url.includes('/layouts/')));

    expect(themeAssets.length).toBeGreaterThan(0);
    expect(themeAssets.some((url) => url.includes(`frontend_theme_id=${activeTheme.id}`))).toBeTruthy();
  });
});
