(function () {
    'use strict';

    var saveTimer = null;

    async function orderApi() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return window.Weline.Api.resource('order');
    }

    async function init(root) {
        if (!root || root.dataset.orderNoticeReady === '1') {
            return;
        }
        root.dataset.orderNoticeReady = '1';

        var input = root.querySelector('[data-order-notice-input]');
        var messageEl = root.querySelector('[data-order-notice-message]');
        if (!input || !window.Weline || !window.Weline.Api) {
            return;
        }

        var client;
        try {
            client = await orderApi();
        } catch (e) {
            return;
        }

        function setMessage(text, isError) {
            if (!messageEl) {
                return;
            }
            messageEl.textContent = text || '';
            messageEl.classList.toggle('is-error', !!isError);
        }

        function scheduleSave() {
            if (saveTimer) {
                clearTimeout(saveTimer);
            }
            saveTimer = setTimeout(function () {
                saveTimer = null;
                var remark = String(input.value || '');
                client.saveCheckoutRemark({ remark: remark }).then(function (response) {
                    if (!response || !response.success) {
                        setMessage((response && response.message) || '保存失败', true);
                        return;
                    }
                    setMessage(response.message || '', false);
                }).catch(function () {
                    setMessage('订单服务暂时不可用', true);
                });
            }, 400);
        }

        client.getCheckoutRemark({}).then(function (response) {
            if (response && response.success && typeof response.remark === 'string') {
                input.value = response.remark;
            }
        }).catch(function () {
            // ignore preload failures
        });

        input.addEventListener('input', scheduleSave);
        input.addEventListener('blur', scheduleSave);
    }

    function boot() {
        document.querySelectorAll('[data-testid="order-notice-widget"]').forEach(function (root) {
            init(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
