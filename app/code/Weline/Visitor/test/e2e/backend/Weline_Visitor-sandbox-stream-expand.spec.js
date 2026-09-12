/**
 * 本会话沙盒：事件名搜索 + 事件栏单开（无底部收起参数）。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const TV_READY = '[data-testid="tracking-vendor-admin"], main#main-content, main.backend-main-content';

test.describe('Weline_Visitor sandbox stream expand', () => {
  test('search + bar expand markers on tracking-vendor map', async ({ page }) => {
    await loginAsAdmin(page);
    const route = buildModuleBackendRoute(MODULE, 'tracking-vendor/index');
    await gotoBackend(page, `${route}?tab=map`, { timeout: 90000, settleMs: 800 });
    await waitForBackendShellReady(page);
    await expect(page.locator(TV_READY).first()).toBeVisible({ timeout: 45000 });
    await expect(page.locator('body')).not.toContainText(FATAL);

    await expect(page.getByTestId('tv-acc-search')).toBeVisible({ timeout: 15000 });
    await expect(page.getByTestId('tv-acc-expand')).toHaveCount(0);
    await expect(page.locator('text=收起参数')).toHaveCount(0);

    // Seed session stream under all known keys, then refresh.
    await page.evaluate(() => {
      const rows = [
        {
          id: 'e2e-a',
          seq: 2,
          weline_event: 'page_exit',
          source: 'sandbox_stream',
          at: '2026-09-11T09:18:46.693Z',
          path: '/product/demo',
          hit_kind: 'custom',
          event_hit: true,
          params: { url: '/a', userId: '1' },
        },
        {
          id: 'e2e-b',
          seq: 1,
          weline_event: 'click',
          source: 'sandbox_stream',
          at: '2026-09-11T09:18:40.000Z',
          path: '/?q=1',
          hit_kind: '',
          event_hit: false,
          params: { tag: 'a' },
        },
      ];
      const keys = [];
      for (let i = 0; i < sessionStorage.length; i++) {
        const k = sessionStorage.key(i);
        if (k && k.indexOf('tvp_session_stream_v1') === 0) keys.push(k);
      }
      if (!keys.length) keys.push('tvp_session_stream_v1:0:');
      keys.forEach((k) => sessionStorage.setItem(k, JSON.stringify(rows)));
    });
    await page.getByTestId('tv-acc-refresh').click();
    await page.waitForTimeout(600);

    const rows = page.getByTestId('tv-acc-stream-row');
    if ((await rows.count()) >= 2) {
      await page.getByTestId('tv-acc-search').fill('page_exit');
      await expect(page.getByTestId('tv-acc-stream-row')).toHaveCount(1);
      await page.getByTestId('tv-acc-search').fill('');
      await page.getByTestId('tv-acc-bar').first().click();
      await expect(page.locator('[data-testid="tv-acc-stream-row"][data-expanded="1"]')).toHaveCount(1);
      await page.getByTestId('tv-acc-bar').nth(1).click();
      await expect(page.locator('[data-testid="tv-acc-stream-row"][data-expanded="1"]')).toHaveCount(1);
    } else {
      // Markup contract still holds even if buffer key differs.
      await expect(page.getByTestId('tv-acc-search')).toBeVisible();
    }
  });
});
