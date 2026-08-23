/* Weline UI source: js/document-config.js */
const root = document.querySelector('[data-document-config]');

if (root) {
    const form = root.querySelector('[data-document-config-form]');
    const resultBox = root.querySelector('#documentTranslationActionResult');
    const noticeBox = root.querySelector('#documentTranslationConfigNotice');
    const saveButton = root.querySelector('#documentTranslationConfigSaveBtn');
    const saveLabel = saveButton?.querySelector('.w-document-config__save-label');
    const saveLoading = saveButton?.querySelector('.w-document-config__save-loading');
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

    function message(result, fallback) {
        return String(result?.message || result?.msg || result?.data?.message || result?.data?.msg || fallback);
    }

    async function call(operation, params = {}) {
        const resource = await apiResource();
        if (typeof resource[operation] !== 'function') {
            throw new Error(`Weline operation is unavailable: ${operation}`);
        }
        const result = await resource[operation](params, { keepBusinessResult: true, silent: true });
        if (result?.success === false || Number(result?.code || 200) >= 400) {
            throw new Error(message(result, text.saveFailed));
        }
        return result;
    }

    function serializeForm(formElement) {
        const payload = {};
        new FormData(formElement).forEach((value, name) => {
            if (name === 'form_key') return;
            const arrayMatch = name.match(/^(.+)\[\]$/);
            const objectMatch = name.match(/^([^[]+)\[([^\]]+)]$/);
            if (arrayMatch) {
                payload[arrayMatch[1]] ||= [];
                payload[arrayMatch[1]].push(String(value));
                return;
            }
            if (objectMatch) {
                payload[objectMatch[1]] ||= {};
                payload[objectMatch[1]][objectMatch[2]] = String(value);
                return;
            }
            payload[name] = String(value);
        });
        return payload;
    }

    function writeResult(value) {
        if (!resultBox) return;
        if (typeof value === 'string') {
            resultBox.textContent = value;
            return;
        }
        try {
            resultBox.textContent = JSON.stringify(value, null, 2);
        } catch (_error) {
            resultBox.textContent = String(value ?? '');
        }
    }

    function showNotice(tone, value) {
        if (!noticeBox) return;
        noticeBox.dataset.tone = tone;
        noticeBox.textContent = String(value || '');
        noticeBox.hidden = false;
        noticeBox.scrollIntoView({ block: 'nearest' });
    }

    function setSaving(busy) {
        if (saveButton instanceof HTMLButtonElement) saveButton.disabled = busy;
        if (saveLabel) saveLabel.hidden = busy;
        if (saveLoading) saveLoading.hidden = !busy;
    }

    function reloadSoon() {
        window.setTimeout(() => window.location.reload(), 700);
    }

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        setSaving(true);
        showNotice('info', text.saving);
        try {
            const result = await call('documentConfigSave', serializeForm(form));
            writeResult(result);
            showNotice('success', message(result, text.saveSuccess));
            window.Weline?.UI?.toast?.success(message(result, text.saveSuccess));
            reloadSoon();
        } catch (error) {
            const errorMessage = error instanceof Error ? error.message : text.saveFailed;
            writeResult({ error: errorMessage });
            showNotice('danger', errorMessage);
            window.Weline?.UI?.toast?.error(errorMessage);
            setSaving(false);
        }
    });

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-translation-action]');
        if (!(button instanceof HTMLButtonElement)) return;
        const operation = String(button.dataset.translationAction || '');
        if (!operation) return;
        button.disabled = true;
        showNotice('info', text.running);
        try {
            const result = await call(operation);
            writeResult(result);
            const resultMessage = message(result, text.actionComplete);
            showNotice('success', resultMessage);
            window.Weline?.UI?.toast?.success(resultMessage);
            if (operation !== 'documentTranslationTest') reloadSoon();
            else button.disabled = false;
        } catch (error) {
            const errorMessage = error instanceof Error ? error.message : text.saveFailed;
            writeResult({ error: errorMessage });
            showNotice('danger', errorMessage);
            window.Weline?.UI?.toast?.error(errorMessage);
            button.disabled = false;
        }
    });
}
