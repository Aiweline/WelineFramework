/**
 * 万能商城内核计划：Search 索引不可用 → product_direct_degraded（TEST-P3C-03）
 *
 * - fixture 与 WLS 分属两个进程，只能通过持久化 gate/marker 传递状态
 * - storefront 只提交 q，Scope 必须来自服务端 RequestContext
 * - 空命中也必须带 Product current watermark/hash/count，禁止假空成功
 *
 * @weline-e2e-spec { module: Weline_Search, type: plan, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Search';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p3c03-degraded-search-fixture.php');
const DIRECT = { useProxy: false };

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  const parsed = JSON.parse(last);
  if (!parsed.ok) {
    throw new Error(`p3c03 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function ensureApi(page) {
  await page.waitForFunction(() => {
    const w = window.Weline;
    return !!(w && ((w.Api && typeof w.Api.resource === 'function') || typeof w.load === 'function'));
  }, { timeout: 30000 });
  await page.evaluate(async () => {
    const w = window.Weline;
    if (w && w.Api && typeof w.Api.resource === 'function') {
      return;
    }
    if (w && typeof w.load === 'function') {
      await w.load('api');
    }
  });
  await page.waitForFunction(
    () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
    { timeout: 15000 },
  );
}

async function callSearch(page, operation, params = {}) {
  return page.evaluate(async ({ operation: op, params: p }) => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const search = await api.resource('search');
    if (!search || typeof search[op] !== 'function') {
      return { __no_op: op, keys: search ? Object.keys(search) : [] };
    }
    try {
      const data = await search[op](p);
      return { ok: true, data };
    } catch (err) {
      return {
        ok: false,
        message: String(err && (err.message || err)),
        response: err && err.response && err.response.data ? err.response.data : null,
      };
    }
  }, { operation, params });
}

function unwrap(result) {
  if (!result || result.__no_api || result.__no_op) {
    throw new Error(`search api unavailable: ${JSON.stringify(result)}`);
  }
  if (!result.ok) {
    const nested = result.response && result.response.data ? result.response.data : null;
    if (nested && typeof nested === 'object') {
      return nested;
    }
    throw new Error(`search op failed: ${JSON.stringify(result)}`);
  }
  const data = result.data;
  if (data && typeof data === 'object' && data.data && typeof data.data === 'object' && data.success === undefined) {
    return data.data;
  }
  return data;
}

moduleDescribe(test, MODULE, '计划 P3C-03 Search degraded', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P3C-03' },
    '持久化 degraded marker 跨进程触发 Product current 直读并提供防假空证据',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      expect(fixture.subject).toBe(
        `${fixture.website_id}:${fixture.store_id}:${fixture.channel_id}`,
      );
      expect(fixture.alias).toBe('index');
      expect(fixture.alias_generation).toBeGreaterThan(0);

      try {
        await gotoFrontend(page, '/', DIRECT);
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureApi(page);

        const result = unwrap(await callSearch(page, 'search', { q: fixture.q }));

        expect(result.success, JSON.stringify(result)).toBeTruthy();
        expect(result.source, '必须显式 degraded 来源').toBe(fixture.expected_source);
        expect(result.degraded).toBeTruthy();
        expect(result.degrade_reason).toBe(fixture.expected_degrade_reason);
        expect(result.rollout_mode).toBe('allowlist');
        expect(result.website_id).toBe(fixture.website_id);
        expect(result.store_id).toBe(fixture.store_id);
        expect(result.channel_id).toBe(fixture.channel_id);
        expect(result.direct_source_watermark).toBeGreaterThanOrEqual(
          fixture.source_watermark_at_mark,
        );
        expect(result.direct_snapshot_hash).toMatch(/^[a-f0-9]{64}$/);
        expect(result.direct_document_count).toBeGreaterThanOrEqual(result.hit_count);
        expect(result.direct_match_count).toBe(result.hit_count);
        expect(Array.isArray(result.hits)).toBeTruthy();
        for (const hit of result.hits) {
          expect(hit.website_id).toBe(fixture.website_id);
          expect(hit.store_id).toBe(fixture.store_id);
          expect(hit.channel_id).toBe(fixture.channel_id);
          expect(['exact', 'neutral']).toContain(hit.dimension_source);
        }
        expect(result.degrade_active, 'degrade marker 必须激活').toBeTruthy();
        expect(result.degrade_marker_persisted).toBeTruthy();
        expect(result.degrade_marker.active).toBeTruthy();
        expect(result.degrade_marker.marker_version).toBeGreaterThanOrEqual(
          fixture.marker_version,
        );
        expect(result.source).not.toBe('');
      } finally {
        const cleanup = runFixture('cleanup');
        expect(cleanup.cleaned).toBeTruthy();
        expect(cleanup.rollout_mode).toBe('off');
        expect(cleanup.marker_active).toBeFalsy();
        expect(cleanup.alias).toBe('direct');
        expect(cleanup.alias_generation).toBe(0);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P3C-04' },
    '恢复门仅在 Search incremental 与 Product current 水位相等时 CAS 清除',
    async () => {
      const prepared = runFixture('prepare_recovery');
      expect(prepared.source_watermark).toBeGreaterThan(0);
      expect(prepared.index_watermark).toBe(prepared.source_watermark);

      try {
        const recovered = runFixture('recover');
        expect(recovered.lagged_index_watermark).toBeLessThan(
          recovered.source_watermark,
        );
        expect(recovered.lag_rejected).toBeTruthy();
        expect(recovered.lag_error_code).toBe('search_recovery_watermark_not_caught_up');
        expect(recovered.index_watermark).toBe(recovered.source_watermark);
        expect(recovered.marker_active).toBeFalsy();
        expect(recovered.marker_version).toBeGreaterThan(prepared.marker_version);
      } finally {
        const cleanup = runFixture('cleanup');
        expect(cleanup.cleaned).toBeTruthy();
        expect(cleanup.rollout_mode).toBe('off');
        expect(cleanup.marker_active).toBeFalsy();
        expect(cleanup.alias).toBe('direct');
        expect(cleanup.alias_generation).toBe(0);
      }
    },
  );
});
