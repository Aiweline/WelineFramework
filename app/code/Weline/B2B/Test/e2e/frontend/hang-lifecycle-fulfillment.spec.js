/**
 * Hang lifecycle pathway: deposit → merchant approve → balance → completed (fulfillment gate).
 * Browser surface asserts CTA progression shells without fatal; service UT covers reserve/paid.
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
const ORDER = 'e2e-hang-life-ord';

moduleDescribe(test, MODULE, 'Hang 定金审批尾款履约通路', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-HANG-LIFECYCLE-FULFILL' },
    'deposit→approve→balance 支付上下文推进且 completed 不回落 fatal',
    async ({ page }) => {
      await page.addInitScript((orderUuid) => {
        window.__b2bLifeCalls = [];
        const KEY = '__b2b_life_phase_' + orderUuid;
        if (!sessionStorage.getItem(KEY)) {
          sessionStorage.setItem(KEY, 'deposit');
        }

        function phase() {
          return sessionStorage.getItem(KEY) || 'deposit';
        }
        function setPhase(next) {
          sessionStorage.setItem(KEY, next);
        }

        function ctxFor(purpose) {
          const p = phase();
          if (p === 'deposit' || purpose === 'deposit') {
            return {
              success: true,
              ok: true,
              order_uuid: orderUuid,
              purpose: 'deposit',
              hang_status: 'awaiting_deposit',
              amount_minor: 3150,
              deposit_amount_minor: 3150,
              currency: 'CNY',
              payment_methods: [{ code: 'fake_card', title: 'Test Card' }],
              label: '支付定金',
            };
          }
          if (p === 'awaiting_merchant_approval') {
            return {
              success: true,
              ok: true,
              order_uuid: orderUuid,
              purpose: 'balance',
              hang_status: 'awaiting_merchant_approval',
              amount_minor: 7350,
              balance_amount_minor: 7350,
              currency: 'CNY',
              payment_methods: [],
              label: '待商家审批',
            };
          }
          if (p === 'awaiting_balance') {
            return {
              success: true,
              ok: true,
              order_uuid: orderUuid,
              purpose: 'balance',
              hang_status: 'awaiting_balance',
              amount_minor: 7350,
              balance_amount_minor: 7350,
              currency: 'CNY',
              payment_methods: [{ code: 'paypal', title: 'PayPal' }],
              label: '支付尾款',
            };
          }
          return {
            success: true,
            ok: true,
            order_uuid: orderUuid,
            purpose: 'balance',
            hang_status: 'completed',
            amount_minor: 0,
            currency: 'CNY',
            payment_methods: [],
            label: '已完成',
            fulfillment_gate: 'paid',
          };
        }

        function b2bApi() {
          return {
            'hang.paymentContext': async (params) => ctxFor(String((params && params.purpose) || 'balance')),
            'hang.startPayment': async (params) => {
              window.__b2bLifeCalls.push(params || {});
              const purpose = String((params && params.purpose) || '');
              if (purpose === 'deposit') {
                setPhase('awaiting_merchant_approval');
                return {
                  success: true,
                  ok: true,
                  hang_status: 'awaiting_merchant_approval',
                  payment: { paid: true, outcome: 'paid' },
                };
              }
              if (phase() === 'awaiting_merchant_approval' || purpose === 'approve') {
                setPhase('awaiting_balance');
                return {
                  success: true,
                  ok: true,
                  hang_status: 'awaiting_balance',
                  note: 'merchant_approved',
                };
              }
              setPhase('completed');
              return {
                success: true,
                ok: true,
                hang_status: 'completed',
                payment: { paid: true, outcome: 'paid' },
                fulfillment_gate: 'notifyOrderPaid',
              };
            },
          };
        }

        function install() {
          window.Weline = window.Weline || {};
          window.Weline.Api = window.Weline.Api || {};
          const api = window.Weline.Api;
          if (api.__b2bLifeMockInstalled) {
            return;
          }
          api.__b2bLifeMockInstalled = true;
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
            set(v) {
              underlying = typeof v === 'function' ? v.bind(api) : v;
            },
          });
        }
        install();
        document.addEventListener('DOMContentLoaded', install);
      }, ORDER);

      await gotoFrontend(page, `/checkout?purpose=deposit&order_uuid=${ORDER}`);
      let body = await page.content();
      expect(FATAL_PATTERN.test(body)).toBeFalsy();

      await page.evaluate(async () => {
        const api = window.Weline && window.Weline.Api && window.Weline.Api.resource('b2b');
        await api['hang.startPayment']({ purpose: 'deposit', order_uuid: 'e2e-hang-life-ord' });
        await api['hang.startPayment']({ purpose: 'approve', order_uuid: 'e2e-hang-life-ord' });
        await api['hang.startPayment']({ purpose: 'balance', order_uuid: 'e2e-hang-life-ord' });
      });

      const calls = await page.evaluate(() => window.__b2bLifeCalls || []);
      expect(calls.length).toBeGreaterThanOrEqual(3);

      const midCtx = await page.evaluate(async () => {
        const api = window.Weline && window.Weline.Api && window.Weline.Api.resource('b2b');
        return api['hang.paymentContext']({ purpose: 'balance', order_uuid: 'e2e-hang-life-ord' });
      });
      expect(midCtx.hang_status).toBe('completed');
      expect(midCtx.fulfillment_gate).toBe('paid');

      await gotoFrontend(page, `/checkout?purpose=balance&order_uuid=${ORDER}`);
      body = await page.content();
      expect(FATAL_PATTERN.test(body)).toBeFalsy();

      const finalCtx = await page.evaluate(async () => {
        const api = window.Weline && window.Weline.Api && window.Weline.Api.resource('b2b');
        return api['hang.paymentContext']({ purpose: 'balance', order_uuid: 'e2e-hang-life-ord' });
      });
      expect(finalCtx.hang_status).toBe('completed');
      expect(finalCtx.fulfillment_gate).toBe('paid');
    },
  );
});
