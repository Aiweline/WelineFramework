/**
 * 主题编辑器交互脚本
 */
(function() {
    'use strict';

    // 编辑器配置 - 从 DOM 获取后台 URL
    const config = {
        apiBase: '',
        apiSaveWidget: '',
        apiUpdateConfig: '',
        apiDeleteWidget: '',
        apiWidgets: '',
        apiPublish: '',
        apiPreview: '',
        autoSaveDelay: 1000,
    };

    // 状态管理
    const state = {
        themeId: 0,
        pageType: 'default',
        selectedWidget: null,
        isDragging: false,
        hasChanges: false,
        draggingWidget: null, // 当前拖拽的部件数据
    };

    // DOM 元素
    let elements = {};

    /**
     * 初始化
     */
    function init() {
        const container = document.getElementById('themeEditor');
        if (!container) return;

        // 从 DOM data 属性获取后台 API URL
        config.apiBase = container.dataset.apiBase || '/backend/theme-editor';
        config.apiSaveWidget = container.dataset.apiSaveWidget || `${config.apiBase}/save-widget`;
        config.apiUpdateConfig = container.dataset.apiUpdateConfig || `${config.apiBase}/update-config`;
        config.apiDeleteWidget = container.dataset.apiDeleteWidget || `${config.apiBase}/delete-widget`;
        config.apiWidgets = container.dataset.apiWidgets || `${config.apiBase}/widgets`;
        config.apiPublish = container.dataset.apiPublish || `${config.apiBase}/publish`;
        config.apiPreview = container.dataset.apiPreview || `${config.apiBase}/preview`;
        config.apiRenderWidget = container.dataset.apiRenderWidget || `${config.apiBase}/render-widget`;
        config.apiWidgetPreview = container.dataset.apiWidgetPreview || `${config.apiBase}/widget-preview`;

        state.themeId = parseInt(container.dataset.themeId) || 0;
        state.pageType = container.dataset.pageType || 'default';

        // 缓存 DOM 元素
        elements = {
            container: container,
            themeSelect: document.getElementById('themeSelect'),
            pageTypeSelect: document.getElementById('pageTypeSelect'),
            configPanel: document.getElementById('configPanel'),
            configContent: document.getElementById('configContent'),
            widgetPanel: document.getElementById('widgetPanel'),
            widgetList: document.getElementById('widgetList'),
            widgetSearch: document.getElementById('widgetSearch'),
            previewFrame: document.getElementById('previewFrame'),
            previewLoading: document.getElementById('previewLoading'),
            btnPreview: document.getElementById('btnPreview'),
            btnSave: document.getElementById('btnSave'),
            btnPublish: document.getElementById('btnPublish'),
            btnRefreshPreview: document.getElementById('btnRefreshPreview'),
            btnFullscreenPreview: document.getElementById('btnFullscreenPreview'),
        };

        // 绑定事件
        bindEvents();

        // 初始化拖拽
        initDragAndDrop();

        console.log('Theme Editor initialized', {
            apiBase: config.apiBase,
            apiSaveWidget: config.apiSaveWidget,
            apiUpdateConfig: config.apiUpdateConfig,
            apiDeleteWidget: config.apiDeleteWidget,
            apiWidgets: config.apiWidgets,
            apiPublish: config.apiPublish,
            apiPreview: config.apiPreview,
            themeId: state.themeId,
            pageType: state.pageType
        });
    }

    /**
     * 绑定事件
     */
    function bindEvents() {
        // 主题选择
        if (elements.themeSelect) {
            elements.themeSelect.addEventListener('change', function() {
                const themeId = this.value;
                if (themeId) {
                    window.location.href = `${config.apiBase}?theme_id=${themeId}&page_type=${state.pageType}`;
                }
            });
        }

        // 页面类型选择
        if (elements.pageTypeSelect) {
            elements.pageTypeSelect.addEventListener('change', function() {
                const pageType = this.value;
                if (state.themeId) {
                    window.location.href = `${config.apiBase}?theme_id=${state.themeId}&page_type=${pageType}`;
                }
            });
        }

        // 部件搜索
        if (elements.widgetSearch) {
            elements.widgetSearch.addEventListener('input', debounce(function() {
                filterWidgets(this.value);
            }, 300));
        }

        // 部件分组折叠/展开
        document.querySelectorAll('.widget-group-header').forEach(header => {
            header.addEventListener('click', function(e) {
                // 如果点击的是分组内的部件项，不触发折叠
                if (e.target.closest('.widget-item')) {
                    return;
                }
                const group = this.closest('.widget-group');
                if (group) {
                    group.classList.toggle('collapsed');
                }
            });
        });

        // 点击预览区的部件
        document.addEventListener('click', function(e) {
            const widgetItem = e.target.closest('.preview-widget-item');
            if (widgetItem) {
                selectWidget(widgetItem);
                return;
            }

            // 编辑按钮 - 打开模态框
            if (e.target.closest('.btn-edit-widget')) {
                e.stopPropagation();
                const widget = e.target.closest('.preview-widget-item');
                if (widget) {
                    openConfigModal(widget);
                }
                return;
            }

            // 删除按钮
            if (e.target.closest('.btn-delete-widget')) {
                const widget = e.target.closest('.preview-widget-item');
                if (widget) {
                    deleteWidget(widget);
                }
                return;
            }
        });

        // 关闭配置面板
        document.getElementById('closeConfigPanel')?.addEventListener('click', function() {
            deselectWidget();
        });

        // 保存按钮
        elements.btnSave?.addEventListener('click', saveLayout);

        // 发布按钮
        elements.btnPublish?.addEventListener('click', publishTheme);

        // 预览按钮
        elements.btnPreview?.addEventListener('click', openPreview);

        // 配置表单提交（左侧面板）
        document.addEventListener('submit', function(e) {
            if (e.target.id === 'widgetConfigForm') {
                e.preventDefault();
                saveWidgetConfig(e.target);
            }
        });

        // 颜色选择器同步
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('form-control-color')) {
                const textInput = e.target.parentElement.querySelector('input[type="text"]');
                if (textInput) {
                    textInput.value = e.target.value;
                }
            }
        });

        // 视图切换（实时预览 / 结构视图）
        document.querySelectorAll('.preview-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const viewType = this.dataset.view;
                switchPreviewView(viewType);
            });
        });

        // iframe 加载完成
        if (elements.previewFrame) {
            elements.previewFrame.addEventListener('load', function() {
                if (elements.previewLoading) {
                    elements.previewLoading.classList.add('hidden');
                }
            });
        }

        // 刷新预览按钮
        if (elements.btnRefreshPreview) {
            elements.btnRefreshPreview.addEventListener('click', refreshPreview);
        }

        // 全屏预览按钮
        if (elements.btnFullscreenPreview) {
            elements.btnFullscreenPreview.addEventListener('click', function() {
                if (elements.previewFrame && elements.previewFrame.requestFullscreen) {
                    elements.previewFrame.requestFullscreen();
                }
            });
        }

        // 监听 iframe 消息（预览页面与编辑器通信）
        window.addEventListener('message', handleIframeMessage);
    }

    /**
     * 处理来自 iframe 的消息
     */
    function handleIframeMessage(e) {
        const data = e.data;
        if (!data || !data.type) return;

        switch (data.type) {
            case 'widget-selected':
                // 预览页面中选中了部件
                handlePreviewWidgetSelected(data);
                break;
            case 'slot-clicked':
                // 预览页面中点击了插槽
                handlePreviewSlotClicked(data);
                break;
        }
    }

    /**
     * 处理预览页面中选中部件
     */
    function handlePreviewWidgetSelected(data) {
        const layoutId = data.layoutId;
        const widgetCode = data.widgetCode;
        let config = {};
        
        try {
            config = JSON.parse(data.config || '{}');
        } catch (e) {
            config = {};
        }

        // 打开配置模态框
        openConfigModalForLayout(layoutId, widgetCode, config);
    }

    /**
     * 处理预览页面中点击插槽
     */
    function handlePreviewSlotClicked(data) {
        const slot = data.slot;
        const accept = data.accept ? data.accept.split(',') : [];
        
        // 高亮右侧部件列表中可放入该插槽的部件
        highlightAcceptableWidgets(accept);
        
        showToast(`插槽 "${slot}" 可接受: ${accept.join(', ')}`, 'info');
    }

    /**
     * 高亮可接受的部件
     */
    function highlightAcceptableWidgets(acceptCodes) {
        // 移除之前的高亮
        document.querySelectorAll('.widget-item.highlighted').forEach(el => {
            el.classList.remove('highlighted');
        });

        // 高亮匹配的部件
        acceptCodes.forEach(code => {
            const widget = document.querySelector(`.widget-item[data-widget-code="${code}"]`);
            if (widget) {
                widget.classList.add('highlighted');
            }
        });

        // 3秒后移除高亮
        setTimeout(() => {
            document.querySelectorAll('.widget-item.highlighted').forEach(el => {
                el.classList.remove('highlighted');
            });
        }, 3000);
    }

    /**
     * 为已保存的布局打开配置模态框
     */
    async function openConfigModalForLayout(layoutId, widgetCode, currentConfig) {
        // 获取部件参数定义
        try {
            const response = await fetch(config.apiWidgets);
            const result = await response.json();

            if (result.success) {
                // 查找匹配的部件
                let widgetMeta = null;
                for (const type in result.data) {
                    const widgets = result.data[type].widgets || [];
                    for (const w of widgets) {
                        if (w.code === widgetCode) {
                            widgetMeta = w;
                            break;
                        }
                    }
                    if (widgetMeta) break;
                }

                if (widgetMeta) {
                    renderConfigModalForLayout(layoutId, widgetMeta, currentConfig);
                } else {
                    showToast('未找到部件定义', 'error');
                }
            }
        } catch (err) {
            console.error('获取部件定义失败:', err);
            showToast('获取部件定义失败', 'error');
        }
    }

    /**
     * 渲染单个表单字段
     */
    function renderFormField(key, param, value) {
        const type = param.type || 'string';
        const label = param.label || key;
        const required = param.required || false;
        const description = param.description || '';
        const placeholder = param.placeholder || '';
        const options = param.options || {};

        let html = `<div class="form-group mb-3">`;
        html += `<label for="config_${key}" class="form-label">${escapeHtml(label)}`;
        if (required) html += ' <span class="text-danger">*</span>';
        html += `</label>`;

        if (type === 'string') {
            html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
        } else if (type === 'number') {
            html += `<input type="number" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
        } else if (type === 'bool' || type === 'boolean') {
            html += `<div class="form-check">
                <input type="checkbox" class="form-check-input" id="config_${key}" name="${key}" value="1" ${value ? 'checked' : ''}>
                <label class="form-check-label" for="config_${key}">启用</label>
            </div>`;
        } else if (type === 'select') {
            html += `<select class="form-select" id="config_${key}" name="${key}" ${required ? 'required' : ''}>
                <option value="">-- 请选择 --</option>`;
            for (const optVal in options) {
                html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
            }
            html += `</select>`;
        } else if (type === 'url') {
            html += `<input type="url" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>`;
        } else if (type === 'image') {
            html += `<div class="input-group">
                <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="图片URL">
                <button type="button" class="btn btn-outline-secondary btn-select-image" data-target="config_${key}">
                    <i class="ri-image-line"></i>
                </button>
            </div>`;
            if (value) {
                html += `<div class="mt-2"><img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 80px;"></div>`;
            }
        } else if (type === 'color') {
            html += `<div class="input-group">
                <input type="color" class="form-control form-control-color" id="config_${key}_picker" value="${escapeHtml(value || '#000000')}" style="width: 50px;">
                <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="#000000">
            </div>`;
        } else if (type === 'textarea') {
            html += `<textarea class="form-control" id="config_${key}" name="${key}" rows="4" 
                     placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
        } else {
            html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
        }

        if (description) {
            html += `<small class="form-text text-muted">${escapeHtml(description)}</small>`;
        }

        html += `</div>`;
        return html;
    }

    /**
     * 渲染配置模态框（用于已保存的布局）
     */
    function renderConfigModalForLayout(layoutId, widgetMeta, currentConfig) {
        const modal = document.getElementById('widgetConfigModal');
        if (!modal) return;

        const modalTitle = modal.querySelector('.modal-title');
        const modalBody = modal.querySelector('.modal-body');

        if (modalTitle) {
            modalTitle.textContent = widgetMeta.name || widgetMeta.code;
        }

        if (modalBody) {
            const params = widgetMeta.params || {};
            let formHtml = `<form id="modalConfigForm" data-layout-id="${layoutId}" data-widget-code="${widgetMeta.code}" data-widget-module="${widgetMeta.module || ''}">`;
            
            // 添加预览区域
            formHtml += `
                <div class="config-preview-area mb-3">
                    <label class="form-label">实时预览</label>
                    <div class="widget-preview-box" id="modalWidgetPreview">
                        <div class="preview-loading"><i class="ri-loader-4-line spin"></i> 加载中...</div>
                    </div>
                </div>
                <hr>
            `;

            for (const [key, param] of Object.entries(params)) {
                const value = currentConfig[key] ?? param.default ?? '';
                formHtml += renderFormField(key, param, value);
            }

            formHtml += `
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="ri-save-line"></i> 保存配置
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        取消
                    </button>
                </div>
            </form>`;

            modalBody.innerHTML = formHtml;

            // 绑定表单提交
            const form = modalBody.querySelector('#modalConfigForm');
            if (form) {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    await saveConfigFromModal(form);
                });

                // 绑定字段变更实时预览
                form.querySelectorAll('input, select, textarea').forEach(field => {
                    field.addEventListener('change', () => updateModalPreview(form, widgetMeta));
                    field.addEventListener('input', debounce(() => updateModalPreview(form, widgetMeta), 300));
                });

                // 绑定颜色选择器同步
                form.querySelectorAll('.form-control-color').forEach(colorPicker => {
                    colorPicker.addEventListener('input', function() {
                        const textInput = this.parentElement.querySelector('input[type="text"]');
                        if (textInput) {
                            textInput.value = this.value;
                        }
                    });
                });
            }

            // 显示模态框
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            // 加载初始预览
            setTimeout(() => updateModalPreview(form, widgetMeta), 100);
        }
    }

    /**
     * 更新模态框中的实时预览
     */
    async function updateModalPreview(form, widgetMeta) {
        const previewBox = document.getElementById('modalWidgetPreview');
        if (!previewBox) return;

        // 收集当前配置
        const currentConfig = {};
        const formData = new FormData(form);
        for (const [key, value] of formData.entries()) {
            currentConfig[key] = value;
        }

        // 处理复选框
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            currentConfig[cb.name] = cb.checked;
        });

        previewBox.innerHTML = '<div class="preview-loading"><i class="ri-loader-4-line spin"></i> 渲染中...</div>';

        try {
            const response = await fetch(config.apiRenderWidget, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    widget_module: widgetMeta.module,
                    widget_code: widgetMeta.code,
                    config: currentConfig
                }),
            });

            const result = await response.json();

            if (result.success) {
                previewBox.innerHTML = result.html;
            } else {
                previewBox.innerHTML = `<div class="preview-error">${result.message || '渲染失败'}</div>`;
            }
        } catch (err) {
            previewBox.innerHTML = `<div class="preview-error">预览加载失败</div>`;
        }
    }

    /**
     * 保存模态框配置
     */
    async function saveConfigFromModal(form) {
        const layoutId = form.dataset.layoutId;
        if (!layoutId) {
            showToast('缺少布局ID', 'error');
            return;
        }

        const configData = {};
        const formData = new FormData(form);
        for (const [key, value] of formData.entries()) {
            configData[key] = value;
        }

        // 处理复选框
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            configData[cb.name] = cb.checked;
        });

        try {
            const response = await fetch(config.apiUpdateConfig, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: configData
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast('配置已保存', 'success');
                
                // 关闭模态框
                const modal = bootstrap.Modal.getInstance(document.getElementById('widgetConfigModal'));
                if (modal) modal.hide();
                
                // 刷新预览
                refreshPreview();
            } else {
                showToast(result.message || '保存失败', 'error');
            }
        } catch (err) {
            showToast('保存失败', 'error');
        }
    }

    /**
     * 防抖函数
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * 切换预览视图
     */
    function switchPreviewView(viewType) {
        // 更新标签状态
        document.querySelectorAll('.preview-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.view === viewType);
        });

        // 切换视图
        document.querySelectorAll('.preview-view').forEach(view => {
            view.classList.remove('active');
        });

        const targetView = document.getElementById(viewType === 'preview' ? 'previewViewPreview' : 'previewViewStructure');
        if (targetView) {
            targetView.classList.add('active');
        }

        // 切换到预览视图时刷新 iframe
        if (viewType === 'preview') {
            refreshPreview();
        }
    }

    /**
     * 初始化拖拽
     */
    function initDragAndDrop() {
        // 可拖拽部件
        document.querySelectorAll('.widget-item.draggable').forEach(item => {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragend', handleDragEnd);
        });

        // 放置区域 - 同时绑定到 preview-area 和 area-widgets
        document.querySelectorAll('.preview-area, .area-widgets').forEach(area => {
            area.addEventListener('dragover', handleDragOver);
            area.addEventListener('dragleave', handleDragLeave);
            area.addEventListener('drop', handleDrop);
        });
    }

    /**
     * 拖拽开始
     */
    function handleDragStart(e) {
        state.isDragging = true;
        this.classList.add('dragging');

        let position = [];
        try {
            position = JSON.parse(this.dataset.widgetPosition || '[]');
        } catch (err) {
            console.error('Invalid position data:', this.dataset.widgetPosition);
            position = [];
        }

        const widgetData = {
            code: this.dataset.widgetCode,
            module: this.dataset.widgetModule,
            type: this.dataset.widgetType,
            name: this.dataset.widgetName,
            position: position,
            compatible: this.dataset.widgetCompatible === '1',
        };

        // 存储到 state 中，以便在 dragover 和 drop 时使用
        state.draggingWidget = widgetData;

        console.log('Drag start - widget:', widgetData.name, 'position:', widgetData.position);

        e.dataTransfer.setData('application/json', JSON.stringify(widgetData));
        e.dataTransfer.effectAllowed = 'copy';

        // 高亮可放置区域
        highlightAllowedAreas(widgetData.position);
    }

    /**
     * 拖拽结束
     */
    function handleDragEnd(e) {
        state.isDragging = false;
        state.draggingWidget = null; // 清理拖拽数据
        this.classList.remove('dragging');

        // 移除高亮
        document.querySelectorAll('.preview-area').forEach(area => {
            area.classList.remove('drag-over', 'drag-invalid', 'drag-allowed');
        });
    }

    /**
     * 拖拽经过
     */
    function handleDragOver(e) {
        // 必须调用 preventDefault 才能允许 drop
        e.preventDefault();
        e.stopPropagation();
        
        // 如果 this 本身就是 preview-area，直接使用；否则查找父级
        const area = this.classList.contains('preview-area') ? this : this.closest('.preview-area');
        if (!area) return;
        
        const areaCode = area.dataset.area;
        if (!areaCode) return;
        
        // 使用 state 中存储的拖拽数据
        const widgetData = state.draggingWidget;
        
        if (widgetData && widgetData.position && widgetData.position.length > 0) {
            // 检查是否允许放置
            const allowed = isAllowedArea(widgetData.position, areaCode);
            if (allowed) {
                e.dataTransfer.dropEffect = 'copy';
                area.classList.add('drag-over');
                area.classList.remove('drag-invalid');
            } else {
                e.dataTransfer.dropEffect = 'none';
                area.classList.add('drag-invalid');
                area.classList.remove('drag-over');
            }
        } else {
            // 没有位置限制，允许放置
            e.dataTransfer.dropEffect = 'copy';
            area.classList.add('drag-over');
            area.classList.remove('drag-invalid');
        }
    }

    /**
     * 拖拽离开
     */
    function handleDragLeave(e) {
        // 如果 this 本身就是 preview-area，直接使用；否则查找父级
        const area = this.classList.contains('preview-area') ? this : this.closest('.preview-area');
        if (area) {
            // 只有当真正离开区域时才移除高亮（避免子元素触发）
            if (!area.contains(e.relatedTarget)) {
                area.classList.remove('drag-over');
            }
        }
    }

    /**
     * 放置
     */
    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // 如果 this 本身就是 preview-area，直接使用；否则查找父级
        const area = this.classList.contains('preview-area') ? this : this.closest('.preview-area');
        if (!area) {
            console.error('Drop: No area found, this:', this);
            return;
        }

        area.classList.remove('drag-over', 'drag-invalid');

        const areaCode = area.dataset.area;
        if (!areaCode) {
            console.error('Drop: No area code found in area:', area);
            return;
        }

        // 优先使用 state 中存储的数据
        let widgetData = state.draggingWidget;
        
        // 如果 state 中没有，尝试从 dataTransfer 获取
        if (!widgetData) {
            try {
                const jsonData = e.dataTransfer.getData('application/json');
                if (jsonData) {
                    widgetData = JSON.parse(jsonData);
                }
            } catch (err) {
                console.error('Invalid widget data:', err);
            }
        }

        if (!widgetData) {
            console.error('Drop: No widget data available');
            showToast('无法获取部件数据', 'error');
            return;
        }

        console.log('Drop - widget:', widgetData.name, 'position:', widgetData.position, 'target area:', areaCode);

        // 检查是否允许放置
        const allowed = isAllowedArea(widgetData.position, areaCode);
        console.log('Drop - allowed:', allowed);
        
        if (!allowed) {
            showToast('该部件不能放置在此区域', 'warning');
            return;
        }

        // 添加部件
        addWidget(areaCode, widgetData);
    }

    /**
     * 高亮允许的区域
     * @param {Array} positions 部件允许的位置数组，如 ['header'] 或 ['left_sidebar', 'right_sidebar']
     */
    function highlightAllowedAreas(positions) {
        console.log('highlightAllowedAreas - positions:', positions);
        
        // 位置到区域的映射：定义每个位置标识对应的区域代码
        const positionAreaMap = {
            'header': ['header'],
            'banner': ['banner'],
            'sidebar': ['left_sidebar', 'right_sidebar'],
            'left_sidebar': ['left_sidebar'],
            'right_sidebar': ['right_sidebar'],
            'content': ['content', 'banner'],
            'footer': ['footer'],
            'all': ['header', 'banner', 'left_sidebar', 'content', 'right_sidebar', 'footer'],
        };

        let allowedAreas = [];
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            // 无限制，允许所有
            allowedAreas = ['header', 'banner', 'left_sidebar', 'content', 'right_sidebar', 'footer'];
            console.log('highlightAllowedAreas - no position restriction, allowing all');
        } else {
            // 收集所有允许的区域
            positions.forEach(pos => {
                if (positionAreaMap[pos]) {
                    allowedAreas = allowedAreas.concat(positionAreaMap[pos]);
                } else {
                    console.warn('Unknown position:', pos);
                }
            });
            // 去重
            allowedAreas = [...new Set(allowedAreas)];
        }

        console.log('highlightAllowedAreas - allowedAreas:', allowedAreas);

        // 高亮允许的区域，标记不允许的区域
        document.querySelectorAll('.preview-area').forEach(area => {
            const areaCode = area.dataset.area;
            if (allowedAreas.includes(areaCode)) {
                area.classList.add('drag-allowed');
                area.classList.remove('drag-invalid');
            } else {
                area.classList.add('drag-invalid');
                area.classList.remove('drag-allowed');
            }
        });
    }

    /**
     * 检查是否允许放置
     * @param {Array} positions 部件允许的位置数组，如 ['header'] 或 ['left_sidebar', 'right_sidebar']
     * @param {string} areaCode 目标区域代码，如 'header', 'footer' 等
     */
    function isAllowedArea(positions, areaCode) {
        console.log('isAllowedArea - positions:', positions, 'areaCode:', areaCode);
        
        // 如果没有位置限制，允许所有区域
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            console.log('isAllowedArea - no restriction, returning true');
            return true;
        }

        // 区域映射：定义每个区域代码对应的允许位置标识
        const areaPositionMap = {
            'header': ['header', 'all'],
            'banner': ['banner', 'content', 'all'],
            'left_sidebar': ['sidebar', 'left_sidebar', 'all'],
            'right_sidebar': ['sidebar', 'right_sidebar', 'all'],
            'content': ['content', 'banner', 'all'],
            'footer': ['footer', 'all'],
        };

        // 获取目标区域允许的位置标识
        const allowedPositions = areaPositionMap[areaCode] || [];
        console.log('isAllowedArea - allowedPositions for', areaCode, ':', allowedPositions);
        
        // 检查部件的 position 数组中是否有任何一个匹配目标区域允许的位置
        const result = positions.some(pos => allowedPositions.includes(pos));
        console.log('isAllowedArea - result:', result);
        return result;
    }

    /**
     * 添加部件
     */
    async function addWidget(area, widgetData) {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        const data = {
            theme_id: state.themeId,
            page_type: state.pageType,
            area: area,
            widget_code: widgetData.code,
            widget_module: widgetData.module,
            widget_type: widgetData.type,
            config: {},
            sort_order: getNextSortOrder(area),
        };

        try {
            const response = await fetch(config.apiSaveWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
            });

            const result = await response.json();

            if (result.success) {
                showToast('部件添加成功', 'success');
                // 切换到预览视图并刷新
                switchPreviewView('preview');
                refreshPreview();
            } else {
                showToast(result.message || '添加失败', 'error');
            }
        } catch (err) {
            console.error('Add widget error:', err);
            showToast('添加部件失败', 'error');
        }
    }

    /**
     * 获取下一个排序值
     */
    function getNextSortOrder(area) {
        const areaWidgets = document.querySelector(`.area-widgets[data-area="${area}"]`);
        if (!areaWidgets) return 0;
        return areaWidgets.querySelectorAll('.preview-widget-item').length;
    }

    /**
     * 选中部件
     */
    function selectWidget(widgetElement) {
        // 移除其他选中状态
        document.querySelectorAll('.preview-widget-item.selected').forEach(el => {
            el.classList.remove('selected');
        });

        // 选中当前
        widgetElement.classList.add('selected');
        state.selectedWidget = widgetElement;

        // 加载配置面板
        loadWidgetConfig(widgetElement);
    }

    /**
     * 取消选中
     */
    function deselectWidget() {
        document.querySelectorAll('.preview-widget-item.selected').forEach(el => {
            el.classList.remove('selected');
        });
        state.selectedWidget = null;

        // 显示空状态
        if (elements.configContent) {
            elements.configContent.innerHTML = `
                <div class="no-widget-selected">
                    <i class="ri-cursor-line"></i>
                    <p>点击预览区域中的部件进行配置</p>
                </div>
            `;
        }
    }

    /**
     * 打开配置模态框
     */
    async function openConfigModal(widgetElement) {
        const modal = document.getElementById('widgetConfigModal');
        const modalBody = document.getElementById('widgetConfigModalBody');
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
        
        // 显示加载状态
        modalBody.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">加载中...</span>
                </div>
            </div>
        `;
        
        // 打开模态框
        modalInstance.show();
        
        // 加载配置
        await loadWidgetConfigForModal(widgetElement, modalBody);
    }

    /**
     * 加载部件配置（用于模态框）
     */
    async function loadWidgetConfigForModal(widgetElement, modalBody) {
        const layoutId = widgetElement.dataset.layoutId;
        const widgetModule = widgetElement.dataset.widgetModule;
        const widgetCode = widgetElement.dataset.widgetCode;
        const widgetType = widgetElement.dataset.widgetType;
        let config = {};

        try {
            config = JSON.parse(widgetElement.dataset.config || '{}');
        } catch (e) {
            config = {};
        }

        // 获取部件参数定义
        try {
            const response = await fetch(config.apiWidgets);
            const result = await response.json();

            if (result.success) {
                // 查找匹配的部件
                let widgetMeta = null;
                for (const type in result.data) {
                    const widgets = result.data[type].widgets || [];
                    for (const w of widgets) {
                        if (w.module === widgetModule && w.code === widgetCode) {
                            widgetMeta = w;
                            break;
                        }
                    }
                    if (widgetMeta) break;
                }

                if (widgetMeta) {
                    renderConfigFormToModal({
                        layout_id: layoutId,
                        widget_code: widgetCode,
                        widget_module: widgetModule,
                        widget_type: widgetType,
                        config: config,
                        meta: widgetMeta,
                    }, widgetMeta.params || {}, modalBody, widgetElement);
                } else {
                    modalBody.innerHTML = '<p class="text-muted">未找到部件配置信息</p>';
                }
            }
        } catch (err) {
            console.error('Load config error:', err);
            modalBody.innerHTML = '<p class="text-danger">加载配置失败</p>';
        }
    }

    /**
     * 加载部件配置（用于左侧面板，保留兼容性）
     */
    async function loadWidgetConfig(widgetElement) {
        const layoutId = widgetElement.dataset.layoutId;
        const widgetModule = widgetElement.dataset.widgetModule;
        const widgetCode = widgetElement.dataset.widgetCode;
        const widgetType = widgetElement.dataset.widgetType;
        let config = {};

        try {
            config = JSON.parse(widgetElement.dataset.config || '{}');
        } catch (e) {
            config = {};
        }

        // 获取部件参数定义
        try {
            const response = await fetch(config.apiWidgets);
            const result = await response.json();

            if (result.success) {
                // 查找匹配的部件
                let widgetMeta = null;
                for (const type in result.data) {
                    const widgets = result.data[type].widgets || [];
                    for (const w of widgets) {
                        if (w.module === widgetModule && w.code === widgetCode) {
                            widgetMeta = w;
                            break;
                        }
                    }
                    if (widgetMeta) break;
                }

                if (widgetMeta) {
                    renderConfigForm({
                        layout_id: layoutId,
                        widget_code: widgetCode,
                        widget_module: widgetModule,
                        widget_type: widgetType,
                        config: config,
                        meta: widgetMeta,
                    }, widgetMeta.params || {});
                } else {
                    elements.configContent.innerHTML = '<p class="text-muted">未找到部件配置信息</p>';
                }
            }
        } catch (err) {
            console.error('Load config error:', err);
            elements.configContent.innerHTML = '<p class="text-danger">加载配置失败</p>';
        }
    }

    /**
     * 渲染配置表单到模态框
     */
    function renderConfigFormToModal(widget, params, modalBody, widgetElement) {
        const typeIcons = {
            'header': 'ri-layout-top-line',
            'footer': 'ri-layout-bottom-line',
            'sidebar': 'ri-layout-left-line',
            'banner': 'ri-image-line',
            'carousel': 'ri-slideshow-line',
            'product': 'ri-shopping-bag-line',
            'category': 'ri-folder-line',
            'navigation': 'ri-menu-line',
            'search': 'ri-search-line',
            'social': 'ri-share-line',
            'newsletter': 'ri-mail-line',
            'content': 'ri-file-text-line',
        };

        const icon = typeIcons[widget.widget_type] || 'ri-widgets-line';
        const widgetName = widget.meta?.name || widget.widget_code;
        const widgetDesc = widget.meta?.description || '';
        const config = widget.config || {};

        let html = `
            <div class="config-widget-header mb-4">
                <div class="config-widget-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="config-widget-info">
                    <h4>${escapeHtml(widgetName)}</h4>
                    <p class="text-muted mb-0">${escapeHtml(widgetDesc)}</p>
                </div>
            </div>
            <form class="config-form" id="widgetConfigFormModal" data-layout-id="${widget.layout_id}" data-widget-element-id="${widgetElement ? 'widget_' + widget.layout_id : ''}">
        `;

        if (Object.keys(params).length === 0) {
            html += '<p class="text-muted">该部件暂无可配置参数</p>';
        } else {
            for (const key in params) {
                const param = params[key];
                const type = param.type || 'string';
                const label = param.label || key;
                const defaultVal = param.default || '';
                const value = config[key] !== undefined ? config[key] : defaultVal;
                const required = param.required || false;
                const description = param.description || '';
                const placeholder = param.placeholder || '';
                const options = param.options || {};

                html += `<div class="form-group mb-3">`;
                html += `<label for="config_${key}" class="form-label">${escapeHtml(label)}`;
                if (required) html += ' <span class="text-danger">*</span>';
                html += `</label>`;

                if (type === 'string') {
                    html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'number') {
                    html += `<input type="number" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'boolean') {
                    html += `<div class="form-check">
                        <input type="checkbox" class="form-check-input" id="config_${key}" name="${key}" value="1" ${value ? 'checked' : ''}>
                        <label class="form-check-label" for="config_${key}">启用</label>
                    </div>`;
                } else if (type === 'select') {
                    html += `<select class="form-select" id="config_${key}" name="${key}" ${required ? 'required' : ''}>
                        <option value="">-- 请选择 --</option>`;
                    for (const optVal in options) {
                        html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
                    }
                    html += `</select>`;
                } else if (type === 'url') {
                    html += `<input type="url" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>`;
                } else if (type === 'image') {
                    html += `<div class="input-group">
                        <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="图片URL">
                        <button type="button" class="btn btn-outline-secondary btn-select-image" data-target="config_${key}">
                            <i class="ri-image-line"></i>
                        </button>
                    </div>`;
                    if (value) {
                        html += `<div class="mt-2"><img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 80px;"></div>`;
                    }
                } else if (type === 'color') {
                    html += `<div class="input-group">
                        <input type="color" class="form-control form-control-color" id="config_${key}_picker" value="${escapeHtml(value || '#000000')}" style="width: 50px;">
                        <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="#000000">
                    </div>`;
                } else if (type === 'textarea') {
                    html += `<textarea class="form-control" id="config_${key}" name="${key}" rows="4" 
                             placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
                } else {
                    html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
                }

                if (description) {
                    html += `<small class="form-text text-muted">${escapeHtml(description)}</small>`;
                }

                html += `</div>`;
            }
        }

        html += `
            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" class="btn btn-primary">
                    <i class="ri-save-line"></i> 保存配置
                </button>
            </div>
            </form>
        `;

        modalBody.innerHTML = html;
        
        // 绑定表单提交事件
        const form = document.getElementById('widgetConfigFormModal');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                saveWidgetConfigFromModal(form, widgetElement);
            });
        }
        
        // 绑定颜色选择器同步
        modalBody.querySelectorAll('.form-control-color').forEach(colorPicker => {
            colorPicker.addEventListener('input', function() {
                const textInput = this.parentElement.querySelector('input[type="text"]');
                if (textInput) {
                    textInput.value = this.value;
                }
            });
        });
    }

    /**
     * 渲染配置表单（左侧面板，保留兼容性）
     */
    function renderConfigForm(widget, params) {
        const typeIcons = {
            'header': 'ri-layout-top-line',
            'footer': 'ri-layout-bottom-line',
            'sidebar': 'ri-layout-left-line',
            'banner': 'ri-image-line',
            'carousel': 'ri-slideshow-line',
            'product': 'ri-shopping-bag-line',
            'category': 'ri-folder-line',
            'navigation': 'ri-menu-line',
            'search': 'ri-search-line',
            'social': 'ri-share-line',
            'newsletter': 'ri-mail-line',
            'content': 'ri-file-text-line',
        };

        const icon = typeIcons[widget.widget_type] || 'ri-widgets-line';
        const widgetName = widget.meta?.name || widget.widget_code;
        const widgetDesc = widget.meta?.description || '';
        const config = widget.config || {};

        let html = `
            <div class="config-widget-header">
                <div class="config-widget-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="config-widget-info">
                    <h4>${escapeHtml(widgetName)}</h4>
                    <p>${escapeHtml(widgetDesc)}</p>
                </div>
            </div>
            <form class="config-form" id="widgetConfigForm" data-layout-id="${widget.layout_id}">
        `;

        if (Object.keys(params).length === 0) {
            html += '<p class="text-muted">该部件暂无可配置参数</p>';
        } else {
            for (const key in params) {
                const param = params[key];
                const type = param.type || 'string';
                const label = param.label || key;
                const defaultVal = param.default || '';
                const value = config[key] !== undefined ? config[key] : defaultVal;
                const required = param.required || false;
                const description = param.description || '';
                const placeholder = param.placeholder || '';
                const options = param.options || {};

                html += `<div class="form-group">`;
                html += `<label for="config_${key}">${escapeHtml(label)}`;
                if (required) html += ' <span class="text-danger">*</span>';
                html += `</label>`;

                if (type === 'string') {
                    html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'number') {
                    html += `<input type="number" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'boolean') {
                    html += `<div class="form-check">
                        <input type="checkbox" class="form-check-input" id="config_${key}" name="${key}" value="1" ${value ? 'checked' : ''}>
                        <label class="form-check-label" for="config_${key}">启用</label>
                    </div>`;
                } else if (type === 'select') {
                    html += `<select class="form-control" id="config_${key}" name="${key}" ${required ? 'required' : ''}>
                        <option value="">-- 请选择 --</option>`;
                    for (const optVal in options) {
                        html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
                    }
                    html += `</select>`;
                } else if (type === 'url') {
                    html += `<input type="url" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>`;
                } else if (type === 'image') {
                    html += `<div class="input-group">
                        <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="图片URL">
                        <button type="button" class="btn btn-outline-secondary btn-select-image" data-target="config_${key}">
                            <i class="ri-image-line"></i>
                        </button>
                    </div>`;
                    if (value) {
                        html += `<div class="mt-2"><img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 80px;"></div>`;
                    }
                } else if (type === 'color') {
                    html += `<div class="input-group">
                        <input type="color" class="form-control form-control-color" id="config_${key}_picker" value="${escapeHtml(value || '#000000')}" style="width: 50px;">
                        <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="#000000">
                    </div>`;
                } else if (type === 'textarea') {
                    html += `<textarea class="form-control" id="config_${key}" name="${key}" rows="4" 
                             placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
                } else {
                    html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
                }

                if (description) {
                    html += `<small class="form-text">${escapeHtml(description)}</small>`;
                }

                html += `</div>`;
            }
        }

        html += `
            <div class="form-group mt-3">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="ri-save-line"></i> 保存配置
                </button>
            </div>
            </form>
        `;

        elements.configContent.innerHTML = html;
    }

    /**
     * 从模态框保存部件配置
     */
    async function saveWidgetConfigFromModal(form, widgetElement) {
        const layoutId = form.dataset.layoutId;
        if (!layoutId) return;

        const formData = new FormData(form);
        const config = {};

        formData.forEach((value, key) => {
            config[key] = value;
        });

        // 处理复选框（未选中时不会在 FormData 中）
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!formData.has(checkbox.name)) {
                config[checkbox.name] = false;
            } else {
                config[checkbox.name] = true;
            }
        });

        try {
            const response = await fetch(config.apiUpdateConfig, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: config,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast('配置已保存', 'success');
                // 更新部件的 data-config
                if (widgetElement) {
                    widgetElement.dataset.config = JSON.stringify(config);
                }
                // 关闭模态框
                const modal = bootstrap.Modal.getInstance(document.getElementById('widgetConfigModal'));
                if (modal) {
                    modal.hide();
                }
                // 刷新预览
                refreshPreview();
            } else {
                showToast(result.message || '保存失败', 'error');
            }
        } catch (err) {
            console.error('Save config error:', err);
            showToast('保存配置失败', 'error');
        }
    }

    /**
     * 保存部件配置（左侧面板）
     */
    async function saveWidgetConfig(form) {
        const layoutId = form.dataset.layoutId;
        if (!layoutId) return;

        const formData = new FormData(form);
        const config = {};

        formData.forEach((value, key) => {
            config[key] = value;
        });

        // 处理复选框（未选中时不会在 FormData 中）
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!formData.has(checkbox.name)) {
                config[checkbox.name] = false;
            } else {
                config[checkbox.name] = true;
            }
        });

        try {
            const response = await fetch(config.apiUpdateConfig, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: config,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast('配置已保存', 'success');
                // 更新部件的 data-config
                if (state.selectedWidget) {
                    state.selectedWidget.dataset.config = JSON.stringify(config);
                }
                // 刷新预览
                refreshPreview();
            } else {
                showToast(result.message || '保存失败', 'error');
            }
        } catch (err) {
            console.error('Save config error:', err);
            showToast('保存配置失败', 'error');
        }
    }

    /**
     * 删除部件
     */
    async function deleteWidget(widgetElement) {
        if (!confirm('确定要删除此部件吗？')) {
            return;
        }

        const layoutId = widgetElement.dataset.layoutId;
        if (!layoutId) return;

        try {
            const response = await fetch(config.apiDeleteWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ layout_id: layoutId }),
            });

            const result = await response.json();

            if (result.success) {
                widgetElement.remove();
                showToast('删除成功', 'success');
                deselectWidget();
                // 刷新预览
                refreshPreview();
            } else {
                showToast(result.message || '删除失败', 'error');
            }
        } catch (err) {
            console.error('Delete widget error:', err);
            showToast('删除部件失败', 'error');
        }
    }

    /**
     * 保存布局
     */
    async function saveLayout() {
        showToast('布局已自动保存', 'info');
    }

    /**
     * 发布主题
     */
    async function publishTheme() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        if (!confirm('确定要发布此主题吗？发布后将生成缓存文件。')) {
            return;
        }

        try {
            const response = await fetch(config.apiPublish, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ theme_id: state.themeId }),
            });

            const result = await response.json();

            if (result.success) {
                showToast('主题发布成功', 'success');
            } else {
                showToast(result.message || '发布失败', 'error');
            }
        } catch (err) {
            console.error('Publish error:', err);
            showToast('发布主题失败', 'error');
        }
    }

    /**
     * 打开预览
     */
    function openPreview() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        window.open(`${config.apiPreview}?theme_id=${state.themeId}&page_type=${state.pageType}`, '_blank');
    }

    /**
     * 过滤部件
     */
    function filterWidgets(keyword) {
        keyword = keyword.toLowerCase().trim();

        document.querySelectorAll('.widget-item.draggable').forEach(item => {
            const name = (item.dataset.widgetName || '').toLowerCase();
            const code = (item.dataset.widgetCode || '').toLowerCase();

            if (!keyword || name.includes(keyword) || code.includes(keyword)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });

        // 隐藏空分组
        document.querySelectorAll('.widget-group').forEach(group => {
            const visibleWidgets = group.querySelectorAll('.widget-item.draggable:not([style*="display: none"])');
            if (visibleWidgets.length === 0) {
                group.style.display = 'none';
            } else {
                group.style.display = '';
            }
        });
    }

    /**
     * 显示提示
     */
    function showToast(message, type = 'info') {
        // 简单的 toast 实现
        const toast = document.createElement('div');
        toast.className = `toast-message toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : type === 'warning' ? '#ffc107' : '#17a2b8'};
            color: ${type === 'warning' ? '#333' : '#fff'};
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 99999;
            animation: slideIn 0.3s ease;
        `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * 工具函数：防抖
     */
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    /**
     * 工具函数：HTML 转义
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // 添加 CSS 动画
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    /**
     * 刷新预览 iframe
     */
    function refreshPreview() {
        if (!elements.previewFrame || !state.themeId) {
            return;
        }

        // 显示加载状态
        if (elements.previewLoading) {
            elements.previewLoading.classList.remove('hidden');
        }

        // 刷新 iframe（添加时间戳避免缓存）
        const currentSrc = elements.previewFrame.src;
        const url = new URL(currentSrc, window.location.origin);
        url.searchParams.set('_t', Date.now());
        elements.previewFrame.src = url.toString();
    }

    // 初始化
    document.addEventListener('DOMContentLoaded', init);
})();
