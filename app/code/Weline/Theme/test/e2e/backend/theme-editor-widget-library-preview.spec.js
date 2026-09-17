// @weline-e2e-runtime wls
// @ts-check
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

test.describe('Theme editor right-panel widget library preview', () => {
  test.setTimeout(120000);

  test('visible library cards show real previews (not blank placeholders)', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await loginAsAdmin(page, {
      timeout: 60000,
      settleMs: 1000,
      allowPasswordFallback: true,
    });

    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
      settleMs: 2000,
    });

    const widgetList = page.locator('#widgetList');
    await widgetList.waitFor({ state: 'attached', timeout: 60000 });
    await expect(widgetList.locator('.widget-item').first()).toBeVisible({ timeout: 60000 });

    await page.waitForFunction(() => {
      const basic = new Set([
        'alert', 'badge', 'button', 'card', 'dropdown', 'form-group', 'loading',
        'message', 'modal', 'pagination', 'section', 'grid', 'text', 'image',
        'divider', 'spacer', 'table', 'tabs',
      ]);
      return Array.from(document.querySelectorAll('#widgetList .widget-preview-canvas')).some((canvas) => {
        const code = String(canvas.dataset.widgetCode || '');
        const tail = code.replace(/\\/g, '/').split('/').filter(Boolean).pop() || '';
        if (basic.has(tail.toLowerCase())) {
          return false;
        }
        if (canvas.dataset.previewLoaded !== '1') {
          return false;
        }
        if (canvas.querySelector('.widget-preview-placeholder, .widget-preview-error')) {
          return false;
        }
        const thumb = canvas.querySelector('.te-library-thumb');
        if (thumb) {
          const text = String(thumb.textContent || '').replace(/\s+/g, ' ').trim();
          const media = thumb.querySelector('img');
          return !!media || text.length > 0;
        }
        const text = String(canvas.textContent || '').replace(/\s+/g, ' ').trim();
        const hasMedia = !!canvas.querySelector('img, svg, video, iframe, picture, canvas, .widget-preview-viewport');
        return hasMedia || text.length > 0;
      });
    }, null, { timeout: 90000 });

    const settledReal = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('#widgetList .widget-preview-canvas[data-preview-loaded="1"]'))
        .filter((canvas) => {
          if (canvas.querySelector('.widget-preview-placeholder, .widget-preview-error')) {
            return false;
          }
          return !!canvas.querySelector('.te-library-thumb, .widget-preview-viewport, .te-component-preview, img');
        })
        .length;
    });
    expect(settledReal).toBeGreaterThan(0);
  });
});
