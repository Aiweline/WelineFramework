// @weline-e2e-runtime wls
// @ts-check
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

test.describe('Theme editor preview behavior', () => {
  test('widget library opens instantly then fills visible card previews', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await loginAsAdmin(page, { timeout: 60000, settleMs: 1000, allowPasswordFallback: true });
    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
      settleMs: 1000,
    });
    await expect(page.locator('#previewFrame')).toBeAttached({ timeout: 60000 });
    await expect(page.locator('#widgetList .widget-item').first()).toBeVisible({ timeout: 60000 });

    // List must appear without blocking on every preview. Visible cards then settle.
    await page.waitForFunction(() => {
      return Array.from(document.querySelectorAll('#widgetList .widget-preview-canvas'))
        .some((canvas) => canvas.dataset.previewLoaded === '1'
          && !canvas.querySelector('.widget-preview-placeholder'));
    }, null, { timeout: 90000 });
  });
});
