const UI = window.Weline?.UI;

if (UI) {
    UI.define('queue-type-admin', ({ element, listen }) => {
        const configNode = document.querySelector('[data-w-queue-type-config]');
        let config = {};
        try { config = JSON.parse(configNode?.textContent || '{}'); } catch (_error) { config = {}; }
        const messages = config.messages || {};
        let resourcePromise = null;
        let reloadTimer = 0;

        const resource = () => {
            resourcePromise ||= window.Weline.load('api')
                .then((api) => api.resource('queue_admin'))
                .catch((error) => {
                    resourcePromise = null;
                    throw error;
                });
            return resourcePromise;
        };
        const errorMessage = (error) => String(error?.msg || error?.message || messages.networkError || 'Request failed.');
        const positiveId = (value) => {
            const id = Number.parseInt(String(value || ''), 10);
            return Number.isFinite(id) && id > 0 ? id : 0;
        };
        const setBusy = (button, busy) => {
            button.disabled = busy;
            button.toggleAttribute('aria-busy', busy);
        };
        const openDrawer = (typeId) => {
            const id = positiveId(typeId);
            const trigger = element.querySelector('.w-queue-type-shared-view-trigger');
            const targetSelector = trigger?.getAttribute('data-w-target') || '';
            let drawer = null;
            try { drawer = targetSelector ? document.querySelector(targetSelector) : null; } catch (_error) { drawer = null; }
            const frame = drawer?.querySelector('[data-w-remote-frame]');
            if (!id || !(drawer instanceof HTMLElement) || !(frame instanceof HTMLIFrameElement)) {
                UI.toast.error(messages.networkError || 'Unable to open drawer.');
                return;
            }
            const base = frame.dataset.wQueueTypeBaseSrc || frame.dataset.src || '';
            const url = new URL(base, window.location.href);
            if (!base || url.origin !== window.location.origin) return;
            frame.dataset.wQueueTypeBaseSrc = base;
            url.searchParams.set('id', String(id));
            frame.dataset.src = url.href;
            frame.removeAttribute('src');
            UI.drawer.open(drawer);
        };
        const toggle = async (button, enabled) => {
            const id = positiveId(button.dataset.typeId);
            const name = button.dataset.typeName || String(id);
            if (!id) return UI.toast.error(messages.networkError || 'Invalid queue type.');
            const label = enabled ? messages.enable : messages.disable;
            const confirmed = await UI.dialog.confirm(`${label} · ${name}`, {
                title: label,
                confirmLabel: label,
                tone: enabled ? 'success' : 'warning',
            });
            if (!confirmed) return;
            setBusy(button, true);
            try {
                const api = await resource();
                const response = await api.setTypeEnabled(
                    { type_id: id, enabled },
                    { keepBusinessResult: true, silent: true },
                );
                if (!response?.success) throw new Error(response?.msg || messages.networkError);
                UI.toast.success(response.msg || messages.saved || 'Saved.');
                reloadTimer = window.setTimeout(() => window.location.reload(), 400);
            } catch (error) {
                UI.toast.error(errorMessage(error));
                setBusy(button, false);
            }
        };

        listen(element, 'click', (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('[data-w-queue-type-action]')
                : null;
            if (!(button instanceof HTMLButtonElement)) return;
            event.preventDefault();
            const action = button.dataset.wQueueTypeAction || '';
            if (action === 'view') openDrawer(button.dataset.typeId);
            if (action === 'enable') toggle(button, true);
            if (action === 'disable') toggle(button, false);
        });

        return { destroy() { window.clearTimeout(reloadTimer); } };
    });
}
