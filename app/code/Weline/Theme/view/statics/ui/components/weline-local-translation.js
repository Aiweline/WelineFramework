/* Weline UI source: js/local-translation.js */
/* Weline UI source: js/local-translation.js */
export function register(UI) {
    UI.define('local-translation', ({ element, listen, UI: componentUI }) => {
        const panel = element.querySelector('[data-w-local-panel]');
        const listRoot = element.querySelector('[data-w-local-list]');
        const loadingEl = element.querySelector('[data-w-local-loading]');
        const emptyEl = element.querySelector('[data-w-local-empty]');
        const emptyTextEl = element.querySelector('[data-w-local-empty-text]');
        const searchInput = element.querySelector('[data-w-local-search]');
        const progressEl = element.querySelector('[data-w-local-progress]');
        const progressBarEl = element.querySelector('[data-w-local-progress-bar]');
        const listHeadEl = element.querySelector('[data-w-local-list-head]');
        const sourceEl = element.querySelector('[data-w-local-source]');
        const refreshButton = element.querySelector('[data-w-local-refresh]');
        const aiButton = element.querySelector('[data-w-local-ai]');
        const submitButton = element.querySelector('[data-w-local-submit]');

        const isElement = (node) => node && typeof node === 'object' && node.nodeType === 1;
        const tagOf = (node) => (isElement(node) ? String(node.tagName || '').toUpperCase() : '');
        const isButton = (node) => tagOf(node) === 'BUTTON';
        const isInput = (node) => tagOf(node) === 'INPUT';

        let formState = null;
        let localeRows = [];
        let searchTerm = '';
        let loadPromise = null;

        const i18nText = (key, vars = {}) => {
            let text = String(panel?.dataset?.[key] || '');
            Object.entries(vars).forEach(([name, value]) => {
                text = text.replace(new RegExp(`%\\{${name}\\}`, 'g'), String(value));
            });
            return text;
        };

        const parseSourceParams = () => {
            try {
                const url = new URL(element.dataset.wSource || '', window.location.href);
                return {
                    model: url.searchParams.get('model') || '',
                    field: url.searchParams.get('field') || '',
                    id: url.searchParams.get('id') || '',
                    value: url.searchParams.get('value') || '',
                };
            } catch (_error) {
                return { model: '', field: '', id: '', value: '' };
            }
        };

        const unwrapResponse = (response) => {
            let current = response;
            for (let depth = 0; depth < 4; depth += 1) {
                if (!current || typeof current !== 'object') {
                    break;
                }
                if (typeof current.success === 'boolean' || Array.isArray(current.locales)) {
                    break;
                }
                if (current.data !== undefined) {
                    current = current.data;
                    continue;
                }
                break;
            }
            return current;
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        const setPanelState = (state) => {
            if (!isElement(panel)) {
                return;
            }
            panel.dataset.state = state;
            if (isElement(loadingEl)) {
                loadingEl.hidden = state !== 'loading';
            }
            if (isElement(emptyEl)) {
                emptyEl.hidden = state !== 'empty';
            }
            if (isElement(listRoot)) {
                listRoot.hidden = state !== 'ready';
            }
            if (isElement(listHeadEl)) {
                listHeadEl.hidden = state !== 'ready';
            }
        };

        const resolveToast = () => componentUI?.toast || window.Weline?.UI?.toast || null;

        const getSourceText = () => String(
            formState?.source_label || formState?.value || parseSourceParams().value || '',
        ).trim();

        const getSourceLocale = () => String(
            formState?.source_locale || panel?.dataset?.sourceLocale || '',
        ).trim();

        const getBaseLocale = () => String(
            formState?.base_locale || panel?.dataset?.baseLocale || '',
        ).trim();

        const updateSourceMeta = () => {
            if (!isElement(panel)) {
                return;
            }
            const noteEl = panel.querySelector('[data-w-local-source-note]');
            if (!isElement(noteEl)) {
                return;
            }
            const baseLocale = getBaseLocale();
            const sourceLocale = getSourceLocale();
            const fallback = Boolean(formState?.source_fallback)
                || (baseLocale !== '' && sourceLocale !== '' && baseLocale.toLowerCase() !== sourceLocale.toLowerCase());
            if (!fallback || sourceLocale === '') {
                noteEl.hidden = true;
                noteEl.textContent = '';
                return;
            }
            const localeRow = localeRows.find((row) => String(row?.local_code || '').toLowerCase() === sourceLocale.toLowerCase());
            const localeName = String(localeRow?.local?.name || sourceLocale);
            noteEl.hidden = false;
            noteEl.textContent = i18nText('i18nSourceFallback', { locale: localeName })
                || `Base is empty; using ${localeName} as translation source`;
        };

        const getLocaleValue = (row) => {
            const code = String(row?.local_code || '');
            if (isElement(listRoot) && code !== '') {
                const rowEl = [...listRoot.querySelectorAll('[data-w-local-row]')].find(
                    (node) => isElement(node) && node.dataset.localeCode === code,
                );
                const input = rowEl?.querySelector('[data-w-local-input]');
                if (isInput(input)) {
                    return String(input.value || '');
                }
            }
            return String(row?.value ?? '');
        };

        const isRealTranslation = (row, value = null) => {
            const sourceText = getSourceText();
            const trimmed = String(value ?? getLocaleValue(row)).trim();
            if (trimmed === '') {
                return false;
            }
            return sourceText === '' || trimmed !== sourceText;
        };

        const analyzeTranslationSlots = () => {
            const sourceLocale = getSourceLocale();
            let emptyCount = 0;
            let filledCount = 0;

            localeRows.forEach((row) => {
                const code = String(row?.local_code || '');
                if (sourceLocale !== '' && code === sourceLocale) {
                    return;
                }
                if (isRealTranslation(row)) {
                    filledCount += 1;
                } else {
                    emptyCount += 1;
                }
            });

            return { emptyCount, filledCount };
        };

        const updateProgress = () => {
            const sourceLocale = getSourceLocale();
            const rows = localeRows.length > 0
                ? localeRows
                : (isElement(listRoot)
                    ? [...listRoot.querySelectorAll('[data-w-local-row]')].map((rowEl) => ({
                        local_code: rowEl.dataset.localeCode || '',
                    }))
                    : []);
            let total = 0;
            let filledCount = 0;
            rows.forEach((row) => {
                const code = String(row?.local_code || '');
                if (sourceLocale !== '' && code === sourceLocale) {
                    return;
                }
                total += 1;
                if (isRealTranslation(row)) {
                    filledCount += 1;
                }
            });
            const ratio = total > 0 ? Math.min(100, Math.round((filledCount / total) * 100)) : 0;
            if (isElement(progressBarEl)) {
                progressBarEl.style.inlineSize = `${ratio}%`;
            }
            if (isElement(progressEl)) {
                progressEl.textContent = total > 0
                    ? i18nText('i18nProgress', { filled: filledCount, total })
                    : '';
            }
        };

        const filterLocales = (rows, term) => {
            const needle = String(term || '').trim().toLowerCase();
            if (needle === '') {
                return rows;
            }
            return rows.filter((row) => {
                const local = row?.local || {};
                const haystack = [
                    row?.local_code,
                    local.name,
                    local.native_name,
                    row?.value,
                ].join(' ').toLowerCase();
                return haystack.includes(needle);
            });
        };

        const renderLocaleRows = (rows) => {
            if (!isElement(listRoot) || !formState) {
                return;
            }
            const visibleRows = filterLocales(rows, searchTerm);
            if (visibleRows.length === 0) {
                setPanelState('empty');
                if (isElement(emptyTextEl)) {
                    emptyTextEl.textContent = searchTerm
                        ? i18nText('i18nNoMatch', {})
                        : i18nText('i18nEmpty', {});
                }
                listRoot.innerHTML = '';
                updateProgress();
                return;
            }

            setPanelState('ready');
            const field = String(formState.field || '');
            const idField = String(formState.id_field || 'id');
            const sourceValue = String(formState.source_label || formState.value || '');
            const baseLocale = String(formState.base_locale || getSourceLocale() || '');
            updateSourceMeta();

            listRoot.innerHTML = visibleRows.map((row) => {
                const code = String(row.local_code || '');
                const local = row.local || {};
                const name = String(local.name || code);
                const nativeName = String(local.native_name || '');
                const flag = String(local.flag || '🌐');
                const value = String(row.value ?? '');
                const translated = isRealTranslation(row, value);
                const isBase = Boolean(row.is_base) || (baseLocale !== '' && code.toLowerCase() === baseLocale.toLowerCase());
                const statusClass = `${translated ? 'is-filled' : 'is-pending'}${isBase ? ' is-base' : ''}`;
                const subtitle = nativeName && nativeName !== name ? nativeName : code;
                const baseLabel = i18nText('i18nBase', {}) || 'Base language';
                const inputLabel = isBase ? `${name} (${code}) · ${baseLabel}` : `${name} (${code})`;
                const star = isBase
                    ? `<span class="w-local-translation__row-star" title="${escapeHtml(baseLabel)}" aria-label="${escapeHtml(baseLabel)}">★</span>`
                    : '';

                return `
                    <article class="w-local-translation__row ${statusClass}" data-w-local-row data-locale-code="${escapeHtml(code)}"${isBase ? ' data-w-local-base="1"' : ''} role="listitem">
                        <span class="w-local-translation__row-flag" aria-hidden="true">${flag}</span>
                        <div class="w-local-translation__row-meta">
                            <span class="w-local-translation__row-name">${escapeHtml(name)}${star}</span>
                            <span class="w-local-translation__row-sub">${escapeHtml(subtitle)}</span>
                        </div>
                        <input
                            class="w-input w-local-translation__row-input"
                            type="text"
                            data-w-local-input
                            name="description[${escapeHtml(code)}][${escapeHtml(field)}]"
                            data-id-field="${escapeHtml(idField)}"
                            value="${escapeHtml(value)}"
                            placeholder="${escapeHtml(sourceValue)}"
                            aria-label="${escapeHtml(inputLabel)}"
                            autocomplete="off"
                            spellcheck="true"
                            required
                        >
                        <span class="w-local-translation__row-badge" data-w-local-status aria-hidden="true"></span>
                    </article>`;
            }).join('');

            listRoot.querySelectorAll('[data-w-local-input]').forEach((input) => {
                listen(input, 'input', () => {
                    const row = input.closest('[data-w-local-row]');
                    if (isElement(row)) {
                        const hasValue = isRealTranslation({ local_code: row.dataset.localeCode || '' }, input.value);
                        row.classList.toggle('is-filled', hasValue);
                        row.classList.toggle('is-pending', !hasValue);
                        const badge = row.querySelector('[data-w-local-status]');
                        if (isElement(badge)) {
                            badge.title = hasValue
                                ? i18nText('i18nFilled', {})
                                : i18nText('i18nPending', {});
                        }
                    }
                    updateProgress();
                });
            });
            updateProgress();
        };

        const buildSavePayload = () => {
            const params = formState || parseSourceParams();
            const payload = {
                model: params.model,
                field: params.field,
                id: params.id,
                value: params.value,
                description: {},
            };
            const idField = String(params.id_field || 'id');
            const field = String(params.field || '');

            if (!isElement(listRoot)) {
                return payload;
            }

            listRoot.querySelectorAll('[data-w-local-row]').forEach((row) => {
                const code = isElement(row) ? row.dataset.localeCode : '';
                const input = row.querySelector('[data-w-local-input]');
                if (!code || !isInput(input) || field === '') {
                    return;
                }
                payload.description[code] = {
                    local_code: code,
                    [idField]: params.id,
                    [field]: input.value,
                };
            });
            return payload;
        };

        const setBusy = (busy) => {
            if (isButton(submitButton)) {
                submitButton.disabled = busy;
                submitButton.setAttribute('aria-busy', String(busy));
            }
            if (isButton(refreshButton)) {
                refreshButton.disabled = busy;
            }
            if (isButton(aiButton)) {
                aiButton.disabled = busy;
                // Do not set aria-busy on AI during panel load — only setAiBusy shows the button spinner.
            }
        };

        const setAiBusy = (busy) => {
            if (isButton(aiButton)) {
                aiButton.disabled = busy;
                aiButton.setAttribute('aria-busy', String(busy));
            }
            if (isButton(refreshButton)) {
                refreshButton.disabled = busy;
            }
            if (isButton(submitButton)) {
                submitButton.disabled = busy;
            }
        };

        const submitViaParentApi = async (payload, action = 'taglib-local-save') => {
            const apiModule = window.Weline?.Api;
            if (!apiModule || typeof apiModule.resource !== 'function') {
                throw new Error('Weline.Api bin-query is unavailable.');
            }
            const api = apiModule.resource('i18n_admin');
            if (!api || typeof api.action !== 'function') {
                throw new Error('I18n bin-query provider is unavailable.');
            }
            const response = await api.action({
                action,
                payload,
            }, { silent: true });
            const result = unwrapResponse(response);
            if (!result || result.success !== true) {
                throw new Error(String(result?.message || result?.msg || 'Save failed'));
            }
            componentUI.toast.success(String(result.message || 'Saved.'));
            await load(true);
            return true;
        };

        const confirmRetranslateAll = async () => {
            const message = i18nText('i18nAiRetranslate', {}) || '已有翻译内容，是否重新翻译？';
            const title = i18nText('i18nAiRetranslateTitle', {}) || '重新翻译';
            const drawerHost = element.closest('.w-drawer');
            const prevBackdrop = drawerHost instanceof HTMLElement ? drawerHost.dataset.wBackdrop : undefined;
            if (drawerHost instanceof HTMLElement) {
                drawerHost.dataset.wBackdrop = 'static';
            }
            try {
                const dialogApi = componentUI?.dialog || window.Weline?.UI?.dialog;
                if (dialogApi && typeof dialogApi.confirm === 'function') {
                    return Boolean(await dialogApi.confirm(message, {
                        title,
                        tone: 'warning',
                        confirmLabel: title,
                    }));
                }
                if (typeof window.BackendConfirm !== 'undefined' && typeof window.BackendConfirm.show === 'function') {
                    return Boolean(await window.BackendConfirm.show(message, {
                        title,
                        type: 'warning',
                    }));
                }
                // Never use window.confirm: dismissing it click-throughs the drawer overlay and closes the panel.
                resolveToast()?.warning?.(message);
                return false;
            } finally {
                if (drawerHost instanceof HTMLElement) {
                    if (prevBackdrop === undefined) delete drawerHost.dataset.wBackdrop;
                    else drawerHost.dataset.wBackdrop = prevBackdrop;
                }
            }
        };

        const runAiTranslate = async () => {
            if (!formState || localeRows.length === 0) {
                await load(true);
            }
            if (!formState || localeRows.length === 0) {
                resolveToast()?.warning?.('Translation form is unavailable.');
                return false;
            }

            const { emptyCount, filledCount } = analyzeTranslationSlots();
            let retranslateAll = false;

            if (emptyCount === 0 && filledCount > 0) {
                if (!(await confirmRetranslateAll())) {
                    return false;
                }
                retranslateAll = true;
            }

            const eavUrl = String(element.dataset.wLocalAiUrl || '').trim();
            const eavType = String(element.dataset.wLocalAiType || '').trim();
            const eavId = String(element.dataset.wLocalAiId || '').trim();

            setAiBusy(true);
            try {
                if (eavUrl !== '' && eavType !== '' && eavId !== '') {
                    const payload = new FormData();
                    payload.set('type', eavType);
                    payload.set('id', eavId);
                    if (retranslateAll) {
                        payload.set('retranslate_all', '1');
                    }
                    const response = await fetch(eavUrl, {
                        method: 'POST',
                        body: payload,
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json'},
                    });
                    const result = unwrapResponse(await response.json());
                    if (!response.ok || !result || result.success !== true) {
                        throw new Error(String(result?.message || 'AI translation failed'));
                    }
                    const translated = Number(result.data?.translated ?? result.translated ?? 0);
                    const toast = resolveToast();
                    if (toast) {
                        const fn = translated > 0 ? toast.success : toast.info;
                        fn.call(toast, String(
                            result.message || i18nText('i18nAiDone', {}) || 'AI translation completed',
                        ));
                    }
                } else {
                    const payloadParams = formState || parseSourceParams();
                    if (!payloadParams.model || !payloadParams.field || !payloadParams.id) {
                        throw new Error('Translation context is incomplete.');
                    }
                    const apiModule = window.Weline?.Api;
                    if (!apiModule || typeof apiModule.resource !== 'function') {
                        throw new Error('Weline.Api bin-query is unavailable.');
                    }
                    const api = apiModule.resource('i18n_admin');
                    const response = await api.action({
                        action: 'taglib-local-ai',
                        payload: {
                            model: payloadParams.model,
                            field: payloadParams.field,
                            id: payloadParams.id,
                            value: payloadParams.value || payloadParams.source_label || '',
                            retranslate_all: retranslateAll ? '1' : '0',
                        },
                    }, { silent: true });
                    const result = unwrapResponse(response);
                    if (!result || result.success !== true) {
                        throw new Error(String(result?.message || 'AI translation failed'));
                    }
                    const translated = Number(result.data?.translated ?? result.translated ?? 0);
                    const toast = resolveToast();
                    if (toast) {
                        const fn = translated > 0 ? toast.success : toast.info;
                        fn.call(toast, String(
                            result.message || i18nText('i18nAiDone', {}) || 'AI translation completed',
                        ));
                    }
                }
                await load(true);
                return true;
            } catch (error) {
                resolveToast()?.error?.(error instanceof Error ? error.message : String(error));
                return false;
            } finally {
                setAiBusy(false);
            }
        };

        const load = async (force = false) => {
            if (loadPromise && !force) {
                return loadPromise;
            }
            loadPromise = (async () => {
                const params = parseSourceParams();
                if (!params.model || !params.field || !params.id) {
                    setPanelState('empty');
                    if (isElement(emptyTextEl)) {
                        emptyTextEl.textContent = 'Translation context is incomplete.';
                    }
                    componentUI.toast.error('Translation form is invalid.');
                    return false;
                }

                if (isElement(sourceEl) && params.value) {
                    sourceEl.textContent = params.value;
                }

                setPanelState('loading');
                setBusy(true);
                try {
                    const apiModule = window.Weline?.Api;
                    if (!apiModule || typeof apiModule.resource !== 'function') {
                        throw new Error('Weline.Api bin-query is unavailable.');
                    }
                    const api = apiModule.resource('i18n_admin');
                    const response = await api.action({
                        action: 'taglib-local-load',
                        payload: {
                            ...params,
                            search: '',
                        },
                    }, { silent: true });
                    const result = unwrapResponse(response);
                    if (!result || result.success !== true) {
                        throw new Error(String(result?.message || 'Load failed'));
                    }
                    formState = result.data && typeof result.data === 'object' ? result.data : result;
                    localeRows = Array.isArray(formState.locales) ? formState.locales : [];
                    if (isElement(sourceEl)) {
                        const label = String(formState.source_label || formState.value || params.value || '');
                        if (label !== '') {
                            sourceEl.textContent = label;
                        }
                    }
                    if (localeRows.length === 0) {
                        setPanelState('empty');
                        if (isElement(emptyTextEl)) {
                            emptyTextEl.textContent = String(result.message || 'No enabled languages were found.');
                        }
                        return false;
                    }
                    renderLocaleRows(localeRows);
                    return true;
                } catch (error) {
                    setPanelState('empty');
                    if (isElement(emptyTextEl)) {
                        emptyTextEl.textContent = error instanceof Error ? error.message : String(error);
                    }
                    componentUI.toast.error(error instanceof Error ? error.message : String(error));
                    return false;
                } finally {
                    setBusy(false);
                    loadPromise = null;
                }
            })();
            return loadPromise;
        };

        const submit = async () => {
            if (!formState || localeRows.length === 0) {
                await load(true);
            }
            if (!formState) {
                componentUI.toast.warning('Translation form is unavailable.');
                return false;
            }

            const invalid = isElement(listRoot)
                ? Array.from(listRoot.querySelectorAll('[data-w-local-input]')).find(
                    (input) => isInput(input) && typeof input.checkValidity === 'function' && !input.checkValidity(),
                )
                : null;
            if (isInput(invalid)) {
                if (typeof invalid.reportValidity === 'function') {
                    invalid.reportValidity();
                }
                invalid.focus();
                return false;
            }

            setBusy(true);
            try {
                await submitViaParentApi(buildSavePayload());
                return true;
            } catch (error) {
                componentUI.toast.error(error instanceof Error ? error.message : String(error));
                return false;
            } finally {
                setBusy(false);
            }
        };

        const scheduleLoad = () => {
            if (element.dataset.state === 'open') {
                load();
            }
        };

        if (isInput(searchInput)) {
            listen(searchInput, 'input', () => {
                searchTerm = String(searchInput.value || '');
                renderLocaleRows(localeRows);
            });
        }

        setPanelState('idle');
        listen(element, 'weline:ui:drawer:before-open', scheduleLoad);
        listen(element, 'weline:ui:drawer:open', scheduleLoad);
        listen(element, 'click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }
            if (target.closest('[data-w-local-refresh]')) {
                event.preventDefault();
                void load(true);
                return;
            }
            if (target.closest('[data-w-local-ai]')) {
                event.preventDefault();
                event.stopPropagation();
                void runAiTranslate();
                return;
            }
            if (target.closest('[data-w-local-submit]')) {
                event.preventDefault();
                void submit();
            }
        });

        element.dispatchEvent(new CustomEvent('weline:ui:component:ready', {
            bubbles: true,
            detail: { name: 'local-translation' },
        }));
        scheduleLoad();

        return { load, submit, aiTranslate: runAiTranslate, element, destroy: () => setBusy(false) };
    });
}
