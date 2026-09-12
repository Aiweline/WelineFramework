/**
 * 无法履约自动退款计划套件：开关 + 售后补偿列通路
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: plan-suite, layer: backend }
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

moduleDescribe(test, MODULE, '无法履约自动退款计划套件', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-AUTO-REFUND-SUITE-001' }, 'Config 开关 → 售后页补偿列闭环', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });

    await gotoBackend(page, 'dropship/backend/config?target_scope=default.default.default', {
      timeout: 90000,
      settleMs: 800,
      useProxy: false,
    });
    await waitForBackendShellReady(page);
    expect(await page.locator('body').innerText()).not.toMatch(FATAL);
    const field = page.locator('[data-testid="config-embed-field"][data-config-key="dropship/ops/auto_refund_on_push_fail"]');
    await expect(field).toBeVisible({ timeout: 30000 });
    await expect(field).toContainText('推单无法履约时自动退款并通知顾客');

    await gotoBackend(page, 'dropship/backend/aftersale/index', {
      timeout: 90000,
      settleMs: 800,
      useProxy: false,
    });
    await waitForBackendShellReady(page);
    expect(await page.locator('body').innerText()).not.toMatch(FATAL);
    await expect(page.locator('[data-testid="dropship-aftersale"]')).toBeVisible({ timeout: 30000 });
    const table = page.locator('[data-testid="dropship-aftersale-table"]');
    if ((await table.count()) > 0) {
      await expect(table.locator('th', { hasText: '补偿进度' })).toBeVisible();
    } else {
      await expect(page.locator('.w-empty')).toBeVisible();
    }
  });
});
