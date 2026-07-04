const { test, expect } = require('@playwright/test');

test.describe('Theme editor preview behavior', () => {
  test('widget library loads without per-widget preview requests', async ({ page }) => {
    const previewRequests = [];

    page.on('request', (request) => {
      const url = request.url();
      if (url.includes('widget-preview')) {
        previewRequests.push(url);
      }
    });

    await page.goto('/theme/backend/theme-editor/index?theme_id=5');
    await page.waitForLoadState('networkidle');

    expect(previewRequests.length).toBe(0);
  });
});
