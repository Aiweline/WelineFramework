/**
 * Hang balance revision gate reflected in paymentContext (mock).
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
const CHECKOUT_HANG = '/checkout?purpose=balance&order_uuid=e2e-hang-revise-ord';

moduleDescribe(test, MODULE, '尾款改价待确认挡支付', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-HANG-REVISE-GATE' },
    'paymentContext revision_pending=true 时面板提示不可支付',
    async ({ page }) => {
      await page.addInitScript(() => {
        window.__b2bHangStartCalls = [];

        function b2bApi() {
          return {
            'hang.paymentContext': async () => ({
              success: true,
              ok: true,
              order_uuid: 'e2e-hang-revise-ord',
              purpose: 'balance',
              hang_status: 'awaiting_balance',
              can_pay: false,
              view_state: 'revision_pending',
              message: '尾款改价待确认，暂不可支付',
              amount_minor: 8500,
              balance_amount_minor: 8500,
              currency: 'CNY',
              revision_pending: true,
              revision_version: 2,
              payment_methods: [],
              label: '支付尾款',
              order_summary: {
                display_number: 'HANG-REV-8500',
                order_uuid: 'e2e-hang-revise-ord',
                hang_status: 'awaiting_balance',
                hang_status_label: '待付尾款',
                lines: [],
                goods_subtotal_minor: 12000,
                deposit_amount_minor: 3500,
                balance_amount_minor: 8500,
                payable_minor: 8500,
              },
            }),
            'hang.startPayment': async () => {
              window.__b2bHangStartCalls.push(1);
              return {
                success: false,
                ok: false,
                error: 'b2b_hang_payment_state_invalid',
                message: '尾款改价待确认',
              };
            },
          };
        }

        function ensureApi() {
          window.Weline = window.Weline || {};
          window.Weline.Api = window.Weline.Api || {};
          const api = window.Weline.Api;
          if (api.__b2bHangReviseMockInstalled) {
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
          api.__b2bHangReviseMockInstalled = true;
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
      await expect(page.locator('[data-b2b-hang-status]')).toContainText('尾款改价待确认，暂不可支付');
      await expect(page.locator('[data-testid="b2b-hang-pay"]')).toBeDisabled();
      await expect(page.locator('[data-hang-view-state]')).toHaveAttribute('data-hang-view-state', 'revision_pending');
    },
  );
});
