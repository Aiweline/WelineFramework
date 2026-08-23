/* Weline UI source: js/components/advanced.js */
let registered = false;

export function register(UI) {
    if (registered) return;
    registered = true;

    UI.define('combobox', ({ element, listen, emit, floating }) => {
        const input = element.querySelector('[role="combobox"]');
        const panel = element.querySelector('[role="listbox"]');
        if (!(input instanceof HTMLInputElement) || !(panel instanceof HTMLElement)) return {};
        const portal = floating.portal(panel, 'combobox');
        const options = () => [...panel.querySelectorAll('[role="option"]')].filter((option) => !option.hidden);
        const close = (reason = 'api', force = false) => {
            if (panel.hidden || (!force && !emit('before-close', {reason}, true))) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            panel.setAttribute('aria-hidden', 'true');
            input.setAttribute('aria-expanded', 'false');
            element.dataset.state = 'closed';
            monitor.unobserve(panel);
            monitor.reset();
            floating.clear(panel);
            portal.restore();
            emit('close', {reason}, false);
            return true;
        };
        const monitor = floating.monitor(
            input,
            () => panel,
            () => element.dataset.wPlacement || 'bottom-start',
            () => close('anchor-hidden'),
        );
        const open = () => {
            if (!panel.hidden || !emit('before-open', {}, true)) return false;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            panel.setAttribute('aria-hidden', 'false');
            input.setAttribute('aria-expanded', 'true');
            element.dataset.state = 'open';
            monitor.observe(panel);
            if (monitor.place()?.anchorVisible === false) {
                close('anchor-hidden', true);
                return false;
            }
            emit('open', {}, false);
            return true;
        };
        const choose = (option) => {
            input.value = option.getAttribute('data-value') || option.textContent.trim();
            options().forEach((candidate) => candidate.setAttribute('aria-selected', String(candidate === option)));
            emit('change', {value: input.value, option}, false);
            input.dispatchEvent(new Event('change', {bubbles: true}));
            close('select');
        };
        listen(input, 'input', () => {
            const query = input.value.trim().toLocaleLowerCase();
            [...panel.querySelectorAll('[role="option"]')].forEach((option) => {
                option.hidden = query !== '' && !option.textContent.toLocaleLowerCase().includes(query);
            });
            open();
        });
        listen(input, 'focus', open);
        listen(input, 'keydown', (event) => {
            const visible = options();
            const active = visible.findIndex((option) => option.dataset.state === 'active');
            if (event.key === 'Escape' && !panel.hidden && portal.isTopmost()) {
                event.preventDefault();
                event.stopPropagation();
                close('escape');
                return;
            }
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && visible.length > 0) {
                event.preventDefault();
                open();
                visible.forEach((option) => delete option.dataset.state);
                const next = event.key === 'ArrowDown'
                    ? (active + 1) % visible.length
                    : (active - 1 + visible.length) % visible.length;
                visible[next].dataset.state = 'active';
                visible[next].scrollIntoView({block: 'nearest'});
            }
            if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                choose(visible[active]);
            }
        });
        listen(panel, 'click', (event) => {
            const option = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
            if (option) choose(option);
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close('outside');
        });
        listen(window, 'pagehide', () => close('pagehide', true));
        listen(window, 'pageshow', () => close('pageshow', true));
        panel.hidden = true;
        panel.dataset.state = 'closed';
        panel.setAttribute('aria-hidden', 'true');
        input.setAttribute('aria-expanded', 'false');
        element.dataset.state = 'closed';
        return {
            open,
            close,
            choose,
            element,
            destroy: () => {
                close('unmount', true);
                monitor.destroy();
                portal.destroy();
            },
        };
    });

    UI.define('tree', ({ element, listen, emit }) => {
        listen(element, 'click', (event) => {
            const toggle = event.target instanceof Element ? event.target.closest('[data-w-tree-toggle]') : null;
            if (!toggle) return;
            const item = toggle.closest('[role="treeitem"]');
            const group = item?.querySelector(':scope > [role="group"]');
            if (!item || !group) return;
            const expanded = item.getAttribute('aria-expanded') === 'true';
            item.setAttribute('aria-expanded', String(!expanded));
            group.hidden = expanded;
            emit('change', {item, expanded: !expanded}, false);
        });
        listen(element, 'keydown', (event) => {
            const item = event.target instanceof Element ? event.target.closest('[role="treeitem"]') : null;
            if (!item) return;
            if (event.key === 'ArrowRight' && item.getAttribute('aria-expanded') === 'false') item.querySelector('[data-w-tree-toggle]')?.click();
            if (event.key === 'ArrowLeft' && item.getAttribute('aria-expanded') === 'true') item.querySelector('[data-w-tree-toggle]')?.click();
        });
        return {element};
    });

    UI.define('reorder-list', ({ element, listen, emit }) => {
        const itemSelector = '[data-w-reorder-item]';
        const handleSelector = '[data-w-reorder-handle]';
        const liveRegion = document.createElement('span');
        let pointerSession = null;

        liveRegion.className = 'w-visually-hidden';
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        element.append(liveRegion);
        if (!element.hasAttribute('role')) element.setAttribute('role', 'list');

        const items = () => [...element.children]
            .filter((child) => child instanceof HTMLElement && child.matches(itemSelector));
        const disabled = () => element.dataset.wReorderDisabled === 'true';
        const handleFor = (item) => item?.querySelector(handleSelector) || null;
        const resolveItem = (target) => {
            const handle = target instanceof Element ? target.closest(handleSelector) : null;
            const item = handle?.closest(itemSelector);
            return handle && item?.parentElement === element ? {handle, item} : null;
        };
        const refresh = () => {
            const ordered = items();
            const total = ordered.length;
            ordered.forEach((item, index) => {
                if (!item.hasAttribute('role')) item.setAttribute('role', 'listitem');
                item.dataset.index = String(index);
                item.setAttribute('aria-posinset', String(index + 1));
                item.setAttribute('aria-setsize', String(total));
                const handle = handleFor(item);
                if (!(handle instanceof HTMLElement)) return;
                if (!(handle instanceof HTMLButtonElement)) {
                    handle.setAttribute('role', 'button');
                    handle.tabIndex = 0;
                }
                handle.setAttribute('aria-keyshortcuts', element.dataset.wReorderAxis === 'horizontal'
                    ? 'ArrowLeft ArrowRight Home End'
                    : 'ArrowUp ArrowDown Home End');
                handle.setAttribute('aria-disabled', String(disabled()));
                handle.setAttribute('draggable', 'false');
            });
            return ordered;
        };
        const announce = (position, total) => {
            const template = element.dataset.wReorderAnnouncement || 'Moved to position {position} of {total}';
            liveRegion.textContent = template
                .replace('{position}', String(position))
                .replace('{total}', String(total));
        };
        const placeAt = (item, targetIndex) => {
            const ordered = items();
            const oldIndex = ordered.indexOf(item);
            const numericTarget = Number(targetIndex);
            if (!Number.isFinite(numericTarget)) return false;
            const nextIndex = Math.max(0, Math.min(ordered.length - 1, Math.trunc(numericTarget)));
            if (oldIndex < 0 || oldIndex === nextIndex) return false;
            if (nextIndex < oldIndex) {
                element.insertBefore(item, ordered[nextIndex]);
            } else {
                element.insertBefore(item, ordered[nextIndex].nextSibling);
            }
            return true;
        };
        const commit = (item, oldIndex, reason) => {
            const ordered = refresh();
            const newIndex = ordered.indexOf(item);
            if (oldIndex < 0 || newIndex < 0 || oldIndex === newIndex) return false;
            const handle = handleFor(item);
            handle?.focus({preventScroll: true});
            announce(newIndex + 1, ordered.length);
            emit('change', {item, oldIndex, newIndex, order: ordered, reason}, false);
            return true;
        };
        const move = (item, targetIndex, reason = 'api') => {
            if (!(item instanceof HTMLElement) || item.parentElement !== element || disabled()) return false;
            const oldIndex = items().indexOf(item);
            if (!placeAt(item, targetIndex)) {
                handleFor(item)?.focus({preventScroll: true});
                return false;
            }
            return commit(item, oldIndex, reason);
        };
        const pointerTargetIndex = (event, item) => {
            const horizontal = element.dataset.wReorderAxis === 'horizontal';
            const coordinate = horizontal ? event.clientX : event.clientY;
            const candidates = items().filter((candidate) => candidate !== item);
            let position = candidates.length;
            candidates.some((candidate, index) => {
                const rect = candidate.getBoundingClientRect();
                const midpoint = horizontal
                    ? rect.left + (rect.width / 2)
                    : rect.top + (rect.height / 2);
                if (coordinate >= midpoint) return false;
                position = index;
                return true;
            });
            return position;
        };
        const autoScroll = (event) => {
            const rect = element.getBoundingClientRect();
            const edge = 32;
            const horizontal = element.dataset.wReorderAxis === 'horizontal';
            let delta = 0;
            if (horizontal && event.clientX < rect.left + edge) delta = -16;
            if (horizontal && event.clientX > rect.right - edge) delta = 16;
            if (!horizontal && event.clientY < rect.top + edge) delta = -16;
            if (!horizontal && event.clientY > rect.bottom - edge) delta = 16;
            if (delta === 0) return;
            if (typeof element.scrollBy === 'function') {
                element.scrollBy(horizontal ? {left: delta} : {top: delta});
            } else if (horizontal) {
                element.scrollLeft += delta;
            } else {
                element.scrollTop += delta;
            }
        };
        const finishPointer = (cancelled = false) => {
            if (!pointerSession) return false;
            const session = pointerSession;
            pointerSession = null;
            if (cancelled && session.item.isConnected) placeAt(session.item, session.oldIndex);
            session.item.removeAttribute('data-state');
            session.handle.removeAttribute('data-state');
            element.removeAttribute('data-state');
            try {
                if (session.handle.hasPointerCapture?.(session.pointerId)) {
                    session.handle.releasePointerCapture(session.pointerId);
                }
            } catch (_error) {
                // The browser may release pointer capture before pointercancel/pagehide.
            }
            refresh();
            if (cancelled) {
                session.handle.focus({preventScroll: true});
                return false;
            }
            return commit(session.item, session.oldIndex, 'pointer');
        };

        listen(element, 'keydown', (event) => {
            if (event.key === 'Escape' && pointerSession) {
                event.preventDefault();
                finishPointer(true);
                return;
            }
            const resolved = resolveItem(event.target);
            if (!resolved || disabled()) return;
            const ordered = items();
            const currentIndex = ordered.indexOf(resolved.item);
            const horizontal = element.dataset.wReorderAxis === 'horizontal';
            let targetIndex = currentIndex;
            if (event.key === 'Home') targetIndex = 0;
            else if (event.key === 'End') targetIndex = ordered.length - 1;
            else if ((!horizontal && event.key === 'ArrowUp') || (horizontal && event.key === 'ArrowLeft')) targetIndex--;
            else if ((!horizontal && event.key === 'ArrowDown') || (horizontal && event.key === 'ArrowRight')) targetIndex++;
            else return;
            event.preventDefault();
            move(resolved.item, targetIndex, 'keyboard');
        });
        listen(element, 'pointerdown', (event) => {
            const resolved = resolveItem(event.target);
            if (pointerSession || !resolved || disabled() || event.button !== 0 || event.isPrimary === false) return;
            event.preventDefault();
            resolved.handle.focus({preventScroll: true});
            pointerSession = {
                item: resolved.item,
                handle: resolved.handle,
                oldIndex: items().indexOf(resolved.item),
                pointerId: event.pointerId,
            };
            resolved.item.dataset.state = 'dragging';
            resolved.handle.dataset.state = 'dragging';
            element.dataset.state = 'dragging';
            try {
                resolved.handle.setPointerCapture?.(event.pointerId);
            } catch (_error) {
                // Pointer capture is an enhancement; delegated events still preserve reordering.
            }
        });
        listen(element, 'pointermove', (event) => {
            if (!pointerSession || pointerSession.pointerId !== event.pointerId) return;
            event.preventDefault();
            autoScroll(event);
            placeAt(pointerSession.item, pointerTargetIndex(event, pointerSession.item));
        });
        listen(element, 'pointerup', (event) => {
            if (pointerSession?.pointerId === event.pointerId) finishPointer(false);
        });
        listen(element, 'pointercancel', (event) => {
            if (pointerSession?.pointerId === event.pointerId) finishPointer(true);
        });
        listen(window, 'blur', () => finishPointer(true));
        listen(window, 'pagehide', () => finishPointer(true));

        const observer = new MutationObserver(() => refresh());
        observer.observe(element, {childList: true, attributes: true, attributeFilter: ['data-w-reorder-disabled']});
        refresh();
        return {
            move,
            refresh,
            element,
            destroy: () => {
                finishPointer(true);
                observer.disconnect();
                liveRegion.remove();
            },
        };
    });

    UI.define('dependent-field', ({ element, listen, emit }) => {
        if (!(element instanceof HTMLInputElement)
            && !(element instanceof HTMLSelectElement)
            && !(element instanceof HTMLTextAreaElement)) return {};
        const dependencyNames = String(element.dataset.wDependencies || '')
            .split(',')
            .map((name) => name.trim())
            .filter(Boolean);
        const endpoint = element.dataset.wDependenceUrl || '';
        const scope = element.closest('form') || document;
        const sources = dependencyNames
            .map((name) => scope.querySelector(`[data-w-field-code="${CSS.escape(name)}"]`))
            .filter((source) => source instanceof HTMLInputElement
                || source instanceof HTMLSelectElement
                || source instanceof HTMLTextAreaElement);
        const label = element.id !== '' ? document.querySelector(`label[for="${CSS.escape(element.id)}"]`) : null;
        let sequence = 0;

        const hasValue = (source) => {
            if (source instanceof HTMLSelectElement && source.multiple) {
                return [...source.selectedOptions].some((option) => option.value !== '');
            }
            return !source.disabled && String(source.value || '').trim() !== '';
        };
        const setAvailable = (available) => {
            element.disabled = !available;
            element.hidden = !available;
            if (label instanceof HTMLElement) label.hidden = !available;
        };
        const normalize = (payload) => {
            if (payload && typeof payload === 'object' && !Array.isArray(payload)
                && Object.prototype.hasOwnProperty.call(payload, 'data')) return payload;
            return {code: 200, data: payload};
        };
        const render = (items) => {
            const entries = items && typeof items === 'object' ? Object.entries(items) : [];
            if (element instanceof HTMLSelectElement) {
                const current = element.value;
                element.replaceChildren();
                for (const [value, text] of entries) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = String(text ?? '');
                    option.selected = value === current;
                    element.append(option);
                }
            } else {
                element.value = entries.map(([, value]) => String(value ?? '')).join(',');
            }
            element.dispatchEvent(new Event('change', {bubbles: true}));
            emit('change', {items}, false);
        };
        const update = async (source = sources.find(hasValue)) => {
            const available = sources.length > 0 && sources.every(hasValue);
            setAvailable(available);
            if (!available || !source || endpoint === '') return false;
            const requestId = ++sequence;
            element.setAttribute('aria-busy', 'true');
            try {
                const url = new URL(endpoint, window.location.href);
                if (url.origin !== window.location.origin) throw new Error('Dependent field endpoint must be same-origin.');
                url.searchParams.set('d', source.dataset.wFieldCode || '');
                url.searchParams.set('dv', source.value || '');
                url.searchParams.set('a', element.dataset.wFieldCode || '');
                url.searchParams.set('av', element.value || '');
                const response = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) throw new Error(`Dependent field request failed (${response.status}).`);
                const business = normalize(await response.json());
                if (Number(business.code || 200) >= 400) throw new Error(String(business.msg || 'Dependent field request failed.'));
                if (requestId === sequence && element.isConnected) render(business.data || {});
                return true;
            } catch (error) {
                if (requestId === sequence) {
                    emit('error', {error}, false);
                    UI.toast.error(error instanceof Error ? error.message : String(error));
                }
                return false;
            } finally {
                if (requestId === sequence) element.removeAttribute('aria-busy');
            }
        };

        sources.forEach((source) => listen(source, 'change', () => update(source)));
        update();
        return {update, element};
    });

    UI.define('transfer-list', ({ element, listen, emit }) => {
        const move = (direction) => {
            const source = element.querySelector(`[data-w-transfer-list="${direction === 'next' ? 'source' : 'target'}"]`);
            const target = element.querySelector(`[data-w-transfer-list="${direction === 'next' ? 'target' : 'source'}"]`);
            if (!source || !target) return;
            const selected = [...source.querySelectorAll('[data-w-transfer-item][aria-selected="true"]')];
            selected.forEach((item) => {
                item.setAttribute('aria-selected', 'false');
                target.append(item);
            });
            emit('change', {direction, items: selected}, false);
        };
        listen(element, 'click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const item = target?.closest('[data-w-transfer-item]');
            if (item) item.setAttribute('aria-selected', String(item.getAttribute('aria-selected') !== 'true'));
            const action = target?.closest('[data-w-transfer-action]')?.getAttribute('data-w-transfer-action');
            if (action === 'next' || action === 'previous') move(action);
        });
        return {move, element};
    });

    UI.define('icon-picker', ({ element, listen, floating, emit, UI: componentUI }) => {
        const input = element.querySelector('[data-w-icon-input]');
        const trigger = element.querySelector('[data-w-icon-trigger]');
        const panel = element.querySelector('[data-w-icon-panel]');
        const search = element.querySelector('[data-w-icon-search]');
        const preview = element.querySelector('[data-w-icon-preview]');
        const text = element.querySelector('[data-w-icon-text]');
        const empty = element.querySelector('[data-w-icon-empty]');
        const clear = element.querySelector('[data-w-icon-clear]');
        const custom = panel?.querySelector('[data-w-icon-custom]');
        if (!(input instanceof HTMLInputElement)
            || !(trigger instanceof HTMLElement)
            || !(panel instanceof HTMLElement)
            || !(preview instanceof HTMLElement)) return {};
        const portal = floating.portal(panel, 'icon-picker');
        const options = [...element.querySelectorAll('[data-w-icon-value]')];
        const normalize = (value) => {
            const name = String(value || '').trim().toLowerCase();
            return /^[a-z][a-z0-9-]{0,63}$/.test(name) && !/^(?:mdi|fa[brs]?|ri)-/.test(name)
                ? name
                : '';
        };
        const sync = () => {
            const value = normalize(input.value);
            if (input.value !== value) input.value = value;
            const selected = options.find((option) => option.dataset.wIconValue === value);
            options.forEach((option) => option.setAttribute('aria-selected', String(option === selected)));
            preview.replaceChildren();
            if (value !== '') preview.append(componentUI.icon.create(value, {size: 'sm'}));
            if (text) text.textContent = value || element.dataset.wEmptyLabel || '';
            if (clear instanceof HTMLElement) clear.hidden = value === '';
            if (custom instanceof HTMLInputElement && document.activeElement !== custom) custom.value = value;
        };
        const close = (reason = 'api', restoreFocus = false, force = false) => {
            if (panel.hidden) return false;
            if (!force && !emit('before-close', {reason}, true)) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            panel.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            element.dataset.state = 'closed';
            monitor.unobserve(panel);
            monitor.reset();
            floating.clear(panel);
            portal.restore();
            if (restoreFocus && trigger.isConnected) trigger.focus({preventScroll: true});
            emit('close', {reason}, false);
            return true;
        };
        const monitor = floating.monitor(
            trigger,
            () => panel,
            () => element.dataset.wPlacement || 'bottom-start',
            () => close('anchor-hidden', false, true),
        );
        const open = (event = null) => {
            if (!panel.hidden || !emit('before-open', {}, true)) return false;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            panel.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            element.dataset.state = 'open';
            monitor.observe(panel);
            const reference = floating.capture(trigger, event, 'element');
            if (monitor.place(reference)?.anchorVisible === false) {
                close('anchor-hidden', false, true);
                return false;
            }
            search?.focus();
            emit('open', {}, false);
            return true;
        };
        const choose = (value, restoreFocus = false) => {
            const normalized = value === '' ? '' : normalize(value);
            if (value !== '' && normalized === '') return false;
            input.value = normalized;
            sync();
            input.dispatchEvent(new Event('change', {bubbles: true}));
            emit('change', {value: normalized}, false);
            close('select', restoreFocus);
            return true;
        };
        const applyCustom = () => {
            if (!(custom instanceof HTMLInputElement)) return false;
            const value = normalize(custom.value);
            const valid = custom.value.trim() === '' || value !== '';
            custom.setAttribute('aria-invalid', String(!valid));
            if (!valid) {
                custom.focus();
                return false;
            }
            return choose(value, true);
        };
        listen(trigger, 'click', (event) => panel.hidden ? open(event) : close('toggle'));
        const onPick = (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const option = target?.closest('[data-w-icon-value]');
            if (option) choose(option.dataset.wIconValue || '', event.detail === 0);
            if (target?.closest('[data-w-icon-clear]')) choose('', event.detail === 0);
            if (target?.closest('[data-w-icon-apply]')) applyCustom();
        };
        listen(element, 'click', onPick);
        listen(panel, 'click', onPick);
        if (search) listen(search, 'input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            options.forEach((option) => {
                option.hidden = query !== '' && !String(option.dataset.wIconValue).includes(query);
                if (!option.hidden) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
            if (!panel.hidden) monitor.place();
        });
        if (custom instanceof HTMLInputElement) {
            listen(custom, 'input', () => custom.removeAttribute('aria-invalid'));
            listen(custom, 'keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    applyCustom();
                }
            });
        }
        const onKeydown = (event) => {
            if (event.key === 'Escape' && !panel.hidden && portal.isTopmost()) {
                event.preventDefault();
                event.stopPropagation();
                close('escape', true);
            }
        };
        listen(element, 'keydown', onKeydown);
        listen(panel, 'keydown', onKeydown);
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close('outside');
        });
        listen(window, 'pagehide', () => close('pagehide', false, true));
        listen(window, 'pageshow', () => close('pageshow', false, true));
        panel.hidden = true;
        panel.dataset.state = 'closed';
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        element.dataset.state = 'closed';
        sync();
        return {
            open,
            close,
            choose,
            element,
            destroy: () => {
                close('unmount', false, true);
                monitor.destroy();
                portal.destroy();
            },
        };
    });
}
