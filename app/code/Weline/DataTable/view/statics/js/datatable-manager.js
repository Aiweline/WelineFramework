const Weline = window.Weline = window.Weline || {};

function translate(message, values = []) {
    if (typeof window.__ === 'function') {
        return window.__(message, values);
    }
    return values.reduce(
        (result, value, index) => result.replace(`%{${index + 1}}`, String(value)),
        String(message),
    );
}

function parseConfig(element) {
    try {
        const value = JSON.parse(element.getAttribute('data-w-config') || '{}');
        return value && typeof value === 'object' ? value : {};
    } catch (error) {
        console.error('Invalid Weline DataTable configuration.', error);
        return {};
    }
}

function isSuccessful(response) {
    if (!response || typeof response !== 'object') return false;
    if ('success' in response) return response.success === true;
    if ('error' in response && response.error === true) return false;
    if ('code' in response) return response.code === 200 || response.code === '200';
    return true;
}

function responsePayload(response) {
    return response && typeof response.data === 'object' && response.data !== null
        ? response.data
        : (response || {});
}

function responseMessage(response, fallback) {
    return String(response?.message || response?.msg || fallback);
}

function normalizeOptions(options) {
    if (Array.isArray(options)) {
        return options.map((option) => {
            if (option && typeof option === 'object') {
                const value = String(option.value ?? option.id ?? '');
                return {value, label: String(option.label ?? option.name ?? value)};
            }
            return {value: String(option), label: String(option)};
        });
    }
    if (typeof options !== 'string' || options.trim() === '') return [];
    return options.split(',').map((pair) => {
        const [value, label] = pair.split(':', 2).map((part) => part.trim());
        return {value, label: label || value};
    });
}

function normalizeField(field) {
    const value = field && typeof field === 'object' ? field : {};
    const name = String(value.name || '');
    return {
        ...value,
        name,
        label: String(value.label || name),
        type: String(value.type || 'text').toLowerCase(),
        options: normalizeOptions(value.options),
        visible: value.visible !== false && value.visible !== 'false',
        searchable: value.searchable !== false && value.searchable !== 'false',
        sortable: value.sortable === true || value.sortable === 'true',
        editable: value.editable === true || value.editable === 'true',
    };
}

function mergeFields(primary, secondary) {
    const map = new Map();
    for (const field of [...primary, ...secondary].map(normalizeField)) {
        if (!field.name) continue;
        map.set(field.name, {...(map.get(field.name) || {}), ...field});
    }
    return [...map.values()];
}

function fieldMap(fields) {
    return new Map(fields.map((field) => [field.name, field]));
}

function parseFieldElements(elements) {
    return [...elements].map((element) => {
        try {
            return normalizeField(JSON.parse(element.getAttribute('data-w-field') || '{}'));
        } catch (_error) {
            return normalizeField({
                name: element.getAttribute('data-field') || '',
                label: element.textContent?.trim() || '',
                sortable: element.getAttribute('data-sortable') === 'true',
            });
        }
    }).filter((field) => field.name);
}

function icon(name, size = 'sm') {
    const element = document.createElement('w-icon');
    element.setAttribute('name', name);
    element.setAttribute('size', size);
    return element;
}

function button(label, options = {}) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = options.className || 'w-button';
    if (options.tone) element.dataset.tone = options.tone;
    if (options.size) element.dataset.size = options.size;
    if (options.action) element.dataset.wDatatableAction = options.action;
    if (options.formAction) element.dataset.wDatatableFormAction = options.formAction;
    if (options.icon) element.append(icon(options.icon, options.iconSize || 'sm'));
    const text = document.createElement('span');
    text.textContent = label;
    element.append(text);
    return element;
}

function valueFor(row, name) {
    if (row && Object.prototype.hasOwnProperty.call(row, name)) return row[name];
    let value = row;
    for (const segment of String(name).split('.')) {
        if (!value || typeof value !== 'object' || !(segment in value)) return null;
        value = value[segment];
    }
    return value;
}

function safeImageUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') return '';
    try {
        const url = new URL(value, window.location.origin);
        return ['http:', 'https:', 'blob:'].includes(url.protocol) ? url.href : '';
    } catch (_error) {
        return '';
    }
}

function safeCssLength(value) {
    const raw = String(value || '').trim();
    if (/^\d+$/.test(raw)) return `${raw}px`;
    return /^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|ch))$/.test(raw) ? raw : '';
}

function fieldValueNode(value, field) {
    if (field.type === 'image') {
        const src = safeImageUrl(String(value || ''));
        if (src) {
            const image = document.createElement('img');
            image.src = src;
            image.alt = field.label;
            image.loading = 'lazy';
            image.width = 48;
            image.height = 48;
            return image;
        }
    }
    if (typeof value === 'boolean') {
        const badge = document.createElement('span');
        badge.className = 'w-badge';
        badge.dataset.tone = value ? 'success' : 'neutral';
        badge.textContent = value ? translate('是') : translate('否');
        return badge;
    }
    const text = document.createElement('span');
    if (value && typeof value === 'object') {
        text.textContent = JSON.stringify(value);
    } else {
        text.textContent = value == null ? '' : String(value);
    }
    return text;
}

async function request(config, operation, payload = {}) {
    if (!Weline.Api || typeof Weline.Api.resource !== 'function') {
        throw new Error(translate('Weline.Api 尚未就绪。'));
    }
    const provider = String(config.apiProvider || 'datatable');
    const resource = await Weline.Api.resource(provider);
    const method = String(config.operations?.[operation] || operation);
    if (!resource || typeof resource[method] !== 'function') {
        throw new Error(translate('资源操作不可用：%{1}.%{2}', [provider, method]));
    }
    const response = await resource[method](payload, {silent: true});
    if (!isSuccessful(response)) {
        throw new Error(responseMessage(response, translate('请求失败。')));
    }
    return response;
}

function normalizePagination(payload, state) {
    const raw = payload.pagination || {};
    const pageSize = Math.max(1, Number(raw.pageSize ?? raw.limit ?? payload.pageSize ?? state.pageSize) || 20);
    const total = Math.max(0, Number(raw.total ?? payload.total ?? 0) || 0);
    const page = Math.max(1, Number(raw.page ?? payload.page ?? state.page) || 1);
    const pages = Math.max(1, Number(raw.lastPage ?? payload.pages) || Math.ceil(total / pageSize) || 1);
    return {page: Math.min(page, pages), pageSize, total, pages};
}

function downloadPayload(payload, format) {
    const body = payload.body ?? payload.content ?? '';
    const contentType = String(payload.content_type || (format === 'json' ? 'application/json' : 'text/csv'));
    const extension = format === 'json' ? 'json' : (format === 'excel' ? 'xlsx' : 'csv');
    const proposed = String(payload.filename || `datatable-export.${extension}`);
    const filename = proposed.replace(/[^A-Za-z0-9._-]+/g, '-') || `datatable-export.${extension}`;
    const url = URL.createObjectURL(new Blob([body], {type: contentType}));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    queueMicrotask(() => URL.revokeObjectURL(url));
}

function confirmAction(UI, message, options = {}) {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'w-dialog';
        dialog.dataset.size = 'sm';
        dialog.dataset.wComponent = 'dialog';
        const header = document.createElement('header');
        header.className = 'w-dialog__header';
        const title = document.createElement('h2');
        title.textContent = options.title || translate('请确认');
        header.append(title);
        const body = document.createElement('div');
        body.className = 'w-dialog__body';
        const paragraph = document.createElement('p');
        paragraph.textContent = String(message);
        body.append(paragraph);
        const footer = document.createElement('footer');
        footer.className = 'w-dialog__footer';
        const cancel = button(options.cancelLabel || translate('取消'), {tone: 'neutral'});
        const confirm = button(options.confirmLabel || translate('确认'), {tone: options.tone || 'danger'});
        footer.append(cancel, confirm);
        dialog.append(header, body, footer);
        document.body.append(dialog);
        UI.mount(dialog);
        let settled = false;
        const finish = (value) => {
            if (settled) return;
            settled = true;
            UI.dialog.close(dialog, value ? 'confirm' : 'cancel');
            queueMicrotask(() => {
                UI.unmount(dialog);
                dialog.remove();
            });
            resolve(value);
        };
        cancel.addEventListener('click', () => finish(false), {once: true});
        confirm.addEventListener('click', () => finish(true), {once: true});
        dialog.addEventListener('cancel', () => finish(false), {once: true});
        UI.dialog.open(dialog);
    });
}

function registerDataTable(UI) {
    UI.define('data-table', ({element, listen}) => {
        const config = parseConfig(element);
        const table = element.querySelector('table');
        const body = table?.querySelector('.w-datatable__body');
        const filterForm = table?.querySelector('[data-w-datatable-filter]');
        const footer = table?.querySelector('.w-datatable__footer');
        const configDialog = document.getElementById(`w-datatable-config-${config.id}`);
        const templateDisplayFields = parseFieldElements(table?.querySelectorAll('.w-datatable__head [data-w-field]') || []);
        const templateFilterFields = parseFieldElements(table?.querySelectorAll('.w-datatable__filters [data-w-field]') || []);
        const state = {
            page: 1,
            pageSize: Math.max(1, Number(config.pageSize) || 20),
            pagination: {page: 1, pageSize: Math.max(1, Number(config.pageSize) || 20), total: 0, pages: 1},
            data: [],
            filters: {},
            sorts: {},
            allFields: mergeFields(templateDisplayFields, templateFilterFields),
            displayFields: templateDisplayFields,
            filterFields: templateFilterFields,
            draftDisplayFields: [],
            draftFilterFields: [],
            destroyed: false,
        };

        if (!(table instanceof HTMLTableElement) || !(body instanceof HTMLTableSectionElement)) {
            return {state};
        }

        const setBusy = (busy, message = '') => {
            element.dataset.state = busy ? 'loading' : 'ready';
            element.setAttribute('aria-busy', String(busy));
            const status = element.querySelector('[data-w-datatable-status]');
            if (status) {
                status.hidden = message === '';
                status.dataset.tone = '';
                status.textContent = message;
            }
        };

        const showError = (error) => {
            const message = error instanceof Error ? error.message : String(error);
            const status = element.querySelector('[data-w-datatable-status]');
            if (status) {
                status.hidden = false;
                status.dataset.tone = 'danger';
                status.textContent = message;
            }
            UI.toast.error(message);
        };

        const payloadBase = () => ({
            model: config.model,
            scope: config.scope,
            join: config.join || '',
            model_config: config.modelConfig || {},
        });

        const updateStats = () => {
            const total = element.querySelector('[data-w-datatable-total]');
            const visible = element.querySelector('[data-w-datatable-visible]');
            if (total) total.textContent = String(state.pagination.total);
            if (visible) visible.textContent = String(state.data.length);
            const summary = footer?.querySelector('[data-w-datatable-summary]');
            if (summary) {
                const start = state.pagination.total === 0 ? 0 : ((state.page - 1) * state.pageSize) + 1;
                const end = Math.min(state.page * state.pageSize, state.pagination.total);
                summary.textContent = translate('显示 %{1}–%{2}，共 %{3} 条', [start, end, state.pagination.total]);
            }
        };

        const renderHeader = () => {
            let head = table.querySelector('.w-datatable__head');
            if (!head) {
                head = document.createElement('thead');
                head.className = 'w-datatable__head';
                table.prepend(head);
            }
            const row = document.createElement('tr');
            for (const field of state.displayFields) {
                const th = document.createElement('th');
                th.dataset.field = field.name;
                const width = safeCssLength(field.width);
                if (width) th.style.setProperty('--w-column-width', width);
                if (config.sortable !== false && field.sortable) {
                    const sort = state.sorts[field.name] || '';
                    th.setAttribute('aria-sort', sort === 'asc' ? 'ascending' : (sort === 'desc' ? 'descending' : 'none'));
                    const sortButton = button(field.label, {className: 'w-datatable__sort', action: 'sort', icon: 'sort', iconSize: 'xs'});
                    sortButton.dataset.field = field.name;
                    row.append(th);
                    th.append(sortButton);
                } else {
                    th.textContent = field.label;
                    row.append(th);
                }
            }
            if (config.editable || config.modalEdit) {
                const th = document.createElement('th');
                th.textContent = translate('操作');
                row.append(th);
            }
            head.replaceChildren(row);
        };

        const renderCell = (rowData, field, rowIndex) => {
            const cell = document.createElement('td');
            cell.dataset.field = field.name;
            cell.dataset.rowIndex = String(rowIndex);
            cell.append(fieldValueNode(valueFor(rowData, field.name), field));
            if (config.inlineEdit && field.editable) cell.dataset.editable = 'true';
            return cell;
        };

        const renderBody = () => {
            const fragment = document.createDocumentFragment();
            if (state.data.length === 0) {
                const row = document.createElement('tr');
                row.className = 'w-datatable__empty';
                const cell = document.createElement('td');
                cell.colSpan = Math.max(1, state.displayFields.length + ((config.editable || config.modalEdit) ? 1 : 0));
                cell.textContent = translate('暂无数据');
                row.append(cell);
                fragment.append(row);
            }
            state.data.forEach((rowData, rowIndex) => {
                const row = document.createElement('tr');
                row.dataset.rowIndex = String(rowIndex);
                row.dataset.recordId = String(rowData.id ?? '');
                for (const field of state.displayFields) row.append(renderCell(rowData, field, rowIndex));
                if (config.editable || config.modalEdit) {
                    const actions = document.createElement('td');
                    const group = document.createElement('div');
                    group.className = 'w-datatable__cell-actions';
                    const edit = button(translate('编辑'), {tone: 'neutral', size: 'sm', action: 'row.edit', icon: 'edit'});
                    edit.dataset.rowIndex = String(rowIndex);
                    const remove = button(translate('删除'), {tone: 'danger', size: 'sm', action: 'row.delete', icon: 'trash'});
                    remove.dataset.rowIndex = String(rowIndex);
                    group.append(edit, remove);
                    actions.append(group);
                    row.append(actions);
                }
                fragment.append(row);
            });
            body.replaceChildren(fragment);
            updateStats();
        };

        const renderPagination = () => {
            const pagination = footer?.querySelector('[data-w-datatable-pagination]');
            if (!pagination || config.showPagination === false) return;
            pagination.replaceChildren();
            const addPage = (label, page, options = {}) => {
                const item = document.createElement('span');
                item.className = 'w-pagination__item';
                const control = button(label, {className: 'w-pagination__link'});
                control.dataset.wDatatableAction = 'page';
                control.dataset.page = String(page);
                control.disabled = options.disabled === true;
                if (options.current) control.setAttribute('aria-current', 'page');
                item.append(control);
                pagination.append(item);
            };
            addPage(translate('上一页'), state.page - 1, {disabled: state.page <= 1});
            const start = Math.max(1, Math.min(state.page - 2, state.pagination.pages - 4));
            const end = Math.min(state.pagination.pages, start + 4);
            for (let page = start; page <= end; page += 1) addPage(String(page), page, {current: page === state.page});
            addPage(translate('下一页'), state.page + 1, {disabled: state.page >= state.pagination.pages});
        };

        const render = () => {
            renderHeader();
            renderBody();
            renderPagination();
        };

        const readFilters = () => {
            const result = {};
            if (!filterForm) return result;
            for (const control of filterForm.querySelectorAll('[data-field]')) {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) continue;
                const name = control.dataset.field || '';
                if (!name || control.disabled) continue;
                const value = control instanceof HTMLInputElement && control.type === 'checkbox'
                    ? (control.checked ? control.value : '')
                    : control.value.trim();
                if (value !== '') result[name] = value;
            }
            return result;
        };

        const renderFilters = () => {
            if (!filterForm) return;
            const cluster = filterForm.querySelector('.w-cluster');
            if (!cluster) return;
            const buttons = [...cluster.querySelectorAll('button')];
            cluster.querySelectorAll('.w-datatable__filter-field').forEach((field) => field.remove());
            const firstButton = buttons[0] || null;
            for (const field of state.filterFields) {
                const wrapper = document.createElement('div');
                wrapper.className = 'w-field w-datatable__filter-field';
                wrapper.dataset.field = field.name;
                const label = document.createElement('label');
                label.className = 'w-visually-hidden';
                label.textContent = field.label;
                let control;
                if (field.type === 'select') {
                    control = document.createElement('select');
                    control.className = 'w-select';
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = translate('全部');
                    control.append(empty);
                    for (const option of field.options) {
                        const item = document.createElement('option');
                        item.value = option.value;
                        item.textContent = option.label;
                        control.append(item);
                    }
                } else {
                    control = document.createElement('input');
                    control.className = 'w-input';
                    control.type = ['search', 'email', 'number', 'date', 'time'].includes(field.type) ? field.type : 'search';
                    control.placeholder = field.placeholder || field.label;
                }
                control.id = `w-filter-${config.id}-${field.name.replace(/[^A-Za-z0-9_-]/g, '-')}`;
                control.dataset.field = field.name;
                label.htmlFor = control.id;
                wrapper.append(label, control);
                cluster.insertBefore(wrapper, firstButton);
            }
        };

        const loadData = async () => {
            setBusy(true, translate('正在加载数据…'));
            try {
                const response = await request(config, 'data', {
                    ...payloadBase(),
                    page: state.page,
                    pageSize: state.pageSize,
                    limit: state.pageSize,
                    filters: state.filters,
                    sorts: state.sorts,
                    sort: state.sorts,
                });
                if (state.destroyed) return;
                const payload = responsePayload(response);
                state.data = Array.isArray(payload.data) ? payload.data : [];
                state.pagination = normalizePagination(payload, state);
                state.page = state.pagination.page;
                state.pageSize = state.pagination.pageSize;
                setBusy(false);
                render();
            } catch (error) {
                setBusy(false);
                showError(error);
            }
        };

        const loadFields = async () => {
            try {
                const response = await request(config, 'fields', {
                    ...payloadBase(),
                    table_id: config.id,
                });
                if (state.destroyed) return;
                const payload = responsePayload(response);
                const apiFields = Array.isArray(payload.all_fields) ? payload.all_fields : [];
                state.allFields = mergeFields(state.allFields, apiFields);
                const displaySource = Array.isArray(payload.cached_display_fields) && payload.cached_display_fields.length
                    ? payload.cached_display_fields
                    : (Array.isArray(payload.display_fields) && payload.display_fields.length ? payload.display_fields : state.displayFields);
                const filterSource = Array.isArray(payload.cached_filter_fields) && payload.cached_filter_fields.length
                    ? payload.cached_filter_fields
                    : (Array.isArray(payload.filter_fields) ? payload.filter_fields : state.filterFields);
                state.displayFields = mergeFields(state.displayFields, displaySource).filter((field) => field.visible !== false);
                state.filterFields = mergeFields(state.filterFields, filterSource).filter((field) => field.searchable !== false);
                if (state.displayFields.length === 0) state.displayFields = state.allFields.filter((field) => field.visible !== false);
                renderFilters();
            } catch (error) {
                showError(error);
            }
        };

        const getFormComponent = () => {
            const form = document.getElementById(String(config.formId || ''));
            const root = form?.closest('[data-w-component~="data-table-form"]');
            return root ? UI.get(root, 'data-table-form') : null;
        };

        const openForm = async (mode, rowIndex = null) => {
            const component = getFormComponent();
            if (!component) {
                showError(new Error(translate('数据表表单未挂载。')));
                return;
            }
            const record = rowIndex === null ? null : state.data[rowIndex];
            await component.open(mode, record?.id ?? '', record || null);
        };

        const deleteRow = async (rowIndex) => {
            const row = state.data[rowIndex];
            if (!row) return;
            const confirmed = await confirmAction(UI, translate('确定删除这条记录吗？'));
            if (!confirmed) return;
            setBusy(true, translate('正在删除…'));
            try {
                await request(config, 'deleteData', {...payloadBase(), ids: [row.id]});
                UI.toast.success(translate('记录已删除。'));
                await loadData();
            } catch (error) {
                setBusy(false);
                showError(error);
            }
        };

        const startInlineEdit = (cell) => {
            if (!(cell instanceof HTMLTableCellElement) || cell.dataset.editable !== 'true' || cell.dataset.state === 'editing') return;
            const rowIndex = Number(cell.dataset.rowIndex);
            const field = fieldMap(state.displayFields).get(cell.dataset.field || '');
            const row = state.data[rowIndex];
            if (!field || !row) return;
            const oldValue = valueFor(row, field.name);
            const input = document.createElement('input');
            input.className = 'w-input';
            input.type = field.type === 'number' ? 'number' : 'text';
            input.value = oldValue == null ? '' : String(oldValue);
            cell.dataset.state = 'editing';
            cell.replaceChildren(input);
            input.focus();
            input.select();
            let settled = false;
            const restore = () => {
                if (settled) return;
                settled = true;
                cell.dataset.state = '';
                cell.replaceChildren(fieldValueNode(oldValue, field));
            };
            const save = async () => {
                if (settled) return;
                settled = true;
                try {
                    await request(config, 'saveData', {
                        ...payloadBase(),
                        id: row.id,
                        data: {[field.name]: input.value},
                    });
                    row[field.name] = input.value;
                    cell.dataset.state = 'saved';
                    cell.replaceChildren(fieldValueNode(input.value, field));
                    window.setTimeout(() => { if (cell.isConnected) cell.dataset.state = ''; }, 1200);
                } catch (error) {
                    cell.dataset.state = '';
                    cell.replaceChildren(fieldValueNode(oldValue, field));
                    showError(error);
                }
            };
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') { event.preventDefault(); void save(); }
                if (event.key === 'Escape') { event.preventDefault(); restore(); }
            });
            input.addEventListener('blur', () => void save(), {once: true});
        };

        const renderConfigList = (mode) => {
            const container = configDialog?.querySelector(`[data-w-datatable-fields="${mode}"]`);
            if (!container) return;
            const selected = mode === 'display' ? state.draftDisplayFields : state.draftFilterFields;
            const selectedNames = new Set(selected.map((field) => field.name));
            const list = document.createElement('ul');
            list.className = 'w-datatable__field-list';
            for (const field of state.allFields) {
                const item = document.createElement('li');
                item.className = 'w-datatable__field-item';
                const check = document.createElement('input');
                check.type = 'checkbox';
                check.checked = selectedNames.has(field.name);
                check.dataset.wDatatableAction = 'field.toggle';
                check.dataset.mode = mode;
                check.dataset.field = field.name;
                const label = document.createElement('span');
                label.textContent = field.label;
                const order = document.createElement('span');
                order.className = 'w-datatable__field-order';
                const up = button(translate('上移'), {tone: 'quiet', size: 'sm', action: 'field.up', icon: 'chevron-up'});
                const down = button(translate('下移'), {tone: 'quiet', size: 'sm', action: 'field.down', icon: 'chevron-down'});
                for (const control of [up, down]) {
                    control.dataset.mode = mode;
                    control.dataset.field = field.name;
                    control.disabled = !selectedNames.has(field.name);
                }
                order.append(up, down);
                item.append(check, label, order);
                list.append(item);
            }
            container.replaceChildren(list);
        };

        const openConfig = () => {
            state.draftDisplayFields = state.displayFields.map((field) => ({...field}));
            state.draftFilterFields = state.filterFields.map((field) => ({...field}));
            renderConfigList('display');
            renderConfigList('filter');
            UI.dialog.open(configDialog);
        };

        const toggleDraftField = (mode, name, checked) => {
            const key = mode === 'display' ? 'draftDisplayFields' : 'draftFilterFields';
            const fields = state[key];
            const exists = fields.some((field) => field.name === name);
            if (checked && !exists) {
                const field = state.allFields.find((item) => item.name === name);
                if (field) fields.push({...field});
            }
            if (!checked && exists) state[key] = fields.filter((field) => field.name !== name);
            renderConfigList(mode);
        };

        const moveDraftField = (mode, name, direction) => {
            const key = mode === 'display' ? 'draftDisplayFields' : 'draftFilterFields';
            const fields = state[key];
            const index = fields.findIndex((field) => field.name === name);
            const target = index + direction;
            if (index < 0 || target < 0 || target >= fields.length) return;
            [fields[index], fields[target]] = [fields[target], fields[index]];
            renderConfigList(mode);
        };

        const saveConfig = async () => {
            if (state.draftDisplayFields.length === 0) {
                showError(new Error(translate('至少保留一个显示字段。')));
                return;
            }
            try {
                await request(config, 'saveConfig', {
                    scope: config.scope,
                    table_id: config.id,
                    display_fields: state.draftDisplayFields,
                    filter_fields: state.draftFilterFields,
                    config: {display_fields: state.draftDisplayFields, filter_fields: state.draftFilterFields},
                });
                state.displayFields = state.draftDisplayFields.map((field) => ({...field}));
                state.filterFields = state.draftFilterFields.map((field) => ({...field}));
                UI.dialog.close(configDialog, 'saved');
                renderFilters();
                state.page = 1;
                await loadData();
                UI.toast.success(translate('字段配置已保存。'));
            } catch (error) {
                showError(error);
            }
        };

        const clearConfig = async () => {
            const confirmed = await confirmAction(UI, translate('确定恢复默认字段配置吗？'), {tone: 'neutral'});
            if (!confirmed) return;
            try {
                await request(config, 'clearConfig', {scope: config.scope, table_id: config.id, type: 'all'});
                await loadFields();
                await loadData();
                UI.dialog.close(configDialog, 'cleared');
                UI.toast.success(translate('字段配置已重置。'));
            } catch (error) {
                showError(error);
            }
        };

        const exportData = async (format) => {
            setBusy(true, translate('正在生成导出文件…'));
            try {
                const response = await request(config, 'exportData', {
                    ...payloadBase(),
                    format,
                    fields: state.displayFields.map((field) => ({name: field.name, label: field.label})),
                    filters: state.filters,
                    sorts: state.sorts,
                });
                downloadPayload(responsePayload(response), format);
                setBusy(false);
                UI.toast.success(translate('导出已生成。'));
            } catch (error) {
                setBusy(false);
                showError(error);
            }
        };

        const handleAction = (action) => {
            const name = action.dataset.wDatatableAction || '';
            if (name === 'reload') void loadData();
            if (name === 'sort') {
                const field = action.dataset.field || '';
                const current = state.sorts[field] || '';
                state.sorts = current === 'asc' ? {[field]: 'desc'} : (current === 'desc' ? {} : {[field]: 'asc'});
                state.page = 1;
                void loadData();
            }
            if (name === 'page') {
                const page = Number(action.dataset.page);
                if (Number.isInteger(page) && page >= 1 && page <= state.pagination.pages && page !== state.page) {
                    state.page = page;
                    void loadData();
                }
            }
            if (name === 'form.open') void openForm('add');
            if (name === 'row.edit') void openForm('edit', Number(action.dataset.rowIndex));
            if (name === 'row.delete') void deleteRow(Number(action.dataset.rowIndex));
            if (name === 'export') void exportData(action.dataset.format || 'csv');
            if (name === 'config.open') openConfig();
            if (name === 'config.save') void saveConfig();
            if (name === 'config.clear') void clearConfig();
            if (name === 'field.toggle' && action instanceof HTMLInputElement) {
                toggleDraftField(action.dataset.mode || 'display', action.dataset.field || '', action.checked);
            }
            if (name === 'field.up') moveDraftField(action.dataset.mode || 'display', action.dataset.field || '', -1);
            if (name === 'field.down') moveDraftField(action.dataset.mode || 'display', action.dataset.field || '', 1);
        };

        const clickHandler = (event) => {
            const target = event.target instanceof Element ? event.target.closest('[data-w-datatable-action]') : null;
            if (!(target instanceof HTMLElement)) return;
            event.preventDefault();
            handleAction(target);
            target.closest('details')?.removeAttribute('open');
        };
        listen(element, 'click', clickHandler);
        if (configDialog) listen(configDialog, 'click', clickHandler);
        listen(body, 'dblclick', (event) => {
            const cell = event.target instanceof Element ? event.target.closest('td[data-editable="true"]') : null;
            if (cell) startInlineEdit(cell);
        });
        if (filterForm) {
            listen(filterForm, 'submit', (event) => {
                event.preventDefault();
                state.filters = readFilters();
                state.page = 1;
                void loadData();
            });
            listen(filterForm, 'reset', () => queueMicrotask(() => {
                state.filters = {};
                state.page = 1;
                void loadData();
            }));
        }
        listen(document, 'weline:datatable:form:saved', (event) => {
            if (event instanceof CustomEvent && event.detail?.formId === config.formId) void loadData();
        });

        queueMicrotask(async () => {
            await loadFields();
            if (!state.destroyed) await loadData();
        });

        return {
            state,
            reload: loadData,
            openConfig,
            destroy() { state.destroyed = true; },
        };
    });
}

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
    registerDataTable(Weline.UI);
    registerDataTableForm(Weline.UI);
    Weline.UI.mount(document);
}

if (Weline.UI) register();
else document.addEventListener('weline:ui:ready', register, {once: true});

export {register};
