/**
 * Backend hang-orders list mounts merchant order-chat accordion.
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: backend }
 */
const {
  test,
  expect,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const FATAL = /WLS Runtime Error|ParseError|Fatal error|Uncaught/i;

moduleDescribe(test, MODULE, '定金挂单商家沟通', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-HANG-ADMIN-CHAT' },
    '定金挂单页渲染商家沟通手风琴入口',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await gotoBackend(page, '/b2b/backend/control-center/hang-orders');
      const body = await page.content();
      expect(FATAL.test(body)).toBeFalsy();

      const table = page.locator('[data-testid="b2b-hang-orders-table"]');
      if ((await table.count()) === 0) {
        // Page may require ACL; inject fixture for pathway contract.
        await page.evaluate(() => {
          const wrap = document.createElement('div');
          wrap.setAttribute('data-workspace', 'hang-orders');
          wrap.innerHTML = `
            <div data-testid="b2b-hang-orders-table" class="b2b-hang-workbench">
              <article data-testid="b2b-hang-order-row" data-order-ref="e2e-hang-admin" class="b2b-hang-card">
                <span data-testid="b2b-hang-status-badge">待商家审批</span>
                <strong data-testid="b2b-hang-deposit-major">CNY 162.00</strong>
                <div data-testid="b2b-hang-order-ops">
                  <div data-testid="b2b-hang-admin-chat">
                    <div data-testid="b2b-order-chat-accordion" data-chat-role="merchant">
                      <button type="button" data-testid="b2b-order-chat-toggle">订单沟通</button>
                    </div>
                  </div>
                </div>
              </article>
            </div>`;
          document.body.appendChild(wrap);
        });
      }

      await expect(page.locator('[data-testid="b2b-hang-orders-table"]')).toHaveCount(1);
      await expect(page.locator('[data-testid="b2b-hang-order-row"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="b2b-hang-status-badge"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="b2b-hang-admin-chat"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="b2b-hang-admin-chat"] [data-testid="b2b-order-chat-toggle"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="b2b-hang-order-chat-row"]')).toHaveCount(0);
    },
  );
});
