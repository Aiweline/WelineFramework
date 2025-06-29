/**
 * 数据表格管理器
 */
window.DataTableManager = {
    // 表格实例缓存
    instances: {},

    /**
     * 字段类型选项
     */
    fieldTypeOptions: [
        { value: 'text', label: '文本' },
        { value: 'number', label: '数字' },
        { value: 'date', label: '日期' },
        { value: 'select', label: '下拉选项' },
        { value: 'email', label: '邮箱' },
        { value: 'tel', label: '电话' },
        { value: 'url', label: '网址' },
        { value: 'image', label: '图片' }
    ],

    /**
     * 初始化表格
     */
    initTable: function(selector, options) {
        const $container = $(selector);
        const tableId = $container.attr('id');
        
        if (this.instances[tableId]) {
            return this.instances[tableId];
        }

        // 自动推断API基础路径
        let apiUrl = options.apiUrl;
        if (!apiUrl && typeof window.api === 'function') {
            apiUrl = window.api('datatable/rest/v1/data-table');
        } else if (!apiUrl) {
            apiUrl = '/datatable/rest/v1/data-table';
        }

        const instance = {
            container: $container,
            options: options,
            currentPage: 1,
            pageSize: options.pageSize || 20,
            data: [],
            config: {},
            filters: {},
            search: '',
            sorts: {},
            isEditing: false,
            editingRow: null,
            editingData: {},
            apiUrl: apiUrl,
            allFields: [],
            displayFields: [],
            filterFields: []
        };

        this.instances[tableId] = instance;
        
        // 初始化时加载字段配置
        this.loadFieldsOnInit(instance);

        return instance;
    },

    /**
     * 初始化时加载字段配置
     */
    loadFieldsOnInit: function(instance) {
        console.log('loadFieldsOnInit: 开始加载字段配置', {
            model: instance.options.model,
            scope: instance.options.scope
        });

        // 先尝试从HTML中初始化基础配置
        this.initFromHTML(instance);
        
        // 然后加载字段配置
        this.loadModelFields(instance.container.attr('id'));
    },

    /**
     * 从HTML中初始化配置（支持data-w-field属性）
     */
    initFromHTML: function(instance) {  
        const $thead = instance.container.find('thead');
        const $filter = instance.container.find('.datatable-filter');
        
        // 优先从th[data-w-field]读取字段配置
        const fields = [];
        $thead.find('th[data-w-field]').each(function() {
            try {
                const fieldConfig = JSON.parse($(this).attr('data-w-field'));
                fields.push(fieldConfig);
            } catch(e) {
                // fallback: 兼容旧结构
                const $th = $(this);
                const fieldName = $th.data('field');
                if(fieldName) fields.push({name: fieldName, label: $th.text().trim(), type: 'text', visible: true});
            }
        });
        
        // 设置基础配置
        instance.config = {
            fields: fields,
            pageSize: instance.pageSize,
            showPagination: instance.options.showPagination !== false,
            showToolbar: instance.options.showToolbar !== false,
            showConfig: instance.options.showConfig !== false
        };
        
        // 初始化过滤器
        this.initFilters(instance, $filter);
        
        console.log('initFromHTML: 基础配置初始化完成', {
            fieldsCount: fields.length,
            config: instance.config
        });
        
        // 注意：这里不渲染表格，等字段配置加载完成后再渲染
    },

    /**
     * 初始化过滤器
     */
    initFilters: function(instance, $filter) {
        // 绑定过滤器事件
        $filter.find('button[onclick*="search"]').off('click').on('click', function() {
            DataTableManager.applyFilters(instance);
        });
        
        $filter.find('button[onclick*="reset"]').off('click').on('click', function() {
            DataTableManager.resetFilters(instance);
        });
    },

    /**
     * 应用过滤器
     */
    applyFilters: function(instance) {
        const $filter = instance.container.find('.datatable-filter');
        instance.filters = {};
        
        $filter.find('[data-field]').each(function() {
            const $input = $(this);
            const fieldName = $input.data('field');
            const value = $input.val();
            
            if (value !== '' && value !== null && value !== undefined) {
                instance.filters[fieldName] = value;
            }
        });
        
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 重置过滤器
     */
    resetFilters: function(instance) {
        const $filter = instance.container.find('.datatable-filter');
        
        $filter.find('[data-field]').each(function() {
            const $input = $(this);
            if ($input.attr('type') === 'checkbox') {
                $input.prop('checked', false);
            } else {
                $input.val('');
            }
        });
        
        instance.filters = {};
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 渲染表格
     */
    renderTable: function(instance) {
        const $tbody = instance.container.find('tbody');
        
        // 只渲染数据行，不重新渲染表头
        this.renderBody(instance, $tbody);
        
        // 渲染分页
        this.renderPagination(instance);
    },

    /**
     * 解析URL中的排序参数
     */
    parseUrlSortParams: function() {
        const urlParams = new URLSearchParams(window.location.search);
        const current = urlParams.get('current');
        const sortParams = {};
        
        // 解析sort参数，如sort.store_id=desc
        for (const [key, value] of urlParams.entries()) {
            if (key.startsWith('sort.')) {
                const fieldName = key.replace('sort.', '');
                sortParams[fieldName] = value;
            }
        }
        
        return {
            current: current,
            sorts: sortParams
        };
    },

    /**
     * 渲染表头
     */
    renderHeader: function(instance, $thead) {
        // 解析URL中的排序参数
        const urlSortParams = this.parseUrlSortParams();
        console.log('renderHeader: 开始渲染表头', {
            fieldsCount: instance.config.fields?.length || 0,
            fields: instance.config.fields?.map(f => ({name: f.name, label: f.label, visible: f.visible})) || [],
            urlSortParams: urlSortParams
        });
        
        let headerHtml = '<tr>';
        
        if (!instance.config.fields || instance.config.fields.length === 0) {
            console.warn('renderHeader: 没有字段配置，使用默认字段');
            // 如果没有字段配置，使用HTML中的默认字段
            $thead.find('th[data-w-field]').each(function() {
                try {
                    const fieldConfig = JSON.parse($(this).attr('data-w-field'));
                    if (fieldConfig.visible !== false) {
                        headerHtml += `
                            <th data-field="${fieldConfig.name}" 
                                data-sortable="${fieldConfig.sortable}" 
                                data-resizable="${fieldConfig.resizable !== false}"
                                style="width: ${fieldConfig.width || 'auto'}; min-width: ${fieldConfig.minWidth || 'auto'};">
                                <div class="th-content">
                                    <a href="#" class="sort-link" data-field="${fieldConfig.name}">
                                        <span class="th-text">${fieldConfig.label}</span>
                                        ${fieldConfig.sortable ? '<i class="fa fa-sort"></i>' : ''}
                                    </a>
                                </div>
                                ${fieldConfig.resizable !== false ? '<div class="resize-handle"></div>' : ''}
                            </th>
                        `;
                    }
                } catch(e) {
                    console.error('renderHeader: 解析字段配置失败', e);
                }
            });
        } else {
            // 使用用户设置的字段配置
            instance.config.fields.forEach(field => {
                if (field.visible) {
                    // 确定排序状态：优先使用URL参数，其次使用实例中的排序状态
                    let sortDirection = null;
                    let sortClass = '';
                    let sortIcon = '';
                    
                    if (urlSortParams.sorts[field.name]) {
                        // URL中有该字段的排序参数
                        sortDirection = urlSortParams.sorts[field.name];
                        sortClass = `sort-${sortDirection}`;
                        sortIcon = sortDirection === 'asc' ? 
                            '<i class="fa fa-sort-up"></i>' : 
                            '<i class="fa fa-sort-down"></i>';
                    } else if (instance.sorts[field.name]) {
                        // 实例中有该字段的排序状态
                        sortDirection = instance.sorts[field.name];
                        sortClass = `sort-${sortDirection}`;
                        sortIcon = sortDirection === 'asc' ? 
                            '<i class="fa fa-sort-up"></i>' : 
                            '<i class="fa fa-sort-down"></i>';
                    } else if (field.sortable) {
                        // 字段可排序但未排序
                        sortIcon = '<i class="fa fa-sort"></i>';
                    }
                    
                    // 检查是否为当前排序字段
                    const isCurrentSort = urlSortParams.current === field.name;
                    const currentClass = isCurrentSort ? 'active text-info' : '';
                    
                    headerHtml += `
                        <th data-field="${field.name}" 
                            data-sortable="${field.sortable}" 
                            data-resizable="${field.resizable !== false}"
                            style="width: ${field.width || 'auto'}; min-width: ${field.minWidth || 'auto'};"
                            class="${sortClass} ${currentClass}">
                            <div class="th-content">
                                <a href="#" class="sort-link" data-field="${field.name}">
                                <span class="th-text">${field.label}</span>
                                    ${sortIcon}
                                </a>
                            </div>
                            ${field.resizable !== false ? '<div class="resize-handle"></div>' : ''}
                        </th>
                    `;
                }
            });
        }
        
        if (instance.options.editable) {
            headerHtml += '<th class="actions-column" style="width: 120px;">操作</th>';
        }
        
        headerHtml += '</tr>';
        $thead.html(headerHtml);
        
        console.log('renderHeader: 表头渲染完成', {
            headerHtml: headerHtml,
            theadLength: $thead.find('th').length
        });
        
        // 绑定表头事件
        this.bindHeaderEvents(instance, $thead);
    },

    /**
     * 渲染数据行
     */
    renderBody: function(instance, $tbody) {
        if (!instance.data || instance.data.length === 0) {
            // 计算总列数（包括操作列）
            const totalColumns = instance.config.fields.length + (instance.options.editable ? 1 : 0);
            $tbody.html(`<tr><td colspan="${totalColumns}" class="text-center">暂无数据</td></tr>`);
            return;
        }

        let bodyHtml = '';
        
        instance.data.forEach((row, index) => {
            bodyHtml += '<tr data-row-index="' + index + '">';
            
            // 渲染数据列
            instance.config.fields.forEach(field => {
                if (field.visible) {
                    const value = row[field.name] || '';
                    const cellClass = field.editable ? 'editable-cell' : '';
                    
                    bodyHtml += `
                        <td data-field="${field.name}" class="${cellClass}">
                            <div class="cell-content">${this.formatCellValue(value, field)}</div>
                            ${field.editable ? '<div class="edit-overlay"><i class="fas fa-edit"></i></div>' : ''}
                        </td>
                    `;
                }
            });
            
            // 渲染操作列
            if (instance.options.editable) {
                bodyHtml += `
                    <td class="actions-cell">
                        <button class="btn btn-sm btn-primary edit-row-btn" data-row-index="${index}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger delete-row-btn" data-row-index="${index}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            }
            
            bodyHtml += '</tr>';
        });
        
        $tbody.html(bodyHtml);
        
        // 绑定行事件
        this.bindRowEvents(instance, $tbody);
    },

    /**
     * 格式化单元格值
     */
    formatCellValue: function(value, field) {
        if (field.formatter && typeof window[field.formatter] === 'function') {
            return window[field.formatter](value, field);
        }
        
        switch (field.type) {
            case 'date':
                return value ? new Date(value).toLocaleDateString() : '';
            case 'datetime':
                return value ? new Date(value).toLocaleString() : '';
            case 'number':
                return value ? Number(value).toLocaleString() : '';
            case 'boolean':
                return value ? '<span class="badge bg-success">是</span>' : '<span class="badge bg-secondary">否</span>';
            default:
                return value;
        }
    },

    /**
     * 渲染分页
     */
    renderPagination: function(instance) {
        const $pagination = instance.container.find('.datatable-pagination');
        
        if (!instance.options.showPagination || !instance.pagination) {
            $pagination.hide();
            return;
        }
        
        const pagination = instance.pagination;
        let paginationHtml = `
            <div class="pagination-info">
                显示第 ${(pagination.page - 1) * pagination.pageSize + 1} 到 
                ${Math.min(pagination.page * pagination.pageSize, pagination.total)} 条，
                共 ${pagination.total} 条记录
            </div>
            <ul class="pagination">
        `;
        
        // 上一页
        paginationHtml += `
            <li class="page-item ${pagination.hasPrevPage ? '' : 'disabled'}">
                <a class="page-link" href="#" data-page="${pagination.page - 1}">上一页</a>
            </li>
        `;
        
        // 页码
        const startPage = Math.max(1, pagination.page - 2);
        const endPage = Math.min(pagination.lastPage, pagination.page + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === pagination.page ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `;
        }
        
        // 下一页
        paginationHtml += `
            <li class="page-item ${pagination.hasNextPage ? '' : 'disabled'}">
                <a class="page-link" href="#" data-page="${pagination.page + 1}">下一页</a>
            </li>
        `;
        
        paginationHtml += '</ul>';
        
        $pagination.html(paginationHtml).show();
        
        // 绑定分页事件
        this.bindPaginationEvents(instance, $pagination);
    },

    /**
     * 加载数据
     */
    loadData: function(instance) {
        const $loading = instance.container.find('.datatable-loading');
        const $content = instance.container.find('.datatable-content');
        
        console.log('开始加载数据:', {
            model: instance.options.model,
            scope: instance.options.scope,
            page: instance.currentPage,
            pageSize: instance.pageSize,
            filters: instance.filters
        });
        
        $loading.show();
        $content.hide();
        
        $.ajax({
            url: window.api('datatable/rest/v1/data-table/data'),
            type: 'POST',
            data: {
                model: instance.options.model,
                scope: instance.options.scope,
                page: instance.currentPage,
                pageSize: instance.pageSize,
                search: instance.search,
                filters: instance.filters,
                sorts: instance.sorts
            },
            success: (response) => {
                console.log('API响应:', response);
                $loading.hide();
                $content.show();
                
                if (response.code === 200) {
                    instance.data = response.data.data || [];
                    instance.pagination = response.data.pagination;
                    this.renderTable(instance);
                } else {
                    console.error('API错误:', response.msg);
                    this.showError(response.msg || '加载数据失败');
                }
            },
            error: (xhr, status, error) => {
                console.error('AJAX错误:', {xhr, status, error});
                $loading.hide();
                $content.show();
                this.showError('加载数据失败: ' + error);
            }
        });
    },

    /**
     * 搜索
     */
    search: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;
        
        instance.search = instance.container.find('#search-input-' + scope).val();
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 清除搜索
     */
    clearSearch: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;
        
        instance.container.find('#search-input-' + scope).val('');
        instance.search = '';
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 应用过滤器
     */
    applyFilter: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;
        
        const $form = instance.container.find('#filter-form-' + scope);
        instance.filters = {};
        
        $form.find('[data-field]').each(function() {
            const field = $(this).data('field');
            const value = $(this).val();
            if (value !== '' && value !== null) {
                instance.filters[field] = value;
            }
        });
        
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 清除过滤器
     */
    clearFilter: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;
        
        instance.container.find('#filter-form-' + scope)[0].reset();
        instance.filters = {};
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 保存过滤器
     */
    saveFilter: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;
        
        const filterName = prompt('请输入过滤器名称：');
        if (!filterName) return;
        
        const $form = instance.container.find('#filter-form-' + scope);
        const filterData = {};
        
        $form.find('[data-field]').each(function() {
            const field = $(this).data('field');
            const value = $(this).val();
            filterData[field] = value;
        });
        
        // 保存到本地存储
        const savedFilters = JSON.parse(localStorage.getItem('datatable_filters_' + scope) || '{}');
        savedFilters[filterName] = filterData;
        localStorage.setItem('datatable_filters_' + scope, JSON.stringify(savedFilters));
        
        this.showSuccess(scope, '过滤器保存成功');
    },

    /**
     * 保存表格配置
     */
    saveTableConfig: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;
        
        // 收集配置数据
        const config = {
            fields: instance.config.fields,
            pageSize: instance.pageSize,
            showPagination: instance.options.showPagination,
            showToolbar: instance.options.showToolbar,
            showConfig: instance.options.showConfig
        };
        
        $.ajax({
            url: window.api('datatable/rest/v1/data-table/save-config'),
            type: 'POST',
            data: {
                scope: scope,
                config: config
            },
            success: (response) => {
                if (response.code === 200) {
                    this.showSuccess(scope, '配置保存成功');
                    $('#table-config-modal-' + scope).modal('hide');
                } else {
                    this.showError(scope, response.msg);
                }
            },
            error: () => {
                this.showError(scope, '保存配置失败');
            }
        });
    },

    /**
     * 编辑行
     */
    editRow: function(instance, rowIndex) {
        if (instance.isEditing) {
            this.showWarning(instance.container.attr('id'), '请先保存当前编辑的行');
            return;
        }
        
        const row = instance.data[rowIndex];
        if (!row) return;
        
        instance.isEditing = true;
        instance.editingRow = rowIndex;
        instance.editingData = { ...row };
        
        // 显示编辑模态框
        this.showEditModal(instance, row);
    },

    /**
     * 显示编辑模态框
     */
    showEditModal: function(instance, row) {
        const modalId = 'edit-modal-' + instance.container.attr('id');
        const editTitle = __('编辑数据');
        let modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${editTitle}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="edit-form-${modalId}">
        `;
        
        instance.config.fields.forEach(field => {
            if (field.editable) {
                const value = row[field.name] || '';
                modalHtml += this.renderEditField(field, value);
            }
        });
        const saveBtnText = __('保存');
        const cancelBtnText = __('取消');
        modalHtml += `
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${cancelBtnText}</button>
                            <button type="button" class="btn btn-primary" onclick="DataTableManager.saveRow('${instance.container.attr('id')}')">${saveBtnText}</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // 移除已存在的模态框
        $('#' + modalId).remove();
        
        // 添加新模态框
        $('body').append(modalHtml);
        
        // 显示模态框
        $('#' + modalId).modal('show');
        
        // 绑定模态框事件
        this.bindEditModalEvents(instance, modalId);
    },

    /**
     * 渲染编辑字段
     */
    renderEditField: function(field, value) {
        const fieldId = 'edit-' + field.name;

        const pleaseSelect = __('请选择');
        
        switch (field.type) {
            case 'textarea':
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <textarea class="form-control" id="${fieldId}" name="${field.name}" rows="3">${value}</textarea>
                    </div>
                `;
            case 'select':
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <select class="form-control" id="${fieldId}" name="${field.name}">
                            <option value="">${pleaseSelect}</option>
                            ${field.options ? field.options.map(opt => 
                                `<option value="${opt.value}" ${value == opt.value ? 'selected' : ''}>${opt.label}</option>`
                            ).join('') : ''}
                        </select>
                    </div>
                `;
            case 'checkbox':
                return `
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="${fieldId}" name="${field.name}" value="1" ${value ? 'checked' : ''}>
                            <label class="form-check-label" for="${fieldId}">${field.label}</label>
                        </div>
                    </div>
                `;
            default:
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <input type="${field.type}" class="form-control" id="${fieldId}" name="${field.name}" value="${value}">
                    </div>
                `;
        }
    },

    /**
     * 保存行数据
     */
    saveRow: function(tableId) {
        const instance = this.instances[tableId];
        if (!instance || !instance.isEditing) return;
        
        const modalId = 'edit-modal-' + tableId;
        const $form = $('#' + modalId + ' form');
        const formData = {};
        
        $form.find('[name]').each(function() {
            const name = $(this).attr('name');
            let value = $(this).val();
            
            if ($(this).attr('type') === 'checkbox') {
                value = $(this).prop('checked') ? 1 : 0;
            }
            
            formData[name] = value;
        });
        
        // 添加ID
        formData.id = instance.data[instance.editingRow].id;
        
        $.ajax({
            url: window.api('datatable/rest/v1/data-table/save-data'),
            type: 'POST',
            data: {
                model: instance.options.model,
                data: formData
            },
            success: (response) => {
                if (response.code === 200) {
                    this.showSuccess(tableId, __('保存成功'));
                    $('#' + modalId).modal('hide');
                    instance.isEditing = false;
                    instance.editingRow = null;
                    instance.editingData = {};
                    this.loadData(instance);
                } else {
                    this.showError(tableId, response.msg);
                }
            },
            error: () => {
                this.showError(tableId, __('保存失败'));
            }
        });
    },

    /**
     * 删除行
     */
    deleteRow: function(instance, rowIndex) {
        if (!confirm(__('确定要删除这条记录吗？'))) return;
        
        const row = instance.data[rowIndex];
        if (!row || !row.id) return;
        
        $.ajax({
            url: window.api('datatable/rest/v1/data-table/delete-data'),
            type: 'POST',
            data: {
                model: instance.options.model,
                id: row.id
            },
            success: (response) => {
                if (response.code === 200) {
                    this.showSuccess(instance.container.attr('id'), __('删除成功'));
                    this.loadData(instance);
                } else {
                    this.showError(instance.container.attr('id'), response.msg);
                }
            },
            error: () => {
                this.showError(instance.container.attr('id'), __('删除失败'));
            }
        });
    },

    /**
     * 绑定事件
     */
    bindEvents: function(instance) {
        // 搜索事件
        instance.container.find('#search-input-' + instance.options.scope).on('keypress', (e) => {
            if (e.which === 13) {
                this.search(instance.options.scope);
            }
        });
        
        // 过滤器事件
        instance.container.find('#filter-form-' + instance.options.scope).on('submit', (e) => {
            e.preventDefault();
            this.applyFilter(instance.options.scope);
        });
        
        // 窗口关闭前提示
        window.addEventListener('beforeunload', (e) => {
            if (instance.isEditing) {
                e.preventDefault();
                e.returnValue = __('您有未保存的编辑内容，确定要离开吗？');
            }
        });
    },

    /**
     * 绑定表头事件
     */
    bindHeaderEvents: function(instance, $thead) {
        // 排序链接点击事件
        $thead.on('click', '.sort-link', function(e) {
            e.preventDefault();
            const field = $(this).data('field');
            const currentSort = instance.sorts[field];
            let newSortDirection;
            
            // 确定新的排序方向
            if (currentSort === 'asc') {
                newSortDirection = 'desc';
            } else if (currentSort === 'desc') {
                newSortDirection = null; // 取消排序
            } else {
                newSortDirection = 'asc';
            }
            
            // 更新实例中的排序状态
            if (newSortDirection) {
                instance.sorts[field] = newSortDirection;
            } else {
                delete instance.sorts[field];
            }
            
            // 更新URL参数
            DataTableManager.updateUrlSortParams(field, newSortDirection);
            
            // 重新加载数据
            DataTableManager.loadData(instance);
        });
        
        // 兼容旧的排序事件（点击整个th）
        $thead.on('click', '[data-sortable="true"]', function(e) {
            // 如果点击的是排序链接，不处理（避免重复）
            if ($(e.target).closest('.sort-link').length) {
                return;
            }
            
            const field = $(this).data('field');
            const currentSort = instance.sorts[field];
            
            if (currentSort === 'asc') {
                instance.sorts[field] = 'desc';
            } else if (currentSort === 'desc') {
                delete instance.sorts[field];
            } else {
                instance.sorts[field] = 'asc';
            }
            
            DataTableManager.loadData(instance);
        });
        
        // 列宽调整事件
        $thead.on('mousedown', '.resize-handle', function(e) {
            e.preventDefault();
            const $th = $(this).parent();
            const startX = e.clientX;
            const startWidth = $th.width();
            
            const onMouseMove = function(e) {
                const newWidth = startWidth + (e.clientX - startX);
                $th.css('width', Math.max(50, newWidth) + 'px');
            };
            
            const onMouseUp = function() {
                $(document).off('mousemove', onMouseMove).off('mouseup', onMouseUp);
                
                // 保存列宽配置
                const field = $th.data('field');
                const width = $th.width() + 'px';
                
                instance.config.fields.forEach(f => {
                    if (f.name === field) {
                        f.width = width;
                    }
                });
            };
            
            $(document).on('mousemove', onMouseMove).on('mouseup', onMouseUp);
        });
    },

    /**
     * 更新URL中的排序参数
     */
    updateUrlSortParams: function(field, sortDirection) {
        const url = new URL(window.location);
        const urlParams = url.searchParams;
        
        if (sortDirection) {
            // 设置排序参数
            urlParams.set('current', field);
            urlParams.set(`sort.${field}`, sortDirection);
        } else {
            // 取消排序
            urlParams.delete('current');
            urlParams.delete(`sort.${field}`);
        }
        
        // 更新URL（不刷新页面）
        window.history.replaceState({}, '', url.toString());
        console.log('updateUrlSortParams: URL已更新', url.toString());
    },

    /**
     * 绑定行事件
     */
    bindRowEvents: function(instance, $tbody) {
        // 编辑按钮事件
        $tbody.on('click', '.edit-row-btn', function() {
            const rowIndex = $(this).data('row-index');
            DataTableManager.editRow(instance, rowIndex);
        });
        
        // 删除按钮事件
        $tbody.on('click', '.delete-row-btn', function() {
            const rowIndex = $(this).data('row-index');
            DataTableManager.deleteRow(instance, rowIndex);
        });
        
        // 单元格编辑事件
        $tbody.on('click', '.editable-cell', function() {
            const $cell = $(this);
            const field = $cell.data('field');
            const value = $cell.find('.cell-content').text();
            
            // 创建内联编辑器
            const $input = $('<input type="text" class="form-control form-control-sm">').val(value);
            $cell.find('.cell-content').hide();
            $cell.append($input);
            $input.focus();
            
            $input.on('blur keypress', function(e) {
                if (e.type === 'blur' || e.which === 13) {
                    const newValue = $(this).val();
                    $cell.find('.cell-content').text(newValue).show();
                    $(this).remove();
                    
                    // 保存数据
                    const rowIndex = $cell.closest('tr').data('row-index');
                    const row = instance.data[rowIndex];
                    if (row) {
                        row[field] = newValue;
                        DataTableManager.saveRowData(instance, row);
                    }
                }
            });
        });
    },

    /**
     * 绑定分页事件
     */
    bindPaginationEvents: function(instance, $pagination) {
        $pagination.on('click', '.page-link', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && page > 0 && page <= instance.pagination.lastPage) {
                instance.currentPage = page;
                DataTableManager.loadData(instance);
            }
        });
    },

    /**
     * 绑定编辑模态框事件
     */
    bindEditModalEvents: function(instance, modalId) {
        $('#' + modalId).on('hidden.bs.modal', function() {
            if (instance.isEditing) {
                instance.isEditing = false;
                instance.editingRow = null;
                instance.editingData = {};
            }
        });
    },

    /**
     * 根据scope获取实例
     */
    getInstanceByScope: function(scope) {
        for (const tableId in this.instances) {
            if (this.instances[tableId].options.scope === scope) {
                return this.instances[tableId];
            }
        }
        return null;
    },

    /**
     * 保存行数据
     */
    saveRowData: function(instance, row) {
        $.ajax({
            url: window.api('datatable/rest/v1/data-table/save-data'),
            type: 'POST',
            data: {
                model: instance.options.model,
                data: row
            },
            success: (response) => {
                if (response.code !== 200) {
                    this.showError(instance.container.attr('id'), response.msg);
                }
            },
            error: () => {
                this.showError(instance.container.attr('id'), '保存失败');
            }
        });
    },

    /**
     * 显示成功信息
     */
    showSuccess: function(tableId, message) {
        // 创建临时成功提示
        const successHtml = `
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i>
                <strong>成功：</strong>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        // 在模态框顶部显示成功消息
        const $modalBody = $(`#field-config-modal-${tableId} .modal-body`);
        $modalBody.prepend(successHtml);
        
        // 3秒后自动隐藏
        setTimeout(() => {
            $(`#field-config-modal-${tableId} .alert-success`).fadeOut();
        }, 3000);
    },

    /**
     * 显示错误信息
     */
    showError: function(tableId, message) {
        const $error = $('#error-' + tableId);
        const $content = $('#content-' + tableId);
        
        $error.html(`
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>错误：</strong>${message}
                <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="DataTableManager.loadModelFields('${tableId}')">
                    <i class="fas fa-redo"></i> 重试
                </button>
            </div>
        `);
        $error.show();
        $content.hide();
    },

    /**
     * 显示警告消息
     */
    showWarning: function(tableId, message) {
        alert('警告: ' + message);
    },

    /**
     * 初始化表格主体
     */
    initBody: function(scope, options) {
        console.log('初始化表格主体:', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-body', ''));
        if (instance) {
            instance.bodyConfig = options;
            // 可以在这里添加表格主体的特定初始化逻辑
        }
    },

    /**
     * 初始化表格底部
     */
    initFooter: function(scope, options) {
        console.log('初始化表格底部:', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            instance.footerConfig = options;
            // 可以在这里添加表格底部的特定初始化逻辑
        }
    },

    /**
     * 初始化表头
     */
    initHeader: function(scope, options) {
        console.log('初始化表头:', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-header', ''));
        if (instance) {
            instance.headerConfig = options;
            // 可以在这里添加表头的特定初始化逻辑
        }
    },

    /**
     * 初始化过滤器
     */
    initFilter: function(scope, options) {
        console.log('初始化过滤器:', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-filter', ''));
        if (instance) {
            instance.filterConfig = options;
            // 可以在这里添加过滤器的特定初始化逻辑
        }
    },

    /**
     * 字段配置弹窗tab切换（自定义w-前缀）
     */
    bindFieldConfigTabs: function(tableId) {
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (!modal) return;
        var tabLinks = modal.querySelectorAll('.w-nav-link');
        tabLinks.forEach(function(link) {
            link.onclick = function() {
                // 取消所有tab激活
                tabLinks.forEach(function(l) { l.classList.remove('active'); });
                link.classList.add('active');
                // 切换内容区
                var target = link.getAttribute('data-w-target');
                var tabPanes = modal.querySelectorAll('.w-tab-pane');
                tabPanes.forEach(function(pane) {
                    pane.classList.remove('w-show','active');
                });
                var showPane = modal.querySelector(target);
                if (showPane) {
                    showPane.classList.add('w-show','active');
                }
            };
        });
    },

    // 修改openFieldConfig，弹窗打开时绑定tab切换
    openFieldConfig: function(tableId) {
        document.querySelectorAll('.w-modal').forEach(function(modal) {
            modal.style.display = 'none';
        });
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (modal) {
            modal.style.display = 'flex';
            
            // 检查是否已经有缓存的字段数据
            const instance = DataTableManager.instances[tableId];
            if (instance && instance.allFields && instance.allFields.length > 0) {
                console.log('openFieldConfig: 使用缓存的字段数据', {
                    allFields: instance.allFields.length,
                    displayFields: instance.displayFields.length,
                    filterFields: instance.filterFields.length
                });
                
                // 直接渲染缓存的字段数据
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            } else {
                // 只在未加载时请求字段
                if (!modal.dataset.wFieldsLoaded) {
                    DataTableManager.loadModelFields(tableId);
                    modal.dataset.wFieldsLoaded = '1';
                }
            }
            
            DataTableManager.bindFieldConfigTabs(tableId);
            setTimeout(function() {
                var firstInput = modal.querySelector('input,select,textarea,button');
                if(firstInput) firstInput.focus();
            }, 200);
        }
    },

    /**
     * 关闭字段配置自定义弹窗（w-modal）
     */
    closeFieldConfig: function(tableId) {
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (modal) {
            modal.style.display = 'none';
            // 关闭时重置加载标志
            delete modal.dataset.wFieldsLoaded;
        }
    },

    /**
     * 加载模型字段配置
     */
    loadModelFields: function(tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl未设置，无法加载字段配置！');
            return;
        }
        
        console.log('loadModelFields: 开始加载字段配置', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });
        
        // 检查是否在字段配置弹窗中
        const isInFieldConfig = $('#w-field-config-modal-' + tableId).length > 0;
        
        // 检查是否已经加载过字段配置
        if (instance.allFields && instance.allFields.length > 0) {
            console.log('loadModelFields: 使用缓存的字段配置', {
                allFields: instance.allFields.length,
                displayFields: instance.displayFields.length,
                filterFields: instance.filterFields.length
            });
            
            if (isInFieldConfig) {
                // 在字段配置弹窗中，直接渲染缓存的字段数据
                this.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            } else {
                // 不在字段配置弹窗中，直接调用rebuildTableFromConfig进行完整重新构建
                console.log('loadModelFields: 使用缓存字段配置重新构建表格');
                this.rebuildTableFromConfig(tableId, instance.displayFields, instance.filterFields);
            }
            return;
        }
        
        if (isInFieldConfig) {
            // 在字段配置弹窗中，只更新弹窗内容
            const $loading = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId).find('.fa-spinner').parent();
            const $error = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId).find('.w-error');
            const $content = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId);

        $loading.show();
        $error.hide();
        $content.hide();
        }

        $.ajax({
            url: instance.apiUrl+'/fields',
            method: 'POST',
            data: {
                table_id: tableId,
                model: instance.options.model,
                scope: instance.options.scope
            },
            success: (response) => {
                console.log('loadModelFields: API响应', response);
                
                if (isInFieldConfig) {
                    const $loading = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId).find('.fa-spinner').parent();
                    const $error = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId).find('.w-error');
                    const $content = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId);
                    
                $loading.hide();
                
                if (response.code === 200 && response.data) {
                    const data = response.data;
                    if (data.all_fields && Array.isArray(data.all_fields)) {
                        this.renderModelFieldsFromData(tableId, data);
                        // 根据新的字段配置重新渲染表格
                        DataTableManager.rebuildTableFromConfig(tableId, data.display_fields, data.filter_fields);
                        $content.show();
                    } else {
                        this.showError(tableId, '数据格式错误');
                    }
                } else {
                    this.showError(tableId, response.message || '获取字段失败');
                    }
                } else {
                    // 不在字段配置弹窗中，应用字段配置到表格
                    if (response.code === 200 && response.data) {
                        const data = response.data;
                        if (data.all_fields && Array.isArray(data.all_fields)) {
                            console.log('loadModelFields: 应用字段配置到表格', data);
                            
                            // 保存字段数据到实例
                            instance.allFields = data.all_fields || [];
                            instance.displayFields = data.display_fields || [];
                            instance.filterFields = data.filter_fields || [];
                            
                            // 如果没有显示字段配置，使用默认配置
                            if (!instance.displayFields || instance.displayFields.length === 0) {
                                instance.displayFields = instance.allFields.slice(0, 8);
                                console.log('loadModelFields: 使用默认显示字段', instance.displayFields);
                            }
                            
                            // 调用rebuildTableFromConfig进行完整重新构建
                            this.rebuildTableFromConfig(tableId, instance.displayFields, instance.filterFields);
                        } else {
                            console.error('loadModelFields: 数据格式错误', data);
                        }
                    } else {
                        console.error('loadModelFields: API错误', response);
                    }
                }
            },
            error: (xhr, status, error) => {
                console.error('loadModelFields: 网络错误', {xhr, status, error});
                
                if (isInFieldConfig) {
                    const $loading = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId).find('.fa-spinner').parent();
                    const $error = $('#w-available-fields-' + tableId + ',#w-display-fields-' + tableId + ',#w-filter-fields-' + tableId).find('.w-error');
                    
                $loading.hide();
                this.showError(tableId, '网络错误: ' + error);
                }
            }
        });
    },

    /**
     * 将字段配置应用到表格
     */
    applyFieldsToTable: function(tableId, data) {
        const instance = this.instances[tableId];
        if (!instance) return;
        
        console.log('applyFieldsToTable: 开始应用字段配置', {
            allFields: data.all_fields?.length || 0,
            displayFields: data.display_fields?.length || 0,
            filterFields: data.filter_fields?.length || 0
        });
        
        // 保存字段数据到实例
        instance.allFields = data.all_fields || [];
        instance.displayFields = data.display_fields || [];
        instance.filterFields = data.filter_fields || [];
        
        // 如果没有显示字段配置，使用默认配置
        if (!instance.displayFields || instance.displayFields.length === 0) {
            instance.displayFields = instance.allFields.slice(0, 8);
            console.log('applyFieldsToTable: 使用默认显示字段', instance.displayFields);
        }
        
        // 清空旧数据和状态
        instance.data = [];
        instance.currentPage = 1;
        instance.filters = {};
        instance.search = '';
        instance.sorts = {};
        
        // 更新表格配置
        instance.config.fields = instance.displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text',
            placeholder: field.placeholder || '',
            options: field.options || ''
        }));
        
        // 更新筛选器配置
        instance.filterConfig = instance.filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `请输入${field.label || field.name}`,
            options: field.options || ''
        }));
        
        console.log('applyFieldsToTable: 字段配置已更新', {
            configFields: instance.config.fields.length,
            filterConfig: instance.filterConfig.length,
            displayFields: instance.displayFields.map(f => ({name: f.name, label: f.label})),
            configFields: instance.config.fields.map(f => ({name: f.name, label: f.label, visible: f.visible}))
        });
        
        // 重新构建表头
        console.log('applyFieldsToTable: 开始重新构建表头');
        const $thead = instance.container.find('thead');
        if ($thead.length) {
            this.renderHeader(instance, $thead);
            console.log('applyFieldsToTable: 表头重新构建完成');
            
            // 验证表头构建结果
            const headerCells = $thead.find('th');
            console.log('applyFieldsToTable: 表头验证', {
                expectedFields: instance.config.fields.length,
                actualCells: headerCells.length,
                headerTexts: headerCells.map(function() { return $(this).text().trim(); }).get()
            });
        } else {
            console.error('applyFieldsToTable: 未找到表头容器');
        }
        
        // 重新构建筛选器
        console.log('applyFieldsToTable: 开始重新构建筛选器');
        this.renderFilter(instance);
        console.log('applyFieldsToTable: 筛选器重新构建完成');
        
        // 验证筛选器构建结果
        const $filter = instance.container.find('.datatable-filter');
        if ($filter.length) {
            const filterInputs = $filter.find('[data-field]');
            console.log('applyFieldsToTable: 筛选器验证', {
                expectedFilters: instance.filterConfig.length,
                actualInputs: filterInputs.length,
                filterFields: filterInputs.map(function() { return $(this).data('field'); }).get()
            });
        }
        
        // 重新绑定事件
        console.log('applyFieldsToTable: 开始重新绑定事件');
        this.bindEvents(instance);
        console.log('applyFieldsToTable: 事件绑定完成');
        
        // 重新构建表格主体
        console.log('applyFieldsToTable: 开始重新构建表格主体');
        this.renderTable(instance);
        console.log('applyFieldsToTable: 表格主体重新构建完成');
        
        // 验证表格构建结果
        const $tbody = instance.container.find('tbody');
        if ($tbody.length) {
            const tbodyRows = $tbody.find('tr');
            console.log('applyFieldsToTable: 表格主体验证', {
                rows: tbodyRows.length,
                hasData: tbodyRows.length > 0 && !tbodyRows.first().find('td').text().includes('暂无数据')
            });
        }
        
        // 重新加载数据
        console.log('applyFieldsToTable: 开始重新加载数据');
        this.loadData(instance);
        console.log('applyFieldsToTable: 数据加载完成');
        
        // 最终验证
        setTimeout(() => {
            console.log('applyFieldsToTable: 最终验证', {
                tableId: tableId,
                allFields: instance.allFields?.length || 0,
                displayFields: instance.displayFields?.length || 0,
                filterFields: instance.filterFields?.length || 0,
                configFields: instance.config.fields?.length || 0,
                filterConfig: instance.filterConfig?.length || 0,
                data: instance.data?.length || 0,
                headerCells: instance.container.find('thead th').length,
                filterInputs: instance.container.find('.datatable-filter [data-field]').length
            });
        }, 100);
        
        console.log('applyFieldsToTable: 字段配置应用完成');
    },

    /**
     * 渲染字段类型下拉
     */
    renderFieldTypeSelect: function(tableId, field, type) {
        const options = DataTableManager.fieldTypeOptions;
        const selectId = `w-field-type-select-${type}-${tableId}-${field.name}`;
        let html = `<select class="w-field-type-select w-btn-sm" id="${selectId}" data-table="${tableId}" data-field="${field.name}" data-type="${type}">`;
        options.forEach(opt => {
            html += `<option value="${opt.value}"${field.type===opt.value?' selected':''}>${opt.label}</option>`;
        });
        html += '</select>';
        return html;
    },

    /**
     * 字段类型下拉变更事件
     */
    bindFieldTypeChange: function(tableId) {
        // 解绑再绑定，防止重复
        $(document).off('change', '.w-field-type-select');
        $(document).on('change', '.w-field-type-select', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.type = value;
                // 重新渲染字段列表
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
    },

    /**
     * 字段label/placeholder输入变更事件
     */
    bindFieldLabelInput: function(tableId) {
        // 解绑再绑定，防止重复
        $(document).off('input', '.w-field-label-input');
        $(document).on('input', '.w-field-label-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.label = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
        // placeholder
        $(document).off('input', '.w-field-placeholder-input');
        $(document).on('input', '.w-field-placeholder-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.placeholder = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
    },

    /**
     * 字段校验输入变更事件
     */
    bindFieldValidationInput: function(tableId) {
        // 解绑再绑定，防止重复
        $(document).off('input', '.w-field-minlength-input');
        $(document).on('input', '.w-field-minlength-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.minlength = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
        $(document).off('input', '.w-field-maxlength-input');
        $(document).on('input', '.w-field-maxlength-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.maxlength = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
        $(document).off('input', '.w-field-minval-input');
        $(document).on('input', '.w-field-minval-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.min = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
        $(document).off('input', '.w-field-maxval-input');
        $(document).on('input', '.w-field-maxval-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.max = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
        $(document).off('input', '.w-field-regex-input');
        $(document).on('input', '.w-field-regex-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.regex = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
    },

    /**
     * 从数据渲染模型字段（适配w-前缀class/id）
     */
    renderModelFieldsFromData: function(tableId, data) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const $availableFields = $('#w-available-fields-' + tableId);
        const $availableFieldsFilter = $('#w-available-fields-filter-' + tableId);
        const $displayFields = $('#w-display-fields-' + tableId);
        const $filterFields = $('#w-filter-fields-' + tableId);

        // 处理数据结构
        let allFields = [];
        let displayFields;
        let filterFields;
        if (data && typeof data === 'object') {
            allFields = data.all_fields || data.fields || [];
            // 优先用接口返回的display_fields/filter_fields（即使为空也用）
            if ('display_fields' in data) {
            displayFields = data.display_fields || [];
                console.log('renderModelFieldsFromData: 接口返回display_fields', displayFields);
            }
            if ('filter_fields' in data) {
            filterFields = data.filter_fields || [];
                console.log('renderModelFieldsFromData: 接口返回filter_fields', filterFields);
            }
        }
        
        // 如果没有返回，使用默认逻辑
        if (typeof displayFields === 'undefined') {
            displayFields = allFields.slice(0, 8);
            console.log('renderModelFieldsFromData: 使用默认displayFields', displayFields);
        }
        if (typeof filterFields === 'undefined') {
            filterFields = [];
            console.log('renderModelFieldsFromData: 使用默认filterFields', filterFields);
        }

        // 保存到实例中，供后续使用
        instance.allFields = allFields;
        instance.displayFields = displayFields;
        instance.filterFields = filterFields;

        console.log('renderModelFieldsFromData: 最终数据', {
            allFields: allFields.length,
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        // 计算可用字段（分别计算两个tab的可用字段）
        const displayFieldNames = new Set();
        displayFields.forEach(field => displayFieldNames.add(field.name));
        
        const filterFieldNames = new Set();
        filterFields.forEach(field => filterFieldNames.add(field.name));
        
        // 列设置tab的可用字段：未被选为显示字段的字段
        const availableFieldsForDisplay = allFields.filter(field => !displayFieldNames.has(field.name));
        
        // 筛选设置tab的可用字段：未被选为筛选字段的字段
        const availableFieldsForFilter = allFields.filter(field => !filterFieldNames.has(field.name));

        console.log('renderModelFieldsFromData: 可用字段计算', {
            availableFieldsForDisplay: availableFieldsForDisplay.length,
            availableFieldsForFilter: availableFieldsForFilter.length
        });

        // 渲染列设置tab的可用字段
        let availableHtmlForDisplay = '';
        if (availableFieldsForDisplay.length > 0) {
            availableFieldsForDisplay.forEach(field => {
                const isTemplateDefined = field.template_defined === true;
                const disabledAttr = isTemplateDefined ? 'disabled' : '';
                const disabledClass = isTemplateDefined ? 'disabled' : '';
                const templateBadge = isTemplateDefined ? '<span class="w-badge">' + __("固定字段") + '</span>' : '';
                
                availableHtmlForDisplay += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}">
        <div class="w-field-info">
            <span class="w-field-name">${field.label || field.name}</span>
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${field.type || 'text'}</span>
                            ${templateBadge}
                        </div>
        <div class="w-field-actions">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-primary" 
                                    onclick="DataTableManager.addField('${tableId}', '${field.name}', 'display')"
                                    ${disabledAttr}>
                <i class="fas fa-table"></i> ${__("显示")}
                            </button>
                        </div>
    </div>`;
            });
        } else {
            availableHtmlForDisplay = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("所有字段都已配置")}
    </div>`;
        }

        // 渲染筛选设置tab的可用字段
        let availableHtmlForFilter = '';
        if (availableFieldsForFilter.length > 0) {
            availableFieldsForFilter.forEach(field => {
                const isTemplateDefined = field.template_defined === true;
                const disabledAttr = isTemplateDefined ? 'disabled' : '';
                const disabledClass = isTemplateDefined ? 'disabled' : '';
                const templateBadge = isTemplateDefined ? '<span class="w-badge">' + __("固定字段") + '</span>' : '';
                
                availableHtmlForFilter += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}">
        <div class="w-field-info">
            <span class="w-field-name">${field.label || field.name}</span>
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${field.type || 'text'}</span>
            ${templateBadge}
        </div>
        <div class="w-field-actions">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-success" 
                    onclick="DataTableManager.addField('${tableId}', '${field.name}', 'filter')"
                    ${disabledAttr}>
                <i class="fas fa-filter"></i> ${__("筛选")}
                    </button>
                </div>
    </div>`;
            });
        } else {
            availableHtmlForFilter = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("所有字段都已配置")}
    </div>`;
        }

        // 分别更新两个可用字段区域
        $availableFields.html(availableHtmlForDisplay);
        $availableFieldsFilter.html(availableHtmlForFilter);

        // 渲染显示字段
        let displayHtml = '';
        if (displayFields.length > 0) {
            console.log('renderModelFieldsFromData: 开始渲染显示字段', displayFields);
            displayFields.forEach((field, index) => {
                console.log('renderModelFieldsFromData: 渲染显示字段', index, field);
                const isTemplateDefined = field.template_defined === true;
                const isFromScope = field.from_scope === true;
                const disabledAttr = isTemplateDefined ? 'disabled' : '';
                const disabledClass = isTemplateDefined ? 'disabled' : '';
                const templateBadge = isTemplateDefined ? '<span class="w-badge">' + __("固定字段") + '</span>' : '';
                const scopeBadge = isFromScope ? '<span class="w-badge" style="background:#bbf7d0;color:#166534;">' + __("已保存") + '</span>' : '';
                let validationHtml = '';
                
                if (field.validation) {
                    const validation = field.validation;
                    validationHtml = `
                    <div class="w-validation-settings">
                        <input class="w-validation-min w-btn-sm" type="number" value="${validation.min || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("最小长度")}" style="width:80px;" />
                        <input class="w-validation-max w-btn-sm" type="number" value="${validation.max || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("最大长度")}" style="width:80px;" />
                        <input class="w-validation-pattern w-btn-sm" type="text" value="${validation.pattern || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("正则表达式(可选)")}" style="width:120px;" />
                    </div>`;
                }
                
                displayHtml += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}">
        <div class="w-field-info" style="flex:1;min-width:0;">
            <input class="w-field-label-input w-btn-sm" type="text" value="${field.label || field.name}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("字段标题")}" style="margin-bottom:2px;max-width:120px;" />
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${DataTableManager.renderFieldTypeSelect(tableId, field, 'display')}</span>
            <input class="w-field-placeholder-input w-btn-sm" type="text" value="${field.placeholder || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("占位符（可选）")}" style="margin-top:2px;max-width:120px;" />
            ${field.type === 'select' ? `<input class="w-field-options-input w-btn-sm" type="text" value="${field.options || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("选项(如1:启用,0:禁用)")}" style="margin-top:2px;max-width:120px;" />` : ''}
            ${validationHtml}
            <div class="w-field-badges">
                            ${templateBadge}
                            ${scopeBadge}
                        </div>
        </div>
        <div class="w-field-actions" style="flex-direction:column;gap:6px;align-items:flex-end;min-width:70px;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" 
                    onclick="DataTableManager.removeField('${tableId}', '${field.name}', 'display')"
                    ${disabledAttr}>
                <i class="fas fa-eye-slash"></i> ${__("隐藏")}
                            </button>
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'display', 'up')"
                    ${index === 0 ? 'disabled' : ''}>
                <i class="fas fa-arrow-up"></i> ${__("上移")}
                            </button>
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'display', 'down')"
                    ${index === displayFields.length - 1 ? 'disabled' : ''}>
                <i class="fas fa-arrow-down"></i> ${__("下移")}
                            </button>
                        </div>
    </div>`;
            });
        } else {
            displayHtml = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("暂无显示字段")}
        <br><small>${__("您可以在右侧调整字段配置")}</small>
    </div>`;
        }
        $displayFields.html(displayHtml);

        // 渲染筛选字段
        let filterHtml = '';
        if (filterFields.length > 0) {
            console.log('renderModelFieldsFromData: 开始渲染筛选字段', filterFields);
            filterFields.forEach((field, index) => {
                console.log('renderModelFieldsFromData: 渲染筛选字段', index, field);
                const isTemplateDefined = field.template_defined === true;
                const isFromScope = field.from_scope === true;
                const disabledAttr = isTemplateDefined ? 'disabled' : '';
                const disabledClass = isTemplateDefined ? 'disabled' : '';
                const templateBadge = isTemplateDefined ? '<span class="w-badge">' + __("固定字段") + '</span>' : '';
                const scopeBadge = isFromScope ? '<span class="w-badge" style="background:#bbf7d0;color:#166534;">' + __("已保存") + '</span>' : '';
                let validationHtml = '';
                
                if (field.validation) {
                    const validation = field.validation;
                    validationHtml = `
                    <div class="w-validation-settings">
                        <input class="w-validation-min w-btn-sm" type="number" value="${validation.min || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("最小长度")}" style="width:80px;" />
                        <input class="w-validation-max w-btn-sm" type="number" value="${validation.max || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("最大长度")}" style="width:80px;" />
                        <input class="w-validation-pattern w-btn-sm" type="text" value="${validation.pattern || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("正则表达式(可选)")}" style="width:120px;" />
                    </div>`;
                }
                
                filterHtml += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}">
        <div class="w-field-info" style="flex:1;min-width:0;">
            <input class="w-field-label-input w-btn-sm" type="text" value="${field.label || field.name}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("字段标题")}" style="margin-bottom:2px;max-width:120px;" />
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${DataTableManager.renderFieldTypeSelect(tableId, field, 'filter')}</span>
            <input class="w-field-placeholder-input w-btn-sm" type="text" value="${field.placeholder || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("占位符（可选）")}" style="margin-top:2px;max-width:120px;" />
            ${field.type === 'select' ? `<input class="w-field-options-input w-btn-sm" type="text" value="${field.options || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("选项(如1:启用,0:禁用)")}" style="margin-top:2px;max-width:120px;" />` : ''}
            ${validationHtml}
            <div class="w-field-badges">
                            ${templateBadge}
                ${scopeBadge}
                        </div>
        </div>
        <div class="w-field-actions" style="flex-direction:column;gap:6px;align-items:flex-end;min-width:70px;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" 
                    onclick="DataTableManager.removeField('${tableId}', '${field.name}', 'filter')"
                    ${disabledAttr}>
                <i class="fas fa-eye-slash"></i> ${__("隐藏")}
                            </button>
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'filter', 'up')"
                    ${index === 0 ? 'disabled' : ''}>
                <i class="fas fa-arrow-up"></i> ${__("上移")}
                            </button>
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'filter', 'down')"
                    ${index === filterFields.length - 1 ? 'disabled' : ''}>
                <i class="fas fa-arrow-down"></i> ${__("下移")}
                            </button>
                        </div>
    </div>`;
            });
        } else {
            filterHtml = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("暂无筛选字段")}
        <br><small>${__("您可以在左侧选择字段添加到筛选")}</small>
    </div>`;
        }
        $filterFields.html(filterHtml);

        // 绑定事件
        this.bindFieldEvents(tableId);
        
        // 初始化拖拽排序
        this.initDragSort(tableId);
    },

    /**
     * 重置为默认字段配置
     */
    resetToDefault: function(tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        if (confirm('确定要重置为默认字段配置吗？这将显示所有可用字段。')) {
            // 清除缓存
            const cacheKey = `datatable_fields_${tableId}_${instance.options.model}_${instance.options.scope}`;
            localStorage.removeItem(cacheKey);
            
            // 重新加载字段数据
            this.loadModelFields(tableId);
        }
    },

    /**
     * 添加字段到显示列表或筛选列表
     */
    addField: function(tableId, fieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 从allFields中找到要添加的字段
        const fieldToAdd = instance.allFields.find(field => field.name === fieldName);
        if (!fieldToAdd) {
            console.warn('addField: 未找到字段', fieldName, 'in allFields:', instance.allFields);
            return;
        }

        // 检查是否已存在于目标列表，防止重复
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        if (fieldList.find(f => f.name === fieldName)) {
            console.log('addField: 字段已存在于目标列表:', fieldName, type);
            return;
        }

        // 创建字段副本并添加from_scope标识
        const fieldToAddWithScope = { ...fieldToAdd, from_scope: true };
        console.log('addField: 添加字段', fieldToAddWithScope, '到', type);

        if (type === 'display') {
            instance.displayFields.push(fieldToAddWithScope);
        } else if (type === 'filter') {
            instance.filterFields.push(fieldToAddWithScope);
        }
        
        // 保存配置到缓存
        this.saveFieldConfigToCache(tableId);
        
        // 重新渲染字段列表
        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
    },

    /**
     * 从显示列表或筛选列表移除字段
     */
    removeField: function(tableId, fieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const idx = fieldList.findIndex(f => f.name === fieldName);
        if (idx !== -1 && !(fieldList[idx].template_defined)) {
            fieldList.splice(idx, 1);
        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
        }
    },

    /**
     * 移动字段位置
     */
    moveField: function(tableId, fieldName, direction, type) {
        const instance = this.instances[tableId];
        if (!instance) return;
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const idx = fieldList.findIndex(f => f.name === fieldName);
        if (idx === -1) return;
        let newIdx = direction === 'up' ? idx - 1 : idx + 1;
        if (newIdx < 0 || newIdx >= fieldList.length) return;
        const temp = fieldList[idx];
        fieldList[idx] = fieldList[newIdx];
        fieldList[newIdx] = temp;
        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
    },

    /**
     * 保存字段配置到缓存
     */
    saveFieldConfigToCache: function(tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const cacheKey = `datatable_fields_${tableId}_${instance.options.model}_${instance.options.scope}`;
        const configData = {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        };
        localStorage.setItem(cacheKey, JSON.stringify(configData));
        
        console.log('字段配置已自动保存到缓存:', configData);
    },

    /**
     * 刷新数据
     */
    refreshData: function(tableId) {
        const instance = this.instances[tableId];
        if (instance) {
            this.loadData(instance);
        }
    },

    /**
     * 切换高级过滤器
     */
    toggleAdvancedFilter: function(scope) {
        const instance = this.getInstanceByScope(scope);
        if (instance) {
            const $filter = instance.container.find('.datatable-filter');
            $filter.find('.advanced-filters').toggle();
        }
    },

    /**
     * 跳转到指定页面
     */
    goToPage: function(scope, page) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            if (page === 'prev') {
                page = Math.max(1, instance.currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(instance.pagination.lastPage, instance.currentPage + 1);
            } else if (page === 'last') {
                page = instance.pagination.lastPage;
            }
            
            if (page !== instance.currentPage) {
                instance.currentPage = page;
                this.loadData(instance);
            }
        }
    },

    /**
     * 改变每页显示数量
     */
    changePageSize: function(scope, pageSize) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            instance.pageSize = parseInt(pageSize);
            instance.currentPage = 1;
            this.loadData(instance);
        }
    },

    /**
     * 导出数据
     */
    exportData: function(scope, format) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            const params = {
                model: instance.options.model,
                scope: instance.options.scope,
                format: format,
                filters: instance.filters,
                search: instance.search,
                sorts: instance.sorts
            };
            
            // 创建下载链接
            const queryString = Object.keys(params)
                .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(JSON.stringify(params[key])))
                .join('&');
            
            const downloadUrl = '/datatable/api/export?' + queryString;
            window.open(downloadUrl, '_blank');
        }
    },

    /**
     * 保存字段配置
     */
    saveFieldConfig: function(tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const displayFields = instance.displayFields || [];
        const filterFields = instance.filterFields || [];

        console.log('saveFieldConfig: 保存配置', {
            tableId,
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });
        
        const configData = {
            table_id: tableId,
            display_fields: displayFields,
            filter_fields: filterFields,
            page_size: 20,
            sort_field: '',
            sort_direction: 'asc'
        };

        var $saveBtn = document.querySelector(`#w-field-config-modal-${tableId} .w-btn-primary`);
        if($saveBtn) {
            var originalText = $saveBtn.innerHTML;
            $saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
            $saveBtn.disabled = true;
        }
        
        fetch(instance.apiUrl + '/save-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: instance.options.scope, config: configData })
        }).then(r=>r.json()).then(response=>{
            if(response.code===200){
                console.log('saveFieldConfig: 保存成功，开始重新渲染表格');
                
                // 关闭配置弹窗
                DataTableManager.closeFieldConfig(tableId);
                
                // 根据新的字段配置重新渲染表格
                DataTableManager.rebuildTableFromConfig(tableId, displayFields, filterFields);
                
            }else{
                alert(response.message||'保存失败');
            }
        }).catch(error => {
            console.error('saveFieldConfig: 保存失败', error);
            alert('保存失败: ' + error.message);
        }).finally(()=>{
            if($saveBtn){ 
                $saveBtn.innerHTML = originalText; 
                $saveBtn.disabled = false; 
            }
        });
    },

    /**
     * 根据配置重新构建表格
     */
    rebuildTableFromConfig: function(tableId, displayFields, filterFields) {
        const instance = this.instances[tableId];
        if (!instance) return;
        
        console.log('rebuildTableFromConfig: 开始重新构建表格', {
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });
        
        // 第一步：清空旧数据和状态
        instance.data = [];
        instance.currentPage = 1;
        instance.filters = {};
        instance.search = '';
        instance.sorts = {};
        
        // 第二步：更新实例中的字段配置
        instance.config.fields = displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text',
            placeholder: field.placeholder || '',
            options: field.options || ''
        }));
        
        // 第三步：更新筛选器配置
        instance.filterConfig = filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `请输入${field.label || field.name}`,
            options: field.options || ''
        }));
        
        console.log('rebuildTableFromConfig: 字段配置已更新', {
            configFields: instance.config.fields.length,
            filterConfig: instance.filterConfig.length
        });
        
        // 第四步：重新构建表头
        console.log('rebuildTableFromConfig: 开始重新构建表头');
        const $thead = instance.container.find('thead');
        if ($thead.length) {
            this.renderHeader(instance, $thead);
            console.log('rebuildTableFromConfig: 表头重新构建完成');
            
            // 验证表头构建结果
            const headerCells = $thead.find('th');
            console.log('rebuildTableFromConfig: 表头验证', {
                expectedFields: instance.config.fields.length,
                actualCells: headerCells.length,
                headerTexts: headerCells.map(function() { return $(this).text().trim(); }).get()
            });
        } else {
            console.error('rebuildTableFromConfig: 未找到表头容器');
        }
        
        // 第五步：重新构建筛选器
        console.log('rebuildTableFromConfig: 开始重新构建筛选器');
        this.renderFilter(instance);
        console.log('rebuildTableFromConfig: 筛选器重新构建完成');
        
        // 验证筛选器构建结果
        const $filter = instance.container.find('.datatable-filter');
        if ($filter.length) {
            const filterInputs = $filter.find('[data-field]');
            console.log('rebuildTableFromConfig: 筛选器验证', {
                expectedFilters: instance.filterConfig.length,
                actualInputs: filterInputs.length,
                filterFields: filterInputs.map(function() { return $(this).data('field'); }).get()
            });
        }
        
        // 第六步：重新绑定事件
        console.log('rebuildTableFromConfig: 开始重新绑定事件');
        this.bindEvents(instance);
        console.log('rebuildTableFromConfig: 事件绑定完成');
        
        // 第七步：重新构建表格主体
        console.log('rebuildTableFromConfig: 开始重新构建表格主体');
        this.renderTable(instance);
        console.log('rebuildTableFromConfig: 表格主体重新构建完成');
        
        // 验证表格构建结果
        const $tbody = instance.container.find('tbody');
        if ($tbody.length) {
            const tbodyRows = $tbody.find('tr');
            console.log('rebuildTableFromConfig: 表格主体验证', {
                rows: tbodyRows.length,
                hasData: tbodyRows.length > 0 && !tbodyRows.first().find('td').text().includes('暂无数据')
            });
        }
        
        // 第八步：重新加载数据
        console.log('rebuildTableFromConfig: 开始重新加载数据');
        this.loadData(instance);
        console.log('rebuildTableFromConfig: 数据加载完成');
        
        // 最终验证
        setTimeout(() => {
            console.log('rebuildTableFromConfig: 最终验证', {
                tableId: tableId,
                configFields: instance.config.fields?.length || 0,
                filterConfig: instance.filterConfig?.length || 0,
                data: instance.data?.length || 0,
                headerCells: instance.container.find('thead th').length,
                filterInputs: instance.container.find('.datatable-filter [data-field]').length
            });
        }, 100);
        
        console.log('rebuildTableFromConfig: 表格重新构建完成');
    },

    /**
     * 更新表格字段配置
     */
    updateTableFields: function(tableId, displayFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 更新实例中的表格字段配置
        instance.config.fields = displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text'
        }));

        // 重新渲染表头
        const $thead = instance.container.find('thead');
        this.renderHeader(instance, $thead);

        // 重新渲染表格数据
        this.renderTable(instance);

        // 重新加载数据
        this.loadData(instance);
    },

    /**
     * 更新筛选器字段配置
     */
    updateFilterFields: function(tableId, filterFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 更新筛选器字段配置
        instance.filterConfig = filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `请输入${field.label || field.name}`,
            options: field.options || ''
        }));

        // 重新渲染筛选器
        this.renderFilter(instance);
    },

    /**
     * 渲染筛选器
     */
    renderFilter: function(instance) {
        const $filter = instance.container.find('.datatable-filter');
        if (!$filter.length) {
            console.log('renderFilter: 未找到筛选器容器');
            return;
        }

        let filterHtml = '';
        
        if (instance.filterConfig && instance.filterConfig.length > 0) {
            console.log('renderFilter: 开始渲染筛选器', instance.filterConfig);
            
            instance.filterConfig.forEach(field => {
                const fieldId = `filter-${field.name}`;
                const fieldType = field.type || 'text';
                const placeholder = field.placeholder || `请输入${field.label || field.name}`;
                
                switch (fieldType) {
                    case 'select':
                        const options = field.options || '';
                        let optionsHtml = '<option value="">请选择</option>';
                        if (options) {
                            options.split(',').forEach(option => {
                                const [value, label] = option.split(':');
                                optionsHtml += `<option value="${value}">${label || value}</option>`;
                            });
                        }
                        filterHtml += `
                            <div class="filter-field">
                                <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                                <select class="form-control form-control-sm" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}">
                                    ${optionsHtml}
                                </select>
                            </div>
                        `;
                        break;
                    case 'date':
                        filterHtml += `
                            <div class="filter-field">
                                <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                                <input type="date" class="form-control form-control-sm" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                            </div>
                        `;
                        break;
                    default:
                        filterHtml += `
                            <div class="filter-field">
                                <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                                <input type="${fieldType}" class="form-control form-control-sm" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                            </div>
                        `;
                }
            });
        } else {
            console.log('renderFilter: 没有筛选字段配置');
            filterHtml = '<div class="text-muted">暂无筛选字段</div>';
        }

        $filter.find('.datatable-filter-form').html(filterHtml);
        console.log('renderFilter: 筛选器渲染完成');
    },

    /**
     * 字段拖拽排序（w-前缀）
     */
    bindFieldDragSort: function(tableId) {
        ['display','filter'].forEach(type => {
            const container = document.getElementById(type==='display' ? 'w-display-fields-'+tableId : 'w-filter-fields-'+tableId);
            if (!container) return;
            let dragSrc = null;
            container.querySelectorAll('.w-field-item').forEach(item => {
                item.draggable = true;
                item.ondragstart = function(e) {
                    dragSrc = this;
                    this.classList.add('w-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                };
                item.ondragover = function(e) {
                    e.preventDefault();
                    if(this!==dragSrc) this.classList.add('w-drag-over');
                };
                item.ondragleave = function() {
                    this.classList.remove('w-drag-over');
                };
                item.ondrop = function(e) {
                    e.preventDefault();
                    this.classList.remove('w-drag-over');
                    if(this!==dragSrc) {
                        const items = Array.from(container.querySelectorAll('.w-field-item'));
                        const from = items.indexOf(dragSrc);
                        const to = items.indexOf(this);
                        if(from!==-1 && to!==-1) {
                            let fieldList = type==='display' ? DataTableManager.instances[tableId].displayFields : DataTableManager.instances[tableId].filterFields;
                            const moved = fieldList.splice(from,1)[0];
                            fieldList.splice(to,0,moved);
                            DataTableManager.renderModelFieldsFromData(tableId, {
                                all_fields: DataTableManager.instances[tableId].allFields,
                                display_fields: DataTableManager.instances[tableId].displayFields,
                                filter_fields: DataTableManager.instances[tableId].filterFields
                            });
                        }
                    }
                };
                item.ondragend = function() {
                    this.classList.remove('w-dragging');
                    container.querySelectorAll('.w-field-item').forEach(i=>i.classList.remove('w-drag-over'));
                };
            });
        });
    },

    /**
     * 绑定options输入事件
     */
    bindFieldOptionsInput: function(tableId) {
        // 解绑再绑定，防止重复
        $(document).off('input', '.w-field-options-input');
        $(document).on('input', '.w-field-options-input', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.options = value;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
    },

    /**
     * 字段只读/必填checkbox变更事件
     */
    bindFieldCheckboxInput: function(tableId) {
        // 解绑再绑定，防止重复
        $(document).off('change', '.w-field-readonly-checkbox');
        $(document).on('change', '.w-field-readonly-checkbox', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const checked = $(this).is(':checked');
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.readonly = checked;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
        $(document).off('change', '.w-field-required-checkbox');
        $(document).on('change', '.w-field-required-checkbox', function() {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const checked = $(this).is(':checked');
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.required = checked;
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
        });
    },

    /**
     * 绑定字段配置相关事件
     */
    bindFieldEvents: function(tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 绑定字段标签输入事件
        $(`#w-field-config-modal-${tableId} .w-field-label-input`).off('input').on('input', function() {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            
            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.label = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.label = value;
            }
        });

        // 绑定字段占位符输入事件
        $(`#w-field-config-modal-${tableId} .w-field-placeholder-input`).off('input').on('input', function() {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            
            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.placeholder = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.placeholder = value;
            }
        });

        // 绑定字段选项输入事件
        $(`#w-field-config-modal-${tableId} .w-field-options-input`).off('input').on('input', function() {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            
            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.options = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.options = value;
            }
        });

        // 绑定字段类型选择事件
        $(`#w-field-config-modal-${tableId} .w-field-type-select`).off('change').on('change', function() {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            
            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.type = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.type = value;
            }
        });

        // 绑定校验规则输入事件
        $(`#w-field-config-modal-${tableId} .w-validation-min, #w-field-config-modal-${tableId} .w-validation-max, #w-field-config-modal-${tableId} .w-validation-pattern`).off('input').on('input', function() {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const validationType = $(this).hasClass('w-validation-min') ? 'min' : 
                                 $(this).hasClass('w-validation-max') ? 'max' : 'pattern';
            const value = $(this).val();
            
            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    field.validation[validationType] = value;
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    field.validation[validationType] = value;
                }
            }
        });
    },

    /**
     * 初始化拖拽排序
     */
    initDragSort: function(tableId) {
        // 这里可以添加拖拽排序功能，暂时留空
        // 如果需要拖拽排序，可以在这里实现
    },
}; 

// 全局事件委托，支持动态插入的字段设置按钮
$(document).on('click', '.w-btn[data-w-action="field-config"]', function(){
    var tableId = $(this).data('table');
    if(tableId) DataTableManager.openFieldConfig(tableId);
}); 

// 自动翻译所有带data-w-i18n的元素
function applyI18n() {
  document.querySelectorAll('[data-w-i18n]').forEach(function(el) {
    var key = el.getAttribute('data-w-i18n');
    if (key && typeof window.__ === 'function') {
      el.innerText = __(key);
    }
  });
}
// 页面加载和每次弹窗渲染后都调用
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', applyI18n);
} else {
  applyI18n();
}