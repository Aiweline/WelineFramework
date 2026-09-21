/**
 * PayPal Google/Apple Pay 后台开关契约（Phase 1）
 *
 * @weline-e2e-spec { module: Weline_Payment, type: e2e, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Payment';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

async function openPayPalMethodConfig(page) {
  const routes = [
    'payment/backend/method/edit?code=paypal&target_scope=default.__website__.default',
    'payment/backend/method/edit?code=paypal&target_scope=default.default.default',
    `${buildModuleBackendRoute(MODULE, 'method/edit')}?code=paypal&target_scope=default.default.default`,
    `${buildModuleBackendRoute(MODULE, 'method')}?target_scope=default.default.default`,
  ];
  for (const route of routes) {
    await gotoBackend(page, route, { timeout: 60000, settleMs: 800 });
    await waitForBackendShellReady(page);
    const body = await page.locator('body').innerHTML();
    if (FATAL.test(body)) {
      continue;
    }
    if (
      body.includes('google_pay_enabled')
      || body.includes('启用 Google Pay')
      || body.includes('Apple Pay')
      || /PayPal/i.test(body)
    ) {
      return body;
    }
  }
  return await page.locator('body').innerHTML();
}

moduleDescribe(test, MODULE, 'Weline_Payment PayPal Google/Apple Pay 开关', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-PAYPAL-WALLET-CFG-001' },
    '后台 PayPal 配置可见 Google/Apple Pay 开关',
    async ({ page }) => {
      await loginAsAdmin(page);
      const body = await openPayPalMethodConfig(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      expect(
        body.includes('google_pay_enabled') || body.includes('启用 Google Pay') || body.includes('Google Pay')
      ).toBeTruthy();
      expect(
        body.includes('apple_pay_enabled') || body.includes('启用 Apple Pay') || body.includes('Apple Pay')
      ).toBeTruthy();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-plan-suite' },
    '计划链路：PayPal 钱包开关合同',
    async ({ page }) => {
      await loginAsAdmin(page);
      const body = await openPayPalMethodConfig(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      expect(/Google Pay|google_pay/i.test(body)).toBeTruthy();
      expect(/Apple Pay|apple_pay/i.test(body)).toBeTruthy();
    }
  );
});
