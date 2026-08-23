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
