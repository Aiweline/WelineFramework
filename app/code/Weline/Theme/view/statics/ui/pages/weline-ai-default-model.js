/* Weline UI source: js/ai-model-select.js */
let modelListPromise = null;

function normalizeModels(response) {
    if (Array.isArray(response)) return response;
    if (Array.isArray(response?.data)) return response.data;
    if (Array.isArray(response?.data?.data)) return response.data.data;
    return [];
}

function loadModels() {
    if (!modelListPromise) {
        modelListPromise = window.Weline.load('api')
            .then((api) => api.resource('ai').listModels({}))
            .then(normalizeModels)
            .catch((error) => {
                modelListPromise = null;
                throw error;
            });
    }
    return modelListPromise;
}

function register(UI) {
    UI.define('ai-model-select', ({ element, listen, floating, emit }) => {
        const trigger = element.querySelector('[data-w-ai-model-trigger]');
        const panel = element.querySelector('[data-w-ai-model-panel]');
        const search = element.querySelector('[data-w-ai-model-search]');
        const supplier = element.querySelector('[data-w-ai-model-supplier]');
        const hidden = element.querySelector('[data-ai-model-value]');
        const display = element.querySelector('[data-w-ai-model-display]');
        const list = element.querySelector('[data-w-ai-model-list]');
        const status = element.querySelector('[data-w-ai-model-status]');
        if (!(trigger instanceof HTMLButtonElement)
            || !(panel instanceof HTMLElement)
            || !(search instanceof HTMLInputElement)
            || !(supplier instanceof HTMLSelectElement)
            || !(hidden instanceof HTMLInputElement)
            || !(display instanceof HTMLElement)
            || !(list instanceof HTMLElement)
            || !(status instanceof HTMLElement)) return {};

        const portal = floating.portal(panel, 'ai-model-select');
        const limit = Math.max(1, Number.parseInt(element.dataset.aiModelLimit || '50', 10) || 50);
        let models = null;

        const modelCode = (model) => String(model?.model_code || model?.code || '');
        const modelLabel = (model) => String(model?.name || modelCode(model));
        const modelSupplier = (model) => String(model?.supplier || '');
        const setStatus = (message = '', tone = 'neutral') => {
            status.textContent = message;
            status.dataset.tone = tone;
            status.hidden = message === '';
        };
        const close = (restoreFocus = false, reason = 'api', force = false) => {
            if (panel.hidden || (!force && !emit('before-close', { reason }, true))) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            panel.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-expanded', 'false');
            element.dataset.state = 'closed';
            monitor.unobserve(panel);
            monitor.reset();
            floating.clear(panel);
            portal.restore();
            if (restoreFocus) trigger.focus();
            emit('close', { reason }, false);
            return true;
        };
        const monitor = floating.monitor(
            trigger,
            () => panel,
            () => element.dataset.wPlacement || 'bottom-start',
            () => close(false),
        );
        const open = async () => {
            if (!panel.hidden || !emit('before-open', {}, true)) return false;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            panel.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            search.setAttribute('aria-expanded', 'true');
            element.dataset.state = 'open';
            monitor.observe(panel);
            if (monitor.place()?.anchorVisible === false) {
                close(false, 'anchor-hidden', true);
                return false;
            }
            search.focus();
            emit('open', {}, false);
            if (models) return true;
            setStatus(element.dataset.aiModelLoading || 'Loading…');
            try {
                models = await loadModels();
                const suppliers = [...new Set(models.map(modelSupplier).filter(Boolean))].sort();
                for (const value of suppliers) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    supplier.append(option);
                }
                render();
                syncInitialSelection();
            } catch (_error) {
                setStatus(element.dataset.aiModelLoadFail || 'Unable to load models.', 'danger');
            }
            return true;
        };
        const choose = (model) => {
            const code = modelCode(model);
            if (!code) return;
            hidden.value = code;
            hidden.setAttribute('value', code);
            display.textContent = modelSupplier(model)
                ? `${modelLabel(model)} (${modelSupplier(model)})`
                : modelLabel(model);
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            emit('change', { value: code, model }, false);
            close(true, 'select');
        };
        const render = () => {
            if (!models) return;
            const query = search.value.trim().toLocaleLowerCase();
            const selectedSupplier = supplier.value.trim().toLocaleLowerCase();
            const filtered = models.filter((model) => {
                const supplierName = modelSupplier(model).toLocaleLowerCase();
                if (selectedSupplier && supplierName !== selectedSupplier) return false;
                const haystack = `${modelLabel(model)} ${supplierName} ${modelCode(model)}`.toLocaleLowerCase();
                return query === '' || haystack.includes(query);
            }).slice(0, limit);
            list.replaceChildren();
            setStatus(filtered.length === 0 ? (element.dataset.aiModelNoMatch || 'No models found.') : '');
            for (const model of filtered) {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'w-combobox__option w-ai-model-select__option';
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(modelCode(model) === hidden.value));
                const copy = document.createElement('span');
                copy.className = 'w-ai-model-select__option-copy';
                const label = document.createElement('strong');
                label.textContent = modelLabel(model);
                const meta = document.createElement('small');
                meta.textContent = [modelSupplier(model), modelCode(model)].filter(Boolean).join(' · ');
                copy.append(label, meta);
                option.append(copy);
                option.addEventListener('click', () => choose(model), { once: true });
                list.append(option);
            }
            if (!panel.hidden) monitor.place();
        };
        const syncInitialSelection = () => {
            if (!models || hidden.value === '') return;
            const selected = models.find((model) => modelCode(model) === hidden.value);
            if (selected) {
                display.textContent = modelSupplier(selected)
                    ? `${modelLabel(selected)} (${modelSupplier(selected)})`
                    : modelLabel(selected);
            }
        };

        listen(trigger, 'click', () => panel.hidden ? open() : close());
        listen(search, 'input', render);
        listen(search, 'keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true, 'escape');
            }
        });
        listen(supplier, 'change', render);
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close(false, 'outside');
        });
        listen(window, 'pagehide', () => close(false, 'pagehide', true));
        listen(window, 'pageshow', () => close(false, 'history-restore', true));
        panel.hidden = true;
        panel.dataset.state = 'closed';
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        search.setAttribute('aria-expanded', 'false');
        element.dataset.state = 'closed';
        return {
            open,
            close,
            element,
            destroy: () => {
                close(false, 'unmount', true);
                monitor.destroy();
                portal.destroy();
            },
        };
    });
    UI.mount(document);
}

if (window.Weline?.UI) register(window.Weline.UI);
else document.addEventListener('weline:ui:ready', () => register(window.Weline.UI), { once: true });

/* Weline UI source: js/default-model.js */
const root = document.querySelector('[data-ai-default-model]');
const configNode = document.querySelector('[data-ai-default-config]');

if (root instanceof HTMLElement && configNode instanceof HTMLScriptElement) {
    let config = {};
    try { config = JSON.parse(configNode.textContent || '{}'); } catch (_error) { config = {}; }
    const messages = config.messages || {};
    const protectedList = root.querySelector('[data-ai-protected-list]');
    let apiPromise = null;

    const api = () => {
        apiPromise ||= window.Weline.load('api').then((loaded) => loaded.resource('ai'));
        return apiPromise;
    };
    const body = (response) => response?.data || response || {};
    const errorMessage = (error) => error instanceof Error ? error.message : String(error || messages.unknownError || 'Error');
    const dialog = (tone, title, message) => window.Weline.UI.dialog.request({
        tone,
        title,
        message,
        confirmLabel: messages.confirm || 'OK',
    });
    const confirm = async (title, message) => (await window.Weline.UI.dialog.request({
        tone: 'warning',
        title,
        message,
        cancelable: true,
        confirmLabel: messages.confirm || 'OK',
        cancelLabel: messages.cancel || 'Cancel',
    })).confirmed;
    const rows = () => [...root.querySelectorAll('[data-ai-service-row]')];
    const rowConfiguration = (row) => ({
        service_type: row.dataset.serviceType || '',
        model_code: row.querySelector('[data-ai-model-value]')?.value || '',
        priority: Math.max(1, Number.parseInt(row.querySelector('[data-ai-priority]')?.value || '100', 10) || 100),
        is_active: row.querySelector('[data-ai-active]')?.checked ? 1 : 0,
    });
    const withBusy = async (button, operation) => {
        if (!(button instanceof HTMLButtonElement) || button.disabled) return;
        button.disabled = true;
        try { await operation(); } finally { button.disabled = false; }
    };

    const updateProtectedModels = async () => {
        if (!(protectedList instanceof HTMLElement)) return;
        protectedList.setAttribute('aria-busy', 'true');
        try {
            const response = body(await (await api()).defaultProtected());
            const models = response.success && Array.isArray(response.data) ? response.data : [];
            protectedList.replaceChildren();
            if (models.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'w-empty';
                empty.textContent = messages.noProtected || 'No protected models.';
                protectedList.append(empty);
                return;
            }
            const list = document.createElement('ul');
            list.className = 'w-ai-default__protected-list';
            for (const model of models) {
                const item = document.createElement('li');
                item.className = 'w-cluster w-ai-default__protected-item';
                const copy = document.createElement('span');
                copy.className = 'w-stack';
                copy.dataset.gap = 'sm';
                const name = document.createElement('strong');
                name.textContent = String(model.model_name || model.model_code || '');
                const vendor = document.createElement('small');
                vendor.className = 'w-text';
                vendor.dataset.tone = 'muted';
                vendor.textContent = String(model.vendor || '');
                copy.append(name, vendor);
                const badge = document.createElement('span');
                badge.className = 'w-badge';
                badge.dataset.tone = 'info';
                badge.textContent = Array.isArray(model.service_types) ? model.service_types.join(', ') : '';
                item.append(copy, badge);
                list.append(item);
            }
            protectedList.append(list);
        } catch (error) {
            protectedList.textContent = `${messages.requestFailed || ''}${errorMessage(error)}`;
        } finally {
            protectedList.removeAttribute('aria-busy');
        }
    };

    const saveRow = async (row) => {
        const payload = rowConfiguration(row);
        if (!payload.model_code) {
            await dialog('warning', messages.selectModel || 'Select a model', messages.selectModel || 'Select a model');
            return;
        }
        try {
            const response = body(await (await api()).defaultSet(payload));
            if (!response.success) throw new Error(String(response.message || messages.unknownError || 'Error'));
            window.Weline.UI.toast.success(messages.saveSuccess || 'Saved.');
            await updateProtectedModels();
        } catch (error) {
            await dialog('danger', messages.saveFailed || 'Save failed', `${messages.saveFailed || ''}${errorMessage(error)}`);
        }
    };

    const saveAll = async () => {
        if (!await confirm(messages.saveAllConfirm || 'Save all?', messages.saveAllMessage || '')) return;
        const saving = window.Weline.UI.toast.info(messages.saving || 'Saving…', { duration: 0 });
        try {
            const response = body(await (await api()).defaultBatchSet({ configurations: rows().map(rowConfiguration) }));
            if (!response.success) throw new Error(String(response.message || messages.unknownError || 'Error'));
            saving.close();
            await dialog('success', messages.saveSuccess || 'Saved', messages.saveAllSuccess || 'Saved.');
            window.location.reload();
        } catch (error) {
            saving.close();
            await dialog('danger', messages.saveFailed || 'Save failed', `${messages.saveFailed || ''}${errorMessage(error)}`);
        }
    };

    const clearCache = async () => {
        if (!await confirm(messages.clearConfirm || 'Clear cache?', messages.clearMessage || '')) return;
        try {
            const response = body(await (await api()).defaultClearCache());
            if (!response.success) throw new Error(String(response.message || messages.unknownError || 'Error'));
            window.Weline.UI.toast.success(messages.clearSuccess || 'Cache cleared.');
        } catch (error) {
            await dialog('danger', messages.requestFailed || 'Request failed', `${messages.requestFailed || ''}${errorMessage(error)}`);
        }
    };

    const initialize = async () => {
        if (!await confirm(messages.initConfirm || 'Initialize?', messages.initMessage || '')) return;
        try {
            const response = body(await (await api()).defaultInitialize());
            if (!response.success) throw new Error(String(response.message || messages.unknownError || 'Error'));
            await dialog('success', messages.saveSuccess || 'Success', messages.initSuccess || 'Initialized.');
            window.location.reload();
        } catch (error) {
            await dialog('danger', messages.requestFailed || 'Request failed', `${messages.requestFailed || ''}${errorMessage(error)}`);
        }
    };

    root.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-ai-default-action]') : null;
        if (!(button instanceof HTMLButtonElement)) return;
        event.preventDefault();
        const action = button.dataset.aiDefaultAction || '';
        withBusy(button, async () => {
            if (action === 'save') await saveRow(button.closest('[data-ai-service-row]'));
            if (action === 'save-all') await saveAll();
            if (action === 'clear-cache') await clearCache();
            if (action === 'init') await initialize();
        });
    });

    updateProtectedModels();
}
