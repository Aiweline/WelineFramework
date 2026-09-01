/**
 * Theme brand basics drawer — Scope-owned favicon/logo via appearance /brand/* patches.
 */
(function () {
    'use strict';

    const BRAND_KEYS = ['favicon', 'apple_touch_icon', 'logo_light', 'logo_dark'];

    /** Picker constraints: icon/favicon hard 1:1; logos recommend size only. */
    const BRAND_PICKER_SPECS = {
        favicon: {
            title: '选择 Favicon',
            ext: 'png,svg,ico',
            aspectRatio: '1:1',
            recommendWidth: 128,
            recommendHeight: 128,
        },
        apple_touch_icon: {
            title: '选择 Apple Touch Icon',
            ext: 'png',
            aspectRatio: '1:1',
            recommendWidth: 180,
            recommendHeight: 180,
        },
        logo_light: {
            title: '选择 Logo（浅色）',
            ext: 'png,svg',
            recommendWidth: 320,
            recommendHeight: 80,
        },
        logo_dark: {
            title: '选择 Logo（深色）',
            ext: 'png,svg',
            recommendWidth: 320,
            recommendHeight: 80,
        },
    };

    function ui() {
        const current = window.Weline?.UI;
        if (!current) throw new Error('Weline.UI must be loaded before Theme Brand Basics.');
        return current;
    }

    function toast(message, type) {
        const tone = type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info';
        return ui().toast.show(String(message ?? ''), { tone, duration: 3000 });
    }

    function editorApi() {
        return window.Weline?.Theme?.Editor || null;
    }

    function drawerEl() {
        return document.getElementById('themeBrandBasicsDrawer');
    }

    function openDrawer() {
        const el = drawerEl();
        if (!el) return;
        el.hidden = false;
        el.dataset.state = 'open';
        el.setAttribute('data-state', 'open');
        try {
            ui().drawer?.open?.(el);
        } catch (_e) {
            el.style.display = '';
        }
        refreshFromWorkspace().catch((err) => toast(err?.message || String(err), 'error'));
    }

    function closeDrawer() {
        const el = drawerEl();
        if (!el) return;
        el.dataset.state = 'closed';
        el.setAttribute('data-state', 'closed');
        try {
            ui().drawer?.close?.(el);
        } catch (_e) {
            /* ignore */
        }
        el.hidden = true;
    }

    function mediaUrl(path) {
        const value = String(path || '').trim();
        if (!value) return '';
        if (/^(https?:)?\/\//i.test(value) || value.startsWith('/')) return value;
        return '/pub/media/' + value.replace(/^\/+/, '');
    }

    function collectDraft() {
        const out = {};
        BRAND_KEYS.forEach((key) => {
            const input = document.querySelector(`[data-w-brand-input="${key}"]`);
            out[key] = String(input?.value || '').trim();
        });
        return out;
    }

    async function loadAppearanceWorkspace() {
        const editor = editorApi();
        if (!editor?.loadScopedWorkspace) {
            throw new Error('Theme Editor scoped workspace API unavailable');
        }
        return editor.loadScopedWorkspace('appearance');
    }

    function ownershipText(workspace, draft) {
        const owned = Array.isArray(workspace?.owned_paths)
            ? workspace.owned_paths.filter((p) => String(p).startsWith('/brand/'))
            : [];
        if (owned.length) {
            return '本级修改';
        }
        const source = String(workspace?.parent_source_scope || workspace?.published_source_scope || 'theme-package-default');
        if (Object.values(draft).some(Boolean)) {
            return `继承自 ${source}`;
        }
        return '继承自主题包默认值';
    }

    async function refreshFromWorkspace() {
        const workspace = await loadAppearanceWorkspace();
        const brand = workspace?.draft_payload?.brand && typeof workspace.draft_payload.brand === 'object'
            ? workspace.draft_payload.brand
            : {};
        const ownedPaths = new Set(Array.isArray(workspace?.owned_paths) ? workspace.owned_paths : []);
        BRAND_KEYS.forEach((key) => {
            setField(key, brand[key] || '', ownedPaths.has(`/brand/${key}`));
        });
        const scopeLabel = document.querySelector('[data-w-brand-scope-label]');
        const editor = editorApi();
        const identity = (typeof editor?.getScopeIdentity === 'function'
            ? editor.getScopeIdentity()
            : (editor?.state?.scopeIdentity || {})) || {};
        if (scopeLabel) {
            const parts = ['作用范围'];
            if (identity.website_code) parts.push(`网站:${identity.website_code}`);
            if (identity.store_code) parts.push(`店铺:${identity.store_code}`);
            if (identity.channel_code) parts.push(`渠道:${identity.channel_code}`);
            scopeLabel.textContent = parts.join(' · ');
        }
        const ownership = document.querySelector('[data-w-brand-ownership]');
        if (ownership) ownership.textContent = ownershipText(workspace, brand);
        window.Weline?.Theme?.EditorChrome?.refresh?.();
        return workspace;
    }

    async function saveBrand() {
        const editor = editorApi();
        if (!editor?.queueScopedChanges) throw new Error('Theme Editor apply API unavailable');
        const draft = collectDraft();
        const workspace = await loadAppearanceWorkspace();
        const current = workspace?.draft_payload?.brand && typeof workspace.draft_payload.brand === 'object'
            ? workspace.draft_payload.brand
            : {};
        const changes = [];
        BRAND_KEYS.forEach((key) => {
            const next = draft[key];
            const prev = String(current[key] || '').trim();
            if (next === prev) return;
            if (next === '') {
                changes.push({ op: 'inherit', path: `/brand/${key}` });
            } else {
                changes.push({ op: 'set', path: `/brand/${key}`, value: next });
            }
        });
        if (!changes.length) {
            toast('没有需要保存的变更', 'info');
            return;
        }
        await editor.queueScopedChanges('appearance', changes, { summary: 'brand_basics_save' });
        await refreshFromWorkspace();
        toast('已保存到当前 Scope 草稿，发布后生效', 'success');
    }

    async function inheritBrand() {
        const editor = editorApi();
        if (!editor?.queueScopedChanges) throw new Error('Theme Editor apply API unavailable');
        const changes = BRAND_KEYS.map((key) => ({ op: 'inherit', path: `/brand/${key}` }));
        await editor.queueScopedChanges('appearance', changes, { summary: 'brand_basics_inherit' });
        await refreshFromWorkspace();
        toast('已恢复继承', 'success');
    }

    let activeMediaDialog = null;

    function sanitizeScopeSegment(value) {
        const raw = String(value ?? '').trim();
        if (!raw || raw.includes('..') || raw.includes('/') || raw.includes('\\')) return 'default';
        if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/.test(raw)) return 'default';
        return raw;
    }

    function resolveMediaScope(themeEl) {
        let website = 'default';
        let store = 'default';
        let channel = '';
        const apply = (source) => {
            if (!source || typeof source !== 'object') return;
            if (source.website_code) website = String(source.website_code);
            if (source.store_code) store = String(source.store_code);
            if (source.channel_code != null && source.channel_code !== '') {
                channel = String(source.channel_code);
            }
        };
        try {
            const params = new URLSearchParams(window.location.search);
            apply({
                website_code: params.get('website_code') || '',
                store_code: params.get('store_code') || '',
                channel_code: params.get('channel_code') || '',
            });
        } catch (_e) { /* ignore */ }
        const editor = editorApi();
        apply(typeof editor?.getScopeIdentity === 'function' ? editor.getScopeIdentity() : null);
        if (themeEl) {
            try { apply(JSON.parse(themeEl.getAttribute('data-scope-identity') || '{}')); } catch (_e2) { /* ignore */ }
        }
        website = sanitizeScopeSegment(website);
        store = sanitizeScopeSegment(store);
        channel = String(channel || '').trim();
        if (channel && channel.toLowerCase() !== 'default') {
            channel = sanitizeScopeSegment(channel);
        } else {
            channel = '';
        }
        const parts = ['websites', website, store];
        if (channel) parts.push(channel);
        return parts.join('/');
    }

    function connectorBaseUrl() {
        const editor = editorApi();
        const themeEl = document.getElementById('themeEditor');
        return String(
            editor?.config?.fileManagerConnectorBase
            || themeEl?.dataset?.fileManagerConnectorBase
            || themeEl?.getAttribute?.('data-file-manager-connector-base')
            || ''
        ).trim();
    }

    function brandStoredPath(file) {
        if (!file || typeof file !== 'object') return '';
        let raw = String(
            file.path
            || file.url
            || file.thumb
            || file.editor_preview_url
            || file.preview_url
            || ''
        ).trim();
        if (!raw) return '';
        if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('/static/') || raw.startsWith('/Weline/')) {
            return raw;
        }
        return raw
            .replace(/^\/pub\/media\//, '')
            .replace(/^pub\/media\//, '')
            .replace(/^\//, '');
    }

    function brandPreviewFromFile(file, stored) {
        const preview = String(
            file?.editor_preview_url
            || file?.preview_url
            || file?.thumb
            || file?.url
            || ''
        ).trim();
        if (preview) return preview;
        return mediaUrl(stored);
    }

    function setField(key, path, owned, previewOverride) {
        const input = document.querySelector(`[data-w-brand-input="${key}"]`);
        const upload = document.querySelector(`[data-w-brand-preview="${key}"]`);
        if (input) input.value = String(path || '');
        if (upload) {
            const empty = upload.querySelector('.w-theme-brand-upload__empty');
            const preview = upload.querySelector('.w-theme-brand-upload__preview');
            const url = String(previewOverride || mediaUrl(path) || '').trim();
            if (url && preview) {
                upload.dataset.hasImage = '1';
                preview.hidden = false;
                preview.innerHTML = `<img src="${url.replace(/"/g, '&quot;')}" alt="">`;
                if (empty) empty.hidden = true;
            } else {
                upload.dataset.hasImage = '0';
                if (preview) {
                    preview.hidden = true;
                    preview.innerHTML = '';
                }
                if (empty) empty.hidden = false;
            }
        }
        const field = document.querySelector(`[data-w-brand-field="${key}"]`);
        if (field) field.dataset.owned = owned ? '1' : '0';
    }

    function bindMediaManagerMessages(targetId, frame, onSelect, onCancel) {
        const handleMessage = (event) => {
            if (event.origin !== window.location.origin || !frame || event.source !== frame.contentWindow) {
                return;
            }
            const data = event.data;
            if (!data || typeof data !== 'object' || data.target !== targetId) return;
            if (data.type === 'weline-media-manager-select') {
                onSelect(data.files || []);
                return;
            }
            if (data.type === 'weline-media-manager-cancel' && typeof onCancel === 'function') {
                onCancel();
            }
        };
        window.addEventListener('message', handleMessage);
        return () => window.removeEventListener('message', handleMessage);
    }

    function openBrandMediaDialog(options) {
        const currentUi = ui();
        if (!options?.targetId || !options?.url) return false;
        if (activeMediaDialog?.isConnected) {
            activeMediaDialog.focus({ preventScroll: true });
            return false;
        }

        const dialog = document.createElement('dialog');
        dialog.className = 'w-dialog w-param-media-dialog w-theme-brand-media-dialog';
        dialog.dataset.wComponent = 'dialog';
        dialog.dataset.state = 'closed';
        dialog.dataset.size = 'lg';
        dialog.dataset.wClosable = 'true';
        dialog.dataset.wBackdrop = 'dismissible';

        const header = document.createElement('header');
        header.className = 'w-dialog__header';
        const title = document.createElement('h2');
        title.className = 'w-dialog__title';
        title.id = `w-theme-brand-media-title-${Date.now()}`;
        title.textContent = options.title || '选择媒体';
        dialog.setAttribute('aria-labelledby', title.id);
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        if (options.closeId) closeBtn.id = options.closeId;
        closeBtn.className = 'w-button';
        closeBtn.dataset.tone = 'quiet';
        closeBtn.dataset.size = 'sm';
        closeBtn.textContent = '关闭';
        header.append(title, closeBtn);

        const body = document.createElement('div');
        body.className = 'w-dialog__body w-param-media-dialog__body';
        const iframe = document.createElement('iframe');
        iframe.className = 'w-param-media-dialog__frame';
        iframe.src = options.url;
        iframe.title = options.title || '媒体管理器';
        body.appendChild(iframe);
        dialog.append(header, body);

        let closed = false;
        const removeMessageHandler = bindMediaManagerMessages(
            options.targetId,
            iframe,
            (files) => {
                if (typeof options.onSelect === 'function') options.onSelect(files || []);
                currentUi.dialog.close(dialog, 'select');
            },
            () => currentUi.dialog.close(dialog, 'cancel'),
        );
        const finish = (event) => {
            if (closed) return;
            closed = true;
            removeMessageHandler();
            if (activeMediaDialog === dialog) activeMediaDialog = null;
            if (typeof options.onClose === 'function') {
                options.onClose(event?.detail?.returnValue || 'close');
            }
            currentUi.unmount(dialog);
            dialog.remove();
        };
        dialog.addEventListener('weline:ui:dialog:close', finish, { once: true });
        closeBtn.addEventListener('click', () => currentUi.dialog.close(dialog, 'button'), { once: true });
        document.body.appendChild(dialog);
        activeMediaDialog = dialog;
        currentUi.mount(dialog);
        if (!currentUi.dialog.open(dialog)) {
            finish();
            return false;
        }
        return true;
    }

    function pickMedia(key) {
        const themeEl = document.getElementById('themeEditor');
        const base = connectorBaseUrl();
        const input = document.querySelector(`[data-w-brand-input="${key}"]`);
        const spec = BRAND_PICKER_SPECS[key] || {
            title: '选择媒体',
            ext: 'jpg,jpeg,png,gif,webp,svg,ico',
        };
        if (!input) return;
        if (!base) {
            const manual = window.prompt('请输入媒体相对路径（pub/media 下）', input.value || '');
            if (manual !== null) {
                setField(key, String(manual).trim(), true);
            }
            return;
        }

        const targetId = input.id || `themeBrandInput_${key}`;
        if (!input.id) input.id = targetId;
        const closeId = `w-theme-brand-media-close-${key}-${Date.now()}`;
        const lockRoot = resolveMediaScope(themeEl);
        const startPath = `${lockRoot}/brand`;
        const params = new URLSearchParams({
            target: targetId,
            close: closeId,
            multi: '0',
            ext: spec.ext,
            path: startPath,
            lockPath: '1',
            lockRoot,
        });
        if (spec.aspectRatio) {
            params.set('aspect_ratio', String(spec.aspectRatio));
        }
        if (spec.recommendWidth > 0) {
            params.set('recommend_width', String(spec.recommendWidth));
        }
        if (spec.recommendHeight > 0) {
            params.set('recommend_height', String(spec.recommendHeight));
        }
        // Brand stores plain media paths for SiteBrand / maintenance; skip ImageUsage JSON.
        const url = `${base}${base.includes('?') ? '&' : '?'}${params.toString()}`;

        const opened = openBrandMediaDialog({
            targetId,
            closeId,
            url,
            title: spec.title,
            onSelect: (files) => {
                const file = Array.isArray(files) && files[0] ? files[0] : null;
                const stored = brandStoredPath(file);
                if (!stored) {
                    toast('未拿到可用的媒体路径', 'warning');
                    return;
                }
                setField(key, stored, true, brandPreviewFromFile(file, stored));
                toast('已选择，记得保存到当前 Scope', 'success');
            },
        });
        if (!opened) {
            toast('媒体选择器打开失败', 'error');
        }
    }

    function bind() {
        const root = document.getElementById('themeEditor');
        if (!root || root.dataset.brandBasicsBound === '1') return;
        root.dataset.brandBasicsBound = '1';

        document.getElementById('btnThemeBrandBasics')?.addEventListener('click', openDrawer);
        document.getElementById('btnThemeBrandBasicsStrip')?.addEventListener('click', openDrawer);
        document.getElementById('btnThemeBrandBasicsClose')?.addEventListener('click', closeDrawer);
        document.querySelector('[data-w-brand-action="save"]')?.addEventListener('click', () => {
            saveBrand().catch((err) => toast(err?.message || String(err), 'error'));
        });
        document.querySelector('[data-w-brand-action="inherit"]')?.addEventListener('click', () => {
            inheritBrand().catch((err) => toast(err?.message || String(err), 'error'));
        });
        document.querySelectorAll('[data-w-brand-pick]').forEach((btn) => {
            btn.addEventListener('click', () => pickMedia(String(btn.getAttribute('data-w-brand-pick') || '')));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
