/**
 * Fit Theme Editor toolbar Scope / language (and sibling selects) to available
 * toolbar space. Long Scope labels ellipsize; secondary selects overflow to「更多」.
 */
(function () {
    'use strict';

    const MAX_SELECT_PX = 240;
    const SELECT_EXTRA_FALLBACK_PX = 48;
    const FIT_SAFETY_PX = 12;

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
        if (label) {
            select.title = label;
        }
    }

    function clearInlineBoxSize(el) {
        if (!(el instanceof HTMLElement)) return;
        // Stale fit passes used to hardcode up to 480px on the trigger; wipe them
        // so CSS (.toolbar-select-field-scope ~14rem) is the only width source.
        [
            'width',
            'inline-size',
            'max-width',
            'max-inline-size',
            'min-width',
            'min-inline-size',
            'flex',
            'flex-basis',
        ].forEach((prop) => {
            el.style.removeProperty(prop);
        });
    }

    function fitScopeField(field) {
        if (!(field instanceof HTMLElement)) return;
        const display = field.querySelector('.w-tree-select-display, .w-scope-select-display, [data-w-scope-display]');
        const trigger = field.querySelector('.w-tree-select-trigger, .w-scope-select-trigger, [data-w-scope-trigger]');
        const tree = field.querySelector('.w-tree-select, .w-scope-select');
        const selected = tree?.querySelector?.('[data-w-scope-node].selected, [data-w-scope-node][aria-selected="true"]');
        const tip = String(
            selected?.getAttribute?.('data-title-label')
            || display?.getAttribute?.('title')
            || trigger?.getAttribute?.('title')
            || display?.textContent
            || trigger?.textContent
            || ''
        ).replace(/\s+/g, ' ').trim();
        // Disabled <button> often suppresses native title tooltips — hang tip on wrappers too.
        if (tip) {
            if (display instanceof HTMLElement) {
                display.setAttribute('title', tip);
            }
            if (trigger instanceof HTMLElement) {
                trigger.setAttribute('title', tip);
            }
            if (tree instanceof HTMLElement) {
                tree.setAttribute('title', tip);
            }
            field.setAttribute('title', tip);
        }

        clearInlineBoxSize(field);
        clearInlineBoxSize(tree);
        clearInlineBoxSize(trigger);
        if (display instanceof HTMLElement) {
            ['max-inline-size', 'min-inline-size', 'width', 'inline-size'].forEach((prop) => {
                display.style.removeProperty(prop);
            });
        }
        delete field.dataset.wFitWidth;
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
            const left = root.querySelector('.toolbar-left');
            if (left) resizeObserver.observe(left);
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
