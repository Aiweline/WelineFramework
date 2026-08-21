(function (global) {
    'use strict';

    var initialized = typeof WeakSet === 'function' ? new WeakSet() : null;

    function apiResource() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return Promise.resolve(global.Weline.Api.resource('session_manager'));
        }
        if (global.Weline && typeof global.Weline.load === 'function') {
            return global.Weline.load('api').then(function (api) {
                return api.resource('session_manager');
            });
        }
        return Promise.reject(new Error('Weline.Api is unavailable'));
    }

    function payload(value) {
        return value && value.data && typeof value.data === 'object' ? value.data : value;
    }

    function formatError(error, fallback) {
        var business = global.WelineApiBusiness || (global.Weline && global.Weline.ApiBusiness);
        if (business && typeof business.formatApiError === 'function') {
            return business.formatApiError(error, fallback) || fallback;
        }
        return fallback;
    }

    function toast(message, type) {
        if (global.Weline && global.Weline.UI.toast && typeof global.Weline.UI.toast.show === 'function') {
            global.Weline.UI.toast.show(message, {tone: type || 'info'});
        } else if (global.Weline.UI.toast && typeof global.Weline.UI.toast[type] === 'function') {
            global.Weline.UI.toast[type](message);
        }
    }

    function textNode(tag, className, value) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        node.textContent = value == null || value === '' ? '—' : String(value);
        return node;
    }

    function dateText(value) {
        if (!value) return '—';
        var parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
        }).format(parsed);
    }

    function init(root) {
        if (!root || (initialized && initialized.has(root))) return;
        if (initialized) initialized.add(root);

        var labels = {};
        try { labels = JSON.parse(root.getAttribute('data-labels') || '{}'); } catch (_error) {}
        var list = root.querySelector('[data-device-list]');
        var loading = root.querySelector('[data-device-loading]');
        var errorState = root.querySelector('[data-device-error]');
        var errorMessage = root.querySelector('[data-device-error-message]');
        var empty = root.querySelector('[data-device-empty]');
        var retry = root.querySelector('[data-device-retry]');
        var notice = root.querySelector('[data-device-notice]');
        var pagination = root.querySelector('[data-device-pagination]');
        var previous = root.querySelector('[data-device-previous]');
        var next = root.querySelector('[data-device-next]');
        var pageLabel = root.querySelector('[data-device-page-label]');
        var state = { page: 1, pageSize: 20, total: 0, busy: false };

        function showNotice(message, type) {
            notice.textContent = message || '';
            notice.hidden = !message;
            notice.classList.toggle('session-device-manager__notice--error', type === 'error');
            if (message) toast(message, type || 'info');
        }

        function meta(label, value) {
            var item = document.createElement('span');
            item.appendChild(textNode('strong', '', label + ': '));
            item.appendChild(document.createTextNode(value || '—'));
            return item;
        }

        function statusLabel(item) {
            if (item.is_current) return labels.current;
            if (item.status === 'remembered') return labels.remembered;
            return labels.active;
        }

        function card(item) {
            var article = document.createElement('article');
            article.className = 'session-device-card';
            article.setAttribute('role', 'listitem');
            article.setAttribute('data-device-id', String(item.device_id || ''));
            article.setAttribute('data-device-current', item.is_current ? '1' : '0');

            var icon = document.createElement('span');
            icon.className = 'session-device-card__icon';
            var iconGlyph = document.createElement('i');
            iconGlyph.className = 'mdi mdi-laptop';
            iconGlyph.setAttribute('aria-hidden', 'true');
            icon.appendChild(iconGlyph);
            article.appendChild(icon);

            var body = document.createElement('div');
            var titleRow = document.createElement('div');
            titleRow.className = 'session-device-card__title-row';
            titleRow.appendChild(textNode('h3', '', item.name || item.browser || item.os || labels.unknown_device));
            titleRow.appendChild(textNode('span', 'session-device-card__status', statusLabel(item)));
            body.appendChild(titleRow);
            var metaRow = document.createElement('div');
            metaRow.className = 'session-device-card__meta';
            metaRow.appendChild(meta(labels.last_active, dateText(item.last_seen_at)));
            metaRow.appendChild(meta(labels.first_seen, dateText(item.first_seen_at)));
            metaRow.appendChild(meta(labels.ip, item.last_ip || '—'));
            if (item.remembered_until) metaRow.appendChild(meta(labels.remember_until, dateText(item.remembered_until)));
            body.appendChild(metaRow);
            article.appendChild(body);

            var actions = document.createElement('div');
            actions.className = 'session-device-card__actions';
            if (!item.is_current) {
                var revoke = textNode('button', '', labels.revoke);
                revoke.type = 'button';
                revoke.setAttribute('data-device-revoke', '');
                revoke.setAttribute('aria-expanded', 'false');
                revoke.addEventListener('click', function () {
                    revoke.hidden = true;
                    revoke.setAttribute('aria-expanded', 'true');
                    confirmRow.hidden = false;
                    confirm.focus();
                });
                actions.appendChild(revoke);
                var confirmRow = document.createElement('div');
                confirmRow.className = 'session-device-card__confirm';
                confirmRow.hidden = true;
                var confirm = textNode('button', '', labels.confirm);
                confirm.type = 'button';
                confirm.setAttribute('data-device-confirm', '');
                var cancel = textNode('button', '', labels.cancel);
                cancel.type = 'button';
                cancel.setAttribute('data-device-cancel', '');
                cancel.addEventListener('click', function () {
                    confirmRow.hidden = true;
                    revoke.hidden = false;
                    revoke.setAttribute('aria-expanded', 'false');
                    revoke.focus();
                });
                confirm.addEventListener('click', function () {
                    if (state.busy) return;
                    state.busy = true;
                    confirm.disabled = true;
                    apiResource().then(function (resource) {
                        return resource[root.getAttribute('data-revoke-operation')]({ device_id: item.device_id });
                    }).then(function (response) {
                        var result = payload(response) || {};
                        if (!result.success) throw { response: { data: result }, message: result.message };
                        showNotice(result.message || labels.revoked, 'success');
                        return load();
                    }).catch(function (error) {
                        showNotice(formatError(error, labels.revoke_error), 'error');
                        confirm.disabled = false;
                    }).finally(function () {
                        state.busy = false;
                    });
                });
                confirmRow.appendChild(confirm);
                confirmRow.appendChild(cancel);
                actions.appendChild(confirmRow);
            }
            article.appendChild(actions);
            return article;
        }

        function render(result) {
            list.replaceChildren();
            var items = Array.isArray(result.items) ? result.items : [];
            items.forEach(function (item) { list.appendChild(card(item)); });
            state.total = Number(result.total || 0);
            state.page = Number(result.page || state.page);
            state.pageSize = Number(result.page_size || state.pageSize);
            empty.hidden = items.length !== 0;
            var pages = Math.max(1, Math.ceil(state.total / state.pageSize));
            pagination.hidden = pages <= 1;
            previous.disabled = state.page <= 1;
            next.disabled = state.page >= pages;
            pageLabel.textContent = String(labels.page || '').replace('%{1}', String(state.page));
        }

        function load() {
            loading.hidden = false;
            errorState.hidden = true;
            empty.hidden = true;
            return apiResource().then(function (resource) {
                return resource[root.getAttribute('data-list-operation')]({
                    page: state.page,
                    page_size: state.pageSize
                });
            }).then(function (response) {
                var result = payload(response) || {};
                if (!result.success) throw { response: { data: result }, message: result.message };
                render(result);
            }).catch(function (error) {
                errorMessage.textContent = formatError(error, labels.load_error);
                errorState.hidden = false;
                list.replaceChildren();
            }).finally(function () {
                loading.hidden = true;
            });
        }

        retry.addEventListener('click', load);
        previous.addEventListener('click', function () { if (state.page > 1) { state.page -= 1; load(); } });
        next.addEventListener('click', function () { if (state.page * state.pageSize < state.total) { state.page += 1; load(); } });
        load();
    }

    function scan(scope) {
        (scope || document).querySelectorAll('[data-device-manager]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(document); });
    } else {
        scan(document);
    }
    global.addEventListener('weline:account-sidebar-content-loaded', function () { scan(document); });
}(window));
