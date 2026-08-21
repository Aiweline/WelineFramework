const globalObject = window;
const Weline = globalObject.Weline = globalObject.Weline || {};
const definitions = new Map();
const instances = new WeakMap();
const cleanupByElement = new WeakMap();
const overlayStack = [];
const lazyComponentSources = new Map([
    ['combobox', './weline-ui-advanced.js'],
    ['tree', './weline-ui-advanced.js'],
    ['transfer-list', './weline-ui-advanced.js'],
    ['icon-picker', './weline-ui-advanced.js'],
]);
const lazyComponentLoads = new Map();
let observer = null;
let toastRegion = null;
const iconSpriteUrl = new URL('./weline-icons.svg', import.meta.url).href;

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
    const semanticName = normalizeIconName(name);
    const size = ['xs', 'sm', 'md', 'lg', 'xl'].includes(options.size) ? options.size : 'md';
    const label = String(options.label || '').trim();
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    svg.classList.add('w-icon');
    svg.dataset.size = size;
    svg.dataset.icon = semanticName;
    svg.setAttribute('viewBox', '0 0 24 24');
    if (label === '') {
        svg.setAttribute('aria-hidden', 'true');
    } else {
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', label);
    }
    use.setAttribute('href', `${iconSpriteUrl}#w-icon-${semanticName}`);
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
    const normalize = (value) => ['system', 'light', 'dark'].includes(value) ? value : 'system';
    const apply = (value) => {
        const preference = normalize(String(value || root.dataset.themePreference || 'system'));
        const theme = preference === 'dark' || (preference === 'system' && media?.matches) ? 'dark' : 'light';
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

function positionFloating(anchor, floating, placement = 'bottom-start') {
    if (!(anchor instanceof Element) || !(floating instanceof HTMLElement)) return;
    const anchorRect = anchor.getBoundingClientRect();
    const floatingRect = floating.getBoundingClientRect();
    const gap = 6;
    let top = anchorRect.bottom + gap;
    let left = placement.endsWith('end') ? anchorRect.right - floatingRect.width : anchorRect.left;
    if (top + floatingRect.height > innerHeight - gap) top = Math.max(gap, anchorRect.top - floatingRect.height - gap);
    left = Math.max(gap, Math.min(left, innerWidth - floatingRect.width - gap));
    floating.style.position = 'fixed';
    floating.style.inset = 'auto';
    floating.style.top = `${Math.round(top)}px`;
    floating.style.left = `${Math.round(left)}px`;
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
        const sourceUrl = new URL(source, import.meta.url).href;
        lazyComponentLoads.set(source, import(sourceUrl).then((module) => {
            if (typeof module.register !== 'function') {
                throw new TypeError(`Weline UI lazy module does not export register(): ${source}`);
            }
            module.register(UI);
        }));
    }
    lazyComponentLoads.get(source)
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

function unmount(root) {
    const elements = [];
    if (root instanceof Element && root.matches('[data-w-component]')) elements.push(root);
    if (root instanceof Document || root instanceof DocumentFragment || root instanceof Element) {
        elements.push(...root.querySelectorAll('[data-w-component]'));
    }
    for (const element of elements.reverse()) {
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
            if (!nativeDialog && event.key === 'Escape' && element.dataset.wClosable !== 'false') close('escape');
            trapFocus(element, event);
        });
        if (nativeDialog) {
            listen(element, 'cancel', (event) => {
                event.preventDefault();
                if (element.dataset.wClosable !== 'false') close('escape');
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
            if (event.key === 'Escape' && element.dataset.wClosable !== 'false') close('escape');
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

function registerMenu() {
    define('menu', ({ element, listen, position, emit: emitLocal }) => {
        const trigger = element.querySelector('[data-w-menu-trigger]');
        const panel = element.querySelector('[data-w-menu-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) return {};
        const items = () => [...panel.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])')];
        const open = (focus = true) => {
            if (!panel.hidden || !emitLocal('before-open')) return false;
            panel.hidden = false;
            panel.dataset.state = 'open';
            trigger.setAttribute('aria-expanded', 'true');
            position(trigger, panel, element.dataset.wPlacement || 'bottom-start');
            if (focus) queueMicrotask(() => items()[0]?.focus());
            emitLocal('open', {}, false);
            return true;
        };
        const close = (restore = true) => {
            if (panel.hidden || !emitLocal('before-close')) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            trigger.setAttribute('aria-expanded', 'false');
            if (restore) trigger.focus();
            emitLocal('close', {}, false);
            return true;
        };
        listen(trigger, 'click', () => panel.hidden ? open(false) : close(false));
        listen(trigger, 'keydown', (event) => {
            if (['ArrowDown', 'Enter', ' '].includes(event.key)) { event.preventDefault(); open(true); }
        });
        listen(panel, 'keydown', (event) => {
            const menuItems = items();
            if (menuItems.length === 0) return;
            const index = menuItems.indexOf(document.activeElement);
            if (event.key === 'Escape') { event.preventDefault(); close(true); }
            if (event.key === 'Home') { event.preventDefault(); menuItems[0]?.focus(); }
            if (event.key === 'End') { event.preventDefault(); menuItems.at(-1)?.focus(); }
            if (event.key === 'ArrowDown') { event.preventDefault(); menuItems[(index + 1) % menuItems.length]?.focus(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); menuItems[(index - 1 + menuItems.length) % menuItems.length]?.focus(); }
        });
        listen(panel, 'click', (event) => {
            if (eventClosest(event, '[role="menuitem"]')) close(false);
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target)) close(false);
        });
        listen(window, 'resize', () => { if (!panel.hidden) position(trigger, panel, element.dataset.wPlacement || 'bottom-start'); });
        return { open, close, element };
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
        listen(trigger, 'click', () => setOpen(panel.hidden));
        return { open: () => setOpen(true), close: () => setOpen(false), toggle: () => setOpen(panel.hidden), element };
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
        return { apply, element };
    });
}

function registerTooltip() {
    define('tooltip', ({ element, listen, position, emit: emitLocal }) => {
        const content = element.getAttribute('data-w-tooltip') || element.getAttribute('aria-label') || '';
        if (!content) return {};
        let tooltip = null;
        const show = () => {
            if (tooltip || !emitLocal('before-open')) return false;
            tooltip = document.createElement('div');
            tooltip.className = 'w-tooltip';
            tooltip.role = 'tooltip';
            tooltip.textContent = content;
            tooltip.id = `w-tooltip-${crypto.randomUUID?.() || Date.now()}`;
            document.body.append(tooltip);
            element.setAttribute('aria-describedby', tooltip.id);
            position(element, tooltip, element.dataset.wPlacement || 'bottom-start');
            emitLocal('open', {}, false);
            return true;
        };
        const hide = () => {
            if (!tooltip || !emitLocal('before-close')) return false;
            tooltip?.remove();
            tooltip = null;
            element.removeAttribute('aria-describedby');
            emitLocal('close', {}, false);
            return true;
        };
        listen(element, 'pointerenter', show);
        listen(element, 'pointerleave', hide);
        listen(element, 'focus', show);
        listen(element, 'blur', hide);
        return { show, hide, destroy: hide, element };
    });
}

function registerPopover() {
    define('popover', ({ element, listen, position, emit: emitLocal }) => {
        const trigger = element.querySelector('[data-w-popover-trigger]');
        const panel = element.querySelector('[data-w-popover-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) return {};
        const open = () => {
            if (!emitLocal('before-open')) return false;
            panel.hidden = false;
            panel.dataset.state = 'open';
            trigger.setAttribute('aria-expanded', 'true');
            position(trigger, panel, element.dataset.wPlacement || 'bottom-start');
            emitLocal('open', {}, false);
            return true;
        };
        const close = () => {
            if (panel.hidden || !emitLocal('before-close')) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            trigger.setAttribute('aria-expanded', 'false');
            emitLocal('close', {}, false);
            return true;
        };
        listen(trigger, 'click', () => panel.hidden ? open() : close());
        listen(document, 'pointerdown', (event) => { if (!element.contains(event.target)) close(); });
        listen(element, 'keydown', (event) => { if (event.key === 'Escape') close(); });
        return { open, close, element };
    });
}

function ensureToastRegion() {
    if (toastRegion?.isConnected) return toastRegion;
    toastRegion = document.createElement('div');
    toastRegion.className = 'w-toast-region';
    toastRegion.setAttribute('aria-live', 'polite');
    toastRegion.setAttribute('aria-atomic', 'false');
    document.body.append(toastRegion);
    return toastRegion;
}

function showToast(message, options = {}) {
    const tone = ['neutral', 'success', 'warning', 'danger', 'info'].includes(options.tone) ? options.tone : 'neutral';
    const duration = Number.isFinite(options.duration) ? Math.max(0, options.duration) : 4200;
    const toast = document.createElement('section');
    toast.className = 'w-toast';
    toast.dataset.tone = tone;
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    const content = document.createElement('div');
    if (message instanceof Node) content.append(message);
    else content.textContent = String(message ?? '');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'w-button';
    close.dataset.tone = 'quiet';
    close.dataset.size = 'sm';
    close.setAttribute('aria-label', String(options.closeLabel || Weline.config?.i18n?.close || 'Close'));
    close.textContent = '×';
    toast.append(content, close);
    const dismiss = () => {
        if (!toast.isConnected || !emit(toast, 'toast', 'before-close', { tone })) return false;
        emit(toast, 'toast', 'close', { tone }, false);
        toast.remove();
        return true;
    };
    close.addEventListener('click', dismiss);
    ensureToastRegion().append(toast);
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
    if (component === 'element' && method === 'remove') asElement(target)?.remove();
    if (component === 'page' && method === 'print') window.print();
}

registerDialog();
registerDrawer();
registerRemoteDrawer();
registerMenu();
registerTabs();
registerDisclosure();
registerNavFilter();
registerTooltip();
registerPopover();

const UI = {
    __version: '2.0.0',
    define,
    mount,
    unmount,
    get,
    position: positionFloating,
    icon: {
        create: createIcon,
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

Weline.UI = UI;
registerIconElement();

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

function start() {
    initializeThemePreference();
    mount(document);
    observer = new MutationObserver((records) => {
        for (const record of records) {
            record.removedNodes.forEach((node) => { if (node instanceof Element) unmount(node); });
            record.addedNodes.forEach((node) => { if (node instanceof Element) mount(node); });
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    document.dispatchEvent(new CustomEvent('weline:ui:ready', { detail: { version: UI.__version } }));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();

export { UI };
export default UI;
