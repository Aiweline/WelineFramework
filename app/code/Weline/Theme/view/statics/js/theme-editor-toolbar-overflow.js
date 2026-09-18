/**
 * Theme Editor toolbar overflow.
 *
 * Keeps every toolbar action available while delegating popup positioning,
 * viewport collision handling, focus restoration and teardown to Weline.UI.
 */
(function registerThemeEditorToolbarOverflow() {
    'use strict';

    // Bundle is type=module and concatenates sources; a hard throw here aborts the
    // rest of weline-theme-editor.js (including loadCanvas / widget library).
    // Defer until Weline.UI is present instead of failing the whole editor boot.
    let bootAttempts = 0;
    const maxBootAttempts = 120;

    function bootWhenUiReady() {
        const UI = window.Weline?.UI;
        if (!UI) {
            bootAttempts += 1;
            if (bootAttempts > maxBootAttempts) {
                console.warn('[ThemeEditor] toolbar-overflow skipped: Weline.UI not ready');
                return;
            }
            window.setTimeout(bootWhenUiReady, 50);
            return;
        }
        register(UI);
    }

    function register(UI) {
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
        } else {
            // preview-actions 须扣掉同行 tabs / 交互模式 / 选中目标 / 禁链等全部兄弟，
            // 否则会高估可用宽、挤占本可由 gutter 让出的空间。
            const siblings = [...parent.children].filter((element) => (
                element !== root && element instanceof HTMLElement && !element.hidden
            ));
            siblingWidth = siblings.reduce((sum, element) => sum + intrinsicWidth(element), 0)
                + gap * Math.max(0, siblings.length);
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

    function previewToolbarMainNeed(main) {
        if (!(main instanceof HTMLElement)) return 0;
        const children = [...main.children].filter((element) => (
            element instanceof HTMLElement && !element.hidden
        ));
        if (children.length === 0) return 0;
        const gap = flexGap(main);
        let sum = horizontalPadding(main);
        children.forEach((element, index) => {
            if (index > 0) sum += gap;
            if (element.classList.contains('preview-actions')
                || element.classList.contains('w-toolbar-overflow')) {
                const itemsHost = element.querySelector('[data-w-toolbar-overflow-items]');
                const menu = element.querySelector('[data-w-toolbar-overflow-menu]');
                const buttons = [
                    ...(itemsHost instanceof HTMLElement ? [...itemsHost.children] : []),
                    ...(menu instanceof HTMLElement ? [...menu.children] : []),
                ].filter((item) => item instanceof HTMLElement);
                const itemGap = flexGap(itemsHost || element);
                sum += buttons.reduce((total, button) => total + intrinsicWidth(button), 0)
                    + itemGap * Math.max(0, buttons.length - 1);
                return;
            }
            sum += intrinsicWidth(element);
        });
        return sum;
    }

    /**
     * 未借用 gutter 时中间列可用宽。已借用时禁止先 remove class 再测：
     * ResizeObserver 盯着 .preview-toolbar，remove/add 会触发无限 schedule 闪烁。
     */
    function unborrowedMiddleWidth(toolbar, main) {
        if (!toolbar.classList.contains('preview-toolbar--borrow-gutters')) {
            return main.clientWidth;
        }
        const style = window.getComputedStyle(toolbar);
        const configW = Number.parseFloat(style.getPropertyValue('--w-theme-editor-active-config-panel-width')) || 0;
        const widgetW = Number.parseFloat(style.getPropertyValue('--w-theme-editor-active-widget-panel-width')) || 0;
        return Math.max(0, toolbar.clientWidth - configW - widgetW);
    }

    function syncPreviewToolbarBorrow() {
        document.querySelectorAll('.preview-toolbar.editor-preview-toolbar').forEach((toolbar) => {
            if (!(toolbar instanceof HTMLElement)) return;
            const main = toolbar.querySelector('.preview-toolbar-main');
            if (!(main instanceof HTMLElement)) return;

            const need = previewToolbarMainNeed(main);
            const middleWidth = unborrowedMiddleWidth(toolbar, main);
            const borrowing = toolbar.classList.contains('preview-toolbar--borrow-gutters');
            // 中间列不够 → 先向两边借 gutter；整条仍不够再由 overflow 收进「更多」。
            // 禁止再用「整条够用才借」门槛：否则 need 大于整条时两侧空着却已出现「更多」。
            // 滞回：避免临界宽度下 true/false 来回抖
            const slack = 2;
            const shouldBorrow = borrowing
                ? (need > middleWidth - slack)
                : (need > middleWidth + slack);
            // classList.toggle(token, force) 在状态相同时无 DOM 变更，避免 DevTools 蓝闪
            toolbar.classList.toggle('preview-toolbar--borrow-gutters', shouldBorrow);
        });
    }

    let layoutPassDepth = 0;

    function scheduleAllLayouts() {
        // 布局过程中 class/尺寸变化会再进 ResizeObserver；忽略以免死循环
        if (layoutPassDepth > 0) return;
        cancelAnimationFrame(scheduleAllLayouts.frame || 0);
        scheduleAllLayouts.frame = requestAnimationFrame(() => {
            layoutPassDepth += 1;
            try {
                syncPreviewToolbarBorrow();
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
            } finally {
                // ResizeObserver 常在本帧布局后、下一帧前投递；延到下一 rAF 再放行
                requestAnimationFrame(() => {
                    layoutPassDepth = Math.max(0, layoutPassDepth - 1);
                });
            }
        });
    }

    try {
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
            // 预览工具栏先决定是否占用两侧 gutter，再测量 overflow 可用宽
            if (element.classList.contains('preview-actions')) {
                syncPreviewToolbarBorrow();
            }
            restoreItems();
            more.hidden = true;
            element.style.removeProperty('inline-size');
            element.style.removeProperty('max-inline-size');

            const limit = availableWidth(element);
            element.style.setProperty('max-inline-size', `${limit}px`);

            if (usedWidth(element, more) > limit + 1) {
                more.hidden = false;
                const movable = [...itemsHost.children].filter((item) => (
                    item instanceof HTMLElement && !item.hasAttribute('data-w-toolbar-overflow-pin')
                ));
                let guard = 0;
                while (usedWidth(element, more) > limit + 1 && movable.length > 0 && guard < 64) {
                    guard += 1;
                    const item = movable.pop();
                    if (item instanceof HTMLElement && item.parentElement === itemsHost) {
                        menu.insertBefore(item, menu.firstChild);
                    }
                }
            }

            // Pack back the most recently overflowed items while leftover space remains.
            // Prevents an empty gap next to「更多」when one large removal frees room for smaller controls.
            let packGuard = 0;
            while (menu.childElementCount > 0 && packGuard < 64) {
                packGuard += 1;
                const candidate = menu.firstChild;
                if (!(candidate instanceof HTMLElement)) break;
                itemsHost.append(candidate);
                if (usedWidth(element, more) > limit + 1) {
                    menu.insertBefore(candidate, menu.firstChild);
                    break;
                }
            }

            if (menu.childElementCount === 0) {
                more.hidden = true;
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
    } catch (error) {
        const message = String(error?.message || error || '');
        if (!message.includes('already defined')) {
            throw error;
        }
    }

    UI.mount(document);
    }

    bootWhenUiReady();
})();
