/**
 * P2E-003 current-source acceptance: TEST-P2E-09 + TEST-BROWSER-01.
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: plan, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'plan-p2e002-current-source-fixture.php');
const DIRECT = { useProxy: false };
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function fixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines[lines.length - 1] || '{}');
  if (!result.ok) {
    throw new Error(`P2E003 fixture ${action} failed: ${result.error || stdout}`);
  }
  return result;
}

function dataOf(result) {
  return result && typeof result.data === 'object' ? result.data : result;
}

function successOf(result) {
  const data = dataOf(result);
  return Boolean(result && result.success === true || data && data.success === true);
}

async function api(page, resource, operation, params = {}) {
  return page.evaluate(async ({ resource, operation, params }) => {
    let apiClient = window.Weline && window.Weline.Api;
    if ((!apiClient || typeof apiClient.resource !== 'function')
      && window.Weline && typeof window.Weline.load === 'function') {
      apiClient = await window.Weline.load('api');
    }
    if (!apiClient || typeof apiClient.resource !== 'function') {
      return { __no_api: true };
    }
    const proxy = await apiClient.resource(resource);
    if (!proxy || typeof proxy[operation] !== 'function') {
      return { __no_operation: `${resource}.${operation}` };
    }
    try {
      return await proxy[operation](params, { useProxy: false });
    } catch (error) {
      return {
        __error: String(error && (error.message || error)),
        response: error && error.response && error.response.data ? error.response.data : null,
      };
    }
  }, { resource, operation, params });
}

function checkoutPayload(result) {
  if (result && result.__error) {
    return result.response && result.response.data
      ? result.response.data
      : result.response || result;
  }
  return result && result.data && result.success === undefined ? result.data : result;
}

async function open(page, route = '/') {
  await gotoFrontend(page, route, { timeout: 60000, settleMs: 500, ...DIRECT });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL);
}

async function seedTrustedCart(page, prepared) {
  const issued = await api(page, 'cart', 'issueGuestToken');
  expect(successOf(issued), JSON.stringify(issued)).toBeTruthy();
  const token = String(dataOf(issued).guest_token || issued.guest_token || '');
  expect(token).not.toBe('');
  await page.context().addCookies([{
    name: 'weline_cart_guest_token',
    value: token,
    url: new URL(page.url()).origin,
    httpOnly: true,
    sameSite: 'Lax',
  }]);
  const offer = prepared.offers.physical_a;
  const added = await api(page, 'cart', 'addV2', {
    provider_code: 'product',
    global_offer_uuid: offer.uuid,
    legacy_product_id: offer.product_id,
    qty: 1,
    selection: { plan: 'p2e003' },
    guest_token: token,
  });
  expect(successOf(added), JSON.stringify(added)).toBeTruthy();
  return offer;
}

function pageIssues(page) {
  const issues = { consoleErrors: [], pageErrors: [], businessPosts: [] };
  page.on('console', (message) => {
    if (message.type() === 'error' && !/favicon|Failed to load resource/i.test(message.text())) {
      issues.consoleErrors.push(message.text());
    }
  });
  page.on('pageerror', (error) => issues.pageErrors.push(String(error)));
  page.on('request', (request) => {
    if (request.method() === 'POST' && ['xhr', 'fetch'].includes(request.resourceType())) {
      issues.businessPosts.push(new URL(request.url()).pathname);
    }
  });
  return issues;
}

function cleanup(page, prepared, quoteTokens = [], groupUuids = []) {
  return api(page, 'cart', 'clearV2')
    .catch(() => null)
    .finally(() => fixture('cleanup', {
      fixture: prepared,
      quote_tokens: quoteTokens.filter(Boolean),
      group_uuids: groupUuids.filter(Boolean),
    }));
}

moduleDescribe(test, MODULE, 'P2E-003 Checkout server UI current-source', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-09' },
    '可信商品行已存在于 Checkout 初始 HTML，JS 不负责构造商品 DOM',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      try {
        await open(page);
        const offer = await seedTrustedCart(page, prepared);
        const origin = getRuntimeInfo().runtime.target_origin;
        const response = await page.request.get(`${origin}/checkout`);
        expect(response.status()).toBe(200);
        const html = await response.text();
        expect(html).not.toMatch(FATAL);
        expect(html).toContain('data-checkout-items-hook');
        expect(html).toContain('<div class="weline-checkout__item" data-checkout-item>');
        expect(html).toContain(offer.name);

        await open(page, '/checkout');
        await expect(page.locator('[data-checkout-items-hook]')).toBeVisible();
        await expect(page.locator('[data-checkout-item]')).toContainText(offer.name);
      } finally {
        await cleanup(page, prepared);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-BROWSER-01' },
    '可信 addV2 → Cart → Checkout → pending Orders 全程只走 Weline.Api',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      const issues = pageIssues(page);
      const quoteTokens = [];
      const groupUuids = [];
      try {
        await open(page);
        const offer = await seedTrustedCart(page, prepared);

        await open(page, '/cart');
        const current = dataOf(await api(page, 'cart', 'getV2Cart'));
        expect(Number(current.item_count || 0)).toBe(1);

        await open(page, '/checkout');
        await expect(page.locator('[data-checkout-item]')).toContainText(offer.name);
        const data = dataOf(await api(page, 'checkout', 'getData', {
          shipping_address: prepared.address,
        }));
        expect(String(data.items_html || '')).toContain(offer.name);

        const frozen = checkoutPayload(await api(page, 'checkout', 'freezeQuote', {
          address: prepared.address,
          service_code: prepared.service_code,
        }));
        expect(frozen.success, JSON.stringify(frozen)).toBeTruthy();
        quoteTokens.push(frozen.quote_token);
        const submitted = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: frozen.quote_token,
          idempotency_key: `${prepared.run}-p2e003`,
        }));
        expect(submitted.success, JSON.stringify(submitted)).toBeTruthy();
        groupUuids.push(submitted.checkout_group_uuid);

        const db = fixture('verify', {
          quote_token: frozen.quote_token,
          checkout_group_uuid: submitted.checkout_group_uuid,
        }).data;
        expect(db.session_state).toBe('submitted');
        expect(db.group_count).toBe(1);
        expect(db.order_count).toBe(1);

        const apiPrefix = getRuntimeInfo().paths.frontend_api_prefix_path || '/api';
        expect(issues.businessPosts.length).toBeGreaterThan(0);
        for (const pathname of issues.businessPosts) {
          expect(pathname.startsWith(apiPrefix), pathname).toBeTruthy();
        }
        expect(issues.pageErrors).toEqual([]);
        expect(issues.consoleErrors).toEqual([]);
      } finally {
        await cleanup(page, prepared, quoteTokens, groupUuids);
      }
    },
  );
});
