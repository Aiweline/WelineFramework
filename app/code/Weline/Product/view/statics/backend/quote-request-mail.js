/**
 * 商品询价后台：企业邮箱回复入口。
 * 由 data-weline-load="productQuoteMail" 声明加载，禁止页面内联业务脚本。
 */
(function () {
    'use strict';

    function bind() {
        document.querySelectorAll('[data-quote-mail-open]').forEach(function (btn) {
            if (btn.getAttribute('data-weline-bound') === '1') {
                return;
            }
            btn.setAttribute('data-weline-bound', '1');
            btn.addEventListener('click', function () {
                var raw = btn.getAttribute('data-composer') || '{}';
                var payload = {};
                try {
                    payload = JSON.parse(raw);
                } catch (e) {
                    payload = {};
                }
                var wrap = btn.closest('[data-quote-mail-pick]');
                var select = wrap ? wrap.querySelector('[data-quote-mail-account]') : null;
                if (select) {
                    payload.account_id = parseInt(select.value || '0', 10) || payload.account_id || 0;
                }
                if (window.WelineMailComposer && typeof window.WelineMailComposer.open === 'function') {
                    window.WelineMailComposer.open(payload);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    window.WelineProductQuoteMail = { bind: bind };
})();
