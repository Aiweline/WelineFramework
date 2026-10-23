(function () {
    'use strict';

    function slugify(title) {
        if (window.Weline && Weline.Cms && Weline.Cms.PageEditor && typeof Weline.Cms.PageEditor.slugifyEnglish === 'function') {
            return Weline.Cms.PageEditor.slugifyEnglish(title);
        }
        return String(title || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 160) || 'page';
    }

    function notify(message, type) {
        if (window.Weline && typeof window.Weline.toast === 'function') {
            window.Weline.toast(message, type || 'info');
            return;
        }
        window.dispatchEvent(new CustomEvent('weline:toast', {
            detail: { message: message, type: type || 'info' },
        }));
    }

    function translateUi(message) {
        return typeof __ !== 'undefined' && typeof __ === 'function' ? __(message) : message;
    }

    function unwrapPayload(json) {
        if (!json || typeof json !== 'object') {
            return {};
        }
        if (json.data && typeof json.data === 'object') {
            return json.data;
        }
        return json;
    }

    async function postForm(url, fields) {
        const body = new FormData();
        Object.keys(fields).forEach(function (key) {
            const value = fields[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key, item);
                });
                return;
            }
            body.append(key, value);
        });
        const response = await fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
        });
        return await response.json();
    }

    function initTranslationPanel() {
        const panel = document.querySelector('[data-blog-translate]');
        if (!panel) {
            return;
        }
        const translateUrl = panel.getAttribute('data-translate-url') || '';
        const auto_translate_url = panel.getAttribute('data-auto-translate-url') || '';
        const statusEl = panel.querySelector('[data-blog-translate-status]');
        const localeField = panel.querySelector('[data-w-language-field]');
        const overwriteEl = panel.querySelector('[data-blog-translate-overwrite]');
        const draftEl = panel.querySelector('[data-blog-translate-draft]');
        const translateButton = panel.querySelector('[data-blog-translate-button]');
        const autoTranslateEl = panel.querySelector('[data-blog-auto-translate]');
        const postIdInput = document.querySelector('.blog-post-settings-bar [name="post_id"]');
        const postId = postIdInput ? String(postIdInput.value || '') : '0';

        if (translateButton) {
            translateButton.addEventListener('click', async function () {
                const locales = localeField ? Array.from(localeField.selectedOptions).map(function (option) {
                    return option.value;
                }).filter(Boolean) : [];
                if (locales.length === 0) {
                    notify(panel.dataset.msgEmpty, 'warning');
                    return;
                }
                translateButton.disabled = true;
                if (statusEl) {
                    statusEl.textContent = panel.dataset.msgWorking;
                    statusEl.removeAttribute('hidden');
                }
                try {
                    const fields = {
                        post_id: postId,
                        overwrite: overwriteEl && overwriteEl.checked ? '1' : '0',
                        as_draft: draftEl && draftEl.checked ? '1' : '0',
                        'locales[]': locales,
                    };
                    const json = await postForm(translateUrl, fields);
                    if (json && json.success) {
                        notify(json.msg || translateUi('翻译完成'), 'success');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);
                    } else {
                        const message = (json && json.msg) || panel.dataset.msgFailed;
                        if (statusEl) {
                            statusEl.textContent = message;
                        }
                        notify(message, 'error');
                    }
                } catch (error) {
                    if (statusEl) {
                        statusEl.textContent = panel.dataset.msgFailed;
                    }
                    notify(panel.dataset.msgFailed, 'error');
                } finally {
                    translateButton.disabled = false;
                }
            });
        }

        if (autoTranslateEl) {
            autoTranslateEl.addEventListener('change', async function () {
                const enabled = autoTranslateEl.checked ? '1' : '0';
                try {
                    const json = await postForm(auto_translate_url, { enabled: enabled });
                    if (json && !json.success) {
                        autoTranslateEl.checked = !autoTranslateEl.checked;
                        notify(json.msg || panel.dataset.msgFailed, 'error');
                    } else {
                        notify(json.msg || translateUi('已保存'), 'success');
                    }
                } catch (error) {
                    autoTranslateEl.checked = !autoTranslateEl.checked;
                    notify(panel.dataset.msgFailed, 'error');
                }
            });
        }
    }

    function init() {
        const form = document.querySelector('.blog-post-settings-bar');
        if (!form) {
            return;
        }

        const titleInput = form.querySelector('[name="title"]');
        const slugInput = form.querySelector('[name="slug"]');
        const slugModeInput = form.querySelector('[name="slug_mode"]');
        const localeInput = form.querySelector('[name="locale"]');
        const aiButton = form.querySelector('[data-blog-slug-ai]');
        const autoButton = form.querySelector('[data-blog-slug-auto]');
        const suggestUrl = form.getAttribute('data-suggest-slug-url') || '';

        if (!titleInput || !slugInput || !slugModeInput) {
            return;
        }

        let autoSlugTimer = null;
        let autoSlugRequestSerial = 0;

        async function requestAutoSlug(title) {
            if (!suggestUrl) {
                return slugify(title);
            }
            const requestSerial = ++autoSlugRequestSerial;
            const body = new FormData();
            body.append('title', title);
            body.append('locale', localeInput ? String(localeInput.value || '') : '');
            body.append('use_ai', '0');
            const response = await fetch(suggestUrl, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
            });
            const json = await response.json();
            if (requestSerial !== autoSlugRequestSerial) {
                return '';
            }
            const payload = unwrapPayload(json);
            const slug = String(payload.slug || '').trim();
            return slug || slugify(title);
        }

        function syncAutoSlugImmediate(title) {
            const normalized = String(title || '').trim();
            if (normalized === '') {
                slugInput.value = '';
                return;
            }
            if (/^[\x00-\x7F]+$/.test(normalized)) {
                slugInput.value = slugify(normalized);
            }
        }

        function syncAutoSlug() {
            if (slugModeInput.value !== 'auto') {
                return;
            }
            const title = String(titleInput.value || '').trim();
            syncAutoSlugImmediate(title);
            if (title === '') {
                return;
            }
            clearTimeout(autoSlugTimer);
            autoSlugTimer = window.setTimeout(function () {
                if (slugModeInput.value !== 'auto') {
                    return;
                }
                const pendingTitle = String(titleInput.value || '').trim();
                if (pendingTitle === '') {
                    slugInput.value = '';
                    return;
                }
                requestAutoSlug(pendingTitle).then(function (slug) {
                    if (slugModeInput.value !== 'auto') {
                        return;
                    }
                    if (String(titleInput.value || '').trim() !== pendingTitle) {
                        return;
                    }
                    if (slug) {
                        slugInput.value = slug;
                    }
                }).catch(function () {
                    if (slugModeInput.value === 'auto') {
                        slugInput.value = slugify(pendingTitle);
                    }
                });
            }, 180);
        }

        function syncAiButtonState() {
            if (!aiButton) {
                return;
            }
            const hasTitle = String(titleInput.value || '').trim() !== '';
            aiButton.disabled = !hasTitle;
            aiButton.setAttribute('aria-disabled', hasTitle ? 'false' : 'true');
            aiButton.classList.toggle('is-disabled', !hasTitle);
        }

        function onTitleInput() {
            syncAutoSlug();
            syncAiButtonState();
        }

        titleInput.addEventListener('input', onTitleInput);
        if (localeInput) {
            localeInput.addEventListener('change', function () {
                if (slugModeInput.value === 'auto') {
                    syncAutoSlug();
                }
            });
        }
        slugInput.addEventListener('input', function () {
            slugModeInput.value = 'manual';
        });

        if (autoButton) {
            autoButton.addEventListener('click', function () {
                slugModeInput.value = 'auto';
                syncAutoSlug();
                slugInput.focus();
            });
        }

        if (aiButton) {
            aiButton.addEventListener('click', async function () {
                const title = String(titleInput.value || '').trim();
                if (!title) {
                    return;
                }
                if (!suggestUrl) {
                    notify(translateUi('Slug 建议接口不可用'), 'error');
                    return;
                }

                aiButton.disabled = true;
                try {
                    const body = new FormData();
                    body.append('title', title);
                    body.append('locale', localeInput ? String(localeInput.value || '') : '');
                    body.append('use_ai', '1');
                    const response = await fetch(suggestUrl, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                    });
                    const json = await response.json();
                    const payload = unwrapPayload(json);
                    const slug = String(payload.slug || '').trim();
                    if (slug) {
                        slugInput.value = slug;
                        slugModeInput.value = 'ai';
                        notify(translateUi('Slug 已生成'), 'success');
                    } else {
                        notify(String(json && json.msg ? json.msg : translateUi('AI slug 生成失败')), 'error');
                    }
                } catch (error) {
                    notify(translateUi('AI slug 请求失败'), 'error');
                } finally {
                    syncAiButtonState();
                }
            });
        }

        syncAutoSlug();
        syncAiButtonState();
    }

    function coverPreviewUrl(path) {
        const value = String(path || '').trim();
        if (value === '') {
            return '';
        }
        if (/^https?:\/\//i.test(value) || value.startsWith('/')) {
            return value;
        }
        const relative = value
            .replace(/^\/pub\/media\//, '')
            .replace(/^pub\/media\//, '')
            .replace(/^\/+/, '');
        return relative === '' ? '' : '/pub/media/' + relative;
    }

    function openCoverPicker() {
        const panel = document.querySelector('[data-blog-cover-panel]');
        const trigger = panel?.querySelector('[data-w-file-picker-open]');
        if (trigger instanceof HTMLButtonElement) {
            trigger.click();
            return;
        }
        notify(translateUi('封面选择器不可用，请刷新页面后重试'), 'warning');
    }

    function syncCoverPickerLabel() {
        const panel = document.querySelector('[data-blog-cover-panel]');
        const trigger = panel?.querySelector('[data-w-file-picker-open] span');
        const coverInput = document.getElementById('blog-cover');
        if (!trigger || !coverInput) {
            return;
        }
        const hasCover = String(coverInput.value || '').trim() !== '';
        trigger.textContent = hasCover ? translateUi('更换封面') : translateUi('选择封面');
    }

    function syncCoverPanel() {
        const panel = document.querySelector('[data-blog-cover-panel]');
        const coverInput = document.getElementById('blog-cover');
        if (!panel || !coverInput) {
            return;
        }
        const value = String(coverInput.value || '').trim();
        panel.classList.toggle('has-cover', value !== '');
        panel.classList.toggle('is-empty', value === '');
        let preview = panel.querySelector('[data-blog-cover-preview]');
        if (value === '') {
            if (preview) {
                preview.remove();
            }
            syncCoverPickerLabel();
            return;
        }
        const previewUrl = coverPreviewUrl(value);
        if (!previewUrl) {
            syncCoverPickerLabel();
            return;
        }
        if (!preview) {
            preview = document.createElement('img');
            preview.className = 'blog-post-cover-preview';
            preview.setAttribute('data-blog-cover-preview', '');
            preview.alt = translateUi('封面预览');
            preview.setAttribute('role', 'button');
            preview.setAttribute('tabindex', '0');
            preview.title = translateUi('点击更换封面');
            panel.insertBefore(preview, panel.firstChild);
        }
        preview.src = previewUrl;
        preview.removeAttribute('hidden');
        syncCoverPickerLabel();
    }

    function loadRuntimeApi(host) {
        if (host.Weline && typeof host.Weline.load === 'function') {
            return host.Weline.load('api').then(function () {
                return host.Weline;
            });
        }
        return Promise.reject(new Error('Weline runtime unavailable'));
    }

    function initBlogCmsThemeEditor() {
        const stateNode = document.getElementById('blog-cms-editor-state');
        const frame = document.querySelector('.blog-post-editor-card .cms-theme-editor-frame');
        if (!stateNode || !frame) {
            return;
        }
        let payload = {};
        try {
            payload = JSON.parse(stateNode.textContent || '{}');
        } catch (error) {
            notify(translateUi('CMS 编辑器桥接数据无效。'), 'error');
            return;
        }
        const pageEditor = window.Weline && Weline.Cms && Weline.Cms.PageEditor;
        const previewBridge = window.Weline && Weline.Theme && Weline.Theme.CmsPreviewBridge;
        if (!previewBridge) {
            return;
        }
        const pageId = Number(payload.pageId || payload.page_id || 0);
        const storeId = Number(payload.storeId || 0);
        const translateButton = document.querySelector('[data-blog-cms-translate-missing]');
        const translationStatus = document.querySelector('[data-blog-cms-translate-status]');
        const translationConfig = payload.translation && typeof payload.translation === 'object' ? payload.translation : {};
        const translationMessages = translationConfig.messages && typeof translationConfig.messages === 'object'
            ? translationConfig.messages
            : {};
        let translationRunning = false;

        function translationMessage(key, fallback) {
            return String(translationMessages[key] || fallback || '');
        }

        function setTranslationStatus(message, tone) {
            if (!translationStatus) {
                return;
            }
            translationStatus.textContent = String(message || '');
            translationStatus.removeAttribute('hidden');
            translationStatus.classList.remove('is-success', 'is-warning', 'is-error');
            if (tone) {
                translationStatus.classList.add('is-' + tone);
            }
        }

        function buildPreviewContext(overrides) {
            return Object.assign({
                pageId: pageId,
                websiteId: Number(payload.websiteId || 0),
                websiteCode: String(payload.websiteCode || ''),
                storeId: storeId,
                storeCode: String(payload.storeCode || ''),
                storeMode: String(payload.storeMode || 'normal'),
                scope: String(payload.scope || ''),
                locale: String(payload.current_locale || ''),
                layoutOption: String(payload.layout_option || 'default'),
                publicRoute: String(payload.publicRoute || ''),
            }, overrides || {});
        }

        const bridge = previewBridge.createParentBridge({
            hostWindow: window,
            targetWindow: frame.contentWindow,
            timeoutMs: 15000,
        });
        bridge.start();

        function syncPreviewContext(overrides) {
            return bridge.setContext(buildPreviewContext(overrides)).then(function (result) {
                if (result.dirty) {
                    notify(translateUi('Theme 编辑器有未保存修改，请先保存后再切换。'), 'warning');
                } else if (!result.ok && !result.fallback && result.reason !== 'superseded') {
                    notify(translateUi('Theme 预览同步失败。'), 'warning');
                }
                return result;
            });
        }

        function pushContextWhenReady() {
            if (!frame.contentWindow) {
                return;
            }
            syncPreviewContext().catch(function () {
                // iframe may still be booting; one retry after load.
            });
        }

        frame.addEventListener('load', pushContextWhenReady, { once: false });
        if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
            pushContextWhenReady();
        }

        const localeInput = document.querySelector('.blog-post-settings-bar [name="locale"]');
        if (localeInput) {
            localeInput.addEventListener('change', async function () {
                const nextLocale = String(localeInput.value || '');
                const previousLocale = String(payload.current_locale || '');
                if (!nextLocale || nextLocale === previousLocale) {
                    return;
                }
                const result = await syncPreviewContext({ locale: nextLocale });
                if (result && result.ok) {
                    payload.current_locale = nextLocale;
                } else if (result && result.reason !== 'superseded') {
                    localeInput.value = previousLocale;
                }
            });
        }

        async function startCmsPageTranslation() {
            if (translationRunning || !pageEditor || pageId < 1) {
                return;
            }
            translationRunning = true;
            if (translateButton) {
                translateButton.disabled = true;
            }
            setTranslationStatus(translationMessage('starting', '正在启动翻译任务…'), '');
            try {
                const api = await loadRuntimeApi(window);
                const outcome = await pageEditor.runTranslationTask({
                    api: api,
                    pageId: pageId,
                    storeId: storeId,
                    requestId: 'blog-cms-' + pageId + '-' + Date.now(),
                    isStopped: function () { return false; },
                    onUpdate: function (snapshot) {
                        const checkpoint = snapshot && snapshot.checkpoint && snapshot.checkpoint.state;
                        const state = checkpoint && typeof checkpoint === 'object' ? checkpoint : {};
                        const total = Number(state.total || 0);
                        const completed = Number(state.next_index || state.completed || 0);
                        let message = translationMessage('progress', '正在翻译语言') + ' ' + completed + '/' + total;
                        if (state.current_locale) {
                            message += ' · ' + String(state.current_locale);
                        }
                        setTranslationStatus(message, '');
                    },
                });
                const status = String(outcome.snapshot && outcome.snapshot.status || '').toLowerCase();
                if (status !== 'completed') {
                    setTranslationStatus(translationMessage('failed', '翻译任务未完成。'), 'error');
                    return;
                }
                setTranslationStatus(translationMessage('complete', 'CMS 页面语言均已补全。'), 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 800);
            } catch (error) {
                setTranslationStatus(translationMessage('failed', '翻译任务未完成。'), 'error');
            } finally {
                translationRunning = false;
                if (translateButton) {
                    translateButton.disabled = false;
                }
            }
        }

        if (translateButton) {
            translateButton.addEventListener('click', function () {
                startCmsPageTranslation();
            });
        }
    }

    function initCoverWatcher() {
        const coverInput = document.getElementById('blog-cover');
        const panel = document.querySelector('[data-blog-cover-panel]');
        if (!coverInput) {
            return;
        }
        coverInput.addEventListener('change', syncCoverPanel);
        coverInput.addEventListener('input', syncCoverPanel);
        if (panel) {
            panel.addEventListener('click', function (event) {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                if (target.closest('[data-w-file-picker-open]')) {
                    return;
                }
                if (target.closest('[data-blog-cover-preview]') || target.closest('[data-blog-cover-empty]')) {
                    openCoverPicker();
                }
            });
            panel.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                const target = event.target;
                if (target instanceof Element && target.matches('[data-blog-cover-preview]')) {
                    event.preventDefault();
                    openCoverPicker();
                }
            });
        }
        syncCoverPanel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
            initCoverWatcher();
            initTranslationPanel();
            initBlogCmsThemeEditor();
        }, { once: true });
    } else {
        init();
        initCoverWatcher();
        initTranslationPanel();
        initBlogCmsThemeEditor();
    }
})();
