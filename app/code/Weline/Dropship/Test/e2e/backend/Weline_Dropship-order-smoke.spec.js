/**
 * 履约订单页人性化 + 商品手风琴
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: smoke, layer: backend }
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
const ORDER_URL = 'dropship/backend/order/index';

moduleDescribe(test, MODULE, '代发订单页冒烟', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-ORDER-SMOKE-001' }, '后台履约订单页中文列与手风琴商品', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, ORDER_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const root = page.locator('[data-testid="dropship-orders"]');
    await expect(root).toBeVisible({ timeout: 30000 });
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);
    await expect(root).toContainText('履约单');
    await expect(root).toContainText('推送队列');
    await expect(root).toContainText('本站订单');
    await expect(root).not.toContainText('>Outbox');
    await expect(page.locator('[data-dropship-order-accordion="1"]')).toBeVisible();

    const expandable = page.locator('[data-testid="dropship-order-row"][data-order-expandable="1"]').first();
    const count = await page.locator('[data-testid="dropship-order-row"][data-order-expandable="1"]').count();
    if (count < 1) {
      test.info().annotations.push({ type: 'note', description: 'no expandable fulfillment rows' });
      return;
    }
    await expandable.click();
    const detail = page.locator('[data-testid="dropship-order-detail"].is-open').first();
    await expect(detail).toBeVisible({ timeout: 15000 });
    await expect(detail.locator('[data-testid="dropship-order-lines"], [data-testid="dropship-order-lines-empty"]')).toBeVisible({ timeout: 15000 });
    const lines = detail.locator('[data-testid="dropship-order-lines"]');
    if (await lines.count()) {
      await expect(
        detail.locator('[data-testid="dropship-order-line-thumb"], [data-testid="dropship-order-line-thumb-ph"]').first()
      ).toBeVisible({ timeout: 10000 });
    }
    const hint = expandable.locator('[data-testid="dropship-order-expand-hint"]');
    await expect(hint.locator('[data-order-expand-label]')).toContainText(/收起/);
  });
});
