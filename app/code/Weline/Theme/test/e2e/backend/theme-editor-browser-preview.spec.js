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
  test('widget library loads without per-widget preview requests', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');
    const previewRequests = [];

    page.on('request', (request) => {
      if (request.url().includes('widget-preview')) {
        previewRequests.push(request.url());
      }
    });

    await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
      settleMs: 1000,
    });
    await expect(page.locator('#previewFrame')).toBeAttached({ timeout: 60000 });
    expect(previewRequests).toEqual([]);
  });
});
