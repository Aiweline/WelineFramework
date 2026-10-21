/**
 * B2B 控制中心：挂起订单复制链接 + 订单聊天启动。
 * 由根节点 data-weline-load="b2bControlCenter" 声明加载。
 */
(function () {
    'use strict';

    function init() {
        var root = document.querySelector('[data-workspace="hang-orders"]');
        if (!root || root.getAttribute('data-copy-bound') === '1') {
            return;
        }
        root.setAttribute('data-copy-bound', '1');
        var copiedText = root.getAttribute('data-text-copied') || '';
        var copyTitle = root.getAttribute('data-text-copy-title') || '';

        root.addEventListener('click', function (event) {
            var btn = event.target && event.target.closest ? event.target.closest('[data-copy-url]') : null;
            if (!btn) return;
            var url = String(btn.getAttribute('data-copy-url') || '').trim();
            if (!url) return;
            var done = function () {
                var prev = btn.textContent;
                btn.textContent = copiedText;
                setTimeout(function () { btn.textContent = prev; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(function () {
                    window.prompt(copyTitle, url);
                });
            } else {
                window.prompt(copyTitle, url);
            }
        });

        if (window.WelineB2BOrderChat && typeof window.WelineB2BOrderChat.boot === 'function') {
            window.WelineB2BOrderChat.boot(root);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
