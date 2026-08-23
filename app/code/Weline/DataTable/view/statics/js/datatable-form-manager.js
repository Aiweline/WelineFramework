import {
    button,
    normalizeField,
    parseConfig,
    request,
    responsePayload,
    translate,
    valueFor,
} from './datatable-common.js';

const Weline = window.Weline = window.Weline || {};

function registerDataTableForm(UI) {
    UI.define('data-table-form', ({element, listen}) => {
        const config = parseConfig(element);
        const form = document.getElementById(String(config.id || '')) || element.querySelector('form');
        const autoContainer = element.querySelector('[data-w-datatable-form-auto]');
        const message = element.querySelector('[data-w-datatable-form-message]');
        const title = element.querySelector('[data-w-datatable-form-title]');
        const state = {
            mode: config.mode === 'edit' ? 'edit' : 'add',
            recordId: config.recordId || '',
            fieldsLoaded: false,
            fieldsPromise: null,
            fields: [],
            destroyed: false,
            previewUrls: new Set(),
        };
        if (!(form instanceof HTMLFormElement)) return {state};

        const showMessage = (text, tone = '') => {
            if (!message) return;
            message.hidden = text === '';
            message.dataset.tone = tone;
            message.textContent = text;
        };

        const setBusy = (busy) => {
            element.setAttribute('aria-busy', String(busy));
            form.querySelectorAll('button, input, select, textarea').forEach((control) => {
                if (control instanceof HTMLButtonElement) control.disabled = busy;
            });
        };

        const clearErrors = () => {
            form.querySelectorAll('[aria-invalid="true"]').forEach((control) => control.removeAttribute('aria-invalid'));
            form.querySelectorAll('[data-w-field-error]').forEach((error) => {
                error.hidden = true;
                error.textContent = '';
            });
            showMessage('');
        };

        const revokePreviews = () => {
            for (const url of state.previewUrls) URL.revokeObjectURL(url);
            state.previewUrls.clear();
        };

        const reset = () => {
            revokePreviews();
            form.reset();
            clearErrors();
            form.querySelectorAll('[data-w-file-preview]').forEach((preview) => preview.replaceChildren());
        };

        const createControl = (field) => {
            const normalized = normalizeField(field);
            const wrapper = document.createElement('div');
            wrapper.className = 'w-field w-datatable-form__field';
            wrapper.dataset.field = normalized.name;
            wrapper.dataset.type = normalized.type;
            const id = `w-field-${config.id}-${normalized.name.replace(/[^A-Za-z0-9_-]/g, '-')}`;
            const label = document.createElement('label');
            label.className = 'w-field__label';
            label.htmlFor = id;
            label.textContent = normalized.label;
            if (field.required) {
                const required = document.createElement('span');
                required.className = 'w-datatable-form__required';
                required.setAttribute('aria-hidden', 'true');
                required.textContent = '*';
                label.append(required);
            }
            let control;
            if (normalized.type === 'textarea') {
                control = document.createElement('textarea');
                control.className = 'w-input';
                control.rows = 4;
            } else if (normalized.type === 'select') {
                control = document.createElement('select');
                control.className = 'w-select';
                const empty = document.createElement('option');
                empty.value = '';
                empty.textContent = translate('请选择');
                control.append(empty);
                for (const option of normalized.options) {
                    const item = document.createElement('option');
                    item.value = option.value;
                    item.textContent = option.label;
                    control.append(item);
                }
            } else if (['checkbox', 'switch'].includes(normalized.type)) {
                const checkLabel = document.createElement('label');
                checkLabel.className = normalized.type === 'switch' ? 'w-switch' : 'w-check';
                control = document.createElement('input');
                control.type = 'checkbox';
                control.value = '1';
                const text = document.createElement('span');
                text.textContent = normalized.label;
                checkLabel.append(control, text);
                wrapper.append(checkLabel);
            } else if (['file', 'image'].includes(normalized.type)) {
                control = document.createElement('input');
                control.type = 'file';
                control.hidden = true;
                if (normalized.options[0]?.value) control.accept = normalized.options[0].value;
                const fileBox = document.createElement('div');
                fileBox.className = 'w-datatable-form__file';
                const choose = button(
                    normalized.type === 'image' ? translate('选择图片') : translate('选择文件'),
                    {tone: 'neutral', formAction: 'file.choose', icon: normalized.type === 'image' ? 'image' : 'upload'},
                );
                choose.dataset.wTarget = `#${id}`;
                const preview = document.createElement('div');
                preview.className = 'w-datatable-form__file-preview';
                preview.dataset.wFilePreview = '';
                fileBox.append(choose, preview);
                wrapper.append(fileBox);
            } else {
                control = document.createElement('input');
                control.type = normalized.type === 'datetime' ? 'datetime-local' : (
                    ['text', 'search', 'email', 'tel', 'url', 'password', 'number', 'date', 'time', 'range', 'color'].includes(normalized.type)
                        ? normalized.type
                        : 'text'
                );
                control.className = 'w-input';
            }
            control.id = id;
            control.name = normalized.name;
            control.required = field.required === true;
            control.readOnly = field.readonly === true;
            control.disabled = field.disabled === true;
            if ('placeholder' in control) control.placeholder = String(field.placeholder || normalized.label || '');
            for (const attribute of ['min', 'max', 'step', 'maxlength']) {
                if (field[attribute] !== undefined && field[attribute] !== null && field[attribute] !== '') {
                    control.setAttribute(attribute, String(field[attribute]));
                }
            }
            if (!wrapper.contains(control)) wrapper.append(label, control);
            else wrapper.prepend(label);
            const error = document.createElement('div');
            error.className = 'w-field__error';
            error.dataset.wFieldError = '';
            error.hidden = true;
            wrapper.append(error);
            return wrapper;
        };

        const manualFields = () => [...form.elements]
            .filter((control) => control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)
            .map((control) => control.name)
            .filter(Boolean);

        const loadFields = () => {
            if (!config.autoFields || !autoContainer) {
                state.fieldsLoaded = true;
                return Promise.resolve();
            }
            if (state.fieldsPromise) return state.fieldsPromise;
            state.fieldsPromise = request(config, 'formFields', {
                form_id: config.id,
                model: config.model,
                scope: config.scope,
                exclude_fields: config.excludeFields || [],
                include_fields: config.includeFields || [],
                manual_fields: manualFields(),
                model_config: config.modelConfig || {},
            }).then((response) => {
                if (state.destroyed) return;
                const payload = responsePayload(response);
                state.fields = Array.isArray(payload.fields) ? payload.fields.map(normalizeField) : [];
                const fragment = document.createDocumentFragment();
                for (const field of state.fields) fragment.append(createControl(field));
                autoContainer.replaceChildren(fragment);
                state.fieldsLoaded = true;
            }).catch((error) => {
                showMessage(error instanceof Error ? error.message : String(error), 'danger');
                throw error;
            });
            return state.fieldsPromise;
        };

        const setValue = (control, value) => {
            if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                control.checked = value === true || value === 1 || value === '1';
            } else if (control instanceof HTMLInputElement && control.type === 'radio') {
                control.checked = String(control.value) === String(value ?? '');
            } else if (!(control instanceof HTMLInputElement && control.type === 'file')) {
                control.value = value == null ? '' : String(value);
            }
        };

        const fill = (record) => {
            for (const control of form.elements) {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) || !control.name) continue;
                setValue(control, valueFor(record, control.name));
            }
        };

        const loadRecord = async (recordId) => {
            const response = await request(config, 'formRecord', {
                model: config.model,
                record_id: recordId,
                model_config: config.modelConfig || {},
            });
            const payload = responsePayload(response);
            return payload.record || payload.data || {};
        };

        const open = async (mode = 'add', recordId = '', record = null) => {
            state.mode = mode === 'edit' ? 'edit' : 'add';
            state.recordId = recordId || '';
            reset();
            if (title) title.textContent = state.mode === 'edit' ? translate('编辑记录') : translate('新增记录');
            if (element instanceof HTMLDialogElement) UI.dialog.open(element);
            else element.scrollIntoView({block: 'start', behavior: 'smooth'});
            setBusy(true);
            try {
                await loadFields();
                if (state.mode === 'edit') fill(record || await loadRecord(state.recordId));
                setBusy(false);
                const first = form.querySelector('input:not([type="hidden"]), select, textarea');
                if (first instanceof HTMLElement) first.focus({preventScroll: true});
            } catch (error) {
                setBusy(false);
                showMessage(error instanceof Error ? error.message : String(error), 'danger');
            }
        };

        const collect = () => {
            const result = {};
            for (const control of form.elements) {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) || !control.name || control.disabled) continue;
                if (control instanceof HTMLInputElement && control.type === 'radio' && !control.checked) continue;
                if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                    result[control.name] = control.checked ? 1 : 0;
                } else if (control instanceof HTMLInputElement && control.type === 'file') {
                    const files = [...(control.files || [])].map((file) => ({
                        name: file.name,
                        size: file.size,
                        type: file.type,
                        lastModified: file.lastModified,
                    }));
                    result[control.name] = control.multiple ? files : (files[0] || '');
                } else {
                    result[control.name] = control.value;
                }
            }
            return result;
        };

        const submit = async () => {
            clearErrors();
            if (!form.checkValidity()) {
                form.reportValidity();
                const invalid = form.querySelector(':invalid');
                if (invalid instanceof HTMLElement) invalid.setAttribute('aria-invalid', 'true');
                return;
            }
            setBusy(true);
            try {
                const data = collect();
                const operation = state.mode === 'edit' ? 'update' : 'create';
                await request(config, operation, {
                    model: config.model,
                    id: state.recordId || undefined,
                    record_id: state.recordId || undefined,
                    data,
                    dependencies: config.dependencies || '',
                    transaction: config.transaction === true,
                    model_config: config.modelConfig || {},
                });
                setBusy(false);
                UI.toast.success(state.mode === 'edit' ? translate('记录已更新。') : translate('记录已创建。'));
                element.dispatchEvent(new CustomEvent('weline:datatable:form:saved', {
                    bubbles: true,
                    detail: {formId: config.id, mode: state.mode, recordId: state.recordId},
                }));
                if (element instanceof HTMLDialogElement) UI.dialog.close(element, 'saved');
                else reset();
            } catch (error) {
                setBusy(false);
                showMessage(error instanceof Error ? error.message : String(error), 'danger');
            }
        };

        const renderFilePreview = (input) => {
            const wrapper = input.closest('.w-datatable-form__field');
            const preview = wrapper?.querySelector('[data-w-file-preview]');
            if (!preview) return;
            preview.replaceChildren();
            for (const file of input.files || []) {
                if (file.type.startsWith('image/')) {
                    const url = URL.createObjectURL(file);
                    state.previewUrls.add(url);
                    const image = document.createElement('img');
                    image.src = url;
                    image.alt = file.name;
                    preview.append(image);
                }
                const text = document.createElement('span');
                text.textContent = `${file.name} (${Math.ceil(file.size / 1024)} KB)`;
                preview.append(text);
            }
        };

        listen(form, 'submit', (event) => {
            event.preventDefault();
            void submit();
        });
        listen(form, 'reset', () => queueMicrotask(clearErrors));
        listen(element, 'click', (event) => {
            const action = event.target instanceof Element ? event.target.closest('[data-w-datatable-form-action]') : null;
            if (!(action instanceof HTMLElement)) return;
            if (action.dataset.wDatatableFormAction === 'file.choose') {
                const target = action.dataset.wTarget || '';
                const input = target ? element.querySelector(target) : null;
                if (input instanceof HTMLInputElement && input.type === 'file') input.click();
            }
        });
        listen(form, 'change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'file') renderFilePreview(event.target);
        });
        if (element instanceof HTMLDialogElement) {
            listen(element, 'weline:ui:dialog:close', revokePreviews);
        }

        queueMicrotask(() => void loadFields());
        return {
            state,
            open,
            reset,
            submit,
            destroy() {
                state.destroyed = true;
                revokePreviews();
            },
        };
    });
}
function register() {
    if (!Weline.UI) return;
    registerDataTableForm(Weline.UI);
    Weline.UI.mount(document);
}

if (Weline.UI) register();
else document.addEventListener('weline:ui:ready', register, {once: true});

export {register};
