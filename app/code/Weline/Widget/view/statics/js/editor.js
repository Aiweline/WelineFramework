/**
 * 可视化编辑器核心 JavaScript
 */
class WidgetEditor {
    constructor(options) {
        this.pageId = options.pageId || 0;
        this.pageContent = options.pageContent || '';
        this.widgets = options.widgets || {};
        this.saveUrl = options.saveUrl || '';
        this.previewUrl = options.previewUrl || '';
        this.selectedWidget = null;
        this.widgetsData = []; // 画布中的部件数据
    }

    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    notify(type, message) {
        const toast = window.BackendToast;
        if (toast && typeof toast[type] === 'function') {
            toast[type](message);
            return;
        }
        if (toast && typeof toast.info === 'function') {
            toast.info(message);
            return;
        }
        console[type === 'error' ? 'error' : 'log'](message);
    }

    confirmAction(message, options) {
        if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
            return window.BackendConfirm.show(message, options || {});
        }
        this.notify('warning', __('确认组件不可用，操作已取消'));
        return Promise.resolve(false);
    }

    init() {
        this.initEventListeners();
        this.loadPageContent();
        this.initDragDrop();
    }

    /**
     * 初始化事件监听
     */
    initEventListeners() {
        // 保存按钮
        const saveBtn = document.getElementById('save-page-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.savePage());
        }

        // 预览按钮
        const previewBtn = document.getElementById('preview-page-btn');
        if (previewBtn) {
            previewBtn.addEventListener('click', () => this.previewPage());
        }

        // 部件搜索
        const searchInput = document.getElementById('widget-search');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => this.searchWidgets(e.target.value));
        }

        // 部件点击添加到画布
        document.querySelectorAll('.widget-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const type = item.dataset.type;
                const name = item.dataset.name;
                const widgetData = JSON.parse(item.dataset.widget || '{}');
                this.addWidgetToCanvas(type, name, widgetData);
            });
        });

        document.addEventListener('click', (event) => {
            const actionButton = event.target.closest('[data-widget-action]');
            if (!actionButton) {
                return;
            }
            if (!actionButton.closest('#canvas-widgets') && !actionButton.closest('#widget-properties')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const action = actionButton.dataset.widgetAction;
            const index = Number.parseInt(actionButton.dataset.index || '', 10);

            if (action === 'edit' && Number.isInteger(index)) {
                this.editWidget(index);
                return;
            }
            if (action === 'remove' && Number.isInteger(index)) {
                this.removeWidget(index);
                return;
            }
            if (action === 'update-params') {
                this.updateWidgetParams();
            }
        });
    }

    /**
     * 加载页面内容
     */
    loadPageContent() {
        if (this.pageContent) {
            // 解析页面内容中的 w:widget 标签
            this.parsePageContent(this.pageContent);
        }
    }

    /**
     * 解析页面内容
     */
    parsePageContent(content) {
        // 简单的正则解析 w:widget 标签
        const regex = /<w:widget\s+([^>]+)\s*\/?>/gi;
        const matches = content.matchAll(regex);
        
        this.widgetsData = [];
        for (const match of matches) {
            const attrs = this.parseAttributes(match[1]);
            if (attrs.type && attrs.name) {
                const widgetId = attrs.id || 'widget-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                let params = {};
                if (attrs.params) {
                    try {
                        params = JSON.parse(attrs.params);
                    } catch (e) {
                        console.error(__('解析部件参数失败'), e);
                    }
                }
                
                this.widgetsData.push({
                    id: widgetId,
                    type: attrs.type,
                    name: attrs.name,
                    params: params
                });
            }
        }
        
        this.renderCanvas();
    }

    /**
     * 解析属性字符串
     */
    parseAttributes(attrString) {
        const attrs = {};
        const regex = /(\w+)=["']([^"']+)["']/g;
        let match;
        while ((match = regex.exec(attrString)) !== null) {
            attrs[match[1]] = match[2];
        }
        return attrs;
    }

    /**
     * 添加部件到画布
     */
    addWidgetToCanvas(type, name, widgetData) {
        const widgetId = 'widget-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        const widget = {
            id: widgetId,
            type: type,
            name: name,
            params: {}
        };

        // 设置默认参数
        if (widgetData.params) {
            Object.keys(widgetData.params).forEach(key => {
                widget.params[key] = widgetData.params[key].default || null;
            });
        }

        this.widgetsData.push(widget);
        this.renderCanvas();
        this.selectWidget(widget);
    }

    /**
     * 渲染画布
     */
    renderCanvas() {
        const canvas = document.getElementById('canvas-widgets');
        const placeholder = document.getElementById('canvas-placeholder');
        
        if (!canvas) return;

        if (this.widgetsData.length === 0) {
            canvas.innerHTML = '';
            if (placeholder) {
                placeholder.classList.remove('hidden');
            }
            return;
        }

        if (placeholder) {
            placeholder.classList.add('hidden');
        }

        canvas.innerHTML = this.widgetsData.map((widget, index) => {
            const widgetInfo = this.widgets[widget.type]?.[widget.name] || {};
            const widgetTitle = this.escapeHtml(widgetInfo.name || widget.name);
            const widgetId = this.escapeHtml(widget.id);
            return `
                <div class="widget-container" data-widget-id="${widgetId}" data-index="${index}">
                    <div class="widget-actions">
                        <button type="button" class="btn btn-sm btn-primary" data-widget-action="edit" data-index="${index}">
                            <i class="ri-edit-line"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" data-widget-action="remove" data-index="${index}">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </div>
                    <div class="widget-content">
                        <div class="widget-preview" data-widget-id="${widgetId}">
                            <div class="text-muted p-3 text-center">
                                <i class="ri-widget-line" style="font-size: 24px;"></i>
                                <div class="mt-2">${widgetTitle}</div>
                                <small class="text-muted">加载中...</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // 绑定点击事件
        canvas.querySelectorAll('.widget-container').forEach(container => {
            container.addEventListener('click', (e) => {
                if (!e.target.closest('.widget-actions')) {
                    const index = parseInt(container.dataset.index);
                    this.selectWidget(this.widgetsData[index]);
                }
            });
        });

        // 更新拖放手柄
        this.updateCanvasDragHandles();

        // 加载预览
        this.loadPreviews();
    }

    /**
     * 选择部件
     */
    selectWidget(widget) {
        this.selectedWidget = widget;
        
        // 更新选中状态
        document.querySelectorAll('.widget-container').forEach(container => {
            container.classList.remove('selected');
        });
        const container = document.querySelector(`[data-widget-id="${widget.id}"]`);
        if (container) {
            container.classList.add('selected');
        }

        // 显示属性配置
        this.renderProperties(widget);
    }

    /**
     * 渲染属性配置面板
     */
    renderProperties(widget) {
        const propertiesPanel = document.getElementById('widget-properties');
        if (!propertiesPanel) return;

        const widgetInfo = this.widgets[widget.type]?.[widget.name] || {};
        const params = widgetInfo.params || {};

        if (Object.keys(params).length === 0) {
            propertiesPanel.innerHTML = `
                <div class="text-muted text-center py-3">
                    <p>此部件没有可配置的参数</p>
                </div>
            `;
            return;
        }

        let html = '<div class="widget-property-group">';
        html += `<h6>${this.escapeHtml(widgetInfo.name || widget.name)}</h6>`;
        
        Object.keys(params).forEach(key => {
            const param = params[key];
            const value = widget.params[key] !== undefined ? widget.params[key] : (param.default || '');
            const keyAttr = this.escapeHtml(key);
            const valueAttr = this.escapeHtml(value);
            const paramLabel = this.escapeHtml(param.label || key);
            
            html += '<div class="mb-3">';
            html += `<label class="form-label">${paramLabel}${param.required ? ' <span class="text-danger">*</span>' : ''}</label>`;
            
            switch (param.type) {
                case 'bool':
                    html += `
                        <select class="form-select" data-param="${keyAttr}">
                            <option value="1" ${value ? 'selected' : ''}>是</option>
                            <option value="0" ${!value ? 'selected' : ''}>否</option>
                        </select>
                    `;
                    break;
                case 'select':
                    const options = param.options || [];
                    html += `<select class="form-select" data-param="${keyAttr}">`;
                    options.forEach(opt => {
                        const optValue = typeof opt === 'object' ? opt.value : opt;
                        const optLabel = typeof opt === 'object' ? opt.label : opt;
                        html += `<option value="${this.escapeHtml(optValue)}" ${value === optValue ? 'selected' : ''}>${this.escapeHtml(optLabel)}</option>`;
                    });
                    html += '</select>';
                    break;
                case 'color':
                    html += `<input type="color" class="form-control form-control-color" data-param="${keyAttr}" value="${valueAttr}">`;
                    break;
                case 'image':
                    html += `
                        <input type="text" class="form-control" data-param="${keyAttr}" value="${valueAttr}" placeholder="图片 URL">
                        <button type="button" class="btn btn-sm btn-secondary mt-1">选择图片</button>
                    `;
                    break;
                default:
                    html += `<input type="text" class="form-control" data-param="${keyAttr}" value="${valueAttr}">`;
            }
            
            if (param.description) {
                html += `<small class="form-text text-muted">${this.escapeHtml(param.description)}</small>`;
            }
            
            html += '</div>';
        });
        
        html += '</div>';
        html += '<button type="button" class="btn btn-primary w-100" data-widget-action="update-params">更新</button>';
        
        propertiesPanel.innerHTML = html;

        // 绑定参数变更事件（实时更新）
        propertiesPanel.querySelectorAll('[data-param]').forEach(input => {
            input.addEventListener('input', () => {
                this.updateWidgetParamsRealtime();
            });
            input.addEventListener('change', () => {
                this.updateWidgetParams();
            });
        });
    }

    /**
     * 实时更新部件参数（不重新渲染整个画布）
     */
    updateWidgetParamsRealtime() {
        if (!this.selectedWidget) return;

        const propertiesPanel = document.getElementById('widget-properties');
        if (!propertiesPanel) return;

        const inputs = propertiesPanel.querySelectorAll('[data-param]');
        const tempParams = {};
        
        inputs.forEach(input => {
            const key = input.dataset.param;
            let value = input.value;
            
            // 类型转换
            if (input.type === 'number') {
                value = parseFloat(value) || 0;
            } else if (input.tagName === 'SELECT' && input.classList.contains('form-select')) {
                if (value === '1' || value === '0') {
                    value = value === '1';
                }
            }
            
            tempParams[key] = value;
        });

        // 更新选中部件的参数
        this.selectedWidget.params = { ...this.selectedWidget.params, ...tempParams };

        // 更新画布中的部件数据
        const index = this.widgetsData.findIndex(w => w.id === this.selectedWidget.id);
        if (index !== -1) {
            this.widgetsData[index].params = this.selectedWidget.params;
        }

        // 实时预览（可选，可以添加防抖）
        this.previewWidget(this.selectedWidget);
    }

    /**
     * 预览单个部件
     */
    async previewWidget(widget) {
        const previewElement = document.querySelector(`.widget-preview[data-widget-id="${widget.id}"]`);
        if (!previewElement || !this.previewUrl) return;

        try {
            const paramsJson = JSON.stringify(widget.params);
            const response = await fetch(
                `${this.previewUrl}?type=${encodeURIComponent(widget.type)}&name=${encodeURIComponent(widget.name)}&params=${encodeURIComponent(paramsJson)}`
            );
            const result = await response.json();
            
            if (result.success && result.preview_safe === true && typeof result.html === 'string') {
                this.renderPreviewFrame(previewElement, result.html);
            } else if (!result.success && result.message) {
                previewElement.textContent = result.message;
            }
        } catch (error) {
            console.error(__('预览失败'), error);
        }
    }

    renderPreviewFrame(previewElement, html) {
        const frame = document.createElement('iframe');
        frame.setAttribute('sandbox', '');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.setAttribute('title', __('预览'));
        frame.style.width = '100%';
        frame.style.minHeight = '160px';
        frame.style.border = '0';
        frame.style.display = 'block';
        frame.srcdoc = `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src http: https: data:; media-src http: https: data:; font-src http: https: data:; style-src 'unsafe-inline'; form-action 'none'; base-uri 'none'; script-src 'none'; object-src 'none'"><style>html,body{margin:0;padding:0;min-height:100%;font-family:Arial,sans-serif;}img,video{max-width:100%;height:auto;}</style></head><body>${html}</body></html>`;
        previewElement.replaceChildren(frame);
    }

    /**
     * 更新部件参数
     */
    updateWidgetParams() {
        if (!this.selectedWidget) return;

        const propertiesPanel = document.getElementById('widget-properties');
        if (!propertiesPanel) return;

        const inputs = propertiesPanel.querySelectorAll('[data-param]');
        inputs.forEach(input => {
            const key = input.dataset.param;
            let value = input.value;
            
            // 类型转换
            if (input.type === 'number') {
                value = parseFloat(value) || 0;
            } else if (input.tagName === 'SELECT' && input.classList.contains('form-select')) {
                // 布尔值处理
                if (value === '1' || value === '0') {
                    value = value === '1';
                }
            }
            
            this.selectedWidget.params[key] = value;
        });

        // 更新画布中的部件
        const index = this.widgetsData.findIndex(w => w.id === this.selectedWidget.id);
        if (index !== -1) {
            this.widgetsData[index] = this.selectedWidget;
            this.renderCanvas();
            this.selectWidget(this.selectedWidget);
        }
    }

    /**
     * 编辑部件
     */
    editWidget(index) {
        const widget = this.widgetsData[index];
        if (!widget) return;

        // 生成 w:widget 标签代码
        const paramsJson = JSON.stringify(widget.params, null, 2);
        const code = `<w:widget type="${widget.type}" name="${widget.name}" params='${paramsJson.replace(/'/g, "\\'")}' />`;
        
        const codeEditor = document.getElementById('code-editor');
        if (codeEditor) {
            codeEditor.value = code;
            const modal = new bootstrap.Modal(document.getElementById('code-editor-modal'));
            modal.show();
        }
    }

    /**
     * 删除部件
     */
    removeWidget(index) {
        this.confirmAction(__('确定要删除这个部件吗？'), {
            title: __('确认删除'),
            type: 'danger',
            confirmText: __('删除'),
            cancelText: __('取消')
        }).then((confirmed) => {
            if (!confirmed) {
                return;
            }
            this.widgetsData.splice(index, 1);
            this.renderCanvas();
            this.selectedWidget = null;
            document.getElementById('widget-properties').innerHTML = `
                <div class="text-muted text-center py-5">
                    <i class="ri-settings-3-line" style="font-size: 48px;"></i>
                    <p>${__('选择一个部件进行配置')}</p>
                </div>
            `;
        });
    }

    /**
     * 初始化拖放
     */
    initDragDrop() {
        const canvas = document.getElementById('editor-canvas');
        if (!canvas) return;

        // 部件项拖放（添加到画布）
        document.querySelectorAll('.widget-item').forEach(item => {
            item.draggable = true;
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    type: item.dataset.type,
                    name: item.dataset.name,
                    widget: item.dataset.widget
                }));
                item.classList.add('dragging');
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
            });
        });

        // 画布接收拖放
        canvas.addEventListener('dragover', (e) => {
            e.preventDefault();
            canvas.classList.add('drag-over');
        });

        canvas.addEventListener('dragleave', () => {
            canvas.classList.remove('drag-over');
        });

        canvas.addEventListener('drop', (e) => {
            e.preventDefault();
            canvas.classList.remove('drag-over');
            
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            if (data.type && data.name) {
                const widgetData = JSON.parse(data.widget || '{}');
                this.addWidgetToCanvas(data.type, data.name, widgetData);
            }
        });

        // 画布内部件排序拖放
        this.initCanvasSortable();
    }

    /**
     * 初始化画布内部件排序
     */
    initCanvasSortable() {
        const canvas = document.getElementById('canvas-widgets');
        if (!canvas) return;

        // 使用原生 HTML5 Drag and Drop 实现排序
        canvas.addEventListener('dragover', (e) => {
            e.preventDefault();
            const afterElement = this.getDragAfterElement(canvas, e.clientY);
            const dragging = document.querySelector('.widget-container.dragging');
            if (dragging) {
                if (afterElement == null) {
                    canvas.appendChild(dragging);
                } else {
                    canvas.insertBefore(dragging, afterElement);
                }
            }
        });

        // 为每个部件容器添加拖放功能
        this.updateCanvasDragHandles();
    }

    /**
     * 更新画布拖放手柄
     */
    updateCanvasDragHandles() {
        const containers = document.querySelectorAll('.widget-container');
        containers.forEach(container => {
            container.draggable = true;
            container.addEventListener('dragstart', (e) => {
                container.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            container.addEventListener('dragend', () => {
                container.classList.remove('dragging');
                this.updateWidgetOrder();
            });
        });
    }

    /**
     * 获取拖放后的元素位置
     */
    getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.widget-container:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    /**
     * 更新部件顺序
     */
    updateWidgetOrder() {
        const containers = document.querySelectorAll('.widget-container');
        const newOrder = [];
        
        containers.forEach((container, index) => {
            const widgetId = container.dataset.widgetId;
            const widget = this.widgetsData.find(w => w.id === widgetId);
            if (widget) {
                newOrder.push(widget);
            }
        });

        this.widgetsData = newOrder;
    }

    /**
     * 搜索部件
     */
    searchWidgets(keyword) {
        const keywordLower = keyword.toLowerCase();
        document.querySelectorAll('.widget-item').forEach(item => {
            const name = item.querySelector('.widget-item-name')?.textContent.toLowerCase() || '';
            const desc = item.querySelector('.widget-item-desc')?.textContent.toLowerCase() || '';
            const matches = name.includes(keywordLower) || desc.includes(keywordLower);
            item.style.display = matches ? '' : 'none';
        });
    }

    /**
     * 加载预览
     */
    async loadPreviews() {
        const previews = document.querySelectorAll('.widget-preview');
        for (const preview of previews) {
            const widgetId = preview.dataset.widgetId;
            const widget = this.widgetsData.find(w => w.id === widgetId);
            if (!widget) continue;

            // 加载预览
            await this.previewWidget(widget);
        }
    }

    /**
     * 保存页面
     */
    async savePage() {
        const title = document.getElementById('page-title-input')?.value || '';
        const handle = document.getElementById('page-handle-input')?.value || '';
        const pageId = document.getElementById('page-id')?.value || 0;

        if (!title || !handle) {
            this.notify('warning', __('请填写页面标题和标识'));
            return;
        }

        // 生成页面内容
        const content = this.generatePageContent();
        const previewWindow = window.open('', '_blank');
        if (!previewWindow) {
            return;
        }

        const previewDocument = previewWindow.document;
        previewDocument.title = __('Page preview');
        previewDocument.body.textContent = '';

        const style = previewDocument.createElement('style');
        style.textContent = 'body { padding: 20px; font-family: Arial, sans-serif; }';
        previewDocument.head.appendChild(style);

        const heading = previewDocument.createElement('h2');
        heading.textContent = __('Page preview');
        previewDocument.body.appendChild(heading);

        const previewContent = previewDocument.createElement('pre');
        previewContent.id = 'preview-content';
        previewContent.textContent = content;
        previewDocument.body.appendChild(previewContent);

        const note = previewDocument.createElement('p');
        note.className = 'text-muted';
        note.textContent = __('The w:widget tags in this preview need server-side rendering before actual widget content is shown.');
        previewDocument.body.appendChild(note);
    }
}

// 全局实例
let widgetEditor;

