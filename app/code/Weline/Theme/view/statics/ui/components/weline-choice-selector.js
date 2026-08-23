/* Weline UI source: js/header-choice-selector.js */
function register(UI) {
    UI.define('choice-filter', ({ element, listen, position }) => {
        const panel = element.querySelector('[data-w-choice-panel]');
        const input = element.querySelector('[data-w-choice-search]');
        const items = element.querySelector('[data-w-choice-items]');
        const more = element.querySelector('[data-w-choice-more]');
        const empty = element.querySelector('[data-w-choice-empty]');
        const trigger = element.querySelector('[data-w-menu-trigger]');
        if (!(panel instanceof HTMLElement) || !(items instanceof HTMLElement)) return {};
        const pageSize = Math.max(1, Number.parseInt(element.dataset.wChoicePageSize || '50', 10) || 50);
        let visibleLimit = pageSize;
        const options = () => [...items.querySelectorAll('[data-w-choice-option]')];
        const render = (reset = false) => {
            if (reset) visibleLimit = pageSize;
            const query = input instanceof HTMLInputElement ? input.value.trim().toLocaleLowerCase() : '';
            let matched = 0;
            let visible = 0;
            for (const option of options()) {
                const haystack = `${option.dataset.search || ''} ${option.textContent || ''}`.toLocaleLowerCase();
                const match = query === '' || haystack.includes(query);
                matched += match ? 1 : 0;
                const show = match && visible < visibleLimit;
                option.hidden = !show;
                if (show) visible++;
            }
            if (empty instanceof HTMLElement) empty.hidden = matched !== 0;
            if (more instanceof HTMLButtonElement) more.hidden = matched <= visible;
            if (!panel.hidden && trigger instanceof HTMLElement) position(trigger, panel, 'bottom-end');
        };
        if (input instanceof HTMLInputElement) {
            listen(input, 'input', () => render(true));
            listen(input, 'keydown', (event) => {
                if (event.key === 'Escape') trigger?.focus();
            });
        }
        if (more instanceof HTMLButtonElement) listen(more, 'click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            visibleLimit += pageSize;
            render();
        });
        listen(element, 'weline:ui:menu:open', () => {
            render();
            input?.focus();
        });
        render(true);
        return { render, element };
    });
    UI.mount(document);
}

if (window.Weline?.UI) register(window.Weline.UI);
else document.addEventListener('weline:ui:ready', () => register(window.Weline.UI), { once: true });
