/* Weline UI source: js/file-picker.js */
/* Weline UI source: js/file-picker.js */
function normalizePreviewSource(rawSource, fallbackSource = '') {
    let source = String(rawSource || fallbackSource || '').trim();
    if (source === '') return '';
    const compact = source.replace(/[\u0000-\u001f\u007f\s]+/g, '').toLowerCase();
    if (compact.startsWith('javascript:') || compact.startsWith('vbscript:')) return '';
    if (compact.startsWith('data:')) return compact.startsWith('data:image/') ? source : '';
    if (compact.startsWith('blob:')) return source;
    source = source.replace(/\\/g, '/');
    if (/^(?:https?:|\/)/i.test(source)) {
        try {
            const url = new URL(source, window.location.href);
            if (url.origin !== window.location.origin) return '';
            if (/^(?:https?:)$/.test(url.protocol)) return url.href;
        } catch (_error) {
            return '';
        }
    }
    const relative = source
        .replace(/^\/pub\/media\//, '')
        .replace(/^pub\/media\//, '')
        .replace(/^\/media\/image\//, '')
        .replace(/^media\/image\//, '')
        .replace(/^\/+/, '');
    return relative === '' ? '' : `/pub/media/${relative}`;
}

const IMAGE_PREVIEW_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'avif', 'heic', 'heif', 'svg',
];
const AUDIO_PREVIEW_EXTENSIONS = [
    'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'wma', 'weba',
];

function extensionOfPath(path) {
    const clean = String(path || '').split('?')[0].split('#')[0];
    const base = clean.split('/').pop() || '';
    const dot = base.lastIndexOf('.');
    return dot >= 0 ? base.slice(dot + 1).toLowerCase() : '';
}

function previewKindFromPath(path) {
    const ext = extensionOfPath(path);
    if (AUDIO_PREVIEW_EXTENSIONS.indexOf(ext) >= 0) return 'audio';
    if (IMAGE_PREVIEW_EXTENSIONS.indexOf(ext) >= 0) return 'image';
    return 'file';
}

function displayNameFromPath(path, preferred) {
    const raw = String(preferred || '').trim();
    const clean = String(path || '').split('?')[0].split('#')[0];
    let base = '';
    try {
        base = decodeURIComponent(clean.split('/').pop() || clean) || clean;
    } catch (_error) {
        base = clean.split('/').pop() || clean || '';
    }
    if (raw !== '') {
        // Prefer a clean label without extension when caller passed a path basename.
        const stripped = raw.replace(/\.[a-z0-9]{2,5}$/i, '');
        return stripped || raw;
    }
    return base.replace(/\.[a-z0-9]{2,5}$/i, '') || base;
}

function clearPreviewImage(previewImage) {
    if (!(previewImage instanceof HTMLImageElement)) return;
    previewImage.onerror = null;
    previewImage.removeAttribute('src');
    previewImage.alt = '';
}

function openPreview(root, sourceElement, componentUI) {
    const item = sourceElement?.closest?.('[data-w-file-item]');
    const kind = item?.dataset?.kind || previewKindFromPath(sourceElement?.dataset?.path || item?.dataset?.path || '');
    if (kind !== 'image') {
        componentUI.toast.warning(root.dataset.wEmptyMessage || 'No preview is available.');
        return false;
    }
    const dialog = root.querySelector('[data-w-file-preview-dialog]');
    const previewImage = dialog?.querySelector('[data-w-file-preview-image]');
    const image = sourceElement?.querySelector('img') || (sourceElement instanceof HTMLImageElement ? sourceElement : null);
    const source = normalizePreviewSource(
        sourceElement?.dataset.path || image?.dataset.src || '',
        image?.currentSrc || image?.src || '',
    );
    if (!(dialog instanceof HTMLElement) || !(previewImage instanceof HTMLImageElement) || source === '') {
        componentUI.toast.warning(root.dataset.wEmptyMessage || 'No preview is available.');
        return false;
    }
    clearPreviewImage(previewImage);
    previewImage.alt = image?.alt || '';
    previewImage.src = source;
    previewImage.onerror = () => {
        previewImage.onerror = null;
        componentUI.dialog.close(dialog, 'load-error');
        componentUI.toast.warning(root.dataset.wEmptyMessage || 'No preview is available.');
    };
    return componentUI.dialog.open(dialog, { sourceElement });
}

function registerFilePreview(UI) {
    UI.define('file-preview', ({ element, listen, UI: componentUI }) => {
        const dialog = element.querySelector('[data-w-file-preview-dialog]');
        const previewImage = dialog?.querySelector('[data-w-file-preview-image]');
        listen(element, 'click', (event) => {
            const trigger = event.target instanceof Element ? event.target.closest('[data-w-file-open]') : null;
            if (!(trigger instanceof HTMLElement) || !element.contains(trigger)) return;
            openPreview(element, trigger, componentUI);
        });
        if (dialog instanceof HTMLElement) {
            listen(dialog, 'weline:ui:dialog:close', () => clearPreviewImage(previewImage));
        }
        return { open: (trigger) => openPreview(element, trigger, componentUI), element };
    });
}

function resolveMediaIdentity(element) {
    const explicitPath = String(element.dataset.wIdentity || '').trim();
    if (explicitPath !== '') {
        return {
            path: explicitPath,
            root: String(element.dataset.wIdentityRoot || '').trim(),
            code: String(element.dataset.wIdentityCode || '').trim(),
            scope: String(element.dataset.wIdentityScope || '').trim(),
            slot: {
                kind: String(element.dataset.wIdentityKind || '').trim(),
                field: String(element.dataset.wIdentityField || '').trim(),
                component: String(element.dataset.wIdentityComponent || '').trim(),
                locale: String(element.dataset.wIdentityLocale || '').trim(),
                instance: String(element.dataset.wIdentityInstance || '').trim(),
            },
            explicit: true,
        };
    }
    const root = String(element.dataset.wIdentityRoot || '').trim();
    const code = String(element.dataset.wIdentityCode || '').trim();
    if (!root || !code) return null;
    const scope = String(element.dataset.wIdentityScope || '').trim() || null;
    const slot = {};
    [
        ['kind', 'wIdentityKind'],
        ['field', 'wIdentityField'],
        ['component', 'wIdentityComponent'],
        ['locale', 'wIdentityLocale'],
        ['instance', 'wIdentityInstance'],
    ].forEach(([key, ds]) => {
        const v = String(element.dataset[ds] || '').trim();
        if (v) slot[key] = v;
    });
    const wScope = typeof window.w_scope === 'function'
        ? window.w_scope
        : (window.Weline && typeof window.Weline.w_scope === 'function' ? window.Weline.w_scope : null);
    if (!wScope) {
        return { incomplete: true, reason: 'w_scope_missing', root, code, scope, slot };
    }
    try {
        const identity = wScope(scope, root, code, slot);
        return {
            path: identity.path,
            root: identity.root,
            code: identity.code,
            scope: identity.scope,
            slot,
            tags: identity.tags || {},
            explicit: false,
        };
    } catch (error) {
        return { incomplete: true, reason: (error && error.message) || 'w_scope_failed', root, code, scope, slot };
    }
}

function registerFilePicker(UI) {
    UI.define('file-picker', ({ element, listen, emit, UI: componentUI }) => {
        const targetId = String(element.dataset.wTargetId || '').trim();
        const target = document.getElementById(targetId);
        const preview = element.querySelector('[data-w-file-preview]');
        const pickerDialog = element.querySelector('[data-w-file-picker-dialog]');
        const previewDialog = element.querySelector('[data-w-file-preview-dialog]');
        const frame = element.querySelector('[data-w-file-picker-frame]');
        const multiple = element.dataset.wMultiple === 'true';
        const strongRef = element.dataset.wStrongRef === '1' || element.dataset.wStrongRef === 'true';
        const refMode = String(element.dataset.wRefMode || (multiple ? 'multi' : 'single')).toLowerCase();
        const valueMode = String(element.dataset.wValueMode || '').trim().toLowerCase();
        const bindUrl = String(element.dataset.wBindUrl || '').trim();
        let draggedItem = null;

        const items = () => preview ? [...preview.querySelectorAll('[data-w-file-item]')] : [];
        const dispatchTargetEvents = () => {
            if (!(target instanceof HTMLElement)) return;
            target.dispatchEvent(new Event('change', { bubbles: true }));
            target.dispatchEvent(new Event('input', { bubbles: true }));
            try {
                target.dispatchEvent(new CustomEvent('weline:param:valuechange', {
                    bubbles: true,
                    composed: true,
                    detail: { source: 'file-picker', valueMode },
                }));
            } catch (_e) {}
        };
        const parseFileImageNode = (raw) => {
            if (!raw) return null;
            let node = raw;
            if (typeof node === 'string') {
                const trimmed = node.trim();
                if (!trimmed || trimmed.charAt(0) !== '{') return null;
                try { node = JSON.parse(trimmed); } catch (_err) { return null; }
            }
            if (!node || typeof node !== 'object' || Array.isArray(node) || node.type !== 'file-image') return null;
            const usage = node.usage;
            if (!usage || typeof usage !== 'object' || !usage.asset_id || !usage.locale_code || Number(usage.version) !== 1) {
                return null;
            }
            return { type: 'file-image', usage };
        };
        const syncTarget = () => {
            if (!(target instanceof HTMLElement)) return;
            let value = '';
            if (valueMode === 'file-image') {
                const nodes = items().map((item) => parseFileImageNode(item.dataset.fileImageNode || '')).filter(Boolean);
                if (nodes.length) {
                    value = multiple ? JSON.stringify(nodes) : JSON.stringify(nodes[0]);
                }
            } else {
                value = items().map((item) => item.dataset.path || '').filter(Boolean).join(',');
            }
            if (element.dataset.wSetAttr === 'text') target.textContent = value;
            else if ('value' in target) target.value = value;
            else target.setAttribute('value', value);
            if (valueMode === 'file-image' && target instanceof HTMLElement) {
                const first = items()[0];
                const thumb = first ? first.querySelector('.w-file-preview__thumbnail img') : null;
                const previewUrl = thumb ? String(thumb.currentSrc || thumb.getAttribute('src') || '').trim() : '';
                if (previewUrl) {
                    target.dataset.previewUrl = previewUrl;
                    target.setAttribute('data-preview-url', previewUrl);
                } else {
                    delete target.dataset.previewUrl;
                    target.removeAttribute('data-preview-url');
                }
            }
            dispatchTargetEvents();
            emit('change', { value, paths: valueMode === 'file-image' ? [] : (value === '' ? [] : value.split(',')) }, false);
        };
        const sameOriginConnector = () => {
            if (!(frame instanceof HTMLIFrameElement)) return '';
            try {
                const url = new URL(frame.dataset.src || '', window.location.href);
                if (url.origin !== window.location.origin) return '';
                ['public_id', 'preview_width', 'preview_page_type'].forEach((key) => url.searchParams.delete(key));
                return url.href;
            } catch (_error) {
                return '';
            }
        };
        const bindSelection = (files) => {
            if (!bindUrl || !files || !files.length) return Promise.resolve();
            const identity = resolveMediaIdentity(element);
            if (!identity || identity.incomplete || !identity.path) return Promise.resolve();
            const assetIds = files.map((f) => String(f.asset_id || '')).filter(Boolean);
            if (!assetIds.length) return Promise.resolve();
            const body = {
                ref_mode: refMode,
                type: identity.root,
                code: identity.code,
                scope: identity.scope || null,
                slot: identity.slot || {},
                owner_type: String(element.dataset.wOwnerType || identity.root || ''),
                owner_id: String(element.dataset.wOwnerId || identity.code || ''),
                owner_version: Number(element.dataset.wOwnerVersion || 1) || 1,
                field_path: identity.path,
                asset_id: assetIds[0],
                asset_ids: assetIds,
            };
            return fetch(bindUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            }).catch(() => null);
        };
        const open = () => {
            if (!(pickerDialog instanceof HTMLElement) || !(frame instanceof HTMLIFrameElement)) {
                componentUI.toast.error(element.dataset.wInvalidMessage || 'File picker is unavailable.');
                return false;
            }
            const identity = resolveMediaIdentity(element);
            if (strongRef && (!identity || identity.incomplete || (!identity.path && !identity.root))) {
                componentUI.toast.error(element.dataset.wIdentityMissing || 'Media identity required.');
                return false;
            }
            if (strongRef && identity && identity.incomplete && identity.reason === 'w_scope_missing') {
                componentUI.toast.error(element.dataset.wIdentityMissing || 'window.w_scope is required.');
                return false;
            }
            const connector = sameOriginConnector();
            if (connector === '') {
                componentUI.toast.error(element.dataset.wInvalidMessage || 'File picker is unavailable.');
                return false;
            }
            const url = new URL(connector);
            let currentValue = target && 'value' in target ? String(target.value || '') : '';
            if (valueMode === 'file-image') {
                const node = parseFileImageNode(currentValue);
                if (node && node.usage && node.usage.asset_id) {
                    url.searchParams.set('asset_id', String(node.usage.asset_id));
                    if (node.usage.locale_code) {
                        url.searchParams.set('locale_code', String(node.usage.locale_code));
                    }
                }
                currentValue = '';
                if (!url.searchParams.get('usage')) {
                    url.searchParams.set('usage', '1');
                }
            }
            // Theme editor: rewrite media path lock to websites/{w}/{s}/… at open time.
            try {
                const themeEl = document.getElementById('themeEditor');
                if (themeEl) {
                    const scopeRaw = themeEl.getAttribute('data-scope-identity') || '{}';
                    let website = 'default';
                    let store = 'default';
                    try {
                        const scope = JSON.parse(scopeRaw);
                        if (scope && typeof scope === 'object') {
                            website = String(scope.website_code || scope.website || website);
                            store = String(scope.store_code || scope.store || store);
                        }
                    } catch (_scopeErr) {}
                    const safeSeg = (value, fallback) => {
                        const raw = String(value == null ? '' : value).trim();
                        if (!raw || raw.indexOf('..') !== -1 || raw.indexOf('/') !== -1) return fallback;
                        if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/.test(raw)) return fallback;
                        return raw;
                    };
                    website = safeSeg(website, 'default');
                    store = safeSeg(store, 'default');
                    const lockRoot = ['websites', website, store].join('/');
                    const rawDir = String(
                        url.searchParams.get('path')
                        || url.searchParams.get('startPath')
                        || 'banner',
                    ).replace(/^\/+|\/+$/g, '') || 'banner';
                    let startPath;
                    if (rawDir === lockRoot || rawDir.indexOf(lockRoot + '/') === 0) {
                        startPath = rawDir;
                    } else if (rawDir.indexOf('websites/') === 0) {
                        // Already scoped; keep leaf under its websites/{w}/{s} prefix.
                        const parts = rawDir.split('/').filter(Boolean);
                        const scopedRoot = parts.slice(0, 3).join('/');
                        startPath = rawDir;
                        url.searchParams.set('lockRoot', scopedRoot || lockRoot);
                    } else {
                        // Connector still carries relative startPath=banner (from getParams).
                        // Prefer the leaf segment so we never nest websites/.../websites/...
                        const leaf = rawDir.includes('/')
                            ? rawDir.split('/').filter(Boolean).pop()
                            : rawDir;
                        startPath = lockRoot + '/' + (leaf || 'banner');
                    }
                    if (!url.searchParams.get('lockRoot')) {
                        url.searchParams.set('lockRoot', lockRoot);
                    }
                    // Manager prefers startPath over path — keep both in sync or the
                    // leftover relative startPath opens outside lockRoot and 500s
                    // 「不能访问指定路径以外的目录」.
                    url.searchParams.set('path', startPath);
                    url.searchParams.set('startPath', startPath);
                    url.searchParams.set('lockPath', '1');
                    const locale = themeEl.getAttribute('data-config-locale')
                        || themeEl.getAttribute('data-default-locale')
                        || document.documentElement.lang
                        || '';
                    if (locale && locale !== 'default' && !url.searchParams.get('locale_code')) {
                        url.searchParams.set('locale_code', String(locale).replace(/-/g, '_'));
                    }
                }
            } catch (_themeErr) {}
            url.searchParams.set('initialValue', currentValue);
            url.searchParams.set('ref_mode', refMode);
            if (identity && identity.path) url.searchParams.set('identity', identity.path);
            if (identity && identity.root) url.searchParams.set('identity_root', identity.root);
            if (identity && identity.code) url.searchParams.set('identity_code', identity.code);
            if (identity && identity.scope) url.searchParams.set('identity_scope', identity.scope);
            const slot = (identity && identity.slot) || {};
            Object.keys(slot).forEach((key) => {
                if (slot[key]) url.searchParams.set('identity_' + key, String(slot[key]));
            });
            // Force aspect constraints from picker dataset so MediaManager CONFIG
            // receives tolerance even when the connector query omitted it.
            const aspectRatio = String(element.dataset.wAspectRatio || element.getAttribute('data-aspect-ratio') || '').trim();
            const aspectTolerance = String(element.dataset.wAspectRatioTolerance || '').trim();
            if (aspectRatio && !url.searchParams.get('aspect_ratio')) {
                url.searchParams.set('aspect_ratio', aspectRatio);
            }
            if (aspectTolerance) {
                url.searchParams.set('aspect_ratio_tolerance', aspectTolerance);
            }
            if (frame.src !== url.href) frame.src = url.href;
            return componentUI.dialog.open(pickerDialog, { trigger: 'file-picker' });
        };
        const close = (reason = '') => pickerDialog instanceof HTMLElement
            ? componentUI.dialog.close(pickerDialog, reason)
            : false;
        const createItem = (file) => {
            const typedNode = parseFileImageNode(file?.file_image_node || file?.file_image_json || '');
            const rawPath = String(
                file?.path
                || file?.url
                || file?.editor_preview_url
                || file?.preview_url
                || file?.thumb
                || file?.name
                || (typedNode ? typedNode.usage.asset_id : '')
                || '',
            );
            const path = rawPath.replace(/^\/pub\/media\//, '').replace(/^pub\/media\//, '');
            if (path === '' && !typedNode) return null;
            const kind = previewKindFromPath(path || String(file?.original_name || file?.name || 'image.png'));
            const label = displayNameFromPath(path, file?.name || file?.display_name || '');
            const item = document.createElement('div');
            item.className = 'w-file-preview__item' + (kind !== 'image' ? ` w-file-preview__item--${kind}` : '');
            item.dataset.wFileItem = '';
            item.dataset.path = path || String(typedNode?.usage?.asset_id || '');
            item.dataset.kind = kind;
            if (file?.asset_id) item.dataset.assetId = String(file.asset_id);
            if (typedNode) {
                item.dataset.fileImageNode = JSON.stringify(typedNode);
            }
            item.draggable = true;

            const media = document.createElement('div');
            media.className = 'w-file-preview__media';

            const thumbnail = document.createElement('button');
            thumbnail.type = 'button';
            thumbnail.className = 'w-file-preview__thumbnail';
            thumbnail.dataset.wFileOpen = '';
            thumbnail.setAttribute('aria-label', label || path);
            if (kind === 'image') {
                const image = document.createElement('img');
                image.dataset.src = path;
                image.src = normalizePreviewSource(
                    file?.editor_preview_url || file?.thumb || file?.url || file?.path || '',
                ) || normalizePreviewSource(path);
                image.alt = label;
                image.draggable = false;
                image.onerror = () => {
                    image.onerror = null;
                    image.remove();
                    const glyph = document.createElement('span');
                    glyph.className = 'w-file-preview__glyph';
                    glyph.dataset.kind = 'file';
                    glyph.setAttribute('aria-hidden', 'true');
                    glyph.textContent = '📄';
                    thumbnail.append(glyph);
                    item.dataset.kind = 'file';
                    item.classList.add('w-file-preview__item--file');
                };
                thumbnail.append(image);
            } else {
                const glyph = document.createElement('span');
                glyph.className = 'w-file-preview__glyph';
                glyph.dataset.kind = kind;
                glyph.setAttribute('aria-hidden', 'true');
                glyph.textContent = kind === 'audio' ? '♪' : '📄';
                thumbnail.append(glyph);
            }

            media.append(thumbnail);

            const name = document.createElement('span');
            name.className = 'w-file-preview__name';
            name.title = label;
            name.textContent = label;

            const actions = document.createElement('span');
            actions.className = 'w-file-preview__actions';
            for (const [action, icon, labelText] of [
                ['previous', 'arrow-left', 'Move earlier'],
                ['next', 'arrow-right', 'Move later'],
                ['remove', 'close', 'Remove'],
            ]) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'w-button';
                button.dataset.size = 'sm';
                button.dataset.tone = action === 'remove' ? 'danger' : 'quiet';
                if (action === 'remove') button.dataset.wFileRemove = '';
                else button.dataset.wFileMove = action;
                button.setAttribute('aria-label', labelText);
                button.append(componentUI.icon.create(icon, { size: 'sm' }));
                actions.append(button);
            }

            if (kind === 'audio' || kind === 'file') {
                const meta = document.createElement('div');
                meta.className = 'w-file-preview__meta';
                const hint = document.createElement('span');
                hint.className = 'w-file-preview__hint';
                hint.textContent = kind === 'audio' ? '点击更换曲目' : '点击更换文件';
                meta.append(name, hint);
                item.append(media, meta, actions);
            } else {
                item.append(media, name, actions);
            }
            return item;
        };
        const receive = (event) => {
            if (!(frame instanceof HTMLIFrameElement) || event.source !== frame.contentWindow || event.origin !== window.location.origin) return;
            const data = event.data;
            if (!data || typeof data !== 'object') return;
            if (data.target && String(data.target) !== targetId) return;
            if (data.type === 'weline-media-manager-cancel') {
                close('cancel');
                return;
            }
            if (data.type !== 'weline-media-manager-select' || !Array.isArray(data.files) || data.files.length === 0 || !preview) return;
            let selectedFiles = data.files;
            if (valueMode === 'file-image') {
                selectedFiles = data.files.filter((file) => !!parseFileImageNode(file?.file_image_node || ''));
                if (!selectedFiles.length) {
                    componentUI.toast.error(element.dataset.wInvalidMessage || '请从媒体库选择带资源身份的图片。');
                    return;
                }
            }
            const append = multiple && data.multi === true;
            if (!append) preview.replaceChildren();
            selectedFiles.forEach((file) => {
                const item = createItem(file);
                if (item) preview.append(item);
            });
            syncTarget();
            bindSelection(selectedFiles);
            close('select');
        };

        listen(element, 'click', (event) => {
            const trigger = event.target instanceof Element ? event.target.closest('button') : null;
            if (!(trigger instanceof HTMLButtonElement) || !element.contains(trigger)) return;
            if (trigger.matches('[data-w-file-picker-open]')) open();
            const item = trigger.closest('[data-w-file-item]');
            if (!(item instanceof HTMLElement)) return;
            if (trigger.matches('[data-w-file-open]')) {
                const kind = item.dataset.kind || '';
                // 音频/文件：点缩略图 = 换选，不走图片预览弹窗
                if (kind === 'audio' || kind === 'file') open();
                else openPreview(element, trigger, componentUI);
            }
            if (trigger.matches('[data-w-file-remove]')) {
                item.remove();
                syncTarget();
            }
            const move = trigger.dataset.wFileMove;
            if (move === 'previous' && item.previousElementSibling) {
                item.previousElementSibling.before(item);
                syncTarget();
                trigger.focus();
            }
            if (move === 'next' && item.nextElementSibling) {
                item.nextElementSibling.after(item);
                syncTarget();
                trigger.focus();
            }
        });
        if (preview) {
            listen(preview, 'dragstart', (event) => {
                draggedItem = event.target instanceof Element ? event.target.closest('[data-w-file-item]') : null;
                if (!(draggedItem instanceof HTMLElement)) return;
                draggedItem.dataset.state = 'dragging';
                event.dataTransfer?.setData('text/plain', draggedItem.dataset.path || '');
                if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
            });
            listen(preview, 'dragover', (event) => {
                const candidate = event.target instanceof Element ? event.target.closest('[data-w-file-item]') : null;
                if (!(candidate instanceof HTMLElement) || candidate === draggedItem) return;
                event.preventDefault();
                items().forEach((item) => { if (item !== candidate) delete item.dataset.state; });
                candidate.dataset.state = 'drop-target';
            });
            listen(preview, 'drop', (event) => {
                const candidate = event.target instanceof Element ? event.target.closest('[data-w-file-item]') : null;
                if (!(candidate instanceof HTMLElement) || !(draggedItem instanceof HTMLElement) || candidate === draggedItem) return;
                event.preventDefault();
                const after = event.clientX > candidate.getBoundingClientRect().left + candidate.getBoundingClientRect().width / 2;
                after ? candidate.after(draggedItem) : candidate.before(draggedItem);
                syncTarget();
            });
            listen(preview, 'dragend', () => {
                items().forEach((item) => delete item.dataset.state);
                draggedItem = null;
            });
        }
        listen(window, 'message', receive);

        // Upgrade SSR / legacy image thumbnails for audio & non-image paths.
        items().forEach((item) => {
            const path = item.dataset.path || '';
            const kind = previewKindFromPath(path) || item.dataset.kind || 'file';
            item.dataset.kind = kind;
            item.classList.toggle('w-file-preview__item--audio', kind === 'audio');
            item.classList.toggle('w-file-preview__item--file', kind === 'file');
            let media = item.querySelector('.w-file-preview__media');
            const thumb = item.querySelector('.w-file-preview__thumbnail');
            let actions = item.querySelector('.w-file-preview__actions');
            if (!(media instanceof HTMLElement) && thumb instanceof HTMLElement) {
                media = document.createElement('div');
                media.className = 'w-file-preview__media';
                thumb.replaceWith(media);
                media.append(thumb);
            }
            if (media instanceof HTMLElement && actions instanceof HTMLElement && media.contains(actions)) {
                media.after(actions);
            }
            let nameEl = item.querySelector('.w-file-preview__name');
            const label = displayNameFromPath(path, nameEl?.textContent || thumb?.getAttribute('aria-label') || '');
            if (!(nameEl instanceof HTMLElement)) {
                nameEl = document.createElement('span');
                nameEl.className = 'w-file-preview__name';
                if (media instanceof HTMLElement) {
                    media.after(nameEl);
                } else {
                    item.append(nameEl);
                }
            }
            if (kind === 'audio' || kind === 'file') {
                let meta = item.querySelector('.w-file-preview__meta');
                if (!(meta instanceof HTMLElement)) {
                    meta = document.createElement('div');
                    meta.className = 'w-file-preview__meta';
                    nameEl.replaceWith(meta);
                    meta.append(nameEl);
                } else if (!meta.contains(nameEl)) {
                    meta.prepend(nameEl);
                }
                let hint = meta.querySelector('.w-file-preview__hint');
                if (!(hint instanceof HTMLElement)) {
                    hint = document.createElement('span');
                    hint.className = 'w-file-preview__hint';
                    hint.textContent = kind === 'audio' ? '点击更换曲目' : '点击更换文件';
                    meta.append(hint);
                }
                if (actions instanceof HTMLElement && meta.nextElementSibling !== actions) {
                    meta.after(actions);
                }
            } else if (actions instanceof HTMLElement && nameEl.nextElementSibling !== actions) {
                nameEl.after(actions);
            }
            if (!String(nameEl.textContent || '').trim()) {
                nameEl.textContent = label;
            }
            nameEl.title = label;
            if (!(thumb instanceof HTMLElement)) return;
            if (kind === 'image') {
                const image = item.querySelector('img');
                if (image instanceof HTMLImageElement) {
                    image.onerror = () => {
                        image.onerror = null;
                        image.remove();
                        const glyph = document.createElement('span');
                        glyph.className = 'w-file-preview__glyph';
                        glyph.dataset.kind = 'file';
                        glyph.setAttribute('aria-hidden', 'true');
                        glyph.textContent = '📄';
                        thumb.append(glyph);
                        item.dataset.kind = 'file';
                    };
                }
                return;
            }
            thumb.querySelector('img')?.remove();
            let glyph = thumb.querySelector('.w-file-preview__glyph');
            if (!(glyph instanceof HTMLElement)) {
                glyph = document.createElement('span');
                glyph.className = 'w-file-preview__glyph';
                glyph.setAttribute('aria-hidden', 'true');
                thumb.append(glyph);
            }
            glyph.dataset.kind = kind;
            glyph.textContent = kind === 'audio' ? '♪' : '📄';
        });

        return { open, close, sync: syncTarget, element, previewDialog };
    });
}

export function register(UI) {
    registerFilePreview(UI);
    registerFilePicker(UI);
}
