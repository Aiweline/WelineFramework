/* Weline UI source: js/weline-ui.js */
const globalObject = window;
const Weline = globalObject.Weline = globalObject.Weline || {};
const runtimeId = 'weline-ui-2';
const existingRuntime = Weline.UI?.__runtimeId === runtimeId ? Weline.UI : null;
const definitions = new Map();
const instances = new WeakMap();
const cleanupByElement = new WeakMap();
const overlayStack = [];
const lazyComponentSources = new Map([
    ['combobox', './weline-ui-advanced.js'],
    ['tree', './weline-ui-advanced.js'],
    ['transfer-list', './weline-ui-advanced.js'],
    ['reorder-list', './weline-ui-advanced.js'],
    ['icon-picker', './weline-ui-advanced.js'],
    ['dependent-field', './weline-ui-advanced.js'],
    ['language-select', './components/weline-language-select.js'],
    ['language-switcher', './components/weline-language-switcher.js?v=locale-nav-23'],
    ['online-translation-collector', './components/weline-online-translation-collector.js'],
    ['scope-persistence', './components/weline-scope-persistence.js'],
    ['file-preview', './components/weline-file-picker.js'],
    ['file-picker', './components/weline-file-picker.js'],
    ['local-translation', './components/weline-local-translation.js'],
    ['mega-menu', './components/weline-mega-menu.js'],
    ['account-recovery', './pages/weline-customer-account-recovery.js'],
    ['account-login', './pages/weline-customer-account-login.js'],
]);
const lazyComponentStyles = new Map([
    ['language-select', './components/weline-language-select.css'],
    ['file-preview', './components/weline-file-picker.css'],
    ['file-picker', './components/weline-file-picker.css'],
    ['mega-menu', './components/weline-mega-menu.css'],
    ['account-recovery', './pages/weline-customer-account-recovery.css'],
    ['account-login', './pages/weline-customer-account-login.css'],
]);
const lazyComponentLoads = new Map();
const lazyStyleLoads = new Map();
const activeFloatingMonitors = new Set();
const floatingPortalRecords = new Set();
let floatingPortalOrder = 0;
let observer = null;
let toastRegion = null;
let floatingViewportFrame = 0;
const iconSpriteUrl = new URL('./weline-icons.svg', import.meta.url).href;
const ICON_SPRITE_HOST_ID = 'weline-ui-icon-sprite';
let iconSpritePromise = null;

/**
 * External <use href="https://…/weline-icons.svg#id"> is unreliable in Chromium
 * (empty bbox / invisible glyphs). Inject the sprite once and reference local #ids.
 */
function ensureIconSprite() {
    if (document.getElementById(ICON_SPRITE_HOST_ID)) {
        return Promise.resolve();
    }
    if (iconSpritePromise) {
        return iconSpritePromise;
    }
    iconSpritePromise = fetch(iconSpriteUrl, { credentials: 'same-origin', cache: 'force-cache' })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`icon sprite ${response.status}`);
            }
            return response.text();
        })
        .then((svgText) => {
            if (document.getElementById(ICON_SPRITE_HOST_ID)) {
                return;
            }
            const host = document.createElement('div');
            host.id = ICON_SPRITE_HOST_ID;
            host.hidden = true;
            host.setAttribute('aria-hidden', 'true');
            host.innerHTML = String(svgText || '').replace(/^<\?xml[^>]*>\s*/i, '');
            (document.body || document.documentElement).prepend(host);
        })
        .catch((error) => {
            iconSpritePromise = null;
            console.warn('[Weline.UI] icon sprite inject failed', error);
        });
    return iconSpritePromise;
}

ensureIconSprite();

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function asElement(value) {
    if (value instanceof Element) return value;
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
        return document.querySelector(value);
    } catch (_error) {
        return null;
    }
}

function eventElement(event) {
    return event?.target instanceof Element ? event.target : null;
}

function eventClosest(event, selector) {
    return eventElement(event)?.closest(selector) || null;
}

function normalizeIconName(value) {
    const name = String(value || '').trim().toLowerCase();
    return /^[a-z][a-z0-9-]*$/.test(name) && !/^(?:mdi|fa[brs]?|ri)-/.test(name) ? name : 'circle';
}

function createIcon(name, options = {}) {
    ensureIconSprite();
    const semanticName = normalizeIconName(name);
    const size = ['xs', 'sm', 'md', 'lg', 'xl'].includes(options.size) ? options.size : 'md';
    const label = String(options.label || '').trim();
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    svg.classList.add('w-icon');
    svg.dataset.size = size;
    svg.dataset.icon = semanticName;
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    if (label === '') {
        svg.setAttribute('aria-hidden', 'true');
    } else {
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', label);
    }
    // Local fragment only — absolute sprite URLs often paint blank squares.
    use.setAttribute('href', `#w-icon-${semanticName}`);
    svg.append(use);
    return svg;
}

function registerIconElement() {
    if (!('customElements' in window) || customElements.get('w-icon')) return;
    customElements.define('w-icon', class WelineIconElement extends HTMLElement {
        static get observedAttributes() { return ['name', 'size', 'label']; }
        connectedCallback() { this.render(); }
        attributeChangedCallback() { if (this.isConnected) this.render(); }
        render() {
            this.replaceChildren(createIcon(this.getAttribute('name'), {
                size: this.getAttribute('size') || 'md',
                label: this.getAttribute('label') || '',
            }));
        }
    });
}

function componentNames(element) {
    return String(element.getAttribute('data-w-component') || '')
        .split(/\s+/)
        .map((name) => name.trim())
        .filter(Boolean);
}

function emit(element, component, phase, detail = {}, cancelable = phase.startsWith('before-')) {
    return element.dispatchEvent(new CustomEvent(`weline:ui:${component}:${phase}`, {
        bubbles: true,
        cancelable,
        detail,
    }));
}

function listen(target, type, handler, options, cleanups) {
    target.addEventListener(type, handler, options);
    cleanups.push(() => target.removeEventListener(type, handler, options));
}

function getFocusable(container) {
    return [...container.querySelectorAll(focusableSelector)]
        .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
}

function trapFocus(container, event) {
    if (event.key !== 'Tab') return;
    const focusable = getFocusable(container);
    if (focusable.length === 0) {
        event.preventDefault();
        container.focus({ preventScroll: true });
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function lockDocument() {
    document.documentElement.dataset.wScrollLocked = 'true';
}

function unlockDocument() {
    if (overlayStack.length === 0) delete document.documentElement.dataset.wScrollLocked;
}

function pushOverlay(element, restoreFocus) {
    const entry = { element, restoreFocus };
    overlayStack.push(entry);
    lockDocument();
    return entry;
}

function popOverlay(element) {
    const index = overlayStack.findIndex((entry) => entry.element === element);
    if (index < 0) return;
    const wasTop = index === overlayStack.length - 1;
    const [entry] = overlayStack.splice(index, 1);
    unlockDocument();
    if (wasTop && entry.restoreFocus instanceof HTMLElement && entry.restoreFocus.isConnected) {
        entry.restoreFocus.focus({ preventScroll: true });
    }
}

function topOverlay() {
    return overlayStack.at(-1)?.element || null;
}

function initializeThemePreference() {
    const root = document.documentElement;
    const area = root.dataset.wArea || 'frontend';
    const storageKey = 'weline_theme_preference';
    const media = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
    const normalize = (value) => ['system', 'light', 'dark'].includes(value) ? value : (area === 'backend' ? 'system' : 'light');
    const apply = (value) => {
        const fallback = area === 'backend' ? 'system' : 'light';
        let preference = normalize(String(value || root.dataset.themePreference || fallback));
        // Storefront: coerce system→light so prefers-color-scheme dark CSS cannot flip canvas to #020617.
        if (area !== 'backend' && preference === 'system') preference = 'light';
        const theme = area === 'backend'
            ? (preference === 'dark' || (preference === 'system' && media?.matches) ? 'dark' : 'light')
            : (preference === 'dark' ? 'dark' : 'light');
        root.dataset.themePreference = preference;
        root.dataset.theme = theme;
        root.style.colorScheme = theme;
        document.dispatchEvent(new CustomEvent('weline:theme:change', {
            detail: { preference, theme },
        }));
        return { preference, theme };
    };
    const storedPreference = () => {
        if (area === 'backend') return normalize(root.dataset.themePreference);
        try {
            return normalize(localStorage.getItem(storageKey) || root.dataset.themePreference);
        } catch (_error) {
            return normalize(root.dataset.themePreference);
        }
    };
    const setPreference = async (value) => {
        const preference = normalize(value);
        if (String(value) !== preference) {
            throw new TypeError('Theme preference must be system, light or dark.');
        }
        if (area === 'backend') {
            if (typeof Weline.Api?.call !== 'function') {
                throw new Error('Backend theme persistence API is unavailable.');
            }
            await Weline.Api.call('theme', 'setBackendThemeMode', {mode: preference});
        } else {
            try {
                localStorage.setItem(storageKey, preference);
            } catch (_error) {
            }
        }
        return apply(preference);
    };
    const onSystemChange = () => {
        if (root.dataset.themePreference === 'system') apply('system');
    };
    Weline.Theme = Object.assign(Weline.Theme || {}, {
        isSupportedPreference: (value) => ['system', 'light', 'dark'].includes(String(value || '')),
        getPreference: () => normalize(root.dataset.themePreference),
        getCurrent: () => root.dataset.theme || 'light',
        apply,
        setPreference,
    });
    media?.addEventListener('change', onSystemChange);
    document.addEventListener('weline:theme:preference', (event) => apply(event.detail?.preference));
    document.addEventListener('click', async (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-w-theme-preference]')
            : null;
        if (!(trigger instanceof HTMLButtonElement) || trigger.disabled) return;
        const preference = trigger.dataset.wThemePreference || '';
        if (!Weline.Theme.isSupportedPreference(preference)) return;
        trigger.disabled = true;
        try {
            await setPreference(preference);
        } catch (error) {
            Weline.UI?.toast.error(error instanceof Error ? error.message : String(error));
        } finally {
            trigger.disabled = false;
        }
    });
    document.addEventListener('weline:theme:change', (event) => {
        const preference = event.detail?.preference || 'system';
        document.querySelectorAll('[data-w-theme-preference]').forEach((option) => {
            option.dataset.state = option.dataset.wThemePreference === preference ? 'active' : 'idle';
            option.setAttribute('aria-pressed', String(option.dataset.wThemePreference === preference));
        });
    });
    apply(storedPreference());
}

function resolveFloatingDocument(root = null) {
    if (root instanceof Document) return root;
    if (root instanceof Node) return root.ownerDocument || document;
    return document;
}

function resolveFloatingWindow(root = null) {
    return resolveFloatingDocument(root).defaultView || window;
}

/**
 * Viewport bounds for floating placement. When `root` is an iframe node/document,
 * use that document's visualViewport so cross-frame toolbars clamp correctly.
 */
function floatingViewport(padding = 8, root = null) {
    const doc = resolveFloatingDocument(root);
    const win = resolveFloatingWindow(root);
    const visual = win.visualViewport;
    const width = visual?.width || doc.documentElement.clientWidth || win.innerWidth;
    const height = visual?.height || doc.documentElement.clientHeight || win.innerHeight;
    const offsetLeft = visual?.offsetLeft || 0;
    const offsetTop = visual?.offsetTop || 0;
    const safePadding = Math.max(4, Math.min(32, Number(padding) || 8));
    const rootStyle = getComputedStyle(doc.documentElement);
    const safeInset = (side) => Math.max(
        0,
        Number.parseFloat(rootStyle.getPropertyValue(`--weline-safe-area-${side}`)) || 0,
    );
    const safeTop = safeInset('top');
    const safeRight = safeInset('right');
    const safeBottom = safeInset('bottom');
    const safeLeft = safeInset('left');
    return {
        left: offsetLeft + safePadding + safeLeft,
        top: offsetTop + safePadding + safeTop,
        right: offsetLeft + width - safePadding - safeRight,
        bottom: offsetTop + height - safePadding - safeBottom,
        width: Math.max(0, width - safePadding * 2 - safeLeft - safeRight),
        height: Math.max(0, height - safePadding * 2 - safeTop - safeBottom),
    };
}

function captureFloatingReference(anchor, event = null, mode = 'element') {
    if (!(anchor instanceof Element)) return null;
    const rect = anchor.getBoundingClientRect();
    const eventTargetsAnchor = event?.target instanceof Node && anchor.contains(event.target);
    const pointer = event && Number.isFinite(event.clientX) && Number.isFinite(event.clientY)
        && (eventTargetsAnchor || (
            event.clientX >= rect.left && event.clientX <= rect.right
            && event.clientY >= rect.top && event.clientY <= rect.bottom
        ))
        ? {
            x: event.clientX,
            y: event.clientY,
            ratioX: rect.width > 0 ? Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)) : 0.5,
            ratioY: rect.height > 0 ? Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)) : 0.5,
        }
        : null;
    return {
        anchor,
        mode: mode === 'pointer' && pointer ? 'pointer' : 'element',
        pointer,
        rect: {
            left: rect.left,
            top: rect.top,
            right: rect.right,
            bottom: rect.bottom,
            width: rect.width,
            height: rect.height,
        },
        capturedAt: performance.now(),
    };
}

function clearFloatingPosition(floating) {
    if (!(floating instanceof HTMLElement)) return;
    delete floating.dataset.wFloatingPositioned;
    delete floating.dataset.wActualPlacement;
    for (const property of [
        '--w-floating-left',
        '--w-floating-top',
        '--w-floating-max-inline-size',
        '--w-floating-max-block-size',
        '--w-floating-transform-origin',
    ]) floating.style.removeProperty(property);
}

/**
 * CSS fixed-position coordinates and DOMRect coordinates can have different
 * origins while the visual viewport is panned/zoomed or when a top-layer host
 * establishes a containing block. Apply the calculated point, measure the
 * rendered box, then correct it in the coordinate space the user actually sees.
 */
function applyMeasuredFloatingPosition(floating, viewport, desiredLeft, desiredTop) {
    let cssLeft = desiredLeft;
    let cssTop = desiredTop;
    let placedRect = null;

    for (let pass = 0; pass < 3; pass += 1) {
        floating.style.setProperty('--w-floating-left', `${cssLeft.toFixed(3)}px`);
        floating.style.setProperty('--w-floating-top', `${cssTop.toFixed(3)}px`);
        placedRect = floating.getBoundingClientRect();

        const targetLeft = Math.max(
            viewport.left,
            Math.min(desiredLeft, viewport.right - placedRect.width),
        );
        const targetTop = Math.max(
            viewport.top,
            Math.min(desiredTop, viewport.bottom - placedRect.height),
        );
        const correctionX = targetLeft - placedRect.left;
        const correctionY = targetTop - placedRect.top;
        if (Math.abs(correctionX) < 0.25 && Math.abs(correctionY) < 0.25) break;
        cssLeft += correctionX;
        cssTop += correctionY;
    }

    placedRect = floating.getBoundingClientRect();
    return {
        cssLeft,
        cssTop,
        left: placedRect.left,
        top: placedRect.top,
        right: placedRect.right,
        bottom: placedRect.bottom,
    };
}

function readNumericZIndex(element) {
    if (!(element instanceof Element)) return null;
    const raw = getComputedStyle(element).zIndex;
    if (raw === 'auto' || raw === '') return null;
    const value = Number.parseInt(raw, 10);
    return Number.isFinite(value) ? value : null;
}

function readCssZToken(name, fallback) {
    const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const value = Number.parseInt(raw, 10);
    return Number.isFinite(value) ? value : fallback;
}

function floatingMenuFloor() {
    return readCssZToken('--weline-z-menu', 700);
}

function floatingToastFloor() {
    return readCssZToken('--weline-z-toast', 1100);
}

function floatingLayerFloor(floating) {
    if (floating instanceof Element
        && (floating.classList.contains('w-tooltip') || floating.classList.contains('w-toast-region'))) {
        return floatingToastFloor();
    }
    return floatingMenuFloor();
}

/**
 * Native showModal() dialogs are the only overlays that must own their floatings:
 * body content cannot paint above the browser top layer. Drawers and non-native
 * dialogs stay viewport-relative by portaling to body; transformed shells would
 * otherwise create a new fixed-position containing block and clip the popup.
 */
function resolveFloatingHost(from) {
    const start = from instanceof Element
        ? from
        : (from instanceof Node ? from.parentElement : null);
    const dialog = start?.closest('dialog');
    if (dialog instanceof HTMLDialogElement && dialog.open) {
        return dialog;
    }
    return document.body;
}

/**
 * Resolve the numeric stacking level of a host (or nearest ancestor with an
 * explicit z-index). Auto / missing → 0 so "host + 1" stays well-defined.
 */
function effectiveStackZ(element) {
    let node = element instanceof Element ? element : null;
    while (node && node !== document.documentElement && node !== document.body) {
        const z = readNumericZIndex(node);
        if (z != null) return z;
        node = node.parentElement;
    }
    if (node === document.body) {
        const bodyZ = readNumericZIndex(document.body);
        if (bodyZ != null) return bodyZ;
    }
    return 0;
}

function clearFloatingStackElevation(floating) {
    if (!(floating instanceof HTMLElement)) return;
    delete floating.dataset.wFloatingPortal;
    floating.style.removeProperty('z-index');
}

/**
 * Popup layer rule: always host z-index + 1.
 * - Hosted in dialog/drawer: shell (or nearest explicit z) + 1
 * - Hosted on body: max(open overlays, token floor - 1) + 1
 * - Nested popups on the same host: each new portal is max(siblings) + 1
 */
function applyFloatingStackElevation(floating, host) {
    if (!(floating instanceof HTMLElement)) return;
    floating.dataset.wFloatingPortal = 'true';
    let base = 0;
    if (host instanceof Element && host !== document.body) {
        base = effectiveStackZ(host);
    } else {
        document.querySelectorAll(
            '.w-overlay, .w-dialog[data-state="open"], .w-drawer[data-state="open"], dialog[open]',
        ).forEach((element) => {
            base = Math.max(base, effectiveStackZ(element));
        });
        // Keep body-level popups at least on the design-token menu/toast rung.
        base = Math.max(base, floatingLayerFloor(floating) - 1);
    }
    let peak = base + 1;
    const scope = host instanceof Element ? host : document.body;
    scope.querySelectorAll('[data-w-floating-portal]').forEach((sibling) => {
        if (sibling === floating) return;
        const z = readNumericZIndex(sibling);
        if (z != null && z >= peak) peak = z + 1;
    });
    floating.style.setProperty('z-index', String(peak));
}

/**
 * In-place hover/open flyouts (megamenu, overflow dropdown, …).
 * Opt-in: [data-wf-scope] > [data-wf-host] > [data-wf-layer]
 * Rule: elevate the scope above sibling stacking contexts, then layer = scope + 1.
 */
const elevateLayerSessions = new WeakMap();

function elevateHostIsActive(host) {
    if (!(host instanceof Element)) return false;
    if (host.matches(':hover') || host.matches(':focus-within')) return true;
    if (host.classList.contains('active') || host.classList.contains('is-open')) return true;
    if (host.getAttribute('data-state') === 'open') return true;
    if (host.getAttribute('aria-expanded') === 'true') return true;
    return false;
}

function resolveElevateLayer(host) {
    if (!(host instanceof Element)) return null;
    const direct = host.querySelector(':scope > [data-wf-layer]');
    if (direct instanceof HTMLElement) return direct;
    const nested = host.querySelector('[data-wf-layer]');
    return nested instanceof HTMLElement ? nested : null;
}

function resolveElevateScope(host) {
    if (!(host instanceof Element)) return null;
    const scoped = host.closest('[data-wf-scope]');
    if (scoped instanceof HTMLElement) return scoped;
    // Header / layout slots often form sibling stacking contexts (e.g.「全部」vs 分类).
    const layoutScope = host.closest(
        '.header-categories, .header-nav-left-slot, .header-nav-right-slot, .header-nav-links-slot, .header-nav-fill, .header-main-nav',
    );
    if (layoutScope instanceof HTMLElement) return layoutScope;
    return host instanceof HTMLElement ? host : null;
}

function siblingStackPeak(element) {
    if (!(element instanceof Element) || !element.parentElement) return 0;
    let peak = 0;
    for (const sibling of element.parentElement.children) {
        if (!(sibling instanceof Element) || sibling === element) continue;
        const z = effectiveStackZ(sibling);
        if (z > peak) peak = z;
    }
    return peak;
}

/**
 * Elevate an in-place layer (and its scope) to host/sibling peak + 1.
 * @returns {{scope: HTMLElement|null, host: HTMLElement|null, layer: HTMLElement, scopeZ: number, layerZ: number}|null}
 */
function applyElevateLayer(layerOrHost, hostHint = null) {
    let layer = null;
    let host = hostHint instanceof HTMLElement ? hostHint : null;
    if (layerOrHost instanceof HTMLElement) {
        if (layerOrHost.hasAttribute('data-wf-layer')) {
            layer = layerOrHost;
            host = host || layer.closest('[data-wf-host]') || layer.parentElement;
        } else if (layerOrHost.hasAttribute('data-wf-host')) {
            host = layerOrHost;
            layer = resolveElevateLayer(host);
        } else {
            layer = layerOrHost;
            host = host || layer.closest('[data-wf-host]') || layer.parentElement;
        }
    }
    if (!(layer instanceof HTMLElement)) return null;
    if (!(host instanceof HTMLElement)) {
        host = layer.parentElement instanceof HTMLElement ? layer.parentElement : null;
    }
    const scope = resolveElevateScope(host || layer);
    let base = Math.max(
        siblingStackPeak(scope || host || layer),
        effectiveStackZ(scope || host || layer),
        floatingMenuFloor() - 1,
    );
    // Nested elevate layers: sit above the parent layer session.
    const parentLayer = layer.parentElement?.closest('[data-wf-layer][data-wf-raised]');
    if (parentLayer instanceof HTMLElement) {
        base = Math.max(base, effectiveStackZ(parentLayer));
    }
    const scopeZ = base + 1;
    const layerZ = scopeZ + 1;

    if (scope instanceof HTMLElement) {
        scope.dataset.wfRaised = 'true';
        scope.style.setProperty('z-index', String(scopeZ));
        scope.style.setProperty('--wf-stack-z', String(scopeZ));
    }
    if (host instanceof HTMLElement && host !== scope) {
        host.dataset.wfRaised = 'true';
        host.style.setProperty('z-index', String(scopeZ));
    }
    layer.dataset.wfRaised = 'true';
    layer.dataset.wFloatingPortal = 'true';
    layer.style.setProperty('z-index', String(layerZ));
    layer.style.setProperty('--wf-stack-z', String(layerZ));
    unclipElevateAncestors(layer, scope);

    const session = { scope, host, layer, scopeZ, layerZ };
    elevateLayerSessions.set(layer, session);
    if (host) elevateLayerSessions.set(host, session);
    if (scope) elevateLayerSessions.set(scope, session);
    return session;
}

function unclipElevateAncestors(layer, scope) {
    if (!(layer instanceof Element)) return;
    let node = layer.parentElement;
    while (node && node !== document.body && node !== document.documentElement) {
        if (node instanceof HTMLElement) {
            node.dataset.wfUnclip = 'true';
        }
        if (scope && node === scope) break;
        node = node.parentElement;
    }
}

function clearElevateUnclip(scope, layer) {
    const roots = [scope, layer?.parentElement].filter(Boolean);
    for (const root of roots) {
        if (!(root instanceof Element)) continue;
        root.querySelectorAll('[data-wf-unclip]').forEach((node) => {
            if (!(node instanceof HTMLElement)) return;
            // Keep unclip while another elevated layer still needs this ancestor.
            if (node.querySelector('[data-wf-layer][data-wf-raised]')) return;
            delete node.dataset.wfUnclip;
        });
        if (root instanceof HTMLElement && root.hasAttribute('data-wf-unclip')) {
            if (!root.querySelector('[data-wf-layer][data-wf-raised]')) {
                delete root.dataset.wfUnclip;
            }
        }
    }
}

function clearElevateLayer(target) {
    const session = target instanceof Element ? elevateLayerSessions.get(target) : null;
    const layer = session?.layer
        || (target instanceof HTMLElement && target.hasAttribute('data-wf-layer') ? target : null)
        || (target instanceof Element ? resolveElevateLayer(target) : null);
    const host = session?.host
        || (target instanceof HTMLElement && target.hasAttribute('data-wf-host') ? target : null)
        || (layer instanceof Element ? layer.closest('[data-wf-host]') : null);
    const scope = session?.scope || resolveElevateScope(host || layer);

    if (layer instanceof HTMLElement) {
        delete layer.dataset.wfRaised;
        clearFloatingStackElevation(layer);
        layer.style.removeProperty('--wf-stack-z');
        elevateLayerSessions.delete(layer);
    }
    if (host instanceof HTMLElement) {
        // Keep host elevated while still active (e.g. .active / :hover).
        if (!elevateHostIsActive(host)) {
            delete host.dataset.wfRaised;
            host.style.removeProperty('z-index');
            host.style.removeProperty('--wf-stack-z');
            elevateLayerSessions.delete(host);
        }
    }
    if (scope instanceof HTMLElement) {
        const stillActive = scope.querySelector('[data-wf-host][data-wf-raised], [data-wf-layer][data-wf-raised]');
        if (!stillActive) {
            delete scope.dataset.wfRaised;
            scope.style.removeProperty('z-index');
            scope.style.removeProperty('--wf-stack-z');
            elevateLayerSessions.delete(scope);
        }
    }
    clearElevateUnclip(scope, layer);
}

function syncElevateHost(host) {
    if (!(host instanceof HTMLElement) || !host.hasAttribute('data-wf-host')) return;
    if (elevateHostIsActive(host)) {
        applyElevateLayer(host);
        return;
    }
    clearElevateLayer(host);
}

function installElevateLayerRuntime() {
    if (document.documentElement.dataset.wfStackRt === '1') return;
    document.documentElement.dataset.wfStackRt = '1';

    const onPointerOver = (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const host = target.closest('[data-wf-host]');
        if (host instanceof HTMLElement) applyElevateLayer(host);
    };
    const onPointerOut = (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const host = target.closest('[data-wf-host]');
        if (!(host instanceof HTMLElement)) return;
        const related = event.relatedTarget;
        if (related instanceof Node && host.contains(related)) return;
        // Defer so :hover has settled after leaving into the layer/sibling.
        queueMicrotask(() => syncElevateHost(host));
    };

    document.addEventListener('pointerover', onPointerOver, true);
    document.addEventListener('pointerout', onPointerOut, true);
    document.addEventListener('focusin', (event) => {
        const host = event.target instanceof Element
            ? event.target.closest('[data-wf-host]')
            : null;
        if (host instanceof HTMLElement) applyElevateLayer(host);
    }, true);
    document.addEventListener('focusout', (event) => {
        const host = event.target instanceof Element
            ? event.target.closest('[data-wf-host]')
            : null;
        if (!(host instanceof HTMLElement)) return;
        queueMicrotask(() => syncElevateHost(host));
    }, true);

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            if (!(record.target instanceof Element)) continue;
            const host = record.target.closest('[data-wf-host]') || (
                record.target.hasAttribute('data-wf-host') ? record.target : null
            );
            if (host instanceof HTMLElement) syncElevateHost(host);
        }
    });
    observer.observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'data-state', 'aria-expanded'],
    });
}

function floatingPortalContains(record, target, visited = new Set()) {
    if (!(target instanceof Node) || !record?.mounted || visited.has(record)) return false;
    visited.add(record);
    if (record.floating.contains(target)) return true;
    for (const child of floatingPortalRecords) {
        if (!child.mounted || child === record || !record.floating.contains(child.marker)) continue;
        if (floatingPortalContains(child, target, visited)) return true;
    }
    return false;
}

function topmostDismissableFloatingPortal() {
    let topmost = null;
    for (const record of floatingPortalRecords) {
        if (!record.mounted || record.name === 'tooltip' || record.floating.hidden) continue;
        if (!topmost || record.order > topmost.order) topmost = record;
    }
    return topmost;
}

function floatingPortalOwner(record) {
    const markerParent = record?.marker?.parentElement;
    return markerParent instanceof Element
        ? markerParent.closest('[data-w-component]')
        : null;
}

function floatingPortalTrigger(record) {
    const owner = floatingPortalOwner(record);
    const panelId = String(record?.floating?.id || '').trim();
    const scopes = owner instanceof Element ? [owner, document] : [document];
    if (panelId) {
        for (const scope of scopes) {
            for (const candidate of scope.querySelectorAll('[aria-controls]')) {
                if (
                    candidate instanceof HTMLElement
                    && candidate.getAttribute('aria-controls') === panelId
                ) {
                    return candidate;
                }
            }
        }
    }
    const name = String(record?.name || '').trim();
    if (!name || !(owner instanceof Element)) return null;
    const candidate = owner.querySelector(`[data-w-${name}-trigger]`);
    return candidate instanceof HTMLElement ? candidate : null;
}

/**
 * Some interactive header controls intentionally stop click bubbling. Listen in capture phase,
 * then defer the fallback until the original click has completed so each interaction dismisses
 * at most the portal that was topmost when it started. Existing component handlers remain first
 * choice; this only closes the same still-open record when bubbling never reached them.
 */
function scheduleFloatingOutsideDismiss(event) {
    const target = event.target;
    if (!(target instanceof Node)) return;
    const record = topmostDismissableFloatingPortal();
    if (!record || floatingPortalContains(record, target)) return;
    const owner = floatingPortalOwner(record);
    if (
        record.floating.dataset.wDismissOutside === 'false'
        || owner?.getAttribute('data-w-dismiss-outside') === 'false'
    ) {
        return;
    }
    const trigger = floatingPortalTrigger(record);
    if (!(trigger instanceof HTMLElement) || trigger.contains(target) || trigger.hasAttribute('disabled')) {
        return;
    }
    queueMicrotask(() => {
        if (
            topmostDismissableFloatingPortal() !== record
            || !record.mounted
            || record.floating.hidden
        ) {
            return;
        }
        const outsideFocus = document.activeElement;
        trigger.click();
        if (
            outsideFocus instanceof HTMLElement
            && outsideFocus !== document.body
            && outsideFocus !== trigger
            && outsideFocus.isConnected
            && !record.floating.contains(outsideFocus)
        ) {
            outsideFocus.focus({ preventScroll: true });
        }
    });
}

document.addEventListener('click', scheduleFloatingOutsideDismiss, true);

function createFloatingPortal(floating, name = 'floating') {
    if (!(floating instanceof HTMLElement)) {
        return {
            mount() {},
            restore() {},
            contains() { return false; },
            isTopmost() { return false; },
            destroy() {},
        };
    }
    const marker = document.createComment(`w-${name}-portal`);
    const record = {
        floating,
        marker,
        name,
        mounted: false,
        order: 0,
    };
    floating.before(marker);
    floatingPortalRecords.add(record);
    const restore = () => {
        record.mounted = false;
        record.order = 0;
        clearFloatingStackElevation(floating);
        if (marker.parentNode && floating.parentNode !== marker.parentNode) marker.after(floating);
    };
    return {
        mount() {
            const host = resolveFloatingHost(marker.parentElement || marker);
            record.mounted = true;
            record.order = ++floatingPortalOrder;
            applyFloatingStackElevation(floating, host);
            if (floating.parentNode !== host) host.append(floating);
        },
        restore,
        contains(target) { return floatingPortalContains(record, target); },
        isTopmost() { return topmostDismissableFloatingPortal() === record; },
        destroy() {
            restore();
            floatingPortalRecords.delete(record);
            marker.remove();
        },
    };
}

function positionFloating(anchor, floating, placement = 'bottom-start', reference = null) {
    if (!(anchor instanceof Element) || !(floating instanceof HTMLElement)) return null;
    const liveRect = anchor.getBoundingClientRect();
    const [requestedSide, requestedAlignment] = String(placement || '').toLowerCase().split('-');
    const side = ['top', 'right', 'bottom', 'left'].includes(requestedSide) ? requestedSide : 'bottom';
    const alignment = ['start', 'center', 'end'].includes(requestedAlignment) ? requestedAlignment : 'start';
    const viewportPadding = floating.dataset.wViewportPadding || anchor.dataset.wViewportPadding || 8;
    syncFloatingViewportCssBounds(floating.ownerDocument);
    const viewport = floatingViewport(viewportPadding, floating);
    const anchorVisible = liveRect.bottom > viewport.top
        && liveRect.top < viewport.bottom
        && liveRect.right > viewport.left
        && liveRect.left < viewport.right;
    if (!anchor.isConnected || !anchorVisible) return { anchorVisible: false };

    const validReference = reference?.anchor === anchor ? reference : null;
    const referenceRect = validReference?.mode === 'pointer' && validReference.pointer
        ? (() => {
            const x = liveRect.left + liveRect.width * validReference.pointer.ratioX;
            const y = liveRect.top + liveRect.height * validReference.pointer.ratioY;
            return side === 'top' || side === 'bottom'
                ? {
                    left: x,
                    right: x,
                    top: liveRect.top,
                    bottom: liveRect.bottom,
                    width: 0,
                    height: liveRect.height,
                }
                : {
                    left: liveRect.left,
                    right: liveRect.right,
                    top: y,
                    bottom: y,
                    width: liveRect.width,
                    height: 0,
                };
        })()
        : liveRect;
    const gapSource = floating.dataset.wGap
        || anchor.dataset.wGap
        || (anchor instanceof Element ? anchor.closest?.('[data-w-gap]')?.dataset?.wGap : '')
        || '';
    const gapParsed = Number.parseFloat(String(gapSource));
    const gap = Number.isFinite(gapParsed) ? Math.max(0, gapParsed) : 8;
    const available = {
        top: Math.max(0, referenceRect.top - viewport.top - gap),
        right: Math.max(0, viewport.right - referenceRect.right - gap),
        bottom: Math.max(0, viewport.bottom - referenceRect.bottom - gap),
        left: Math.max(0, referenceRect.left - viewport.left - gap),
    };
    const opposite = { top: 'bottom', right: 'left', bottom: 'top', left: 'right' };

    clearFloatingPosition(floating);
    floating.dataset.wFloatingPositioned = 'pending';
    floating.style.setProperty('--w-floating-max-inline-size', `${Math.floor(viewport.width)}px`);
    floating.style.setProperty('--w-floating-max-block-size', `${Math.floor(viewport.height)}px`);
    let floatingRect = floating.getBoundingClientRect();
    const vertical = side === 'top' || side === 'bottom';
    const required = vertical ? floatingRect.height : floatingRect.width;
    const resolvedSide = available[side] < required && available[opposite[side]] > available[side]
        ? opposite[side]
        : side;
    const sideSpace = Math.max(0, available[resolvedSide]);
    if (resolvedSide === 'top' || resolvedSide === 'bottom') {
        floating.style.setProperty('--w-floating-max-block-size', `${Math.floor(sideSpace)}px`);
    } else {
        floating.style.setProperty('--w-floating-max-inline-size', `${Math.floor(sideSpace)}px`);
    }
    floatingRect = floating.getBoundingClientRect();

    const direction = getComputedStyle(anchor).direction;
    const alignStart = direction === 'rtl' ? referenceRect.right - floatingRect.width : referenceRect.left;
    const alignEnd = direction === 'rtl' ? referenceRect.left : referenceRect.right - floatingRect.width;
    let left = alignment === 'center'
        ? referenceRect.left + (referenceRect.width - floatingRect.width) / 2
        : alignment === 'end' ? alignEnd : alignStart;
    let top = alignment === 'center'
        ? referenceRect.top + (referenceRect.height - floatingRect.height) / 2
        : alignment === 'end' ? referenceRect.bottom - floatingRect.height : referenceRect.top;

    if (resolvedSide === 'top') top = referenceRect.top - floatingRect.height - gap;
    if (resolvedSide === 'bottom') top = referenceRect.bottom + gap;
    if (resolvedSide === 'left') left = referenceRect.left - floatingRect.width - gap;
    if (resolvedSide === 'right') left = referenceRect.right + gap;

    left = Math.max(viewport.left, Math.min(left, viewport.right - floatingRect.width));
    top = Math.max(viewport.top, Math.min(top, viewport.bottom - floatingRect.height));
    const actualPlacement = `${resolvedSide}-${alignment}`;
    const measured = applyMeasuredFloatingPosition(floating, viewport, left, top);
    floating.style.setProperty(
        '--w-floating-transform-origin',
        resolvedSide === 'top' ? 'bottom' : resolvedSide === 'bottom' ? 'top' : opposite[resolvedSide],
    );
    floating.dataset.wActualPlacement = actualPlacement;
    floating.dataset.wFloatingPositioned = 'true';
    return {
        anchorVisible: true,
        placement: actualPlacement,
        left: measured.left,
        top: measured.top,
        right: measured.right,
        bottom: measured.bottom,
    };
}

function createFloatingMonitor(anchor, getFloating, getPlacement, onAnchorHidden) {
    let frame = 0;
    let reference = null;
    let observedFloating = null;
    let boundWin = null;
    let onWinScroll = null;
    let onWinResize = null;
    const raf = (callback) => {
        const win = resolveFloatingWindow(observedFloating || anchor);
        return (win.requestAnimationFrame || requestAnimationFrame)(callback);
    };
    const caf = (id) => {
        const win = resolveFloatingWindow(observedFloating || anchor);
        (win.cancelAnimationFrame || cancelAnimationFrame)(id);
    };
    const unbindForeignWindow = () => {
        if (!boundWin) return;
        if (onWinScroll) boundWin.removeEventListener('scroll', onWinScroll, true);
        if (onWinResize) {
            boundWin.removeEventListener('resize', onWinResize);
            boundWin.visualViewport?.removeEventListener('resize', onWinResize);
            boundWin.visualViewport?.removeEventListener('scroll', onWinResize);
        }
        boundWin = null;
        onWinScroll = null;
        onWinResize = null;
    };
    const bindForeignWindow = (floating) => {
        const win = resolveFloatingWindow(floating);
        if (!win || win === window) {
            unbindForeignWindow();
            return;
        }
        if (win === boundWin) return;
        unbindForeignWindow();
        boundWin = win;
        onWinScroll = () => schedule(true);
        onWinResize = () => schedule(true);
        win.addEventListener('scroll', onWinScroll, true);
        win.addEventListener('resize', onWinResize);
        win.visualViewport?.addEventListener('resize', onWinResize, { passive: true });
        win.visualViewport?.addEventListener('scroll', onWinResize, { passive: true });
    };
    const place = (nextReference = reference) => {
        const floating = getFloating();
        if (!(floating instanceof HTMLElement) || floating.hidden) return null;
        reference = nextReference;
        const result = positionFloating(anchor, floating, getPlacement(), reference);
        if (result?.anchorVisible === false) onAnchorHidden?.();
        return result;
    };
    const schedule = (refreshAnchor = false) => {
        const floating = getFloating();
        if (!(floating instanceof HTMLElement) || floating.hidden) return;
        if (refreshAnchor && reference?.mode !== 'pointer') {
            reference = captureFloatingReference(anchor);
        }
        caf(frame);
        frame = raf(() => place());
    };
    const observer = typeof ResizeObserver === 'function'
        ? new ResizeObserver(() => schedule(false))
        : null;
    const monitor = {
        place,
        viewportChanged() { schedule(true); },
        observe(floating) {
            if (!(floating instanceof HTMLElement)) return;
            if (observedFloating && observedFloating !== floating) observer?.unobserve(observedFloating);
            observedFloating = floating;
            bindForeignWindow(floating);
            observer?.observe(anchor);
            observer?.observe(floating);
            activeFloatingMonitors.add(monitor);
        },
        unobserve(floating) {
            if (floating instanceof HTMLElement) observer?.unobserve(floating);
            observer?.unobserve(anchor);
            if (observedFloating === floating) {
                observedFloating = null;
                unbindForeignWindow();
            }
            activeFloatingMonitors.delete(monitor);
        },
        reset() { reference = null; },
        destroy() {
            caf(frame);
            observer?.disconnect();
            observedFloating = null;
            unbindForeignWindow();
            activeFloatingMonitors.delete(monitor);
        },
    };
    return monitor;
}

function scheduleFloatingViewportUpdate() {
    cancelAnimationFrame(floatingViewportFrame);
    floatingViewportFrame = requestAnimationFrame(() => {
        for (const monitor of activeFloatingMonitors) monitor.viewportChanged();
    });
}

function syncFloatingViewportCssBounds(root = document) {
    const doc = resolveFloatingDocument(root);
    const viewport = floatingViewport(8, doc);
    const html = doc.documentElement;
    html.style.setProperty('--w-floating-viewport-left', `${viewport.left}px`);
    html.style.setProperty('--w-floating-viewport-top', `${viewport.top}px`);
    html.style.setProperty('--w-floating-viewport-right', `${viewport.right}px`);
    html.style.setProperty('--w-floating-viewport-bottom', `${viewport.bottom}px`);
}

function refreshFloatingViewportGeometry() {
    // Resize/orientation events expose the new viewport before the next paint. Publish the exact
    // visualViewport + safe-area bounds first, reposition now, then retain the coalesced frame pass
    // for browser chrome and virtual-keyboard geometry that settles one frame later.
    syncFloatingViewportCssBounds();
    for (const monitor of activeFloatingMonitors) monitor.viewportChanged();
    scheduleFloatingViewportUpdate();
}

function installFloatingViewportListeners() {
    syncFloatingViewportCssBounds();
    window.addEventListener('resize', refreshFloatingViewportGeometry, { passive: true });
    document.addEventListener('scroll', scheduleFloatingViewportUpdate, { passive: true, capture: true });
    window.visualViewport?.addEventListener('resize', refreshFloatingViewportGeometry, { passive: true });
    window.visualViewport?.addEventListener('scroll', refreshFloatingViewportGeometry, { passive: true });
    window.screen?.orientation?.addEventListener('change', refreshFloatingViewportGeometry, { passive: true });
}

function define(name, factory) {
    if (!/^[a-z][a-z0-9-]*$/.test(name) || typeof factory !== 'function') {
        throw new TypeError('Weline.UI.define requires a kebab-case name and factory.');
    }
    if (definitions.has(name)) throw new Error(`Weline.UI component already defined: ${name}`);
    definitions.set(name, factory);
    return factory;
}

function loadLazyComponent(name, element) {
    const source = lazyComponentSources.get(name);
    if (!source) return;
    if (!lazyComponentLoads.has(source)) {
        const sourceUrl = new URL(source, import.meta.url);
        // Inherit ?v= from weline-ui.js so lazy modules bust with the shell.
        const shellQuery = new URL(import.meta.url).search;
        if (shellQuery && !sourceUrl.search) sourceUrl.search = shellQuery;
        lazyComponentLoads.set(source, import(sourceUrl.href).then((module) => {
            if (typeof module.register !== 'function') {
                throw new TypeError(`Weline UI lazy module does not export register(): ${source}`);
            }
            module.register(UI);
        }));
    }
    const styleSource = lazyComponentStyles.get(name);
    let styleLoad = Promise.resolve();
    if (styleSource) {
        const styleUrl = new URL(styleSource, import.meta.url);
        const shellQuery = new URL(import.meta.url).search;
        if (shellQuery && !styleUrl.search) styleUrl.search = shellQuery;
        if (!lazyStyleLoads.has(styleUrl.href)) {
            const existing = [...document.querySelectorAll('link[rel="stylesheet"]')]
                .find((link) => link.href === styleUrl.href);
            if (existing instanceof HTMLLinkElement) {
                lazyStyleLoads.set(styleUrl.href, Promise.resolve(existing));
            } else {
                lazyStyleLoads.set(styleUrl.href, new Promise((resolve, reject) => {
                    const link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = styleUrl.href;
                    link.dataset.wUiStylesheet = name;
                    link.addEventListener('load', () => resolve(link), { once: true });
                    link.addEventListener('error', () => reject(new Error(`Unable to load Weline UI stylesheet: ${styleSource}`)), { once: true });
                    document.head.append(link);
                }));
            }
        }
        styleLoad = lazyStyleLoads.get(styleUrl.href);
    }
    Promise.all([lazyComponentLoads.get(source), styleLoad])
        .then(() => {
            if (element.isConnected) mountElement(element);
        })
        .catch((error) => {
            console.error(`Unable to load Weline UI component: ${name}`, error);
            UI.toast.error(`Unable to load UI component: ${name}`);
        });
}

function mountElement(element) {
    const map = instances.get(element) || new Map();
    const elementCleanups = cleanupByElement.get(element) || [];
    for (const name of componentNames(element)) {
        if (map.has(name)) continue;
        const factory = definitions.get(name);
        if (!factory) {
            loadLazyComponent(name, element);
            continue;
        }
        const localCleanups = [];
        const context = {
            element,
            emit: (phase, detail, cancelable) => emit(element, name, phase, detail, cancelable),
            listen: (target, type, handler, options) => listen(target, type, handler, options, localCleanups),
            position: positionFloating,
            floating: {
                capture: captureFloatingReference,
                monitor: createFloatingMonitor,
                clear: clearFloatingPosition,
                portal: createFloatingPortal,
                viewport: floatingViewport,
                syncViewport: syncFloatingViewportCssBounds,
            },
            UI,
        };
        const instance = factory(context) || {};
        if (typeof instance.destroy === 'function') localCleanups.push(() => instance.destroy());
        map.set(name, instance);
        elementCleanups.push(...localCleanups);
    }
    instances.set(element, map);
    cleanupByElement.set(element, elementCleanups);
}

function mount(root = document) {
    if (root instanceof Element && root.matches('[data-w-component]')) mountElement(root);
    if (root instanceof Document || root instanceof DocumentFragment || root instanceof Element) {
        root.querySelectorAll('[data-w-component]').forEach(mountElement);
    }
    return root;
}

function logicalUnmountScopes(root) {
    const scopes = [root];
    for (let index = 0; index < scopes.length; index += 1) {
        const scope = scopes[index];
        if (!(scope instanceof Node)) continue;
        for (const record of floatingPortalRecords) {
            if (!record.mounted || scopes.includes(record.floating) || !scope.contains(record.marker)) continue;
            scopes.push(record.floating);
        }
    }
    return scopes;
}

function unmount(root) {
    const elements = new Set();
    for (const scope of logicalUnmountScopes(root)) {
        if (scope instanceof Element && scope.matches('[data-w-component]')) elements.add(scope);
        if (scope instanceof Document || scope instanceof DocumentFragment || scope instanceof Element) {
            scope.querySelectorAll('[data-w-component]').forEach((element) => elements.add(element));
        }
    }
    for (const element of [...elements].reverse()) {
        for (const cleanup of (cleanupByElement.get(element) || []).reverse()) cleanup();
        cleanupByElement.delete(element);
        instances.delete(element);
    }
    return root;
}

function get(elementOrSelector, name) {
    const element = asElement(elementOrSelector) || (elementOrSelector instanceof Element ? elementOrSelector : null);
    if (!element) return null;
    return instances.get(element)?.get(name) || null;
}

function ensureMounted(element, name) {
    if (!element) return null;
    mountElement(element);
    return get(element, name);
}

function closeTransientSurfaces(reason = 'pagehide') {
    document.querySelectorAll('[data-w-component~="menu"]').forEach((element) => {
        get(element, 'menu')?.close(false, reason, true);
    });
    document.querySelectorAll('[data-w-component~="popover"]').forEach((element) => {
        get(element, 'popover')?.close(reason, true);
    });
    document.querySelectorAll('[data-w-component~="tooltip"]').forEach((element) => {
        get(element, 'tooltip')?.hide(reason, true);
    });
}

function registerDialog() {
    define('dialog', ({ element, listen, emit: emitLocal }) => {
        const nativeDialog = element instanceof HTMLDialogElement;
        let lastFocus = null;
        let backdrop = null;
        const open = (options = {}) => {
            const isOpen = nativeDialog ? element.open : element.dataset.state === 'open';
            if (isOpen || !emitLocal('before-open', { options })) return false;
            lastFocus = document.activeElement;
            element.dataset.state = 'open';
            if (nativeDialog) {
                element.showModal();
            } else {
                element.hidden = false;
                element.setAttribute('role', 'dialog');
                element.setAttribute('aria-modal', 'true');
                element.setAttribute('aria-hidden', 'false');
                backdrop = document.createElement('div');
                backdrop.className = 'w-overlay';
                document.body.append(backdrop);
                backdrop.addEventListener('click', () => {
                    if (element.dataset.wBackdrop !== 'static') close('backdrop');
                });
            }
            pushOverlay(element, lastFocus);
            queueMicrotask(() => (element.querySelector('[autofocus]') || getFocusable(element)[0] || element).focus({ preventScroll: true }));
            emitLocal('open', { options }, false);
            return true;
        };
        const close = (returnValue = '') => {
            const isOpen = nativeDialog ? element.open : element.dataset.state === 'open';
            if (!isOpen || !emitLocal('before-close', { returnValue })) return false;
            if (nativeDialog) {
                element.close(String(returnValue));
            } else {
                element.dataset.state = 'closed';
                element.setAttribute('aria-hidden', 'true');
                element.hidden = true;
                backdrop?.remove();
                backdrop = null;
                popOverlay(element);
                emitLocal('close', { returnValue: String(returnValue) }, false);
            }
            return true;
        };
        listen(element, 'keydown', (event) => {
            if (topOverlay() !== element) return;
            if (!nativeDialog && event.key === 'Escape' && element.dataset.wClosable !== 'false') {
                event.preventDefault();
                event.stopPropagation();
                close('escape');
                return;
            }
            trapFocus(element, event);
        });
        if (nativeDialog) {
            listen(element, 'cancel', (event) => {
                event.preventDefault();
                if (topOverlay() === element && element.dataset.wClosable !== 'false') close('escape');
            });
        }
        listen(element, 'click', (event) => {
            if (nativeDialog && event.target === element && element.dataset.wBackdrop !== 'static') close('backdrop');
        });
        if (nativeDialog) {
            listen(element, 'close', () => {
                element.dataset.state = 'closed';
                popOverlay(element);
                emitLocal('close', { returnValue: element.returnValue }, false);
            });
        }
        return {
            open,
            close,
            element,
            destroy: () => {
                if (nativeDialog && element.open) element.close('unmount');
                backdrop?.remove();
                backdrop = null;
                popOverlay(element);
            },
        };
    });
}

function registerDrawer() {
    define('drawer', ({ element, listen, emit: emitLocal }) => {
        let backdrop = null;
        let lastFocus = null;
        const persistentMedia = element.dataset.wResponsive === 'desktop-persistent'
            ? window.matchMedia('(min-width: 64rem)')
            : null;
        const syncAccessibility = () => {
            const persistent = persistentMedia?.matches === true;
            element.setAttribute('aria-hidden', String(!persistent && element.dataset.state !== 'open'));
            if (persistent) element.hidden = false;
        };
        const open = (options = {}) => {
            if (element.dataset.state === 'open' || !emitLocal('before-open', { options })) return false;
            lastFocus = document.activeElement;
            element.dataset.state = 'open';
            element.hidden = false;
            element.setAttribute('aria-hidden', 'false');
            backdrop = document.createElement('div');
            backdrop.className = 'w-overlay';
            backdrop.dataset.wDrawerBackdrop = element.id || '';
            document.body.append(backdrop);
            backdrop.addEventListener('click', () => {
                if (element.dataset.wBackdrop !== 'static') close('backdrop');
            });
            pushOverlay(element, lastFocus);
            queueMicrotask(() => (getFocusable(element)[0] || element).focus({ preventScroll: true }));
            emitLocal('open', { options }, false);
            return true;
        };
        const close = (reason = '') => {
            if (element.dataset.state !== 'open' || !emitLocal('before-close', { reason })) return false;
            element.dataset.state = 'closed';
            syncAccessibility();
            backdrop?.remove();
            backdrop = null;
            popOverlay(element);
            const finish = () => {
                if (persistentMedia?.matches === true) {
                    element.hidden = false;
                    return;
                }
                if (element.dataset.state !== 'open' && element.dataset.wKeepMounted !== 'true') element.hidden = true;
            };
            element.addEventListener('transitionend', finish, { once: true });
            setTimeout(finish, 240);
            emitLocal('close', { reason }, false);
            return true;
        };
        listen(element, 'keydown', (event) => {
            if (topOverlay() !== element) return;
            if (event.key === 'Escape' && element.dataset.wClosable !== 'false') {
                event.preventDefault();
                event.stopPropagation();
                close('escape');
                return;
            }
            trapFocus(element, event);
        });
        if (persistentMedia) {
            listen(persistentMedia, 'change', () => {
                if (persistentMedia.matches && element.dataset.state === 'open') close('viewport');
                syncAccessibility();
            });
            syncAccessibility();
        }
        return {
            open,
            close,
            element,
            destroy: () => {
                backdrop?.remove();
                backdrop = null;
                popOverlay(element);
            },
        };
    });
}

function registerRemoteDrawer() {
    define('remote-drawer', ({ element, listen, UI: componentUI }) => {
        const frame = element.querySelector('[data-w-remote-frame]');
        if (!(frame instanceof HTMLIFrameElement)) return {};

        const load = (force = false) => {
            const source = frame.dataset.src || '';
            if (source === '') return false;
            if (force || frame.getAttribute('src') !== source) frame.setAttribute('src', source);
            return true;
        };
        const frameDocument = () => {
            try {
                return frame.contentDocument;
            } catch (_error) {
                return null;
            }
        };
        const submit = () => {
            const documentRoot = frameDocument();
            const result = documentRoot?.body?.dataset.offcanvasResult || '';
            if (['success', 'error', 'info'].includes(result)) return false;
            const configuredSelector = element.dataset.wRemoteForm || '';
            let form = null;
            try {
                form = configuredSelector !== ''
                    ? documentRoot?.querySelector(configuredSelector)
                    : documentRoot?.querySelector('form');
            } catch (_error) {
                form = null;
            }
            if (!(form instanceof HTMLFormElement)) {
                componentUI.toast.warning(Weline.config?.i18n?.formNotFound || 'Form not found.');
                return false;
            }
            if (!form.reportValidity()) return false;
            form.requestSubmit();
            return true;
        };

        listen(element, 'weline:ui:drawer:before-open', () => load());
        listen(element, 'click', (event) => {
            const action = eventClosest(event, '[data-w-remote-action]')?.dataset.wRemoteAction || '';
            if (action === 'reload') load(true);
            if (action === 'submit' && element.dataset.wRemoteSave === 'true') submit();
        });

        return { load, submit, element };
    });
}

function bindHoverOpenSurface({
    element,
    trigger,
    panel,
    listen,
    open,
    close,
}) {
    if (!(element instanceof HTMLElement) || element.dataset.wOpenOn !== 'hover') {
        return false;
    }
    let hoverCloseTimer = 0;
    const clearHoverClose = () => {
        window.clearTimeout(hoverCloseTimer);
        hoverCloseTimer = 0;
    };
    const scheduleHoverClose = () => {
        clearHoverClose();
        hoverCloseTimer = window.setTimeout(() => {
            hoverCloseTimer = 0;
            close();
        }, 160);
    };
    listen(trigger, 'pointerenter', (event) => {
        if (event.pointerType === 'touch') return;
        clearHoverClose();
        open();
    });
    listen(trigger, 'pointerleave', (event) => {
        if (event.pointerType === 'touch') return;
        scheduleHoverClose();
    });
    listen(panel, 'pointerenter', (event) => {
        if (event.pointerType === 'touch') return;
        clearHoverClose();
    });
    listen(panel, 'pointerleave', (event) => {
        if (event.pointerType === 'touch') return;
        scheduleHoverClose();
    });
    return {
        clear: clearHoverClose,
        destroy: clearHoverClose,
    };
}

function registerMenu() {
    define('menu', ({ element, listen, emit: emitLocal }) => {
        const trigger = element.querySelector('[data-w-menu-trigger]');
        const panel = element.querySelector('[data-w-menu-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) return {};
        let pointerReference = null;
        const portal = createFloatingPortal(panel, 'menu');
        const items = () => [...panel.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])')]
            .filter((item) => item.closest('[data-w-menu-panel]') === panel);
        const placement = () => element.dataset.wPlacement || 'bottom-start';
        const anchorMode = () => element.dataset.wAnchorMode === 'pointer' ? 'pointer' : 'element';
        const close = (restore = true, reason = '', force = false) => {
            if (panel.hidden || (!force && !emitLocal('before-close', { reason }))) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            panel.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            monitor.unobserve(panel);
            monitor.reset();
            clearFloatingPosition(panel);
            portal.restore();
            pointerReference = null;
            if (restore) trigger.focus({ preventScroll: true });
            emitLocal('close', { reason }, false);
            return true;
        };
        const monitor = createFloatingMonitor(
            trigger,
            () => panel,
            placement,
            () => close(false, 'anchor-hidden', true),
        );
        const open = (focus = true, reference = null) => {
            if (!panel.hidden || !emitLocal('before-open')) return false;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            panel.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            monitor.observe(panel);
            const stableReference = reference || captureFloatingReference(trigger, null, anchorMode());
            if (monitor.place(stableReference)?.anchorVisible === false) {
                close(false, 'anchor-hidden', true);
                return false;
            }
            if (focus) queueMicrotask(() => items()[0]?.focus());
            emitLocal('open', {}, false);
            return true;
        };
        listen(trigger, 'pointerdown', (event) => {
            if (anchorMode() !== 'pointer' || !event.isPrimary || event.button !== 0) return;
            pointerReference = captureFloatingReference(trigger, event, anchorMode());
        });
        const hoverBound = bindHoverOpenSurface({
            element,
            trigger,
            panel,
            listen,
            open: () => open(false, captureFloatingReference(trigger)),
            close: () => close(false, 'hover-leave'),
        });
        listen(trigger, 'click', (event) => {
            // Hover menus keep click for keyboard/touch toggle; mouse hover already opened.
            if (hoverBound && event.pointerType === 'mouse' && event.detail > 0) {
                return;
            }
            if (!panel.hidden) {
                close(false, 'trigger');
                return;
            }
            const recentPointer = pointerReference
                && performance.now() - pointerReference.capturedAt < 1200
                ? pointerReference
                : captureFloatingReference(trigger, event.detail > 0 ? event : null, anchorMode());
            pointerReference = null;
            open(event.detail === 0, recentPointer);
        });
        listen(trigger, 'keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden && portal.isTopmost()) {
                event.preventDefault();
                event.stopPropagation();
                pointerReference = null;
                close(true, 'escape');
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                pointerReference = null;
                open(true, captureFloatingReference(trigger));
            }
        });
        listen(panel, 'keydown', (event) => {
            if (event.key === 'Escape' && portal.isTopmost()) {
                event.preventDefault();
                event.stopPropagation();
                close(true, 'escape');
                return;
            }
            if (event.key === 'Tab') { close(false, 'tab'); return; }
            const menuItems = items();
            if (menuItems.length === 0) return;
            const index = menuItems.indexOf(document.activeElement);
            if (event.key === 'Home') { event.preventDefault(); menuItems[0]?.focus(); }
            if (event.key === 'End') { event.preventDefault(); menuItems.at(-1)?.focus(); }
            if (event.key === 'ArrowDown') { event.preventDefault(); menuItems[(index + 1) % menuItems.length]?.focus(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); menuItems[(index - 1 + menuItems.length) % menuItems.length]?.focus(); }
        });
        listen(panel, 'click', (event) => {
            if (eventClosest(event, '[data-w-menu-close]')) {
                close(true, 'dismiss');
                return;
            }
            if (eventClosest(event, '[role="menuitem"]')) close(false, 'select');
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close(false, 'outside');
        });
        // A menu is transient UI. Browser history/reload restoration must never
        // resurrect a panel merely because its DOM hidden property was mutated
        // before navigation. Initial-open is an explicit opt-in contract.
        const initialOpen = element.dataset.wInitialOpen === 'true';
        panel.hidden = true;
        panel.dataset.state = 'closed';
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        if (initialOpen) queueMicrotask(() => open(false, captureFloatingReference(trigger)));
        return {
            open,
            close,
            element,
            destroy: () => {
                hoverBound?.destroy?.();
                close(false, 'unmount', true);
                monitor.destroy();
                clearFloatingPosition(panel);
                portal.destroy();
            },
        };
    });
}

function registerTabs() {
    define('tabs', ({ element, listen, emit: emitLocal }) => {
        const tabs = [...element.querySelectorAll('[role="tab"]')];
        const select = (tab, focus = false) => {
            if (!tabs.includes(tab)) return;
            for (const current of tabs) {
                const selected = current === tab;
                current.setAttribute('aria-selected', String(selected));
                current.tabIndex = selected ? 0 : -1;
                const panel = document.getElementById(current.getAttribute('aria-controls') || '');
                if (panel) panel.hidden = !selected;
            }
            if (focus) tab.focus();
            emitLocal('change', { tab }, false);
        };
        tabs.forEach((tab) => {
            listen(tab, 'click', () => select(tab));
            listen(tab, 'keydown', (event) => {
                const index = tabs.indexOf(tab);
                if (event.key === 'ArrowRight') { event.preventDefault(); select(tabs[(index + 1) % tabs.length], true); }
                if (event.key === 'ArrowLeft') { event.preventDefault(); select(tabs[(index - 1 + tabs.length) % tabs.length], true); }
                if (event.key === 'Home') { event.preventDefault(); select(tabs[0], true); }
                if (event.key === 'End') { event.preventDefault(); select(tabs.at(-1), true); }
            });
        });
        select(tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0]);
        return { select, element };
    });
}

function registerDisclosure() {
    define('disclosure', ({ element, listen, emit: emitLocal }) => {
        const trigger = element.querySelector('[data-w-disclosure-trigger]');
        const panel = element.querySelector('[data-w-disclosure-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) return {};
        const setOpen = (open) => {
            const phase = open ? 'before-open' : 'before-close';
            if (!emitLocal(phase)) return false;
            trigger.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
            element.dataset.state = open ? 'open' : 'closed';
            emitLocal(open ? 'open' : 'close', {}, false);
            return true;
        };
        const ariaState = trigger.getAttribute('aria-expanded');
        const initialOpen = ariaState === null ? !panel.hidden : ariaState === 'true';
        trigger.setAttribute('aria-expanded', String(initialOpen));
        panel.hidden = !initialOpen;
        element.dataset.state = initialOpen ? 'open' : 'closed';
        listen(trigger, 'click', () => {
            const action = trigger.getAttribute('data-w-action') || '';
            if (['disclosure.open', 'disclosure.close', 'disclosure.toggle'].includes(action)) return;
            setOpen(panel.hidden);
        });
        return { open: () => setOpen(true), close: () => setOpen(false), toggle: () => setOpen(panel.hidden), element };
    });
}

function registerLoading() {
    define('loading', ({ element, emit: emitLocal }) => {
        const show = (message = '') => {
            if (!emitLocal('before-open', { message })) return false;
            const messageElement = element.querySelector('[data-w-loading-message]');
            if (messageElement instanceof HTMLElement && message !== '') {
                messageElement.textContent = String(message);
            }
            element.hidden = false;
            element.dataset.state = 'open';
            element.setAttribute('aria-hidden', 'false');
            emitLocal('open', { message }, false);
            return true;
        };
        const hide = (reason = '') => {
            if (element.hidden || !emitLocal('before-close', { reason })) return false;
            element.hidden = true;
            element.dataset.state = 'closed';
            element.setAttribute('aria-hidden', 'true');
            emitLocal('close', { reason }, false);
            return true;
        };
        element.hidden = element.dataset.state !== 'open';
        element.setAttribute('aria-hidden', String(element.hidden));
        return { show, hide, element };
    });
}

function registerNavFilter() {
    define('nav-filter', ({ element, listen, emit: emitLocal }) => {
        const input = element.querySelector('[data-w-nav-filter-input]');
        const list = element.querySelector('[data-w-nav-filter-list]');
        const empty = element.querySelector('[data-w-nav-filter-empty]');
        if (!(input instanceof HTMLInputElement) || !(list instanceof HTMLElement)) return {};
        const entries = [...list.querySelectorAll(':scope > .w-backend-nav__entry')];
        const groups = [...list.querySelectorAll(':scope > .w-backend-nav__group')];
        const backendTokenPattern = /^[A-Za-z0-9_-]{16,}$/;
        const actionAliases = new Set([
            'index', 'edit', 'new', 'create', 'add', 'form', 'view', 'detail',
            'show', 'update', 'save', 'delete', 'remove', 'get', 'post',
        ]);
        const routeSegments = (rawUrl) => {
            try {
                const url = new URL(rawUrl, window.location.href);
                if (
                    (url.protocol !== 'http:' && url.protocol !== 'https:')
                    || url.origin !== window.location.origin
                ) {
                    return null;
                }
                const segments = url.pathname
                    .split('/')
                    .filter(Boolean)
                    .map((segment) => {
                        try {
                            return decodeURIComponent(segment);
                        } catch {
                            return segment;
                        }
                    });
                if (segments.length > 0 && backendTokenPattern.test(segments[0])) {
                    segments.shift();
                }
                return segments;
            } catch {
                return null;
            }
        };
        const pathMatchScore = (currentSegments, menuSegments) => {
            const exact = currentSegments.length === menuSegments.length
                && menuSegments.every((segment, index) => segment === currentSegments[index]);
            if (exact) return Number.MAX_SAFE_INTEGER;
            if (menuSegments.length === 0 || menuSegments.length > currentSegments.length) return -1;

            let matched = 0;
            while (
                matched < menuSegments.length
                && menuSegments[matched] === currentSegments[matched]
            ) {
                matched++;
            }
            if (matched === menuSegments.length) return matched;

            if (
                menuSegments.length === currentSegments.length
                && matched === menuSegments.length - 1
            ) {
                const menuAction = String(menuSegments.at(-1) || '').toLocaleLowerCase();
                const currentAction = String(currentSegments.at(-1) || '').toLocaleLowerCase();
                if (actionAliases.has(menuAction) && actionAliases.has(currentAction)) {
                    return matched;
                }
            }
            return -1;
        };
        const syncCurrentRoute = () => {
            const currentSegments = routeSegments(window.location.href);
            const menuItems = [...list.querySelectorAll('.w-backend-nav__item')];
            const disclosures = [...list.querySelectorAll('details.w-backend-nav__disclosure')];
            menuItems.forEach((item) => {
                item.removeAttribute('aria-current');
                item.removeAttribute('data-state');
            });

            let current = null;
            let bestScore = -1;
            if (currentSegments) {
                const canonicalLinks = menuItems.filter((item) =>
                    item instanceof HTMLAnchorElement
                    && item.hasAttribute('href')
                    && !item.closest('[data-menu-source-ref]')
                );
                canonicalLinks.forEach((link) => {
                    const menuSegments = routeSegments(link.getAttribute('href'));
                    if (!menuSegments) return;
                    const score = pathMatchScore(currentSegments, menuSegments);
                    if (score > bestScore) {
                        current = link;
                        bestScore = score;
                    }
                });
            }

            const currentDisclosures = new Set();
            if (current && bestScore >= 0) {
                current.setAttribute('aria-current', 'page');
                current.setAttribute('data-state', 'active');
                let node = current.parentElement;
                while (node && node !== list) {
                    if (node instanceof HTMLDetailsElement) currentDisclosures.add(node);
                    node = node.parentElement;
                }
            }
            disclosures.forEach((disclosure) => {
                disclosure.open = currentDisclosures.has(disclosure);
            });
            return current;
        };
        const scrollCurrentIntoView = () => {
            const current = list.querySelector('.w-backend-nav__item[aria-current="page"]');
            if (!(current instanceof HTMLElement)) return;
            let node = current.parentElement;
            while (node && node !== element) {
                if (node instanceof HTMLDetailsElement) node.open = true;
                node = node.parentElement;
            }
            const scroller = element.querySelector(':scope > nav')
                || element.closest('.w-backend-sidebar')
                || element.closest('[data-w-component~="drawer"]')
                || element;
            if (!(scroller instanceof HTMLElement)) return;
            const scrollerRect = scroller.getBoundingClientRect();
            const currentRect = current.getBoundingClientRect();
            if (scrollerRect.height <= 0 || currentRect.height <= 0) return;
            const delta = (currentRect.top + currentRect.height / 2)
                - (scrollerRect.top + scroller.clientHeight / 2);
            if (Math.abs(delta) > 8) {
                scroller.scrollTop += delta;
            }
        };
        const scheduleScrollCurrentIntoView = () => {
            requestAnimationFrame(() => {
                requestAnimationFrame(scrollCurrentIntoView);
            });
        };
        const syncCurrentRouteAndScroll = () => {
            syncCurrentRoute();
            scheduleScrollCurrentIntoView();
        };
        const apply = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let visible = 0;
            entries.forEach((entry) => {
                const match = query === '' || (entry.textContent || '').toLocaleLowerCase().includes(query);
                entry.hidden = !match;
                if (match) {
                    visible++;
                    if (query !== '') entry.querySelector('details')?.setAttribute('open', '');
                }
            });
            groups.forEach((group) => {
                let sibling = group.nextElementSibling;
                let hasVisible = false;
                while (sibling && !sibling.classList.contains('w-backend-nav__group')) {
                    if (sibling.classList.contains('w-backend-nav__entry') && !sibling.hidden) hasVisible = true;
                    sibling = sibling.nextElementSibling;
                }
                group.hidden = !hasVisible;
            });
            if (empty) empty.hidden = visible !== 0;
            emitLocal('change', { query, visible }, false);
        };
        listen(input, 'input', apply);
        listen(input, 'keydown', (event) => {
            if (event.key === 'Escape' && input.value !== '') {
                input.value = '';
                apply();
            }
        });
        apply();
        syncCurrentRoute();
        const drawer = element.closest('[data-w-component~="drawer"]');
        if (drawer) {
            listen(drawer, 'weline:ui:drawer:open', syncCurrentRouteAndScroll);
        }
        listen(window, 'pageshow', syncCurrentRouteAndScroll);
        listen(window, 'popstate', syncCurrentRouteAndScroll);
        scheduleScrollCurrentIntoView();
        return { apply, syncCurrentRoute, scrollCurrentIntoView, element };
    });
}

function registerAnchoredFloat() {
    /**
     * Base floating surface: tooltip-like flip/shift against an anchor.
     *
     * Usage:
     *   <div class="widget" data-w-component="anchored-float" data-w-placement="top-end">
     *     <div data-w-float-surface>...</div>
     *   </div>
     * or self-surface:
     *   <div class="toolbar" data-w-component="anchored-float" data-w-float-self
     *        data-w-placement="top-end" data-w-portal="0">...</div>
     */
    define('anchored-float', ({ element, listen, emit: emitLocal }) => {
        const selfSurface = element.hasAttribute('data-w-float-self')
            || element.dataset.wFloatSelf === '1'
            || element.dataset.wFloatSelf === 'true';
        const surface = selfSurface
            ? element
            : (element.querySelector('[data-w-float-surface]') || element);
        if (!(surface instanceof HTMLElement)) return {};

        const resolveAnchor = () => {
            const selector = element.dataset.wFloatAnchor || surface.dataset.wFloatAnchor || '';
            if (selector) {
                return element.closest(selector)
                    || surface.closest(selector)
                    || resolveFloatingDocument(element).querySelector(selector);
            }
            if (surface === element) return element.parentElement;
            return element;
        };

        const placement = () => (
            surface.dataset.wPlacement
            || element.dataset.wPlacement
            || 'top-end'
        );
        const usePortal = !(
            element.dataset.wPortal === '0'
            || element.dataset.wPortal === 'false'
            || surface.dataset.wPortal === '0'
            || surface.dataset.wPortal === 'false'
        );

        let portal = null;
        let active = false;
        let syncFrame = 0;
        const monitor = createFloatingMonitor(
            resolveAnchor() || element,
            () => surface,
            placement,
            () => hide('anchor-hidden', true),
        );

        const isSurfaceVisible = () => {
            if (!(surface instanceof HTMLElement) || !surface.isConnected) return false;
            if (surface.hidden) return false;
            const style = getComputedStyle(surface);
            if (style.display === 'none' || style.visibility === 'hidden') return false;
            return true;
        };

        const refreshMonitorAnchor = () => {
            const nextAnchor = resolveAnchor();
            if (!(nextAnchor instanceof Element)) return null;
            // Recreate monitor binding by observing with current place target; place() reads live rect.
            return nextAnchor;
        };

        const place = () => {
            const anchor = refreshMonitorAnchor();
            if (!(anchor instanceof Element) || !isSurfaceVisible()) return null;
            if (usePortal) {
                portal ||= createFloatingPortal(surface, 'anchored-float');
                portal.mount();
            } else if (!surface.hasAttribute('data-w-floating-portal')) {
                // Enable foundation fixed-position CSS without moving the node.
                surface.dataset.wFloatingPortal = 'local';
            }
            monitor.observe(surface);
            return monitor.place(captureFloatingReference(anchor));
        };

        const hide = (reason = '', force = false) => {
            if (!active && !surface.dataset.wFloatingPositioned) return false;
            if (!force && !emitLocal('before-close', { reason })) return false;
            active = false;
            monitor.unobserve(surface);
            monitor.reset();
            clearFloatingPosition(surface);
            if (surface.dataset.wFloatingPortal === 'local') {
                delete surface.dataset.wFloatingPortal;
            }
            portal?.restore?.();
            emitLocal('close', { reason }, false);
            return true;
        };

        const show = (force = false) => {
            if (!isSurfaceVisible() && !force) return false;
            if (!emitLocal('before-open') && !force) return false;
            active = true;
            const result = place();
            if (result?.anchorVisible === false) {
                hide('anchor-hidden', true);
                return false;
            }
            emitLocal('open', {}, false);
            return true;
        };

        const sync = () => {
            const win = resolveFloatingWindow(surface);
            (win.cancelAnimationFrame || cancelAnimationFrame)(syncFrame);
            syncFrame = (win.requestAnimationFrame || requestAnimationFrame)(() => {
                if (isSurfaceVisible()) {
                    if (!active) show(true);
                    else place();
                    return;
                }
                if (active || surface.dataset.wFloatingPositioned) hide('hidden', true);
            });
        };

        const mutationRoot = surface === element ? element.parentElement : element;
        const visibilityObserver = typeof MutationObserver === 'function'
            ? new MutationObserver(sync)
            : null;
        visibilityObserver?.observe(element, {
            attributes: true,
            attributeFilter: ['class', 'style', 'hidden', 'data-state'],
        });
        if (mutationRoot && mutationRoot !== element) {
            visibilityObserver?.observe(mutationRoot, {
                attributes: true,
                attributeFilter: ['class', 'style', 'hidden', 'data-state'],
            });
        }

        listen(element, 'weline:anchored-float:place', () => {
            place();
        });
        listen(element, 'weline:anchored-float:show', () => {
            show(true);
        });
        listen(element, 'weline:anchored-float:hide', () => {
            hide('api', true);
        });

        // Initial sync after CSS (e.g. :hover / .show-actions) may already expose the surface.
        queueMicrotask(sync);

        return {
            show: () => show(true),
            hide: () => hide('api', true),
            place,
            sync,
            element,
            surface,
            destroy: () => {
                const win = resolveFloatingWindow(surface);
                (win.cancelAnimationFrame || cancelAnimationFrame)(syncFrame);
                visibilityObserver?.disconnect();
                hide('unmount', true);
                monitor.destroy();
                portal?.destroy?.();
                portal = null;
            },
        };
    });
}

function registerTooltip() {
    define('tooltip', ({ element, listen, emit: emitLocal }) => {
        const content = element.getAttribute('data-w-tooltip') || element.getAttribute('aria-label') || '';
        if (!content) return {};
        let tooltip = null;
        let portal = null;
        const placement = () => element.dataset.wPlacement || 'bottom-start';
        const hide = (reason = '', force = false) => {
            if (!tooltip || (!force && !emitLocal('before-close', { reason }))) return false;
            monitor.unobserve(tooltip);
            clearFloatingPosition(tooltip);
            portal?.destroy();
            portal = null;
            tooltip.remove();
            tooltip = null;
            monitor.reset();
            element.removeAttribute('aria-describedby');
            emitLocal('close', { reason }, false);
            return true;
        };
        const monitor = createFloatingMonitor(
            element,
            () => tooltip,
            placement,
            () => hide('anchor-hidden', true),
        );
        const show = () => {
            if (tooltip || !emitLocal('before-open')) return false;
            tooltip = document.createElement('div');
            tooltip.className = 'w-tooltip';
            tooltip.role = 'tooltip';
            tooltip.textContent = content;
            tooltip.id = `w-tooltip-${crypto.randomUUID?.() || Date.now()}`;
            // Anchor near the trigger so portal host resolution can find open dialogs.
            element.after(tooltip);
            portal = createFloatingPortal(tooltip, 'tooltip');
            portal.mount();
            element.setAttribute('aria-describedby', tooltip.id);
            monitor.observe(tooltip);
            if (monitor.place(captureFloatingReference(element))?.anchorVisible === false) {
                hide('anchor-hidden', true);
                return false;
            }
            emitLocal('open', {}, false);
            return true;
        };
        listen(element, 'pointerenter', show);
        listen(element, 'pointerleave', () => hide('pointerleave'));
        listen(element, 'focus', show);
        listen(element, 'blur', () => hide('blur'));
        return {
            show,
            hide,
            element,
            destroy: () => {
                monitor.destroy();
                portal?.destroy();
                portal = null;
                tooltip?.remove();
                tooltip = null;
                element.removeAttribute('aria-describedby');
            },
        };
    });
}

function registerPopover() {
    define('popover', ({ element, listen, emit: emitLocal }) => {
        const trigger = element.querySelector('[data-w-popover-trigger]');
        const panel = element.querySelector('[data-w-popover-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) return {};
        let pointerReference = null;
        const portal = createFloatingPortal(panel, 'popover');
        const placement = () => element.dataset.wPlacement || 'bottom-start';
        const anchorMode = () => element.dataset.wAnchorMode === 'pointer' ? 'pointer' : 'element';
        const close = (reason = '', force = false) => {
            if (panel.hidden || (!force && !emitLocal('before-close', { reason }))) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            panel.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            monitor.unobserve(panel);
            monitor.reset();
            clearFloatingPosition(panel);
            portal.restore();
            pointerReference = null;
            if (reason === 'dismiss' || reason === 'escape') trigger.focus({ preventScroll: true });
            emitLocal('close', { reason }, false);
            return true;
        };
        const monitor = createFloatingMonitor(
            trigger,
            () => panel,
            placement,
            () => close('anchor-hidden', true),
        );
        const open = (reference = null, focus = false) => {
            if (!panel.hidden || !emitLocal('before-open')) return false;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            panel.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            monitor.observe(panel);
            if (monitor.place(reference || captureFloatingReference(trigger, null, anchorMode()))?.anchorVisible === false) {
                close('anchor-hidden', true);
                return false;
            }
            if ((focus || panel.getAttribute('role') === 'dialog') && panel.dataset.drawerFlyout !== '1') {
                queueMicrotask(() => {
                    const first = getFocusable(panel)[0];
                    if (first instanceof HTMLElement) {
                        first.focus({ preventScroll: true });
                        return;
                    }
                    if (!panel.hasAttribute('tabindex')) panel.tabIndex = -1;
                    panel.focus({ preventScroll: true });
                });
            }
            emitLocal('open', {}, false);
            return true;
        };
        listen(trigger, 'pointerdown', (event) => {
            if (!event.isPrimary || event.button !== 0) return;
            pointerReference = captureFloatingReference(trigger, event, anchorMode());
        });
        const hoverBound = bindHoverOpenSurface({
            element,
            trigger,
            panel,
            listen,
            open: () => open(captureFloatingReference(trigger), false),
            close: () => close('hover-leave'),
        });
        listen(trigger, 'click', (event) => {
            if (hoverBound && event.pointerType === 'mouse' && event.detail > 0) {
                // Allow <a> navigation / keep hover-open panel.
                return;
            }
            if (!panel.hidden) {
                close('trigger');
                return;
            }
            const recentPointer = pointerReference
                && performance.now() - pointerReference.capturedAt < 1200
                ? pointerReference
                : captureFloatingReference(trigger, event.detail > 0 ? event : null, anchorMode());
            pointerReference = null;
            open(recentPointer, event.detail === 0);
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close('outside');
        });
        listen(panel, 'click', (event) => {
            if (eventClosest(event, '[data-w-popover-close]')) close('dismiss');
        });
        const dismissOnEscape = (event, immediate = false) => {
            if (panel.hidden || event.key !== 'Escape' || event.defaultPrevented || !portal.isTopmost()) return;
            event.preventDefault();
            if (immediate) event.stopImmediatePropagation();
            else event.stopPropagation();
            close('escape');
        };
        listen(trigger, 'keydown', (event) => dismissOnEscape(event));
        listen(panel, 'keydown', (event) => dismissOnEscape(event));
        listen(document, 'keydown', (event) => dismissOnEscape(event, true));
        panel.hidden = true;
        panel.dataset.state = 'closed';
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        return {
            open,
            close,
            element,
            destroy: () => {
                hoverBound?.destroy?.();
                close('unmount', true);
                monitor.destroy();
                clearFloatingPosition(panel);
                portal.destroy();
            },
        };
    });
}

function resolveToastHost() {
    const modal = document.querySelector('dialog:modal');
    if (modal instanceof HTMLDialogElement) {
        return modal;
    }
    // Some shells expose open dialogs before :modal matches on the same tick.
    const openDialogs = document.querySelectorAll('dialog[open]');
    for (let index = openDialogs.length - 1; index >= 0; index -= 1) {
        const dialog = openDialogs[index];
        if (dialog instanceof HTMLDialogElement && dialog.matches(':modal')) {
            return dialog;
        }
    }
    return document.body;
}

function detachToastRegionPopover(region) {
    if (!(region instanceof HTMLElement)) {
        return;
    }
    if (typeof region.hidePopover === 'function') {
        try {
            if (region.matches(':popover-open')) {
                region.hidePopover();
            }
        } catch (_error) {
            // Ignore InvalidStateError when already closed.
        }
    }
    region.removeAttribute('popover');
}

function ensureToastRegion() {
    const host = resolveToastHost();
    const supportsPopover = typeof HTMLElement !== 'undefined'
        && typeof HTMLElement.prototype.showPopover === 'function';
    // Prefer hosting inside the open modal dialog so toast shares its top-layer
    // entry (plain z-index and even popover cannot reliably paint above
    // showModal() in all Chromium/Electron shells). When no modal is open,
    // promote the body-hosted region with Popover API for overlay stacking.
    const usePopover = supportsPopover && host === document.body;

    if (toastRegion?.isConnected) {
        const needsMove = toastRegion.parentElement !== host;
        const needsPopover = usePopover && toastRegion.getAttribute('popover') !== 'manual';
        const needsPlain = !usePopover && toastRegion.hasAttribute('popover');
        if (needsMove || needsPopover || needsPlain) {
            detachToastRegionPopover(toastRegion);
            if (usePopover) {
                toastRegion.setAttribute('popover', 'manual');
            }
            host.append(toastRegion);
        } else if (host instanceof HTMLDialogElement) {
            // Re-append as last child so successive toasts stay above dialog chrome.
            host.append(toastRegion);
        }
        syncToastRegionTopLayer(toastRegion);
        return toastRegion;
    }

    toastRegion = document.createElement('div');
    toastRegion.className = 'w-toast-region';
    toastRegion.setAttribute('aria-live', 'polite');
    toastRegion.setAttribute('aria-atomic', 'false');
    if (usePopover) {
        toastRegion.setAttribute('popover', 'manual');
    }
    host.append(toastRegion);
    bindToastHostMigration();
    syncToastRegionTopLayer(toastRegion);
    return toastRegion;
}

function bindToastHostMigration() {
    if (bindToastHostMigration.bound) return;
    bindToastHostMigration.bound = true;
    document.addEventListener('close', (event) => {
        if (!(event.target instanceof HTMLDialogElement)) return;
        if (!toastRegion?.isConnected || toastRegion.parentElement !== event.target) return;
        const supportsPopover = typeof HTMLElement !== 'undefined'
            && typeof HTMLElement.prototype.showPopover === 'function';
        detachToastRegionPopover(toastRegion);
        document.body.append(toastRegion);
        if (supportsPopover && toastRegion.childElementCount > 0) {
            toastRegion.setAttribute('popover', 'manual');
        }
        syncToastRegionTopLayer(toastRegion);
    }, true);
}

function syncToastRegionTopLayer(region) {
    if (!(region instanceof HTMLElement) || typeof region.showPopover !== 'function') {
        return;
    }
    if (region.getAttribute('popover') == null) {
        return;
    }
    const open = region.matches(':popover-open');
    if (region.childElementCount > 0) {
        if (!open) {
            try {
                region.showPopover();
            } catch (_error) {
                // Ignore InvalidStateError when the document is unloading.
            }
        }
        return;
    }
    if (open) {
        try {
            region.hidePopover();
        } catch (_error) {
            // Ignore InvalidStateError when already closed.
        }
    }
}

function showToast(message, options = {}) {
    const tone = ['neutral', 'success', 'warning', 'danger', 'info'].includes(options.tone) ? options.tone : 'neutral';
    const duration = Number.isFinite(options.duration) ? Math.max(0, options.duration) : 4200;
    const toast = document.createElement('section');
    toast.className = 'w-toast';
    toast.dataset.tone = tone;
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    const icon = createIcon({
        success: 'check-circle',
        warning: 'warning',
        danger: 'x-circle',
        info: 'info',
        neutral: 'bell',
    }[tone], { size: 'md' });
    icon.classList.add('w-toast__icon');
    const content = document.createElement('div');
    content.className = 'w-toast__content';
    if (options.title) {
        const title = document.createElement('strong');
        title.textContent = String(options.title);
        content.append(title);
    }
    if (message instanceof Node) content.append(message);
    else {
        const copy = document.createElement('span');
        copy.textContent = String(message ?? '');
        content.append(copy);
    }
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'w-button';
    close.dataset.tone = 'quiet';
    close.dataset.size = 'sm';
    close.setAttribute('aria-label', String(options.closeLabel || Weline.config?.i18n?.close || 'Close'));
    close.append(createIcon('close', { size: 'sm' }));
    toast.append(icon, content, close);
    const region = ensureToastRegion();
    const dismiss = () => {
        if (!toast.isConnected || !emit(toast, 'toast', 'before-close', { tone })) return false;
        emit(toast, 'toast', 'close', { tone }, false);
        toast.remove();
        syncToastRegionTopLayer(region);
        return true;
    };
    close.addEventListener('click', dismiss);
    region.append(toast);
    syncToastRegionTopLayer(region);
    emit(toast, 'toast', 'open', { tone }, false);
    if (duration > 0) setTimeout(dismiss, duration);
    return { element: toast, close: dismiss };
}

function requestDialog(options = {}) {
    const dialog = document.createElement('dialog');
    dialog.className = 'w-dialog';
    const requestedClasses = Array.isArray(options.classes)
        ? options.classes
        : String(options.classes || '').split(/\s+/);
    requestedClasses
        .filter((name) => /^w-[a-z0-9_-]+$/.test(name))
        .forEach((name) => dialog.classList.add(name));
    dialog.dataset.wComponent = 'dialog';
    dialog.dataset.size = ['sm', 'lg'].includes(options.size) ? options.size : 'sm';
    dialog.dataset.wClosable = options.dismissible === false ? 'false' : 'true';
    dialog.dataset.wBackdrop = options.dismissible === false ? 'static' : 'dismissible';
    const requestedTone = options.dangerous === true ? 'danger' : options.tone;
    dialog.dataset.tone = ['success', 'warning', 'danger', 'info'].includes(requestedTone)
        ? requestedTone
        : 'neutral';

    const header = document.createElement('header');
    header.className = 'w-dialog__header';
    const heading = document.createElement('div');
    heading.className = 'w-cluster';
    if (dialog.dataset.tone !== 'neutral') {
        const iconName = {
            success: 'check-circle',
            warning: 'warning',
            danger: 'x-circle',
            info: 'info',
        }[dialog.dataset.tone];
        heading.append(createIcon(iconName, { size: 'md' }));
    }
    const title = document.createElement('h2');
    title.className = 'w-dialog__title';
    title.textContent = String(options.title || Weline.config?.i18n?.confirmTitle || 'Confirm');
    heading.append(title);
    header.append(heading);

    const body = document.createElement('div');
    body.className = 'w-dialog__body w-stack';
    const message = document.createElement('div');
    if (options.message instanceof Node) message.append(options.message);
    else message.textContent = String(options.message ?? '');
    body.append(message);

    let field = null;
    let fieldError = null;
    if (options.field && typeof options.field === 'object') {
        const fieldConfig = options.field;
        const fieldRoot = document.createElement('label');
        fieldRoot.className = 'w-field';
        if (fieldConfig.label) {
            const label = document.createElement('span');
            label.className = 'w-field__label';
            label.textContent = String(fieldConfig.label);
            fieldRoot.append(label);
        }
        if (fieldConfig.type === 'select') {
            field = document.createElement('select');
            field.className = 'w-select';
            if (fieldConfig.placeholder) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = String(fieldConfig.placeholder);
                placeholder.disabled = true;
                field.append(placeholder);
            }
            const choices = fieldConfig.choices instanceof Map
                ? [...fieldConfig.choices.entries()]
                : Array.isArray(fieldConfig.choices)
                    ? fieldConfig.choices
                    : Object.entries(fieldConfig.choices || {});
            for (const choice of choices) {
                const pair = Array.isArray(choice) ? choice : [choice, choice];
                const option = document.createElement('option');
                option.value = String(pair[0] ?? '');
                option.textContent = String(pair[1] ?? pair[0] ?? '');
                field.append(option);
            }
        } else if (fieldConfig.type === 'textarea') {
            field = document.createElement('textarea');
            field.className = 'w-textarea';
        } else {
            field = document.createElement('input');
            field.className = 'w-input';
            field.type = ['email', 'number', 'password', 'search', 'tel', 'text', 'url'].includes(fieldConfig.type)
                ? fieldConfig.type
                : 'text';
        }
        field.value = String(fieldConfig.value ?? '');
        field.required = fieldConfig.required === true;
        if (fieldConfig.placeholder) field.placeholder = String(fieldConfig.placeholder);
        field.autocomplete = String(fieldConfig.autocomplete || 'off');
        fieldError = document.createElement('span');
        fieldError.className = 'w-field__error';
        fieldError.hidden = true;
        fieldRoot.append(field, fieldError);
        body.append(fieldRoot);
    }

    const footer = document.createElement('footer');
    footer.className = 'w-dialog__footer';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'w-button';
    cancel.dataset.tone = ['danger', 'success', 'warning', 'info'].includes(options.cancelTone)
        ? options.cancelTone
        : 'neutral';
    cancel.textContent = String(options.cancelLabel || Weline.config?.i18n?.cancel || 'Cancel');
    cancel.hidden = options.cancelable !== true;
    const accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'w-button';
    const confirmTone = options.confirmTone || dialog.dataset.tone;
    accept.dataset.tone = ['danger', 'success', 'warning', 'info'].includes(confirmTone)
        ? confirmTone
        : 'primary';
    accept.textContent = String(options.confirmLabel || Weline.config?.i18n?.confirm || 'Confirm');
    accept.autofocus = true;
    accept.hidden = options.confirmable === false;
    if (options.reverseActions === true) footer.append(accept, cancel);
    else footer.append(cancel, accept);
    dialog.append(header, body, footer);
    document.body.append(dialog);
    mount(dialog);

    return new Promise((resolve) => {
        let settled = false;
        let acceptedValue = field?.value ?? true;
        const finish = (confirmed) => {
            if (settled) return;
            settled = true;
            resolve({
                confirmed,
                cancelled: !confirmed,
                value: confirmed ? acceptedValue : null,
            });
            queueMicrotask(() => {
                unmount(dialog);
                dialog.remove();
            });
        };
        cancel.addEventListener('click', () => UI.dialog.close(dialog, 'cancel'), { once: true });
        accept.addEventListener('click', async () => {
            if (field && !field.reportValidity()) return;
            const value = field?.value ?? true;
            if (typeof options.validate === 'function') {
                const validation = await options.validate(value);
                if (validation !== true && validation != null && validation !== '') {
                    fieldError.textContent = String(validation);
                    fieldError.hidden = false;
                    field?.setAttribute('aria-invalid', 'true');
                    return;
                }
            }
            accept.disabled = true;
            cancel.disabled = true;
            try {
                acceptedValue = typeof options.beforeConfirm === 'function'
                    ? await options.beforeConfirm(value)
                    : value;
                if (acceptedValue === false) return;
                UI.dialog.close(dialog, 'confirm');
            } catch (error) {
                const errorMessage = error instanceof Error ? error.message : String(error);
                if (fieldError) {
                    fieldError.textContent = errorMessage;
                    fieldError.hidden = false;
                    field?.setAttribute('aria-invalid', 'true');
                } else {
                    UI.toast.error(errorMessage);
                }
            } finally {
                if (dialog.open) {
                    accept.disabled = false;
                    cancel.disabled = false;
                }
            }
        });
        dialog.addEventListener('close', () => finish(dialog.returnValue === 'confirm'), { once: true });
        if (!UI.dialog.open(dialog)) finish(false);
        if (Number.isFinite(options.duration) && options.duration > 0) {
            setTimeout(() => UI.dialog.close(dialog, 'timeout'), options.duration);
        }
    });
}

async function confirmDialog(message, options = {}) {
    const result = await requestDialog({ ...options, message, cancelable: true });
    return result.confirmed;
}

async function alertDialog(message, options = {}) {
    await requestDialog({ ...options, message, cancelable: false });
}

function promptDialog(message, options = {}) {
    return requestDialog({
        ...options,
        message,
        cancelable: true,
        field: options.field || { type: 'text' },
    });
}

function performAction(actionElement) {
    const action = actionElement.getAttribute('data-w-action') || '';
    const target = actionElement.getAttribute('data-w-target') || '';
    const [component, method] = action.split('.');
    if (component === 'dialog' && ['open', 'close'].includes(method)) UI.dialog[method](target || actionElement.closest('.w-dialog'));
    if (component === 'drawer' && ['open', 'close'].includes(method)) UI.drawer[method](target || actionElement.closest('.w-drawer'));
    if (component === 'disclosure' && ['open', 'close', 'toggle'].includes(method)) {
        const panel = asElement(target);
        const root = panel?.closest('[data-w-component~="disclosure"]') || actionElement.closest('[data-w-component~="disclosure"]');
        ensureMounted(root, 'disclosure')?.[method]?.();
    }
    if (component === 'toast' && method === 'show') UI.toast.show(actionElement.getAttribute('data-w-message') || '', { tone: actionElement.getAttribute('data-tone') || 'neutral' });
    if (component === 'element' && method === 'remove') {
        (asElement(target) || actionElement.closest('[data-w-removable]'))?.remove();
    }
    if (component === 'page' && method === 'print') window.print();
}

if (!existingRuntime) {
    registerDialog();
    registerDrawer();
    registerRemoteDrawer();
    registerMenu();
    registerTabs();
    registerDisclosure();
    registerLoading();
    registerNavFilter();
    registerTooltip();
    registerPopover();
    registerAnchoredFloat();
}

const createdUI = {
    __version: '2.0.0',
    __runtimeId: runtimeId,
    define,
    mount,
    unmount,
    get,
    position: positionFloating,
    floating: {
        capture: captureFloatingReference,
        monitor: createFloatingMonitor,
        clear: clearFloatingPosition,
        portal: createFloatingPortal,
        viewport: floatingViewport,
        syncViewport: syncFloatingViewportCssBounds,
        /** Mount/ensure anchored-float on a surface or host element. */
        attach(target, options = {}) {
            const element = asElement(target);
            if (!(element instanceof HTMLElement)) return null;
            if (options.placement) element.dataset.wPlacement = String(options.placement);
            if (options.portal === false) element.dataset.wPortal = '0';
            if (options.self !== false && !element.querySelector('[data-w-float-surface]')) {
                element.dataset.wFloatSelf = '1';
            }
            if (options.anchor) element.dataset.wFloatAnchor = String(options.anchor);
            if (!/\banchored-float\b/.test(element.getAttribute('data-w-component') || '')) {
                const existing = element.getAttribute('data-w-component');
                element.setAttribute(
                    'data-w-component',
                    existing ? `${existing} anchored-float` : 'anchored-float',
                );
            }
            return ensureMounted(element, 'anchored-float');
        },
    },
    stack: {
        /** Portal / dialog floatings: host z-index + 1 */
        apply: applyFloatingStackElevation,
        clear: clearFloatingStackElevation,
        /** In-place hover/open layers: scope above siblings, layer = scope + 1 */
        elevate: applyElevateLayer,
        clearElevate: clearElevateLayer,
        syncHost: syncElevateHost,
    },
    icon: {
        create: createIcon,
        ensureSprite: ensureIconSprite,
        spriteUrl: iconSpriteUrl,
    },
    dialog: {
        open(target, options) { const element = asElement(target); return ensureMounted(element, 'dialog')?.open(options) ?? false; },
        close(target, value) { const element = asElement(target); return ensureMounted(element, 'dialog')?.close(value) ?? false; },
        request: requestDialog,
        alert: alertDialog,
        confirm: confirmDialog,
        prompt: promptDialog,
    },
    drawer: {
        open(target, options) { const element = asElement(target); return ensureMounted(element, 'drawer')?.open(options) ?? false; },
        close(target, reason) { const element = asElement(target); return ensureMounted(element, 'drawer')?.close(reason) ?? false; },
    },
    toast: {
        show: showToast,
        success(message, options = {}) { return showToast(message, { ...options, tone: 'success' }); },
        warning(message, options = {}) { return showToast(message, { ...options, tone: 'warning' }); },
        error(message, options = {}) { return showToast(message, { ...options, tone: 'danger' }); },
        info(message, options = {}) { return showToast(message, { ...options, tone: 'info' }); },
    },
};
const UI = existingRuntime || createdUI;

function start() {
    initializeThemePreference();
    installElevateLayerRuntime();
    mount(document);
    observer = new MutationObserver((records) => {
        for (const record of records) {
            record.removedNodes.forEach((node) => {
                if (node instanceof Element && !node.isConnected) unmount(node);
            });
            record.addedNodes.forEach((node) => {
                if (node instanceof Element && node.isConnected) mount(node);
            });
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    document.dispatchEvent(new CustomEvent('weline:ui:ready', { detail: { version: UI.__version } }));
}

if (!existingRuntime) {
    Weline.UI = UI;
    registerIconElement();
    installFloatingViewportListeners();
    document.addEventListener('click', (event) => {
        const action = eventClosest(event, '[data-w-action]');
        if (action) performAction(action);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const overlay = topOverlay();
        if (!overlay || overlay.dataset.wClosable === 'false') return;
        const component = componentNames(overlay).find((name) => name === 'dialog' || name === 'drawer');
        if (component === 'dialog') ensureMounted(overlay, 'dialog')?.close('escape');
        if (component === 'drawer') ensureMounted(overlay, 'drawer')?.close('escape');
    });
    window.addEventListener('pagehide', () => closeTransientSurfaces('pagehide'));
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) closeTransientSurfaces('history-restore');
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => UI.mount(document), { once: true });
} else {
    UI.mount(document);
}

export { UI };
export default UI;
