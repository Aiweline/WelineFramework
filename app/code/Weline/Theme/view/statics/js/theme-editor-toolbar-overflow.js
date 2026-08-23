/**
 * Theme Editor toolbar overflow.
 *
 * Keeps every toolbar action available while delegating popup positioning,
 * viewport collision handling, focus restoration and teardown to Weline.UI.
 */
(function registerThemeEditorToolbarOverflow() {
    'use strict';

    const UI = window.Weline?.UI;
    if (!UI) {
        throw new Error('Weline.UI must be loaded before Theme Editor toolbar overflow.');
    }

    const roots = () => [...document.querySelectorAll('[data-w-component~="toolbar-overflow"]')]
        .filter((element) => element instanceof HTMLElement);

    const flexGap = (element) => {
        const style = window.getComputedStyle(element);
        return Number.parseFloat(style.columnGap || style.gap) || 0;
    };

    const horizontalPadding = (element) => {
        const style = window.getComputedStyle(element);
        return (Number.parseFloat(style.paddingInlineStart || style.paddingLeft) || 0)
            + (Number.parseFloat(style.paddingInlineEnd || style.paddingRight) || 0);
    };

    function intrinsicWidth(element) {
        if (!(element instanceof HTMLElement)) return 0;
        const previous = {
            flex: element.style.flex,
            inlineSize: element.style.inlineSize,
            maxInlineSize: element.style.maxInlineSize,
            minInlineSize: element.style.minInlineSize,
        };
        element.style.flex = '0 0 auto';
        element.style.inlineSize = 'max-content';
        element.style.maxInlineSize = 'none';
        element.style.minInlineSize = '0';
        const width = Math.ceil(element.getBoundingClientRect().width || element.scrollWidth || 0);
        element.style.flex = previous.flex;
        element.style.inlineSize = previous.inlineSize;
        element.style.maxInlineSize = previous.maxInlineSize;
        element.style.minInlineSize = previous.minInlineSize;
        return width;
    }

    function availableWidth(root) {
        const parent = root.parentElement;
        if (!(parent instanceof HTMLElement)) return Math.max(96, root.clientWidth || 96);

        const parentWidth = parent.clientWidth - horizontalPadding(parent);
        const gap = flexGap(parent);
        let siblingWidth = 0;

        if (root.classList.contains('toolbar-right')) {
            const left = parent.querySelector('.toolbar-left');
            if (left instanceof HTMLElement && Math.abs(root.offsetTop - left.offsetTop) > 1) {
                return Math.max(96, Math.floor(parentWidth));
            }
            const leftNeed = Math.min(intrinsicWidth(left), Math.floor(parentWidth * 0.58));
            siblingWidth = leftNeed + gap;
        } else if (root.classList.contains('preview-actions')) {
            siblingWidth = intrinsicWidth(parent.querySelector('.preview-tabs')) + gap;
        } else {
            const siblings = [...parent.children].filter((element) => (
                element !== root && element instanceof HTMLElement && !element.hidden
            ));
            siblingWidth = siblings.reduce((sum, element) => sum + intrinsicWidth(element), 0)
                + gap * siblings.length;
        }

        return Math.max(96, Math.floor(parentWidth - siblingWidth));
    }

    function usedWidth(root, more) {
        const children = [...root.children].filter((element) => (
            element instanceof HTMLElement && !(element === more && more.hidden)
        ));
        if (children.length === 0) return 0;
        return children.reduce((sum, element) => sum + element.offsetWidth, 0)
            + flexGap(root) * Math.max(0, children.length - 1);
    }

    function scheduleAllLayouts() {
        cancelAnimationFrame(scheduleAllLayouts.frame || 0);
        scheduleAllLayouts.frame = requestAnimationFrame(() => {
            const ordered = roots().sort((left, right) => {
                const rank = (element) => {
                    if (element.classList.contains('toolbar-right')) return 0;
                    if (element.classList.contains('toolbar-selects')) return 1;
                    if (element.classList.contains('preview-actions')) return 2;
                    return 3;
                };
                return rank(left) - rank(right);
            });
            ordered.forEach((root) => UI.get(root, 'toolbar-overflow')?.layout());
        });
    }

    UI.define('toolbar-overflow', ({ element, listen, emit, floating }) => {
        const itemsHost = element.querySelector('[data-w-toolbar-overflow-items]');
        const more = element.querySelector('[data-w-toolbar-overflow-more]');
        const trigger = element.querySelector('[data-w-toolbar-overflow-toggle]');
        const menu = element.querySelector('[data-w-toolbar-overflow-menu]');
        if (!(itemsHost instanceof HTMLElement)
            || !(more instanceof HTMLElement)
            || !(trigger instanceof HTMLElement)
            || !(menu instanceof HTMLElement)) {
            return {};
        }

        const portal = floating.portal(menu, 'toolbar-overflow');
        const placement = () => element.dataset.wPlacement || 'bottom-end';
        let pointerReference = null;
        let layoutFrame = 0;
        let destroyed = false;

        const restoreItems = () => {
            while (menu.firstChild) itemsHost.append(menu.firstChild);
        };

        const close = (reason = '', restoreFocus = false, force = false) => {
            if (menu.hidden || (!force && !emit('before-close', { reason }))) return false;
            menu.hidden = true;
            menu.dataset.state = 'closed';
            menu.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            element.dataset.state = 'closed';
            monitor.unobserve(menu);
            monitor.reset();
            floating.clear(menu);
            portal.restore();
            pointerReference = null;
            if (restoreFocus && trigger.isConnected) trigger.focus({ preventScroll: true });
            emit('close', { reason }, false);
            scheduleLayout();
            return true;
        };

        const monitor = floating.monitor(
            trigger,
            () => menu,
            placement,
            () => close('anchor-hidden', false, true),
        );

        const open = (reference = null) => {
            if (!menu.hidden || menu.childElementCount === 0 || !emit('before-open')) return false;
            roots().forEach((root) => {
                if (root !== element) UI.get(root, 'toolbar-overflow')?.close('peer');
            });
            portal.mount();
            menu.hidden = false;
            menu.dataset.state = 'open';
            menu.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            element.dataset.state = 'open';
            monitor.observe(menu);
            const stableReference = reference || floating.capture(trigger, null, 'element');
            if (monitor.place(stableReference)?.anchorVisible === false) {
                close('anchor-hidden', false, true);
                return false;
            }
            queueMicrotask(() => menu.querySelector('button:not([disabled]), a[href], select:not([disabled])')?.focus());
            emit('open', {}, false);
            return true;
        };

        const layout = () => {
            if (destroyed || !menu.hidden) return;
            restoreItems();
            more.hidden = true;
            element.style.removeProperty('inline-size');
            element.style.removeProperty('max-inline-size');

            const limit = availableWidth(element);
            element.style.setProperty('max-inline-size', `${limit}px`);

            if (usedWidth(element, more) > limit + 1) {
                more.hidden = false;
                const movable = [...itemsHost.children].filter((item) => item instanceof HTMLElement);
                let guard = 0;
                while (usedWidth(element, more) > limit + 1 && movable.length > 0 && guard < 64) {
                    guard += 1;
                    const item = movable.pop();
                    if (item instanceof HTMLElement && item.parentElement === itemsHost) {
                        menu.insertBefore(item, menu.firstChild);
                    }
                }
                if (menu.childElementCount === 0) more.hidden = true;
            }

            if (more.hidden) {
                menu.hidden = true;
                menu.dataset.state = 'closed';
                menu.setAttribute('aria-hidden', 'true');
                trigger.setAttribute('aria-expanded', 'false');
                element.dataset.state = 'closed';
            }
        };

        function scheduleLayout() {
            cancelAnimationFrame(layoutFrame);
            layoutFrame = requestAnimationFrame(layout);
        }

        const resizeObserver = typeof ResizeObserver === 'function'
            ? new ResizeObserver(scheduleAllLayouts)
            : null;
        resizeObserver?.observe(element);
        if (element.parentElement) resizeObserver?.observe(element.parentElement);
        const shell = element.closest('.editor-toolbar, .preview-toolbar, .theme-editor-container');
        if (shell instanceof HTMLElement) resizeObserver?.observe(shell);

        listen(trigger, 'pointerdown', (event) => {
            if (!event.isPrimary || event.button !== 0) return;
            pointerReference = floating.capture(trigger, event, 'element');
        });
        listen(trigger, 'click', (event) => {
            event.preventDefault();
            if (!menu.hidden) {
                close('trigger');
                return;
            }
            const reference = pointerReference
                && performance.now() - pointerReference.capturedAt < 1200
                ? pointerReference
                : floating.capture(trigger, null, 'element');
            pointerReference = null;
            open(reference);
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close('outside');
        });
        listen(document, 'keydown', (event) => {
            if (menu.hidden || event.key !== 'Escape' || event.defaultPrevented || !portal.isTopmost()) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            close('escape', true);
        });
        listen(menu, 'click', (event) => {
            if (event.target instanceof Element && event.target.closest('button, a[href]')) {
                queueMicrotask(() => close('action'));
            }
        });
        listen(window, 'pagehide', () => close('pagehide', false, true));
        listen(window, 'pageshow', () => close('history-restore', false, true));

        menu.hidden = true;
        menu.dataset.state = 'closed';
        menu.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        element.dataset.state = 'closed';
        scheduleAllLayouts();

        return {
            open,
            close,
            layout,
            destroy() {
                close('unmount', false, true);
                destroyed = true;
                cancelAnimationFrame(layoutFrame);
                resizeObserver?.disconnect();
                monitor.destroy();
                portal.destroy();
                restoreItems();
                element.style.removeProperty('max-inline-size');
            },
        };
    });

    UI.mount(document);
})();
