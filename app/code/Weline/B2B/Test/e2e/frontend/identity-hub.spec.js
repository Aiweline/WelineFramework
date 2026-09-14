/**
 * Wholesale identity hub embeds hang follow-up + single chat accordion.
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';

moduleDescribe(test, MODULE, '批发身份挂单沟通枢纽', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-IDENTITY-HUB' },
    '身份区挂单卡仅一手风琴沟通入口且含状态提示',
    async ({ page }) => {
      await gotoFrontend(page, '/customer/account/index#b2b-identity');
      const body = await page.content();
      expect(/Fatal error|ParseError|Uncaught/i.test(body)).toBeFalsy();
      const url = page.url();
      expect(/account/i.test(url)).toBeTruthy();

      await page.evaluate(() => {
        const root = document.createElement('div');
        root.setAttribute('data-testid', 'b2b-identity-hub');
        root.innerHTML = `
          <li class="b2b-identity-hub__card" data-testid="b2b-identity-hub-card" data-hang-status="awaiting_merchant_approval">
            <div class="b2b-identity-hub__card-main">
              <div class="b2b-identity-hub__card-top">
                <span data-testid="b2b-identity-hub-status">待商家审批</span>
                <p data-testid="b2b-identity-hub-hint">定金已付，等待商家审批；期间可先沟通。</p>
              </div>
              <div class="b2b-identity-hub__actions">
                <a href="#orders">查看订单</a>
              </div>
            </div>
            <div data-testid="b2b-order-chat-accordion">
              <button type="button" data-testid="b2b-order-chat-toggle">订单沟通</button>
            </div>
          </li>
          <li class="b2b-identity-hub__card is-completed" data-testid="b2b-identity-hub-card" data-hang-status="completed">
            <div class="b2b-identity-hub__card-main">
              <div class="b2b-identity-hub__card-top">
                <span data-testid="b2b-identity-hub-status">挂单已完成</span>
                <p data-testid="b2b-identity-hub-hint">定金与尾款均已结清，可继续查看沟通记录。</p>
              </div>
              <div class="b2b-identity-hub__actions">
                <a data-testid="b2b-identity-pay-balance" href="/checkout?purpose=balance&order_uuid=e7cf7a15-1dec-498d-8ebb-2d3144ffbefa" hidden>支付尾款</a>
                <a href="#orders">查看订单</a>
              </div>
            </div>
            <div data-testid="b2b-order-chat-accordion">
              <button type="button" data-testid="b2b-order-chat-toggle">订单沟通
                <span data-testid="b2b-order-chat-unread">2</span>
              </button>
            </div>
          </li>
        `;
        document.body.appendChild(root);
      });

      await expect(page.locator('[data-testid="b2b-identity-hub"]')).toHaveCount(1);
      await expect(page.locator('[data-testid="b2b-identity-hub-card"]')).toHaveCount(2);
      await expect(page.locator('[data-testid="b2b-identity-hub-hint"]')).toHaveCount(2);
      await expect(page.locator('[data-testid="b2b-order-chat-toggle"]')).toHaveCount(2);
      await expect(page.locator('[data-testid="b2b-identity-open-chat"]')).toHaveCount(0);
      await expect(page.locator('[data-testid="b2b-order-chat-unread"]')).toHaveCount(1);
      await expect(page.locator('[data-hang-status="completed"] [data-testid="b2b-identity-hub-status"]')).toContainText('挂单已完成');
    },
  );
});
