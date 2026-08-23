(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.Weline = root.Weline || {};
        root.Weline.Cms = root.Weline.Cms || {};
        root.Weline.Cms.PageEditor = api;
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    function createLocaleBuffer(initialTitles, initialLocale) {
        const titles = Object.assign({}, initialTitles || {});
        let currentLocale = String(initialLocale || '');
        return {
            currentLocale() {
                return currentLocale;
            },
            commit(value) {
                if (currentLocale) {
                    titles[currentLocale] = String(value || '');
                }
            },
            switchTo(nextLocale, currentValue) {
                this.commit(currentValue);
                currentLocale = String(nextLocale || '');
                return String(titles[currentLocale] || '');
            },
            value(locale) {
                return String(titles[String(locale || '')] || '');
            },
            has(locale) {
                return Object.prototype.hasOwnProperty.call(titles, String(locale || ''));
            },
            fillMissing(locale, value) {
                const code = String(locale || '');
                const title = String(value || '');
                if (!code || !this.has(code) || !title.trim() || this.value(code).trim()) {
                    return false;
                }
                titles[code] = title;
                return true;
            },
            toObject() {
                return Object.assign({}, titles);
            },
        };
    }

    function missingLocales(supportedLocales, titles, sourceLocale) {
        const values = titles && typeof titles === 'object' ? titles : {};
        const source = String(sourceLocale || '');
        const seen = new Set();
        return (Array.isArray(supportedLocales) ? supportedLocales : []).reduce(function (missing, locale) {
            const code = String(locale || '').trim();
            if (!code || code === source || seen.has(code)) {
                return missing;
            }
            seen.add(code);
            if (!String(values[code] || '').trim()) {
                missing.push(code);
            }
            return missing;
        }, []);
    }

    function mergeTranslationResults(buffer, results, currentValue) {
        if (!buffer || typeof buffer.commit !== 'function') {
            return String(currentValue || '');
        }
        buffer.commit(currentValue);
        const entries = results && typeof results === 'object' ? Object.entries(results) : [];
        entries.forEach(function ([locale, result]) {
            if (!result || String(result.status || '') !== 'saved') {
                return;
            }
            buffer.fillMissing(locale, result.title);
        });
        return buffer.value(buffer.currentLocale());
    }

    function slugifyEnglish(title) {
        return String(title || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 160) || 'page';
    }

    function resolveSlugUpdate(options) {
        if (String(options.mode || '') !== 'auto'
            || String(options.locale || '') !== String(options.sourceLocale || '')) {
            return String(options.currentSlug || '');
        }
        return slugifyEnglish(options.title);
    }

    function unwrapRuntimeValue(value) {
        let current = value;
        for (let depth = 0; depth < 5; depth++) {
            if (!current || typeof current !== 'object') {
                return {};
            }
            if ('task_id' in current || 'status' in current || 'result' in current || 'checkpoint' in current) {
                return current;
            }
            const next = current.data && typeof current.data === 'object'
                ? current.data
                : (current.task && typeof current.task === 'object' ? current.task : null);
            if (!next || next === current) {
                return current;
            }
            current = next;
        }
        return current && typeof current === 'object' ? current : {};
    }

    function normalizeRuntimeTaskHandle(value) {
        const task = unwrapRuntimeValue(value);
        if (typeof task.task_id !== 'string' || !task.task_id
            || typeof task.lease_id !== 'string' || !task.lease_id) {
            throw new Error('Invalid runtime task response.');
        }
        return {
            task_id: task.task_id,
            lease_id: task.lease_id,
            stream_channel: typeof task.stream_channel === 'string' ? task.stream_channel : '',
        };
    }

    function runtimeResultData(value) {
        const snapshot = unwrapRuntimeValue(value);
        const result = snapshot.result;
        if (!result || typeof result !== 'object') {
            return {};
        }
        return result.data && typeof result.data === 'object' ? result.data : result;
    }

    function isRuntimeTerminal(status) {
        return [
            'completed',
            'failed',
            'cancelled',
            'canceled',
            'expired',
            'recovery_unsafe',
            'event_backlog_limit',
        ].includes(String(status || '').toLowerCase());
    }

    function defaultSleep(ms) {
        return new Promise(function (resolve) {
            globalThis.setTimeout(resolve, ms);
        });
    }

    async function runTranslationTask(options) {
        const settings = options || {};
        if (!settings.api || typeof settings.api.resource !== 'function') {
            throw new Error('Runtime task API is unavailable.');
        }
        const taskApi = settings.api.resource('runtime_task');
        let task = settings.task ? normalizeRuntimeTaskHandle(settings.task) : null;
        if (!task) {
            task = normalizeRuntimeTaskHandle(await taskApi.start({
                type_code: 'cms.page_translation',
                input: {
                    page_id: Number(settings.pageId || 0),
                    store_id: Number(settings.storeId || 0),
                    request_id: String(settings.requestId || ''),
                },
            }, { silent: true }));
        }
        if (typeof settings.onTask === 'function') {
            settings.onTask(task);
        }

        const sleep = typeof settings.sleep === 'function' ? settings.sleep : defaultSleep;
        const now = typeof settings.now === 'function' ? settings.now : Date.now;
        const waitMs = Number(settings.intervalMs) > 0 ? Number(settings.intervalMs) : 1000;
        const touchEveryMs = Number(settings.touchEveryMs) > 0 ? Number(settings.touchEveryMs) : 15000;
        let nextTouchAt = 0;

        while (!(typeof settings.isStopped === 'function' && settings.isStopped())) {
            const timestamp = Number(now());
            if (timestamp >= nextTouchAt) {
                nextTouchAt = timestamp + touchEveryMs;
                try {
                    await taskApi.touch({
                        task_id: task.task_id,
                        lease_id: task.lease_id,
                    }, { silent: true });
                } catch (error) {
                    // Lease renewal is best-effort; status remains authoritative.
                }
            }
            const snapshot = unwrapRuntimeValue(await taskApi.status({
                task_id: task.task_id,
            }, { silent: true }));
            if (typeof settings.onUpdate === 'function') {
                settings.onUpdate(snapshot, task);
            }
            if (isRuntimeTerminal(snapshot.status)) {
                return {
                    task,
                    snapshot,
                    data: runtimeResultData(snapshot),
                    detached: false,
                };
            }
            await sleep(waitMs);
        }

        return { task, snapshot: null, data: {}, detached: true };
    }

    function resolveApiModule(value) {
        const candidates = [value, value && value.default, value && value.api, value && value.default && value.default.api];
        return candidates.find(function (candidate) {
            return candidate && typeof candidate.resource === 'function';
        }) || null;
    }

    async function loadRuntimeApi(host) {
        if (host.Weline && host.Weline.Api && typeof host.Weline.Api.resource === 'function') {
            return host.Weline.Api;
        }
        if (!host.Weline || typeof host.Weline.load !== 'function') {
            throw new Error('Weline API loader is unavailable.');
        }
        const api = resolveApiModule(await host.Weline.load('api'));
        if (!api) {
            throw new Error('Weline runtime task API is unavailable.');
        }
        host.Weline.Api = api;
        return host.Weline.Api;
    }

    function createTranslationRequestId(pageId, host) {
        const prefix = 'cms-page-' + Number(pageId || 0) + '-translation';
        if (host.crypto && typeof host.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            host.crypto.getRandomValues(bytes);
            return prefix + '-' + Array.from(bytes, function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        }
        return prefix + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 14);
    }

    function translationStorageKey(pageId, storeId) {
        return 'weline:cms:page-translation:' + Number(pageId || 0) + ':' + Number(storeId || 0);
    }

    function persistTranslationTask(pageId, storeId, task, host) {
        try {
            host.sessionStorage.setItem(translationStorageKey(pageId, storeId), JSON.stringify(task));
        } catch (error) {
            // Storage is optional; the server task remains recoverable independently.
        }
    }

    function loadTranslationTask(pageId, storeId, host) {
        try {
            const raw = host.sessionStorage.getItem(translationStorageKey(pageId, storeId));
            return raw ? normalizeRuntimeTaskHandle(JSON.parse(raw)) : null;
        } catch (error) {
            try {
                host.sessionStorage.removeItem(translationStorageKey(pageId, storeId));
            } catch (ignored) {
                // Storage is optional.
            }
            return null;
        }
    }

    function clearTranslationTask(pageId, storeId, host) {
        try {
            host.sessionStorage.removeItem(translationStorageKey(pageId, storeId));
        } catch (error) {
            // Storage is optional.
        }
    }

    function notify(message, type) {
        if (typeof window === 'undefined') {
            return;
        }
        if (window.Weline && typeof window.Weline.toast === 'function') {
            window.Weline.toast(message, type || 'info');
            return;
        }
        window.dispatchEvent(new CustomEvent('weline:toast', {
            detail: { message, type: type || 'info' },
        }));
    }

    function translateUi(message) {
        return typeof __ !== 'undefined' && typeof __ === 'function' ? __(message) : message;
    }

    function init() {
        if (typeof document === 'undefined') {
            return null;
        }
        const form = document.querySelector('.cms-page-settings-bar');
        const stateNode = document.getElementById('cms-page-editor-state');
        if (!form || !stateNode) {
            return null;
        }

        let payload = {};
        try {
            payload = JSON.parse(stateNode.textContent || '{}');
        } catch (error) {
            notify(translateUi('CMS 编辑器语言数据无效。'), 'error');
            return null;
        }

        const titleInput = form.querySelector('[name="title"]');
        const localeInput = form.querySelector('[name="locale_code"]');
        const sourceLocaleInput = form.querySelector('[name="source_locale"]');
        const titlesInput = form.querySelector('[name="locale_titles_json"]');
        const slugInput = form.querySelector('[name="slug"]');
        const slugModeInput = form.querySelector('[name="slug_mode"]');
        const frame = document.querySelector('.cms-theme-editor-frame');
        const layoutInput = form.querySelector('[name="layout_option"]');
        const storeInput = form.querySelector('[name="store_id"]');
        const storeCodeInput = form.querySelector('[name="store_code"]');
        const storeModeInput = form.querySelector('[name="store_mode"]');
        const translateButton = form.querySelector('[data-cms-translate-missing]');
        const translationLabel = form.querySelector('[data-cms-translation-label]');
        const translationStatus = form.querySelector('[data-cms-translation-status]');
        if (!titleInput || !localeInput || !sourceLocaleInput || !titlesInput || !slugInput || !slugModeInput) {
            return null;
        }

        const buffer = createLocaleBuffer(payload.titles || {}, payload.current_locale || localeInput.value);
        const sourceLocale = String(payload.source_locale || sourceLocaleInput.value || '');
        const persistedSourceTitle = buffer.value(sourceLocale);
        const supportedLocales = Array.isArray(payload.supported_locales) ? payload.supported_locales : [];
        const pageId = Number(payload.page_id || 0);
        const currentStoreId = Number(storeInput ? storeInput.value : payload.storeId || 0);
        const translationConfig = payload.translation && typeof payload.translation === 'object' ? payload.translation : {};
        const translationMessages = translationConfig.messages && typeof translationConfig.messages === 'object'
            ? translationConfig.messages
            : {};
        let translationRunning = false;
        let translationDetached = false;
        let cmsFormDirty = false;
        sourceLocaleInput.value = sourceLocale;
        localeInput.value = buffer.currentLocale();
        titleInput.value = buffer.value(buffer.currentLocale());

        function syncTitles() {
            buffer.commit(titleInput.value);
            titlesInput.value = JSON.stringify(buffer.toObject());
        }

        function syncAutoSlug() {
            slugInput.value = resolveSlugUpdate({
                mode: slugModeInput.value,
                locale: buffer.currentLocale(),
                sourceLocale,
                title: titleInput.value,
                currentSlug: slugInput.value,
            });
        }

        let bridge = null;
        const previewBridge = window.Weline?.Theme?.CmsPreviewBridge;
        if (frame && previewBridge) {
            bridge = previewBridge.createParentBridge({
                hostWindow: window,
                targetWindow: frame.contentWindow,
                timeoutMs: 10000,
            });
            bridge.start();
        }

        function currentLayoutOption() {
            const field = form.querySelector('[name="layout_option"]');
            return String(field ? field.value : payload.layout_option || 'default');
        }

        function selectedStoreContext() {
            const option = storeInput && storeInput.selectedOptions ? storeInput.selectedOptions[0] : null;
            return {
                storeId: Number(storeInput ? storeInput.value : payload.storeId || 0),
                storeCode: String(option?.dataset?.storeCode || storeCodeInput?.value || payload.storeCode || ''),
                storeMode: String(option?.dataset?.storeMode || storeModeInput?.value || payload.storeMode || 'normal'),
                canonicalScope: String(option?.dataset?.canonicalScope || payload.scope || ''),
            };
        }

        function buildPreviewContext(overrides) {
            const store = selectedStoreContext();
            const context = Object.assign({
                pageId: Number(payload.pageId || pageId || 0),
                websiteId: Number(payload.websiteId || 0),
                websiteCode: String(payload.websiteCode || ''),
                storeId: store.storeId,
                storeCode: store.storeCode,
                storeMode: store.storeMode,
                scope: store.canonicalScope,
                locale: buffer.currentLocale(),
                layoutOption: currentLayoutOption(),
            }, overrides || {});
            if (overrides && 'canonicalScope' in overrides) {
                context.scope = String(overrides.canonicalScope || '');
            }
            return context;
        }

        function syncPreviewContext(overrides) {
            if (!bridge) {
                // A loaded Theme iframe without the v2 bridge cannot prove its
                // dirty state. Fail closed so Store/Locale/Layout changes never
                // discard an editor session merely because an asset failed to
                // initialize. New pages without an iframe remain switchable.
                return Promise.resolve({
                    ok: !frame,
                    fallback: false,
                    reason: frame ? 'bridge-unavailable' : 'no-theme-frame',
                });
            }
            return bridge.setContext(buildPreviewContext(overrides)).then(function (result) {
                if (result.dirty) {
                    notify(translateUi('Theme 编辑器有未保存修改，请先保存后再切换语言或布局。'), 'warning');
                } else if (!result.ok && !result.fallback && result.reason !== 'superseded') {
                    notify(translateUi('Theme 预览同步失败，已保留 CMS 表单内容。'), 'warning');
                }
                return result;
            });
        }

        function contextSwitchAccepted(result) {
            return Boolean(result && result.ok === true);
        }

        function translationMessage(key, fallback) {
            return String(translationMessages[key] || fallback || '');
        }

        function setTranslationStatus(message, state) {
            if (!translationStatus) {
                return;
            }
            translationStatus.textContent = String(message || '');
            translationStatus.classList.remove('is-success', 'is-warning', 'is-error');
            if (state) {
                translationStatus.classList.add('is-' + state);
            }
        }

        function setTranslationBusy(busy) {
            if (!translateButton) {
                return;
            }
            translateButton.disabled = Boolean(busy);
            if (busy) {
                translateButton.setAttribute('aria-busy', 'true');
            } else {
                translateButton.removeAttribute('aria-busy');
            }
        }

        function remainingTranslationLocales() {
            buffer.commit(titleInput.value);
            return missingLocales(supportedLocales, buffer.toObject(), sourceLocale);
        }

        function refreshTranslationButton(preserveStatus) {
            if (!translateButton || !translationLabel) {
                return;
            }
            const baseLabel = translationMessage('button', '翻译缺失语言');
            if (translationRunning) {
                translationLabel.textContent = translationMessage('running', '翻译进行中');
                setTranslationBusy(true);
                return;
            }
            if (pageId < 1) {
                translationLabel.textContent = baseLabel;
                setTranslationBusy(true);
                if (!preserveStatus) {
                    setTranslationStatus(translationMessage('save_first', '请先保存页面，再翻译缺失语言。'), 'warning');
                }
                return;
            }
            const remaining = remainingTranslationLocales();
            const storedTask = loadTranslationTask(pageId, currentStoreId, window);
            if (storedTask) {
                translationLabel.textContent = translationMessage('continue', '继续查看翻译任务');
                setTranslationBusy(false);
                return;
            }
            translationLabel.textContent = remaining.length > 0 ? baseLabel + ' (' + remaining.length + ')' : baseLabel;
            setTranslationBusy(remaining.length === 0);
            if (!preserveStatus) {
                if (remaining.length === 0) {
                    setTranslationStatus(translationMessage('complete', '网站支持的语言均已补全。'), 'success');
                } else {
                    setTranslationStatus(translationMessage('ready', '待补全语言：') + ' ' + remaining.join(', '), '');
                }
            }
        }

        function renderTranslationProgress(snapshot) {
            const checkpoint = snapshot && snapshot.checkpoint && snapshot.checkpoint.state;
            const state = checkpoint && typeof checkpoint === 'object' ? checkpoint : {};
            const targets = Array.isArray(state.target_locales) ? state.target_locales : remainingTranslationLocales();
            const total = Number(state.total || targets.length || 0);
            const completed = Number(state.next_index || state.completed || 0);
            let message = translationMessage('progress', '正在翻译语言') + ' ' + completed + '/' + total;
            if (state.current_locale) {
                message += ' · ' + String(state.current_locale);
            }
            setTranslationStatus(message, '');
        }

        async function startOrResumeTranslation(taskToResume) {
            if (translationRunning || !translateButton) {
                return;
            }
            syncTitles();
            const task = taskToResume || loadTranslationTask(pageId, currentStoreId, window);
            const remaining = remainingTranslationLocales();
            if (pageId < 1 || (!task && remaining.length === 0)) {
                refreshTranslationButton();
                return;
            }
            if (!task && buffer.value(sourceLocale).trim() !== persistedSourceTitle.trim()) {
                setTranslationStatus(
                    translationMessage('save_source_first', '英语标题已修改，请先保存页面再翻译。'),
                    'warning'
                );
                return;
            }

            translationRunning = true;
            setTranslationBusy(true);
            translationLabel.textContent = translationMessage('running', '翻译进行中');
            setTranslationStatus(
                task
                    ? translationMessage('reconnecting', '正在恢复上次翻译任务…')
                    : translationMessage('starting', '正在启动翻译任务…'),
                ''
            );

            try {
                const api = await loadRuntimeApi(window);
                const outcome = await runTranslationTask({
                    api,
                    task,
                    pageId,
                    storeId: Number(storeInput ? storeInput.value : payload.storeId || 0),
                    requestId: createTranslationRequestId(pageId, window),
                    isStopped: function () { return translationDetached; },
                    onTask: function (runningTask) {
                        persistTranslationTask(pageId, currentStoreId, runningTask, window);
                    },
                    onUpdate: renderTranslationProgress,
                });
                if (outcome.detached) {
                    return;
                }

                const status = String(outcome.snapshot && outcome.snapshot.status || '').toLowerCase();
                clearTranslationTask(pageId, currentStoreId, window);
                if (status !== 'completed') {
                    setTranslationStatus(translationMessage('failed', '翻译任务未完成，请稍后重试。'), 'error');
                    return;
                }

                const results = outcome.data && outcome.data.results && typeof outcome.data.results === 'object'
                    ? outcome.data.results
                    : {};
                titleInput.value = mergeTranslationResults(buffer, results, titleInput.value);
                syncTitles();
                syncAutoSlug();
                const after = remainingTranslationLocales();
                if (after.length === 0) {
                    setTranslationStatus(translationMessage('complete', '网站支持的语言均已补全。'), 'success');
                } else {
                    setTranslationStatus(
                        translationMessage('completed_partial', '翻译任务完成，仍有未补全语言：') + ' ' + after.join(', '),
                        'warning'
                    );
                }
            } catch (error) {
                setTranslationStatus(
                    translationMessage('disconnected', '连接暂时中断，点击按钮可继续查看任务。'),
                    'warning'
                );
                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('[Weline CMS] page translation task unavailable', error);
                }
            } finally {
                translationRunning = false;
                refreshTranslationButton(true);
            }
        }

        localeInput.addEventListener('change', async function () {
            const nextLocale = String(localeInput.value || '');
            const previousLocale = buffer.currentLocale();
            if (!nextLocale || nextLocale === previousLocale) {
                return;
            }
            const result = await syncPreviewContext({ locale: nextLocale });
            if (result && result.reason === 'superseded') {
                return;
            }
            if (!contextSwitchAccepted(result)) {
                localeInput.value = previousLocale;
                return;
            }
            titleInput.value = buffer.switchTo(nextLocale, titleInput.value);
            syncTitles();
            syncAutoSlug();
            refreshTranslationButton();
        });

        if (storeInput) {
            let previousStoreId = String(storeInput.value || '');
            storeInput.addEventListener('change', async function () {
                if (cmsFormDirty) {
                    storeInput.value = previousStoreId;
                    notify(translateUi('CMS 表单有未保存修改，请先保存后再切换店铺。'), 'warning');
                    return;
                }
                const option = storeInput.selectedOptions ? storeInput.selectedOptions[0] : null;
                const nextStoreId = String(storeInput.value || '');
                const nextStoreCode = String(option?.dataset?.storeCode || '');
                const nextStoreMode = String(option?.dataset?.storeMode || 'normal');
                const nextCanonicalScope = String(option?.dataset?.canonicalScope || '');
                const result = await syncPreviewContext({
                    storeId: Number(nextStoreId || 0),
                    storeCode: nextStoreCode,
                    storeMode: nextStoreMode,
                    canonicalScope: nextCanonicalScope,
                });
                if (result && result.reason === 'superseded') {
                    return;
                }
                if (!contextSwitchAccepted(result)) {
                    storeInput.value = previousStoreId;
                    return;
                }
                if (storeCodeInput) {
                    storeCodeInput.value = nextStoreCode;
                }
                if (storeModeInput) {
                    storeModeInput.value = nextStoreMode;
                }
                previousStoreId = nextStoreId;
                const url = new URL(window.location.href);
                url.searchParams.set('store_id', nextStoreId);
                url.searchParams.set('locale_code', buffer.currentLocale());
                window.location.assign(url.toString());
            });
        }

        titleInput.addEventListener('input', function () {
            syncTitles();
            syncAutoSlug();
            if (!translationRunning) {
                refreshTranslationButton();
            }
        });
        slugInput.addEventListener('input', function () {
            slugModeInput.value = 'manual';
        });
        form.querySelector('[data-cms-slug-auto]')?.addEventListener('click', function () {
            cmsFormDirty = true;
            slugModeInput.value = 'auto';
            const sourceTitle = buffer.value(sourceLocale) || (buffer.currentLocale() === sourceLocale ? titleInput.value : '');
            slugInput.value = slugifyEnglish(sourceTitle);
            slugInput.focus();
        });
        if (layoutInput) {
            let previousLayoutOption = currentLayoutOption();
            layoutInput.addEventListener('change', async function () {
                const nextLayoutOption = currentLayoutOption();
                if (!nextLayoutOption || nextLayoutOption === previousLayoutOption) {
                    return;
                }
                const wasDirty = cmsFormDirty;
                cmsFormDirty = true;
                const result = await syncPreviewContext({ layoutOption: nextLayoutOption });
                if (result && result.reason === 'superseded') {
                    return;
                }
                if (!contextSwitchAccepted(result)) {
                    layoutInput.value = previousLayoutOption;
                    cmsFormDirty = wasDirty;
                    return;
                }
                previousLayoutOption = nextLayoutOption;
            });
        }
        form.addEventListener('input', function (event) {
            if (event.target !== storeInput && event.target !== localeInput && event.target !== layoutInput) {
                cmsFormDirty = true;
            }
        });
        form.addEventListener('change', function (event) {
            if (event.target !== storeInput && event.target !== localeInput && event.target !== layoutInput) {
                cmsFormDirty = true;
            }
        });
        if (translateButton) {
            translateButton.addEventListener('click', function () {
                startOrResumeTranslation(loadTranslationTask(pageId, currentStoreId, window));
            });
            window.addEventListener('pagehide', function () {
                translationDetached = true;
            }, { once: true });
        }
        form.addEventListener('submit', syncTitles);

        syncTitles();
        syncAutoSlug();
        syncPreviewContext();
        refreshTranslationButton();
        const storedTranslationTask = loadTranslationTask(pageId, currentStoreId, window);
        if (storedTranslationTask) {
            Promise.resolve().then(function () {
                return startOrResumeTranslation(storedTranslationTask);
            });
        }

        return { buffer, bridge, syncPreviewContext, startOrResumeTranslation }; 
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init, { once: true });
        } else {
            init();
        }
    }

    return {
        createLocaleBuffer,
        slugifyEnglish,
        resolveSlugUpdate,
        missingLocales,
        mergeTranslationResults,
        normalizeRuntimeTaskHandle,
        runtimeResultData,
        runTranslationTask,
        init,
    };
});
