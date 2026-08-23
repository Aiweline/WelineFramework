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
    await expect(frame.locator('html')).toHaveAttribute('data-theme', /light|dark/);
    await expect(frame.locator('body')).toBeVisible({ timeout: 60000 });
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

    expect(await frame.locator('[data-wslot]').count()).toBeGreaterThan(0);
    await expect(frame.locator('#orphan-widgets-warning')).toHaveCount(0);
    await expect(frame.locator('link[href*="weline-theme-preview.css"]')).toHaveCount(1);
    await expect(frame.locator('script[src*="weline-theme-preview.js"]')).toHaveCount(1);
    await expect(frame.locator('html')).toHaveAttribute('data-w-editor-preview-engine', 'full');

    const themeAssets = await frame.locator('link[href], script[src]').evaluateAll((nodes) => nodes
      .map((node) => node.getAttribute('href') || node.getAttribute('src') || '')
      .filter((url) => url.includes('/view/theme/') || url.includes('/layouts/')));

    expect(themeAssets.length).toBeGreaterThan(0);
    expect(themeAssets.some((url) => url.includes(`frontend_theme_id=${activeTheme.id}`))).toBeTruthy();
  });

  test('library drag exposes inside, before, and after placement feedback and clears on cancel', async ({ page }) => {
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

    const libraryWidget = page.locator('.widget-item.draggable').first();
    await libraryWidget.waitFor({ state: 'visible', timeout: 60000 });
    const frame = page.frameLocator('#previewFrame');
    const slot = frame.locator('[data-wslot]').first();
    await slot.waitFor({ state: 'visible', timeout: 60000 });

    await libraryWidget.evaluate((element) => {
      const dataTransfer = new DataTransfer();
      element.dispatchEvent(new DragEvent('dragstart', {
        bubbles: true,
        cancelable: true,
        dataTransfer,
      }));
    });
    await page.waitForTimeout(50);

    await slot.evaluate((element) => {
      element.querySelectorAll('[data-w-drag-contract-fixture]').forEach((node) => node.remove());
      const first = document.createElement('div');
      first.dataset.layoutId = 'drag-contract-first';
      first.dataset.widgetCode = 'drag-contract-first';
      first.dataset.widgetName = 'First block';
      first.dataset.wDragContractFixture = '1';
      first.style.minHeight = '80px';
      const second = document.createElement('div');
      second.dataset.layoutId = 'drag-contract-second';
      second.dataset.widgetCode = 'drag-contract-second';
      second.dataset.widgetName = 'Second block';
      second.dataset.wDragContractFixture = '1';
      second.style.minHeight = '80px';
      element.append(first, second);
    });

    await slot.evaluate((element) => {
      const target = element.querySelector('[data-w-drag-contract-fixture="1"]');
      const rect = target.getBoundingClientRect();
      element.dispatchEvent(new DragEvent('dragover', {
        bubbles: true,
        cancelable: true,
        clientY: rect.top + 2,
        dataTransfer: new DataTransfer(),
      }));
    });
    await expect(slot).toHaveAttribute('data-w-drop-position', 'before');
    await expect(slot.locator(':scope > .w-theme-preview-drop-feedback')).toContainText('前');

    await slot.evaluate((element) => {
      const fixtures = element.querySelectorAll('[data-w-drag-contract-fixture]');
      const target = fixtures[fixtures.length - 1];
      const rect = target.getBoundingClientRect();
      element.dispatchEvent(new DragEvent('dragover', {
        bubbles: true,
        cancelable: true,
        clientY: rect.bottom - 2,
        dataTransfer: new DataTransfer(),
      }));
    });
    await expect(slot).toHaveAttribute('data-w-drop-position', 'after');
    await expect(slot.locator(':scope > .w-theme-preview-drop-feedback')).toContainText('后');

    await slot.evaluate((element) => {
      element.querySelectorAll('[data-w-drag-contract-fixture]').forEach((node) => node.remove());
      const rect = element.getBoundingClientRect();
      element.dispatchEvent(new DragEvent('dragover', {
        bubbles: true,
        cancelable: true,
        clientY: rect.top + Math.max(1, rect.height / 2),
        dataTransfer: new DataTransfer(),
      }));
    });
    await expect(slot).toHaveAttribute('data-w-drop-position', 'inside');
    await expect(slot.locator(':scope > .w-theme-preview-drop-feedback')).toContainText('放入');

    await libraryWidget.evaluate((element) => {
      element.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true }));
    });
    await expect(slot).not.toHaveAttribute('data-w-drop-position', /.+/);
    await expect(slot.locator(':scope > .w-theme-preview-drop-feedback')).toHaveCount(0);
  });
});
