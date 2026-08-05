/**
 * 万能商城内核计划：B2B 组客 vs 零售候选价（TEST-P4C-01）
 *
 * - shadow 开启：B2B 客户命中价目；零售客户仍 retail
 * - mode off：B2B 候选关闭（source=b2b_closed）
 *
 * @weline-e2e-spec { module: Weline_B2B, type: plan, layer: frontend }
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

const MODULE = 'Weline_B2B';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p4c01-b2b-retail-candidate-fixture.php');
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
    throw new Error(`p4c01 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function ensureApi(page) {
  await page.waitForFunction(() => {
    const w = window.Weline;
    return !!(w && ((w.Api && typeof w.Api.resource === 'function') || typeof w.load === 'function'));
  }, undefined, { timeout: 30000 });
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
    undefined,
    { timeout: 15000 },
  );
}

async function callB2b(page, operation, params = {}) {
  return page.evaluate(async ({ operation: op, params: p }) => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const b2b = await api.resource('b2b');
    if (!b2b || typeof b2b[op] !== 'function') {
      return { __no_op: op, keys: b2b ? Object.keys(b2b) : [] };
    }
    try {
      const data = await b2b[op](p);
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
    throw new Error(`b2b api unavailable: ${JSON.stringify(result)}`);
  }
  if (!result.ok) {
    const nested = result.response && result.response.data ? result.response.data : null;
    if (nested && typeof nested === 'object') {
      return nested;
    }
    throw new Error(`b2b op failed: ${JSON.stringify(result)}`);
  }
  const data = result.data;
  if (data && typeof data === 'object' && data.data && typeof data.data === 'object' && data.success === undefined) {
    return data.data;
  }
  return data;
}

moduleDescribe(test, MODULE, '计划 P4C-01 B2B 与零售候选', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P4C-01' },
    'B2B 客户命中价目；零售保持 retail；mode off 关闭 B2B 候选',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      expect(fixture.harness_active).toBeTruthy();

      try {
        await gotoFrontend(page, '/customer/account/login', DIRECT);
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureApi(page);

        const b2b = unwrap(await callB2b(page, 'resolve', {
          customer_id: fixture.b2b_customer_id,
          website_id: fixture.website_id,
          sku: fixture.sku,
          retail_amount_minor: fixture.retail_amount_minor,
        }));
        expect(b2b.ok, JSON.stringify(b2b)).toBeTruthy();
        expect(b2b.source).toBe(fixture.expected_b2b_source);
        expect(b2b.amount_minor).toBe(fixture.expected_b2b_amount_minor);
        expect(b2b.price_list_id).toBe(fixture.expected_b2b_price_list_id);

        const retail = unwrap(await callB2b(page, 'resolve', {
          customer_id: fixture.retail_customer_id,
          website_id: fixture.website_id,
          sku: fixture.sku,
          retail_amount_minor: fixture.retail_amount_minor,
        }));
        expect(retail.ok, JSON.stringify(retail)).toBeTruthy();
        expect(retail.source).toBe(fixture.expected_retail_source);
        expect(retail.amount_minor).toBe(fixture.expected_retail_amount_minor);
        expect(retail.price_list_id).toBeNull();

        const modeOff = runFixture('set_mode', { rollout_mode: 'off' });
        expect(modeOff.ok, JSON.stringify(modeOff)).toBeTruthy();

        const closed = unwrap(await callB2b(page, 'resolve', {
          customer_id: fixture.b2b_customer_id,
          website_id: fixture.website_id,
          sku: fixture.sku,
          retail_amount_minor: fixture.retail_amount_minor,
        }));
        expect(closed.ok, JSON.stringify(closed)).toBeTruthy();
        expect(closed.source).toBe(fixture.expected_mode_off_source);
        expect(closed.amount_minor).toBe(fixture.retail_amount_minor);
        expect(closed.price_list_id).toBeNull();
        expect(closed.rule_stack || []).toEqual(expect.arrayContaining(['b2b_mode_off_closes_candidate']));
      } finally {
        runFixture('cleanup');
      }
    },
  );
});
