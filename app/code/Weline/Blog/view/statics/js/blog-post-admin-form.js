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
        }, { once: true });
    } else {
        init();
        initCoverWatcher();
    }
})();
