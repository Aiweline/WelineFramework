/**
 * 事件监视「清空」：清会话记录但保持面板开启并继续监听。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: frontend }
 */
const { test, expect, moduleDescribe, moduleCase, getRuntimeInfo } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';

moduleDescribe(test, MODULE, 'Visitor 监视器清空继续监听', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-MONITOR-CLEAR-001' },
    '点清空后会话记录归零且面板仍在',
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

      await page.evaluate(async () => {
        function loadMonitor() {
          return new Promise(function (resolve, reject) {
            if (window.WelineEventSandboxMonitor && typeof window.WelineEventSandboxMonitor.enable === 'function') {
              resolve();
              return;
            }
            var existing = document.querySelector(
              'script[data-weline-event-sandbox-monitor-bundle="true"],script[data-wesm-src]'
            );
            if (existing) {
              var tries = 0;
              var timer = setInterval(function () {
                tries += 1;
                if (window.WelineEventSandboxMonitor) {
                  clearInterval(timer);
                  resolve();
                } else if (tries > 80) {
                  clearInterval(timer);
                  reject(new Error('monitor script present but api missing'));
                }
              }, 50);
              return;
            }
            var s = document.createElement('script');
            s.src = '/Weline/Visitor/view/statics/js/event-sandbox-monitor.js?v=e2e-clear';
            s.defer = true;
            s.setAttribute('data-wesm-src', '1');
            s.setAttribute('data-weline-event-sandbox-monitor-bundle', 'true');
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('monitor script load failed')); };
            document.documentElement.appendChild(s);
          });
        }
        await loadMonitor();
        window.WelineEventSandboxMonitor.enable();
      });

      await page.waitForSelector('[data-testid="wesm-clear"]', { timeout: 20000 });

      await page.evaluate(() => {
        sessionStorage.setItem(
          'weline_event_sandbox_monitor_history_v1',
          JSON.stringify({
            current: {
              path: (location.pathname || '/') + '::e2e-seed',
              rows: [{ id: 'e2e-seed-cur', name: 'e2e_seed_current' }],
              updatedAt: Date.now()
            },
            previous: {
              path: '/e2e-prev',
              rows: [{ id: 'e2e-seed-prev', name: 'e2e_seed_previous' }],
              updatedAt: Date.now()
            }
          })
        );
        sessionStorage.setItem(
          'weline_event_sandbox_monitor_chains_v1',
          JSON.stringify([{ id: 'e2e-seed-chain', name: 'e2e_seed_chain' }])
        );
      });

      await page.click('[data-testid="wesm-clear"]');

      const after = await page.evaluate(() => {
        let hist = null;
        let chain = [];
        try {
          hist = JSON.parse(sessionStorage.getItem('weline_event_sandbox_monitor_history_v1') || 'null');
        } catch (e) {}
        try {
          chain = JSON.parse(sessionStorage.getItem('weline_event_sandbox_monitor_chains_v1') || '[]');
        } catch (e2) {}
        const currentRows = (hist && hist.current && hist.current.rows) || [];
        const previousRows = (hist && hist.previous && hist.previous.rows) || [];
        const allIds = []
          .concat(currentRows, previousRows, Array.isArray(chain) ? chain : [])
          .map((r) => String(r && r.id || ''));
        const root = document.getElementById('weline-event-sandbox-monitor');
        return {
          panelAlive: !!root,
          enabled: !!(window.WelineEventSandboxMonitor && window.WelineEventSandboxMonitor.isEnabled()),
          previousLen: previousRows.length,
          chainLen: Array.isArray(chain) ? chain.length : -1,
          hasAnySeed: allIds.some((id) => id.indexOf('e2e-seed-') === 0),
          clearBtnAlive: !!document.querySelector('[data-testid="wesm-clear"]'),
          hasClearApi: typeof window.WelineEventSandboxMonitor.clear === 'function',
        };
      });

      expect(after.hasClearApi).toBeTruthy();
      expect(after.panelAlive, '清空后面板应仍在').toBeTruthy();
      expect(after.enabled, '清空后监视应仍开启').toBeTruthy();
      expect(after.clearBtnAlive, '清空按钮应仍在').toBeTruthy();
      expect(after.previousLen, '上一页应清空').toBe(0);
      expect(after.chainLen, '累积链应清空').toBe(0);
      expect(after.hasAnySeed, '种子事件应被清掉').toBeFalsy();
    }
  );
});
