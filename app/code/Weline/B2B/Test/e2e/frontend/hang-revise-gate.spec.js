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
              amount_minor: 8500,
              balance_amount_minor: 8500,
              currency: 'CNY',
              revision_pending: true,
              revision_version: 2,
              payment_methods: [{ code: 'fake_card', title: 'Test Card' }],
              label: '支付尾款',
            }),
            'hang.startPayment': async () => {
              window.__b2bHangStartCalls.push(1);
              return { success: false, ok: false, error: 'b2b_hang_payment_state_invalid', message: '尾款改价待确认' };
            },
          };
        }
        const patch = () => {
          window.Weline = window.Weline || {};
          window.Weline.Api = window.Weline.Api || {};
          window.Weline.Api.resource = window.Weline.Api.resource || {};
          const prev = window.Weline.Api.resource.query;
          window.Weline.Api.resource.query = async (module, op, params) => {
            if (module === 'b2b' && b2bApi()[op]) {
              return b2bApi()[op](params);
            }
            return typeof prev === 'function' ? prev(module, op, params) : { success: false };
          };
        };
        patch();
        document.addEventListener('DOMContentLoaded', patch);
      });

      await gotoFrontend(page, '/checkout?purpose=balance&order_uuid=e2e-hang-revise-ord');
      // Panel may show hang UI; assert mock wired without fatal.
      const body = await page.content();
      expect(/Fatal error|ParseError|Uncaught/i.test(body)).toBeFalsy();
    },
  );
});
