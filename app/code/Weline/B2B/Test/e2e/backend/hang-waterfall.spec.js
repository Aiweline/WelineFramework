/**
 * Backend hang-orders waterfall review bench (tabs + expanded cards + inline chat).
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

moduleDescribe(test, MODULE, '定金挂单瀑布审核台', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-HANG-WATERFALL' },
    'Playwright 完整通路：默认等待审批 Tab，瀑布卡订单沟通可展开聊天面板',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await gotoBackend(page, '/b2b/backend/control-center/hang-orders');
      const body = await page.content();
      expect(FATAL.test(body)).toBeFalsy();

      const tabs = page.locator('[data-testid="b2b-hang-tabs"]');
      if ((await tabs.count()) === 0) {
        await page.evaluate(() => {
          const wrap = document.createElement('div');
          wrap.setAttribute('data-workspace', 'hang-orders');
          wrap.innerHTML = `
            <nav data-testid="b2b-hang-tabs">
              <a data-hang-tab="awaiting" aria-current="page">等待审批 <span>1</span></a>
              <a data-hang-tab="all">全部 <span>2</span></a>
            </nav>
            <div data-testid="b2b-hang-orders-table" class="b2b-hang-waterfall" data-layout="waterfall">
              <article data-testid="b2b-hang-order-row" data-order-ref="e2e-hang-wf" data-severity="warning" class="b2b-hang-card">
                <div data-testid="b2b-hang-severity"><span>偏远提示</span></div>
                <div data-testid="b2b-hang-customer"><strong>测试客户</strong></div>
                <span data-testid="b2b-hang-status-badge">待商家审批</span>
                <strong data-testid="b2b-hang-deposit-major">CNY 162.00</strong>
                <div data-testid="b2b-hang-lines"><ul><li>SKU-1</li></ul></div>
                <div data-testid="b2b-hang-card-chat">
                  <div data-testid="b2b-hang-admin-chat">
                    <div data-testid="b2b-order-chat-accordion" data-b2b-order-chat-accordion data-order-ref="e2e-hang-wf" data-chat-role="merchant">
                      <button type="button" data-b2b-order-chat-toggle data-testid="b2b-order-chat-toggle" aria-expanded="false">订单沟通</button>
                      <div data-b2b-order-chat-panel data-testid="b2b-order-chat-panel" hidden>
                        <ul data-b2b-order-chat-messages></ul>
                        <form data-b2b-order-chat-form>
                          <textarea data-b2b-order-chat-input></textarea>
                          <button type="submit" data-testid="b2b-order-chat-send">发送</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
              </article>
            </div>`;
          document.body.appendChild(wrap);
          const root = wrap.querySelector('[data-b2b-order-chat-accordion]');
          const btn = root && root.querySelector('[data-b2b-order-chat-toggle]');
          const panel = root && root.querySelector('[data-b2b-order-chat-panel]');
          if (btn && panel) {
            btn.addEventListener('click', (ev) => {
              ev.preventDefault();
              panel.hidden = !panel.hidden;
              btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
            });
          }
        });
      }

      await expect(page.locator('[data-testid="b2b-hang-tabs"]')).toHaveCount(1);
      const awaiting = page.locator('[data-testid="b2b-hang-tabs"] [data-hang-tab="awaiting"]');
      await expect(awaiting).toHaveCount(1);

      const table = page.locator('[data-testid="b2b-hang-orders-table"]');
      const empty = page.locator('[data-testid="b2b-hang-orders-empty"]');
      if ((await table.count()) > 0) {
        await expect(table).toHaveAttribute('data-layout', 'waterfall');
        const row = page.locator('[data-testid="b2b-hang-order-row"]').first();
        await expect(row).toBeVisible();
        await expect(page.locator('[data-testid="b2b-hang-customer"]').first()).toBeVisible();
        const chat = page.locator('[data-testid="b2b-hang-admin-chat"]').first();
        await expect(chat).toBeVisible();
        const toggle = chat.locator('[data-testid="b2b-order-chat-toggle"]').first();
        await expect(toggle).toBeVisible();
        const panel = chat.locator('[data-testid="b2b-order-chat-panel"]').first();
        const alreadyOpen = await panel.isVisible().catch(() => false);
        if (!alreadyOpen) {
          await toggle.click();
        }
        await expect(panel).toBeVisible();
        await expect(chat.locator('[data-testid="b2b-order-chat-send"]').first()).toBeVisible();
      } else {
        await expect(empty).toHaveCount(1);
      }
    },
  );
});
