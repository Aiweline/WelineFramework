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
} from './datatable-common.js';

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
        const isLocal = String(config.mode || 'api').toLowerCase() === 'local';
        const capabilities = {
            read: true,
            create: false,
            update: false,
            delete: false,
            export: false,
            preferences: false,
            composite_write: false,
            ...(config.capabilities || {}),
        };
        const selectable = config.selectable === true;
        const rowActions = Array.isArray(config.rowActions) ? config.rowActions : [];
        const hasRowActions = capabilities.update === true
            || capabilities.delete === true
            || rowActions.length > 0;
        const showActions = config.showActions !== false && hasRowActions;
        const selectField = String(config.selectField || 'id');
        if (table) {
            if (config.stickyActions !== false && showActions) table.setAttribute('data-w-sticky-end', '');
            else table.removeAttribute('data-w-sticky-end');
        }
        if (table && selectable) {
            table.setAttribute('data-w-sticky-start', '');
        }
        const body = table?.querySelector('.w-datatable__body');
        const filterForm = table?.querySelector('[data-w-datatable-filter]');
        const footer = table?.querySelector('.w-datatable__footer');
        const searchForm = element.querySelector('[data-w-datatable-search-form]');
        const searchInput = element.querySelector('[data-w-datatable-search]');
        const pageSizeControl = footer?.querySelector('[data-w-datatable-page-size]');
        const configDialog = document.getElementById(`w-datatable-config-${config.id}`);
        const templateDisplayFields = parseFieldElements(table?.querySelectorAll('.w-datatable__head [data-w-field]') || []);
        const templateFilterFields = parseFieldElements(table?.querySelectorAll('.w-datatable__filters [data-w-field]') || []);
        const state = {
            page: 1,
            pageSize: Math.max(1, Number(config.pageSize) || 20),
            pagination: {page: 1, pageSize: Math.max(1, Number(config.pageSize) || 20), total: 0, pages: 1},
            data: [],
            sourceData: [],
            search: '',
            filters: {},
            sorts: {},
            allFields: mergeFields(templateDisplayFields, templateFilterFields),
            displayFields: templateDisplayFields,
            filterFields: templateFilterFields,
            draftDisplayFields: [],
            draftFilterFields: [],
            columnMerges: {},
            draftColumnMerges: {},
            destroyed: false,
        };
        const defaultDisplayFields = templateDisplayFields.map((field) => ({...field}));
        const defaultFilterFields = templateFilterFields.map((field) => ({...field}));
        const preferencesKey = `weline.datatable.v1.${String(config.scope || '')}.${String(config.id || '')}`;
        const normalizeColumnMerges = (merges, fields) => {
            const source = merges && typeof merges === 'object' && !Array.isArray(merges) ? merges : {};
            const names = new Set(fields.map((field) => field.name));
            const mergedSources = new Set(Object.keys(source).filter((name) => String(source[name] || '') !== ''));
            const result = {};
            for (const field of fields) {
                const target = String(source[field.name] || '');
                if (!target || target === field.name || !names.has(target) || mergedSources.has(target)) continue;
                result[field.name] = target;
            }
            return result;
        };
        const visibleDisplayFields = () => state.displayFields.filter((field) => !state.columnMerges[field.name]);
        const mergedFieldsFor = (target) => state.displayFields.filter((field) => state.columnMerges[field.name] === target);
        const isEmptyDisplayValue = (value) => value == null || String(value).trim() === '';
        const busyControls = new Map();

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
                status.setAttribute('role', 'status');
                status.textContent = message;
            }
            for (const control of element.querySelectorAll('button, select[data-w-datatable-page-size]')) {
                if (!(control instanceof HTMLButtonElement || control instanceof HTMLSelectElement)) continue;
                if (busy) {
                    if (!busyControls.has(control)) busyControls.set(control, control.disabled);
                    control.disabled = true;
                } else if (busyControls.has(control)) {
                    control.disabled = busyControls.get(control) === true;
                    busyControls.delete(control);
                }
            }
        };

        const showError = (error) => {
            const message = error instanceof Error ? error.message : String(error);
            const status = element.querySelector('[data-w-datatable-status]');
            if (status) {
                status.hidden = false;
                status.dataset.tone = 'danger';
                status.setAttribute('role', 'alert');
                status.textContent = message;
            }
            UI.toast.error(message);
        };

        const payloadBase = () => ({
            model: config.model,
            scope: config.scope,
            join: config.join || '',
            model_config: config.modelConfig || {},
            resource: config.resource || '',
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
            const renderedFields = visibleDisplayFields();
            const renderedColumnCount = renderedFields.length + (selectable ? 1 : 0) + (showActions ? 1 : 0);
            table.style.setProperty('--w-datatable-column-count', String(Math.max(1, renderedColumnCount)));
            const row = document.createElement('tr');
            if (selectable) {
                const th = document.createElement('th');
                th.dataset.wSticky = 'start';
                th.style.width = '2.5rem';
                const selectAll = document.createElement('input');
                selectAll.type = 'checkbox';
                selectAll.setAttribute('data-testid', 'datatable-select-all');
                selectAll.setAttribute('data-w-datatable-select-all', '');
                selectAll.setAttribute('aria-label', translate('全选'));
                th.append(selectAll);
                row.append(th);
            }
            for (const field of renderedFields) {
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
            if (showActions) {
                const th = document.createElement('th');
                th.dataset.wSticky = 'end';
                th.textContent = translate('操作');
                row.append(th);
            }
            head.replaceChildren(row);
        };

        const renderCellContent = (rowData, field) => {
            const fragment = document.createDocumentFragment();
            const mergedFields = mergedFieldsFor(field.name);
            if (mergedFields.length === 0) {
                fragment.append(fieldValueNode(valueFor(rowData, field.name), field));
                return fragment;
            }
            const composite = document.createElement('div');
            composite.className = 'w-datatable__cell-composite';
            const primary = document.createElement('div');
            primary.className = 'w-datatable__cell-primary';
            primary.append(fieldValueNode(valueFor(rowData, field.name), field));
            composite.append(primary);
            for (const mergedField of mergedFields) {
                const value = valueFor(rowData, mergedField.name);
                if (isEmptyDisplayValue(value)) continue;
                const detail = document.createElement('div');
                detail.className = 'w-datatable__cell-detail';
                const label = document.createElement('span');
                label.className = 'w-datatable__cell-detail-label';
                label.textContent = `${mergedField.label}:`;
                const displayValue = document.createElement('span');
                displayValue.className = 'w-datatable__cell-detail-value';
                displayValue.append(fieldValueNode(value, mergedField));
                detail.append(label, displayValue);
                composite.append(detail);
            }
            fragment.append(composite);
            return fragment;
        };

        const renderCell = (rowData, field, rowIndex) => {
            const cell = document.createElement('td');
            cell.dataset.field = field.name;
            cell.dataset.rowIndex = String(rowIndex);
            const mergedFields = mergedFieldsFor(field.name);
            if (mergedFields.length > 0) cell.dataset.mergedFields = mergedFields.map((item) => item.name).join(',');
            cell.append(renderCellContent(rowData, field));
            if (config.inlineEdit && !config.confirmWrite && capabilities.update === true && field.editable) cell.dataset.editable = 'true';
            return cell;
        };

        const interpolate = (template, rowData) => String(template || '').replace(/\{([a-zA-Z0-9_.]+)\}/g, (_match, key) => {
            const value = valueFor(rowData, key);
            return value == null ? '' : encodeURIComponent(String(value));
        });

        const selectedCountEl = () => element.querySelector('[data-w-datatable-selected-count]');

        const syncSelection = () => {
            const boxes = [...element.querySelectorAll('.w-datatable__row-select')];
            const selected = boxes.filter((box) => box.checked);
            const countEl = selectedCountEl();
            if (countEl) countEl.textContent = String(selected.length);
            const selectAll = element.querySelector('[data-w-datatable-select-all]');
            if (selectAll instanceof HTMLInputElement) {
                selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
            }
        };

        const renderBody = () => {
            const fragment = document.createDocumentFragment();
            const renderedFields = visibleDisplayFields();
            const extraColumns = (selectable ? 1 : 0) + (showActions ? 1 : 0);
            if (state.data.length === 0) {
                const row = document.createElement('tr');
                row.className = 'w-datatable__empty';
                const cell = document.createElement('td');
                cell.colSpan = Math.max(1, renderedFields.length + extraColumns);
                cell.textContent = (state.search || Object.keys(state.filters).length > 0)
                    ? translate('没有符合当前搜索或筛选条件的数据，请调整条件后重试。')
                    : translate('暂无数据。可刷新表格，或在有写入权限时新增第一条记录。');
                row.append(cell);
                fragment.append(row);
            }
            state.data.forEach((rowData, rowIndex) => {
                const row = document.createElement('tr');
                row.dataset.rowIndex = String(rowIndex);
                row.tabIndex = 0;
                const recordId = valueFor(rowData, selectField) ?? rowData.id ?? rowData.product_id ?? '';
                row.dataset.recordId = String(recordId);
                if (selectable) {
                    const selectCell = document.createElement('td');
                    selectCell.dataset.wSticky = 'start';
                    if (recordId !== '' && recordId != null) {
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'w-datatable__row-select';
                        checkbox.value = String(recordId);
                        checkbox.setAttribute('data-testid', 'datatable-row-select');
                        checkbox.setAttribute('data-record-id', String(recordId));
                        checkbox.setAttribute('aria-label', translate('选择行') + ' ' + String(recordId));
                        selectCell.append(checkbox);
                    }
                    row.append(selectCell);
                }
                for (const field of renderedFields) row.append(renderCell(rowData, field, rowIndex));
                if (showActions) {
                    const actions = document.createElement('td');
                    actions.dataset.wSticky = 'end';
                    const group = document.createElement('div');
                    group.className = 'w-datatable__cell-actions';
                    for (const action of rowActions) {
                        const actionType = String(action.type || 'link');
                        if (actionType === 'event') {
                            const control = button(String(action.label || translate('操作')), {
                                tone: action.tone || 'neutral',
                                size: action.size || 'sm',
                                icon: action.icon || '',
                            });
                            if (action.variant) control.dataset.variant = String(action.variant);
                            control.dataset.wDatatableRowEvent = String(action.event || action.action || action.name || 'action');
                            control.dataset.rowIndex = String(rowIndex);
                            if (action.testId) control.setAttribute('data-testid', String(action.testId));
                            group.append(control);
                            continue;
                        }
                        if (actionType !== 'link') continue;
                        const link = document.createElement('a');
                        link.className = 'w-button';
                        link.dataset.tone = action.tone || 'primary';
                        link.dataset.variant = action.variant || 'outline';
                        link.dataset.size = action.size || 'sm';
                        if (action.testId) link.setAttribute('data-testid', String(action.testId));
                        link.href = interpolate(action.hrefTemplate || action.href || '#', {
                            ...rowData,
                            id: recordId,
                            product_id: rowData.product_id ?? recordId,
                        });
                        if (action.icon) {
                            const iconEl = document.createElement('w-icon');
                            iconEl.setAttribute('name', String(action.icon));
                            iconEl.setAttribute('size', 'sm');
                            link.append(iconEl);
                        }
                        const label = document.createElement('span');
                        label.textContent = String(action.label || translate('操作'));
                        link.append(label);
                        group.append(link);
                    }
                    if (capabilities.update === true) {
                        const edit = button(translate('编辑'), {tone: 'neutral', size: 'sm', action: 'row.edit', icon: 'edit'});
                        edit.dataset.rowIndex = String(rowIndex);
                        group.append(edit);
                    }
                    if (capabilities.delete === true) {
                        const remove = button(translate('删除'), {tone: 'danger', size: 'sm', action: 'row.delete', icon: 'trash'});
                        remove.dataset.rowIndex = String(rowIndex);
                        group.append(remove);
                    }
                    actions.append(group);
                    row.append(actions);
                }
                fragment.append(row);
            });
            body.replaceChildren(fragment);
            updateStats();
            syncSelection();
        };

        const renderPagination = () => {
            if (pageSizeControl instanceof HTMLSelectElement) {
                pageSizeControl.value = String(state.pageSize);
            }
            const pageSizeValue = footer?.querySelector('[data-w-datatable-page-size-value]');
            if (pageSizeValue) pageSizeValue.textContent = String(state.pageSize);
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
            for (const control of filterForm.querySelectorAll('input, select, textarea')) {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) continue;
                const name = control.dataset.field
                    || control.closest('[data-field]')?.getAttribute('data-field')
                    || String(control.name || '').replace(/^filter\[|\]$/g, '');
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
                control.name = `filter[${field.name}]`;
                label.htmlFor = control.id;
                wrapper.append(label, control);
                cluster.insertBefore(wrapper, firstButton);
            }
        };

        const normalizedText = (value) => String(value ?? '').toLocaleLowerCase();

        const applyLocalState = () => {
            let rows = state.sourceData.map((row, index) => ({row, index}));
            if (state.search) {
                const needle = normalizedText(state.search);
                const searchable = state.allFields.filter((field) => field.searchable !== false);
                rows = rows.filter(({row}) => searchable.some((field) => normalizedText(valueFor(row, field.name)).includes(needle)));
            }
            for (const [field, expected] of Object.entries(state.filters)) {
                const needle = normalizedText(expected);
                rows = rows.filter(({row}) => normalizedText(valueFor(row, field)).includes(needle));
            }
            const sorts = Object.entries(state.sorts);
            if (sorts.length > 0) {
                rows.sort((left, right) => {
                    for (const [field, direction] of sorts) {
                        const a = valueFor(left.row, field);
                        const b = valueFor(right.row, field);
                        const numeric = Number(a) - Number(b);
                        const comparison = Number.isFinite(numeric) && String(a).trim() !== '' && String(b).trim() !== ''
                            ? numeric
                            : String(a ?? '').localeCompare(String(b ?? ''), undefined, {numeric: true, sensitivity: 'base'});
                        if (comparison !== 0) return direction === 'desc' ? -comparison : comparison;
                    }
                    return left.index - right.index;
                });
            }
            const total = rows.length;
            const pages = Math.max(1, Math.ceil(total / state.pageSize));
            state.page = Math.min(Math.max(1, state.page), pages);
            const offset = (state.page - 1) * state.pageSize;
            state.data = rows.slice(offset, offset + state.pageSize).map(({row}) => row);
            state.pagination = {page: state.page, pageSize: state.pageSize, total, pages};
            setBusy(false);
            render();
        };

        const loadLocalData = () => {
            let rows = Array.isArray(config.localData) ? config.localData : [];
            if ((!rows || rows.length === 0) && config.localDataEl) {
                const el = document.querySelector(String(config.localDataEl));
                if (el) {
                    try {
                        const parsed = JSON.parse(el.textContent || '[]');
                        rows = Array.isArray(parsed) ? parsed : [];
                    } catch (_error) {
                        rows = [];
                    }
                }
            }
            state.sourceData = Array.isArray(rows) ? rows.slice() : [];
            applyLocalState();
        };

        const loadData = async () => {
            if (isLocal) {
                loadLocalData();
                return;
            }
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
                    search: state.search,
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
                if (payload.capabilities && typeof payload.capabilities === 'object') {
                    for (const name of Object.keys(capabilities)) {
                        if (name in payload.capabilities) {
                            capabilities[name] = capabilities[name] === true && payload.capabilities[name] === true;
                        }
                    }
                }
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
            const required = mode === 'edit' ? 'update' : 'create';
            if (capabilities[required] !== true) {
                showError(new Error(translate('当前上下文没有执行此写入操作的权限。')));
                return;
            }
            const component = getFormComponent();
            if (!component) {
                showError(new Error(translate('数据表表单未挂载。')));
                return;
            }
            const record = rowIndex === null ? null : state.data[rowIndex];
            await component.open(mode, record?.id ?? '', record || null);
        };

        const deleteRow = async (rowIndex) => {
            if (capabilities.delete !== true) {
                showError(new Error(translate('当前上下文没有删除权限。')));
                return;
            }
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
                const recordId = valueFor(row, selectField) ?? row.id;
                await request(config, 'deleteData', {...payloadBase(), ids: [recordId]});
                UI.toast.success(translate('记录已删除。'));
                await loadData();
            } catch (error) {
                setBusy(false);
                showError(error);
            }
        };

        const startInlineEdit = (cell) => {
            if (capabilities.update !== true) return;
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
                cell.replaceChildren(renderCellContent(row, field));
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
                    cell.replaceChildren(renderCellContent(row, field));
                    window.setTimeout(() => { if (cell.isConnected) cell.dataset.state = ''; }, 1200);
                } catch (error) {
                    cell.dataset.state = '';
                    cell.replaceChildren(renderCellContent(row, field));
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
                check.id = `w-datatable-field-${config.id}-${mode}-${field.name.replace(/[^A-Za-z0-9_-]/g, '-')}`;
                check.type = 'checkbox';
                check.checked = selectedNames.has(field.name);
                check.dataset.wDatatableAction = 'field.toggle';
                check.dataset.mode = mode;
                check.dataset.field = field.name;
                const label = document.createElement('label');
                label.htmlFor = check.id;
                label.textContent = field.label;
                if (mode === 'display') {
                    const merge = document.createElement('select');
                    merge.className = 'w-select w-datatable__field-merge';
                    merge.dataset.wDatatableMergeField = field.name;
                    merge.disabled = !selectedNames.has(field.name);
                    merge.setAttribute('aria-label', translate('将 %{1} 合并到', [field.label]));
                    const independent = document.createElement('option');
                    independent.value = '';
                    independent.textContent = translate('独立显示');
                    merge.append(independent);
                    for (const target of selected) {
                        if (target.name === field.name || state.draftColumnMerges[target.name]) continue;
                        const option = document.createElement('option');
                        option.value = target.name;
                        option.textContent = translate('合并到 %{1}', [target.label]);
                        merge.append(option);
                    }
                    merge.value = state.draftColumnMerges[field.name] || '';
                    item.append(check, label, merge);
                } else {
                    item.append(check, label);
                }
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
                item.append(order);
                list.append(item);
            }
            container.replaceChildren(list);
        };

        const fieldsFromNames = (names, fallback) => {
            if (!Array.isArray(names)) return fallback.map((field) => ({...field}));
            const available = fieldMap(state.allFields);
            return names.map((name) => available.get(String(name))).filter(Boolean).map((field) => ({...field}));
        };

        const applyLocalPreferences = () => {
            try {
                const stored = JSON.parse(localStorage.getItem(preferencesKey) || '{}');
                if (isLocal || capabilities.preferences !== true) {
                    const display = fieldsFromNames(stored.displayFields, defaultDisplayFields);
                    const filters = fieldsFromNames(stored.filterFields, defaultFilterFields);
                    if (display.length > 0) state.displayFields = display;
                    state.filterFields = filters;
                }
                state.columnMerges = normalizeColumnMerges(stored.columnMerges, state.displayFields);
            } catch (_error) {
                if (isLocal || capabilities.preferences !== true) {
                    state.displayFields = defaultDisplayFields.map((field) => ({...field}));
                    state.filterFields = defaultFilterFields.map((field) => ({...field}));
                }
                state.columnMerges = {};
            }
        };

        const openConfig = () => {
            state.draftDisplayFields = state.displayFields.map((field) => ({...field}));
            state.draftFilterFields = state.filterFields.map((field) => ({...field}));
            state.draftColumnMerges = {...state.columnMerges};
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
            if (!checked && exists) {
                state[key] = fields.filter((field) => field.name !== name);
                if (mode === 'display') {
                    delete state.draftColumnMerges[name];
                    for (const [source, target] of Object.entries(state.draftColumnMerges)) {
                        if (target === name) delete state.draftColumnMerges[source];
                    }
                }
            }
            renderConfigList(mode);
        };

        const setDraftColumnMerge = (source, target) => {
            if (!state.draftDisplayFields.some((field) => field.name === source)) return;
            if (!target) {
                delete state.draftColumnMerges[source];
            } else if (target !== source && state.draftDisplayFields.some((field) => field.name === target)) {
                for (const [mergedSource, currentTarget] of Object.entries(state.draftColumnMerges)) {
                    if (currentTarget === source) delete state.draftColumnMerges[mergedSource];
                }
                state.draftColumnMerges[source] = target;
            }
            state.draftColumnMerges = normalizeColumnMerges(state.draftColumnMerges, state.draftDisplayFields);
            renderConfigList('display');
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
                const columnMerges = normalizeColumnMerges(state.draftColumnMerges, state.draftDisplayFields);
                localStorage.setItem(preferencesKey, JSON.stringify({
                    displayFields: state.draftDisplayFields.map((field) => field.name),
                    filterFields: state.draftFilterFields.map((field) => field.name),
                    columnMerges,
                }));
                if (!isLocal && capabilities.preferences === true) {
                    await request(config, 'saveConfig', {
                        ...payloadBase(),
                        table_id: config.id,
                        display_fields: state.draftDisplayFields,
                        filter_fields: state.draftFilterFields,
                        config: {display_fields: state.draftDisplayFields, filter_fields: state.draftFilterFields},
                    });
                }
                state.displayFields = state.draftDisplayFields.map((field) => ({...field}));
                state.filterFields = state.draftFilterFields.map((field) => ({...field}));
                state.columnMerges = columnMerges;
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
                localStorage.removeItem(preferencesKey);
                state.columnMerges = {};
                if (!isLocal && capabilities.preferences === true) {
                    await request(config, 'clearConfig', {...payloadBase(), table_id: config.id, type: 'all'});
                    await loadFields();
                } else {
                    state.displayFields = defaultDisplayFields.map((field) => ({...field}));
                    state.filterFields = defaultFilterFields.map((field) => ({...field}));
                }
                renderFilters();
                await loadData();
                UI.dialog.close(configDialog, 'cleared');
                UI.toast.success(translate('字段配置已重置。'));
            } catch (error) {
                showError(error);
            }
        };

        const exportData = async (format) => {
            if (capabilities.export !== true) {
                showError(new Error(translate('当前上下文不允许导出。')));
                return;
            }
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

        const handleAction = (action, event = null) => {
            const name = action.dataset.wDatatableAction || '';
            if (name === 'reload') void loadData();
            if (name === 'sort') {
                const field = action.dataset.field || '';
                const current = state.sorts[field] || '';
                const next = current === 'asc' ? 'desc' : (current === 'desc' ? '' : 'asc');
                const sorts = event?.shiftKey ? {...state.sorts} : {};
                if (next) sorts[field] = next;
                else delete sorts[field];
                state.sorts = sorts;
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
            if (name === 'form.open' && capabilities.create === true) void openForm('add');
            if (name === 'row.edit' && capabilities.update === true) void openForm('edit', Number(action.dataset.rowIndex));
            if (name === 'row.delete' && capabilities.delete === true) void deleteRow(Number(action.dataset.rowIndex));
            if (name === 'export' && capabilities.export === true) void exportData(action.dataset.format || 'csv');
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
            const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
            const rowEvent = path.find((node) => node instanceof HTMLElement && node.dataset.wDatatableRowEvent)
                || (event.target instanceof Element ? event.target.closest('[data-w-datatable-row-event]') : null);
            if (rowEvent instanceof HTMLElement) {
                event.preventDefault();
                const rowIndex = Number(rowEvent.dataset.rowIndex);
                const row = state.data[rowIndex];
                element.dispatchEvent(new CustomEvent('weline:datatable:row-action', {
                    bubbles: true,
                    detail: {
                        tableId: config.id,
                        action: rowEvent.dataset.wDatatableRowEvent || 'action',
                        row,
                        rowIndex,
                    },
                }));
                return;
            }
            const target = event.target instanceof Element ? event.target.closest('[data-w-datatable-action]') : null;
            if (!(target instanceof HTMLElement)) return;
            event.preventDefault();
            handleAction(target, event);
            target.closest('details')?.removeAttribute('open');
        };
        listen(element, 'click', clickHandler);
        if (configDialog) {
            listen(configDialog, 'click', clickHandler);
            listen(configDialog, 'change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLSelectElement) || !target.matches('[data-w-datatable-merge-field]')) return;
                setDraftColumnMerge(target.dataset.wDatatableMergeField || '', target.value);
            });
        }
        listen(body, 'dblclick', (event) => {
            const cell = event.target instanceof Element ? event.target.closest('td[data-editable="true"]') : null;
            if (cell) startInlineEdit(cell);
        });
        if (filterForm) {
            const filterMode = filterForm.dataset.filterMode || (isLocal ? 'client' : 'ajax');
            if (filterMode !== 'page') {
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
            if (filterMode !== 'page' && filterForm.dataset.filterDisplay === 'simple') {
                let filterTimer = 0;
                listen(filterForm, 'input', () => {
                    window.clearTimeout(filterTimer);
                    filterTimer = window.setTimeout(() => {
                        state.filters = readFilters();
                        state.page = 1;
                        void loadData();
                    }, 250);
                });
                listen(filterForm, 'change', () => {
                    state.filters = readFilters();
                    state.page = 1;
                    void loadData();
                });
            }
        }
        if (searchForm && config.filterMode !== 'page') {
            const applySearch = () => {
                state.search = searchInput instanceof HTMLInputElement ? searchInput.value.trim() : '';
                state.page = 1;
                void loadData();
            };
            listen(searchForm, 'submit', (event) => {
                event.preventDefault();
                applySearch();
            });
            if (searchInput instanceof HTMLInputElement) {
                let searchTimer = 0;
                listen(searchInput, 'input', () => {
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(applySearch, 250);
                });
            }
        }
        if (pageSizeControl instanceof HTMLSelectElement) {
            listen(pageSizeControl, 'change', () => {
                state.pageSize = Math.max(1, Math.min(100, Number(pageSizeControl.value) || 20));
                state.page = 1;
                void loadData();
            });
        }
        listen(body, 'keydown', (event) => {
            const row = event.target instanceof Element ? event.target.closest('tr[data-row-index]') : null;
            if (!(row instanceof HTMLTableRowElement) || event.target !== row || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            const rows = [...body.querySelectorAll('tr[data-row-index]')];
            const index = rows.indexOf(row);
            const target = rows[index + (event.key === 'ArrowDown' ? 1 : -1)];
            if (target instanceof HTMLElement) target.focus();
        });
        listen(document, 'weline:datatable:form:saved', (event) => {
            if (event instanceof CustomEvent && event.detail?.formId === config.formId) void loadData();
        });

        listen(element, 'change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) return;
            if (target.matches('[data-w-datatable-select-all]')) {
                element.querySelectorAll('.w-datatable__row-select').forEach((box) => {
                    if (box instanceof HTMLInputElement) box.checked = target.checked;
                });
                syncSelection();
                return;
            }
            if (target.classList.contains('w-datatable__row-select')) {
                syncSelection();
            }
        });

        queueMicrotask(async () => {
            if (isLocal) {
                state.displayFields = templateDisplayFields.length
                    ? templateDisplayFields
                    : state.displayFields;
                state.filterFields = templateFilterFields;
                applyLocalPreferences();
                renderFilters();
                if (!state.destroyed) loadLocalData();
                return;
            }
            await loadFields();
            applyLocalPreferences();
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
