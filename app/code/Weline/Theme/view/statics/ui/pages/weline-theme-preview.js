/* Weline UI source: js/pages/theme-preview.js */
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

function initialize() {
    root.dataset.wEditorPreview = 'true';
    mount(document);

    const observer = new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node instanceof Element) mount(node);
        }));
    });
    observer.observe(document.body, { childList: true, subtree: true });

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
