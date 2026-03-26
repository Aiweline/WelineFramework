// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const {
  test,
  expect,
  getActiveTheme,
  getRuntimeInfo,
  gotoFrontend,
  gotoThemePreview,
} = require('../../../../../../../tests/e2e/framework');

test.describe('Theme frontend preview integration', () => {
  test.setTimeout(90000);

  test('framework helper renders the live active frontend theme preview shell', async ({ page }) => {
    const runtime = getRuntimeInfo();
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    expect(activeTheme.id).toBeGreaterThan(0);

    await gotoThemePreview(page, {
      themeId: activeTheme.id,
      pageType: 'homepage',
      previewMode: 'live',
    }, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });

    const currentUrl = page.url();

    await expect(page.locator('html')).toHaveAttribute('data-theme', /.+/);
    await expect(page.locator('link[href*="/Weline/Theme/view/theme/frontend/assets/css/theme.css"]')).toHaveCount(1);

    const slotCount = await page.locator('[data-wslot]').count();
    const widgetCount = await page.locator('[data-widget-module="Weline_Theme"]').count();
    expect(slotCount).toBeGreaterThan(0);
    expect(widgetCount).toBeGreaterThan(0);
    expect(currentUrl).toContain('/theme/frontend/theme-preview/content');
    expect(currentUrl).toContain(`frontend_theme_id=${activeTheme.id}`);
    expect(currentUrl).toContain('weline_preview_token=');

    const runtimeTheme = runtime.themes.active.frontend || runtime.themes.active.global;
    expect(runtimeTheme.id).toBe(activeTheme.id);
  });

  test('legacy preview entry is normalized by the proxy layer and still renders the active theme preview', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');
    const activeThemeId = activeTheme.id;

    await gotoFrontend(page, `/?preview_theme=${activeTheme.id}&page_type=homepage`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });

    const state = await page.evaluate(themeId => ({
      currentUrl: window.location.href,
      themedDocument: !!document.documentElement.getAttribute('data-theme'),
      slotCount: document.querySelectorAll('[data-wslot]').length,
      widgetCount: document.querySelectorAll('[data-widget-module="Weline_Theme"]').length,
      hasPreviewToken: window.location.href.includes('weline_preview_token='),
      hasFrontendThemeId: window.location.href.includes(`frontend_theme_id=${themeId}`),
    }), activeThemeId);

    expect(state.themedDocument).toBeTruthy();
    expect(state.slotCount).toBeGreaterThan(0);
    expect(state.widgetCount).toBeGreaterThan(0);
    expect(state.currentUrl).toMatch(/(theme\/frontend\/theme-preview\/content|preview_theme=)/);
    expect(state.currentUrl).toMatch(new RegExp(`(preview_theme|frontend_theme_id)=${activeThemeId}`));
    expect(state.hasPreviewToken || state.hasFrontendThemeId).toBeTruthy();
  });

  test('preview theme assets stay isolated and do not leak into the next live visit', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await gotoThemePreview(page, {
      themeId: activeTheme.id,
      pageType: 'homepage',
      previewMode: 'live',
    }, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });

    const previewAssets = await page.evaluate(() => Array.from(document.querySelectorAll('link[href], script[src]'))
      .map(node => node.getAttribute('href') || node.getAttribute('src') || '')
      .filter(url => url.includes('/view/theme/') || url.includes('/layouts/')));

    expect(previewAssets.length).toBeGreaterThan(0);
    expect(previewAssets.some(url => url.includes('weline_preview_token='))).toBeTruthy();

    const livePage = await page.context().newPage();

    await gotoFrontend(livePage, '/', {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });

    const liveState = await livePage.evaluate(() => ({
      currentUrl: window.location.href,
      themedAssets: Array.from(document.querySelectorAll('link[href], script[src]'))
        .map(node => node.getAttribute('href') || node.getAttribute('src') || '')
        .filter(url => url.includes('/view/theme/') || url.includes('/layouts/')),
    }));

    expect(liveState.currentUrl).not.toContain('weline_preview_token=');
    expect(liveState.currentUrl).not.toContain('frontend_theme_id=');
    expect(liveState.themedAssets.every(url => !url.includes('weline_preview_token='))).toBeTruthy();
    expect(liveState.themedAssets.every(url => !url.includes('/static/__preview/'))).toBeTruthy();

    await livePage.close();
  });
});
