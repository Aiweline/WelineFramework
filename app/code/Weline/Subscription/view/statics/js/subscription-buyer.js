/**
 * 订阅买家屏：订阅/续订/取消表单的 API 提交与消息提示。
 * Registered as weline module `subscriptionBuyer`（由根节点 data-weline-load 声明）。
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-testid="subscription-buyer"]');
    if (!root) return;
    var messageNode = root.querySelector('[data-testid="subscription-buyer-message"]');
    var messageBag = {};
    try {
        messageBag = JSON.parse(document.getElementById('subscription-buyer-messages').textContent || '{}');
    } catch (error) {}

    function showMessage(text, tone) {
        if (!messageNode) return;
        messageNode.hidden = false;
        messageNode.setAttribute('data-tone', tone || 'info');
        messageNode.textContent = text;
    }

    function api() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return null;
        }
        return window.Weline.Api.resource('subscription');
    }

    root.querySelectorAll('[data-subscription-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var resource = api();
            if (!resource) {
                showMessage(messageBag.api || '', 'danger');
                return;
            }
            var action = form.getAttribute('data-subscription-form');
            var button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            showMessage(messageBag.working || '', 'info');
            var request;
            if (action === 'subscribe') {
                var data = new FormData(form);
                request = resource.subscribe({
                    provider_code: String(data.get('provider_code') || ''),
                    plan_code: String(data.get('plan_code') || ''),
                    idempotency_key: form.getAttribute('data-idempotency') || ''
                });
            } else if (action === 'renew') {
                request = resource.renew({
                    subscription_id: form.getAttribute('data-subscription-id') || ''
                });
            } else {
                request = resource.cancel({
                    subscription_id: form.getAttribute('data-subscription-id') || '',
                    expected_version: Number(form.getAttribute('data-expected-version') || 0)
                });
            }
            Promise.resolve(request).then(function (result) {
                var payload = result && result.data ? result.data : result;
                if (!payload || payload.success === false) {
                    showMessage((payload && payload.message) || messageBag.failed || '', 'danger');
                    if (button) button.disabled = false;
                    return;
                }
                if (action === 'renew') {
                    showMessage(messageBag.renewed || '', 'success');
                    if (button) button.disabled = false;
                    return;
                }
                window.location.assign(root.getAttribute('data-done-url') || window.location.pathname);
            }).catch(function () {
                showMessage(messageBag.failed || '', 'danger');
                if (button) button.disabled = false;
            });
        });
    });
})();
