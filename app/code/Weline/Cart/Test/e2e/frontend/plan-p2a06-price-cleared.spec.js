/**
 * 万能商城内核计划：Price cleared → Cart/Checkout 可售闸门（TEST-P2A-06）
 *
 * - Store cleared 覆盖时 Weline.Api.resource('cart').add 必须失败且 error_code=price_cleared_at_scope
 * - 删除 Store 覆盖后恢复 Website 父价，加购成功
 * - 加购成功后再 clear：checkout.placeOrder 必须同样拒绝（提交窗口闸门）
 * - 无 catalog Offer 的假 product_id 仍可匿名快照加购（兼容既有合同 E2E）
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
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p2a06-price-cleared-fixture.php');
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
    throw new Error(`p2a06 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function runResourceApi(page, provider, operation, params = {}, options = {}) {
  return page.evaluate(async ({ provider, operation, params, options }) => {
    const api = window.Weline && window.Weline.Api;
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    try {
      const resource = await api.resource(provider);
      const fn = resource && resource[operation];
      if (typeof fn !== 'function') {
        return { __no_operation: operation };
      }
      return await fn.call(resource, params, options);
    } catch (e) {
      return {
        __error: String(e && e.message ? e.message : e),
        __error_code: e && e.code ? String(e.code) : '',
        __error_response: e && e.response && e.response.data ? e.response.data : null,
      };
    }
  }, { provider, operation, params, options });
}

function pickErrorCode(result) {
  if (!result || typeof result !== 'object') {
    return '';
  }
  if (result.error_code) {
    return String(result.error_code);
  }
  if (result.code && result.code !== 'business_error') {
    return String(result.code);
  }
  if (result.data && result.data.error_code) {
    return String(result.data.error_code);
  }
  if (result.data && result.data.code && result.data.code !== 'business_error') {
    return String(result.data.code);
  }
  if (result.__error_code && result.__error_code !== 'business_error') {
    return String(result.__error_code);
  }
  return '';
}

function isSuccess(result) {
  if (!result || typeof result !== 'object') {
    return false;
  }
  if (result.success === true) {
    return true;
  }
  return !!(result.data && result.data.success === true);
}

moduleDescribe(test, MODULE, '计划 Price cleared Cart/Checkout 闸门', () => {
  test.setTimeout(240000);

  /** @type {Record<string, any>|null} */
  let fixture = null;

  test.beforeAll(() => {
    fixture = runFixture('prepare', {
      website_id: 0,
      store_id: 1,
      currency: 'CNY',
    });
  });

  test.afterAll(() => {
    if (!fixture) {
      return;
    }
    try {
      runFixture('cleanup', {
        website_id: fixture.website_id,
        store_id: fixture.store_id,
        offer_id: fixture.offer_id,
        product_id: fixture.product_id,
        currency: fixture.currency,
      });
    } catch (_) {
      // best-effort
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2A-06' },
    'Store price cleared → cart.add / checkout.placeOrder 拒绝；删除覆盖后可售',
    async ({ page }) => {
      expect(fixture, 'fixture 必须准备成功').toBeTruthy();

      await gotoFrontend(page, '/cart', { timeout: 60000, settleMs: 800, ...DIRECT });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

      const probe = await runResourceApi(page, 'cart', 'summary');
      expect(probe.__no_api, '页面必须暴露 Weline.Api').toBeUndefined();
      expect(probe.__no_operation, 'cart 资源必须提供 summary').toBeUndefined();
      await runResourceApi(page, 'cart', 'clear');

      const addParams = {
        product_id: fixture.product_id,
        offer_id: fixture.offer_id,
        website_id: fixture.website_id,
        store_id: fixture.store_id,
        currency: fixture.currency,
        qty: 1,
        name: fixture.name,
        sku: fixture.sku,
        price: fixture.price,
      };

      const keepResult = { keepBusinessResult: true, silent: true };

      const blocked = await runResourceApi(page, 'cart', 'add', addParams, keepResult);
      expect(blocked.__error, `cart.add 不应抛 JS 异常：${JSON.stringify(blocked)}`).toBeUndefined();
      expect(isSuccess(blocked), `cleared 后必须失败：${JSON.stringify(blocked)}`).toBeFalsy();
      expect(pickErrorCode(blocked), `必须返回 price_cleared_at_scope：${JSON.stringify(blocked)}`)
        .toBe('price_cleared_at_scope');

      const restored = runFixture('restore', {
        website_id: fixture.website_id,
        store_id: fixture.store_id,
        offer_id: fixture.offer_id,
        currency: fixture.currency,
      });
      expect(restored.amount_minor).toBe(fixture.parent_amount_minor);

      const allowed = await runResourceApi(page, 'cart', 'add', addParams, keepResult);
      expect(allowed.__error, `恢复后加购不应抛异常：${JSON.stringify(allowed)}`).toBeUndefined();
      expect(isSuccess(allowed), `恢复父价后必须成功：${JSON.stringify(allowed)}`).toBeTruthy();

      const fake = await runResourceApi(page, 'cart', 'add', {
        product_id: 990001,
        qty: 1,
        name: 'E2E Fake Snapshot Compat',
        sku: 'E2E-FAKE-P2A06',
        price: 1.23,
      }, keepResult);
      expect(isSuccess(fake), `无 Offer 假 product_id 仍应可加购：${JSON.stringify(fake)}`).toBeTruthy();

      // 仅保留 catalog Offer 行，便于 Checkout 闸门对准 fixture product
      await runResourceApi(page, 'cart', 'clear');
      const catalogOnly = await runResourceApi(page, 'cart', 'add', addParams, keepResult);
      expect(isSuccess(catalogOnly), `catalog 商品加购必须成功：${JSON.stringify(catalogOnly)}`).toBeTruthy();

      // 加购后 Store cleared：提交窗口必须拒绝
      runFixture('clear_store', {
        website_id: fixture.website_id,
        store_id: fixture.store_id,
        offer_id: fixture.offer_id,
        currency: fixture.currency,
      });

      await gotoFrontend(page, '/checkout', { timeout: 60000, settleMs: 800, ...DIRECT });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

      const placeParams = {
        guest_email: 'e2e.p2a06.checkout@example.test',
        shipping_method: 'e2e_dummy_shipping',
        payment_method: 'e2e_dummy_payment',
        shipping_address: {
          firstname: 'E2E',
          lastname: 'P2A06',
          email: 'e2e.p2a06.checkout@example.test',
          telephone: '13800000006',
          country_code: 'CN',
          province: '上海',
          city: '上海',
          district: '浦东',
          street: '测试路 1 号',
          postcode: '200000',
        },
      };

      const placeBlocked = await runResourceApi(page, 'checkout', 'placeOrder', placeParams, keepResult);
      expect(placeBlocked.__error, `placeOrder 业务失败应 keepBusinessResult：${JSON.stringify(placeBlocked)}`).toBeUndefined();
      expect(isSuccess(placeBlocked), `cleared 后 placeOrder 必须失败：${JSON.stringify(placeBlocked)}`).toBeFalsy();
      expect(
        pickErrorCode(placeBlocked),
        `placeOrder 必须返回 price_cleared_at_scope：${JSON.stringify(placeBlocked)}`
      ).toBe('price_cleared_at_scope');

      // 恢复父价后，不再因 cleared 拒绝（其它配送/支付错误可接受）
      runFixture('restore', {
        website_id: fixture.website_id,
        store_id: fixture.store_id,
        offer_id: fixture.offer_id,
        currency: fixture.currency,
      });
      const placeAfterRestore = await runResourceApi(page, 'checkout', 'placeOrder', placeParams, keepResult);
      expect(pickErrorCode(placeAfterRestore)).not.toBe('price_cleared_at_scope');

      await gotoFrontend(page, '/cart', { timeout: 60000, settleMs: 500, ...DIRECT });
      await runResourceApi(page, 'cart', 'clear');
    }
  );
});
