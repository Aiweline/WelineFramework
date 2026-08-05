/**
 * P2E-002 current-source acceptance: TEST-P2E-04 .. TEST-P2E-08.
 *
 * Browser trade calls use only Weline.Api. The fixture process seeds and
 * inspects real Shipping/CheckoutSession/Inventory/Order database rows.
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: plan, layer: frontend }
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

const MODULE = 'Weline_Checkout';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p2e002-current-source-fixture.php');
const DIRECT = { useProxy: false };
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function fixture(action, payload = {}) {
  let stdout = '';
  try {
    stdout = execFileSync('php', [FIXTURE_SCRIPT], {
      cwd: ROOT_DIR,
      input: JSON.stringify({ action, ...payload }),
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (error) {
    const capturedStdout = String(error && error.stdout || '').trim();
    const capturedStderr = String(error && error.stderr || '').trim();
    throw new Error(
      `P2E002 fixture ${action} process failed: stdout=${capturedStdout} stderr=${capturedStderr}`,
    );
  }
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines[lines.length - 1] || '{}');
  if (!result.ok) {
    throw new Error(`P2E002 fixture ${action} failed: ${result.error || stdout}`);
  }
  return result;
}

function dataOf(result) {
  if (result && typeof result === 'object' && result.data && typeof result.data === 'object') {
    return result.data;
  }
  return result;
}

function successOf(result) {
  const data = dataOf(result);
  return result && result.success === true || data && data.success === true;
}

async function ensureApi(page) {
  await page.waitForFunction(() => {
    const w = window.Weline;
    return !!(w && ((w.Api && typeof w.Api.resource === 'function') || typeof w.load === 'function'));
  }, { timeout: 30000 });
  await page.evaluate(async () => {
    if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
      return;
    }
    if (window.Weline && typeof window.Weline.load === 'function') {
      await window.Weline.load('api');
    }
  });
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
      return { __no_operation: `${resource}.${operation}`, keys: proxy ? Object.keys(proxy) : [] };
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
  expect(result && result.__no_api, JSON.stringify(result)).toBeUndefined();
  expect(result && result.__no_operation, JSON.stringify(result)).toBeUndefined();
  if (result && result.__error) {
    const nested = result.response && result.response.data ? result.response.data : result.response;
    if (nested && typeof nested === 'object') {
      return nested;
    }
    throw new Error(`Checkout Weline.Api failed: ${JSON.stringify(result)}`);
  }
  return result && result.data && result.success === undefined ? result.data : result;
}

async function open(page) {
  await gotoFrontend(page, '/', { timeout: 60000, settleMs: 600, ...DIRECT });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  await ensureApi(page);
}

async function guestToken(page) {
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
  return token;
}

async function addOffers(page, prepared, keys, token = '') {
  for (const entry of keys) {
    const key = typeof entry === 'string' ? entry : entry.key;
    const qty = typeof entry === 'string' ? 1 : entry.qty;
    const offer = prepared.offers[key];
    const params = {
      provider_code: 'product',
      global_offer_uuid: offer.uuid,
      legacy_product_id: offer.product_id,
      qty,
      selection: { plan: 'p2e002' },
    };
    if (token) {
      params.guest_token = token;
    }
    const added = await api(page, 'cart', 'addV2', params);
    expect(successOf(added), `cart.addV2 ${key}: ${JSON.stringify(added)}`).toBeTruthy();
  }
}

async function assertTrustedCartVisible(page, token, expectedItemCount) {
  const cookies = await page.context().cookies();
  const guestCookie = cookies.find((cookie) => cookie.name === 'weline_cart_guest_token');
  expect(guestCookie && guestCookie.value).toBe(token);

  const current = dataOf(await api(page, 'cart', 'getV2Cart'));
  expect(Number(current.item_count || 0), JSON.stringify(current)).toBe(expectedItemCount);
  expect(String(current.owner_id || ''), JSON.stringify(current)).toBe(token);
  expect(String(current.scope_key || ''), JSON.stringify(current)).toMatch(/^channel\|/);
}

async function freeze(page, prepared, extra = {}) {
  return checkoutPayload(await api(page, 'checkout', 'freezeQuote', {
    address: prepared.address,
    service_code: prepared.service_code,
    ...extra,
  }));
}

async function clearCart(page) {
  const cleared = await api(page, 'cart', 'clearV2');
  expect(successOf(cleared), `cart.clearV2: ${JSON.stringify(cleared)}`).toBeTruthy();
}

function cleanup(prepared, quoteTokens, groupUuids) {
  fixture('cleanup', {
    fixture: prepared,
    quote_tokens: quoteTokens.filter(Boolean),
    group_uuids: groupUuids.filter(Boolean),
  });
}

moduleDescribe(test, MODULE, 'P2E-002 Checkout / Shipping current-source', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-04' },
    '可信 Cart 冻结重价；伪造事实、缺模板、币种漂移和跨法务主体均 fail-closed',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      const quoteTokens = [];
      try {
        await open(page);
        const token = await guestToken(page);
        await addOffers(page, prepared, ['physical_a'], token);
        await assertTrustedCartVisible(page, token, 1);

        const frozen = await freeze(page, prepared);
        expect(frozen.success, JSON.stringify(frozen)).toBeTruthy();
        quoteTokens.push(frozen.quote_token);
        const payload = frozen.data;
        expect(payload.orders[0].items[0].unit_price_minor).toBe(
          prepared.offers.physical_a.unit_price_minor,
        );
        expect(payload.orders[0].items[0].split_key).toBeUndefined();
        expect(payload.orders[0].split_key).toBe(prepared.offers.physical_a.split_key);
        expect(String(payload.cart_hash || '')).toMatch(/^[a-f0-9]{64}$/);

        const forged = await freeze(page, prepared, {
          client_hints: {
            lines: [{ unit_price_minor: 1, split_key: 'evil' }],
            scope: { website_id: 999 },
            customer_id: 999,
          },
        });
        expect(forged.success, JSON.stringify(forged)).toBeFalsy();
        expect(forged.error_code).toBe('checkout_client_fact_rejected');

        const missing = await freeze(page, prepared, {
          address: { country_code: 'YY' },
        });
        expect(missing.success, JSON.stringify(missing)).toBeFalsy();
        expect(missing.error_code).toBe('shipping_quote_service_unavailable');

        await clearCart(page);
        await addOffers(page, prepared, ['blocked_a', 'blocked_b'], token);
        const blocked = await freeze(page, prepared);
        expect(blocked.success, JSON.stringify(blocked)).toBeFalsy();
        expect(blocked.error_code).toBe('checkout_shipping_combo_blocked');

        await clearCart(page);
        await addOffers(page, prepared, ['usd'], token);
        const currency = await freeze(page, prepared);
        expect(currency.success, JSON.stringify(currency)).toBeFalsy();
        expect(currency.error_code).toBe('checkout_cart_currency_conflict');
      } finally {
        try {
          await clearCart(page);
        } catch (_) {
        }
        cleanup(prepared, quoteTokens, []);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-05' },
    '配置漂移拒绝旧 token；同 key 重放同组，不同 key 冲突且只提交一组',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      const quoteTokens = [];
      const groupUuids = [];
      try {
        await open(page);
        const token = await guestToken(page);
        await addOffers(page, prepared, ['physical_a'], token);

        const old = await freeze(page, prepared);
        expect(old.success, JSON.stringify(old)).toBeTruthy();
        quoteTokens.push(old.quote_token);
        const mutated = fixture('mutate_shipping', { fixture: prepared });
        expect(mutated.config_version).not.toBe(prepared.config_version);
        const oldSubmit = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: old.quote_token,
          idempotency_key: `${prepared.run}-old`,
        }));
        expect(oldSubmit.success, JSON.stringify(oldSubmit)).toBeFalsy();
        expect(oldSubmit.error_code).toBe('checkout_quote_token_conflict');

        const valid = await freeze(page, prepared);
        expect(valid.success, JSON.stringify(valid)).toBeTruthy();
        quoteTokens.push(valid.quote_token);
        const key = `${prepared.run}-valid`;
        const first = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: valid.quote_token,
          idempotency_key: key,
        }));
        expect(first.success, JSON.stringify(first)).toBeTruthy();
        groupUuids.push(first.checkout_group_uuid);
        expect(first.replayed).toBeFalsy();

        const replay = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: valid.quote_token,
          idempotency_key: key,
        }));
        expect(replay.success, JSON.stringify(replay)).toBeTruthy();
        expect(replay.replayed).toBeTruthy();
        expect(replay.checkout_group_uuid).toBe(first.checkout_group_uuid);

        const otherKey = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: valid.quote_token,
          idempotency_key: `${key}-other`,
        }));
        expect(otherKey.success, JSON.stringify(otherKey)).toBeFalsy();
        expect(otherKey.error_code).toBe('checkout_quote_token_conflict');

        const db = fixture('verify', {
          quote_token: valid.quote_token,
          checkout_group_uuid: first.checkout_group_uuid,
        }).data;
        expect(db.session_state).toBe('submitted');
        expect(db.session_idempotency_key).toBe(key);
        expect(db.group_count).toBe(1);
        expect(db.order_count).toBe(1);
        expect(db.reservations).toHaveLength(1);
      } finally {
        try {
          await clearCart(page);
        } catch (_) {
        }
        cleanup(prepared, quoteTokens, groupUuids);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-06' },
    '双物理 split 单次 Quote、owner 100/0、精确分摊、真实预占及失败原子回滚',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      const quoteTokens = [];
      const groupUuids = [];
      try {
        await open(page);
        const token = await guestToken(page);
        await addOffers(page, prepared, [
          { key: 'physical_a', qty: 2 },
          { key: 'physical_b', qty: 1 },
        ], token);
        const frozen = await freeze(page, prepared);
        expect(frozen.success, JSON.stringify(frozen)).toBeTruthy();
        quoteTokens.push(frozen.quote_token);
        const payload = frozen.data;
        expect(payload.quote.amount_minor).toBe(prepared.shipping_amount_minor);
        expect(payload.allocation.owner_index).toBe(0);
        expect(payload.allocation.order_shipping_minor).toEqual([
          prepared.shipping_amount_minor,
          0,
        ]);
        expect(
          Object.values(payload.allocation.owner_item_shipping_minor)
            .reduce((sum, amount) => sum + amount, 0),
        ).toBe(prepared.shipping_amount_minor);

        const submitted = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: frozen.quote_token,
          idempotency_key: `${prepared.run}-split`,
        }));
        expect(submitted.success, JSON.stringify(submitted)).toBeTruthy();
        groupUuids.push(submitted.checkout_group_uuid);
        const db = fixture('verify', {
          quote_token: frozen.quote_token,
          checkout_group_uuid: submitted.checkout_group_uuid,
        }).data;
        expect(db.order_count).toBe(2);
        expect(db.orders.filter((order) => order.is_shipping_charge_owner)).toHaveLength(1);
        expect(db.orders[0].money.shipping_amount_minor).toBe(prepared.shipping_amount_minor);
        expect(db.orders[1].money.shipping_amount_minor).toBe(0);
        expect(db.reservations).toHaveLength(2);
        expect(db.reservations.map((row) => row.quantity_minor).sort()).toEqual([1, 2]);
        expect(db.reservations.every((row) => row.found && row.state === 'reserved')).toBeTruthy();

        const atomic = fixture('atomic_failure', { fixture: prepared }).data;
        expect(atomic.error).toContain('p2e002_injected_order_failure');
        expect(atomic.after).toEqual(atomic.before);
        expect(atomic.session_state_after_failure).toBe('quoted');
      } finally {
        try {
          await clearCart(page);
        } catch (_) {
        }
        cleanup(prepared, quoteTokens, groupUuids);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-07' },
    '金额和 customer_id 伪造被拒；Order 客户来自登录会话，Tax 精确为 none/none/0',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      const quoteTokens = [];
      const groupUuids = [];
      let loggedIn = false;
      try {
        await open(page);
        const login = await api(page, 'account', 'login', {
          email: prepared.email,
          username: prepared.email,
          password: prepared.password,
        });
        expect(successOf(login), JSON.stringify(login)).toBeTruthy();
        loggedIn = true;
        await addOffers(page, prepared, ['physical_a']);

        const money = await freeze(page, prepared, {
          client_hints: {
            shipping_amount_minor: 1,
            tax_amount_minor: 2,
            grand_total_minor: 3,
          },
        });
        expect(money.success, JSON.stringify(money)).toBeFalsy();
        expect(money.error_code).toBe('checkout_client_money_rejected');

        const identity = await freeze(page, prepared, {
          client_hints: { customer_id: prepared.customer_id + 999 },
        });
        expect(identity.success, JSON.stringify(identity)).toBeFalsy();
        expect(identity.error_code).toBe('checkout_client_fact_rejected');

        const frozen = await freeze(page, prepared);
        expect(frozen.success, JSON.stringify(frozen)).toBeTruthy();
        quoteTokens.push(frozen.quote_token);
        expect(frozen.data.customer_id).toBe(prepared.customer_id);
        expect(frozen.data.tax.mode).toBe('none');
        expect(frozen.data.tax.engine).toBe('none');
        expect(frozen.data.tax.tax_amount_minor).toBe(0);

        const submitted = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: frozen.quote_token,
          idempotency_key: `${prepared.run}-identity`,
        }));
        expect(submitted.success, JSON.stringify(submitted)).toBeTruthy();
        groupUuids.push(submitted.checkout_group_uuid);
        const db = fixture('verify', {
          quote_token: frozen.quote_token,
          checkout_group_uuid: submitted.checkout_group_uuid,
        }).data;
        expect(Number(db.orders[0].customer_id)).toBe(prepared.customer_id);
        expect(db.orders[0].tax.mode).toBe('none');
        expect(db.orders[0].tax.engine).toBe('none');
        expect(db.orders[0].tax.tax_amount_minor).toBe(0);
      } finally {
        try {
          await clearCart(page);
        } catch (_) {
        }
        if (loggedIn) {
          try {
            await api(page, 'account', 'logout');
          } catch (_) {
          }
        }
        cleanup(prepared, quoteTokens, groupUuids);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2E-08' },
    '全虚拟为零运费零预占；混合组只预占物理行且仍只有一个组 Quote',
    async ({ page }) => {
      const prepared = fixture('prepare').fixture;
      const quoteTokens = [];
      const groupUuids = [];
      try {
        await open(page);
        const token = await guestToken(page);
        await addOffers(page, prepared, ['virtual'], token);
        const virtual = await freeze(page, prepared);
        expect(virtual.success, JSON.stringify(virtual)).toBeTruthy();
        quoteTokens.push(virtual.quote_token);
        expect(virtual.data.quote.amount_minor).toBe(0);
        expect(virtual.data.quote.free_reason).toBe('virtual_only');
        expect(virtual.data.allocation.owner_index).toBeNull();
        const virtualSubmit = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: virtual.quote_token,
          idempotency_key: `${prepared.run}-virtual`,
        }));
        expect(virtualSubmit.success, JSON.stringify(virtualSubmit)).toBeTruthy();
        groupUuids.push(virtualSubmit.checkout_group_uuid);
        const virtualDb = fixture('verify', {
          quote_token: virtual.quote_token,
          checkout_group_uuid: virtualSubmit.checkout_group_uuid,
        }).data;
        expect(virtualDb.reservations).toHaveLength(0);

        await clearCart(page);
        await addOffers(page, prepared, ['virtual', 'physical_a'], token);
        const mixed = await freeze(page, prepared);
        expect(mixed.success, JSON.stringify(mixed)).toBeTruthy();
        quoteTokens.push(mixed.quote_token);
        expect(mixed.data.quote.amount_minor).toBe(prepared.shipping_amount_minor);
        expect(mixed.data.orders).toHaveLength(2);
        const mixedSubmit = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: mixed.quote_token,
          idempotency_key: `${prepared.run}-mixed`,
        }));
        expect(mixedSubmit.success, JSON.stringify(mixedSubmit)).toBeTruthy();
        groupUuids.push(mixedSubmit.checkout_group_uuid);
        const mixedDb = fixture('verify', {
          quote_token: mixed.quote_token,
          checkout_group_uuid: mixedSubmit.checkout_group_uuid,
        }).data;
        expect(mixedDb.reservations).toHaveLength(1);
        expect(mixedDb.reservations[0].offer_id).toBe(prepared.offers.physical_a.offer_id);
      } finally {
        try {
          await clearCart(page);
        } catch (_) {
        }
        cleanup(prepared, quoteTokens, groupUuids);
      }
    },
  );
});
