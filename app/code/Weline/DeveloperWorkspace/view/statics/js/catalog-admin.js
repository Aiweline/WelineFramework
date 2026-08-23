const root = document.querySelector('[data-catalog-admin]');

if (root) {
    const form = root.querySelector('[data-catalog-form]');
    const text = Object.fromEntries(
        Object.entries(root.dataset)
            .filter(([key]) => key.startsWith('text'))
            .map(([key, value]) => [key.slice(4, 5).toLowerCase() + key.slice(5), String(value || '')]),
    );

    async function apiResource() {
        const Weline = window.Weline;
        const api = Weline?.Api?.resource ? Weline.Api : await Weline?.load?.('api');
        if (!api?.resource) throw new Error('Weline API runtime is unavailable.');
        return api.resource('developer_workspace');
    }

    function resultMessage(result, fallback) {
        return String(result?.message || result?.msg || result?.data?.message || result?.data?.msg || fallback);
    }

    async function call(operation, params) {
        const resource = await apiResource();
        if (typeof resource[operation] !== 'function') {
            throw new Error(`Weline operation is unavailable: ${operation}`);
        }
        const result = await resource[operation](params, { keepBusinessResult: true, silent: true });
        if (result?.success === false || Number(result?.code || 200) >= 400) {
            throw new Error(resultMessage(result, text.saveFailed));
        }
        return result;
    }

    function catalogUrl(id = 0) {
        const url = new URL(window.location.href);
        url.search = '';
        if (id > 0) url.searchParams.set('id', String(id));
        return url.href;
    }

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const submit = form.querySelector('button[type="submit"]');
        const fields = new FormData(form);
        const active = form.querySelector('input[type="checkbox"][name="is_active"]');
        const payload = {
            id: Number(fields.get('id') || 0),
            pid: Number(fields.get('pid') || 0),
            name: String(fields.get('name') || '').trim(),
            description: String(fields.get('description') || ''),
            is_active: active?.checked ? 1 : 0,
        };
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        try {
            const result = await call('catalogAdminSave', payload);
            window.Weline?.UI?.toast?.success(resultMessage(result, text.saveSuccess));
            const id = Number(result?.data?.id || payload.id || 0);
            window.location.assign(catalogUrl(id));
        } catch (error) {
            if (submit instanceof HTMLButtonElement) submit.disabled = false;
            window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.saveFailed);
        }
    });

    root.addEventListener('click', async (event) => {
        const deleteTrigger = event.target.closest('[data-catalog-delete]');
        if (deleteTrigger instanceof HTMLButtonElement) {
            const id = Number(deleteTrigger.dataset.catalogDelete || 0);
            if (!id) return;
            const confirmed = await window.Weline?.UI?.dialog?.confirm?.(text.deleteMessage, {
                title: text.deleteTitle,
                dangerous: true,
                confirmTone: 'danger',
            });
            if (!confirmed) return;
            deleteTrigger.disabled = true;
            try {
                const result = await call('catalogAdminDelete', { id });
                window.Weline?.UI?.toast?.success(resultMessage(result, text.deleteSuccess));
                window.location.assign(catalogUrl());
            } catch (error) {
                deleteTrigger.disabled = false;
                window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.deleteFailed);
            }
            return;
        }

        const reorderTrigger = event.target.closest('[data-catalog-reorder]');
        if (!(reorderTrigger instanceof HTMLButtonElement) || reorderTrigger.disabled) return;
        reorderTrigger.disabled = true;
        try {
            const result = await call('catalogAdminReorder', {
                id: Number(reorderTrigger.dataset.id || 0),
                pid: Number(reorderTrigger.dataset.pid || 0),
                level: Number(reorderTrigger.dataset.level || 1),
                position: Number(reorderTrigger.dataset.position || 1),
            });
            window.Weline?.UI?.toast?.success(resultMessage(result, text.reorderSuccess));
            window.location.reload();
        } catch (error) {
            reorderTrigger.disabled = false;
            window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.reorderFailed);
        }
    });
}
