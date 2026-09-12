/**
 * 货源配置：库存/价格跟随开关挂在 config:embed
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

moduleDescribe(test, MODULE, '货源配置跟随开关', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-CONFIG-FOLLOW-001' }, 'Config 页露出库存/价格跟随开关', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, CONFIG_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-dropship-admin="config"]')).toBeVisible({ timeout: 30000 });
    const follow = page.locator('[data-testid="config-embed-field"][data-config-key="dropship/ops/follow_enabled"]');
    await expect(follow).toBeVisible({ timeout: 30000 });
    await expect(follow).toContainText('启用库存/价格跟随');
    await expect(follow).toContainText('同步远程库存与价格');
    await expect(follow.locator('input[type="checkbox"]')).toHaveCount(1);
  });
});
