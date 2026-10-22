(function () {
    'use strict';

    function message(section, name, fallback) {
        if (!section) {
            return fallback;
        }
        var value = section.getAttribute('data-msg-' + name);
        return value && value !== '' ? value : fallback;
    }

    function setStatus(form, text) {
        var status = form.querySelector('[data-account-buyer-aftersale-status="true"]');
        if (status) {
            status.textContent = text || '';
        }
    }

    function bindForm(form) {
        if (!form || form.getAttribute('data-account-buyer-aftersale-bound') === '1') {
            return;
        }
        form.setAttribute('data-account-buyer-aftersale-bound', '1');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var section = form.closest('[data-testid="account-buyer-aftersale"]');
            var detail = form.closest('[data-account-order-detail]');
            var orderUuid = detail ? String(detail.getAttribute('data-order-uuid') || '').trim() : '';
            var selected = form.querySelector('input[name="resolution"]:checked');
            var resolution = selected ? String(selected.value || '').trim() : '';
            var reasonField = form.querySelector('[name="reason_code"]');
            var reasonCode = reasonField ? String(reasonField.value || '').trim() : '';
            var noteField = form.querySelector('[name="reason_text"]');
            var reasonText = noteField ? String(noteField.value || '') : '';
            var button = form.querySelector('[data-testid="account-buyer-aftersale-submit"]');

            if (orderUuid === '') {
                setStatus(form, message(section, 'order', ''));
                return;
            }
            if (resolution === '') {
                setStatus(form, message(section, 'resolution', ''));
                return;
            }
            if (reasonCode === '') {
                setStatus(form, message(section, 'reason', ''));
                return;
            }
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                setStatus(form, message(section, 'unavailable', ''));
                return;
            }
            if (button) {
                button.disabled = true;
            }
            Promise.resolve(window.Weline.Api.resource('buyer-aftersale')).then(function (api) {
                return api.submit({
                    order_uuid: orderUuid,
                    resolution: resolution,
                    reason_code: reasonCode,
                    reason_text: reasonText
                }, { silent: true });
            }).then(function (data) {
                if (data && data.success) {
                    setStatus(form, (data.message) || message(section, 'submitted', ''));
                    return;
                }
                setStatus(form, (data && (data.message || data.error_code)) || message(section, 'failed', ''));
            }).catch(function () {
                setStatus(form, message(section, 'failed', ''));
            }).finally(function () {
                if (button) {
                    button.disabled = false;
                }
            });
        });
    }

    function bindAll() {
        document.querySelectorAll('[data-account-buyer-aftersale-form="true"]').forEach(bindForm);
    }

    bindAll();
    window.addEventListener('weline:account-sidebar-content-loaded', function (event) {
        var section = event && event.detail ? event.detail.section : '';
        if (section && section !== 'orders') {
            return;
        }
        bindAll();
    });
})();
