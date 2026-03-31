// @weline-e2e-runtime fallback
// app/code/Weline/Theme/test/e2e/frontend/deferred-immediate-load.spec.js
// 冒烟：主题预览页包含 theme.js 与带 data-load-order="last" 的声明脚本（与 theme.js 延迟加载约定一致）。
// Weline.declare 行为以 Weline/Theme/test/Unit/DeferredImmediateLoadTest.js 为准；当前环境下预览页未必挂载 window.Weline（见 theme-override 仅校验 SSR/资源）。
// @weline-e2e-transport direct

const {
  test,
  expect,
  getActiveTheme,
  gotoThemePreview,
} = require('../../../../../../../tests/e2e/framework');

async function openLiveHomePreview(page) {
  const activeTheme = getActiveTheme('frontend');
  test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

  await gotoThemePreview(
    page,
    {
      themeId: activeTheme.id,
      pageType: 'homepage',
      previewMode: 'live',
    },
    {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1200,
      loadStateTimeout: 90000,
      allowLoadStateTimeout: true,
    },
  );

  await page.waitForLoadState('load', { timeout: 60000 }).catch(() => {});
}

test.describe('Deferred immediate load (preview DOM smoke)', () => {
  test.describe.configure({ retries: 1, timeout: 120000 });

  test('live preview exposes theme.js and at least one data-load-order="last" script', async ({ page }) => {
    await openLiveHomePreview(page);

    await expect(page.locator('html')).toHaveAttribute('data-theme', /.+/);
    await expect(page).toHaveURL(/theme\/frontend\/theme-preview\/content/);

    const themeJsScripts = page.locator('script[src*="theme.js"]');
    await expect(themeJsScripts.first()).toBeAttached({ timeout: 30000 });

    const deferredScripts = page.locator('script[data-load-order="last"]');
    await expect(deferredScripts.first()).toBeAttached({ timeout: 30000 });

    const searchDeferredOk = await page.evaluate(() => Array.from(document.querySelectorAll('script[data-load-order="last"]')).some((el) => {
      const t = el.textContent || '';
      return t.includes('search') && (t.includes('declare') || t.includes('WeShop_Search'));
    }));
    expect(searchDeferredOk).toBeTruthy();

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(/WLS Runtime Error|ParseError|Fatal error/i);
  });
});
