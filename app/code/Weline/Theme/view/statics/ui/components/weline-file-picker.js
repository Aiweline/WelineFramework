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

function clearPreviewImage(previewImage) {
    if (!(previewImage instanceof HTMLImageElement)) return;
    previewImage.onerror = null;
    previewImage.removeAttribute('src');
    previewImage.alt = '';
}

function openPreview(root, sourceElement, componentUI) {
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

function registerFilePicker(UI) {
    UI.define('file-picker', ({ element, listen, emit, UI: componentUI }) => {
        const targetId = String(element.dataset.wTargetId || '').trim();
        const target = document.getElementById(targetId);
        const preview = element.querySelector('[data-w-file-preview]');
        const pickerDialog = element.querySelector('[data-w-file-picker-dialog]');
        const previewDialog = element.querySelector('[data-w-file-preview-dialog]');
        const frame = element.querySelector('[data-w-file-picker-frame]');
        const multiple = element.dataset.wMultiple === 'true';
        let draggedItem = null;

        const items = () => preview ? [...preview.querySelectorAll('[data-w-file-item]')] : [];
        const dispatchTargetEvents = () => {
            if (!(target instanceof HTMLElement)) return;
            target.dispatchEvent(new Event('change', { bubbles: true }));
            target.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const syncTarget = () => {
            if (!(target instanceof HTMLElement)) return;
            const value = items().map((item) => item.dataset.path || '').filter(Boolean).join(',');
            if (element.dataset.wSetAttr === 'text') target.textContent = value;
            else if ('value' in target) target.value = value;
            else target.setAttribute('value', value);
            dispatchTargetEvents();
            emit('change', { value, paths: value === '' ? [] : value.split(',') }, false);
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
        const open = () => {
            if (!(pickerDialog instanceof HTMLElement) || !(frame instanceof HTMLIFrameElement)) {
                componentUI.toast.error(element.dataset.wInvalidMessage || 'File picker is unavailable.');
                return false;
            }
            const connector = sameOriginConnector();
            if (connector === '') {
                componentUI.toast.error(element.dataset.wInvalidMessage || 'File picker is unavailable.');
                return false;
            }
            const url = new URL(connector);
            const currentValue = target && 'value' in target ? String(target.value || '') : '';
            url.searchParams.set('initialValue', currentValue);
            if (frame.src !== url.href) frame.src = url.href;
            return componentUI.dialog.open(pickerDialog, { trigger: 'file-picker' });
        };
        const close = (reason = '') => pickerDialog instanceof HTMLElement
            ? componentUI.dialog.close(pickerDialog, reason)
            : false;
        const createItem = (file) => {
            const rawPath = String(file?.path || file?.url || file?.name || '');
            const path = rawPath.replace(/^\/pub\/media\//, '').replace(/^pub\/media\//, '');
            if (path === '') return null;
            const item = document.createElement('div');
            item.className = 'w-file-preview__item';
            item.dataset.wFileItem = '';
            item.dataset.path = path;
            item.draggable = true;

            const thumbnail = document.createElement('button');
            thumbnail.type = 'button';
            thumbnail.className = 'w-file-preview__thumbnail';
            thumbnail.dataset.wFileOpen = '';
            thumbnail.setAttribute('aria-label', String(file?.name || path));
            const image = document.createElement('img');
            image.dataset.src = path;
            image.src = normalizePreviewSource(file?.thumb || file?.url || file?.path || '') || normalizePreviewSource(path);
            image.alt = String(file?.name || '');
            image.draggable = false;
            thumbnail.append(image);

            const actions = document.createElement('span');
            actions.className = 'w-file-preview__actions';
            for (const [action, icon, label] of [
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
                button.setAttribute('aria-label', label);
                button.append(componentUI.icon.create(icon, { size: 'sm' }));
                actions.append(button);
            }
            item.append(thumbnail, actions);
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
            const append = multiple && data.multi === true;
            if (!append) preview.replaceChildren();
            data.files.forEach((file) => {
                const item = createItem(file);
                if (item) preview.append(item);
            });
            syncTarget();
            close('select');
        };

        listen(element, 'click', (event) => {
            const trigger = event.target instanceof Element ? event.target.closest('button') : null;
            if (!(trigger instanceof HTMLButtonElement) || !element.contains(trigger)) return;
            if (trigger.matches('[data-w-file-picker-open]')) open();
            const item = trigger.closest('[data-w-file-item]');
            if (!(item instanceof HTMLElement)) return;
            if (trigger.matches('[data-w-file-open]')) openPreview(element, trigger, componentUI);
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

        return { open, close, sync: syncTarget, element, previewDialog };
    });
}

export function register(UI) {
    registerFilePreview(UI);
    registerFilePicker(UI);
}
