const UI = window.Weline?.UI;

if (UI) {
    UI.define('queue-content', ({ element, listen }) => {
        const configNode = document.querySelector('[data-w-queue-content-config]');
        const content = element.querySelector('[data-w-queue-content-body]');
        let config = {};
        try { config = JSON.parse(configNode?.textContent || '{}'); } catch (_error) { config = {}; }
        const messages = config.messages || {};

        listen(element, 'click', async (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-w-queue-copy-content]') : null;
            if (!(button instanceof HTMLButtonElement)) return;
            event.preventDefault();
            if (!(content instanceof HTMLElement) || !navigator.clipboard?.writeText) {
                UI.toast.error(messages.copyFailed || 'Copy failed.');
                return;
            }
            button.disabled = true;
            try {
                await navigator.clipboard.writeText(content.textContent || '');
                UI.toast.success(messages.copied || 'Copied.');
            } catch (_error) {
                UI.toast.error(messages.copyFailed || 'Copy failed.');
            } finally {
                button.disabled = false;
            }
        });

        return {};
    });
}
