/**
 * Fit Theme Editor toolbar Scope / language (and sibling selects) to the
 * currently selected label width so leftover chrome does not reserve empty space.
 */
(function () {
    'use strict';

    const MAX_SELECT_PX = 360;
    const MAX_SCOPE_PX = 480;
    const SELECT_EXTRA_FALLBACK_PX = 48;
    const SCOPE_EXTRA_FALLBACK_PX = 64;
    const FIT_SAFETY_PX = 20;

    let mirror = null;
    let scheduled = 0;

    function ensureMirror() {
        if (mirror && mirror.isConnected) return mirror;
        mirror = document.createElement('span');
        mirror.setAttribute('aria-hidden', 'true');
        mirror.dataset.wThemeEditorFitMirror = '1';
        mirror.style.cssText = [
            'position:absolute',
            'left:-9999px',
            'top:0',
            'visibility:hidden',
            'pointer-events:none',
            'white-space:nowrap',
            'display:inline-block',
        ].join(';');
        document.body.appendChild(mirror);
        return mirror;
    }

    function horizontalChrome(element, fallback) {
        if (!(element instanceof HTMLElement)) return fallback;
        const style = window.getComputedStyle(element);
        const pad = (Number.parseFloat(style.paddingInlineStart || style.paddingLeft) || 0)
            + (Number.parseFloat(style.paddingInlineEnd || style.paddingRight) || 0);
        const border = (Number.parseFloat(style.borderInlineStartWidth || style.borderLeftWidth) || 0)
            + (Number.parseFloat(style.borderInlineEndWidth || style.borderRightWidth) || 0);
        return Math.ceil(pad + border) || fallback;
    }

    function measureText(text, source) {
        const el = ensureMirror();
        const style = window.getComputedStyle(source);
        el.style.font = style.font;
        el.style.fontSize = style.fontSize;
        el.style.fontWeight = style.fontWeight;
        el.style.fontFamily = style.fontFamily;
        el.style.letterSpacing = style.letterSpacing;
        el.style.textTransform = style.textTransform;
        el.textContent = String(text || '').replace(/\s+/g, ' ').trim() || '—';
        return Math.ceil(el.getBoundingClientRect().width || el.offsetWidth || 0);
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function fitNativeSelect(select) {
        if (!(select instanceof HTMLSelectElement) || select.hidden) return;
        const option = select.options[select.selectedIndex];
        const label = option ? String(option.text || option.label || '') : '';
        const textWidth = measureText(label, select);
        const chrome = horizontalChrome(select, SELECT_EXTRA_FALLBACK_PX);
        const next = clamp(textWidth + chrome + FIT_SAFETY_PX, 96, MAX_SELECT_PX);
        select.style.setProperty('inline-size', `${next}px`);
        select.style.setProperty('width', `${next}px`);
        select.style.setProperty('max-inline-size', `${MAX_SELECT_PX}px`);
        select.dataset.wFitWidth = '1';
    }

    function fitScopeField(field) {
        if (!(field instanceof HTMLElement)) return;
        const display = field.querySelector('.w-tree-select-display, .w-scope-select-display, [data-w-scope-display]');
        const trigger = field.querySelector('.w-tree-select-trigger, .w-scope-select-trigger, [data-w-scope-trigger]');
        const target = trigger || field.querySelector('.w-tree-select, .w-scope-select') || field;
        const source = display instanceof HTMLElement ? display : target;
        const label = String(
            source?.getAttribute?.('title')
            || source?.getAttribute?.('aria-label')
            || source?.textContent
            || ''
        ).replace(/\s+/g, ' ').trim();
        const textWidth = measureText(label || 'Scope', source);
        const chrome = horizontalChrome(trigger instanceof HTMLElement ? trigger : target, SCOPE_EXTRA_FALLBACK_PX);
        const next = clamp(textWidth + chrome + FIT_SAFETY_PX, 120, MAX_SCOPE_PX);
        if (trigger instanceof HTMLElement) {
            trigger.style.setProperty('inline-size', `${next}px`);
            trigger.style.setProperty('width', `${next}px`);
            trigger.style.setProperty('max-inline-size', `${MAX_SCOPE_PX}px`);
        }
        const tree = field.querySelector('.w-tree-select, .w-scope-select');
        if (tree instanceof HTMLElement) {
            tree.style.setProperty('inline-size', 'auto');
            tree.style.setProperty('width', 'auto');
            tree.style.setProperty('max-inline-size', `${MAX_SCOPE_PX}px`);
        }
        field.style.setProperty('inline-size', 'auto');
        field.style.setProperty('width', 'auto');
        field.style.setProperty('max-inline-size', `min(${MAX_SCOPE_PX}px, 48vw)`);
        field.dataset.wFitWidth = '1';
    }

    function relayoutOverflow() {
        try {
            const UI = window.Weline?.UI;
            if (!UI?.get) return;
            document.querySelectorAll('.theme-editor-container [data-w-component~="toolbar-overflow"]').forEach((root) => {
                UI.get(root, 'toolbar-overflow')?.layout?.();
            });
        } catch (_e) {
            /* ignore */
        }
    }

    function fitAll() {
        const root = document.getElementById('themeEditor');
        if (!root) return;
        fitScopeField(root.querySelector('.toolbar-select-field-scope'));
        // Fit every toolbar select (including ones currently parked in「更多」) so pack-back
        // can reclaim them once measured widths shrink.
        root.querySelectorAll('.toolbar-left select.w-select, .toolbar-left #editorLangSwitcher').forEach((select) => {
            fitNativeSelect(select);
        });
        relayoutOverflow();
    }

    function scheduleFit() {
        cancelAnimationFrame(scheduled);
        scheduled = requestAnimationFrame(() => {
            fitAll();
            requestAnimationFrame(() => {
                fitAll();
                // Overflow may have skipped while「更多」was open; one more pass after paint.
                setTimeout(relayoutOverflow, 0);
            });
        });
    }

    function bind() {
        const root = document.getElementById('themeEditor');
        if (!root || root.dataset.fitControlsBound === '1') return;
        root.dataset.fitControlsBound = '1';

        root.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (target.matches('#scopeSelect, #editorLangSwitcher, .toolbar-left select.w-select')
                || target.closest('.toolbar-select-field-scope')) {
                scheduleFit();
            }
        });

        const scopeField = root.querySelector('.toolbar-select-field-scope');
        if (scopeField && typeof MutationObserver === 'function') {
            const observer = new MutationObserver(scheduleFit);
            observer.observe(scopeField, {
                subtree: true,
                characterData: true,
                childList: true,
                attributes: true,
                attributeFilter: ['value', 'data-value', 'aria-label', 'title'],
            });
        }

        if (typeof ResizeObserver === 'function') {
            const resizeObserver = new ResizeObserver(scheduleFit);
            resizeObserver.observe(root);
            const toolbar = root.querySelector('.editor-toolbar');
            if (toolbar) resizeObserver.observe(toolbar);
        }

        window.addEventListener('resize', scheduleFit);
        document.addEventListener('weline:scope-change', scheduleFit);
        document.addEventListener('weline:theme-editor:scope-changed', scheduleFit);

        window.Weline = window.Weline || {};
        window.Weline.Theme = window.Weline.Theme || {};
        window.Weline.Theme.EditorFitControls = {
            refresh: scheduleFit,
        };

        scheduleFit();
        // Scope label often arrives after async init.
        setTimeout(scheduleFit, 120);
        setTimeout(scheduleFit, 480);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
