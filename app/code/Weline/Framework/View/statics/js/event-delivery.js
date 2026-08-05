(function () {
    'use strict';

    function boot() {
        const root = document.getElementById('event-delivery-app');
        if (!root) {
            return;
        }

        const elements = {
            refresh: document.getElementById('ed-refresh'),
            apply: document.getElementById('ed-apply'),
            status: document.getElementById('ed-status'),
            scope: document.getElementById('ed-scope'),
            website: document.getElementById('ed-website'),
            message: document.getElementById('ed-message'),
            rows: document.getElementById('ed-rows'),
            tableWrap: document.getElementById('ed-table-wrap'),
            empty: document.getElementById('ed-empty'),
            total: document.getElementById('ed-total'),
            scopeLabel: document.getElementById('ed-scope-label'),
            statusLabel: document.getElementById('ed-status-label'),
            pageLabel: document.getElementById('ed-page-label'),
            prev: document.getElementById('ed-prev'),
            next: document.getElementById('ed-next'),
            modal: document.getElementById('ed-detail-modal'),
            modalPanel: document.querySelector('#ed-detail-modal .ed-modal-panel'),
            detailSubtitle: document.getElementById('ed-detail-subtitle'),
            detailMessage: document.getElementById('ed-detail-message'),
            detailFields: document.getElementById('ed-detail-fields'),
            payload: document.getElementById('ed-payload'),
            replayBox: document.getElementById('ed-replay-box'),
            replayReason: document.getElementById('ed-replay-reason'),
            reasonCount: document.getElementById('ed-reason-count'),
            replay: document.getElementById('ed-replay')
        };

        const state = {
            page: 1,
            totalPages: 0,
            loading: false,
            detail: null,
            detailRequestId: 0,
            lastFocus: null,
            api: null
        };

        const labels = {
            delivery_id: root.dataset.labelDeliveryId,
            outbox_id: root.dataset.labelOutboxId,
            event_name: root.dataset.labelEventName,
            event_id: root.dataset.labelEventId,
            observer_key: root.dataset.labelObserver,
            website: root.dataset.labelWebsite,
            resource: root.dataset.labelResource,
            status: root.dataset.labelStatus,
            attempt: root.dataset.labelAttempt,
            transport_name: root.dataset.labelTransport,
            queue_id: root.dataset.labelQueue,
            last_error_code: root.dataset.labelErrorCode,
            last_error: root.dataset.labelError,
            terminal_reason: root.dataset.labelTerminalReason,
            replay_of_delivery_id: root.dataset.labelReplayOf,
            replay_requested_by: root.dataset.labelReplayBy,
            replay_requested_at: root.dataset.labelReplayAt,
            created_at: root.dataset.labelCreatedAt,
            updated_at: root.dataset.labelUpdatedAt,
            finished_at: root.dataset.labelFinishedAt
        };

        function text(name, fallback) {
            return root.dataset[name] || fallback || '';
        }

        function interpolate(pattern, values) {
            let result = String(pattern || '');
            values.forEach(function (value, index) {
                result = result.replaceAll('%{' + (index + 1) + '}', String(value));
            });
            return result;
        }

        function statusText(status) {
            const key = 'textStatus' + String(status || '')
                .split('_')
                .map(function (part) { return part.charAt(0).toUpperCase() + part.slice(1); })
                .join('');
            return text(key, status || '—');
        }

        function showMessage(kind, message) {
            elements.message.dataset.kind = kind;
            elements.message.textContent = message;
            elements.message.hidden = false;
        }

        function hideMessage() {
            elements.message.hidden = true;
            elements.message.textContent = '';
        }

        function showDetailMessage(kind, message) {
            elements.detailMessage.dataset.kind = kind;
            elements.detailMessage.textContent = message;
            elements.detailMessage.hidden = false;
        }

        function hideDetailMessage() {
            elements.detailMessage.hidden = true;
            elements.detailMessage.textContent = '';
        }

        function getApi() {
            if (state.api) {
                return state.api;
            }
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                throw new Error('api_unavailable');
            }
            state.api = Promise.resolve(window.Weline.Api.resource(root.dataset.provider))
                .catch(function (error) {
                    state.api = null;
                    throw error;
                });
            return state.api;
        }

        function parseWebsiteId() {
            const raw = String(elements.website.value || '').trim();
            if (raw === '') {
                return null;
            }
            if (!/^(0|[1-9][0-9]*)$/.test(raw)) {
                throw new Error('invalid_website');
            }
            return Number(raw);
        }

        function listParams() {
            const params = {
                page: state.page,
                page_size: 20,
                status: elements.status.value,
                scope: elements.scope.value
            };
            if (params.scope === 'current') {
                const websiteId = parseWebsiteId();
                if (websiteId !== null) {
                    params.website_id = websiteId;
                }
            }
            return params;
        }

        function cell(content, className) {
            const td = document.createElement('td');
            if (className) {
                td.className = className;
            }
            if (content instanceof Node) {
                td.appendChild(content);
            } else {
                td.textContent = content === null || content === undefined || content === '' ? '—' : String(content);
            }
            return td;
        }

        function stack(primary, secondary) {
            const wrap = document.createElement('div');
            const first = document.createElement('div');
            first.textContent = primary || '—';
            wrap.appendChild(first);
            if (secondary !== null && secondary !== undefined && secondary !== '') {
                const second = document.createElement('small');
                second.className = 'text-muted ed-code d-block mt-1';
                second.textContent = String(secondary);
                wrap.appendChild(second);
            }
            return wrap;
        }

        function statusStack(item) {
            const wrap = document.createElement('div');
            const badge = document.createElement('span');
            badge.className = 'ed-badge';
            badge.dataset.status = item.status || '';
            badge.textContent = statusText(item.status);
            wrap.appendChild(badge);
            const reason = item.terminal_reason || item.last_error_code;
            if (reason) {
                const small = document.createElement('small');
                small.className = 'text-muted ed-code d-block mt-1';
                small.textContent = reason;
                wrap.appendChild(small);
            }
            return wrap;
        }

        function actionButton(item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-primary';
            button.textContent = text('textViewDetail');
            button.addEventListener('click', function () {
                openDetail(item, button);
            });
            return button;
        }

        function renderRows(items) {
            elements.rows.replaceChildren();
            items.forEach(function (item) {
                const tr = document.createElement('tr');
                tr.appendChild(cell(stack('#' + item.delivery_id, item.event_id), 'ed-code'));
                tr.appendChild(cell(stack(item.website_code || '—', '#' + item.website_id)));
                tr.appendChild(cell(stack(
                    (item.resource_type || '—') + ':' + (item.resource_id || '—'),
                    (item.resource_action || '—') + ' · r' + (item.revision || 0)
                ), 'ed-code'));
                tr.appendChild(cell(stack(item.observer_name || item.observer_module, item.observer_key)));
                tr.appendChild(cell(statusStack(item)));
                tr.appendChild(cell(item.updated_at || item.finished_at || item.created_at));
                tr.appendChild(cell(actionButton(item), 'text-end'));
                elements.rows.appendChild(tr);
            });
            elements.empty.hidden = items.length !== 0;
            elements.tableWrap.hidden = items.length === 0;
        }

        function updatePermissionControls(permissions) {
            const allOption = elements.scope.querySelector('option[value="all"]');
            const canAll = permissions && permissions.all_websites === true;
            allOption.disabled = !canAll;
            if (!canAll && elements.scope.value === 'all') {
                elements.scope.value = 'current';
            }
            const current = permissions ? permissions.current_website_id : null;
            if (elements.website.value === '' && current !== null && current !== undefined) {
                elements.website.value = String(current);
            }
        }

        function updateSummary(result) {
            elements.total.textContent = interpolate(text('textTotal'), [result.total || 0]);
            elements.statusLabel.textContent = statusText(result.status);
            elements.scopeLabel.textContent = result.scope === 'all'
                ? text('textAllWebsites')
                : interpolate(text('textCurrentWebsite'), [result.website_id]);
            state.totalPages = Number(result.total_pages || 0);
            elements.pageLabel.textContent = interpolate(text('textPage'), [result.page || 1, state.totalPages]);
            elements.prev.disabled = state.page <= 1 || state.loading;
            elements.next.disabled = state.totalPages < 1 || state.page >= state.totalPages || state.loading;
        }

        async function loadList() {
            if (state.loading) {
                return;
            }
            state.loading = true;
            elements.refresh.disabled = true;
            elements.apply.disabled = true;
            showMessage('loading', text('textLoading'));
            try {
                const api = await getApi();
                const result = await api.asyncEventDeliveryList(listParams());
                const items = Array.isArray(result && result.items) ? result.items : [];
                renderRows(items);
                updatePermissionControls(result && result.permissions ? result.permissions : {});
                updateSummary(result || {});
                hideMessage();
            } catch (error) {
                renderRows([]);
                showMessage(
                    'error',
                    error && error.message === 'invalid_website'
                        ? text('textInvalidWebsite')
                        : text('textLoadError')
                );
            } finally {
                state.loading = false;
                elements.refresh.disabled = false;
                elements.apply.disabled = false;
                elements.prev.disabled = state.page <= 1;
                elements.next.disabled = state.totalPages < 1 || state.page >= state.totalPages;
            }
        }

        function addDetailField(label, value, code) {
            if (!label) {
                return;
            }
            const group = document.createElement('div');
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = label;
            dd.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
            if (code) {
                dd.className = 'ed-code';
            }
            group.append(dt, dd);
            elements.detailFields.appendChild(group);
        }

        function renderDetail(detail) {
            elements.detailFields.replaceChildren();
            const website = detail.website || {};
            const resource = detail.resource || {};
            addDetailField(labels.delivery_id, detail.delivery_id, true);
            addDetailField(labels.outbox_id, detail.outbox_id, true);
            addDetailField(labels.event_name, detail.event_name, true);
            addDetailField(labels.event_id, detail.event_id, true);
            addDetailField(labels.observer_key, detail.observer_key, true);
            addDetailField(labels.website, (website.code || '—') + ' (#' + website.id + ')');
            addDetailField(labels.resource, (resource.type || '—') + ':' + (resource.id || '—') + ' · ' + (resource.action || '—') + ' · r' + (resource.revision || 0), true);
            addDetailField(labels.status, statusText(detail.status));
            addDetailField(labels.attempt, (detail.attempt_no || 0) + ' / ' + (detail.max_attempts || 0));
            addDetailField(labels.transport_name, detail.transport_name, true);
            addDetailField(labels.queue_id, detail.queue_id, true);
            addDetailField(labels.last_error_code, detail.last_error_code, true);
            addDetailField(labels.last_error, detail.last_error, true);
            addDetailField(labels.terminal_reason, detail.terminal_reason, true);
            addDetailField(labels.replay_of_delivery_id, detail.replay_of_delivery_id, true);
            addDetailField(labels.replay_requested_by, detail.replay_requested_by, true);
            addDetailField(labels.replay_requested_at, detail.replay_requested_at);
            addDetailField(labels.created_at, detail.created_at);
            addDetailField(labels.updated_at, detail.updated_at);
            addDetailField(labels.finished_at, detail.finished_at);
            elements.payload.textContent = JSON.stringify(detail.payload || {}, null, 2);
            elements.replayBox.hidden = detail.can_replay !== true;
            elements.replayReason.value = '';
            updateReasonState();
        }

        async function openDetail(item, trigger) {
            const requestId = ++state.detailRequestId;
            state.lastFocus = trigger || document.activeElement;
            state.detail = null;
            elements.modal.hidden = false;
            elements.detailSubtitle.textContent = '#' + item.delivery_id + ' · ' + (item.event_id || '');
            elements.detailFields.replaceChildren();
            elements.payload.textContent = '';
            elements.replayBox.hidden = true;
            showDetailMessage('loading', text('textLoading'));
            document.body.style.overflow = 'hidden';
            elements.modalPanel.focus();
            try {
                const api = await getApi();
                const detail = await api.asyncEventDeliveryDetail({
                    delivery_id: item.delivery_id,
                    website_id: item.website_id
                });
                if (requestId !== state.detailRequestId) {
                    return;
                }
                state.detail = detail;
                renderDetail(detail || {});
                hideDetailMessage();
            } catch (error) {
                if (requestId === state.detailRequestId) {
                    showDetailMessage('error', text('textDetailError'));
                }
            }
        }

        function closeDetail() {
            state.detailRequestId += 1;
            elements.modal.hidden = true;
            document.body.style.overflow = '';
            state.detail = null;
            if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
                state.lastFocus.focus();
            }
        }

        function byteLength(value) {
            if (window.TextEncoder) {
                return new window.TextEncoder().encode(value).length;
            }
            return unescape(encodeURIComponent(value)).length;
        }

        function updateReasonState() {
            const reason = String(elements.replayReason.value || '').trim();
            const bytes = byteLength(reason);
            elements.reasonCount.textContent = interpolate(text('textReasonBytes'), [bytes]);
            elements.replay.disabled = !state.detail || reason === '' || bytes > 500;
        }

        async function replay() {
            if (!state.detail || elements.replay.disabled) {
                return;
            }
            const requestId = state.detailRequestId;
            const detail = state.detail;
            const reason = String(elements.replayReason.value || '').trim();
            const bytes = byteLength(reason);
            if (reason === '' || bytes > 500) {
                showDetailMessage('error', text('textInvalidReason'));
                return;
            }
            elements.replay.disabled = true;
            showDetailMessage('loading', text('textReplayLoading'));
            try {
                const api = await getApi();
                const result = await api.asyncEventDeliveryReplay({
                    delivery_id: detail.delivery_id,
                    website_id: detail.website.id,
                    reason: reason
                });
                if (requestId === state.detailRequestId) {
                    showDetailMessage(
                        'success',
                        text('textReplaySuccess') + ' ' + (result && result.event_id ? result.event_id : '')
                    );
                    elements.replayBox.hidden = true;
                }
                loadList();
            } catch (error) {
                if (requestId === state.detailRequestId) {
                    showDetailMessage('error', text('textReplayError'));
                    updateReasonState();
                }
            }
        }

        elements.refresh.addEventListener('click', loadList);
        elements.apply.addEventListener('click', function () {
            state.page = 1;
            loadList();
        });
        elements.scope.addEventListener('change', function () {
            elements.website.disabled = elements.scope.value === 'all';
        });
        elements.prev.addEventListener('click', function () {
            if (state.page > 1) {
                state.page -= 1;
                loadList();
            }
        });
        elements.next.addEventListener('click', function () {
            if (state.page < state.totalPages) {
                state.page += 1;
                loadList();
            }
        });
        elements.replayReason.addEventListener('input', updateReasonState);
        elements.replay.addEventListener('click', replay);
        document.querySelectorAll('[data-ed-close]').forEach(function (button) {
            button.addEventListener('click', closeDetail);
        });
        elements.modal.addEventListener('click', function (event) {
            if (event.target === elements.modal) {
                closeDetail();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !elements.modal.hidden) {
                closeDetail();
            }
        });

        loadList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}());
