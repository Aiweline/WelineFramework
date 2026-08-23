const configNode = document.querySelector('[data-seo-embed-config]');
let config = {};
try {
    config = JSON.parse(configNode?.textContent || '{}');
} catch (error) {
    console.error('[Seo Embed] Invalid page configuration.', error);
}

const text = config.text || {};

function api() {
    if (window.Weline?.Api?.resource) return Promise.resolve(window.Weline.Api);
    if (typeof window.Weline?.load === 'function') return window.Weline.load('api');
    return Promise.reject(new Error(text.requestFailed || 'Request API unavailable.'));
}

function notify(tone, message) {
    const toast = window.Weline?.UI?.toast;
    const method = tone === 'error' ? 'error' : tone;
    toast?.[method]?.(String(message || ''));
}

function errorMessage(error, fallback) {
    if (window.Weline?.ApiBusiness?.formatApiError) {
        return window.Weline.ApiBusiness.formatApiError(error, fallback);
    }
    return error instanceof Error && error.message ? `${fallback}: ${error.message}` : fallback;
}

function setSuggestionState(loading) {
    const content = document.getElementById('suggestionContent');
    const empty = document.getElementById('noSuggestion');
    const indicator = document.getElementById('suggestionLoading');
    if (content) content.hidden = loading;
    if (empty) empty.hidden = loading;
    if (indicator) indicator.hidden = !loading;
}

function renderSuggestion(value) {
    const indicator = document.getElementById('suggestionLoading');
    let content = document.getElementById('suggestionContent');
    if (!content) {
        content = document.createElement('div');
        content.id = 'suggestionContent';
        content.className = 'w-seo-embed__suggestion-content';
        indicator?.parentNode?.insertBefore(content, indicator);
    }
    content.textContent = String(value || '');
    content.hidden = false;
    document.getElementById('noSuggestion')?.remove();
}

function start() {
    document.getElementById('btnEdit')?.addEventListener('click', () => {
        window.Weline.UI.dialog.open('#editModal');
    });

    document.getElementById('editForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!(event.currentTarget instanceof HTMLFormElement) || !event.currentTarget.reportValidity()) return;
        const params = Object.fromEntries(new FormData(event.currentTarget));
        try {
            const client = await api();
            const data = await client.resource('seo_admin').saveEmbedSubject(params);
            if (!data || data.success === false) throw {response: {data}};
            notify('success', data.message || text.saveSuccess);
            window.location.reload();
        } catch (error) {
            notify('error', errorMessage(error, text.saveFailed));
        }
    });

    document.getElementById('btnRefreshSuggestion')?.addEventListener('click', async () => {
        setSuggestionState(true);
        try {
            const client = await api();
            const data = await client.resource('seo_admin').refreshEmbedSuggestion({
                subject_id: Number(config.subjectId || 0),
            });
            const suggestion = data?.data?.suggestion;
            if (!data?.success || !suggestion) throw {response: {data}};
            renderSuggestion(suggestion.content);
        } catch (error) {
            setSuggestionState(false);
            notify('error', errorMessage(error, text.generateFailed));
        } finally {
            const indicator = document.getElementById('suggestionLoading');
            if (indicator) indicator.hidden = true;
        }
    });
}

if (window.Weline?.UI) start();
else document.addEventListener('weline:ui:ready', start, {once: true});
