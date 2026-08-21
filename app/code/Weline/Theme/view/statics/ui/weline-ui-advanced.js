/* Weline UI source: js/components/advanced.js */
let registered = false;

export function register(UI) {
    if (registered) return;
    registered = true;

    UI.define('combobox', ({ element, listen, emit }) => {
        const input = element.querySelector('[role="combobox"]');
        const panel = element.querySelector('[role="listbox"]');
        if (!(input instanceof HTMLInputElement) || !(panel instanceof HTMLElement)) return {};
        const options = () => [...panel.querySelectorAll('[role="option"]')].filter((option) => !option.hidden);
        const open = () => { panel.hidden = false; input.setAttribute('aria-expanded', 'true'); };
        const close = () => { panel.hidden = true; input.setAttribute('aria-expanded', 'false'); };
        const choose = (option) => {
            input.value = option.getAttribute('data-value') || option.textContent.trim();
            options().forEach((candidate) => candidate.setAttribute('aria-selected', String(candidate === option)));
            emit('change', {value: input.value, option}, false);
            input.dispatchEvent(new Event('change', {bubbles: true}));
            close();
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
            if (event.key === 'Escape') close();
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && visible.length > 0) {
                event.preventDefault();
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
        listen(document, 'pointerdown', (event) => { if (!element.contains(event.target)) close(); });
        return {open, close, choose, element};
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

    UI.define('icon-picker', ({ element, listen, position, emit }) => {
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
        const open = () => {
            panel.hidden = false;
            panel.dataset.state = 'open';
            trigger.setAttribute('aria-expanded', 'true');
            position(trigger, panel);
            search?.focus();
        };
        const close = () => {
            panel.hidden = true;
            panel.dataset.state = 'closed';
            trigger.setAttribute('aria-expanded', 'false');
        };
        const choose = (value) => {
            input.value = value;
            sync();
            input.dispatchEvent(new Event('change', {bubbles: true}));
            emit('change', {value}, false);
            close();
        };
        listen(trigger, 'click', () => panel.hidden ? open() : close());
        listen(element, 'click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const option = target?.closest('[data-w-icon-value]');
            if (option) choose(option.dataset.wIconValue || '');
            if (target?.closest('[data-w-icon-clear]')) choose('');
        });
        if (search) listen(search, 'input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            options.forEach((option) => {
                option.hidden = query !== '' && !String(option.dataset.wIconValue).includes(query);
                if (!option.hidden) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
            position(trigger, panel);
        });
        listen(element, 'keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                trigger.focus();
            }
        });
        listen(document, 'pointerdown', (event) => { if (!element.contains(event.target)) close(); });
        sync();
        return {open, close, choose, element};
    });
}
