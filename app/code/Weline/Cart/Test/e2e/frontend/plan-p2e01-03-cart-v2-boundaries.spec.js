/**
 * 万能商城内核计划：Cart V2 selection 边界（TEST-P2E-03）。
 *
 * - 客户端伪造 selection_hash、嵌套 selection 均由服务端拒绝，失败后购物车保持为空
 *
 * TEST-P2E-01 的双 Website/Host 真实 Scope 验收由
 * Weline_Websites/plan-commerce-kernel-dual-scope.spec.js 统一负责。
 *
 * @weline-e2e-spec { module: Weline_Cart, type: plan, layer: frontend }
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

const MODULE = 'Weline_Cart';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p2e02-guest-login-merge-fixture.php');
const DIRECT = { useProxy: false };

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`P2E Cart V2 fixture ${action} failed: ${parsed.error || stdout}`);
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

async function runCartApi(page, operation, params = {}) {
  return page.evaluate(async ({ operation, params }) => {
    try {
      const resource = await window.Weline.Api.resource('cart');
      if (!resource || typeof resource[operation] !== 'function') {
        return { __no_operation: operation, keys: resource ? Object.keys(resource) : [] };
      }
      return await resource[operation](params, { useProxy: false });
    } catch (error) {
      return {
        __error: String(error && error.message ? error.message : error),
        __error_code: error && error.code ? String(error.code) : '',
        __error_response: error && error.response && error.response.data ? error.response.data : null,
      };
    }
  }, { operation, params });
}

function pickData(result) {
  if (!result || typeof result !== 'object') {
    return {};
  }
  return result.data && typeof result.data === 'object' ? result.data : result;
}

function isSuccess(result) {
  return !!(result && (
    result.success === true
    || (result.data && result.data.success === true)
  ));
}

function errorCode(result) {
  const response = result && result.__error_response;
  return String(
    (result && result.error_code)
    || (result && result.data && result.data.error_code)
    || (response && response.error_code)
    || (response && response.data && response.data.error_code)
    || (response && response.data && response.data.data && response.data.data.error_code)
    || (result && result.__error_code)
    || '',
  );
}

moduleDescribe(test, MODULE, '计划 P2E-03 Cart V2 selection 边界', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-03' },
    '伪造 selection_hash 与嵌套 selection 被拒绝且不污染购物车',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      let guestToken = '';

      try {
        await gotoFrontend(page, '/', { timeout: 60000, settleMs: 800, ...DIRECT });
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureApi(page);

        const tokenResult = await runCartApi(page, 'issueGuestToken');
        expect(isSuccess(tokenResult), JSON.stringify(tokenResult)).toBeTruthy();
        guestToken = String(pickData(tokenResult).guest_token || tokenResult.guest_token || '');
        const common = {
          provider_code: fixture.provider_code,
          global_offer_uuid: fixture.offer_uuid,
          guest_token: guestToken,
          qty: 1,
        };

        const forged = await runCartApi(page, 'addV2', {
          ...common,
          selection: { size: 'M' },
          selection_hash: 'deadbeef',
        });
        expect(isSuccess(forged), JSON.stringify(forged)).toBeFalsy();
        expect(errorCode(forged), JSON.stringify(forged)).toBe('cart_selection_hash_mismatch');

        const nested = await runCartApi(page, 'addV2', {
          ...common,
          selection: { bad: ['nested'] },
        });
        expect(isSuccess(nested), JSON.stringify(nested)).toBeFalsy();
        expect(errorCode(nested), JSON.stringify(nested)).toBe('cart_selection_invalid');

        const cart = pickData(await runCartApi(page, 'getV2Cart', {
          guest_token: guestToken,
        }));
        expect(cart.is_empty === true).toBeTruthy();
        expect(Number(cart.item_count || 0)).toBe(0);
      } finally {
        runFixture('cleanup', {
          customer_id: fixture.customer_id,
          offer_uuid: fixture.offer_uuid,
          guest_token: guestToken,
        });
      }
    },
  );
});
