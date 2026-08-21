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
        const close = (reason = 'api') => {
            if (panel.hidden) return false;
            panel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            monitor.unobserve(panel);
            floating.clear(panel);
            portal.restore();
            return true;
        };
        const monitor = floating.monitor(
            input,
            () => panel,
            () => element.dataset.wPlacement || 'bottom-start',
            () => close('anchor-hidden'),
        );
        const open = () => {
            if (!panel.hidden) return false;
            portal.mount();
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            monitor.observe(panel);
            if (monitor.place()?.anchorVisible === false) {
                close('anchor-hidden');
                return false;
            }
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
            if (event.key === 'Escape') close('escape');
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
        return {
            open,
            close,
            choose,
            element,
            destroy: () => {
                close('unmount');
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

    UI.define('icon-picker', ({ element, listen, floating, emit }) => {
        const input = element.querySelector('[data-w-icon-input]');
        const trigger = element.querySelector('[data-w-icon-trigger]');
        const panel = element.querySelector('[data-w-icon-panel]');
        const search = element.querySelector('[data-w-icon-search]');
        const preview = element.querySelector('[data-w-icon-preview]');
        const text = element.querySelector('[data-w-icon-text]');
        const empty = element.querySelector('[data-w-icon-empty]');
        if (!(input instanceof HTMLInputElement)
            || !(trigger instanceof HTMLElement)
            || !(panel instanceof HTMLElement)
            || !(preview instanceof HTMLElement)) return {};
        const portal = floating.portal(panel, 'icon-picker');
        const options = [...element.querySelectorAll('[data-w-icon-value]')];
        const sync = () => {
            const selected = options.find((option) => option.dataset.wIconValue === input.value);
            options.forEach((option) => option.setAttribute('aria-selected', String(option === selected)));
            preview.replaceChildren();
            if (selected) {
                const icon = selected.querySelector('.w-icon')?.cloneNode(true);
                if (icon) preview.append(icon);
            }
            if (text) text.textContent = input.value;
        };
        const close = () => {
            if (panel.hidden) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            trigger.setAttribute('aria-expanded', 'false');
            monitor.unobserve(panel);
            floating.clear(panel);
            portal.restore();
            return true;
        };
        const monitor = floating.monitor(
            trigger,
            () => panel,
            () => element.dataset.wPlacement || 'bottom-start',
            () => close(),
        );
        const open = () => {
            if (!panel.hidden) return false;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            trigger.setAttribute('aria-expanded', 'true');
            monitor.observe(panel);
            if (monitor.place()?.anchorVisible === false) {
                close();
                return false;
            }
            search?.focus();
            return true;
        };
        const choose = (value) => {
            input.value = value;
            sync();
            input.dispatchEvent(new Event('change', {bubbles: true}));
            emit('change', {value}, false);
            close();
        };
        listen(trigger, 'click', () => panel.hidden ? open() : close());
        const onPick = (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const option = target?.closest('[data-w-icon-value]');
            if (option) choose(option.dataset.wIconValue || '');
            if (target?.closest('[data-w-icon-clear]')) choose('');
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
        listen(element, 'keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                trigger.focus();
            }
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close();
        });
        sync();
        return {
            open,
            close,
            choose,
            element,
            destroy: () => {
                close();
                monitor.destroy();
                portal.destroy();
            },
        };
    });
}
