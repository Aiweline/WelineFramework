const UI = window.Weline?.UI;

if (UI) {
    UI.define('queue-admin', ({ element, listen }) => {
        const configNode = document.querySelector('[data-w-queue-admin-config]');
        const statsRegion = element.querySelector('[data-w-queue-stats]');
        const listingRegion = element.querySelector('[data-w-queue-listing]');
        const batchActions = element.querySelector('[data-w-queue-batch-actions]');
        let config = {};
        try { config = JSON.parse(configNode?.textContent || '{}'); } catch (_error) { config = {}; }

        const messages = config.messages || {};
        const state = {
            destroyed: false,
            snapshotTimer: 0,
            mutationTimer: 0,
            snapshotInFlight: false,
            refreshPending: false,
            mutationEpoch: 0,
            revision: '',
            lastStats: '',
            lastListing: '',
        };
        let resourcePromise = null;

        const errorMessage = (error) => String(
            error?.response?.data?.data?.msg
            || error?.data?.msg
            || error?.msg
            || error?.message
            || messages.networkError
            || 'Request failed.',
        );
        const resource = () => {
            resourcePromise ||= window.Weline.load('api')
                .then((api) => api.resource('queue_admin'))
                .catch((error) => {
                    resourcePromise = null;
                    throw error;
                });
            return resourcePromise;
        };
        const call = async (operation, params = {}) => {
            const api = await resource();
            if (!api || typeof api[operation] !== 'function') throw new Error(messages.apiUnavailable || 'Queue API unavailable.');
            return api[operation](params, { keepBusinessResult: true, silent: true });
        };
        const positiveId = (value) => {
            const id = Number.parseInt(String(value || ''), 10);
            return Number.isFinite(id) && id > 0 ? id : 0;
        };
        const selectedIds = () => [...element.querySelectorAll('[data-w-queue-select]:checked')]
            .map((input) => positiveId(input.value))
            .filter(Boolean);
        const restoreSelected = (ids) => {
            const selected = new Set(ids.map(String));
            element.querySelectorAll('[data-w-queue-select]').forEach((input) => {
                input.checked = selected.has(String(input.value || ''));
            });
        };
        const updateSelection = () => {
            const ids = selectedIds();
            const choices = [...element.querySelectorAll('[data-w-queue-select]')];
            const selectAll = element.querySelector('[data-w-queue-select-all]');
            if (batchActions instanceof HTMLElement) batchActions.hidden = ids.length === 0;
            if (selectAll instanceof HTMLInputElement) {
                selectAll.checked = choices.length > 0 && ids.length === choices.length;
                selectAll.indeterminate = ids.length > 0 && ids.length < choices.length;
            }
        };
        const setButtonBusy = (button, busy) => {
            if (!(button instanceof HTMLButtonElement)) return;
            button.disabled = busy;
            button.toggleAttribute('aria-busy', busy);
        };
        const setBatchBusy = (busy) => {
            element.querySelectorAll('[data-w-queue-batch]').forEach((button) => setButtonBusy(button, busy));
        };
        const pauseSnapshot = () => document.hidden || Boolean(document.querySelector(
            '[data-w-component~="dialog"][data-state="open"], [data-w-component~="drawer"][data-state="open"]',
        ));
        const snapshotParams = () => {
            const url = new URL(window.location.href);
            const params = {};
            ['module', 'status', 'q', 'biz_key'].forEach((key) => {
                const value = url.searchParams.get(key);
                if (value) params[key] = value;
            });
            const page = positiveId(url.searchParams.get('page')) || 1;
            params.page = page;
            const queueId = positiveId(url.searchParams.get('queue_id') || url.searchParams.get('id'));
            if (queueId) params.queue_id = queueId;
            if (state.revision) params.known_revision = state.revision;
            return params;
        };

        const safeFragment = (html) => {
            const parsed = new DOMParser().parseFromString(String(html || ''), 'text/html');
            parsed.querySelectorAll('script, style, iframe, object, embed, base, meta, link').forEach((node) => node.remove());
            parsed.body.querySelectorAll('*').forEach((node) => {
                [...node.attributes].forEach((attribute) => {
                    const name = attribute.name.toLowerCase();
                    const value = attribute.value.trim();
                    if (name.startsWith('on') || name === 'style' || name === 'srcdoc') {
                        node.removeAttribute(attribute.name);
                        return;
                    }
                    if (name === 'class') {
                        const classes = value.split(/\s+/).filter((item) => /^w-[a-z0-9_-]+$/.test(item));
                        if (classes.length > 0) node.setAttribute('class', classes.join(' '));
                        else node.removeAttribute('class');
                        return;
                    }
                    if (['href', 'src', 'action', 'formaction'].includes(name) && value !== '' && value !== '#') {
                        try {
                            const target = new URL(value, window.location.href);
                            if (target.origin !== window.location.origin) node.removeAttribute(attribute.name);
                        } catch (_error) {
                            node.removeAttribute(attribute.name);
                        }
                    }
                });
            });
            const fragment = document.createDocumentFragment();
            [...parsed.body.childNodes].forEach((node) => fragment.append(document.importNode(node, true)));
            return fragment;
        };
        const replaceRegion = (region, html) => {
            if (!(region instanceof HTMLElement)) return;
            UI.unmount(region);
            region.replaceChildren(safeFragment(html));
            UI.mount(region);
        };
        const applySnapshot = (payload) => {
            const selected = selectedIds();
            if (typeof payload.stats_html === 'string' && payload.stats_html !== state.lastStats) {
                replaceRegion(statsRegion, payload.stats_html);
                state.lastStats = payload.stats_html;
            }
            if (typeof payload.listing_html === 'string' && payload.listing_html !== state.lastListing) {
                replaceRegion(listingRegion, payload.listing_html);
                state.lastListing = payload.listing_html;
            }
            restoreSelected(selected);
            updateSelection();
        };
        const refreshSnapshot = async (options = {}) => {
            if (state.destroyed) return false;
            if (state.snapshotInFlight) {
                if (options.force) state.refreshPending = true;
                return false;
            }
            if (!options.force && pauseSnapshot()) return false;
            state.snapshotInFlight = true;
            const epoch = state.mutationEpoch;
            statsRegion?.setAttribute('aria-busy', 'true');
            listingRegion?.setAttribute('aria-busy', 'true');
            try {
                const response = await call('snapshot', snapshotParams());
                if (!response?.success) throw new Error(response?.msg || messages.networkError);
                if (epoch !== state.mutationEpoch) {
                    state.refreshPending = true;
                    return false;
                }
                if (response.changed !== false) applySnapshot(response);
                if (typeof response.revision === 'string') state.revision = response.revision;
                return true;
            } catch (error) {
                if (options.showError) UI.toast.error(errorMessage(error));
                return false;
            } finally {
                state.snapshotInFlight = false;
                statsRegion?.removeAttribute('aria-busy');
                listingRegion?.removeAttribute('aria-busy');
                if (state.refreshPending && !state.destroyed) {
                    state.refreshPending = false;
                    window.setTimeout(() => refreshSnapshot({ force: true }), 0);
                }
            }
        };
        const scheduleSnapshot = (delay = Number(config.pollIntervalMs) || 10000) => {
            window.clearTimeout(state.snapshotTimer);
            if (state.destroyed) return;
            state.snapshotTimer = window.setTimeout(async () => {
                await refreshSnapshot();
                scheduleSnapshot();
            }, Math.max(1000, delay));
        };
        const scheduleMutationRefresh = (delay = Number(config.actionRefreshDelayMs) || 600) => {
            state.mutationEpoch += 1;
            state.revision = '';
            window.clearTimeout(state.mutationTimer);
            state.mutationTimer = window.setTimeout(() => refreshSnapshot({ force: true, showError: true }), Math.max(0, delay));
        };

        const openDrawer = (triggerSelector, queueId) => {
            const id = positiveId(queueId);
            const trigger = element.querySelector(triggerSelector);
            if (!id || !(trigger instanceof HTMLElement)) {
                UI.toast.error(messages.apiUnavailable || messages.networkError || 'Unable to open drawer.');
                return;
            }
            const targetSelector = trigger.getAttribute('data-w-target') || '';
            let drawer = null;
            try { drawer = targetSelector ? document.querySelector(targetSelector) : null; } catch (_error) { drawer = null; }
            const frame = drawer?.querySelector('[data-w-remote-frame]');
            if (!(drawer instanceof HTMLElement) || !(frame instanceof HTMLIFrameElement)) {
                UI.toast.error(messages.apiUnavailable || 'Unable to open drawer.');
                return;
            }
            const base = frame.dataset.wQueueBaseSrc || frame.dataset.src || '';
            if (!base) {
                UI.toast.error(messages.apiUnavailable || 'Unable to open drawer.');
                return;
            }
            frame.dataset.wQueueBaseSrc = base;
            const url = new URL(base, window.location.href);
            if (url.origin !== window.location.origin) return;
            url.searchParams.set('id', String(id));
            frame.dataset.src = url.href;
            frame.removeAttribute('src');
            UI.drawer.open(drawer);
        };
        const mutate = async (queueId, action, confirmMessage, button) => {
            const id = positiveId(queueId);
            if (!id) return UI.toast.error(messages.networkError || 'Invalid queue.');
            const confirmed = await UI.dialog.confirm(confirmMessage, { tone: action === 'delete' ? 'danger' : 'warning' });
            if (!confirmed) return;
            setButtonBusy(button, true);
            try {
                const response = await call('action', { queue_id: id, action });
                if (!response?.success) throw new Error(response?.msg || messages.networkError);
                UI.toast.success(response.msg || messages.actionComplete || 'Done.');
                scheduleMutationRefresh(action === 'delete' ? 350 : undefined);
            } catch (error) {
                UI.toast.error(errorMessage(error));
            } finally {
                setButtonBusy(button, false);
            }
        };
        const batchMutate = async (action, template) => {
            const ids = selectedIds();
            if (ids.length === 0) return UI.toast.warning(messages.selectRequired || 'Select at least one queue.');
            const confirmed = await UI.dialog.confirm(
                String(template || '').replace('__QUEUE_COUNT__', String(ids.length)),
                { tone: action === 'delete' ? 'danger' : 'warning' },
            );
            if (!confirmed) return;
            setBatchBusy(true);
            try {
                const response = await call('batchAction', { queue_ids: ids, action });
                if (!response?.success && !response?.partial) throw new Error(response?.msg || messages.networkError);
                UI.toast.show(response.msg || messages.actionComplete || 'Done.', { tone: response.partial ? 'warning' : 'success' });
                if (Number(response.success_count || 0) > 0) scheduleMutationRefresh();
            } catch (error) {
                UI.toast.error(errorMessage(error));
            } finally {
                setBatchBusy(false);
            }
        };
        const copyResult = async (button) => {
            const id = button?.dataset?.wQueueResultId || '';
            const dialog = id ? document.getElementById(id) : null;
            const result = dialog?.querySelector('[data-w-queue-result-body]');
            if (!(result instanceof HTMLElement) || !navigator.clipboard?.writeText) {
                UI.toast.error(messages.resultCopyFailed || 'Copy failed.');
                return;
            }
            try {
                await navigator.clipboard.writeText(result.textContent || '');
                UI.toast.success(messages.resultCopied || 'Copied.');
            } catch (_error) {
                UI.toast.error(messages.resultCopyFailed || 'Copy failed.');
            }
        };

        listen(element, 'change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
            if (target.matches('[data-w-queue-select-all]')) {
                element.querySelectorAll('[data-w-queue-select]').forEach((input) => { input.checked = target.checked; });
                updateSelection();
                return;
            }
            if (target.matches('[data-w-queue-select]')) {
                updateSelection();
                return;
            }
            if (target.matches('[data-w-queue-status-filter]')) {
                const url = new URL(window.location.href);
                if (target.value) url.searchParams.set('status', target.value);
                else url.searchParams.delete('status');
                url.searchParams.delete('page');
                window.location.assign(url.href);
            }
        });
        listen(element, 'click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const copy = target?.closest('[data-w-queue-copy-result]');
            if (copy instanceof HTMLButtonElement) {
                event.preventDefault();
                copyResult(copy);
                return;
            }
            const batch = target?.closest('[data-w-queue-batch]');
            if (batch instanceof HTMLButtonElement) {
                event.preventDefault();
                const action = batch.dataset.wQueueBatch || '';
                const templates = {
                    delete: messages.batchDeleteConfirm,
                    stop: messages.batchStopConfirm,
                    continue: messages.batchContinueConfirm,
                };
                if (templates[action]) batchMutate(action, templates[action]);
                return;
            }
            const actionButton = target?.closest('[data-w-queue-action]');
            if (!(actionButton instanceof HTMLButtonElement)) return;
            event.preventDefault();
            const action = actionButton.dataset.wQueueAction || '';
            if (action === 'show') return openDrawer('.w-queue-shared-show-trigger', actionButton.dataset.queueId);
            if (action === 'edit') return openDrawer('.w-queue-shared-edit-trigger', actionButton.dataset.queueId);
            const queueName = actionButton.dataset.queueName || actionButton.dataset.queueId || '';
            const confirms = {
                delete: String(messages.deleteConfirm || '').replace('__QUEUE_NAME__', queueName),
                stop: messages.stopConfirm,
                continue: messages.continueConfirm,
                retry: messages.retryConfirm,
                reset: messages.resetConfirm,
            };
            if (confirms[action]) mutate(actionButton.dataset.queueId, action, confirms[action], actionButton);
        });
        listen(document, 'visibilitychange', () => {
            if (!document.hidden) scheduleSnapshot(200);
        });

        updateSelection();
        scheduleSnapshot();
        return {
            destroy() {
                state.destroyed = true;
                window.clearTimeout(state.snapshotTimer);
                window.clearTimeout(state.mutationTimer);
            },
        };
    });
}
