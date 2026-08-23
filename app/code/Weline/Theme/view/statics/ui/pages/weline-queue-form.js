/* Weline UI source: js/queue-form.js */
const UI = window.Weline?.UI;

if (UI) {
    UI.define('queue-form', ({ element, listen }) => {
        const configNode = document.querySelector('[data-w-queue-form-config]');
        const form = element.querySelector('[data-w-queue-form]');
        const panels = [...element.querySelectorAll('[data-w-queue-panel]')];
        const stepButtons = [...element.querySelectorAll('[data-w-queue-step]')];
        const progress = element.querySelector('[data-w-queue-progress]');
        const typeSearch = element.querySelector('[data-w-queue-type-search]');
        const typeList = element.querySelector('[data-w-queue-types]');
        const typeTip = element.querySelector('[data-w-queue-type-tip]');
        const typeName = element.querySelector('[data-w-queue-type-name]');
        const typeDescription = element.querySelector('[data-w-queue-type-description]');
        const typeClass = element.querySelector('[data-w-queue-type-class]');
        const nameInput = element.querySelector('[data-w-queue-name]');
        const bizKeyInput = element.querySelector('[data-w-queue-biz-key]');
        const attributesRegion = element.querySelector('[data-w-queue-attributes]');
        const dependenceError = element.querySelector('[data-w-queue-dependence-error]');
        const retryDependence = element.querySelector('[data-w-queue-retry-dependence]');
        const summary = element.querySelector('[data-w-queue-summary]');
        const previousButton = element.querySelector('[data-w-queue-previous]');
        const nextButton = element.querySelector('[data-w-queue-next]');
        const submitButton = element.querySelector('[data-w-queue-submit]');

        if (!(form instanceof HTMLFormElement)
            || !(typeList instanceof HTMLElement)
            || !(attributesRegion instanceof HTMLElement)
            || !(nameInput instanceof HTMLInputElement)
            || !(bizKeyInput instanceof HTMLInputElement)) return {};

        let config = {};
        try {
            config = JSON.parse(configNode?.textContent || '{}');
        } catch (_error) {
            config = {};
        }

        const boot = config.boot || {};
        const messages = config.messages || {};
        const state = {
            destroyed: false,
            step: 0,
            maxStep: 0,
            types: [],
            selectedType: null,
            attributes: [],
            controls: new Map(),
            attributesReady: false,
            loadingAttributes: false,
            submitting: false,
            generation: 0,
            run: null,
            searchSequence: 0,
            searchTimer: 0,
        };
        let resourcePromise = null;

        const messageFor = (error, fallback = '') => {
            const candidates = [
                error?.response?.data?.data?.msg,
                error?.response?.data?.data?.message,
                error?.data?.msg,
                error?.data?.message,
                error?.msg,
                error?.message,
                fallback,
                messages.apiUnavailable,
            ];
            return String(candidates.find((value) => typeof value === 'string' && value.trim() !== '') || 'Error');
        };
        const resource = () => {
            resourcePromise ||= window.Weline.load('api')
                .then((api) => api.resource('queue_admin'))
                .catch((error) => {
                    resourcePromise = null;
                    throw error;
                });
            return resourcePromise;
        };
        const call = async (operation, params = {}) => {
            const api = await resource();
            if (!api || typeof api[operation] !== 'function') {
                throw new Error(messages.apiUnavailable || 'Queue API unavailable.');
            }
            const response = await api[operation](params, { keepBusinessResult: true, silent: true });
            if (!response || response.success !== true) {
                throw new Error(messageFor(response, messages.apiUnavailable));
            }
            return response;
        };
        const icon = (name, size = 'sm') => UI.icon.create(name, { size });
        const text = (tag, className, value) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            node.textContent = String(value ?? '');
            return node;
        };
        const asPositiveInt = (value) => {
            const number = Number.parseInt(String(value ?? ''), 10);
            return Number.isFinite(number) && number > 0 ? number : 0;
        };
        const truthy = (value) => value === true || value === 1 || value === '1' || value === 'true';
        const hasValue = (value) => Array.isArray(value)
            ? value.length > 0
            : value !== null && value !== undefined && String(value).trim() !== '';
        const normalizeType = (value) => ({
            type_id: asPositiveInt(value?.type_id),
            name: String(value?.name || ''),
            module_name: String(value?.module_name || ''),
            class: String(value?.class || ''),
            tip: String(value?.tip || ''),
        });
        const setBusy = (target, busy) => {
            if (!(target instanceof HTMLElement)) return;
            if (busy) target.setAttribute('aria-busy', 'true');
            else target.removeAttribute('aria-busy');
        };
        const dialogError = (title, error) => UI.dialog.request({
            tone: 'danger',
            title: String(title || messages.saveFailed || 'Error'),
            message: messageFor(error),
            confirmLabel: messages.confirm || 'OK',
        });

        const renderStatus = (message, options = {}) => {
            const box = document.createElement('div');
            box.className = options.empty ? 'w-empty w-queue-form__empty' : 'w-queue-form__loading';
            if (options.spinner) {
                const spinner = document.createElement('span');
                spinner.className = 'w-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                box.append(spinner);
            } else {
                box.append(icon(options.tone === 'danger' ? 'warning' : 'info', 'lg'));
            }
            box.append(text('span', '', message));
            if (typeof options.retry === 'string') {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'w-button';
                retry.dataset.size = 'sm';
                retry.dataset.wQueueReloadAttributes = '';
                retry.textContent = options.retry;
                box.append(retry);
            }
            return box;
        };

        const selectedTypeById = (id) => state.types.find((item) => item.type_id === id)
            || (state.selectedType?.type_id === id ? state.selectedType : null);
        const showTypeTip = (type) => {
            if (!(typeTip instanceof HTMLElement)) return;
            typeTip.hidden = !type;
            if (typeName instanceof HTMLElement) typeName.textContent = type?.name || '';
            if (typeDescription instanceof HTMLElement) typeDescription.textContent = type?.tip || '';
            if (typeClass instanceof HTMLElement) typeClass.textContent = type?.class || '';
        };
        const renderTypes = (values) => {
            state.types = (Array.isArray(values) ? values : [])
                .map(normalizeType)
                .filter((item) => item.type_id > 0);
            typeList.replaceChildren();
            if (state.types.length === 0) {
                typeList.append(renderStatus(messages.noTypes || 'No queue types.', { empty: true }));
                return;
            }
            for (const type of state.types) {
                const card = document.createElement('label');
                card.className = 'w-queue-form__type';
                const input = document.createElement('input');
                input.className = 'w-visually-hidden';
                input.type = 'radio';
                input.name = 'weline_queue_type';
                input.value = String(type.type_id);
                input.dataset.wQueueTypeChoice = '';
                input.checked = state.selectedType?.type_id === type.type_id;

                const heading = document.createElement('span');
                heading.className = 'w-queue-form__type-heading';
                const name = text('span', 'w-queue-form__type-name', type.name || `#${type.type_id}`);
                heading.append(name);
                if (type.module_name) {
                    const module = text('span', 'w-badge', type.module_name);
                    module.dataset.tone = 'info';
                    heading.append(module);
                }
                const className = text('code', 'w-queue-form__type-class', type.class);
                card.append(input, heading, className);
                typeList.append(card);
            }
        };

        const controlValue = (control) => {
            if (control instanceof HTMLSelectElement && control.multiple) {
                return [...control.selectedOptions].map((option) => option.value);
            }
            if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                return control.checked ? 1 : 0;
            }
            return 'value' in control ? control.value : '';
        };
        const assignControlValue = (control, value) => {
            if (control instanceof HTMLSelectElement && control.multiple) {
                const selected = Array.isArray(value)
                    ? value.map(String)
                    : String(value ?? '').split(',').map((item) => item.trim()).filter(Boolean);
                [...control.options].forEach((option) => { option.selected = selected.includes(option.value); });
                return;
            }
            if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                control.checked = truthy(value);
                return;
            }
            control.value = value === null || value === undefined ? '' : String(value);
        };
        const normalizedOptions = (values) => {
            if (Array.isArray(values)) {
                return values.map((item, index) => {
                    if (item && typeof item === 'object') {
                        return { value: String(item.value ?? item.code ?? index), label: String(item.label ?? item.value ?? item.code ?? '') };
                    }
                    return { value: String(index), label: String(item ?? '') };
                });
            }
            if (!values || typeof values !== 'object') return [];
            return Object.entries(values).map(([value, label]) => ({ value: String(value), label: String(label ?? '') }));
        };
        const replaceSelectOptions = (select, values, selectedValue) => {
            if (!(select instanceof HTMLSelectElement)) return;
            const options = normalizedOptions(values);
            select.replaceChildren();
            if (!select.multiple) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = select.dataset.wPlaceholder || '';
                select.append(placeholder);
            }
            for (const item of options) {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                select.append(option);
            }
            assignControlValue(select, selectedValue);
        };
        const createControl = (attribute, index) => {
            const descriptor = attribute?.control && typeof attribute.control === 'object' ? attribute.control : {};
            const kind = String(descriptor.kind || 'text').toLowerCase();
            const id = `w-queue-attribute-${state.generation}-${index}`;
            let control;
            if (kind === 'select') {
                control = document.createElement('select');
                control.className = 'w-select';
                control.multiple = Boolean(descriptor.multiple);
                control.dataset.wPlaceholder = String(descriptor.placeholder || '');
                replaceSelectOptions(control, descriptor.options, attribute.value);
            } else if (kind === 'textarea') {
                control = document.createElement('textarea');
                control.className = 'w-textarea';
                control.placeholder = String(descriptor.placeholder || '');
                assignControlValue(control, attribute.value);
            } else {
                control = document.createElement('input');
                control.className = kind === 'checkbox' ? '' : 'w-input';
                control.type = kind === 'checkbox' ? 'checkbox' : [
                    'color', 'date', 'datetime-local', 'email', 'number', 'tel', 'time', 'url',
                ].includes(kind) ? kind : 'text';
                if (kind !== 'checkbox') control.placeholder = String(descriptor.placeholder || '');
                assignControlValue(control, attribute.value);
            }
            control.id = id;
            control.name = String(attribute.code || '');
            control.dataset.wQueueAttributeControl = String(attribute.code || '');
            control.required = Boolean(attribute.required);
            return control;
        };
        const renderAttributes = (values) => {
            state.attributes = (Array.isArray(values) ? values : []).filter((item) => item && typeof item === 'object' && String(item.code || '') !== '');
            state.controls = new Map();
            attributesRegion.replaceChildren();
            if (state.attributes.length === 0) {
                attributesRegion.append(renderStatus(messages.noAttributes || 'No additional attributes.', { empty: true }));
                return;
            }
            state.attributes.forEach((attribute, index) => {
                attribute.code = String(attribute.code || '');
                attribute.name = String(attribute.name || attribute.code);
                attribute.dependence = Array.isArray(attribute.dependence)
                    ? [...new Set(attribute.dependence.map(String).filter(Boolean))]
                    : [];
                const wrapper = document.createElement('div');
                wrapper.className = 'w-field w-queue-form__attribute';
                wrapper.dataset.wQueueAttribute = attribute.code;
                wrapper.dataset.required = Boolean(attribute.required) ? 'true' : 'false';
                const kind = String(attribute.control?.kind || 'text');
                if (kind === 'textarea' || Boolean(attribute.control?.multiple)) wrapper.dataset.wide = 'true';

                const label = text('label', 'w-field__label', attribute.name);
                const control = createControl(attribute, index);
                label.htmlFor = control.id;
                if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                    const check = document.createElement('label');
                    check.className = 'w-check';
                    check.htmlFor = control.id;
                    check.append(control, text('span', '', attribute.name));
                    wrapper.append(label, check);
                } else {
                    wrapper.append(label, control);
                }
                const status = document.createElement('small');
                status.className = 'w-field__hint w-queue-form__attribute-status';
                status.dataset.wQueueAttributeStatus = '';
                status.setAttribute('aria-live', 'polite');
                wrapper.append(status);
                attributesRegion.append(wrapper);
                state.controls.set(attribute.code, { attribute, wrapper, control, status });
            });
        };

        const updateDependenceGate = () => {
            const run = state.run;
            const pending = run?.pending?.size || 0;
            const failed = run?.failed?.size || 0;
            if (dependenceError instanceof HTMLElement) dependenceError.hidden = failed === 0;
            if (retryDependence instanceof HTMLButtonElement) retryDependence.disabled = failed === 0 || pending > 0;
            if (nextButton instanceof HTMLButtonElement) {
                nextButton.disabled = state.submitting || state.loadingAttributes || (state.step === 1 && (pending > 0 || failed > 0));
            }
            if (submitButton instanceof HTMLButtonElement) submitButton.disabled = state.submitting || pending > 0 || failed > 0;
        };
        const dependenciesActive = (attribute) => attribute.dependence.every((code) => {
            const source = state.controls.get(code)?.control;
            return source ? hasValue(controlValue(source)) : false;
        });
        const markDependentInactive = (entry) => {
            if (state.run) {
                state.run.versions.set(
                    entry.attribute.code,
                    (state.run.versions.get(entry.attribute.code) || 0) + 1,
                );
            }
            entry.control.disabled = true;
            entry.wrapper.removeAttribute('aria-busy');
            entry.status.textContent = '';
            entry.status.className = 'w-field__hint w-queue-form__attribute-status';
            state.run?.failed.delete(entry.attribute.code);
            updateDependenceGate();
        };
        const resolveDependence = async (targetCode, sourceCode) => {
            const run = state.run;
            const entry = state.controls.get(targetCode);
            const source = state.controls.get(sourceCode)?.control;
            if (!run || !entry || !source || !entry.attribute.dependence.includes(sourceCode)) return;
            if (!dependenciesActive(entry.attribute)) {
                markDependentInactive(entry);
                return;
            }

            const version = (run.versions.get(targetCode) || 0) + 1;
            run.versions.set(targetCode, version);
            const token = Symbol(targetCode);
            run.pending.add(token);
            run.failed.delete(targetCode);
            entry.control.disabled = true;
            entry.wrapper.setAttribute('aria-busy', 'true');
            entry.status.textContent = messages.dependenceLoading || 'Loading…';
            updateDependenceGate();

            try {
                const response = await call('resolveAttributeDependence', {
                    type_id: state.selectedType?.type_id || 0,
                    attribute: targetCode,
                    dependence_attribute: sourceCode,
                    dependence_value: controlValue(source),
                    attribute_value: controlValue(entry.control),
                });
                if (state.run !== run || run.versions.get(targetCode) !== version) return;
                const currentValue = controlValue(entry.control);
                replaceSelectOptions(entry.control, response.data, currentValue);
                entry.control.disabled = false;
                entry.status.textContent = '';
                entry.status.className = 'w-field__hint w-queue-form__attribute-status';
                run.failed.delete(targetCode);
            } catch (error) {
                if (state.run !== run || run.versions.get(targetCode) !== version) return;
                entry.control.disabled = true;
                entry.status.textContent = messageFor(error, messages.dependenceFailed);
                entry.status.className = 'w-field__error w-queue-form__attribute-status';
                run.failed.set(targetCode, { targetCode, sourceCode });
            } finally {
                run.pending.delete(token);
                if (state.run === run) {
                    if (run.versions.get(targetCode) === version) entry.wrapper.removeAttribute('aria-busy');
                    updateDependenceGate();
                }
            }
        };
        const initializeDependencies = () => {
            for (const entry of state.controls.values()) {
                if (entry.attribute.dependence.length === 0) continue;
                const sourceCode = entry.attribute.dependence.find((code) => state.controls.has(code));
                if (!sourceCode || !dependenciesActive(entry.attribute)) {
                    markDependentInactive(entry);
                    continue;
                }
                resolveDependence(entry.attribute.code, sourceCode);
            }
        };

        const loadAttributes = async (typeId) => {
            state.generation += 1;
            const run = {
                generation: state.generation,
                pending: new Set(),
                failed: new Map(),
                versions: new Map(),
            };
            state.run = run;
            state.attributesReady = false;
            state.loadingAttributes = true;
            attributesRegion.replaceChildren(renderStatus(messages.loadingAttributes || 'Loading…', { spinner: true }));
            updateDependenceGate();
            try {
                const response = await call('typeAttributes', {
                    type_id: typeId,
                    queue_id: asPositiveInt(boot.queueId),
                });
                if (state.run !== run || state.destroyed) return;
                renderAttributes(response.data);
                state.attributesReady = true;
                initializeDependencies();
            } catch (error) {
                if (state.run !== run || state.destroyed) return;
                state.attributes = [];
                state.controls = new Map();
                attributesRegion.replaceChildren(renderStatus(messageFor(error), {
                    tone: 'danger',
                    retry: messages.retry || 'Retry',
                }));
            } finally {
                if (state.run === run) {
                    state.loadingAttributes = false;
                    updateDependenceGate();
                }
            }
        };

        const selectType = (id) => {
            const typeId = asPositiveInt(id);
            if (typeId <= 0) return;
            const type = selectedTypeById(typeId) || { type_id: typeId, name: `#${typeId}`, module_name: '', class: '', tip: '' };
            const changed = state.selectedType?.type_id !== typeId;
            state.selectedType = type;
            typeList.querySelectorAll('[data-w-queue-type-choice]').forEach((input) => {
                input.checked = asPositiveInt(input.value) === typeId;
            });
            showTypeTip(type);
            if (changed) {
                state.maxStep = 0;
                if (nameInput.value.trim() === '') nameInput.value = type.name;
                loadAttributes(typeId);
            }
            updateSteps();
        };

        const searchTypes = async (query) => {
            const sequence = ++state.searchSequence;
            typeList.setAttribute('aria-busy', 'true');
            try {
                const response = await call('searchTypes', {
                    q: String(query || '').trim(),
                    module: String(boot.module || ''),
                    dir: String(boot.dir || ''),
                });
                if (sequence !== state.searchSequence || state.destroyed) return;
                renderTypes(response.data);
            } catch (error) {
                if (sequence === state.searchSequence && !state.destroyed) UI.toast.error(messageFor(error));
            } finally {
                if (sequence === state.searchSequence) typeList.setAttribute('aria-busy', 'false');
            }
        };

        const updateSteps = () => {
            panels.forEach((panel, index) => { panel.hidden = index !== state.step; });
            stepButtons.forEach((button, index) => {
                if (index === state.step) button.setAttribute('aria-current', 'step');
                else button.removeAttribute('aria-current');
                button.dataset.state = index < state.step ? 'complete' : index === state.step ? 'current' : 'pending';
                button.disabled = index > state.maxStep;
            });
            if (progress instanceof HTMLElement) progress.style.setProperty('--w-progress', `${((state.step + 1) / 3) * 100}%`);
            if (previousButton instanceof HTMLButtonElement) previousButton.hidden = state.step === 0;
            if (nextButton instanceof HTMLButtonElement) nextButton.hidden = state.step === 2;
            if (submitButton instanceof HTMLButtonElement) submitButton.hidden = state.step !== 2;
            updateDependenceGate();
        };
        const setStep = (nextStep) => {
            const normalized = Math.max(0, Math.min(2, Number(nextStep) || 0));
            if (normalized > state.maxStep) return;
            state.step = normalized;
            updateSteps();
            const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            element.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
        };
        const validateParameters = () => {
            if (!form.reportValidity()) return false;
            const pending = state.run?.pending?.size || 0;
            const failed = state.run?.failed?.size || 0;
            if (pending > 0) {
                UI.toast.info(messages.dependenceLoading || 'Loading…');
                return false;
            }
            if (failed > 0) {
                UI.toast.warning(messages.dependenceFailed || 'Dependency resolution failed.');
                return false;
            }
            return true;
        };
        const attributesPayload = () => [...state.controls.values()].map(({ attribute, control }) => ({
            code: attribute.code,
            value: controlValue(control),
        }));
        const summaryValue = (entry) => {
            const value = controlValue(entry.control);
            if (entry.control instanceof HTMLInputElement && entry.control.type === 'checkbox') {
                return value ? (messages.yes || 'Yes') : (messages.no || 'No');
            }
            if (entry.control instanceof HTMLSelectElement) {
                const labels = [...entry.control.selectedOptions].map((option) => option.textContent || option.value).filter(Boolean);
                return labels.join(', ') || messages.none || 'None';
            }
            return hasValue(value) ? String(value) : messages.none || 'None';
        };
        const appendSummary = (label, value) => {
            summary?.append(text('dt', '', label), text('dd', '', value));
        };
        const buildSummary = () => {
            if (!(summary instanceof HTMLElement)) return;
            summary.replaceChildren();
            appendSummary(messages.typeLabel || 'Queue type', state.selectedType?.name || messages.none || 'None');
            appendSummary(messages.nameLabel || 'Queue name', nameInput.value.trim() || messages.none || 'None');
            appendSummary(messages.bizKeyLabel || 'Business key', bizKeyInput.value.trim() || messages.none || 'None');
            for (const entry of state.controls.values()) appendSummary(entry.attribute.name, summaryValue(entry));
        };
        const next = () => {
            if (state.step === 0) {
                if (!state.selectedType) {
                    UI.toast.warning(messages.selectType || 'Select a queue type.');
                    typeSearch?.focus();
                    return;
                }
                if (state.loadingAttributes || !state.attributesReady) {
                    UI.toast.info(messages.loadingAttributes || 'Loading…');
                    return;
                }
                state.maxStep = Math.max(state.maxStep, 1);
                setStep(1);
                nameInput.focus();
                return;
            }
            if (state.step === 1 && validateParameters()) {
                buildSummary();
                state.maxStep = 2;
                setStep(2);
            }
        };

        const submit = async () => {
            if (state.submitting || !state.selectedType || !validateParameters()) return;
            state.submitting = true;
            updateDependenceGate();
            const savingToast = UI.toast.info(messages.saving || 'Saving…', { duration: 0 });
            try {
                const response = await call('save', {
                    queue_id: asPositiveInt(boot.queueId),
                    type_id: state.selectedType.type_id,
                    name: nameInput.value.trim(),
                    biz_key: bizKeyInput.value.trim(),
                    attributes: attributesPayload(),
                });
                const resultUrl = new URL(String(config.resultUrl || ''), window.location.href);
                if (resultUrl.origin !== window.location.origin) throw new Error(messages.apiUnavailable || 'Invalid result URL.');
                resultUrl.searchParams.set('msg', String(response.msg || messages.saved || 'Saved.'));
                const warnings = Array.isArray(response.warnings) ? response.warnings.map(String).filter(Boolean) : [];
                if (warnings.length > 0) resultUrl.searchParams.set('content', warnings.join('\n'));
                resultUrl.searchParams.set('reload', '1');
                resultUrl.searchParams.set('time', '0');
                window.location.assign(resultUrl.href);
            } catch (error) {
                savingToast.close();
                state.submitting = false;
                updateDependenceGate();
                await dialogError(messages.saveFailed || 'Save failed', error);
            }
        };

        listen(typeList, 'change', (event) => {
            const input = event.target instanceof Element ? event.target.closest('[data-w-queue-type-choice]') : null;
            if (input instanceof HTMLInputElement && input.checked) selectType(input.value);
        });
        if (typeSearch instanceof HTMLInputElement) {
            listen(typeSearch, 'input', () => {
                window.clearTimeout(state.searchTimer);
                state.searchTimer = window.setTimeout(() => searchTypes(typeSearch.value), 250);
            });
        }
        listen(attributesRegion, 'change', (event) => {
            const control = event.target instanceof Element ? event.target.closest('[data-w-queue-attribute-control]') : null;
            if (!control) return;
            state.maxStep = Math.min(state.maxStep, 1);
            const sourceCode = control.dataset.wQueueAttributeControl || '';
            for (const entry of state.controls.values()) {
                if (entry.attribute.dependence.includes(sourceCode)) resolveDependence(entry.attribute.code, sourceCode);
            }
        });
        listen(attributesRegion, 'click', (event) => {
            const retry = event.target instanceof Element ? event.target.closest('[data-w-queue-reload-attributes]') : null;
            if (retry) loadAttributes(state.selectedType?.type_id || 0);
        });
        listen(form, 'input', () => {
            if (state.step < 2) state.maxStep = Math.min(state.maxStep, 1);
        });
        if (retryDependence instanceof HTMLButtonElement) {
            listen(retryDependence, 'click', () => {
                const failures = [...(state.run?.failed?.values() || [])];
                for (const failure of failures) resolveDependence(failure.targetCode, failure.sourceCode);
            });
        }
        if (previousButton instanceof HTMLButtonElement) listen(previousButton, 'click', () => setStep(state.step - 1));
        if (nextButton instanceof HTMLButtonElement) listen(nextButton, 'click', next);
        stepButtons.forEach((button, index) => listen(button, 'click', () => setStep(index)));
        listen(form, 'submit', (event) => {
            event.preventDefault();
            submit();
        });

        renderTypes(boot.types);
        updateSteps();
        const initialTypeId = asPositiveInt(boot.typeId);
        if (initialTypeId > 0) selectType(initialTypeId);
        if (state.types.length === 0) searchTypes('');

        return {
            destroy() {
                state.destroyed = true;
                state.generation += 1;
                state.run = null;
                state.searchSequence += 1;
                window.clearTimeout(state.searchTimer);
            },
        };
    });
}
