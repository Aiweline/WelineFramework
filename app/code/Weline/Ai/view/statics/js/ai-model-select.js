let modelListPromise = null;

function normalizeModels(response) {
    if (Array.isArray(response)) return response;
    if (Array.isArray(response?.data)) return response.data;
    if (Array.isArray(response?.data?.data)) return response.data.data;
    return [];
}

function loadModels() {
    if (!modelListPromise) {
        modelListPromise = window.Weline.load('api')
            .then((api) => api.resource('ai').listModels({}))
            .then(normalizeModels)
            .catch((error) => {
                modelListPromise = null;
                throw error;
            });
    }
    return modelListPromise;
}

function register(UI) {
    UI.define('ai-model-select', ({ element, listen, floating, emit }) => {
        const trigger = element.querySelector('[data-w-ai-model-trigger]');
        const panel = element.querySelector('[data-w-ai-model-panel]');
        const search = element.querySelector('[data-w-ai-model-search]');
        const supplier = element.querySelector('[data-w-ai-model-supplier]');
        const hidden = element.querySelector('[data-ai-model-value]');
        const display = element.querySelector('[data-w-ai-model-display]');
        const list = element.querySelector('[data-w-ai-model-list]');
        const status = element.querySelector('[data-w-ai-model-status]');
        if (!(trigger instanceof HTMLButtonElement)
            || !(panel instanceof HTMLElement)
            || !(search instanceof HTMLInputElement)
            || !(supplier instanceof HTMLSelectElement)
            || !(hidden instanceof HTMLInputElement)
            || !(display instanceof HTMLElement)
            || !(list instanceof HTMLElement)
            || !(status instanceof HTMLElement)) return {};

        const portal = floating.portal(panel, 'ai-model-select');
        const limit = Math.max(1, Number.parseInt(element.dataset.aiModelLimit || '50', 10) || 50);
        let models = null;

        const modelCode = (model) => String(model?.model_code || model?.code || '');
        const modelLabel = (model) => String(model?.name || modelCode(model));
        const modelSupplier = (model) => String(model?.supplier || '');
        const setStatus = (message = '', tone = 'neutral') => {
            status.textContent = message;
            status.dataset.tone = tone;
            status.hidden = message === '';
        };
        const close = (restoreFocus = false) => {
            if (panel.hidden) return false;
            panel.hidden = true;
            panel.dataset.state = 'closed';
            trigger.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-expanded', 'false');
            monitor.unobserve(panel);
            floating.clear(panel);
            portal.restore();
            if (restoreFocus) trigger.focus();
            return true;
        };
        const monitor = floating.monitor(
            trigger,
            () => panel,
            () => element.dataset.wPlacement || 'bottom-start',
            () => close(false),
        );
        const open = async () => {
            if (!panel.hidden) return;
            portal.mount();
            panel.hidden = false;
            panel.dataset.state = 'open';
            trigger.setAttribute('aria-expanded', 'true');
            search.setAttribute('aria-expanded', 'true');
            monitor.observe(panel);
            if (monitor.place()?.anchorVisible === false) {
                close(false);
                return;
            }
            search.focus();
            if (models) return;
            setStatus(element.dataset.aiModelLoading || 'Loading…');
            try {
                models = await loadModels();
                const suppliers = [...new Set(models.map(modelSupplier).filter(Boolean))].sort();
                for (const value of suppliers) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    supplier.append(option);
                }
                render();
                syncInitialSelection();
            } catch (_error) {
                setStatus(element.dataset.aiModelLoadFail || 'Unable to load models.', 'danger');
            }
        };
        const choose = (model) => {
            const code = modelCode(model);
            if (!code) return;
            hidden.value = code;
            hidden.setAttribute('value', code);
            display.textContent = modelSupplier(model)
                ? `${modelLabel(model)} (${modelSupplier(model)})`
                : modelLabel(model);
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            emit('change', { value: code, model }, false);
            close(true);
        };
        const render = () => {
            if (!models) return;
            const query = search.value.trim().toLocaleLowerCase();
            const selectedSupplier = supplier.value.trim().toLocaleLowerCase();
            const filtered = models.filter((model) => {
                const supplierName = modelSupplier(model).toLocaleLowerCase();
                if (selectedSupplier && supplierName !== selectedSupplier) return false;
                const haystack = `${modelLabel(model)} ${supplierName} ${modelCode(model)}`.toLocaleLowerCase();
                return query === '' || haystack.includes(query);
            }).slice(0, limit);
            list.replaceChildren();
            setStatus(filtered.length === 0 ? (element.dataset.aiModelNoMatch || 'No models found.') : '');
            for (const model of filtered) {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'w-combobox__option w-ai-model-select__option';
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(modelCode(model) === hidden.value));
                const copy = document.createElement('span');
                copy.className = 'w-ai-model-select__option-copy';
                const label = document.createElement('strong');
                label.textContent = modelLabel(model);
                const meta = document.createElement('small');
                meta.textContent = [modelSupplier(model), modelCode(model)].filter(Boolean).join(' · ');
                copy.append(label, meta);
                option.append(copy);
                option.addEventListener('click', () => choose(model), { once: true });
                list.append(option);
            }
            if (!panel.hidden) monitor.place();
        };
        const syncInitialSelection = () => {
            if (!models || hidden.value === '') return;
            const selected = models.find((model) => modelCode(model) === hidden.value);
            if (selected) {
                display.textContent = modelSupplier(selected)
                    ? `${modelLabel(selected)} (${modelSupplier(selected)})`
                    : modelLabel(selected);
            }
        };

        listen(trigger, 'click', () => panel.hidden ? open() : close());
        listen(search, 'input', render);
        listen(search, 'keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            }
        });
        listen(supplier, 'change', render);
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close();
        });
        return {
            open,
            close,
            element,
            destroy: () => {
                close(false);
                monitor.destroy();
                portal.destroy();
            },
        };
    });
    UI.mount(document);
}

if (window.Weline?.UI) register(window.Weline.UI);
else document.addEventListener('weline:ui:ready', () => register(window.Weline.UI), { once: true });
