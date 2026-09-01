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
        const capabilities = {create: false, update: false, ...(config.capabilities || {})};
        const planDialog = document.getElementById(String(config.planDialogId || ''));
        const planConfirm = planDialog?.querySelector('[data-w-datatable-write-plan-confirm]');
        const busyControls = new Map();
        const state = {
            mode: config.mode === 'edit' ? 'edit' : 'add',
            recordId: config.recordId || '',
            fieldsLoaded: false,
            fieldsPromise: null,
            fields: [],
            destroyed: false,
            previewUrls: new Set(),
            planToken: '',
            pendingPayload: null,
        };
        if (!(form instanceof HTMLFormElement)) return {state};

        const showMessage = (text, tone = '') => {
            if (!message) return;
            message.hidden = text === '';
            message.dataset.tone = tone;
            message.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
            message.textContent = text;
        };

        const setBusy = (busy) => {
            element.setAttribute('aria-busy', String(busy));
            element.querySelectorAll('button, input, select, textarea').forEach((control) => {
                if (!(control instanceof HTMLButtonElement || control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
                if (busy) {
                    if (!busyControls.has(control)) busyControls.set(control, control.disabled);
                    control.disabled = true;
                } else if (busyControls.has(control)) {
                    control.disabled = busyControls.get(control) === true;
                    busyControls.delete(control);
                }
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
                resource: config.resource || '',
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
                if (config.readOnly === true) {
                    autoContainer.querySelectorAll('input, select, textarea, button').forEach((control) => {
                        control.disabled = true;
                    });
                }
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
                resource: config.resource || '',
                record_id: recordId,
                model_config: config.modelConfig || {},
            });
            const payload = responsePayload(response);
            return payload.record || payload.data || {};
        };

        const open = async (mode = 'add', recordId = '', record = null) => {
            state.mode = mode === 'edit' ? 'edit' : 'add';
            const capability = state.mode === 'edit' ? 'update' : 'create';
            if (capabilities[capability] !== true) {
                showMessage(translate('当前上下文没有执行此写入操作的权限。'), 'danger');
                return;
            }
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

        const assignNested = (target, name, value) => {
            const segments = String(name).split('.').filter(Boolean);
            if (segments.length === 0) return;
            let cursor = target;
            for (const segment of segments.slice(0, -1)) {
                if (!cursor[segment] || typeof cursor[segment] !== 'object' || Array.isArray(cursor[segment])) cursor[segment] = {};
                cursor = cursor[segment];
            }
            cursor[segments.at(-1)] = value;
        };

        const collect = () => {
            const result = {};
            for (const control of form.elements) {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) || !control.name || control.disabled) continue;
                if (control instanceof HTMLInputElement && control.type === 'radio' && !control.checked) continue;
                if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                    assignNested(result, control.name, control.checked ? 1 : 0);
                } else if (control instanceof HTMLInputElement && control.type === 'file') {
                    const files = [...(control.files || [])].map((file) => ({
                        name: file.name,
                        size: file.size,
                        type: file.type,
                        lastModified: file.lastModified,
                    }));
                    assignNested(result, control.name, control.multiple ? files : (files[0] || ''));
                } else {
                    assignNested(result, control.name, control.value);
                }
            }
            return result;
        };

        const writePayload = () => ({
            model: config.model,
            resource: config.resource || '',
            id: state.recordId || undefined,
            record_id: state.recordId || undefined,
            data: collect(),
            dependencies: config.dependencies || '',
            transaction: config.transaction === true,
            write_order: config.writeOrder || '',
            model_config: config.modelConfig || {},
            scope: config.scope || '',
            write_operation: state.mode === 'edit' ? 'update' : 'create',
        });

        const finishSuccess = () => {
            UI.toast.success(state.mode === 'edit' ? translate('记录已更新。') : translate('记录已创建。'));
            element.dispatchEvent(new CustomEvent('weline:datatable:form:saved', {
                bubbles: true,
                detail: {formId: config.id, mode: state.mode, recordId: state.recordId},
            }));
            if (planDialog instanceof HTMLDialogElement) UI.dialog.close(planDialog, 'executed');
            if (element instanceof HTMLDialogElement) UI.dialog.close(element, 'saved');
            else reset();
        };

        const renderWritePlan = (plan) => {
            if (!(planDialog instanceof HTMLDialogElement)) throw new Error(translate('写入计划确认窗口未挂载。'));
            const targets = planDialog.querySelector('[data-w-datatable-write-plan-targets]');
            const meta = planDialog.querySelector('[data-w-datatable-write-plan-meta]');
            const warning = planDialog.querySelector('[data-w-datatable-write-plan-warning]');
            const targetFragment = document.createDocumentFragment();
            for (const target of Array.isArray(plan.targets) ? plan.targets : []) {
                const item = document.createElement('li');
                const heading = document.createElement('strong');
                heading.textContent = `${translate('步骤')} ${target.order}: ${target.resource || target.alias} (${target.operation || plan.operation || ''})`;
                const fields = document.createElement('dl');
                fields.className = 'w-datatable-write-plan__fields';
                for (const field of Array.isArray(target.fields) ? target.fields : []) {
                    const dt = document.createElement('dt');
                    const dd = document.createElement('dd');
                    dt.textContent = `${field.label || field.name}${field.required ? ' *' : ''}`;
                    const rawValue = field.dependency?.from
                        ? `${field.dependency.from} → ${field.dependency.to}`
                        : field.value;
                    dd.textContent = rawValue === '' || rawValue == null
                        ? translate('未填写')
                        : (typeof rawValue === 'object' ? JSON.stringify(rawValue) : String(rawValue));
                    if (field.changed) dd.dataset.changed = 'true';
                    fields.append(dt, dd);
                }
                item.append(heading, fields);
                targetFragment.append(item);
            }
            targets?.replaceChildren(targetFragment);
            if (meta) {
                const entries = [
                    [translate('操作'), plan.operation || ''],
                    [translate('事务范围'), plan.atomic_scope || 'none'],
                    [translate('计划有效期'), translate('%{1} 秒', [plan.expires_in || 0])],
                ];
                const fragment = document.createDocumentFragment();
                for (const [label, value] of entries) {
                    const dt = document.createElement('dt');
                    const dd = document.createElement('dd');
                    dt.textContent = label;
                    dd.textContent = String(value);
                    fragment.append(dt, dd);
                }
                meta.replaceChildren(fragment);
            }
            if (warning) {
                const warnings = Array.isArray(plan.warnings) ? plan.warnings.filter(Boolean) : [];
                const missing = Array.isArray(plan.missing_required) ? plan.missing_required : [];
                if (missing.length > 0) {
                    warnings.push(translate('请先补全必填字段：%{1}', [missing.map((field) => `${field.alias}.${field.label || field.name}`).join(', ')]));
                }
                warning.hidden = warnings.length === 0;
                warning.textContent = warnings.join(' ');
            }
            if (planConfirm instanceof HTMLButtonElement) {
                planConfirm.disabled = plan.can_proceed !== true || !state.planToken;
            }
            UI.dialog.open(planDialog);
            if (planConfirm instanceof HTMLElement) planConfirm.focus({preventScroll: true});
        };

        const submit = async () => {
            clearErrors();
            const capability = state.mode === 'edit' ? 'update' : 'create';
            if (config.readOnly === true || capabilities[capability] !== true) {
                showMessage(translate('当前上下文没有执行此写入操作的权限。'), 'danger');
                return;
            }
            if (!form.checkValidity()) {
                form.reportValidity();
                const invalid = form.querySelector(':invalid');
                if (invalid instanceof HTMLElement) invalid.setAttribute('aria-invalid', 'true');
                return;
            }
            setBusy(true);
            try {
                const payload = writePayload();
                if (config.requiresWritePlan === true) {
                    const response = await request(config, 'previewWrite', payload);
                    const plan = responsePayload(response);
                    state.planToken = String(plan.plan_token || '');
                    state.pendingPayload = payload;
                    setBusy(false);
                    renderWritePlan(plan);
                    return;
                }
                await request(config, state.mode === 'edit' ? 'update' : 'create', payload);
                setBusy(false);
                finishSuccess();
            } catch (error) {
                setBusy(false);
                showMessage(error instanceof Error ? error.message : String(error), 'danger');
            }
        };

        const executePlan = async () => {
            if (!state.planToken || !state.pendingPayload) return;
            if (planConfirm instanceof HTMLButtonElement) planConfirm.disabled = true;
            setBusy(true);
            try {
                await request(config, 'executeWrite', {...state.pendingPayload, plan_token: state.planToken});
                state.planToken = '';
                state.pendingPayload = null;
                finishSuccess();
            } catch (error) {
                showMessage(error instanceof Error ? error.message : String(error), 'danger');
            } finally {
                setBusy(false);
                if (planConfirm instanceof HTMLButtonElement) planConfirm.disabled = false;
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
        if (planConfirm instanceof HTMLElement) {
            listen(planConfirm, 'click', () => void executePlan());
        }

        queueMicrotask(() => {
            if (config.readOnly === true) {
                form.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    control.disabled = true;
                });
            }
            void loadFields();
        });
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
