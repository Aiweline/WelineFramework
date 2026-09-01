/**
 * Notification center page interactions: mark-all-read, mark-topic-read, detail keyboard nav.
 * Bound via data-w-component="notification-center" on index/detail templates.
 */
(function () {
    'use strict';

    function ui() {
        return window.Weline && window.Weline.UI ? window.Weline.UI : null;
    }

    function csrfToken() {
        if (window.site && window.site.csrf_token) {
            return String(window.site.csrf_token);
        }
        const cfg = window.Weline && window.Weline.config && window.Weline.config.site
            ? window.Weline.config.site
            : null;
        return cfg && cfg.csrf_token ? String(cfg.csrf_token) : '';
    }

    function buildHeaders() {
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const token = csrfToken();
        if (token) {
            headers['X-CSRF-TOKEN'] = token;
        }
        return headers;
    }

    async function postForm(url, fields) {
        const body = fields && typeof fields === 'object'
            ? new URLSearchParams(fields).toString()
            : '';
        const response = await fetch(String(url || ''), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: buildHeaders(),
            body: body,
        });
        const text = await response.text();
        let data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (_error) {
            data = null;
        }
        const success = data && (data.success === true || data.success === 'true');
        if (!response.ok || !success) {
            const message = data && data.message
                ? String(data.message)
                : ('HTTP ' + response.status);
            throw new Error(message);
        }
        return data;
    }

    async function confirmAction(message, options) {
        const runtime = ui();
        if (runtime && runtime.dialog && typeof runtime.dialog.confirm === 'function') {
            return !!(await runtime.dialog.confirm(String(message || ''), options || {}));
        }
        return window.confirm(String(message || ''));
    }

    function toast(kind, message) {
        const runtime = ui();
        if (runtime && runtime.toast) {
            if (kind === 'success' && typeof runtime.toast.success === 'function') {
                runtime.toast.success(String(message || ''));
                return;
            }
            if (kind === 'error' && typeof runtime.toast.error === 'function') {
                runtime.toast.error(String(message || ''));
                return;
            }
            if (typeof runtime.toast.show === 'function') {
                runtime.toast.show(String(message || ''), {
                    tone: kind === 'success' ? 'success' : 'danger',
                });
                return;
            }
        }
        if (kind === 'error') {
            window.alert(String(message || ''));
        }
    }

    function setBusy(button, busy) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.disabled = !!busy;
        button.toggleAttribute('aria-busy', !!busy);
    }

    async function markAllRead(root, button) {
        const url = root.getAttribute('data-w-mark-all-url') || '';
        const confirmMessage = root.getAttribute('data-w-confirm-all') || '';
        const successMessage = root.getAttribute('data-w-success-message') || '';
        const failureMessage = root.getAttribute('data-w-failure-message') || '';
        const requestFailureMessage = root.getAttribute('data-w-request-failure-message') || '';
        if (!url) {
            toast('error', failureMessage || 'Missing mark-all URL');
            return;
        }
        const confirmed = await confirmAction(confirmMessage, { tone: 'warning' });
        if (!confirmed) {
            return;
        }
        setBusy(button, true);
        try {
            const data = await postForm(url, {});
            toast('success', (data && data.message) || successMessage);
            clearNotificationBadges();
            window.setTimeout(function () {
                window.location.reload();
            }, 350);
        } catch (error) {
            toast('error', error instanceof Error && error.message
                ? error.message
                : (requestFailureMessage || failureMessage));
            setBusy(button, false);
        }
    }

    async function markTopicRead(root, button) {
        const url = root.getAttribute('data-w-mark-topic-url') || '';
        const topicCode = button.getAttribute('data-w-topic-code') || '';
        const topicName = button.getAttribute('data-w-topic-name') || topicCode;
        const confirmTemplate = root.getAttribute('data-w-confirm-topic') || '';
        const successMessage = root.getAttribute('data-w-success-message') || '';
        const failureMessage = root.getAttribute('data-w-failure-message') || '';
        const requestFailureMessage = root.getAttribute('data-w-request-failure-message') || '';
        if (!url || !topicCode) {
            toast('error', failureMessage || 'Missing topic mark URL');
            return;
        }
        const confirmMessage = String(confirmTemplate || '').replace('%{name}', topicName);
        const confirmed = await confirmAction(confirmMessage, { tone: 'warning' });
        if (!confirmed) {
            return;
        }
        setBusy(button, true);
        try {
            const data = await postForm(url, { topic_code: topicCode });
            toast('success', (data && data.message) || successMessage);
            clearNotificationBadges();
            window.setTimeout(function () {
                window.location.reload();
            }, 350);
        } catch (error) {
            toast('error', error instanceof Error && error.message
                ? error.message
                : (requestFailureMessage || failureMessage));
            setBusy(button, false);
        }
    }

    function clearNotificationBadges() {
        document.querySelectorAll('.w-notification-trigger__badge').forEach((node) => node.remove());
        document.querySelectorAll('.w-notification-menu__header-actions .w-badge').forEach((node) => node.remove());
        document.querySelectorAll('.w-notification-center__unread').forEach((node) => node.remove());
        document.querySelectorAll('.w-notification-center__item[data-state="unread"]').forEach((node) => {
            node.setAttribute('data-state', 'read');
        });
        document.querySelectorAll('.w-notification-item[data-state="unread"]').forEach((node) => {
            node.setAttribute('data-state', 'read');
        });
    }

    function bindKeyboard(root) {
        if ((root.getAttribute('data-w-mode') || '') !== 'detail') {
            return;
        }
        const onKey = function (event) {
            if (!(event instanceof KeyboardEvent)) {
                return;
            }
            if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
                return;
            }
            const target = event.target;
            if (target instanceof HTMLElement) {
                const tag = target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable) {
                    return;
                }
            }
            if (event.key === 'ArrowLeft') {
                const prev = root.getAttribute('data-w-prev-url') || '';
                if (prev) {
                    event.preventDefault();
                    window.location.assign(prev);
                }
                return;
            }
            if (event.key === 'ArrowRight') {
                const next = root.getAttribute('data-w-next-url') || '';
                if (next) {
                    event.preventDefault();
                    window.location.assign(next);
                }
            }
        };
        document.addEventListener('keydown', onKey);
        root._wNotificationKeyHandler = onKey;
    }

    function bindRoot(root) {
        if (!(root instanceof HTMLElement) || root.dataset.wNotificationBound === '1') {
            return;
        }
        root.dataset.wNotificationBound = '1';

        root.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target : null;
            const button = target ? target.closest('[data-w-notification-action]') : null;
            if (!(button instanceof HTMLButtonElement) || !root.contains(button)) {
                return;
            }
            const action = button.getAttribute('data-w-notification-action') || '';
            event.preventDefault();
            if (action === 'mark-all-read') {
                markAllRead(root, button);
                return;
            }
            if (action === 'mark-topic-read') {
                markTopicRead(root, button);
            }
        });

        bindKeyboard(root);
    }

    function start() {
        document.querySelectorAll('[data-w-component~="notification-center"]').forEach(bindRoot);
    }

    function boot() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, { once: true });
            return;
        }
        start();
    }

    boot();
}());
