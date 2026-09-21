/**
 * 真实续付通路（acceptance_real_business_pathway）：
 * P2E 夹具建车 → freeze/submitV2(fake_card) → mark cancel → #payment-recovery → resumePaymentV2 → 回报真实 order_uuid。
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: pathway, layer: frontend, feature: payment-cancel-continue-pay }
 * @weline-e2e-runtime wls
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
const P2E_FIXTURE = path.resolve(__dirname, 'plan-p2e002-current-source-fixture.php');
const CPAY_FIXTURE = path.resolve(__dirname, 'checkout-continue-pay-real-pathway-fixture.php');
const DIRECT = { useProxy: false };
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function fixture(script, action, payload = {}) {
  const stdout = execFileSync('php', [script], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines[lines.length - 1] || '{}');
  if (!result.ok) {
    throw new Error(`fixture ${action} failed: ${result.error || stdout}`);
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

function checkoutPayload(result) {
  if (result && result.__error) {
    return result.response && result.response.data
      ? result.response.data
      : result.response || result;
  }
  return result && result.data && result.success === undefined ? result.data : result;
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

async function open(page, route = '/') {
  await gotoFrontend(page, route, { timeout: 60000, settleMs: 500, ...DIRECT });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL);
}

moduleDescribe(test, MODULE, '真实继续支付通路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-CONTINUE-PAY-REAL-001' },
    '建单后取消恢复并 resumePaymentV2，产出真实 order_uuid',
    async ({ page }) => {
      test.setTimeout(240000);
      const prepared = fixture(P2E_FIXTURE, 'prepare').fixture;
      const quoteTokens = [];
      const groupUuids = [];
      let orderUuid = '';
      let orderNumber = '';
      let quoteToken = '';
      let idempotencyKey = '';

      try {
        await open(page, '/');
        const issued = await api(page, 'cart', 'issueGuestToken');
        expect(successOf(issued), JSON.stringify(issued)).toBeTruthy();
        const guestToken = String(dataOf(issued).guest_token || issued.guest_token || '');
        expect(guestToken).not.toBe('');
        await page.context().addCookies([{
          name: 'weline_cart_guest_token',
          value: guestToken,
          url: new URL(page.url()).origin,
          httpOnly: true,
          sameSite: 'Lax',
        }]);

        const offer = prepared.offers.physical_a;
        const added = await api(page, 'cart', 'addItem', {
          global_offer_uuid: offer.global_offer_uuid,
          qty: 1,
          guest_token: guestToken,
        });
        expect(successOf(added), JSON.stringify(added)).toBeTruthy();

        await open(page, '/checkout');
        const frozen = checkoutPayload(await api(page, 'checkout', 'freezeQuote', {
          address: prepared.address,
          service_code: prepared.service_code,
        }));
        expect(frozen.success, JSON.stringify(frozen)).toBeTruthy();
        quoteToken = String(frozen.quote_token || '');
        quoteTokens.push(quoteToken);
        expect(quoteToken).not.toBe('');

        const idem = `${prepared.run}-cpay-real`;
        const submitted = checkoutPayload(await api(page, 'checkout', 'submitV2', {
          quote_token: quoteToken,
          idempotency_key: idem,
          payment_method: 'fake_card',
          guest_token: guestToken,
        }));
        expect(submitted.success, JSON.stringify(submitted)).toBeTruthy();
        groupUuids.push(String(submitted.checkout_group_uuid || ''));
        const orderUuids = Array.isArray(submitted.order_uuids) ? submitted.order_uuids : [];
        orderUuid = String(orderUuids[0] || (submitted.data && submitted.data.order_uuids && submitted.data.order_uuids[0]) || '');
        expect(orderUuid).toMatch(/^[a-f0-9-]{36}$/i);
        idempotencyKey = idem;

        const cancelReady = fixture(CPAY_FIXTURE, 'prepare_cancel_continue', {
          order_uuid: orderUuid,
        }).data;
        expect(cancelReady.can_retry).toBeTruthy();
        expect(String(cancelReady.continue_pay_url || '')).toContain('#payment-recovery?');
        orderNumber = String(cancelReady.order_number || '');
        quoteToken = String(cancelReady.quote_token || quoteToken);
        idempotencyKey = String(cancelReady.idempotency_key || idempotencyKey);
        expect(orderNumber).toBeTruthy();

        const continuePath = String(cancelReady.continue_pay_url).replace(/^https?:\/\/[^/]+/i, '');
        await open(page, continuePath);
        const recovery = page.locator('[data-checkout-payment-recovery]');
        await expect(recovery).toBeVisible({ timeout: 20000 });
        const recoveryHidden = await recovery.evaluate((el) => el.hasAttribute('hidden'));
        expect(recoveryHidden).toBeFalsy();

        const resume = checkoutPayload(await api(page, 'checkout', 'resumePaymentV2', {
          quote_token: quoteToken,
          idempotency_key: idempotencyKey,
          payment_idempotency_key: `cpay-resume-${Date.now()}`,
          payment_method: 'fake_card',
          country_code: prepared.address.country_code || 'CN',
        }));
        expect(resume.success, JSON.stringify(resume)).toBeTruthy();

        const verified = fixture(CPAY_FIXTURE, 'verify_pathway', {
          order_uuid: orderUuid,
          quote_token: quoteToken,
          idempotency_key: idempotencyKey,
        }).data;
        expect(verified.order_uuid).toBe(orderUuid);
        expect(verified.order_number).toBe(orderNumber);
        expect(verified.transaction_count).toBeGreaterThanOrEqual(1);
        expect(verified.history_count + verified.transaction_count).toBeGreaterThanOrEqual(2);

        // eslint-disable-next-line no-console
        console.log(JSON.stringify({
          evidence: 'acceptance_real_business_pathway',
          order_uuid: orderUuid,
          order_number: orderNumber,
          checkout_group_uuid: groupUuids[0] || '',
          transaction_count: verified.transaction_count,
          history_count: verified.history_count,
          latest_outcome: verified.latest_outcome,
        }));
      } finally {
        try {
          fixture(P2E_FIXTURE, 'cleanup', {
            fixture: prepared,
            quote_tokens: quoteTokens,
            group_uuids: groupUuids,
          });
        } catch (_) {
        }
      }
    },
  );
});
