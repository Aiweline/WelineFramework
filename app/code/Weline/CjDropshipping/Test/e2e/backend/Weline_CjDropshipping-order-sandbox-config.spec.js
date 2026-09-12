/**
 * CJ 凭证弹窗可见「订单沙盒」开关（createOrderV3 isSandbox）。
 *
 * @weline-e2e-spec { module: Weline_CjDropshipping, type: flow, layer: backend }
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

const MODULE = 'Weline_CjDropshipping';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CHANNEL_URL = 'dropship/backend/channel/index';

moduleDescribe(test, MODULE, 'CJ 订单沙盒配置', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-CJ-ORDER-SANDBOX-001' }, '货源平台 CJ 配置弹窗可见订单沙盒开关', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, CHANNEL_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    const cjRow = page.locator('tr[data-provider-code="cj"]');
    await expect(cjRow).toBeVisible({ timeout: 30000 });
    await cjRow.locator('[data-testid="dropship-config-credentials"]').click();

    const dialog = page.locator('[data-testid="dropship-cred-dialog"][data-provider-code="cj"]');
    await expect(dialog).toBeVisible({ timeout: 15000 });
    await expect(dialog.locator('[data-testid="dropship-cred-embed"]')).toBeVisible();
    await expect(dialog.getByText('订单沙盒', { exact: false })).toBeVisible({ timeout: 15000 });
    await expect(dialog.locator('body, *').filter({ hasText: /isSandbox|假支付|不扣/ }).first()).toBeVisible();
  });
});
