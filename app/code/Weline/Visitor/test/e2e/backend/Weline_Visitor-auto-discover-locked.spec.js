/**
 * 自动发现事件：后台锁定徽章 + 店面沙盒 register-auto 端到端。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: fullstack }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
  getRuntimeInfo,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const TV_READY = '[data-testid="tracking-vendor-admin"], main#main-content, main.backend-main-content';

async function openTrackingVendor(page, query = {}) {
  const qs = new URLSearchParams();
  Object.entries(query || {}).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    qs.set(k, String(v));
  });
  const route = buildModuleBackendRoute(MODULE, 'tracking-vendor/index');
  const withQuery = qs.toString() ? `${route}?${qs.toString()}` : route;
  await gotoBackend(page, withQuery, {
    timeout: 90000,
    settleMs: 800,
  });
  await waitForBackendShellReady(page);
  const root = page.locator(TV_READY).first();
  await expect(root, `tracking-vendor ready url=${page.url()}`).toBeVisible({ timeout: 45000 });
  await expect(page.locator('body')).not.toContainText(FATAL);
  const admin = page.locator('[data-testid="tracking-vendor-admin"]').first();
  if (await admin.count()) {
    await expect(admin).toBeVisible({ timeout: 15000 });
  }
}

async function postRegisterAuto(request, origin, fields) {
  const url = `${origin}/visitor/analytics/event-picker/mapped`;
  const res = await request.post(url, {
    form: fields,
    headers: { Accept: 'application/json' },
  });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch (_e) {
    json = { ok: false, raw: text.slice(0, 400) };
  }
  return { res, text, json };
}

moduleDescribe(test, MODULE, 'Visitor 自动发现事件锁定 e2e', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-AUTO-DISC-001' },
    'register-auto 沙盒门禁：sandbox≠1 拒绝；sandbox=1 创建锁定且二次幂等',
    async ({ page, request }) => {
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || '').replace(/\/$/, '');
      expect(origin, 'e2e target_origin').toBeTruthy();

      // 先登录后台，避免先访店面绝对域再切 proxy 导致壳层异常
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });

      const name = `e2e_auto_disc_${Date.now()}`;
      const scope = 'default.default.default';

      const denied = await postRegisterAuto(request, origin, {
        register_auto: '1',
        sandbox: '0',
        website_id: '1',
        storage_scope: scope,
        weline_event: name,
      });
      expect([200, 403].includes(denied.res.status()), `deny status ${denied.res.status()}`).toBeTruthy();
      expect(denied.json.ok).toBeFalsy();
      expect(String(denied.json.error || '')).toContain('sandbox_required');

      const created = await postRegisterAuto(request, origin, {
        register_auto: '1',
        sandbox: '1',
        website_id: '1',
        storage_scope: scope,
        weline_event: name,
      });
      expect(
        created.res.ok(),
        `create status ${created.res.status()} body=${created.text.slice(0, 300)}`
      ).toBeTruthy();
      expect(created.json.ok).toBeTruthy();
      expect(created.json.deletable === false || created.json.origin === 'auto_discovered').toBeTruthy();

      const again = await postRegisterAuto(request, origin, {
        register_auto: '1',
        sandbox: '1',
        website_id: '1',
        storage_scope: scope,
        weline_event: name,
      });
      expect(again.res.ok()).toBeTruthy();
      expect(again.json.ok).toBeTruthy();
      expect(again.json.already === true || again.json.created === false).toBeTruthy();

      const createdScope = String(created.json.storage_scope || created.json.scope || scope);
      await openTrackingVendor(page, {
        website_id: '1',
        target_scope: createdScope,
        storage_scope: createdScope,
        tab: 'map',
        vendor: 'weline',
      });

      // 自定义事件池在「事件搭接」主 tab 内；默认「基本配置」会 hidden
      const mapTab = page.locator('#tv-tabs [data-tab="map"]').first();
      await expect(mapTab).toBeVisible({ timeout: 15000 });
      await mapTab.click();
      await page.waitForTimeout(500);

      const customTab = page.locator('[data-testid="tv-map-subtab-custom"], [data-map-subtab="custom"]').first();
      await expect(customTab).toBeVisible({ timeout: 10000 });
      await customTab.click();
      await page.waitForTimeout(400);

      const mapPanel = page.locator('[data-panel="map"]').first();
      await expect(mapPanel).toBeVisible({ timeout: 10000 });

      const refresh = page.locator('[data-testid="tv-custom-refresh"], #tv-custom-refresh').first();
      await expect(refresh).toBeVisible({ timeout: 10000 });
      await refresh.click();
      await page.waitForTimeout(1000);

      const search = page.locator('[data-testid="tv-custom-search"], #tv-custom-search').first();
      await expect(search).toBeVisible({ timeout: 10000 });
      await search.fill(name);
      await page.waitForTimeout(400);

      const lockedRow = page
        .locator('[data-panel="map"] [data-testid="tv-custom-row"]')
        .filter({ hasText: name })
        .first();
      await expect(lockedRow).toBeVisible({ timeout: 25000 });
      await expect(lockedRow.locator('[data-testid="tv-badge-auto-discovered"]')).toBeVisible();
      await expect(
        lockedRow.locator('[data-custom-del][data-locked="1"], [data-custom-del][disabled], [data-testid="tv-custom-del"][disabled]')
      ).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-AUTO-DISC-002' },
    '店面沙盒 Monitor 开启后声明元素触发 register-auto 写入 LS',
    async ({ page }) => {
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || runtime.target_origin || runtime.baseURL || '').replace(/\/$/, '');
      expect(origin, 'storefront origin').toBeTruthy();

      await page.goto(`${origin}/?weline_e2e_auto_disc=1`, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await page.waitForTimeout(1200);

      const evName = `e2e_sf_auto_${Date.now()}`;
      const result = await page.evaluate(async (name) => {
        const out = { name, ok: false, fetches: 0, inLs: false, error: '' };
        try {
          if (window.WelineEventSandboxMonitor && typeof window.WelineEventSandboxMonitor.enable === 'function') {
            window.WelineEventSandboxMonitor.enable();
          } else {
            sessionStorage.setItem('weline_event_sandbox_monitor_v1', '1');
          }
          const el = document.createElement('button');
          el.className = `weline-pixel::${name}`;
          el.textContent = 'e2e-auto';
          el.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;';
          document.body.appendChild(el);
          const origFetch = window.fetch.bind(window);
          let hit = 0;
          window.fetch = function (...args) {
            try {
              const u = String(args[0] || '');
              const body = args[1] && args[1].body ? String(args[1].body) : '';
              if (u.indexOf('event-picker/mapped') !== -1 && body.indexOf('register_auto=1') !== -1) {
                hit += 1;
              }
            } catch (_e) {}
            return origFetch(...args);
          };
          el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
          await new Promise((r) => setTimeout(r, 2200));
          window.fetch = origFetch;
          const scope = (window.__WelineVisitorTrackingConfig
            && (window.__WelineVisitorTrackingConfig.storageScope || window.__WelineVisitorTrackingConfig.storage_scope))
            || 'default.default.default';
          const raw = localStorage.getItem(`weline-visitor-custom-events:${scope}`);
          const ls = raw ? JSON.parse(raw) : null;
          out.fetches = hit;
          out.inLs = !!(ls && Array.isArray(ls.events) && ls.events.some((e) => e && e.name === name));
          out.ok = out.inLs || out.fetches > 0;
        } catch (e) {
          out.error = String(e && e.message ? e.message : e);
        }
        return out;
      }, evName);

      expect(result.error || '', `storefront error: ${result.error}`).toBe('');
      expect(result.ok, `register not observed: ${JSON.stringify(result)}`).toBeTruthy();
    }
  );
});
