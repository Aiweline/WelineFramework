/**
 * 真实续付通路（acceptance_real_business_pathway）：
 * 种子未付订单 → mark cancel → #payment-recovery → resumePaymentV2 → 回报真实 order_uuid。
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
const CPAY_FIXTURE = path.resolve(__dirname, 'checkout-continue-pay-real-pathway-fixture.php');
const DIRECT = { useProxy: false };
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function fixture(action, payload = {}) {
  let stdout = '';
  let stderr = '';
  try {
    stdout = execFileSync('php', [CPAY_FIXTURE], {
      cwd: ROOT_DIR,
      input: JSON.stringify({ action, ...payload }),
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (error) {
    stdout = String(error && error.stdout || '');
    stderr = String(error && error.stderr || '');
    throw new Error(`fixture ${action} process failed: stdout=${stdout.trim()} stderr=${stderr.trim()}`);
  }
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines[lines.length - 1] || '{}');
  if (!result.ok) {
    throw new Error(`fixture ${action} failed: ${result.error || stdout}`);
  }
  return result;
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
      test.setTimeout(180000);
      const seeded = fixture('seed_unpaid_pathway').data;
      let orderUuid = String(seeded.order_uuid || '');
      let orderNumber = String(seeded.order_number || '');
      let quoteToken = String(seeded.quote_token || '');
      let idempotencyKey = String(seeded.idempotency_key || '');
      expect(orderUuid).toMatch(/^[a-f0-9-]{36}$/i);
      expect(orderNumber).toBeTruthy();
      expect(quoteToken).toBeTruthy();
      expect(Number(seeded.order_item_count || 0)).toBeGreaterThanOrEqual(1);

      try {
        const cancelReady = fixture('prepare_cancel_continue', {
          order_uuid: orderUuid,
        }).data;
        expect(cancelReady.can_retry).toBeTruthy();
        expect(String(cancelReady.continue_pay_url || '')).toContain('#payment-recovery?');
        orderNumber = String(cancelReady.order_number || orderNumber);
        quoteToken = String(cancelReady.quote_token || quoteToken);
        idempotencyKey = String(cancelReady.idempotency_key || idempotencyKey);

        const continuePath = String(cancelReady.continue_pay_url).replace(/^https?:\/\/[^/]+/i, '');
        const pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(String(err && err.message || err)));
        page.on('console', (msg) => {
          if (msg.type() === 'error') {
            pageErrors.push(msg.text());
          }
        });
        await open(page, continuePath);
        // Full checkout + continue-pay chrome (recovery island must stay hidden as entry UI).
        const chrome = page.locator('[data-checkout-continue-chrome]');
        await expect(chrome).toBeVisible({ timeout: 20000 });
        const chromeHidden = await chrome.evaluate((el) => el.hasAttribute('hidden'));
        expect(chromeHidden).toBeFalsy();
        const form = page.locator('[data-checkout-form]');
        await expect(form).toBeVisible({ timeout: 20000 });
        const recovery = page.locator('[data-checkout-payment-recovery]');
        const recoveryHidden = await recovery.evaluate((el) => el.hasAttribute('hidden'));
        expect(recoveryHidden).toBeTruthy();
        const empty = page.locator('[data-checkout-empty]');
        const emptyHidden = await empty.evaluate((el) => el.hasAttribute('hidden'));
        expect(emptyHidden).toBeTruthy();
        const notice = ((await page.locator('[data-checkout-message]').textContent()) || '');
        expect(notice).not.toMatch(/购物车为空|Shopping cart is empty|cart is empty/i);
        const submit = page.locator('[data-submit]');
        await expect(submit).toBeEnabled({ timeout: 15000 });
        const chromeText = ((await chrome.textContent()) || '');
        expect(chromeText).toContain(orderUuid);
        expect(pageErrors.filter((e) => !/favicon|third-party|Download the React/i.test(e)), JSON.stringify(pageErrors)).toEqual([]);

        // Prefer UI continue-pay path; API resume remains secondary evidence.
        const resume = checkoutPayload(await api(page, 'checkout', 'resumePaymentV2', {
          quote_token: quoteToken,
          idempotency_key: idempotencyKey,
          payment_idempotency_key: `cpay-resume-${Date.now()}`,
          payment_method: 'fake_card',
          country_code: 'CN',
        }));
        expect(resume.success, JSON.stringify(resume)).toBeTruthy();

        const verified = fixture('verify_pathway', {
          order_uuid: orderUuid,
          quote_token: quoteToken,
          idempotency_key: idempotencyKey,
        }).data;
        expect(verified.order_uuid).toBe(orderUuid);
        expect(verified.order_number).toBe(orderNumber);
        expect(Number(verified.order_item_count || 0)).toBeGreaterThanOrEqual(1);
        expect(verified.transaction_count).toBeGreaterThanOrEqual(1);
        expect(verified.history_count + verified.transaction_count).toBeGreaterThanOrEqual(2);

        // eslint-disable-next-line no-console
        console.log(JSON.stringify({
          evidence: 'acceptance_real_business_pathway',
          order_uuid: orderUuid,
          order_number: orderNumber,
          transaction_count: verified.transaction_count,
          history_count: verified.history_count,
          latest_outcome: verified.latest_outcome,
        }));
      } finally {
        try {
          fixture('cleanup_seed', {
            order_uuid: orderUuid,
            quote_token: quoteToken,
          });
        } catch (_) {
        }
      }
    },
  );
});
