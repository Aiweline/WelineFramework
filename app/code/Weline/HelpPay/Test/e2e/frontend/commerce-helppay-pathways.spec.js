/**
 * 帮付 / 快捷购买通路（录制固化）：
 * - 快捷购买：PDP → createQuickPay → startQuickPayment(fake_card) → transaction_no
 * - 找朋友代付：PDP → createHelpPay → startPayerPayment(fake_card) → transaction_no
 *
 * @weline-e2e-spec { module: Weline_HelpPay, type: pathway, layer: frontend, feature: commerce-helppay }
 * @weline-e2e-runtime wls
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const {
  prepareCustomer,
  gotoPdp,
  runQuickPayCaptureEvidence,
  runHelpPayFriendCaptureEvidence,
  assertStep,
} = require('./commerce-helppay-pathways-helpers');

const MODULE = 'Weline_HelpPay';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, '帮付与快捷购买结账通路', () => {
  test.setTimeout(360000);

  moduleCase(
    test,
    { module: MODULE, id: 'CR-HELPPAY-QUICK-ORDER' },
    '快捷购买：createQuickPay→startQuickPayment→transaction_no',
    async ({ page }) => {
      const prepared = (prepareCustomer().fixture) || {};
      const pdp = String((prepared && prepared.pdp_path) || process.env.CHECKOUT_PDP_PATH || '').trim()
        || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';

      await gotoPdp(page, gotoFrontend, pdp);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="product-quick-pay"], [data-helppay-quick-pay]').first())
        .toBeVisible({ timeout: 30000 });

      const evidence = await runQuickPayCaptureEvidence(page, { scope: 'helppay_quick' });
      assertStep(!!evidence.transaction_no, 'helppay.quick.txn', evidence);
      expect(evidence.transaction_no).toMatch(/PAY|TXN|[A-Z0-9]{8,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-HELPPAY-FRIEND-ORDER' },
    '找朋友代付：createHelpPay→startPayerPayment→transaction_no',
    async ({ page }) => {
      const prepared = (prepareCustomer().fixture) || {};
      const pdp = String((prepared && prepared.pdp_path) || process.env.CHECKOUT_PDP_PATH || '').trim()
        || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';

      await gotoPdp(page, gotoFrontend, pdp);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="product-help-pay"], [data-helppay-open], [data-testid="help-pay-open"]').first())
        .toBeVisible({ timeout: 30000 });

      const evidence = await runHelpPayFriendCaptureEvidence(page, { scope: 'helppay_friend' });
      assertStep(!!evidence.transaction_no, 'helppay.friend.txn', evidence);
      expect(evidence.transaction_no).toMatch(/PAY|TXN|[A-Z0-9]{8,}/i);
    }
  );
});
