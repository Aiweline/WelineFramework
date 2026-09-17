const root = document.documentElement;
const parentOrigin = window.location.origin;
const mountedSlots = new WeakSet();

function integer(value, fallback) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function list(value) {
    return String(value || '')
        .split(',')
        .map((item) => item.trim().toLowerCase())
        .filter(Boolean);
}

function directWidgets(slot) {
    return [...slot.querySelectorAll('[data-layout-id], [data-widget-code]')]
        .filter((widget) => widget.closest('[data-wslot]') === slot);
}

function slotPayload(slot) {
    return {
        id: String(slot.dataset.wslot || ''),
        name: String(slot.dataset.wslotName || slot.dataset.wslot || ''),
        accept: list(slot.dataset.wslotAccept),
        reject: list(slot.dataset.wslotReject),
        multiple: slot.dataset.wslotMultiple !== 'false',
        exclusive: slot.dataset.wslotExclusive === 'true',
        min: integer(slot.dataset.wslotMin, 0),
        max: integer(slot.dataset.wslotMax, -1),
        current_count: directWidgets(slot).length,
        position: String(slot.dataset.wslotPosition || ''),
    };
}

function notify(type, detail = {}) {
    if (window.parent === window) return;
    window.parent.postMessage({ source: 'weline-theme-preview', type, ...detail }, parentOrigin);
}

function selectSlot(slot) {
    document.querySelectorAll('[data-wslot][data-state="selected"]').forEach((candidate) => {
        if (candidate !== slot) candidate.removeAttribute('data-state');
    });
    slot.dataset.state = 'selected';
    notify('slot-selected', { slot: slotPayload(slot) });
}

function mountSlot(slot) {
    if (!(slot instanceof HTMLElement) || mountedSlots.has(slot)) return;
    mountedSlots.add(slot);
    const name = String(slot.dataset.wslotName || slot.dataset.wslot || 'Slot');
    if (!slot.hasAttribute('aria-label')) slot.setAttribute('aria-label', name);

    // 完整编辑引擎已经提供 slot 选择、信息面板和拖放能力；此处只做无引擎时的最小适配。
    if (root.dataset.wEditorPreviewEngine === 'full') return;

    const action = document.createElement('button');
    action.type = 'button';
    action.className = 'w-theme-preview-slot-action';
    action.dataset.wPreviewSlotAction = 'select';
    action.textContent = `选择 · ${name}`;
    action.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        selectSlot(slot);
    });
    slot.append(action);
}

function mount(scope = document) {
    if (scope instanceof Element && scope.matches('[data-wslot]')) mountSlot(scope);
    scope.querySelectorAll?.('[data-wslot]').forEach(mountSlot);
}

function clearPreviewClientState() {
    document.cookie = 'weline_preview_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    try {
        localStorage.removeItem('weline_preview_float_pos');
    } catch (error) {
        // Ignore storage failures.
    }
}

function stripPreviewTokenFromUrl() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('weline_preview_token')) {
            return '';
        }
        url.searchParams.delete('weline_preview_token');
        return url.toString();
    } catch (error) {
        return '';
    }
}

function buildExitRedirectTarget() {
    const cleanUrl = stripPreviewTokenFromUrl();
    if (cleanUrl) {
        try {
            const parsed = new URL(cleanUrl, window.location.origin);
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (error) {
            return cleanUrl;
        }
    }
    try {
        const current = new URL(window.location.href);
        current.searchParams.delete('weline_preview_token');
        return current.pathname + current.search + current.hash;
    } catch (error) {
        return window.location.pathname + window.location.search + window.location.hash;
    }
}

function buildExitNavigateUrl(exitUrl, previewToken) {
    const target = buildExitRedirectTarget();
    try {
        const gateway = new URL(exitUrl, window.location.origin);
        gateway.searchParams.set('exit', '1');
        gateway.searchParams.set('redirect', target);
        if (previewToken) {
            gateway.searchParams.set('token', previewToken);
        }
        return gateway.toString();
    } catch (error) {
        const joinChar = exitUrl.includes('?') ? '&' : '?';
        return `${exitUrl}${joinChar}exit=1&redirect=${encodeURIComponent(target)}`
            + (previewToken ? `&token=${encodeURIComponent(previewToken)}` : '');
    }
}

function navigatePreviewExit(exitUrl, previewToken = '') {
    clearPreviewClientState();
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                source: 'weline-theme-preview',
                type: 'preview-exit',
            }, window.location.origin);
        }
    } catch (error) {
        // Ignore cross-origin parent access failures.
    }
    window.location.replace(buildExitNavigateUrl(exitUrl, previewToken));
}

function bindPreviewExitButtons() {
    document.querySelectorAll('[data-w-preview-exit]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement) || button.dataset.wPreviewExitBound === '1') {
            return;
        }
        button.dataset.wPreviewExitBound = '1';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (button.disabled) {
                return;
            }
            button.disabled = true;
            const exitUrl = String(button.dataset.wPreviewExitUrl || '').trim();
            const tokenMatch = document.cookie.match(/(?:^|;\s*)weline_preview_token(?:_w\d+)?=([^;]+)/);
            const urlToken = new URLSearchParams(window.location.search).get('weline_preview_token') || '';
            const previewToken = decodeURIComponent(String(urlToken || (tokenMatch ? tokenMatch[1] : '') || '')).trim();
            navigatePreviewExit(exitUrl, previewToken);
        });
    });
}

function initialize() {
    root.dataset.wEditorPreview = 'true';
    mount(document);
    bindPreviewExitButtons();

    // Dense storefront hydrate would otherwise trip DEV delivery_storm; coalesce like ModuleLoader.
    let mountScheduled = false;
    const pendingMountRoots = new Set();
    const mountObserveOptions = { childList: true, subtree: true };
    const observer = new MutationObserver((records) => {
        try {
            observer.disconnect();
        } catch (_) {}
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node instanceof Element) {
                pendingMountRoots.add(node);
            }
        }));
        if (mountScheduled) {
            return;
        }
        mountScheduled = true;
        const flush = () => {
            mountScheduled = false;
            const batch = Array.from(pendingMountRoots);
            pendingMountRoots.clear();
            batch.forEach((node) => {
                if (node.isConnected) {
                    mount(node);
                }
            });
            try {
                if (document.body) {
                    observer.observe(document.body, mountObserveOptions);
                }
            } catch (_) {}
        };
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(() => requestAnimationFrame(flush));
        } else {
            setTimeout(flush, 0);
        }
    });
    observer.observe(document.body, mountObserveOptions);

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('[data-editor-interactive], [data-w-preview-slot-action]')) return;
        const navigation = target.closest('a[href], button[type="submit"], input[type="submit"]');
        if (!navigation) return;
        event.preventDefault();
        notify('navigation-blocked');
    }, true);

    document.addEventListener('submit', (event) => {
        if (!(event.target instanceof HTMLFormElement) || event.target.closest('[data-editor-interactive]')) return;
        event.preventDefault();
        notify('navigation-blocked');
    }, true);

    notify('ready', { slots: document.querySelectorAll('[data-wslot]').length });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
} else {
    initialize();
}
