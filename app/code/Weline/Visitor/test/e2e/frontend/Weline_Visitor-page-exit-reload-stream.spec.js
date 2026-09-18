/**
 * 同 path 刷新不得把上一文档 page_exit 灌进当前数据流。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: frontend }
 */
const { test, expect, moduleDescribe, moduleCase, getRuntimeInfo } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';

moduleDescribe(test, MODULE, 'Visitor 监视器刷新不串 page_exit', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PAGE-EXIT-002' },
    '首页刷新后当前数据流不含 page_exit（应进上一页）',
    async ({ page }) => {
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || '').replace(/\/$/, '');
      expect(origin).toBeTruthy();

      await page.addInitScript(() => {
        try {
          sessionStorage.setItem('weline_event_sandbox_monitor_v1', '1');
        } catch (e) {}
      });

      await page.goto(`${origin}/`, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await page.waitForFunction(
        () => !!(window.WelinePixel && typeof window.WelinePixel.track === 'function'),
        null,
        { timeout: 30000 }
      );
      await page.waitForTimeout(1200);

      // 刷新同 path：旧实现会把卸载文档的 page_exit 恢复进当前流
      await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
      await page.waitForFunction(
        () => !!(window.WelinePixel && typeof window.WelinePixel.track === 'function'),
        null,
        { timeout: 30000 }
      );
      await page.waitForTimeout(1500);

      const summary = await page.evaluate(() => {
        let hist = null;
        try {
          hist = JSON.parse(sessionStorage.getItem('weline_event_sandbox_monitor_history_v1') || 'null');
        } catch (e) {}
        const currentNames = ((hist && hist.current && hist.current.rows) || []).map((r) => String(r.name || ''));
        const previousNames = ((hist && hist.previous && hist.previous.rows) || []).map((r) => String(r.name || ''));
        return {
          currentNames,
          previousNames,
          currentHasExit: currentNames.indexOf('page_exit') >= 0,
          previousHasExit: previousNames.indexOf('page_exit') >= 0,
          currentPath: hist && hist.current && hist.current.path,
          previousPath: hist && hist.previous && hist.previous.path,
        };
      });

      expect(summary.currentHasExit, '当前流不应含上一文档 page_exit: ' + JSON.stringify(summary)).toBeFalsy();
      // 刷新场景下上一文档的 page_exit 应落在 previous（若卸载时监视已记录）
      if (summary.previousNames.length) {
        expect(
          summary.previousHasExit || summary.previousNames.indexOf('page_hide') >= 0,
          '上一页应保留卸载生命周期事件'
        ).toBeTruthy();
      }
    }
  );
});
