/**
 * 万能商城内核计划：Media shareCopy / COW（TEST-P2A-07）
 *
 * - 真实 website shard DB（MediaRepository），非假绿 DOM
 * - 决定性：A→B share 后 owner.ref_count=2；cowEdit 副本后 cow=true 且 owner blob 不变、ref_count 回 1
 *
 * @weline-e2e-spec { module: Weline_Product, type: plan, layer: frontend }
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

const MODULE = 'Weline_Product';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p2a07-media-cow-fixture.php');
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
    throw new Error(`p2a07 fixture ${action} failed: ${parsed.error || stdout}`);
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

async function callMedia(page, operation, params = {}) {
  return page.evaluate(async ({ operation: op, params: p }) => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const resource = await api.resource('product_media');
    if (!resource || typeof resource[op] !== 'function') {
      return { __no_op: op, keys: resource ? Object.keys(resource) : [] };
    }
    try {
      const data = await resource[op](p);
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
    throw new Error(`product_media api unavailable: ${JSON.stringify(result)}`);
  }
  if (!result.ok) {
    const nested = result.response && result.response.data ? result.response.data : null;
    if (nested && typeof nested === 'object') {
      return nested;
    }
    throw new Error(`product_media op failed: ${JSON.stringify(result)}`);
  }
  const data = result.data;
  if (data && typeof data === 'object' && data.data && typeof data.data === 'object' && data.success === undefined) {
    return data.data;
  }
  return data;
}

moduleDescribe(test, MODULE, '计划 P2A-07 Media shareCopy/COW', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2A-07' },
    'A→B shareCopy 引用守恒；副本 cowEdit 后 owner blob 不变',
    async ({ page }) => {
      const fixture = runFixture('prepare', {
        run_id: `p2a07-e2e-${Date.now()}`,
        website_id: 0,
      });
      expect(fixture.harness_active).toBeTruthy();

      try {
        await gotoFrontend(page, '/', DIRECT);
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureApi(page);

        const inactive = unwrap(await callMedia(page, 'clearHarness', {}));
        expect(inactive.success, JSON.stringify(inactive)).toBeTruthy();

        const prep = unwrap(await callMedia(page, 'prepareHarness', {
          run_id: fixture.run_id,
          website_id: 0,
        }));
        expect(prep.success, JSON.stringify(prep)).toBeTruthy();
        expect(prep.harness_active).toBeTruthy();

        const result = unwrap(await callMedia(page, 'runShareCow', {
          website_id: 0,
          suffix: String(Date.now()),
        }));
        expect(result.success, JSON.stringify(result)).toBeTruthy();
        expect(result.product_a_id).toBeGreaterThan(0);
        expect(result.product_b_id).toBeGreaterThan(0);
        expect(result.product_a_id).not.toBe(result.product_b_id);

        expect(result.owner, JSON.stringify(result)).toBeTruthy();
        expect(result.owner.ref_count).toBe(2);
        expect(result.owner.product_id).toBe(result.product_a_id);
        expect(result.copy_after_share.product_id).toBe(result.product_b_id);
        expect(result.copy_after_share.blob_key).toBe(result.owner.blob_key);
        expect(Number(result.copy_after_share.cow_source_media_id)).toBe(result.owner.media_id);

        expect(result.fork.cow, JSON.stringify(result.fork)).toBeTruthy();
        expect(result.fork.media.blob_key).not.toBe(result.owner.blob_key);
        expect(result.owner_after_cow.blob_key).toBe(result.owner.blob_key);
        expect(result.owner_after_cow.ref_count).toBe(1);
        expect(result.copy_after_cow.blob_key).toBe(result.fork.media.blob_key);
        expect(result.copy_after_cow.ref_count).toBe(1);
      } finally {
        runFixture('cleanup');
      }
    },
  );
});
