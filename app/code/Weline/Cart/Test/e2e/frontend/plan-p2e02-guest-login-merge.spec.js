/**
 * 万能商城内核计划：guest→login 同 Scope 合车（TEST-P2E-02）
 *
 * - 浏览器先登录并 addV2（customer qty=2），随后退出
 * - issueGuestToken + addV2（guest qty=4）
 * - 再次 account.login → 自动 mergeGuest（Cookie guest_token）
 * - getV2Cart：由服务端当前登录身份读取 customer cart，item_count=5（stock 截断）
 * - fixture 跨进程检查 guest cart 已清空
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
  const last = lines[lines.length - 1] || '{}';
  const parsed = JSON.parse(last);
  if (!parsed.ok) {
    throw new Error(`p2e02 fixture ${action} failed: ${parsed.error || stdout}`);
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
        return { __no_operation: operation, keys: resource ? Object.keys(resource) : [] };
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

function isSuccess(result) {
  if (!result || typeof result !== 'object') {
    return false;
  }
  if (result.success === true) {
    return true;
  }
  return !!(result.data && result.data.success === true);
}

function pickData(result) {
  if (!result || typeof result !== 'object') {
    return {};
  }
  if (result.data && typeof result.data === 'object') {
    return result.data;
  }
  return result;
}

moduleDescribe(test, MODULE, '计划 P2E-02 guest/login 合车', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-02' },
    'guest 加购后登录同 Scope 合车并按库存截断',
    async ({ browser, page }) => {
      const fixture = runFixture('prepare');
      expect(fixture.customer_id, '须有 customer_id').toBeGreaterThan(0);
      expect(fixture.offer_uuid, '须有 offer_uuid').toBeTruthy();
      let guestToken = '';

      try {
        await gotoFrontend(page, '/', { timeout: 60000, settleMs: 800, ...DIRECT });
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureApi(page);

        const scope = {
          website_id: fixture.website_id,
          website_code: fixture.website_code,
          store_code: fixture.store_code,
          channel_code: fixture.channel_code,
          store_mode: fixture.store_mode,
        };
        const offerParams = {
          provider_code: fixture.provider_code,
          global_offer_uuid: fixture.offer_uuid,
          selection: { color: 'red' },
          website_id: fixture.website_id,
          website_code: fixture.website_code,
          store_code: fixture.store_code,
          channel_code: fixture.channel_code,
          store_mode: fixture.store_mode,
        };

        const firstLogin = await runResourceApi(page, 'account', 'login', {
          email: fixture.email,
          username: fixture.email,
          password: fixture.password,
        });
        expect(isSuccess(firstLogin), `首次 account.login 失败：${JSON.stringify(firstLogin)}`).toBeTruthy();

        const customerSeed = await runResourceApi(page, 'cart', 'addV2', {
          ...offerParams,
          qty: fixture.customer_pre_qty,
        });
        expect(isSuccess(customerSeed), `customer addV2 失败：${JSON.stringify(customerSeed)}`).toBeTruthy();
        expect(Number(pickData(customerSeed).item_count || 0)).toBe(fixture.customer_pre_qty);
        expect(String(pickData(customerSeed).owner_id || '')).toBe(String(fixture.customer_id));

        const logout = await runResourceApi(page, 'account', 'logout');
        expect(isSuccess(logout), `account.logout 失败：${JSON.stringify(logout)}`).toBeTruthy();

        const tokenRes = await runResourceApi(page, 'cart', 'issueGuestToken', {});
        expect(isSuccess(tokenRes), `issueGuestToken 失败：${JSON.stringify(tokenRes)}`).toBeTruthy();
        guestToken = String(pickData(tokenRes).guest_token || tokenRes.guest_token || '');
        expect(guestToken, 'guest_token 非空').not.toBe('');
        // API JSON 响应不一定落 Cookie；显式写入以保证 login_after Observer 能读到
        const origin = new URL(page.url()).origin;
        await page.context().addCookies([
          {
            name: 'weline_cart_guest_token',
            value: guestToken,
            url: origin,
            httpOnly: true,
            sameSite: 'Lax',
          },
        ]);

        const guestAdd = await runResourceApi(page, 'cart', 'addV2', {
          ...offerParams,
          guest_token: guestToken,
          qty: fixture.guest_qty,
        });
        expect(isSuccess(guestAdd), `guest addV2 失败：${JSON.stringify(guestAdd)}`).toBeTruthy();
        expect(Number(pickData(guestAdd).item_count || 0)).toBe(fixture.guest_qty);
        const guestCookie = (await page.context().cookies(origin))
          .find((cookie) => cookie.name === 'weline_cart_guest_token');
        expect(guestCookie && guestCookie.value, '登录前须保留 guest_token Cookie').toBe(guestToken);

        const login = await runResourceApi(page, 'account', 'login', {
          email: fixture.email,
          username: fixture.email,
          password: fixture.password,
        });
        expect(isSuccess(login), `account.login 失败：${JSON.stringify(login)}`).toBeTruthy();

        const customerCart = await runResourceApi(page, 'cart', 'getV2Cart', scope);
        expect(isSuccess(customerCart), `getV2Cart customer 失败：${JSON.stringify(customerCart)}`).toBeTruthy();
        const customerData = pickData(customerCart);
        expect(
          Number(customerData.item_count || 0),
          `自动合车结果错误；第二次登录响应：${JSON.stringify(login)}；客户车：${JSON.stringify(customerData)}`,
        ).toBe(fixture.expected_merged_qty);
        expect(Number(customerData.distinct_count || 0)).toBe(1);
        expect(String(customerData.owner_id || '')).toBe(String(fixture.customer_id));

        // 显式调用仍必须以当前登录身份执行；若 login_after 已合车则保持幂等结果。
        const merged = await runResourceApi(page, 'cart', 'mergeGuest', {
          ...scope,
          guest_token: guestToken,
        });
        expect(isSuccess(merged), `mergeGuest 失败：${JSON.stringify(merged)}`).toBeTruthy();
        const mergedData = pickData(merged);
        expect(
          Number(mergedData.item_count || 0),
          `合车后 item_count 须为库存上限 ${fixture.expected_merged_qty}，实际 ${JSON.stringify(mergedData)}`,
        ).toBe(fixture.expected_merged_qty);
        expect(String(mergedData.owner_id || '')).toBe(String(fixture.customer_id));

        const guestContext = await browser.newContext({ ignoreHTTPSErrors: true });
        try {
          const guestPage = await guestContext.newPage();
          await gotoFrontend(guestPage, '/', { timeout: 60000, settleMs: 500, ...DIRECT });
          await ensureApi(guestPage);
          const guestCart = await runResourceApi(guestPage, 'cart', 'getV2Cart', {
            ...scope,
            guest_token: guestToken,
          });
          expect(isSuccess(guestCart), `跨浏览器读取 guest cart 失败：${JSON.stringify(guestCart)}`).toBeTruthy();
          const guestData = pickData(guestCart);
          expect(guestData.is_empty === true, `自动合车后游客车须清空：${JSON.stringify(guestData)}`).toBeTruthy();
          expect(Number(guestData.item_count || 0)).toBe(0);
        } finally {
          await guestContext.close();
        }
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
