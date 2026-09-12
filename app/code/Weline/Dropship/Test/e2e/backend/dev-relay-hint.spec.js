/**
 * 货源 Webhook 中继说明页：生产 CJ hook URL + 链到支付 DevRelay
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: flow, layer: backend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Dropship';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, '货源 Webhook 中继说明', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-DEV-RELAY-HINT-001' }, '中继页展示生产 notify URL', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, 'dropship/backend/dev-relay', { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);
    expect(bodyText).toMatch(/Webhook|中继|DevRelay/i);
    const sandboxInput = page.locator('input.w-input[readonly]').first();
    await expect(sandboxInput).toBeVisible({ timeout: 15000 });
    await expect(sandboxInput).toHaveValue(/dropship\/frontend\/callback\/notify/);
    await expect(sandboxInput).toHaveValue(/cj\.sandbox\.default/);
  });
});
