/**
 * 货源配置：无法履约自动退款开关（默认开）
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
const CONFIG_URL = 'dropship/backend/config?target_scope=default.default.default';

moduleDescribe(test, MODULE, '货源无法履约自动退款开关', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-AUTO-REFUND-SWITCH-001' }, 'Config 页露出自动退款开关且默认文案可见', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, CONFIG_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-dropship-admin="config"]')).toBeVisible({ timeout: 30000 });
    const field = page.locator('[data-testid="config-embed-field"][data-config-key="dropship/ops/auto_refund_on_push_fail"]');
    await expect(field).toBeVisible({ timeout: 30000 });
    await expect(field).toContainText('推单无法履约时自动退款并通知顾客');
    await expect(field.locator('input[type="checkbox"]')).toHaveCount(1);
  });
});
