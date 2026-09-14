/**
 * B2B order chat signal + poll/markSeen Query pathway (DOM inject + Query mock).
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

moduleDescribe(test, MODULE, '订单沟通角标与 Query', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-ORDER-CHAT-SIGNAL' },
    'b2b.order_chat 角标空壳可绘制且 orderChat markSeen 通路可用',
    async ({ page }) => {
      await page.addInitScript(() => {
        window.__b2bChatOps = [];
        function b2bApi() {
          return {
            'orderChat.open': async (params) => {
              window.__b2bChatOps.push(['open', params]);
              return { success: true, ok: true, thread_id: 'th_e2e', order_ref: 'ord-e2e', customer_unread: 1 };
            },
            'orderChat.messages': async (params) => {
              window.__b2bChatOps.push(['messages', params]);
              return {
                success: true,
                ok: true,
                messages: [{ message_row_id: 1, sender_role: 'merchant', body_text: 'hello' }],
              };
            },
            'orderChat.markSeen': async (params) => {
              window.__b2bChatOps.push(['markSeen', params]);
              return { success: true, ok: true, thread_id: 'th_e2e', customer_unread: 0 };
            },
            'orderChat.list': async () => ({
              success: true,
              ok: true,
              threads: [{ thread_id: 'th_e2e', order_ref: 'ord-e2e', customer_unread: 1 }],
            }),
          };
        }
        function install() {
          window.Weline = window.Weline || {};
          window.Weline.Api = window.Weline.Api || {};
          const api = window.Weline.Api;
          if (api.__b2bChatMockInstalled) return;
          api.__b2bChatMockInstalled = true;
          let underlying = typeof api.resource === 'function' ? api.resource.bind(api) : null;
          Object.defineProperty(api, 'resource', {
            configurable: true,
            enumerable: true,
            get() {
              return function resource(name) {
                if (String(name) === 'b2b') return b2bApi();
                return underlying ? underlying(name) : {};
              };
            },
            set(v) {
              underlying = typeof v === 'function' ? v.bind(api) : v;
            },
          });
        }
        install();
        document.addEventListener('DOMContentLoaded', install);
      });

      await gotoFrontend(page, '/');
      await page.evaluate(() => {
        const a = document.createElement('a');
        a.setAttribute('data-account-menu-signal', 'b2b.order_chat');
        a.setAttribute('href', '/customer/account/index#orders');
        a.innerHTML = '<span class="account-menu-signal-badge" data-account-menu-signal-badge="1" hidden aria-hidden="true"></span>';
        document.body.appendChild(a);

        const wrap = document.createElement('div');
        wrap.innerHTML = [
          '<div data-testid="b2b-order-chat-accordion" data-b2b-order-chat-accordion data-order-ref="ord-e2e" data-chat-role="customer">',
          '  <button type="button" data-b2b-order-chat-toggle aria-expanded="false">订单沟通</button>',
          '  <div data-testid="b2b-order-chat-panel" data-b2b-order-chat-panel hidden>',
          '    <ul data-b2b-order-chat-messages></ul>',
          '    <p data-b2b-order-chat-status hidden></p>',
          '    <form data-b2b-order-chat-form><input data-b2b-order-chat-input /><button data-b2b-order-chat-send type="submit">发送</button></form>',
          '  </div>',
          '</div>',
        ].join('');
        document.body.appendChild(wrap.firstElementChild);
      });
      const shell = page.locator('[data-account-menu-signal="b2b.order_chat"]');
      await expect(shell).toHaveCount(1);
      const text = await shell.innerText();
      expect(/\d/.test(text.trim())).toBeFalsy();
      await expect(page.locator('[data-testid="b2b-order-chat-accordion"]')).toHaveCount(1);
      await expect(page.locator('[data-b2b-order-chat-toggle]')).toBeVisible();

      const poll = await page.evaluate(async () => {
        const api = window.Weline.Api.resource('b2b');
        await api['orderChat.open']({ order_uuid: 'ord-e2e' });
        const msgs = await api['orderChat.messages']({ thread_id: 'th_e2e' });
        const seen = await api['orderChat.markSeen']({ thread_id: 'th_e2e', role: 'customer' });
        return { msgs, seen, ops: window.__b2bChatOps };
      });
      expect(poll.msgs.messages.length).toBe(1);
      expect(poll.seen.customer_unread).toBe(0);
      expect(poll.ops.map((x) => x[0])).toEqual(['open', 'messages', 'markSeen']);

      const body = await page.content();
      expect(/Fatal error|ParseError|Uncaught/i.test(body)).toBeFalsy();
    },
  );
});
