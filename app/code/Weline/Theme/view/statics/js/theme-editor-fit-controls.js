/**
 * Fit Theme Editor toolbar Scope / language (and sibling selects) to available
 * toolbar space. Long Scope labels ellipsize; secondary selects overflow to「更多」.
 *
 * Uses an off-screen text mirror for native <select> width measurement. Fit runs
 * only on real layout/input changes — never from its own title/width writes
 * (those used to re-enter via MutationObserver / ResizeObserver and flash the mirror).
 */
(function () {
    'use strict';

    const MAX_SELECT_PX = 240;
    const SELECT_EXTRA_FALLBACK_PX = 48;
    const FIT_SAFETY_PX = 12;

    let mirror = null;
    let scheduled = 0;
    /** True while fitAll / overflow layout is applying side effects we must ignore. */
    let suppressObserve = false;
    let lastMirrorKey = '';

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
        lastMirrorKey = '';
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
        const content = String(text || '').replace(/\s+/g, ' ').trim() || '—';
        const key = [
            content,
            style.font,
            style.fontSize,
            style.fontWeight,
            style.fontFamily,
            style.letterSpacing,
            style.textTransform,
        ].join('\u0001');
        if (key !== lastMirrorKey) {
            el.style.font = style.font;
            el.style.fontSize = style.fontSize;
            el.style.fontWeight = style.fontWeight;
            el.style.fontFamily = style.fontFamily;
            el.style.letterSpacing = style.letterSpacing;
            el.style.textTransform = style.textTransform;
            el.textContent = content;
            lastMirrorKey = key;
        }
        return Math.ceil(el.getBoundingClientRect().width || el.offsetWidth || 0);
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function setAttrIfChanged(el, name, value) {
        if (!(el instanceof HTMLElement)) return;
        if (el.getAttribute(name) === value) return;
        el.setAttribute(name, value);
    }

    function setStyleIfChanged(el, prop, value) {
        if (!(el instanceof HTMLElement)) return;
        if (el.style.getPropertyValue(prop) === value) return;
        el.style.setProperty(prop, value);
    }

    function fitNativeSelect(select) {
        if (!(select instanceof HTMLSelectElement) || select.hidden) return;
        const option = select.options[select.selectedIndex];
        const label = option ? String(option.text || option.label || '') : '';
        const textWidth = measureText(label, select);
        const chrome = horizontalChrome(select, SELECT_EXTRA_FALLBACK_PX);
        const next = clamp(textWidth + chrome + FIT_SAFETY_PX, 96, MAX_SELECT_PX);
        const size = `${next}px`;
        setStyleIfChanged(select, 'inline-size', size);
        setStyleIfChanged(select, 'width', size);
        setStyleIfChanged(select, 'max-inline-size', `${MAX_SELECT_PX}px`);
        select.dataset.wFitWidth = '1';
        if (label && select.title !== label) {
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
            if (el.style.getPropertyValue(prop)) {
                el.style.removeProperty(prop);
            }
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
            setAttrIfChanged(display, 'title', tip);
            setAttrIfChanged(trigger, 'title', tip);
            setAttrIfChanged(tree, 'title', tip);
            setAttrIfChanged(field, 'title', tip);
        }

        clearInlineBoxSize(field);
        clearInlineBoxSize(tree);
        clearInlineBoxSize(trigger);
        if (display instanceof HTMLElement) {
            ['max-inline-size', 'min-inline-size', 'width', 'inline-size'].forEach((prop) => {
                if (display.style.getPropertyValue(prop)) {
                    display.style.removeProperty(prop);
                }
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
            suppressObserve = true;
            try {
                fitAll();
                requestAnimationFrame(() => {
                    try {
                        fitAll();
                        setTimeout(() => {
                            try {
                                relayoutOverflow();
                            } finally {
                                suppressObserve = false;
                            }
                        }, 0);
                    } catch (err) {
                        suppressObserve = false;
                        throw err;
                    }
                });
            } catch (err) {
                suppressObserve = false;
                throw err;
            }
        });
    }

    function onObservedChange() {
        // Drop ResizeObserver / MutationObserver callbacks caused by our own
        // width/title writes — re-queuing them was the continuous mirror flash.
        if (suppressObserve) return;
        scheduleFit();
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
            const observer = new MutationObserver(onObservedChange);
            observer.observe(scopeField, {
                subtree: true,
                characterData: true,
                childList: true,
                attributes: true,
                // Do not watch `title`: fitScopeField writes title for tooltips and
                // must not re-trigger itself.
                attributeFilter: ['value', 'data-value', 'aria-label', 'data-title-label', 'class'],
            });
        }

        if (typeof ResizeObserver === 'function') {
            const resizeObserver = new ResizeObserver(onObservedChange);
            resizeObserver.observe(root);
            const toolbar = root.querySelector('.editor-toolbar');
            if (toolbar) resizeObserver.observe(toolbar);
            const left = root.querySelector('.toolbar-left');
            if (left) resizeObserver.observe(left);
        }

        window.addEventListener('resize', onObservedChange);
        document.addEventListener('weline:scope-change', onObservedChange);
        document.addEventListener('weline:theme-editor:scope-changed', onObservedChange);

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
