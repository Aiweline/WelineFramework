// @weline-e2e-runtime fallback
// app/code/Weline/Theme/test/e2e/frontend/deferred-immediate-load.spec.js
// 冒烟：主题预览页包含 Theme 运行时。业务模块是否注册延迟脚本不属于 Theme 的契约。
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

  test('live preview exposes the Theme runtime without a server error', async ({ page }) => {
    await openLiveHomePreview(page);

    await expect(page.locator('html')).toHaveAttribute('data-theme', /.+/);
    await expect(page).toHaveURL(/theme\/frontend\/theme-preview\/content/);

    const themeJsScripts = page.locator('script[src*="theme.js"]');
    await expect(themeJsScripts.first()).toBeAttached({ timeout: 30000 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(/WLS Runtime Error|ParseError|Fatal error/i);
  });
});
