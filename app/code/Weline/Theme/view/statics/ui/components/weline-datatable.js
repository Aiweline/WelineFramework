/* Weline UI source: js/datatable-manager.js */
import {
    button,
    downloadPayload,
    fieldMap,
    fieldValueNode,
    mergeFields,
    normalizePagination,
    parseConfig,
    parseFieldElements,
    request,
    responsePayload,
    safeCssLength,
    translate,
    valueFor,
} from './datatable-common.js?v=6b1e4ac74b27';

const Weline = window.Weline = window.Weline || {};

function registerDataTable(UI) {
    UI.define('data-table', ({element, listen}) => {
        const config = parseConfig(element);
        for (const [property, value] of [
            ['--w-datatable-height', element.dataset.wDatatableHeight],
            ['--w-datatable-width', element.dataset.wDatatableWidth],
        ]) {
            const length = safeCssLength(value);
            if (length) element.style.setProperty(property, length);
        }
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
                const hydrateFields = (fields) => {
                    const available = fieldMap(state.allFields);
                    return mergeFields([], fields).map((field) => ({
                        ...(available.get(field.name) || {}),
                        ...field,
                    }));
                };
                const apiDisplayFields = Array.isArray(payload.display_fields) ? payload.display_fields : [];
                const apiFilterFields = Array.isArray(payload.filter_fields) ? payload.filter_fields : [];
                const displaySource = Array.isArray(payload.cached_display_fields) && payload.cached_display_fields.length
                    ? payload.cached_display_fields
                    : (templateDisplayFields.length ? templateDisplayFields : apiDisplayFields);
                const filterSource = Array.isArray(payload.cached_filter_fields) && payload.cached_filter_fields.length
                    ? payload.cached_filter_fields
                    : (templateFilterFields.length ? templateFilterFields : apiFilterFields);
                state.displayFields = hydrateFields(displaySource).filter((field) => field.visible !== false);
                state.filterFields = hydrateFields(filterSource).filter((field) => field.searchable !== false);
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
            const confirmed = await UI.dialog.confirm(translate('确定删除这条记录吗？'), {
                title: translate('请确认'),
                tone: 'danger',
                dangerous: true,
            });
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
            const confirmed = await UI.dialog.confirm(translate('确定恢复默认字段配置吗？'), {
                title: translate('请确认'),
                tone: 'neutral',
            });
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

function register() {
    if (!Weline.UI) return;
    registerDataTable(Weline.UI);
    Weline.UI.mount(document);
}

if (Weline.UI) register();
else document.addEventListener('weline:ui:ready', register, {once: true});

export {register};
