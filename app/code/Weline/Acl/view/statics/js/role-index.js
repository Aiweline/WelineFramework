const root = document.querySelector('[data-acl-role-index]');

if (root) {
    root.querySelectorAll('[data-role-delete]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === 'true') return;
            event.preventDefault();
            const ui = window.Weline?.UI;
            if (!ui?.dialog?.confirm) return;
            const roleName = String(form.dataset.roleName || '');
            const confirmed = await ui.dialog.confirm(
                String(root.dataset.textDeleteMessage || '').replace('%{1}', roleName),
                {
                    title: String(root.dataset.textDeleteTitle || ''),
                    confirmText: String(root.dataset.textDeleteConfirm || ''),
                    cancelText: String(root.dataset.textCancel || ''),
                    tone: 'danger',
                },
            );
            if (!confirmed) return;
            form.dataset.confirmed = 'true';
            form.requestSubmit();
        });
    });

    const drawer = root.querySelector('#w-role-create');
    drawer?.addEventListener('weline:ui:drawer:close', () => {
        const form = drawer.querySelector('form');
        if (form instanceof HTMLFormElement) form.reset();
    });
}
