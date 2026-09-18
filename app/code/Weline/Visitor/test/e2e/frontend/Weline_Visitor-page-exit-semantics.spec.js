/**
 * page_exit 仅真实卸载；切标签只发 page_hide，不发 page_exit。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: frontend }
 */
const { test, expect, moduleDescribe, moduleCase, getRuntimeInfo } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';

moduleDescribe(test, MODULE, 'Visitor page_exit 触发语义', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PAGE-EXIT-001' },
    '停留页 visibility_hidden 只 page_hide；非持久 pagehide 才 page_exit',
    async ({ page }) => {
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || '').replace(/\/$/, '');
      expect(origin).toBeTruthy();

      await page.goto(`${origin}/`, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await page.waitForFunction(
        () => !!(window.WelinePixel && typeof window.WelinePixel.track === 'function'),
        null,
        { timeout: 30000 }
      );

      await page.evaluate(() => {
        window.__e2ePixelEvents = [];
        const orig = window.WelinePixel.track.bind(window.WelinePixel);
        window.WelinePixel.track = function (name, params, opts) {
          window.__e2ePixelEvents.push({
            name: String(name || ''),
            trigger: params && params.trigger ? String(params.trigger) : '',
          });
          return orig(name, params, opts);
        };
      });

      // 模拟切标签 / IDE 失焦
      await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', {
          configurable: true,
          get: function () {
            return 'hidden';
          },
        });
        document.dispatchEvent(new Event('visibilitychange'));
      });
      await page.waitForTimeout(300);

      let names = await page.evaluate(() => (window.__e2ePixelEvents || []).map((e) => e.name));
      expect(names, 'visibility_hidden 应记 page_hide').toContain('page_hide');
      expect(names, 'visibility_hidden 不得记 page_exit').not.toContain('page_exit');

      // 恢复可见后再模拟真实卸载
      await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', {
          configurable: true,
          get: function () {
            return 'visible';
          },
        });
        document.dispatchEvent(new Event('visibilitychange'));
        window.__e2ePixelEvents = [];
        var ev = new Event('pagehide');
        Object.defineProperty(ev, 'persisted', { value: false });
        window.dispatchEvent(ev);
      });
      await page.waitForTimeout(300);

      names = await page.evaluate(() => (window.__e2ePixelEvents || []).map((e) => e.name + ':' + e.trigger));
      expect(names.some((n) => n.indexOf('page_exit:pagehide') === 0), '非持久 pagehide 应 page_exit').toBeTruthy();
    }
  );
});
