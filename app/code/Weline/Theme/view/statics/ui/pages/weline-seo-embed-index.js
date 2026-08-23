/* Weline UI source: js/embed-index.js */
const node = document.querySelector('[data-seo-embed-index-config]');
let config = {};
try { config = JSON.parse(node?.textContent || '{}'); } catch (error) { console.error('[Seo Embed] Invalid list configuration.', error); }
const text = config.text || {};

function api() {
    if (window.Weline?.Api?.resource) return Promise.resolve(window.Weline.Api);
    if (typeof window.Weline?.load === 'function') return window.Weline.load('api');
    return Promise.reject(new Error(text.requestFailed || 'Request API unavailable.'));
}

function formatError(error, fallback) {
    return window.Weline?.ApiBusiness?.formatApiError
        ? window.Weline.ApiBusiness.formatApiError(error, fallback)
        : (error instanceof Error && error.message ? `${fallback}: ${error.message}` : fallback);
}

function start() {
    document.getElementById('addSubjectForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!(form instanceof HTMLFormElement) || !form.reportValidity()) return;
        try {
            const client = await api();
            const data = await client.resource('seo_admin').saveEmbedSubject(Object.fromEntries(new FormData(form)));
            if (!data || data.success === false) throw {response: {data}};
            window.Weline.UI.toast.success(data.message || text.saveSuccess);
            window.location.reload();
        } catch (error) {
            window.Weline.UI.toast.error(formatError(error, text.saveFailed));
        }
    });

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-seo-action="delete"]') : null;
        if (!(button instanceof HTMLButtonElement)) return;
        const confirmed = await window.Weline.UI.dialog.confirm(text.confirmDelete, {tone: 'danger', dangerous: true});
        if (!confirmed) return;
        try {
            const client = await api();
            const data = await client.resource('seo_admin').deleteEmbedSubject({subject_id: button.dataset.subjectId || ''});
            if (!data || data.success === false) throw {response: {data}};
            button.closest('[data-subject-id]')?.remove();
        } catch (error) {
            window.Weline.UI.toast.error(formatError(error, text.deleteFailed));
        }
    });
}

if (window.Weline?.UI) start();
else document.addEventListener('weline:ui:ready', start, {once: true});
