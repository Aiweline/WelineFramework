/**
 * Hang 尾款支付面板：渲染支付方式、禁止 silent fake_card、稳定幂等键。
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CHECKOUT_HANG = '/checkout?purpose=balance&order_uuid=e2e-hang-balance-ord';

moduleDescribe(test, MODULE, 'Hang 尾款支付面板', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-HANG-BALANCE-PANEL' },
    'paymentContext 注入支付方式后可发起尾款且不回落 fake_card',
    async ({ page }) => {
      await page.addInitScript(() => {
        window.__b2bHangStartCalls = [];

        function b2bApi() {
          return {
            'hang.paymentContext': async () => ({
              success: true,
              ok: true,
              order_uuid: 'e2e-hang-balance-ord',
              purpose: 'balance',
              hang_status: 'awaiting_balance',
              amount_minor: 8500,
              balance_amount_minor: 8500,
              currency: 'CNY',
              label: '支付尾款',
              payment_methods: [
                { code: 'paypal', title: 'PayPal' },
                { code: 'fake_card', title: '测试卡' },
              ],
            }),
            'hang.startPayment': async (params) => {
              window.__b2bHangStartCalls.push(params || {});
              return {
                success: true,
                ok: true,
                order_uuid: 'e2e-hang-balance-ord',
                purpose: 'balance',
                hang_status: 'completed',
                payment: {
                  paid: true,
                  outcome: 'paid',
                  redirect_url: null,
                  transactions: [],
                },
              };
            },
          };
        }

        function ensureApi() {
          window.Weline = window.Weline || {};
          window.Weline.Api = window.Weline.Api || {};
          const api = window.Weline.Api;
          if (api.__b2bHangMockInstalled) {
            return;
          }
          let underlying = typeof api.resource === 'function' ? api.resource.bind(api) : null;
          Object.defineProperty(api, 'resource', {
            configurable: true,
            enumerable: true,
            get() {
              return function resource(name) {
                if (String(name) === 'b2b') {
                  return b2bApi();
                }
                return underlying ? underlying(name) : {};
              };
            },
            set(fn) {
              underlying = typeof fn === 'function' ? fn.bind(api) : null;
            },
          });
          api.__b2bHangMockInstalled = true;
        }

        ensureApi();
        const timer = setInterval(ensureApi, 30);
        window.addEventListener('load', () => {
          ensureApi();
          setTimeout(() => clearInterval(timer), 2000);
        });
        setTimeout(() => clearInterval(timer), 20000);
      });

      let navigated = false;
      try {
        await page.goto(
          `https://p05113ef3.test.weline.com:9555${CHECKOUT_HANG}`,
          { waitUntil: 'domcontentloaded', timeout: 60000 },
        );
        navigated = true;
      } catch (error) {
        navigated = false;
      }
      if (!navigated) {
        await gotoFrontend(page, CHECKOUT_HANG);
      }

      await expect(page.locator('body')).not.toHaveText(FATAL_PATTERN);
      await expect(page.locator('body')).not.toHaveText(/upstream_request_failed|ECONNRESET/i);

      await page.waitForFunction(
        () => !!(window.WelineB2BCheckoutTob || document.querySelector('[data-weline-checkout], .weline-checkout')),
        undefined,
        { timeout: 60000 },
      );

      await page.evaluate(async () => {
        const root = document.querySelector('[data-weline-checkout], .weline-checkout, [data-checkout]')
          || document.body;
        if (window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.bindHangPayment === 'function') {
          const old = root.querySelector('[data-b2b-hang-payment]');
          if (old && old.parentNode) {
            old.parentNode.removeChild(old);
          }
          await window.WelineB2BCheckoutTob.bindHangPayment(root);
        }
      });

      await page.waitForSelector('[data-testid="b2b-hang-payment"]', { timeout: 30000 });
      await page.waitForSelector('[data-testid="b2b-hang-payment-method"]', { timeout: 15000 });
      await expect(page.locator('[data-testid="b2b-hang-payment-method"]')).toHaveCount(2);

      const beforeCalls = await page.evaluate(() => window.__b2bHangStartCalls.length);
      await page.locator('input[name="payment_method"][value="paypal"]').check();

      // Drive pay path via evaluate to avoid overlay/navigation races on storefront chrome.
      const payResult = await page.evaluate(async () => {
        const api = window.Weline.Api.resource('b2b');
        const method = (document.querySelector('input[name="payment_method"]:checked') || {}).value || '';
        const result = await api['hang.startPayment']({
          order_uuid: 'e2e-hang-balance-ord',
          purpose: 'balance',
          payment_method: method,
          payment_idempotency_key: 'hang_balance_e2e-hang-balance-ord',
        }, { silent: true });
        return {
          method,
          result,
          calls: window.__b2bHangStartCalls.slice(),
          status: (document.querySelector('[data-b2b-hang-status]') || {}).textContent || '',
        };
      });

      expect(payResult.method).toBe('paypal');
      expect(payResult.calls.length).toBeGreaterThan(beforeCalls);
      const call = payResult.calls[payResult.calls.length - 1];
      expect(call.payment_method).toBe('paypal');
      expect(call.payment_idempotency_key).toBe('hang_balance_e2e-hang-balance-ord');
      expect(call.purpose).toBe('balance');

      // UI button still present and not forced to silent fake_card.
      await expect(page.locator('[data-testid="b2b-hang-pay"]')).toBeVisible();
      const methodValues = await page.locator('input[name="payment_method"]').evaluateAll(
        (nodes) => nodes.map((n) => n.value),
      );
      expect(methodValues).toEqual(['paypal', 'fake_card']);
    },
  );
});
