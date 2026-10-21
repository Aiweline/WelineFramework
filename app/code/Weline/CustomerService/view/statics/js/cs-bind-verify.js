/**
 * Customer service email bind verify page (Weline_CustomerService).
 * Moved from the inline <script> in templates/frontend/Bind/verify.phtml.
 * Runtime data comes from the non-executable JSON block #cs-bind-verify-config.
 * Registered as module `csBindVerify`; page root declares data-weline-load.
 */
(function () {
    'use strict';
    var CONFIG = (function () {
        var el = document.getElementById('cs-bind-verify-config');
        if (!el) { return {}; }
        try { return JSON.parse(el.textContent || '{}') || {}; } catch (e) { return {}; }
    }());

    (function () {
        var SUCCESS = CONFIG.success === true;
        var PAYLOAD = CONFIG.payload || null;
        var STORAGE_KEY = 'cs_email_bound_v1';
        var CHANNEL = 'weline-cs-email-bound';
        var HOME = CONFIG.home || '/';

        function publishBound() {
            if (!SUCCESS || !PAYLOAD || !PAYLOAD.email) {
                return;
            }
            PAYLOAD.ts = Date.now();
            try {
                if (typeof BroadcastChannel !== 'undefined') {
                    var channel = new BroadcastChannel(CHANNEL);
                    channel.postMessage(PAYLOAD);
                    channel.close();
                }
            } catch (_e) {}
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(PAYLOAD));
                // Retrigger storage listeners in other tabs even if value identical.
                localStorage.removeItem(STORAGE_KEY);
                localStorage.setItem(STORAGE_KEY, JSON.stringify(PAYLOAD));
            } catch (_e2) {}
        }

        function tryClose() {
            var status = document.getElementById('cs-verify-status');
            var closeBtn = document.getElementById('cs-verify-close');
            try {
                window.close();
            } catch (_e) {}
            setTimeout(function () {
                if (!window.closed) {
                    if (status) {
                        status.textContent = CONFIG.i18n['已同步。可关闭此页，返回原页面继续聊天。'] || '已同步。可关闭此页，返回原页面继续聊天。';
                    }
                    if (closeBtn) {
                        closeBtn.hidden = false;
                        closeBtn.addEventListener('click', function () {
                            try { window.close(); } catch (_e3) {}
                            window.location.href = HOME;
                        });
                    }
                }
            }, 450);
        }

        if (SUCCESS) {
            publishBound();
            tryClose();
        }

        document.addEventListener('click', function (event) {
            var backTrigger = event.target.closest('[data-cs-bind-back]');
            if (backTrigger) {
                event.preventDefault();
                history.back();
                return;
            }
            var chatTrigger = event.target.closest('[data-cs-bind-open-chat]');
            if (chatTrigger) {
                event.preventDefault();
                window.location.href = HOME;
            }
        });
    })();
}());
