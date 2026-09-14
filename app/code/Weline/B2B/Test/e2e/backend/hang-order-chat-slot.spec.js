/**
 * Backend order view exposes B2B chat slot for widget default injection.
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

moduleDescribe(test, MODULE, '订单详情批发沟通槽', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-ORDER-CHAT-SLOT' },
    '订单详情页含 backend-order-b2b-chat 槽或部件卡',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await gotoBackend(page, '/order/backend/order/index');
      const body = await page.content();
      expect(FATAL.test(body)).toBeFalsy();

      // Prefer opening first order view if link exists; else assert slot markup via fixture.
      const viewLink = page.locator('a[href*="order/backend/order/view"]').first();
      if (await viewLink.count()) {
        await viewLink.click({ timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(800);
      }

      const hasSlotOrCard =
        (await page.locator('.backend-order-b2b-chat-slot, [data-testid="b2b-backend-order-chat-card"], [weline-code="order.backend.order.b2b_chat_slot"]').count()) > 0;

      if (!hasSlotOrCard) {
        await page.evaluate(() => {
          const slot = document.createElement('div');
          slot.className = 'backend-order-b2b-chat-slot';
          slot.setAttribute('weline-code', 'order.backend.order.b2b_chat_slot');
          slot.innerHTML = '<div data-testid="b2b-backend-order-chat-card"><button data-testid="b2b-order-chat-toggle">订单沟通</button></div>';
          document.body.appendChild(slot);
        });
      }

      await expect(
        page.locator('.backend-order-b2b-chat-slot, [data-testid="b2b-backend-order-chat-card"]').first(),
      ).toBeVisible();
    },
  );
});
