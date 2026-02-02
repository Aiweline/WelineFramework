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
        selectedArea: null, // 当前选中的区域代码
        isDragging: false,
        hasChanges: false,
        draggingWidget: null, // 当前拖拽的部件数据
        selectedSlot: null, // 当前选中的插槽
        originalWidgetOrder: new Map(), // 保存原始部件顺序
        originalGroupOrder: [], // 保存原始分组顺序
        previewRefreshInFlight: false,
        previewRefreshQueued: false,
        previewStatus: 'draft', // 预览版本状态：draft（草稿）/ published（已发布）
    };

    // DOM 元素
    let elements = {};

    // 注意：pageType 和 layoutType 现在是同一个概念
    // 之前的 layoutTypeToPageType / pageTypeToLayoutType 转换函数已移除
    // 页面类型就是布局类型，直接使用 pageType

    /**
     * 初始化
     */
    function init() {
        const container = document.getElementById('themeEditor');
        if (!container) {
            console.error('[ThemeEditor] Container #themeEditor not found!');
            return;
        }

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
        config.apiCompileLayout = container.dataset.apiCompileLayout || `${config.apiBase}/compile-layout`;
        config.apiLayoutPreview = container.dataset.apiLayoutPreview || `${config.apiBase}/layout-preview`;
        config.apiParamRenderForm = container.dataset.apiParamRenderForm || '/theme/backend/widget/paramRender/form';
        config.apiSaveCompiledLayout = container.dataset.apiSaveCompiledLayout || `${config.apiBase}/save-compiled-layout`;

        // Preview-related endpoints and call sites (baseline for TDD)
        // - apiRenderWidget: used by renderWidgetPreview()/preview render flows
        // - apiWidgetPreview: legacy per-widget preview fetches (to be removed)
        // - apiLayoutPreview: used by refreshPreviewWidgets() and loadLayoutPreview()
        // - apiCompileLayout: used by fetchLayoutSlots()
        // - apiUpdateConfig/apiSaveWidget: save flows that should return preview_html

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
            slotsInfoPanel: document.getElementById('slotsInfoPanel'),
            slotsInfoList: document.getElementById('slotsInfoList'),
            btnPreview: document.getElementById('btnPreview'),
            btnSave: document.getElementById('btnSave'),
            btnPublish: document.getElementById('btnPublish'),
            btnRestoreLayout: document.getElementById('btnRestoreLayout'),
            btnRefreshPreview: document.getElementById('btnRefreshPreview'),
            btnFullscreenPreview: document.getElementById('btnFullscreenPreview'),
        };

        // 当前布局信息
        // pageType 和 layoutType 现在是同一个概念，直接使用 pageType
        state.layoutType = state.pageType || 'homepage';
        state.layoutOption = 'default';
        state.slots = {}; // 页面插槽信息

        // 绑定事件
        bindEvents();

        // 初始化拖拽
        initDragAndDrop();

        // 适配部件库预览缩放
        fitWidgetPreviews();
        window.addEventListener('resize', debounce(() => {
            fitWidgetPreviews();
        }, 200));

        console.log('Theme Editor initialized', {
            apiBase: config.apiBase,
            apiSaveWidget: config.apiSaveWidget,
            apiUpdateConfig: config.apiUpdateConfig,
            apiDeleteWidget: config.apiDeleteWidget,
            apiWidgets: config.apiWidgets,
            apiPublish: config.apiPublish,
            apiPreview: config.apiPreview,
            themeId: state.themeId,
            pageType: state.pageType,
            layoutType: state.layoutType
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

        // 页面类型选择（AJAX 切换，不刷新页面）
        if (elements.pageTypeSelect) {
            elements.pageTypeSelect.addEventListener('change', function() {
                const pageType = this.value;
                if (state.themeId && pageType) {
                    // 更新状态
                    state.pageType = pageType;
                    state.layoutType = pageType; // pageType 和 layoutType 是同一个概念
                    
                    // 更新 URL（不刷新页面，便于分享链接）
                    const url = new URL(window.location.href);
                    url.searchParams.set('page_type', pageType);
                    window.history.replaceState({}, '', url.toString());
                    
                    // AJAX 加载新布局预览
                    loadLayoutPreview();
                    
                    showToast(__('已切换到: ') + this.options[this.selectedIndex].text, 'info');
                }
            });
        }

        // 关闭插槽面板按钮
        document.getElementById('closeSlotsPanel')?.addEventListener('click', function() {
            if (elements.slotsInfoPanel) {
                elements.slotsInfoPanel.classList.remove('active');
            }
        });

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

        // 点击预览区的部件或区域
        document.addEventListener('click', function(e) {
            const widgetItem = e.target.closest('.preview-widget-item');
            if (widgetItem) {
                selectWidget(widgetItem);
                return;
            }

            // 点击区域标签或区域本身时，选中区域并过滤部件
            const areaLabel = e.target.closest('.area-label');
            const previewArea = e.target.closest('.preview-area');
            
            if (areaLabel || (previewArea && !e.target.closest('.area-widgets'))) {
                // 点击区域标签或区域空白处（非部件列表区域）
                const area = areaLabel ? areaLabel.closest('.preview-area') : previewArea;
                if (area && area.dataset.area) {
                    selectArea(area);
                    return;
                }
            }
            
            // 点击空白区域时，取消选中 slot 并恢复部件顺序
            const slotElement = e.target.closest('.container-slot, [data-wslot], .preview-area');
            if (!slotElement && !e.target.closest('.widget-item')) {
                clearSlotSelection();
                // 同时取消区域选中
                deselectArea();
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
            // 添加错误监听
            elements.previewFrame.addEventListener('error', function(e) {
                console.error('[ThemeEditor] Iframe error:', e);
            });
            
            elements.previewFrame.addEventListener('load', function() {
                if (elements.previewLoading) {
                    elements.previewLoading.classList.add('hidden');
                }
                
                // 设置 iframe 内链接拦截，使链接跳转到预览模式
                setupIframeLinkInterception();
                
                // 初始化部件 hover 操作按钮
                setTimeout(() => initWidgetHoverActions(), 100);
            });
            
            // 添加超时机制：如果 5 秒后仍未加载完成，强制隐藏加载状态
            setTimeout(function() {
                if (elements.previewLoading && !elements.previewLoading.classList.contains('hidden')) {
                    elements.previewLoading.classList.add('hidden');
                }
            }, 5000);
        } else {
            console.error('[ThemeEditor] Preview iframe element not found!');
        }

        // 恢复原始布局按钮
        if (elements.btnRestoreLayout) {
            elements.btnRestoreLayout.addEventListener('click', handleRestoreLayout);
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
     * 适配部件库预览缩放（宽度100%，高度自适应）
     */
    function fitWidgetPreviews() {
        const canvases = document.querySelectorAll('.widget-preview-canvas');
        canvases.forEach(canvas => {
            const child = canvas.firstElementChild;
            if (!child) return;

            // 重置
            child.style.transform = '';
            child.style.transformOrigin = 'top left';
            child.style.display = 'block';

            const containerWidth = canvas.clientWidth;
            const naturalWidth = child.scrollWidth || child.offsetWidth || containerWidth;
            const naturalHeight = child.scrollHeight || child.offsetHeight || 0;

            if (!containerWidth || !naturalWidth) return;

            const scale = Math.min(1, containerWidth / naturalWidth);
            child.style.transform = `scale(${scale})`;

            // 高度始终自适应内容
            canvas.style.height = 'auto';
            canvas.style.minHeight = 'auto';
        });
    }

    /**
     * 处理来自 iframe 的消息
     */
    function handleIframeMessage(e) {
        const data = e.data;
        if (!data || !data.type) return;

        console.log('收到 iframe 消息:', data);

        switch (data.type) {
            case 'widget-selected':
                // 预览页面中选中了部件
                handlePreviewWidgetSelected(data);
                break;
            case 'slot-clicked':
                // 预览页面中点击了插槽（旧版）
                handlePreviewSlotClicked(data);
                break;
            case 'slot-selected':
                // 预览页面中选中了插槽（新版）
                handleSlotSelected(data.slot);
                break;
            case 'widget-dropped':
                // 部件被拖放到插槽
                handleWidgetDropped(data.widget, data.slot);
                break;
            case 'widget-rejected':
                // 部件被插槽拒绝
                showToast(data.reason || '部件被拒绝', 'error');
                break;
        }
    }

    /**
     * 处理插槽选中
     */
    function handleSlotSelected(slot) {
        console.log('插槽被选中:', slot);
        
        // 保存当前选中的插槽
        state.selectedSlot = slot;
        
        // 高亮右侧部件列表中可放入该插槽的部件并排序
        let acceptCodes = slot.accept || [];
        // 确保 acceptCodes 是数组
        if (typeof acceptCodes === 'string') {
            acceptCodes = acceptCodes.split(',').map(s => s.trim()).filter(s => s);
        }
        if (!Array.isArray(acceptCodes)) {
            acceptCodes = [];
        }
        highlightAcceptableWidgets(acceptCodes);
        
        // 滚动到高亮的部件（延迟以等待DOM更新）
        scrollToHighlightedWidgets();
        
        // 更新左侧配置面板，显示插槽信息
        renderSlotInfoPanel(slot);
        
        // 显示提示
        showToast(`已选中插槽: ${slot.name}`, 'info');
    }
    
    /**
     * 渲染插槽信息到配置面板
     * - 如果插槽内有部件，显示所有部件的配置（可折叠）
     * - 如果插槽内没有部件，显示空状态提示
     */
    async function renderSlotInfoPanel(slot) {
        if (!elements.configContent) return;
        
        const slotName = slot.name || slot.id || '未命名插槽';
        const slotId = slot.id || '';
        const acceptCodes = slot.accept || [];
        
        // 显示加载状态
        elements.configContent.innerHTML = `
            <div class="slot-loading text-center py-4">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <p class="mt-2 text-muted">加载插槽配置...</p>
            </div>
        `;
        
        // 查找插槽内的部件
        // 支持多种选择器：area-widgets、container-slot、data-wslot
        const widgetsInSlot = findWidgetsInSlot(slotId);
        
        if (widgetsInSlot.length > 0) {
            // 有部件，加载并显示所有部件的配置
            await renderSlotWidgetsConfig(slot, widgetsInSlot);
        } else {
            // 无部件，显示空状态
            renderSlotEmptyState(slot);
        }
    }
    
    /**
     * 查找插槽内的部件元素
     * 注意：部件在 iframe 内，需要从 iframe 的 document 中查找
     */
    function findWidgetsInSlot(slotId) {
        const widgets = [];
        
        // 获取 iframe 的 document
        let iframeDoc = null;
        try {
            if (elements.previewFrame && elements.previewFrame.contentDocument) {
                iframeDoc = elements.previewFrame.contentDocument;
            } else if (elements.previewFrame && elements.previewFrame.contentWindow) {
                iframeDoc = elements.previewFrame.contentWindow.document;
            }
        } catch (e) {
            console.warn('无法访问 iframe document:', e);
        }
        
        if (!iframeDoc) {
            console.log('findWidgetsInSlot: iframe document 不可用');
            return widgets;
        }
        
        // 尝试多种选择器查找部件
        // 1. 查找 widget-wrapper 容器内的部件（SlotRendererService 渲染的）
        // 2. 查找原有标记的部件元素
        const selectors = [
            `[data-wslot="${slotId}"] .widget-wrapper`,
            `[data-wslot="${slotId}"] [data-layout-id]`,
            `.area-widgets[data-area="${slotId}"] .preview-widget-item`,
            `.area-widgets[data-area="${slotId}"] [data-layout-id]`,
            `.${slotId}-slot-widgets .preview-widget-item`,
            `.container-slot[data-slot="${slotId}"] .preview-widget-item`,
            `.area-slot[data-slot="${slotId}"] .preview-widget-item`,
        ];
        
        for (const selector of selectors) {
            try {
                const found = iframeDoc.querySelectorAll(selector);
                if (found.length > 0) {
                    console.log(`findWidgetsInSlot: 在 iframe 中找到 ${found.length} 个部件，选择器: ${selector}`);
                    found.forEach(el => widgets.push(el));
                    break;
                }
            } catch (e) {
                console.warn('选择器查询失败:', selector, e);
            }
        }
        
        // 如果没找到，尝试直接查找该 slot 容器
        if (widgets.length === 0) {
            try {
                const slotContainer = iframeDoc.querySelector(`[data-wslot="${slotId}"]`);
                
                if (slotContainer) {
                    console.log(`findWidgetsInSlot: 找到 slot 容器，检查内部内容...`);
                    // 检查容器内是否有任何带 data-layout-id 或 widget 相关类名的元素
                    const innerWidgets = slotContainer.querySelectorAll('[data-layout-id], .widget-wrapper, .widget-content');
                    
                    if (innerWidgets.length > 0) {
                        innerWidgets.forEach(el => widgets.push(el));
                    }
                }
            } catch (e) {
                console.warn('slot 容器查询失败:', e);
            }
        }
        
        console.log(`findWidgetsInSlot(${slotId}): 找到 ${widgets.length} 个部件`);
        return widgets;
    }
    
    /**
     * 渲染插槽内所有部件的配置（可折叠手风琴）
     */
    async function renderSlotWidgetsConfig(slot, widgetElements) {
        const slotName = slot.name || slot.id || '未命名插槽';
        const slotId = slot.id || '';
        
        // 收集部件信息（从 iframe 内的元素读取 data 属性）
        const widgetsData = [];
        for (const el of widgetElements) {
            // 尝试从元素本身或其 .widget-wrapper 父元素读取数据
            let dataSource = el;
            if (!el.dataset.layoutId && el.closest) {
                const wrapper = el.closest('.widget-wrapper');
                if (wrapper) {
                    dataSource = wrapper;
                }
            }
            
            const layoutId = dataSource.dataset?.layoutId || dataSource.getAttribute?.('data-layout-id');
            const widgetCode = dataSource.dataset?.widgetCode || dataSource.getAttribute?.('data-widget-code');
            const widgetModule = dataSource.dataset?.widgetModule || dataSource.getAttribute?.('data-widget-module');
            const widgetType = dataSource.dataset?.widgetType || dataSource.getAttribute?.('data-widget-type');
            const widgetName = dataSource.dataset?.widgetName || dataSource.getAttribute?.('data-widget-name') || widgetCode || '未知部件';
            
            // #region agent log
            fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:renderSlotWidgetsConfig',message:'Widget data extraction',data:{layoutId,widgetCode,widgetModule,widgetType,widgetName,datasetWidgetName:dataSource.dataset?.widgetName,attrWidgetName:dataSource.getAttribute?.('data-widget-name'),dataSourceTagName:dataSource.tagName,dataSourceClassName:dataSource.className},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'B,E'})}).catch(()=>{});
            // #endregion
            
            // 只添加有 layoutId 的部件
            if (layoutId) {
                widgetsData.push({
                    element: el,
                    layoutId,
                    widgetCode,
                    widgetModule,
                    widgetType,
                    widgetName
                });
            }
        }
        
        // 如果没有可识别的部件，显示空状态
        if (widgetsData.length === 0) {
            console.log('renderSlotWidgetsConfig: 没有找到可识别的部件，显示空状态');
            renderSlotEmptyState(slot);
            return;
        }
        
        // 构建手风琴 HTML
        let accordionHtml = '';
        for (let i = 0; i < widgetsData.length; i++) {
            const widget = widgetsData[i];
            const isFirst = i === 0;
            const collapseId = `widgetConfig_${widget.layoutId || i}`;
            
            // 类型图标映射
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
                'container': 'ri-layout-grid-line',
            };
            const icon = typeIcons[widget.widgetType] || 'ri-widgets-line';
            
            accordionHtml += `
                <div class="slot-widget-accordion-item" data-layout-id="${widget.layoutId}">
                    <div class="slot-widget-header ${isFirst ? '' : 'collapsed'}" 
                         data-bs-toggle="collapse" 
                         data-bs-target="#${collapseId}"
                         aria-expanded="${isFirst ? 'true' : 'false'}">
                        <div class="widget-header-left">
                            <i class="${icon}"></i>
                            <span class="widget-name">${widget.widgetName}</span>
                        </div>
                        <div class="widget-header-right">
                            <span class="widget-type-badge">${widget.widgetType || 'widget'}</span>
                            <i class="ri-arrow-down-s-line collapse-icon"></i>
                        </div>
                    </div>
                    <div id="${collapseId}" class="slot-widget-body collapse ${isFirst ? 'show' : ''}" data-layout-id="${widget.layoutId}">
                        <div class="widget-config-loading text-center py-3">
                            <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        const html = `
            <div class="slot-config-panel">
                <div class="slot-config-header">
                    <div class="slot-icon">
                        <i class="ri-layout-grid-line"></i>
                    </div>
                    <div class="slot-title">
                        <h5>${slotName}</h5>
                        <span class="widget-count-badge">${widgetsData.length} 个部件</span>
                    </div>
                </div>
                
                <div class="slot-widgets-accordion">
                    ${accordionHtml}
                </div>
                
                <div class="slot-add-more">
                    <button type="button" class="btn btn-sm btn-outline-primary w-100">
                        <i class="ri-add-line"></i> 继续添加部件
                    </button>
                </div>
            </div>
        `;
        
        elements.configContent.innerHTML = html;
        
        // 为每个部件加载配置表单
        for (const widget of widgetsData) {
            if (widget.layoutId) {
                loadWidgetConfigForAccordion(widget.layoutId);
            }
        }
        
        // 绑定手风琴头部点击事件（加载配置）
        document.querySelectorAll('.slot-widget-header').forEach(header => {
            header.addEventListener('click', function() {
                const body = this.nextElementSibling;
                const layoutId = body?.dataset.layoutId;
                if (layoutId && body.querySelector('.widget-config-loading')) {
                    loadWidgetConfigForAccordion(layoutId);
                }
            });
        });
    }
    
    /**
     * 加载部件配置到手风琴面板
     */
    async function loadWidgetConfigForAccordion(layoutId) {
        const configBody = document.querySelector(`.slot-widget-body[data-layout-id="${layoutId}"]`);
        if (!configBody) return;
        
        // 如果已加载，跳过
        if (!configBody.querySelector('.widget-config-loading')) return;
        
        try {
            const apiUrl = `${config.apiBase}/widget-config?layout_id=${layoutId}`;
            const response = await fetch(apiUrl);
            const result = await response.json();
            
            if (result.success && result.data) {
                const widgetData = result.data;
                const params = widgetData.params || {};
                const widgetConfig = widgetData.config || {};
                
                // #region agent log
                fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:loadWidgetConfigForAccordion',message:'Before generateWidgetConfigForm call',data:{layoutId,paramsKeys:Object.keys(params),widgetConfigKeys:Object.keys(widgetConfig)},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'A'})}).catch(()=>{});
                // #endregion
                
                // 生成配置表单
                const formHtml = await generateWidgetConfigForm(layoutId, params, widgetConfig);
                
                // #region agent log
                fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:loadWidgetConfigForAccordion',message:'After generateWidgetConfigForm call',data:{layoutId,formHtmlType:typeof formHtml,formHtmlLength:formHtml?.length,isPromise:formHtml instanceof Promise,formHtmlPreview:String(formHtml).substring(0,200)},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'A'})}).catch(()=>{});
                // #endregion
                
                configBody.innerHTML = formHtml;
                
                // 绑定表单事件
                bindAccordionFormEvents(configBody);
            } else {
                configBody.innerHTML = `<div class="text-muted text-center py-3">
                    <i class="ri-settings-3-line d-block mb-2" style="font-size: 24px; opacity: 0.5;"></i>
                    <small>该部件无可配置项</small>
                </div>`;
            }
        } catch (err) {
            console.error('Load widget config error:', err);
            configBody.innerHTML = `<div class="text-danger text-center py-3">
                <small>加载配置失败</small>
            </div>`;
        }
    }
    
    /**
     * 生成部件配置表单 HTML
     * 优先使用后端 API 渲染，失败时回退到前端渲染
     */
    async function generateWidgetConfigForm(layoutId, params, formConfig) {
        // #region agent log
        fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:generateWidgetConfigForm:entry',message:'Function entry',data:{layoutId,paramsKeys:Object.keys(params||{}),formConfigKeys:Object.keys(formConfig||{}),apiParamRenderForm:config.apiParamRenderForm},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'D'})}).catch(()=>{});
        // #endregion
        
        if (!params || Object.keys(params).length === 0) {
            return `<div class="config-empty-state">
                <i class="ri-settings-3-line"></i>
                <p>该部件无可配置项</p>
            </div>`;
        }
        
        // 尝试使用后端 API 渲染
        try {
            const response = await fetch(config.apiParamRenderForm || '/theme/backend/widget/paramRender/form', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    layoutId: layoutId,
                    params: JSON.stringify(params),
                    config: JSON.stringify(formConfig || {}),
                }),
            });
            
            // #region agent log
            fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:generateWidgetConfigForm:apiResponse',message:'Backend API response',data:{responseOk:response.ok,responseStatus:response.status},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'D'})}).catch(()=>{});
            // #endregion
            
            if (response.ok) {
                const html = await response.text();
                // #region agent log
                fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:generateWidgetConfigForm:htmlResult',message:'Backend HTML result',data:{htmlLength:html?.length,hasError:html?.includes('alert-danger'),htmlPreview:html?.substring(0,200)},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'D'})}).catch(()=>{});
                // #endregion
                if (html && !html.includes('alert-danger')) {
                    return html;
                }
            }
        } catch (err) {
            // #region agent log
            fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'theme-editor.js:generateWidgetConfigForm:error',message:'Backend API error',data:{error:err?.message || String(err)},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'D'})}).catch(()=>{});
            // #endregion
            console.warn('[ThemeEditor] Backend form render failed, using fallback:', err);
        }
        
        // 回退到前端渲染
        return generateWidgetConfigFormFallback(layoutId, params, formConfig);
    }
    
    /**
     * 前端回退渲染方法
     */
    function generateWidgetConfigFormFallback(layoutId, params, formConfig) {
        // 按类型分组字段
        const basicFields = {};
        const styleFields = {};
        const linkFields = {};
        
        for (const [key, param] of Object.entries(params)) {
            if (key.includes('style') || key.includes('size') || key.includes('color') || key.includes('align') || key.includes('gap')) {
                styleFields[key] = param;
            } else if (key.includes('http') || key.includes('url') || key.includes('link') || ['facebook','twitter','instagram','youtube','linkedin','pinterest','tiktok','weibo','wechat','github','telegram','whatsapp','discord','reddit','snapchat'].includes(key)) {
                linkFields[key] = param;
            } else {
                basicFields[key] = param;
            }
        }
        
        // 生成字段HTML的辅助函数
        const renderField = (key, param, layoutId, config) => {
            const label = param.label || key;
            const type = param.type || 'text';
            const value = config[key] ?? param.default ?? '';
            const description = param.description || '';
            const translatable = param.translatable || false;
            const fieldId = `config_${layoutId}_${key}`;
            const fieldClass = translatable ? 'config-field translatable-field' : 'config-field';
            
            let fieldHtml = `<div class="${fieldClass}" data-field-key="${key}" data-translatable="${translatable}">`;
            
            // 字段头部：标签 + 多语言按钮
            fieldHtml += `<div class="config-field-header">
                <label class="config-label" for="${fieldId}">${label}</label>
                ${translatable ? `<button type="button" class="btn-i18n-edit" data-field="${key}" data-layout-id="${layoutId}" title="编辑多语言">
                    <i class="ri-translate-2"></i>
                    <span>多语言</span>
                </button>` : ''}
            </div>`;
            
            // 输入控件
            fieldHtml += `<div class="config-field-input">`;
            if (type === 'bool' || type === 'boolean') {
                fieldHtml += `<div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="${fieldId}" name="${key}" ${value ? 'checked' : ''}>
                    <label class="form-check-label" for="${fieldId}">启用</label>
                </div>`;
            } else if (type === 'select' && param.options) {
                fieldHtml += `<select class="form-select" id="${fieldId}" name="${key}">`;
                for (const [optVal, optLabel] of Object.entries(param.options)) {
                    fieldHtml += `<option value="${optVal}" ${value == optVal ? 'selected' : ''}>${optLabel}</option>`;
                }
                fieldHtml += `</select>`;
            } else if (type === 'textarea' || type === 'html') {
                fieldHtml += `<textarea class="form-control" id="${fieldId}" name="${key}" rows="3">${value}</textarea>`;
            } else if (type === 'number') {
                fieldHtml += `<input type="number" class="form-control" id="${fieldId}" name="${key}" value="${value}" min="${param.min || ''}" max="${param.max || ''}">`;
            } else if (type === 'color') {
                fieldHtml += `<div class="color-picker-wrapper">
                    <input type="color" class="form-control-color" id="${fieldId}_picker" value="${value || '#000000'}">
                    <input type="text" class="form-control" id="${fieldId}" name="${key}" value="${value || '#000000'}" placeholder="#000000">
                </div>`;
            } else if (type === 'url') {
                fieldHtml += `<div class="input-with-icon">
                    <i class="ri-link"></i>
                    <input type="url" class="form-control" id="${fieldId}" name="${key}" value="${value}" placeholder="https://">
                </div>`;
            } else if (type === 'image') {
                fieldHtml += `<div class="image-picker-wrapper">
                    <div class="image-preview-container${value ? ' has-image' : ''}">
                        <div class="image-preview" id="${fieldId}_preview">
                            ${value ? `<img src="${value}" alt="预览">` : '<div class="image-placeholder"><i class="ri-image-add-line"></i><span>点击选择图片</span></div>'}
                        </div>
                    </div>
                    <div class="image-url-input">
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-link"></i></span>
                            <input type="text" class="form-control" id="${fieldId}" name="${key}" value="${value || ''}" placeholder="图片URL">
                        </div>
                    </div>
                </div>`;
            } else if (type === 'range' || type === 'slider') {
                const min = param.min || 0;
                const max = param.max || 100;
                const step = param.step || 1;
                fieldHtml += `<div class="range-slider-wrapper">
                    <div class="range-slider-container">
                        <input type="range" class="form-range" id="${fieldId}_slider" min="${min}" max="${max}" step="${step}" value="${value || min}">
                    </div>
                    <div class="range-value-display">
                        <input type="number" class="form-control range-value-input" id="${fieldId}" name="${key}" min="${min}" max="${max}" step="${step}" value="${value || min}">
                    </div>
                </div>`;
            } else if (type === 'datetime' || type === 'date' || type === 'time') {
                const inputType = type === 'date' ? 'date' : (type === 'time' ? 'time' : 'datetime-local');
                fieldHtml += `<div class="input-group">
                    <span class="input-group-text"><i class="ri-calendar-event-line"></i></span>
                    <input type="${inputType}" class="form-control" id="${fieldId}" name="${key}" value="${value || ''}">
                </div>`;
            } else if (type === 'array') {
                fieldHtml += `<div class="array-editor-wrapper" data-field-id="${fieldId}">
                    <div class="array-items-container" id="${fieldId}_items">
                        <div class="array-empty-state"><i class="ri-list-check-2"></i><p>暂无项目</p></div>
                    </div>
                    <div class="array-actions">
                        <button type="button" class="btn btn-outline-primary btn-add-array-item" data-target="${fieldId}">
                            <i class="ri-add-line"></i> 添加项目
                        </button>
                    </div>
                    <input type="hidden" id="${fieldId}" name="${key}" value='${JSON.stringify(value || [])}'>
                </div>`;
            } else if (type === 'icon') {
                fieldHtml += `<div class="icon-picker-wrapper">
                    <div class="icon-preview">
                        <span class="icon-preview-display">${value ? `<i class="${value}"></i>` : '<i class="ri-add-line placeholder-icon"></i>'}</span>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-icon-picker" data-target="${fieldId}">
                            <i class="ri-apps-line"></i> 选择图标
                        </button>
                    </div>
                    <input type="hidden" id="${fieldId}" name="${key}" value="${value || ''}">
                </div>`;
            } else {
                fieldHtml += `<input type="text" class="form-control" id="${fieldId}" name="${key}" value="${value}">`;
            }
            fieldHtml += `</div>`;
            
            // 多语言编辑区（初始隐藏）
            if (translatable) {
                fieldHtml += `<div class="i18n-edit-panel" id="i18n_panel_${layoutId}_${key}" style="display:none;">
                    <div class="i18n-panel-header">
                        <span><i class="ri-global-line"></i> 多语言配置</span>
                        <button type="button" class="btn-i18n-close" data-field="${key}"><i class="ri-close-line"></i></button>
                    </div>
                    <div class="i18n-panel-body">
                        <div class="i18n-lang-row">
                            <label class="i18n-lang-label"><span class="lang-flag">🇨🇳</span> 简体中文</label>
                            <input type="text" class="form-control i18n-input" data-locale="zh_Hans_CN" data-field="${key}" placeholder="输入简体中文值">
                        </div>
                        <div class="i18n-lang-row">
                            <label class="i18n-lang-label"><span class="lang-flag">🇺🇸</span> English</label>
                            <input type="text" class="form-control i18n-input" data-locale="en_US" data-field="${key}" placeholder="Enter English value">
                        </div>
                    </div>
                    <div class="i18n-panel-footer">
                        <button type="button" class="btn btn-sm btn-primary btn-save-i18n" data-field="${key}" data-layout-id="${layoutId}">
                            <i class="ri-save-line"></i> 保存多语言
                        </button>
                    </div>
                </div>`;
            }
            
            if (description) {
                fieldHtml += `<div class="config-field-description"><i class="ri-information-line"></i> ${description}</div>`;
            }
            fieldHtml += `</div>`;
            return fieldHtml;
        };
        
        // 生成分组HTML
        let groupsHtml = '';
        
        if (Object.keys(basicFields).length > 0) {
            let fieldsHtml = '';
            for (const [key, param] of Object.entries(basicFields)) {
                fieldsHtml += renderField(key, param, layoutId, formConfig);
            }
            groupsHtml += `
                <div class="config-group">
                    <h5 class="config-group-title">
                        <i class="ri-information-line"></i>
                        基本信息
                        <i class="ri-arrow-down-s-line toggle-icon"></i>
                    </h5>
                    <div class="config-fields">${fieldsHtml}</div>
                </div>
            `;
        }
        
        if (Object.keys(styleFields).length > 0) {
            let fieldsHtml = '';
            for (const [key, param] of Object.entries(styleFields)) {
                fieldsHtml += renderField(key, param, layoutId, formConfig);
            }
            groupsHtml += `
                <div class="config-group">
                    <h5 class="config-group-title">
                        <i class="ri-palette-line"></i>
                        样式设置
                        <i class="ri-arrow-down-s-line toggle-icon"></i>
                    </h5>
                    <div class="config-fields">${fieldsHtml}</div>
                </div>
            `;
        }
        
        if (Object.keys(linkFields).length > 0) {
            let fieldsHtml = '';
            for (const [key, param] of Object.entries(linkFields)) {
                fieldsHtml += renderField(key, param, layoutId, formConfig);
            }
            groupsHtml += `
                <div class="config-group collapsed">
                    <h5 class="config-group-title">
                        <i class="ri-links-line"></i>
                        链接配置
                        <i class="ri-arrow-down-s-line toggle-icon"></i>
                    </h5>
                    <div class="config-fields">${fieldsHtml}</div>
                </div>
            `;
        }
        
        return `
            <form class="widget-accordion-config-form" data-layout-id="${layoutId}">
                ${groupsHtml}
                <div class="config-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i> 保存配置
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-delete-widget" data-layout-id="${layoutId}">
                        <i class="ri-delete-bin-line"></i> 删除
                    </button>
                </div>
            </form>
        `;
    }
    
    /**
     * 绑定手风琴配置表单事件
     */
    function bindAccordionFormEvents(container) {
        // 配置分组折叠功能
        container.querySelectorAll('.config-group-title').forEach(title => {
            title.addEventListener('click', function() {
                const group = this.closest('.config-group');
                group.classList.toggle('collapsed');
            });
        });
        
        // 多语言编辑按钮
        container.querySelectorAll('.btn-i18n-edit').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                e.stopPropagation();
                const fieldKey = this.dataset.field;
                const layoutId = this.dataset.layoutId;
                const panel = document.getElementById(`i18n_panel_${layoutId}_${fieldKey}`);
                
                if (panel.style.display === 'none') {
                    // 显示面板并加载数据
                    panel.style.display = 'block';
                    this.classList.add('active');
                    await loadI18nValues(layoutId, fieldKey, panel);
                } else {
                    panel.style.display = 'none';
                    this.classList.remove('active');
                }
            });
        });
        
        // 多语言面板关闭按钮
        container.querySelectorAll('.btn-i18n-close').forEach(btn => {
            btn.addEventListener('click', function() {
                const panel = this.closest('.i18n-edit-panel');
                panel.style.display = 'none';
                const fieldKey = this.dataset.field;
                container.querySelector(`.btn-i18n-edit[data-field="${fieldKey}"]`)?.classList.remove('active');
            });
        });
        
        // 保存多语言按钮
        container.querySelectorAll('.btn-save-i18n').forEach(btn => {
            btn.addEventListener('click', async function() {
                const fieldKey = this.dataset.field;
                const layoutId = this.dataset.layoutId;
                const panel = this.closest('.i18n-edit-panel');
                await saveI18nValues(layoutId, fieldKey, panel);
            });
        });
        
        // 颜色选择器同步
        container.querySelectorAll('.color-picker-wrapper').forEach(wrapper => {
            const picker = wrapper.querySelector('.form-control-color');
            const text = wrapper.querySelector('.form-control');
            const transparentBtn = wrapper.querySelector('.btn-transparent');
            
            if (picker && text) {
                picker.addEventListener('input', () => {
                    text.value = picker.value;
                    transparentBtn?.classList.remove('active');
                });
                text.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) {
                        picker.value = text.value;
                        transparentBtn?.classList.remove('active');
                    } else if (text.value.toLowerCase() === 'transparent') {
                        transparentBtn?.classList.add('active');
                    }
                });
            }
            
            // 透明按钮
            if (transparentBtn) {
                transparentBtn.addEventListener('click', function() {
                    const targetId = this.dataset.target;
                    const input = document.getElementById(targetId);
                    if (input) {
                        if (input.value.toLowerCase() === 'transparent') {
                            input.value = '#000000';
                            this.classList.remove('active');
                        } else {
                            input.value = 'transparent';
                            this.classList.add('active');
                        }
                    }
                });
            }
            
            // 预设颜色按钮
            wrapper.querySelectorAll('.color-preset-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetId = this.dataset.target;
                    const color = this.dataset.color;
                    const input = document.getElementById(targetId);
                    const pickerEl = document.getElementById(targetId + '_picker');
                    if (input) {
                        input.value = color;
                        if (pickerEl && /^#[0-9A-Fa-f]{6}$/.test(color)) {
                            pickerEl.value = color;
                        }
                    }
                });
            });
        });
        
        // 范围滑块同步
        container.querySelectorAll('.range-slider-wrapper').forEach(wrapper => {
            const slider = wrapper.querySelector('.form-range');
            const input = wrapper.querySelector('.range-value-input');
            const label = wrapper.querySelector('.range-value-label');
            const hidden = wrapper.querySelector('input[type="hidden"]');
            
            if (slider && (input || label || hidden)) {
                slider.addEventListener('input', function() {
                    if (input) input.value = this.value;
                    if (label) label.textContent = this.value;
                    if (hidden) hidden.value = this.value;
                });
                
                if (input) {
                    input.addEventListener('input', function() {
                        slider.value = this.value;
                    });
                }
            }
        });
        
        // 图标选择器
        container.querySelectorAll('.icon-picker-wrapper').forEach(wrapper => {
            const pickerBtn = wrapper.querySelector('.btn-icon-picker');
            const clearBtn = wrapper.querySelector('.btn-icon-clear');
            const panel = wrapper.querySelector('.icon-picker-panel');
            const preview = wrapper.querySelector('.icon-preview-display');
            const hidden = wrapper.querySelector('input[type="hidden"]');
            
            if (pickerBtn && panel) {
                pickerBtn.addEventListener('click', function() {
                    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
                });
            }
            
            if (clearBtn && hidden && preview) {
                clearBtn.addEventListener('click', function() {
                    hidden.value = '';
                    preview.innerHTML = '<i class="ri-add-line placeholder-icon"></i>';
                    this.style.display = 'none';
                });
            }
            
            // 图标项点击
            wrapper.querySelectorAll('.icon-picker-item').forEach(item => {
                item.addEventListener('click', function() {
                    const icon = this.dataset.icon;
                    if (hidden) hidden.value = icon;
                    if (preview) preview.innerHTML = `<i class="${icon}"></i>`;
                    if (panel) panel.style.display = 'none';
                    if (clearBtn) clearBtn.style.display = 'inline-block';
                    
                    // 更新选中状态
                    wrapper.querySelectorAll('.icon-picker-item').forEach(i => i.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            // 搜索过滤
            const searchInput = panel?.querySelector('.icon-picker-search input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const keyword = this.value.toLowerCase();
                    wrapper.querySelectorAll('.icon-picker-item').forEach(item => {
                        const iconName = item.dataset.icon?.toLowerCase() || '';
                        item.style.display = iconName.includes(keyword) ? '' : 'none';
                    });
                });
            }
            
            // 自定义图标输入
            const customInput = panel?.querySelector('.icon-picker-custom input');
            const applyBtn = panel?.querySelector('.btn-apply-custom');
            if (customInput && applyBtn) {
                applyBtn.addEventListener('click', function() {
                    const icon = customInput.value.trim();
                    if (icon) {
                        if (hidden) hidden.value = icon;
                        if (preview) preview.innerHTML = `<i class="${icon}"></i>`;
                        if (panel) panel.style.display = 'none';
                        if (clearBtn) clearBtn.style.display = 'inline-block';
                    }
                });
            }
        });
        
        // 图片选择器
        container.querySelectorAll('.image-picker-wrapper').forEach(wrapper => {
            const urlInput = wrapper.querySelector('.image-url-input input');
            const preview = wrapper.querySelector('.image-preview');
            const clearBtn = wrapper.querySelector('.btn-clear-image');
            const uploadBtn = wrapper.querySelector('.btn-upload-image');
            const fileInput = wrapper.querySelector('.image-file-input');
            
            // URL 输入变化时更新预览
            if (urlInput && preview) {
                urlInput.addEventListener('input', function() {
                    const url = this.value.trim();
                    if (url) {
                        preview.innerHTML = `<img src="${url}" alt="预览" onerror="this.parentElement.innerHTML='<div class=\\'image-placeholder\\'><i class=\\'ri-image-add-line\\'></i><span>图片加载失败</span></div>'">`;
                        preview.closest('.image-preview-container')?.classList.add('has-image');
                    } else {
                        preview.innerHTML = '<div class="image-placeholder"><i class="ri-image-add-line"></i><span>点击选择图片</span></div>';
                        preview.closest('.image-preview-container')?.classList.remove('has-image');
                    }
                });
            }
            
            // 清除按钮
            if (clearBtn && urlInput && preview) {
                clearBtn.addEventListener('click', function() {
                    urlInput.value = '';
                    preview.innerHTML = '<div class="image-placeholder"><i class="ri-image-add-line"></i><span>点击选择图片</span></div>';
                    preview.closest('.image-preview-container')?.classList.remove('has-image');
                });
            }
            
            // 上传按钮
            if (uploadBtn && fileInput) {
                uploadBtn.addEventListener('click', () => fileInput.click());
                fileInput.addEventListener('change', function() {
                    const file = this.files?.[0];
                    if (file) {
                        // TODO: 实现文件上传逻辑
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            if (preview) {
                                preview.innerHTML = `<img src="${e.target.result}" alt="预览">`;
                                preview.closest('.image-preview-container')?.classList.add('has-image');
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
        });
        
        // 数组编辑器
        container.querySelectorAll('.array-editor-wrapper').forEach(wrapper => {
            const addBtn = wrapper.querySelector('.btn-add-array-item');
            const itemsContainer = wrapper.querySelector('.array-items-container');
            const hiddenInput = wrapper.querySelector('input[type="hidden"]');
            const template = wrapper.querySelector('template');
            
            // 更新隐藏字段值
            const updateHiddenValue = () => {
                if (!hiddenInput || !itemsContainer) return;
                const items = [];
                itemsContainer.querySelectorAll('.array-item').forEach(item => {
                    const simpleInput = item.querySelector('.array-item-input');
                    if (simpleInput) {
                        items.push(simpleInput.value);
                    } else {
                        const obj = {};
                        item.querySelectorAll('[data-field]').forEach(field => {
                            const key = field.dataset.field;
                            if (field.type === 'checkbox') {
                                obj[key] = field.checked;
                            } else {
                                obj[key] = field.value;
                            }
                        });
                        items.push(obj);
                    }
                });
                hiddenInput.value = JSON.stringify(items);
            };
            
            // 添加项目
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    const maxItems = parseInt(wrapper.dataset.maxItems) || Infinity;
                    const currentCount = itemsContainer?.querySelectorAll('.array-item').length || 0;
                    
                    if (currentCount >= maxItems) {
                        showToast('已达到最大项目数', 'warning');
                        return;
                    }
                    
                    // 移除空状态
                    itemsContainer?.querySelector('.array-empty-state')?.remove();
                    
                    // 添加新项
                    if (template) {
                        const html = template.innerHTML.replace(/__INDEX__/g, Date.now().toString());
                        itemsContainer?.insertAdjacentHTML('beforeend', html);
                    } else {
                        const index = currentCount;
                        const itemHtml = `
                            <div class="array-item" data-index="${index}">
                                <div class="array-item-handle"><i class="ri-draggable"></i></div>
                                <div class="array-item-content">
                                    <input type="text" class="form-control array-item-input" value="">
                                </div>
                                <div class="array-item-actions">
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-array-item">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                        itemsContainer?.insertAdjacentHTML('beforeend', itemHtml);
                    }
                    
                    // 绑定新项的事件
                    const newItem = itemsContainer?.querySelector('.array-item:last-child');
                    if (newItem) {
                        bindArrayItemEvents(newItem, updateHiddenValue);
                    }
                    
                    updateHiddenValue();
                });
            }
            
            // 绑定现有项的事件
            itemsContainer?.querySelectorAll('.array-item').forEach(item => {
                bindArrayItemEvents(item, updateHiddenValue);
            });
        });
        
        // 日期时间快捷按钮
        container.querySelectorAll('.datetime-shortcuts button').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                const targetId = this.dataset.target;
                const input = document.getElementById(targetId);
                if (!input) return;
                
                const now = new Date();
                let newDate;
                
                switch (action) {
                    case 'today':
                        newDate = now;
                        break;
                    case 'tomorrow':
                        newDate = new Date(now.getTime() + 24 * 60 * 60 * 1000);
                        break;
                    case 'next_week':
                        newDate = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
                        break;
                }
                
                if (newDate) {
                    if (input.type === 'date') {
                        input.value = newDate.toISOString().split('T')[0];
                    } else if (input.type === 'datetime-local') {
                        input.value = newDate.toISOString().slice(0, 16);
                    }
                }
            });
        });
        
        // 清除日期时间按钮
        container.querySelectorAll('.btn-clear-datetime').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const input = document.getElementById(targetId);
                if (input) input.value = '';
            });
        });
        
        // URL 测试按钮
        container.querySelectorAll('.btn-test-url').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const input = document.getElementById(targetId);
                if (input?.value) {
                    window.open(input.value, '_blank');
                }
            });
        });
        
        // URL 快捷链接
        container.querySelectorAll('.url-suggestion-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const url = this.dataset.url;
                const input = document.getElementById(targetId);
                if (input) input.value = url;
            });
        });
        
        // 多行文本自动调整高度
        container.querySelectorAll('textarea.auto-resize').forEach(textarea => {
            const adjustHeight = () => {
                textarea.style.height = 'auto';
                textarea.style.height = textarea.scrollHeight + 'px';
            };
            textarea.addEventListener('input', adjustHeight);
            adjustHeight();
        });
        
        // 文本域字符计数
        container.querySelectorAll('.textarea-counter').forEach(counter => {
            const wrapper = counter.closest('.textarea-wrapper');
            const textarea = wrapper?.querySelector('textarea');
            const currentCount = counter.querySelector('.current-count');
            
            if (textarea && currentCount) {
                textarea.addEventListener('input', function() {
                    currentCount.textContent = this.value.length;
                });
            }
        });
        
        // 保存按钮
        container.querySelectorAll('.widget-accordion-config-form').forEach(form => {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const layoutId = this.dataset.layoutId;
                const formData = new FormData(this);
                const configData = {};
                
                formData.forEach((value, key) => {
                    configData[key] = value;
                });
                
                // 处理复选框
                this.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    configData[checkbox.name] = checkbox.checked;
                });
                
                try {
                    const response = await fetch(config.apiUpdateConfig, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ layout_id: layoutId, config: configData })
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showToast('配置已保存', 'success');
                        // 更新预览
                        if (result.preview_html) {
                            updateWidgetPreviewInIframe(layoutId, result.preview_html);
                        }
                    } else {
                        showToast(result.message || '保存失败', 'error');
                    }
                } catch (err) {
                    showToast('保存失败', 'error');
                }
            });
        });
        
        // 删除按钮
        container.querySelectorAll('.btn-delete-widget').forEach(btn => {
            btn.addEventListener('click', async function() {
                const layoutId = this.dataset.layoutId;
                
                const confirmed = await showCustomConfirm(
                    '确认删除部件？',
                    '确定要删除这个部件吗？',
                    '确认删除',
                    '取消'
                );
                if (!confirmed) return;
                
                try {
                    const response = await fetch(config.apiDeleteWidget, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ layout_id: layoutId, theme_id: state.themeId })
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showToast('部件已删除', 'success');
                        
                        // 恢复iframe预览区的原始内容
                        const iframe = elements.previewFrame;
                        if (iframe && iframe.contentDocument) {
                            const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                            if (widgetEl) {
                                const slot = widgetEl.closest('[data-wslot], [data-slot]');
                                const actualSlotId = slot?.getAttribute('data-wslot') || slot?.getAttribute('data-slot');
                                
                                // 移除部件元素
                                widgetEl.remove();
                                
                                // 恢复原始内容
                                if (slot && !slot.querySelector('[data-layout-id]')) {
                                    if (result.has_original && result.original_html) {
                                        // 有原始内容，恢复模板默认的内容
                                        slot.innerHTML = result.original_html;
                                        // 重新初始化恢复的部件的hover操作
                                        initWidgetHoverActions();
                                    } else {
                                        // 没有原始内容，显示占位符
                                        const slotName = slot.getAttribute('data-wslot-name') || slot.getAttribute('data-name') || actualSlotId;
                                        slot.innerHTML = `
                                            <div class="slot-placeholder" style="
                                                padding: 40px 20px;
                                                text-align: center;
                                                color: #999;
                                                border: 2px dashed #ddd;
                                                border-radius: 8px;
                                                background: rgba(0,0,0,0.02);
                                            ">
                                                <i class="ri-inbox-line" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                                <p style="margin: 0; font-size: 14px;">插槽原本为空</p>
                                                <p style="margin: 5px 0 0 0; font-size: 12px; opacity: 0.7;">拖入部件或点击选择新部件</p>
                                            </div>
                                        `;
                                    }
                                }
                            }
                        }
                        
                        // 从配置面板手风琴移除
                        const accordionItem = this.closest('.slot-widget-accordion-item');
                        accordionItem?.remove();
                        
                        // 从结构视图移除
                        document.querySelector(`.preview-widget-item[data-layout-id="${layoutId}"]`)?.remove();
                        
                        // 关闭配置面板
                        elements.configPanel.classList.remove('show');
                    } else {
                        showToast(result.message || '删除失败', 'error');
                    }
                } catch (err) {
                    console.error('Delete widget error:', err);
                    showToast('删除失败', 'error');
                }
            });
        });
    }
    
    /**
     * 绑定数组项事件
     */
    function bindArrayItemEvents(item, updateCallback) {
        // 删除按钮
        const removeBtn = item.querySelector('.btn-remove-array-item');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                const wrapper = item.closest('.array-editor-wrapper');
                const minItems = parseInt(wrapper?.dataset.minItems) || 0;
                const itemsContainer = wrapper?.querySelector('.array-items-container');
                const currentCount = itemsContainer?.querySelectorAll('.array-item').length || 0;
                
                if (currentCount <= minItems) {
                    showToast(`至少需要 ${minItems} 个项目`, 'warning');
                    return;
                }
                
                item.remove();
                
                // 如果没有项目了，显示空状态
                if (currentCount - 1 === 0 && itemsContainer) {
                    itemsContainer.innerHTML = '<div class="array-empty-state"><i class="ri-list-check-2"></i><p>暂无项目</p></div>';
                }
                
                updateCallback?.();
            });
        }
        
        // 输入变化
        item.querySelectorAll('input, select, textarea').forEach(input => {
            input.addEventListener('input', updateCallback);
            input.addEventListener('change', updateCallback);
        });
        
        // 拖拽排序（简单实现）
        const handle = item.querySelector('.array-item-handle');
        if (handle) {
            handle.style.cursor = 'grab';
            handle.addEventListener('mousedown', function(e) {
                e.preventDefault();
                const itemsContainer = item.closest('.array-items-container');
                if (!itemsContainer) return;
                
                const items = Array.from(itemsContainer.querySelectorAll('.array-item'));
                const startIndex = items.indexOf(item);
                let currentIndex = startIndex;
                
                const onMouseMove = (e) => {
                    // 简单的拖拽指示
                    item.style.opacity = '0.5';
                };
                
                const onMouseUp = () => {
                    item.style.opacity = '';
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    updateCallback?.();
                };
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        }
    }
    
    /**
     * 渲染插槽空状态
     */
    function renderSlotEmptyState(slot) {
        const slotName = slot.name || slot.id || '未命名插槽';
        const slotId = slot.id || '';
        const acceptCodes = slot.accept || [];
        
        // 生成接受的部件列表 HTML
        let acceptHtml = '';
        if (acceptCodes.length === 0 || acceptCodes.includes('*')) {
            acceptHtml = '<span class="badge bg-success">接受所有部件</span>';
        } else {
            acceptHtml = acceptCodes.map(code => 
                `<span class="badge bg-primary me-1 mb-1">${code}</span>`
            ).join('');
        }
        
        const html = `
            <div class="slot-empty-panel">
                <div class="slot-empty-header">
                    <div class="slot-icon">
                        <i class="ri-layout-grid-line"></i>
                    </div>
                    <div class="slot-title">
                        <h5>${slotName}</h5>
                        <span class="slot-id text-muted">ID: ${slotId}</span>
                    </div>
                </div>
                
                <div class="slot-empty-state">
                    <div class="empty-icon">
                        <i class="ri-inbox-2-line"></i>
                    </div>
                    <h6>该插槽暂无部件</h6>
                    <p class="text-muted">此区域目前显示的是原生 HTML 内容</p>
                </div>
                
                <div class="slot-accept-info">
                    <label><i class="ri-checkbox-circle-line"></i> 可接受的部件：</label>
                    <div class="slot-accept-list">
                        ${acceptHtml}
                    </div>
                </div>
                
                <div class="slot-action-hint">
                    <div class="action-hint-box">
                        <i class="ri-drag-drop-line"></i>
                        <p><strong>从右侧部件库拖拽部件</strong></p>
                        <small>部件将替换当前的原生 HTML 内容</small>
                    </div>
                </div>
            </div>
        `;
        
        elements.configContent.innerHTML = html;
    }

    /**
     * 处理部件拖放到插槽
     */
    async function handleWidgetDropped(widget, slot) {
        console.log('部件拖放到插槽:', { widget, slot });
        
        const area = slot.position || slot.id;
        const slotId = slot.id;
        
        // 保存部件到该插槽
        try {
            const response = await fetch(config.apiSaveWidget, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    area: area,
                    slot_id: slotId,
                    widget_module: widget.module,
                    widget_code: widget.code,
                    widget_type: widget.type || '',
                    page_type: state.pageType,
                    config: {},
                    sort_order: 0
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast(`${widget.name || widget.code} 已添加到 ${slot.name}`, 'success');
                
                // 更新结构视图：添加部件到结构面板
                const layoutId = result.data?.layout_id;
                if (layoutId) {
                    addWidgetToStructureView(area, slotId, widget, layoutId, false);
                }
                
                // 使用返回的 preview_html 更新预览（如果有）
                // 新添加的部件传入 isNewWidget=true，以便触发重试或完整刷新
                if (result.preview_html && layoutId) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html, true);
                } else {
                    // 没有返回 preview_html，直接刷新整个预览
                    loadLayoutPreview();
                }
            } else {
                showToast(result.message || '添加失败', 'error');
            }
        } catch (err) {
            console.error('保存部件失败:', err);
            showToast('保存失败', 'error');
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
        const accept = data.accept ? data.accept.split(',').map(s => s.trim()) : [];
        
        // 保存当前选中的插槽
        state.selectedSlot = { id: slot, accept: accept, name: slot };
        
        // 高亮右侧部件列表中可放入该插槽的部件并排序
        highlightAcceptableWidgets(accept);
        
        // 滚动到高亮的部件
        scrollToHighlightedWidgets();
        
        showToast(`插槽 "${slot}" 可接受: ${accept.join(', ')}`, 'info');
    }

    /**
     * 高亮可接受的部件并排序
     */
    function highlightAcceptableWidgets(acceptCodes) {
        if (!acceptCodes || acceptCodes.length === 0) {
            // 如果没有接受代码，恢复原始顺序并移除高亮
            restoreWidgetOrder();
            return;
        }

        // 保存原始顺序（如果还没有保存）
        if (state.originalWidgetOrder.size === 0) {
            saveWidgetOrder();
        }

        // 移除之前的高亮
        document.querySelectorAll('.widget-item.highlighted').forEach(el => {
            el.classList.remove('highlighted');
        });

        // 获取所有分组并分类：包含匹配部件的分组和不包含的
        const widgetList = elements.widgetList;
        if (!widgetList) return;

        const allGroups = Array.from(widgetList.querySelectorAll('.widget-group'));
        const groupsWithMatches = [];
        const groupsWithoutMatches = [];

        // 先遍历所有分组，找出哪些包含匹配的部件
        allGroups.forEach(group => {
            const groupContent = group.querySelector('.widget-group-content');
            if (!groupContent) {
                groupsWithoutMatches.push(group);
                return;
            }

            const allWidgets = Array.from(groupContent.querySelectorAll('.widget-item'));
            let hasMatch = false;

            // 检查是否有匹配的部件
            allWidgets.forEach(widget => {
                const widgetCode = widget.getAttribute('data-widget-code');
                const widgetSlot = widget.getAttribute('data-widget-slot') || '';
                
                const isMatch = acceptCodes.some(acceptCode => {
                    const trimmedAccept = acceptCode.trim().toLowerCase();
                    const codeLower = (widgetCode || '').toLowerCase();
                    const slotLower = (widgetSlot || '').toLowerCase();
                    
                    if (codeLower === trimmedAccept) return true;
                    if (slotLower && slotLower === trimmedAccept) return true;
                    if (codeLower && codeLower.includes(trimmedAccept) || trimmedAccept.includes(codeLower)) return true;
                    return false;
                });

                if (isMatch) {
                    hasMatch = true;
                }
            });

            if (hasMatch) {
                groupsWithMatches.push(group);
            } else {
                groupsWithoutMatches.push(group);
            }
        });

        // 重新排序分组：包含匹配部件的分组排到前面
        if (groupsWithMatches.length > 0) {
            // 先移除所有分组
            allGroups.forEach(group => group.remove());
            
            // 先添加包含匹配部件的分组
            groupsWithMatches.forEach(group => widgetList.appendChild(group));
            
            // 再添加不包含匹配部件的分组
            groupsWithoutMatches.forEach(group => widgetList.appendChild(group));
        }

        // 遍历所有部件组，排序部件（重新获取分组，因为DOM已改变）
        widgetList.querySelectorAll('.widget-group').forEach(group => {
            const groupContent = group.querySelector('.widget-group-content');
            if (!groupContent) return;

            const allWidgets = Array.from(groupContent.querySelectorAll('.widget-item'));
            const matchingWidgets = [];
            const nonMatchingWidgets = [];

            // 分类部件：匹配的和不匹配的
            allWidgets.forEach(widget => {
                const widgetCode = widget.getAttribute('data-widget-code');
                const widgetSlot = widget.getAttribute('data-widget-slot') || '';
                
                // 检查是否匹配：精确匹配 widget code 或 slot 匹配
                const isMatch = acceptCodes.some(acceptCode => {
                    const trimmedAccept = acceptCode.trim().toLowerCase();
                    const codeLower = (widgetCode || '').toLowerCase();
                    const slotLower = (widgetSlot || '').toLowerCase();
                    
                    // 精确匹配 widget code
                    if (codeLower === trimmedAccept) {
                        return true;
                    }
                    // 匹配 slot 属性
                    if (slotLower && slotLower === trimmedAccept) {
                        return true;
                    }
                    // 部分匹配（code 包含 accept 或 accept 包含 code）
                    if (codeLower && codeLower.includes(trimmedAccept) || trimmedAccept.includes(codeLower)) {
                        return true;
                    }
                    return false;
                });

                if (isMatch) {
                    matchingWidgets.push(widget);
                    widget.classList.add('highlighted');
                } else {
                    nonMatchingWidgets.push(widget);
                }
            });

            // 重新排序：匹配的部件排到前面
            if (matchingWidgets.length > 0) {
                // 先移除所有部件
                allWidgets.forEach(widget => widget.remove());
                
                // 先添加匹配的部件
                matchingWidgets.forEach(widget => groupContent.appendChild(widget));
                
                // 再添加不匹配的部件
                nonMatchingWidgets.forEach(widget => groupContent.appendChild(widget));
            }
        });

        // 自动展开包含匹配部件的组
        document.querySelectorAll('.widget-group').forEach(group => {
            const hasHighlighted = group.querySelector('.widget-item.highlighted');
            if (hasHighlighted && group.classList.contains('collapsed')) {
                group.classList.remove('collapsed');
            }
        });
    }

    /**
     * 滚动部件面板到高亮的部件位置
     * 用于点击插槽后自动定位到匹配的部件
     */
    function scrollToHighlightedWidgets() {
        setTimeout(() => {
            const widgetPanelContent = document.querySelector('#widgetPanel .panel-content');
            if (!widgetPanelContent) return;
            
            // 找到第一个高亮的部件
            const firstHighlighted = widgetPanelContent.querySelector('.widget-item.highlighted');
            
            if (firstHighlighted) {
                // 获取部件所在的组
                const widgetGroup = firstHighlighted.closest('.widget-group');
                
                if (widgetGroup) {
                    // 计算滚动位置（组的顶部位置 - 一些间距）
                    const groupTop = widgetGroup.offsetTop;
                    const scrollOffset = Math.max(0, groupTop - 20);
                    
                    widgetPanelContent.scrollTo({ 
                        top: scrollOffset, 
                        behavior: 'smooth' 
                    });
                    
                    console.log('[scrollToHighlightedWidgets] Scrolled to highlighted widget in group:', widgetGroup.dataset.type);
                }
            } else {
                // 如果没有找到高亮的部件，滚动到顶部
                widgetPanelContent.scrollTo({ top: 0, behavior: 'smooth' });
                console.log('[scrollToHighlightedWidgets] No highlighted widget found, scrolled to top');
            }
        }, 150); // 延迟150ms等待高亮和排序完成
    }

    /**
     * 保存原始部件顺序和分组顺序
     */
    function saveWidgetOrder() {
        state.originalWidgetOrder.clear();
        state.originalGroupOrder = [];
        
        const widgetList = elements.widgetList;
        if (!widgetList) return;

        // 保存分组顺序
        const allGroups = Array.from(widgetList.querySelectorAll('.widget-group'));
        state.originalGroupOrder = allGroups.map(group => {
            const groupType = group.getAttribute('data-type') || '';
            return groupType;
        });

        // 保存每个组内的部件顺序
        allGroups.forEach((group, groupIndex) => {
            const groupContent = group.querySelector('.widget-group-content');
            if (!groupContent) return;
            
            const widgets = Array.from(groupContent.querySelectorAll('.widget-item'));
            const order = widgets.map(widget => widget.getAttribute('data-widget-code'));
            state.originalWidgetOrder.set(groupIndex, order);
        });
    }

    /**
     * 恢复原始部件顺序和分组顺序
     */
    function restoreWidgetOrder() {
        const widgetList = elements.widgetList;
        if (!widgetList) return;

        // 移除所有高亮
        document.querySelectorAll('.widget-item.highlighted').forEach(el => {
            el.classList.remove('highlighted');
        });

        // 恢复分组顺序
        if (state.originalGroupOrder.length > 0) {
            const allGroups = Array.from(widgetList.querySelectorAll('.widget-group'));
            const groupMap = new Map();
            
            // 创建分组映射（按 data-type）
            allGroups.forEach(group => {
                const groupType = group.getAttribute('data-type') || '';
                if (!groupMap.has(groupType)) {
                    groupMap.set(groupType, []);
                }
                groupMap.get(groupType).push(group);
            });

            // 清空列表
            allGroups.forEach(group => group.remove());

            // 按原始顺序重新添加分组
            state.originalGroupOrder.forEach(groupType => {
                const groups = groupMap.get(groupType);
                if (groups) {
                    groups.forEach(group => widgetList.appendChild(group));
                    // 从映射中移除已添加的分组
                    groupMap.delete(groupType);
                }
            });

            // 添加剩余的分组（如果有新增的）
            groupMap.forEach(groups => {
                groups.forEach(group => widgetList.appendChild(group));
            });
        }

        // 恢复每个组内的部件顺序
        if (state.originalWidgetOrder.size > 0) {
            const allGroups = Array.from(widgetList.querySelectorAll('.widget-group'));
            allGroups.forEach((group, groupIndex) => {
                const groupContent = group.querySelector('.widget-group-content');
                if (!groupContent) return;

                const originalOrder = state.originalWidgetOrder.get(groupIndex);
                if (!originalOrder) return;

                // 创建部件映射
                const widgetMap = new Map();
                Array.from(groupContent.querySelectorAll('.widget-item')).forEach(widget => {
                    const code = widget.getAttribute('data-widget-code');
                    widgetMap.set(code, widget);
                });

                // 清空组内容
                groupContent.innerHTML = '';

                // 按原始顺序重新添加
                originalOrder.forEach(code => {
                    const widget = widgetMap.get(code);
                    if (widget) {
                        groupContent.appendChild(widget);
                    }
                });
            });
        }

        // 清空保存的顺序
        state.originalWidgetOrder.clear();
        state.originalGroupOrder = [];
    }

    /**
     * 获取带页面类型参数的部件API URL
     */
    function getWidgetsApiUrl() {
        const url = new URL(config.apiWidgets, window.location.origin);
        if (state.pageType) {
            url.searchParams.set('page_type', state.pageType);
        }
        return url.toString();
    }

    /**
     * 为已保存的布局打开配置模态框
     */
    async function openConfigModalForLayout(layoutId, widgetCode, currentConfig) {
        // 获取部件参数定义（传递页面类型以获取适用的部件）
        try {
            const response = await fetch(getWidgetsApiUrl());
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
     * 
     * 支持的类型：
     * - string: 文本输入
     * - number: 数字输入（支持 min/max/step）
     * - bool/boolean: 复选框
     * - select: 下拉选择
     * - multiselect: 多选下拉
     * - url: URL 输入
     * - image/image_picker: 图片选择器
     * - file: 文件选择器
     * - color: 颜色选择器
     * - textarea: 多行文本
     * - rich_text: 富文本编辑器
     * - date: 日期选择器
     * - datetime: 日期时间选择器
     * - eav_select: EAV 属性选择器
     * - eav_options: EAV 属性选项选择器
     * - checkbox_group: 复选框组
     * - radio: 单选按钮组
     */
    function renderFormField(key, param, value) {
        const type = param.type || 'string';
        const label = param.label || key;
        const required = param.required || false;
        const description = param.description || '';
        const placeholder = param.placeholder || '';
        const options = param.options || {};

        let html = `<div class="form-group mb-3" data-field-type="${escapeHtml(type)}">`;
        html += `<label for="config_${key}" class="form-label">${escapeHtml(label)}`;
        if (required) html += ' <span class="text-danger">*</span>';
        html += `</label>`;

        if (type === 'string' || type === 'text') {
            html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
        } else if (type === 'number') {
            const min = param.min !== undefined ? `min="${param.min}"` : '';
            const max = param.max !== undefined ? `max="${param.max}"` : '';
            const step = param.step !== undefined ? `step="${param.step}"` : '';
            html += `<input type="number" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${min} ${max} ${step} ${required ? 'required' : ''}>`;
        } else if (type === 'bool' || type === 'boolean') {
            html += `<div class="form-check">
                <input type="checkbox" class="form-check-input" id="config_${key}" name="${key}" value="1" ${value ? 'checked' : ''}>
                <label class="form-check-label" for="config_${key}">${escapeHtml(param.checkbox_label || '启用')}</label>
            </div>`;
        } else if (type === 'select') {
            html += `<select class="form-select" id="config_${key}" name="${key}" ${required ? 'required' : ''}>
                <option value="">-- 请选择 --</option>`;
            for (const optVal in options) {
                html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
            }
            html += `</select>`;
        } else if (type === 'multiselect') {
            const selectedValues = Array.isArray(value) ? value : (value ? String(value).split(',') : []);
            html += `<select class="form-select" id="config_${key}" name="${key}[]" multiple ${required ? 'required' : ''} style="min-height: 120px;">`;
            for (const optVal in options) {
                const isSelected = selectedValues.includes(String(optVal));
                html += `<option value="${escapeHtml(optVal)}" ${isSelected ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
            }
            html += `</select>`;
            html += `<small class="form-text text-muted">按住 Ctrl/Cmd 可多选</small>`;
        } else if (type === 'url') {
            html += `<input type="url" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>`;
        } else if (type === 'image' || type === 'image_picker') {
            html += `<div class="input-group">
                <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="图片URL">
                <button type="button" class="btn btn-outline-secondary btn-select-image" data-target="config_${key}">
                    <i class="ri-image-line"></i> 选择
                </button>
            </div>`;
            if (value) {
                html += `<div class="mt-2 image-preview-container">
                    <img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 100px;">
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="document.getElementById('config_${key}').value='';this.parentElement.remove();">
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </div>`;
            }
        } else if (type === 'file') {
            html += `<div class="input-group">
                <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="文件路径">
                <button type="button" class="btn btn-outline-secondary btn-select-file" data-target="config_${key}" data-accept="${escapeHtml(param.accept || '*')}">
                    <i class="ri-folder-open-line"></i> 浏览
                </button>
            </div>`;
        } else if (type === 'color') {
            html += `<div class="input-group">
                <input type="color" class="form-control form-control-color" id="config_${key}_picker" value="${escapeHtml(value || '#000000')}" style="width: 50px;">
                <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="#000000">
            </div>`;
        } else if (type === 'textarea') {
            html += `<textarea class="form-control" id="config_${key}" name="${key}" rows="${param.rows || 4}" 
                     placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
        } else if (type === 'rich_text') {
            html += `<textarea class="form-control rich-text-editor" id="config_${key}" name="${key}" rows="${param.rows || 6}" 
                     placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
            html += `<small class="form-text text-muted">支持 HTML 格式</small>`;
        } else if (type === 'date') {
            html += `<input type="date" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
        } else if (type === 'datetime') {
            html += `<input type="datetime-local" class="form-control" id="config_${key}" name="${key}" 
                     value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
        } else if (type === 'eav_select') {
            // EAV 属性选择器
            const entityCode = param.entity_code || 'product';
            html += `<select class="form-select eav-attribute-select" id="config_${key}" name="${key}" 
                     data-entity-code="${escapeHtml(entityCode)}" ${required ? 'required' : ''}>
                <option value="">-- 加载中... --</option>
            </select>`;
            // 延迟加载 EAV 属性
            html += `<script>
                (function() {
                    const select = document.getElementById('config_${key}');
                    if (!select) return;
                    fetch('/weline/eav/api/options/attributes?entity_code=${escapeHtml(entityCode)}')
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.data.attributes) {
                                select.innerHTML = '<option value="">-- 请选择属性 --</option>';
                                data.data.attributes.forEach(attr => {
                                    const opt = document.createElement('option');
                                    opt.value = attr.code;
                                    opt.textContent = attr.name + ' (' + attr.code + ')';
                                    if ('${escapeHtml(value)}' === attr.code) opt.selected = true;
                                    select.appendChild(opt);
                                });
                            }
                        })
                        .catch(err => {
                            select.innerHTML = '<option value="">加载失败</option>';
                        });
                })();
            </script>`;
        } else if (type === 'eav_options') {
            // EAV 属性选项选择器
            const entityCode = param.entity_code || 'product';
            const attributeCode = param.attribute_code || '';
            const multiple = param.multiple || false;
            html += `<select class="form-select eav-options-select" id="config_${key}" name="${key}${multiple ? '[]' : ''}" 
                     data-entity-code="${escapeHtml(entityCode)}" data-attribute-code="${escapeHtml(attributeCode)}" 
                     ${multiple ? 'multiple style="min-height: 120px;"' : ''} ${required ? 'required' : ''}>
                <option value="">-- 加载中... --</option>
            </select>`;
            if (attributeCode) {
                html += `<script>
                    (function() {
                        const select = document.getElementById('config_${key}');
                        if (!select) return;
                        fetch('/weline/eav/api/options?entity_code=${escapeHtml(entityCode)}&attribute_code=${escapeHtml(attributeCode)}')
                            .then(r => r.json())
                            .then(data => {
                                if (data.success && data.data.options) {
                                    select.innerHTML = '<option value="">-- 请选择 --</option>';
                                    const currentValues = ${multiple ? JSON.stringify(Array.isArray(value) ? value : (value ? String(value).split(',') : [])) : `['${escapeHtml(value)}']`};
                                    data.data.options.forEach(opt => {
                                        const option = document.createElement('option');
                                        option.value = opt.id;
                                        option.textContent = opt.value;
                                        if (currentValues.includes(String(opt.id))) option.selected = true;
                                        select.appendChild(option);
                                    });
                                }
                            })
                            .catch(err => {
                                select.innerHTML = '<option value="">加载失败</option>';
                            });
                    })();
                </script>`;
            }
        } else if (type === 'checkbox_group') {
            html += `<div class="checkbox-group" id="config_${key}_group">`;
            const selectedValues = Array.isArray(value) ? value : (value ? String(value).split(',') : []);
            for (const optVal in options) {
                const isChecked = selectedValues.includes(String(optVal));
                html += `<div class="form-check">
                    <input type="checkbox" class="form-check-input" id="config_${key}_${escapeHtml(optVal)}" 
                           name="${key}[]" value="${escapeHtml(optVal)}" ${isChecked ? 'checked' : ''}>
                    <label class="form-check-label" for="config_${key}_${escapeHtml(optVal)}">${escapeHtml(options[optVal])}</label>
                </div>`;
            }
            html += `</div>`;
        } else if (type === 'radio') {
            html += `<div class="radio-group" id="config_${key}_group">`;
            for (const optVal in options) {
                const isChecked = String(value) === String(optVal);
                html += `<div class="form-check">
                    <input type="radio" class="form-check-input" id="config_${key}_${escapeHtml(optVal)}" 
                           name="${key}" value="${escapeHtml(optVal)}" ${isChecked ? 'checked' : ''}>
                    <label class="form-check-label" for="config_${key}_${escapeHtml(optVal)}">${escapeHtml(options[optVal])}</label>
                </div>`;
            }
            html += `</div>`;
        } else {
            // 默认为文本输入
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
                    await saveConfigFromModal(form, widgetMeta);
                });

                // T012: 移除实时预览 API 调用，只在保存时刷新预览
                // 注：不再绑定 change/input 事件触发 updateModalPreview

                // 绑定颜色选择器同步（仅同步UI，不触发预览请求）
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

            // T012: 使用静态预览提示代替实时 API 请求
            const previewBox = document.getElementById('modalWidgetPreview');
            if (previewBox) {
                previewBox.innerHTML = '<div class="preview-static-hint"><i class="ri-eye-line"></i> 保存后预览更新</div>';
            }
        }
    }

    /**
     * 更新模态框中的实时预览
     * 
     * @deprecated T012: 实时预览已移除，改为保存后刷新。
     *             此函数保留但不再被调用，预览通过 saveConfigFromModal 返回的 preview_html 更新。
     */
    async function updateModalPreview(form, widgetMeta) {
        // T012: 此函数已弃用，不再执行实时 API 调用
        // 保留函数签名以防止外部调用报错
        console.warn('[ThemeEditor] updateModalPreview is deprecated. Preview updates only on save.');
    }

    /**
     * 保存模态框配置
     * 
     * T012: 配置保存后使用返回的 preview_html 更新预览，不再使用实时 API
     */
    async function saveConfigFromModal(form, widgetMeta) {
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
                
                // T012: 使用返回的 preview_html 更新预览，不再触发 layout-preview 请求
                if (result.preview_html) {
                    const previewBox = document.getElementById('modalWidgetPreview');
                    if (previewBox) {
                        previewBox.innerHTML = result.preview_html;
                    }
                    
                    // 更新 iframe 中对应部件的预览（如果存在）
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                }
                
                // 关闭模态框
                const modal = bootstrap.Modal.getInstance(document.getElementById('widgetConfigModal'));
                if (modal) modal.hide();
                
                // 注意：不再调用 refreshPreview()，已通过 preview_html 更新
            } else {
                // T015: 保存失败时保持当前预览，仅显示错误提示
                showToast(result.message || '保存失败', 'error');
                // 不更新预览，保持上一次的状态
            }
        } catch (err) {
            // T015: 网络错误时保持当前预览，继续编辑
            showToast('保存失败，请检查网络连接', 'error');
            // 不更新预览，不关闭模态框，允许用户重试
        }
    }

    /**
     * 更新 iframe 中指定部件的预览 HTML
     * 
     * T012: 使用服务端返回的 preview_html 替换 iframe 中对应部件
     * 改进: 添加重试逻辑和 fallback 到完整刷新
     * 
     * @param {string|number} layoutId 部件布局ID
     * @param {string} previewHtml 预览HTML内容
     * @param {boolean} isNewWidget 是否为新添加的部件（默认false）
     */
    function updateWidgetPreviewInIframe(layoutId, previewHtml, isNewWidget = false) {
        const iframe = elements.previewFrame;
        if (!iframe) {
            console.warn('[ThemeEditor] iframe not found, triggering full refresh');
            loadLayoutPreview();
            return;
        }

        // 尝试更新部件，带有重试逻辑
        const maxRetries = 3;
        const retryDelay = 100; // ms
        let retryCount = 0;

        function tryUpdate() {
            try {
                // 检查 iframe 是否可访问
                if (!iframe.contentDocument || !iframe.contentDocument.body) {
                    retryCount++;
                    if (retryCount < maxRetries) {
                        console.log(`[ThemeEditor] iframe not ready, retry ${retryCount}/${maxRetries}`);
                        setTimeout(tryUpdate, retryDelay);
                        return;
                    }
                    // 重试超限，使用完整刷新
                    console.warn('[ThemeEditor] iframe not accessible after retries, triggering full refresh');
                    loadLayoutPreview();
                    return;
                }

                const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                
                if (widgetEl) {
                    // 找到部件，更新其内容
                    const contentEl = widgetEl.querySelector('.widget-content') || widgetEl;
                    contentEl.innerHTML = previewHtml;
                    console.log(`[ThemeEditor] Widget ${layoutId} preview updated successfully`);
                    
                    // 高亮更新的部件（短暂视觉反馈）
                    widgetEl.classList.add('widget-updated');
                    setTimeout(() => widgetEl.classList.remove('widget-updated'), 1000);
                } else if (isNewWidget) {
                    // 新部件 - 尝试在对应插槽中插入
                    const slotId = state.selectedSlot?.id || state.draggingWidget?.slot;
                    let slotEl = null;
                    
                    if (slotId) {
                        // 尝试多种方式查找插槽
                        slotEl = iframe.contentDocument.querySelector(`[data-wslot="${slotId}"]`) ||
                                 iframe.contentDocument.querySelector(`[data-slot="${slotId}"]`) ||
                                 iframe.contentDocument.querySelector(`.slot-${slotId}`) ||
                                 iframe.contentDocument.querySelector(`#slot-${slotId}`);
                    }
                    
                    if (slotEl) {
                        // 确保 iframe 中有样式
                        injectStylesIntoIframe();
                        
                        // 找到插槽，插入新部件
                        const wrapper = iframe.contentDocument.createElement('div');
                        wrapper.setAttribute('data-layout-id', layoutId);
                        wrapper.setAttribute('data-slot-id', slotId);
                        wrapper.classList.add('widget-wrapper', 'widget-new');
                        wrapper.style.position = 'relative';
                        
                        // 判断是否独占
                        const isExclusive = isExclusiveSlot(slotId, state.draggingWidget?.code || '');
                        
                        // 生成操作按钮
                        const actionsHtml = generateWidgetHoverActionsHtml(layoutId, slotId, isExclusive, true, true);
                        
                        // 组装部件内容
                        wrapper.innerHTML = actionsHtml + '<div class="widget-content">' + previewHtml + '</div>';
                        
                        // 清除插槽中的原有内容（独占模式）
                        slotEl.innerHTML = '';
                        slotEl.appendChild(wrapper);
                        
                        // 绑定按钮事件（如果还没有绑定）
                        if (!iframe.contentDocument.body._widgetActionsInitialized) {
                            bindWidgetActionEvents(iframe.contentDocument);
                            iframe.contentDocument.body._widgetActionsInitialized = true;
                        }
                        
                        console.log(`[ThemeEditor] New widget ${layoutId} inserted into slot ${slotId} with hover actions`);
                        
                        // 高亮新部件
                        setTimeout(() => wrapper.classList.remove('widget-new'), 1500);
                    } else {
                        // 找不到插槽，重试或刷新
                        retryCount++;
                        if (retryCount < maxRetries) {
                            console.log(`[ThemeEditor] Slot for widget ${layoutId} not found, retry ${retryCount}/${maxRetries}`);
                            setTimeout(tryUpdate, retryDelay * retryCount);
                            return;
                        }
                        console.log(`[ThemeEditor] New widget ${layoutId} slot not found, triggering layout refresh`);
                        loadLayoutPreview();
                    }
                } else {
                    // 非新部件但找不到 - 可能是slot结构问题，刷新预览
                    console.warn(`[ThemeEditor] Existing widget ${layoutId} not found, triggering full refresh`);
                    loadLayoutPreview();
                }
            } catch (err) {
                // iframe 跨域或其他错误
                console.warn('[ThemeEditor] Error updating iframe:', err.message);
                
                retryCount++;
                if (retryCount < maxRetries) {
                    setTimeout(tryUpdate, retryDelay);
                    return;
                }
                
                // 重试超限，触发完整刷新
                console.warn('[ThemeEditor] iframe update failed after retries, triggering full refresh');
                loadLayoutPreview();
            }
        }

        // 启动更新尝试
        tryUpdate();
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

        // 注意：不再在切换视图时自动刷新 iframe，避免重复请求
        // 用户可以手动点击刷新按钮来刷新预览
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

        // 放置区域 - 绑定到 preview-area 和 area-widgets
        document.querySelectorAll('.preview-area, .area-widgets').forEach(area => {
            area.addEventListener('dragover', handleDragOver);
            area.addEventListener('dragleave', handleDragLeave);
            area.addEventListener('drop', handleDrop);
        });

        // 容器内插槽 - 绑定拖放事件（支持新旧两种属性标记）
        // 旧版: .container-slot, data-slot
        // 新版: [data-wslot]
        document.querySelectorAll('.container-slot, .slot-widgets, [data-wslot]').forEach(slot => {
            slot.addEventListener('dragover', handleSlotDragOver);
            slot.addEventListener('dragleave', handleSlotDragLeave);
            slot.addEventListener('drop', handleSlotDrop);
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

        let pageLayouts = ['*'];
        try {
            pageLayouts = JSON.parse(this.dataset.widgetPageLayouts || '["*"]');
        } catch (err) {
            console.error('Invalid page_layouts data:', this.dataset.widgetPageLayouts);
            pageLayouts = ['*'];
        }

        const widgetData = {
            code: this.dataset.widgetCode,
            module: this.dataset.widgetModule,
            type: this.dataset.widgetType,
            name: this.dataset.widgetName,
            position: position,
            compatible: this.dataset.widgetCompatible === '1',
            // 插槽相关属性
            slot: this.dataset.widgetSlot || null,
            exclusive: this.dataset.widgetExclusive === '1' || this.dataset.widgetExclusive === 'true',
            // 布局和容器属性
            pageLayouts: pageLayouts,
            isContainer: this.dataset.widgetIsContainer === '1',
        };

        // 检查部件是否支持当前布局
        if (!isWidgetAllowedForLayout(pageLayouts, state.layoutType)) {
            showToast(`部件 "${widgetData.name}" 不支持当前布局 "${state.layoutType}"`, 'warning');
            e.preventDefault();
            return;
        }

        // 存储到 state 中，以便在 dragover 和 drop 时使用
        state.draggingWidget = widgetData;

        console.log('Drag start - widget:', widgetData.name, 'position:', widgetData.position, 'slot:', widgetData.slot, 'exclusive:', widgetData.exclusive, 'pageLayouts:', widgetData.pageLayouts);

        e.dataTransfer.setData('application/json', JSON.stringify(widgetData));
        e.dataTransfer.effectAllowed = 'copy';

        // 高亮可放置区域
        highlightAllowedAreas(widgetData.position);
    }

    /**
     * 检查部件是否支持指定的布局
     */
    function isWidgetAllowedForLayout(widgetPageLayouts, currentLayout) {
        // * 表示所有布局都可用
        if (widgetPageLayouts.includes('*')) {
            return true;
        }
        // 检查是否包含当前布局
        if (widgetPageLayouts.includes(currentLayout)) {
            return true;
        }
        // default 布局在所有页面都可用
        if (widgetPageLayouts.includes('default')) {
            return true;
        }
        return false;
    }

    /**
     * 拖拽结束
     */
    function handleDragEnd(e) {
        state.isDragging = false;
        state.draggingWidget = null; // 清理拖拽数据
        this.classList.remove('dragging');

        // 移除区域高亮
        document.querySelectorAll('.preview-area').forEach(area => {
            area.classList.remove('drag-over', 'drag-invalid', 'drag-allowed');
        });

        // 移除插槽高亮（支持新旧两种标记方式）
        document.querySelectorAll('.container-slot, [data-wslot]').forEach(slot => {
            slot.classList.remove('drag-over', 'drag-invalid', 'drag-allowed');
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
        
        if (widgetData) {
            // 检查是否允许放置（传入 position 和 type，让 isAllowedArea 推断）
            const allowed = isAllowedArea(widgetData.position, areaCode, widgetData.type);
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
            // 没有部件数据，不允许放置
            e.dataTransfer.dropEffect = 'none';
            area.classList.add('drag-invalid');
            area.classList.remove('drag-over');
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
        
        // 检查是否是插槽区域的放置（由 handleSlotDrop 处理）
        // 支持新旧两种插槽标记方式
        const slot = e.target.closest('[data-wslot], .container-slot, .slot-widgets');
        if (slot) {
            return; // 让 handleSlotDrop 处理
        }
        
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

        console.log('Drop - widget:', widgetData.name, 'position:', widgetData.position, 'type:', widgetData.type, 'target area:', areaCode);

        // 检查是否允许放置（传入 position 和 type，让 isAllowedArea 推断）
        const allowed = isAllowedArea(widgetData.position, areaCode, widgetData.type);
        console.log('Drop - allowed:', allowed);
        
        if (!allowed) {
            showToast('该部件不能放置在此区域', 'warning');
            return;
        }

        // 添加部件
        addWidget(areaCode, widgetData);
    }

    /**
     * 容器内插槽 - 拖拽经过
     * 支持新旧两种插槽标记方式：
     * - 旧版: .container-slot + data-slot + data-accept
     * - 新版: [data-wslot] + data-wslot-accept
     */
    function handleSlotDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // 查找插槽元素 - 支持新旧两种方式
        let slot = this;
        if (!slot.dataset.wslot && !slot.classList.contains('container-slot')) {
            slot = this.closest('[data-wslot]') || this.closest('.container-slot');
        }
        if (!slot) return;
        
        // 获取接受的部件类型（兼容新旧属性）
        const acceptAttr = slot.dataset.wslotAccept || slot.dataset.accept || '';
        const acceptCodes = acceptAttr ? acceptAttr.split(',').map(s => s.trim()) : [];
        
        // 获取拒绝的部件类型（新属性）
        const rejectAttr = slot.dataset.wslotReject || '';
        const rejectCodes = rejectAttr ? rejectAttr.split(',').map(s => s.trim()) : [];
        
        // 获取插槽ID（兼容新旧属性）
        const slotId = slot.dataset.wslot || slot.dataset.slot;
        
        const widgetData = state.draggingWidget;
        
        if (widgetData) {
            const widgetCode = widgetData.code;
            const widgetType = widgetData.type;
            const widgetSlot = widgetData.slot;
            
            // 检查部件是否被拒绝
            // 1. 部件 type 在 reject 列表中
            // 2. 部件 code 在 reject 列表中
            const rejected = rejectCodes.includes(widgetType) || rejectCodes.includes(widgetCode);
            
            // 检查部件是否可以放入此插槽
            // 1. 不能被拒绝
            // 2. 部件的 slot 属性匹配
            // 3. 或者部件 code 在 accept 列表中
            // 4. 或者无限制（acceptCodes 为空）
            const allowed = !rejected && (
                (widgetSlot && widgetSlot === slotId) || 
                acceptCodes.includes(widgetCode) || 
                acceptCodes.length === 0
            );
            
            console.log('SlotDragOver - widget:', widgetCode, 'type:', widgetType, 'slot:', slotId, 'accept:', acceptCodes, 'reject:', rejectCodes, 'allowed:', allowed);
            
            if (allowed) {
                e.dataTransfer.dropEffect = 'copy';
                slot.classList.add('drag-over');
                slot.classList.remove('drag-invalid');
            } else {
                e.dataTransfer.dropEffect = 'none';
                slot.classList.add('drag-invalid');
                slot.classList.remove('drag-over');
            }
        } else {
            e.dataTransfer.dropEffect = 'copy';
            slot.classList.add('drag-over');
        }
    }

    /**
     * 容器内插槽 - 拖拽离开
     * 支持新旧两种插槽标记方式
     */
    function handleSlotDragLeave(e) {
        // 查找插槽元素 - 支持新旧两种方式
        let slot = this;
        if (!slot.dataset.wslot && !slot.classList.contains('container-slot')) {
            slot = this.closest('[data-wslot]') || this.closest('.container-slot');
        }
        if (slot) {
            if (!slot.contains(e.relatedTarget)) {
                slot.classList.remove('drag-over', 'drag-invalid');
            }
        }
    }

    /**
     * 容器内插槽 - 放置
     * 支持新旧两种插槽标记方式
     */
    function handleSlotDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // 查找插槽元素 - 支持新旧两种方式
        let slot = this;
        if (!slot.dataset.wslot && !slot.classList.contains('container-slot')) {
            slot = this.closest('[data-wslot]') || this.closest('.container-slot');
        }
        if (!slot) {
            console.error('SlotDrop: No slot found');
            return;
        }

        slot.classList.remove('drag-over', 'drag-invalid');

        // 获取插槽ID和区域（兼容新旧属性）
        const slotId = slot.dataset.wslot || slot.dataset.slot;
        const slotName = slot.dataset.wslotName || slotId;
        const areaCode = slot.dataset.area || slot.closest('.preview-area')?.dataset.area || 'content';
        const acceptAttr = slot.dataset.wslotAccept || slot.dataset.accept || '';
        // exclusive: 优先从 DOM 属性读取，未设置时用 isExclusiveSlot() 判断
        const exclusiveAttr = slot.dataset.wslotExclusive || slot.dataset.exclusive;
        const exclusive = exclusiveAttr !== undefined ? exclusiveAttr === 'true' : undefined; // undefined 表示需要后续判断
        
        if (!slotId) {
            console.error('SlotDrop: Missing slot id');
            return;
        }

        // 获取部件数据
        let widgetData = state.draggingWidget;
        
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
            console.error('SlotDrop: No widget data available');
            showToast('无法获取部件数据', 'error');
            return;
        }

        // 验证部件是否可以放入插槽
        const acceptCodes = acceptAttr ? acceptAttr.split(',').map(s => s.trim()) : [];
        const rejectAttr = slot.dataset.wslotReject || '';
        const rejectCodes = rejectAttr ? rejectAttr.split(',').map(s => s.trim()) : [];
        
        const widgetCode = widgetData.code;
        const widgetType = widgetData.type;
        const widgetSlot = widgetData.slot;
        
        // 检查是否被拒绝
        const rejected = rejectCodes.includes(widgetType) || rejectCodes.includes(widgetCode);
        
        const allowed = !rejected && (
            (widgetSlot && widgetSlot === slotId) || 
            acceptCodes.includes(widgetCode) || 
            acceptCodes.length === 0
        );
        
        console.log('SlotDrop - widget:', widgetCode, 'type:', widgetType, 'to slot:', slotId, 'in area:', areaCode, 'reject:', rejectCodes, 'allowed:', allowed, 'exclusive:', exclusive);

        if (!allowed) {
            const reason = rejected ? '该类型部件不允许放入此区域' : `部件 "${widgetData.name}" 不能放入插槽 "${slotName}"`;
            showToast(reason, 'warning');
            return;
        }

        // 添加部件到插槽
        addWidgetToSlot(areaCode, slotId, widgetData, { exclusive });
    }

    /**
     * 添加部件到容器内插槽
     * @param {string} area 区域代码
     * @param {string} slotId 插槽ID
     * @param {object} widgetData 部件数据
     * @param {object} options 选项 { exclusive }
     */
    async function addWidgetToSlot(area, slotId, widgetData, options = {}) {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        // 独占插槽检查 - 优先使用传入的选项
        const exclusive = options.exclusive !== undefined 
            ? options.exclusive 
            : (widgetData.exclusive || isExclusiveSlot(slotId, widgetData.code));

        const data = {
            theme_id: state.themeId,
            page_type: state.pageType,
            area: area,
            slot_id: slotId,
            widget_code: widgetData.code,
            widget_module: widgetData.module,
            widget_type: widgetData.type,
            config: {},
            sort_order: getNextSlotSortOrder(slotId),
            exclusive: exclusive,
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
                showToast(exclusive ? `${widgetData.name} 已替换到 ${slotId}` : `${widgetData.name} 已添加到 ${slotId}`, 'success');
                
                // 更新结构视图：添加部件到结构面板
                const layoutId = result.data?.layout_id;
                if (layoutId) {
                    addWidgetToStructureView(area, slotId, widgetData, layoutId, exclusive);
                }
                
                // 切换到预览视图
                switchPreviewView('preview');
                // 使用返回的 preview_html 更新预览（如果有）
                // 新添加的部件传入 isNewWidget=true
                if (result.preview_html && layoutId) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html, true);
                } else {
                    // 没有返回 preview_html，直接刷新整个预览
                    loadLayoutPreview();
                }
            } else {
                showToast(result.message || '添加失败', 'error');
            }
        } catch (err) {
            console.error('Add widget to slot error:', err);
            showToast('添加部件失败', 'error');
        }
    }

    /**
     * 添加部件到结构视图面板
     * 在保存成功后更新左侧结构视图，无需刷新页面
     * 
     * @param {string} area 区域代码
     * @param {string} slotId 插槽ID
     * @param {object} widgetData 部件数据
     * @param {number|string} layoutId 布局ID
     * @param {boolean} exclusive 是否独占（替换现有部件）
     */
    function addWidgetToStructureView(area, slotId, widgetData, layoutId, exclusive = false) {
        // 部件类型图标映射
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
            'container': 'ri-layout-grid-line',
        };
        
        const icon = typeIcons[widgetData.type] || 'ri-widgets-line';
        const widgetName = widgetData.name || widgetData.code;
        
        // 创建部件项 HTML
        const widgetHtml = `
            <div class="preview-widget-item widget-new" 
                 id="widget_${layoutId}"
                 data-layout-id="${layoutId}"
                 data-widget-code="${escapeHtml(widgetData.code)}"
                 data-widget-module="${escapeHtml(widgetData.module || '')}"
                 data-widget-type="${escapeHtml(widgetData.type || '')}"
                 data-config='{}'>
                <div class="widget-header">
                    <span class="widget-name">
                        <i class="${icon}"></i>
                        ${escapeHtml(widgetName)}
                    </span>
                    <div class="widget-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-widget" title="编辑">
                            <i class="ri-edit-line"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-widget" title="删除">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </div>
                </div>
                <div class="widget-preview">
                    <span class="text-muted">点击配置此部件</span>
                </div>
            </div>
        `;
        
        // 查找目标容器 - 简化后的三区域布局
        let targetContainer = null;
        
        // 根据区域查找对应的容器
        const areaContainerMap = {
            'header': '.header-slot-widgets',
            'content': '.content-slot-widgets',
            'footer': '.footer-slot-widgets',
        };
        
        // 优先使用区域映射
        if (areaContainerMap[area]) {
            targetContainer = document.querySelector(areaContainerMap[area]);
        }
        
        // 如果没找到，尝试查找通用的 area-widgets 容器
        if (!targetContainer) {
            targetContainer = document.querySelector(`.area-widgets[data-area="${area}"]`);
        }
        
        // 如果还是没有，查找旧版插槽容器（兼容性）
        if (!targetContainer && slotId) {
            targetContainer = document.querySelector(`.slot-widgets[data-slot="${slotId}"]`);
        }
        
        if (!targetContainer) {
            console.warn(`[ThemeEditor] Structure view container not found for area: ${area}, slot: ${slotId}`);
            return;
        }
        
        // 如果是独占模式，先清空容器中的现有部件
        if (exclusive) {
            const existingWidgets = targetContainer.querySelectorAll('.preview-widget-item');
            existingWidgets.forEach(el => el.remove());
        }
        
        // 移除占位符（如果存在）
        const placeholder = targetContainer.querySelector('.slot-placeholder, .content-slot-placeholder, .slot-placeholder-large');
        if (placeholder) {
            placeholder.remove();
        }
        
        // 插入新部件
        targetContainer.insertAdjacentHTML('beforeend', widgetHtml);
        
        // 添加视觉反馈动画
        const newWidget = targetContainer.querySelector(`#widget_${layoutId}`);
        if (newWidget) {
            // 短暂延迟后移除 widget-new 类（动画效果）
            setTimeout(() => {
                newWidget.classList.remove('widget-new');
            }, 1500);
            
            // 滚动到新部件
            newWidget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        console.log(`[ThemeEditor] Widget added to structure view: ${widgetName} in ${area}/${slotId || 'default'}`);
    }

    /**
     * 获取插槽内下一个排序值
     */
    function getNextSlotSortOrder(slotId) {
        const slotWidgets = document.querySelector(`.slot-widgets[data-slot="${slotId}"]`);
        if (!slotWidgets) return 0;
        return slotWidgets.querySelectorAll('.preview-widget-item').length;
    }

    /**
     * 高亮允许的区域和插槽
     * @param {Array} positions 部件允许的位置数组，如 ['header'] 或 ['left_sidebar', 'right_sidebar']
     */
    function highlightAllowedAreas(positions) {
        const widgetData = state.draggingWidget;
        const widgetType = widgetData ? widgetData.type : null;
        console.log('highlightAllowedAreas - positions:', positions, 'type:', widgetType, 'widget:', widgetData);
        
        // 区域互斥规则：每个区域不接受哪些类型的部件
        // content 区域不接受 header 和 footer 类型
        // header 区域不接受 footer 类型
        // footer 区域不接受 header 类型
        const areaExclusiveTypes = {
            'content': ['header', 'footer'],      // content 区域排除 header、footer 类型
            'header': ['footer'],                  // header 区域排除 footer 类型
            'footer': ['header'],                  // footer 区域排除 header 类型
        };
        
        // 类型到允许区域的映射（与后端 WidgetPositionResolver::inferAreasFromType 保持一致）
        const typeToAreasMap = {
            'header': ['header'],
            'footer': ['footer'],
            'sidebar': ['left_sidebar', 'right_sidebar', 'content'],
            'banner': ['banner', 'content'],
            'carousel': ['banner', 'content'],
            'slider': ['banner', 'content'],
            'product': ['content', 'left_sidebar', 'right_sidebar'],
            'category': ['content', 'left_sidebar', 'right_sidebar'],
            'navigation': ['header', 'left_sidebar'],
            'search': ['header', 'content'],
            'breadcrumb': ['content'],
            'pagination': ['content'],
            'social': ['footer', 'left_sidebar', 'right_sidebar'],
            'newsletter': ['footer', 'left_sidebar', 'right_sidebar'],
            'testimonial': ['content'],
            'faq': ['content'],
            'video': ['content', 'banner'],
            'content': ['content', 'left_sidebar', 'right_sidebar'],
        };
        
        // 位置到区域的映射（与后端 POSITION_TO_AREA_MAP 保持一致）
        const positionToAreaMap = {
            'header': ['header'],
            'banner': ['banner'],
            'sidebar': ['left_sidebar', 'right_sidebar'],
            'left_sidebar': ['left_sidebar'],
            'right_sidebar': ['right_sidebar'],
            'content': ['content', 'banner'],
            'footer': ['footer'],
        };

        // 所有可能的区域（简化后的三区域结构）
        const allAreas = ['header', 'content', 'footer'];

        let allowedAreas = [];
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            // 没有 position 限制，根据 type 推断
            if (widgetType && typeToAreasMap[widgetType]) {
                allowedAreas = typeToAreasMap[widgetType];
                console.log('highlightAllowedAreas - inferred from type:', widgetType, '-> areas:', allowedAreas);
            } else {
                // 如果没有类型信息，默认只允许 content 区域
                allowedAreas = ['content'];
                console.log('highlightAllowedAreas - no position and no type, defaulting to content only');
            }
        } else if (positions.includes('*') || positions.includes('all')) {
            // 通配符，根据类型排除不允许的区域
            allowedAreas = allAreas.filter(area => {
                if (widgetType && areaExclusiveTypes[area] && areaExclusiveTypes[area].includes(widgetType)) {
                    return false; // 该区域拒绝该类型
                }
                return true;
            });
            console.log('highlightAllowedAreas - wildcard found, filtered by type exclusion:', allowedAreas);
        } else {
            // 收集所有允许的区域
            positions.forEach(pos => {
                if (positionToAreaMap[pos]) {
                    allowedAreas = allowedAreas.concat(positionToAreaMap[pos]);
                } else {
                    // 未知的 position，假设它直接对应同名区域
                    if (allAreas.includes(pos)) {
                        allowedAreas.push(pos);
                    } else {
                        console.warn('Unknown position:', pos);
                    }
                }
            });
            // 去重
            allowedAreas = [...new Set(allowedAreas)];
        }
        
        // 应用区域互斥规则：过滤掉类型被拒绝的区域
        if (widgetType) {
            allowedAreas = allowedAreas.filter(area => {
                if (areaExclusiveTypes[area] && areaExclusiveTypes[area].includes(widgetType)) {
                    console.log('highlightAllowedAreas - excluding area due to type:', area, 'rejects', widgetType);
                    return false;
                }
                return true;
            });
        }

        console.log('highlightAllowedAreas - final allowedAreas:', allowedAreas);

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

        // 高亮匹配的容器内插槽（支持新旧两种属性标记）
        if (widgetData) {
            const widgetCode = widgetData.code;
            const widgetSlot = widgetData.slot;
            
            // 查找所有插槽（包括新旧两种标记方式）
            document.querySelectorAll('.container-slot, [data-wslot]').forEach(slot => {
                // 获取插槽ID（兼容新旧属性）
                const slotId = slot.dataset.wslot || slot.dataset.slot;
                // 获取接受的部件类型（兼容新旧属性）
                const acceptAttr = slot.dataset.wslotAccept || slot.dataset.accept || '';
                const acceptCodes = acceptAttr ? acceptAttr.split(',').map(s => s.trim()) : [];
                
                // 检查是否匹配
                const matches = (widgetSlot && widgetSlot === slotId) || 
                               acceptCodes.includes(widgetCode) ||
                               acceptCodes.length === 0;
                
                if (matches) {
                    slot.classList.add('drag-allowed');
                    slot.classList.remove('drag-invalid');
                } else {
                    slot.classList.remove('drag-allowed');
                }
            });
        }
    }

    /**
     * 检查是否允许放置
     * @param {Array} positions 部件允许的位置数组，如 ['header'] 或 ['left_sidebar', 'right_sidebar']
     * @param {string} areaCode 目标区域代码，如 'header', 'footer' 等
     */
    /**
     * 检查部件是否可以放置到指定区域
     * @param {Array} positions 部件的 position 数组
     * @param {string} areaCode 目标区域代码
     * @returns {boolean}
     */
    /**
     * 检查部件是否可以放置到指定区域
     * @param {Array} positions 部件的 position 数组
     * @param {string} areaCode 目标区域代码
     * @param {string} widgetType 部件类型（可选，用于推断 position）
     * @returns {boolean}
     */
    function isAllowedArea(positions, areaCode, widgetType = null) {
        console.log('isAllowedArea - positions:', positions, 'areaCode:', areaCode, 'type:', widgetType);
        
        // 区域互斥规则：每个区域不接受哪些类型的部件
        // content 区域不接受 header 和 footer 类型
        // header 区域不接受 footer 类型
        // footer 区域不接受 header 类型
        const areaExclusiveTypes = {
            'content': ['header', 'footer'],      // content 区域排除 header、footer 类型
            'header': ['footer'],                  // header 区域排除 footer 类型
            'footer': ['header'],                  // footer 区域排除 header 类型
        };
        
        // 检查部件类型是否被当前区域拒绝
        if (widgetType && areaExclusiveTypes[areaCode]) {
            if (areaExclusiveTypes[areaCode].includes(widgetType)) {
                console.log('isAllowedArea - type rejected by area:', widgetType, 'not allowed in', areaCode);
                return false;
            }
        }
        
        // 类型到允许区域的映射（与后端 WidgetPositionResolver::inferAreasFromType 保持一致）
        const typeToAreasMap = {
            'header': ['header'],
            'footer': ['footer'],
            'sidebar': ['left_sidebar', 'right_sidebar', 'content'],
            'banner': ['banner', 'content'],
            'carousel': ['banner', 'content'],
            'slider': ['banner', 'content'],
            'product': ['content', 'left_sidebar', 'right_sidebar'],
            'category': ['content', 'left_sidebar', 'right_sidebar'],
            'navigation': ['header', 'left_sidebar'],
            'search': ['header', 'content'],
            'breadcrumb': ['content'],
            'pagination': ['content'],
            'social': ['footer', 'left_sidebar', 'right_sidebar'],
            'newsletter': ['footer', 'left_sidebar', 'right_sidebar'],
            'testimonial': ['content'],
            'faq': ['content'],
            'video': ['content', 'banner'],
            'content': ['content', 'left_sidebar', 'right_sidebar'],
        };
        
        // 如果没有 position 限制，根据 type 推断
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            if (widgetType && typeToAreasMap[widgetType]) {
                // 使用类型推断的区域
                const inferredAreas = typeToAreasMap[widgetType];
                const result = inferredAreas.includes(areaCode);
                console.log('isAllowedArea - inferred from type:', widgetType, '-> areas:', inferredAreas, '-> result:', result);
                return result;
            }
            // 如果没有类型信息，默认只允许 content 区域
            console.log('isAllowedArea - no position and no type, defaulting to content only');
            return areaCode === 'content';
        }

        // 如果位置包含 '*' 或 'all'，检查类型是否被拒绝
        if (positions.includes('*') || positions.includes('all')) {
            // 即使是通配符，也要检查区域互斥规则
            if (widgetType && areaExclusiveTypes[areaCode] && areaExclusiveTypes[areaCode].includes(widgetType)) {
                console.log('isAllowedArea - wildcard but type rejected:', widgetType, 'not allowed in', areaCode);
                return false;
            }
            console.log('isAllowedArea - wildcard found, returning true');
            return true;
        }

        // 位置到区域的映射（与后端 POSITION_TO_AREA_MAP 保持一致）
        const positionToAreaMap = {
            'header': ['header'],
            'banner': ['banner'],
            'sidebar': ['left_sidebar', 'right_sidebar'],
            'left_sidebar': ['left_sidebar'],
            'right_sidebar': ['right_sidebar'],
            'content': ['content', 'banner'],
            'footer': ['footer'],
        };

        // 收集部件允许放置的所有区域
        let allowedAreas = [];
        positions.forEach(pos => {
            if (positionToAreaMap[pos]) {
                allowedAreas = allowedAreas.concat(positionToAreaMap[pos]);
            }
        });
        allowedAreas = [...new Set(allowedAreas)]; // 去重

        console.log('isAllowedArea - allowedAreas:', allowedAreas, 'target:', areaCode);
        
        const result = allowedAreas.includes(areaCode);
        console.log('isAllowedArea - result:', result);
        return result;
    }

    /**
     * 添加部件
     */
    /**
     * 添加部件到区域
     * @param {string} area 区域代码
     * @param {object} widgetData 部件数据
     * @param {object} options 选项 { slotId, exclusive }
     */
    async function addWidget(area, widgetData, options = {}) {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        // 获取插槽信息
        const slotId = options.slotId || widgetData.slot || null;
        // 独占插槽：logo、search 等只能有一个
        const exclusive = options.exclusive !== undefined ? options.exclusive : isExclusiveSlot(slotId, widgetData.code);

        const data = {
            theme_id: state.themeId,
            page_type: state.pageType,
            area: area,
            widget_code: widgetData.code,
            widget_module: widgetData.module,
            widget_type: widgetData.type,
            config: {},
            sort_order: getNextSortOrder(area),
            slot_id: slotId,
            exclusive: exclusive,
        };

        console.log('addWidget - data:', data);

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
                showToast(exclusive ? '部件已替换' : '部件添加成功', 'success');
                
                // 更新结构视图：添加部件到结构面板
                const layoutId = result.data?.layout_id;
                if (layoutId) {
                    addWidgetToStructureView(area, slotId, widgetData, layoutId, exclusive);
                }
                
                // 切换到预览视图
                switchPreviewView('preview');
                // 使用返回的 preview_html 更新预览（如果有）
                // 新添加的部件传入 isNewWidget=true
                if (result.preview_html && layoutId) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html, true);
                } else {
                    // 没有返回 preview_html，直接刷新整个预览
                    loadLayoutPreview();
                }
            } else {
                showToast(result.message || '添加失败', 'error');
            }
        } catch (err) {
            console.error('Add widget error:', err);
            showToast('添加部件失败', 'error');
        }
    }

    /**
     * 判断插槽是否为独占类型
     * 独占插槽：同一插槽只能有一个部件，新部件会替换旧部件
     */
    function isExclusiveSlot(slotId, widgetCode) {
        // 独占插槽列表
        const exclusiveSlots = [
            'logo',           // Logo 只能有一个
            'search',         // 搜索框只能有一个
            'main-nav',       // 主导航只能有一个
            'header-container', // Header 容器独占
            'footer-container', // Footer 容器独占
            'copyright',      // 版权信息只能有一个
            'user-area',      // 用户区域独占
            'cart',           // 购物车独占
            'language',       // 语言切换独占
            'currency',       // 货币切换独占
            'top-bar',        // 顶栏独占
            'footer-links',   // 底部链接独占
            'footer-social',  // 底部社交独占
            'footer-newsletter', // 底部订阅独占
        ];

        // 独占部件类型：这些部件在同一区域只能有一个
        const exclusiveWidgets = [
            'logo',
            'main-nav',
            'search-box',
            'header-container',
            'footer-container',
            'footer-copyright',
            'language-switcher',
            'currency-switcher',
            'cart-mini',
            'user-menu',
        ];

        if (slotId && exclusiveSlots.includes(slotId)) {
            return true;
        }

        if (widgetCode && exclusiveWidgets.includes(widgetCode)) {
            return true;
        }

        return false;
    }

    /**
     * SVG 图标定义（内联 SVG 替代字体图标，确保 iframe 内可见）
     */
    const WIDGET_ACTION_ICONS = {
        replace: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M5.46257 4.43262C7.21556 2.91688 9.5007 2 12 2C17.5228 2 22 6.47715 22 12C22 14.1361 21.3302 16.1158 20.1892 17.7406L17 12H20C20 7.58172 16.4183 4 12 4C9.84982 4 7.89777 4.84827 6.46023 6.22842L5.46257 4.43262ZM18.5374 19.5674C16.7844 21.0831 14.4993 22 12 22C6.47715 22 2 17.5228 2 12C2 9.86386 2.66979 7.88416 3.8108 6.25944L7 12H4C4 16.4183 7.58172 20 12 20C14.1502 20 16.1022 19.1517 17.5398 17.7716L18.5374 19.5674Z"></path></svg>`,
        delete: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17 6H22V8H20V21C20 21.5523 19.5523 22 19 22H5C4.44772 22 4 21.5523 4 21V8H2V6H7V3C7 2.44772 7.44772 2 8 2H16C16.5523 2 17 2.44772 17 3V6ZM18 8H6V20H18V8ZM9 11H11V17H9V11ZM13 11H15V17H13V11ZM9 4V6H15V4H9Z"></path></svg>`,
        moveUp: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 10.8284L16.9497 15.778L15.5355 17.1924L12 13.6569L8.46447 17.1924L7.05025 15.778L12 10.8284ZM12 6.00005L17.6569 11.6569L16.2426 13.0711L12 8.82848L7.75736 13.0711L6.34315 11.6569L12 6.00005Z"></path></svg>`,
        moveDown: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 13.1716L16.9497 8.22185L15.5355 6.80764L12 10.3431L8.46447 6.80764L7.05025 8.22185L12 13.1716ZM12 18L17.6569 12.3432L16.2426 10.929L12 15.1716L7.75736 10.929L6.34315 12.3432L12 18Z"></path></svg>`
    };

    /**
     * 生成部件 hover 操作按钮的 HTML
     * @param {string|number} layoutId - 布局记录 ID
     * @param {string} slotId - 插槽 ID
     * @param {boolean} isExclusive - 是否独占插槽
     * @param {boolean} isFirst - 是否是第一个部件
     * @param {boolean} isLast - 是否是最后一个部件
     * @returns {string} 操作按钮 HTML
     */
    function generateWidgetHoverActionsHtml(layoutId, slotId, isExclusive, isFirst = true, isLast = true) {
        let html = `<div class="widget-hover-actions" data-layout-id="${layoutId}">`;
        
        // 替换按钮 - 所有部件都有
        html += `<button class="btn-widget-replace" title="替换部件" data-action="replace" data-layout-id="${layoutId}" data-slot-id="${slotId}">
                    ${WIDGET_ACTION_ICONS.replace}
                 </button>`;
        
        // 删除按钮 - 所有部件都有
        html += `<button class="btn-widget-delete" title="删除部件" data-action="delete" data-layout-id="${layoutId}" data-slot-id="${slotId}">
                    ${WIDGET_ACTION_ICONS.delete}
                 </button>`;
        
        // 非独占部件显示上下移动按钮
        if (!isExclusive) {
            html += `<button class="btn-widget-move-up" title="上移" data-action="move-up" data-layout-id="${layoutId}" ${isFirst ? 'disabled' : ''}>
                        ${WIDGET_ACTION_ICONS.moveUp}
                     </button>`;
            html += `<button class="btn-widget-move-down" title="下移" data-action="move-down" data-layout-id="${layoutId}" ${isLast ? 'disabled' : ''}>
                        ${WIDGET_ACTION_ICONS.moveDown}
                     </button>`;
        }
        
        html += '</div>';
        return html;
    }

    /**
     * 注入样式到 iframe
     */
    function injectStylesIntoIframe() {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        
        const iframeDoc = iframe.contentDocument;
        
        // 检查是否已注入
        if (iframeDoc.getElementById('widget-hover-styles')) {
            return;
        }
        
        const style = iframeDoc.createElement('style');
        style.id = 'widget-hover-styles';
        style.textContent = `
            /* 部件包装器 */
            .widget-wrapper {
                position: relative;
                transition: box-shadow 0.2s, outline 0.2s;
                overflow: visible !important;
            }
            .widget-wrapper:hover {
                outline: 2px solid #4a90d9;
                outline-offset: 2px;
                z-index: 100;
            }
            .widget-wrapper.selected {
                outline: 2px solid #4a90d9;
                outline-offset: 2px;
                box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.2);
            }
            /* Hover 操作按钮容器 - 放置在部件内部右上角 */
            .widget-hover-actions {
                position: absolute;
                top: 4px;
                right: 4px;
                display: none;
                gap: 3px;
                z-index: 1000;
                background: rgba(30, 30, 30, 0.92);
                padding: 4px 5px;
                border-radius: 6px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
                backdrop-filter: blur(4px);
            }
            .widget-wrapper:hover .widget-hover-actions {
                display: flex;
            }
            /* 操作按钮 */
            .widget-hover-actions button {
                width: 26px;
                height: 26px;
                border: none;
                border-radius: 4px;
                background: rgba(255, 255, 255, 0.08);
                color: #fff;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.15s, transform 0.1s;
                padding: 0;
            }
            .widget-hover-actions button svg {
                width: 16px;
                height: 16px;
                fill: currentColor;
            }
            .widget-hover-actions button:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: scale(1.08);
            }
            .widget-hover-actions button:active {
                transform: scale(0.95);
            }
            .widget-hover-actions .btn-widget-delete:hover {
                background: rgba(220, 53, 69, 0.85);
            }
            .widget-hover-actions .btn-widget-replace:hover {
                background: rgba(74, 144, 217, 0.85);
            }
            .widget-hover-actions .btn-widget-move-up:hover,
            .widget-hover-actions .btn-widget-move-down:hover {
                background: rgba(40, 167, 69, 0.85);
            }
            .widget-hover-actions button:disabled {
                opacity: 0.3;
                cursor: not-allowed;
                transform: none !important;
            }
            .widget-hover-actions button:disabled:hover {
                background: rgba(255, 255, 255, 0.08);
            }
            /* 部件拖拽排序样式 */
            .widget-wrapper.dragging {
                opacity: 0.5;
                outline: 2px dashed #4a90d9;
            }
            .widget-wrapper.drag-over-top::before {
                content: '';
                position: absolute;
                top: -4px;
                left: 0;
                right: 0;
                height: 4px;
                background: #4a90d9;
                border-radius: 2px;
            }
            .widget-wrapper.drag-over-bottom::after {
                content: '';
                position: absolute;
                bottom: -4px;
                left: 0;
                right: 0;
                height: 4px;
                background: #4a90d9;
                border-radius: 2px;
            }
            .widget-wrapper.widget-updated {
                animation: widget-highlight 1s ease-out;
            }
            .widget-wrapper.widget-new {
                animation: widget-new-highlight 1.5s ease-out;
            }
            @keyframes widget-highlight {
                0% { box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.6); }
                100% { box-shadow: none; }
            }
            @keyframes widget-new-highlight {
                0% { box-shadow: 0 0 0 6px rgba(74, 144, 217, 0.8); }
                50% { box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.4); }
                100% { box-shadow: none; }
            }
        `;
        iframeDoc.head.appendChild(style);
        
        // 同时注入 Remix Icon 如果不存在
        if (!iframeDoc.querySelector('link[href*="remixicon"]')) {
            const link = iframeDoc.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css';
            iframeDoc.head.appendChild(link);
        }
        
        console.log('[ThemeEditor] Styles injected into iframe');
    }

    /**
     * 初始化 iframe 内的部件 hover 操作按钮
     */
    function initWidgetHoverActions() {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) {
            return;
        }
        
        // 先注入样式
        injectStylesIntoIframe();
        
        const iframeDoc = iframe.contentDocument;
        
        // 查找所有已有的部件包装器并添加操作按钮
        const widgetWrappers = iframeDoc.querySelectorAll('[data-layout-id]');
        
        widgetWrappers.forEach((wrapper, index) => {
            const layoutId = wrapper.getAttribute('data-layout-id');
            const slotId = wrapper.getAttribute('data-slot-id') || 
                           wrapper.closest('[data-wslot]')?.getAttribute('data-wslot') ||
                           wrapper.closest('[data-slot]')?.getAttribute('data-slot') || '';
            const isExclusive = isExclusiveSlot(slotId, '');
            
            // 检查是否已有操作按钮
            if (wrapper.querySelector('.widget-hover-actions')) return;
            
            // 确保是 relative 定位
            if (getComputedStyle(wrapper).position === 'static') {
                wrapper.style.position = 'relative';
            }
            wrapper.classList.add('widget-wrapper');
            
            // 计算同层部件位置
            const siblings = wrapper.parentElement?.querySelectorAll('[data-layout-id]') || [];
            const siblingArray = Array.from(siblings);
            const currentIndex = siblingArray.indexOf(wrapper);
            const isFirst = currentIndex === 0;
            const isLast = currentIndex === siblingArray.length - 1;
            
            // 添加操作按钮
            const actionsHtml = generateWidgetHoverActionsHtml(layoutId, slotId, isExclusive, isFirst, isLast);
            wrapper.insertAdjacentHTML('afterbegin', actionsHtml);
        });
        
        // 绑定按钮事件
        bindWidgetActionEvents(iframeDoc);
        
        // 绑定 slot 点击事件（选中 slot 后过滤部件并滚动）
        bindSlotClickEvents(iframeDoc);
        
        // 初始化拖拽排序
        initWidgetSortable();
        
        console.log('[ThemeEditor] Widget hover actions initialized,', widgetWrappers.length, 'widgets processed');
    }

    /**
     * 绑定部件操作按钮事件
     */
    function bindWidgetActionEvents(doc) {
        // 使用事件委托 - 必须在最早阶段阻止冒泡
        doc.body.addEventListener('click', function(e) {
            const button = e.target.closest('.widget-hover-actions button');
            
            // 立即阻止事件传播（在检查之前）
            if (e.target.closest('.widget-hover-actions')) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
            }
            
            if (!button) return;
            
            console.log('[ThemeEditor] Widget action button clicked:', button.dataset.action);
            
            const action = button.dataset.action;
            const layoutId = button.dataset.layoutId;
            const slotId = button.dataset.slotId;
            
            switch (action) {
                case 'replace':
                    handleWidgetReplace(layoutId, slotId);
                    break;
                case 'delete':
                    handleWidgetDelete(layoutId, slotId);
                    break;
                case 'move-up':
                    handleWidgetMoveUp(layoutId);
                    break;
                case 'move-down':
                    handleWidgetMoveDown(layoutId);
                    break;
            }
        }, true); // 使用捕获阶段
    }

    /**
     * 绑定 iframe 内 slot 的点击事件
     * 点击 slot 时：选中该 slot，过滤右侧部件列表，并滚动到匹配的部件
     */
    function bindSlotClickEvents(doc) {
        if (!doc || !doc.body) return;
        
        // 使用事件委托，监听所有 slot 的点击
        doc.body.addEventListener('click', function(e) {
            // 如果点击的是部件或操作按钮，跳过
            if (e.target.closest('.widget-hover-actions') || 
                e.target.closest('[data-layout-id]') ||
                e.target.closest('.widget-wrapper')) {
                return;
            }
            
            // 查找点击的 slot 元素（支持多种标记方式）
            const slotEl = e.target.closest('[data-slot]') || 
                          e.target.closest('[data-wslot]') ||
                          e.target.closest('.content-slot');
            
            if (!slotEl) return;
            
            // 获取 slot 信息
            const slotId = slotEl.getAttribute('data-slot') || 
                          slotEl.getAttribute('data-wslot') || '';
            const slotName = slotEl.getAttribute('data-name') ||
                            slotEl.getAttribute('data-wslot-name') ||
                            slotEl.querySelector('.slot-placeholder span')?.textContent ||
                            slotId;
            const acceptAttr = slotEl.getAttribute('data-accept') ||
                              slotEl.getAttribute('data-wslot-accept') || '*';
            
            if (!slotId) return;
            
            console.log('[ThemeEditor] Slot clicked in iframe:', slotId, 'accept:', acceptAttr);
            
            // 构造 slot 信息
            const slotInfo = {
                id: slotId,
                name: slotName,
                accept: acceptAttr
            };
            
            // 调用 slot 选中处理函数（这会过滤部件并滚动）
            handleSlotSelected(slotInfo);
            
            // 高亮被点击的 slot
            doc.querySelectorAll('[data-slot], [data-wslot], .content-slot').forEach(el => {
                el.classList.remove('slot-selected');
            });
            slotEl.classList.add('slot-selected');
            
        }, false);
        
        console.log('[ThemeEditor] Slot click events bound in iframe');
    }

    /**
     * 处理部件替换
     */
    function handleWidgetReplace(layoutId, slotId) {
        console.log('[ThemeEditor] Replace widget:', layoutId, 'in slot:', slotId);
        
        // 选中对应的插槽
        if (slotId) {
            // 构造插槽信息并触发选中
            const slotInfo = { 
                id: slotId, 
                name: slotId,
                accept: '*'  // 默认接受所有部件，后续可通过插槽配置获取
            };
            
            // 尝试从 iframe 获取插槽的 accept 属性
            const iframe = elements.previewFrame;
            if (iframe && iframe.contentDocument) {
                const slotEl = iframe.contentDocument.querySelector(`[data-wslot="${slotId}"]`) ||
                               iframe.contentDocument.querySelector(`[data-slot="${slotId}"]`);
                if (slotEl) {
                    slotInfo.accept = slotEl.getAttribute('data-wslot-accept') || 
                                      slotEl.getAttribute('data-accept') || '*';
                    slotInfo.name = slotEl.getAttribute('data-wslot-name') || 
                                    slotEl.getAttribute('data-name') || slotId;
                }
            }
            
            // 使用现有的插槽选中处理函数
            handleSlotSelected(slotInfo);
        }
        
        // 滚动部件面板到顶部
        const widgetPanel = document.querySelector('.editor-widget-panel .widget-list');
        if (widgetPanel) {
            widgetPanel.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        // 高亮显示符合的部件
        highlightCompatibleWidgets(slotId);
        
        showToast('请从右侧选择新部件进行替换', 'info');
    }

    /**
     * 高亮显示兼容的部件
     */
    function highlightCompatibleWidgets(slotId) {
        // 移除所有高亮
        document.querySelectorAll('.widget-item.highlight').forEach(el => {
            el.classList.remove('highlight');
        });
        
        // 如果有选中的插槽信息
        if (state.selectedSlot && state.selectedSlot.accept) {
            const acceptList = state.selectedSlot.accept.split(',').map(s => s.trim());
            
            document.querySelectorAll('.widget-item').forEach(item => {
                const widgetCode = item.dataset.widgetCode;
                if (acceptList.includes('*') || acceptList.includes(widgetCode)) {
                    item.classList.add('highlight');
                }
            });
        } else {
            // 没有限制，所有部件都高亮
            document.querySelectorAll('.widget-item').forEach(item => {
                item.classList.add('highlight');
            });
        }
    }

    /**
     * 处理部件删除
     */
    async function handleWidgetDelete(layoutId, slotId) {
        console.log('[ThemeEditor] Delete widget:', layoutId, 'in slot:', slotId);
        
        // 确认删除 - 使用自定义对话框
        const confirmed = await showCustomConfirm(
            '确认删除部件？',
            '删除后插槽将恢复为原始内容。',
            '确认删除',
            '取消'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            // 调用删除 API
            const response = await fetch(config.apiDeleteWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    theme_id: state.themeId
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // 从 iframe 中移除部件并恢复原始内容
                const iframe = elements.previewFrame;
                if (iframe && iframe.contentDocument) {
                    const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                    if (widgetEl) {
                        const slot = widgetEl.closest('[data-wslot], [data-slot]');
                        const actualSlotId = slot?.getAttribute('data-wslot') || slot?.getAttribute('data-slot');
                        
                        // 移除部件元素
                        widgetEl.remove();
                        
                        // 恢复原始内容
                        if (slot && !slot.querySelector('[data-layout-id]')) {
                            if (result.has_original && result.original_html) {
                                // 有原始内容，恢复模板默认的内容
                                slot.innerHTML = result.original_html;
                                // 重新初始化恢复的部件的hover操作
                                initWidgetHoverActions();
                            } else {
                                // 没有原始内容，显示占位符
                                const slotName = slot.getAttribute('data-wslot-name') || slot.getAttribute('data-name') || actualSlotId;
                                slot.innerHTML = `
                                    <div class="slot-placeholder" style="
                                        padding: 40px 20px;
                                        text-align: center;
                                        color: #999;
                                        border: 2px dashed #ddd;
                                        border-radius: 8px;
                                        background: rgba(0,0,0,0.02);
                                    ">
                                        <i class="ri-inbox-line" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                        <p style="margin: 0; font-size: 14px;">插槽原本为空</p>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; opacity: 0.7;">拖入部件或点击选择新部件</p>
                                    </div>
                                `;
                            }
                        }
                    }
                }
                
                // 从结构视图中移除
                const structureItem = document.querySelector(`.preview-widget-item[data-layout-id="${layoutId}"]`);
                if (structureItem) {
                    structureItem.remove();
                }
                
                showToast(result.has_original ? '已恢复为原始布局' : '部件已删除', 'success');
                
                // 更新同层部件的移动按钮状态（如果有的话）
                if (slotId) {
                    updateSiblingMoveButtons(slotId);
                }
            } else {
                showToast(result.message || '删除失败', 'error');
            }
        } catch (err) {
            console.error('[ThemeEditor] Delete widget error:', err);
            showToast('删除部件时发生错误', 'error');
        }
    }

    /**
     * 处理部件上移
     */
    async function handleWidgetMoveUp(layoutId) {
        console.log('[ThemeEditor] Move up widget:', layoutId);
        
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        
        const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
        if (!widgetEl) return;
        
        const prevWidget = widgetEl.previousElementSibling;
        if (!prevWidget || !prevWidget.hasAttribute('data-layout-id')) {
            showToast('已经是第一个部件', 'info');
            return;
        }
        
        const prevLayoutId = prevWidget.getAttribute('data-layout-id');
        
        // 交换位置
        await swapWidgetOrder(layoutId, prevLayoutId);
    }

    /**
     * 处理部件下移
     */
    async function handleWidgetMoveDown(layoutId) {
        console.log('[ThemeEditor] Move down widget:', layoutId);
        
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        
        const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
        if (!widgetEl) return;
        
        const nextWidget = widgetEl.nextElementSibling;
        if (!nextWidget || !nextWidget.hasAttribute('data-layout-id')) {
            showToast('已经是最后一个部件', 'info');
            return;
        }
        
        const nextLayoutId = nextWidget.getAttribute('data-layout-id');
        
        // 交换位置
        await swapWidgetOrder(layoutId, nextLayoutId);
    }

    /**
     * 交换两个部件的排序
     */
    async function swapWidgetOrder(layoutId1, layoutId2) {
        try {
            const response = await fetch(config.apiBase + '/swap-widget-order', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    layout_id_1: layoutId1,
                    layout_id_2: layoutId2
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // 在 iframe 中交换 DOM 位置
                const iframe = elements.previewFrame;
                if (iframe && iframe.contentDocument) {
                    const el1 = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId1}"]`);
                    const el2 = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId2}"]`);
                    
                    if (el1 && el2) {
                        // 判断哪个在前
                        if (el1.compareDocumentPosition(el2) & Node.DOCUMENT_POSITION_FOLLOWING) {
                            // el1 在 el2 前面，el1 要移到 el2 后面
                            el2.parentNode.insertBefore(el1, el2.nextSibling);
                        } else {
                            // el2 在 el1 前面，el1 要移到 el2 前面
                            el2.parentNode.insertBefore(el1, el2);
                        }
                        
                        // 更新移动按钮状态
                        const slotId = el1.getAttribute('data-slot-id') || 
                                       el1.closest('[data-wslot]')?.getAttribute('data-wslot') || '';
                        updateSiblingMoveButtons(slotId);
                    }
                }
                
                showToast('部件位置已更新', 'success');
            } else {
                showToast(result.message || '移动失败', 'error');
            }
        } catch (err) {
            console.error('[ThemeEditor] Swap widget order error:', err);
            showToast('移动部件时发生错误', 'error');
        }
    }

    /**
     * 更新同层部件的移动按钮状态
     */
    function updateSiblingMoveButtons(slotId) {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        
        // 找到插槽
        let slotEl = iframe.contentDocument.querySelector(`[data-wslot="${slotId}"]`) ||
                     iframe.contentDocument.querySelector(`[data-slot="${slotId}"]`);
        
        if (!slotEl) return;
        
        // 获取插槽内所有部件
        const widgets = slotEl.querySelectorAll('[data-layout-id]');
        
        widgets.forEach((widget, index) => {
            const upBtn = widget.querySelector('.btn-widget-move-up');
            const downBtn = widget.querySelector('.btn-widget-move-down');
            
            if (upBtn) {
                upBtn.disabled = (index === 0);
            }
            if (downBtn) {
                downBtn.disabled = (index === widgets.length - 1);
            }
        });
    }

    /**
     * 初始化部件拖拽排序功能
     */
    function initWidgetSortable() {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        
        const iframeDoc = iframe.contentDocument;
        
        // 为所有部件包装器添加拖拽属性
        const widgets = iframeDoc.querySelectorAll('.widget-wrapper[data-layout-id]');
        
        widgets.forEach(widget => {
            const slotId = widget.getAttribute('data-slot-id') || 
                           widget.closest('[data-wslot]')?.getAttribute('data-wslot') || '';
            
            // 独占插槽不需要排序
            if (isExclusiveSlot(slotId, '')) {
                return;
            }
            
            // 设置可拖拽
            widget.setAttribute('draggable', 'true');
            
            // 拖拽开始
            widget.addEventListener('dragstart', function(e) {
                e.stopPropagation();
                this.classList.add('dragging');
                
                const layoutId = this.getAttribute('data-layout-id');
                e.dataTransfer.setData('text/plain', layoutId);
                e.dataTransfer.effectAllowed = 'move';
                
                // 记录拖拽数据
                state.sortDragging = {
                    layoutId: layoutId,
                    slotId: slotId,
                    element: this
                };
            });
            
            // 拖拽结束
            widget.addEventListener('dragend', function(e) {
                this.classList.remove('dragging');
                state.sortDragging = null;
                
                // 移除所有拖拽指示器
                iframeDoc.querySelectorAll('.drag-over-top, .drag-over-bottom').forEach(el => {
                    el.classList.remove('drag-over-top', 'drag-over-bottom');
                });
            });
            
            // 拖拽经过
            widget.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (!state.sortDragging) return;
                
                // 检查是否是同层部件
                const targetSlotId = this.getAttribute('data-slot-id') || 
                                     this.closest('[data-wslot]')?.getAttribute('data-wslot') || '';
                
                if (targetSlotId !== state.sortDragging.slotId) {
                    e.dataTransfer.dropEffect = 'none';
                    return;
                }
                
                // 不能放到自己身上
                if (this === state.sortDragging.element) {
                    return;
                }
                
                e.dataTransfer.dropEffect = 'move';
                
                // 计算鼠标位置，决定插入到上方还是下方
                const rect = this.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                
                this.classList.remove('drag-over-top', 'drag-over-bottom');
                if (e.clientY < midY) {
                    this.classList.add('drag-over-top');
                } else {
                    this.classList.add('drag-over-bottom');
                }
            });
            
            // 拖拽离开
            widget.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            
            // 放置
            widget.addEventListener('drop', async function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                this.classList.remove('drag-over-top', 'drag-over-bottom');
                
                if (!state.sortDragging) return;
                
                const sourceLayoutId = state.sortDragging.layoutId;
                const targetLayoutId = this.getAttribute('data-layout-id');
                
                if (sourceLayoutId === targetLayoutId) return;
                
                // 计算插入位置
                const rect = this.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                const insertBefore = e.clientY < midY;
                
                // 保存排序
                await saveWidgetSortOrder(sourceLayoutId, targetLayoutId, insertBefore, state.sortDragging.slotId);
            });
        });
        
        console.log('[ThemeEditor] Widget sortable initialized,', widgets.length, 'widgets');
    }

    /**
     * 保存部件排序顺序
     */
    async function saveWidgetSortOrder(sourceLayoutId, targetLayoutId, insertBefore, slotId) {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        
        const iframeDoc = iframe.contentDocument;
        const sourceEl = iframeDoc.querySelector(`[data-layout-id="${sourceLayoutId}"]`);
        const targetEl = iframeDoc.querySelector(`[data-layout-id="${targetLayoutId}"]`);
        
        if (!sourceEl || !targetEl) return;
        
        // 先在 DOM 中移动
        if (insertBefore) {
            targetEl.parentNode.insertBefore(sourceEl, targetEl);
        } else {
            targetEl.parentNode.insertBefore(sourceEl, targetEl.nextSibling);
        }
        
        // 收集新的排序顺序
        const slot = targetEl.parentNode;
        const widgets = slot.querySelectorAll('[data-layout-id]');
        const sortData = {};
        
        widgets.forEach((widget, index) => {
            const layoutId = widget.getAttribute('data-layout-id');
            sortData[layoutId] = index;
        });
        
        try {
            // 调用后端 API 保存排序
            const response = await fetch(config.apiBase + '/update-sort', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    sort_data: sortData
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast('排序已保存', 'success');
                
                // 更新移动按钮状态
                updateSiblingMoveButtons(slotId);
            } else {
                // 保存失败，恢复原位置
                loadLayoutPreview();
                showToast(result.message || '排序保存失败', 'error');
            }
        } catch (err) {
            console.error('[ThemeEditor] Save sort order error:', err);
            loadLayoutPreview();
            showToast('保存排序时发生错误', 'error');
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
     * 清除插槽选中状态
     */
    function clearSlotSelection() {
        if (state.selectedSlot) {
            state.selectedSlot = null;
            restoreWidgetOrder();
        }
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

        // 获取部件参数定义（传递页面类型以获取适用的部件）
        try {
            const response = await fetch(getWidgetsApiUrl());
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
        let widgetConfig = {};

        try {
            widgetConfig = JSON.parse(widgetElement.dataset.config || '{}');
        } catch (e) {
            widgetConfig = {};
        }

        // 获取部件参数定义（传递页面类型以获取适用的部件）
        try {
            const response = await fetch(getWidgetsApiUrl());
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
                        config: widgetConfig,
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
            <div class="widget-config-panel">
                <div class="config-header">
                    <div class="config-widget-info">
                        <div class="widget-icon">
                            <i class="${icon}"></i>
                        </div>
                        <div class="widget-meta">
                            <h4 class="widget-name">${escapeHtml(widgetName)}</h4>
                            <p class="widget-desc">${escapeHtml(widgetDesc)}</p>
                        </div>
                    </div>
                    <div class="config-lang-switcher">
                        <select class="form-select form-select-sm" id="configLangSwitcher" data-widget-layout-id="${widget.layout_id}">
                            <option value="">默认（全语言）</option>
                            <option value="zh_Hans_CN">简体中文</option>
                            <option value="en_US">English</option>
                        </select>
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
                    <div class="config-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-line"></i> 保存配置
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-reset">
                            <i class="ri-restart-line"></i> 重置
                        </button>
                    </div>
                </form>
            </div>
        `;

        elements.configContent.innerHTML = html;
        
        // 绑定语言切换器事件
        const langSwitcher = document.getElementById('configLangSwitcher');
        if (langSwitcher) {
            langSwitcher.addEventListener('change', async function() {
                const locale = this.value || null;
                const layoutId = this.dataset.widgetLayoutId;
                // 重新加载配置（带locale参数）
                await reloadWidgetConfigWithLocale(layoutId, locale);
            });
        }
    }

    /**
     * 加载字段的多语言值
     */
    async function loadI18nValues(layoutId, fieldKey, panel) {
        const locales = ['zh_Hans_CN', 'en_US'];
        
        for (const locale of locales) {
            try {
                const apiUrl = `${config.apiBase}/widget-config?layout_id=${layoutId}&locale=${locale}`;
                const response = await fetch(apiUrl);
                const result = await response.json();
                
                if (result.success && result.data && result.data.config) {
                    const value = result.data.config[fieldKey] || '';
                    const input = panel.querySelector(`.i18n-input[data-locale="${locale}"][data-field="${fieldKey}"]`);
                    if (input) {
                        input.value = value;
                    }
                }
            } catch (err) {
                console.error(`Load i18n ${locale} error:`, err);
            }
        }
    }
    
    /**
     * 保存字段的多语言值
     */
    async function saveI18nValues(layoutId, fieldKey, panel) {
        const inputs = panel.querySelectorAll('.i18n-input');
        let successCount = 0;
        
        for (const input of inputs) {
            const locale = input.dataset.locale;
            const value = input.value;
            
            try {
                const apiUrl = `${config.apiBase}/save-widget-config`;
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        layout_id: layoutId,
                        config: { [fieldKey]: value },
                        locale: locale
                    })
                });
                const result = await response.json();
                if (result.success) successCount++;
            } catch (err) {
                console.error(`Save i18n ${locale} error:`, err);
            }
        }
        
        if (successCount > 0) {
            showToast(`已保存 ${successCount} 种语言的翻译`, 'success');
        } else {
            showToast('保存失败', 'error');
        }
    }
    
    /**
     * 重新加载部件配置（支持多语言）
     * @param {string} layoutId 布局ID
     * @param {string|null} locale 语言代码，null表示默认语言
     */
    async function reloadWidgetConfigWithLocale(layoutId, locale) {
        if (!layoutId) return;
        
        try {
            const apiUrl = `${config.apiBase}/widget-config?layout_id=${layoutId}${locale ? '&locale=' + locale : ''}`;
            const response = await fetch(apiUrl);
            const result = await response.json();
            
            if (result.success && result.data) {
                const widgetData = result.data;
                const params = widgetData.params || {};
                const widgetConfig = widgetData.config || {};
                
                // 更新表单中的值
                const form = document.getElementById('widgetConfigForm');
                if (form) {
                    for (const [key, value] of Object.entries(widgetConfig)) {
                        const input = form.querySelector(`[name="${key}"]`);
                        if (input) {
                            if (input.type === 'checkbox') {
                                input.checked = !!value;
                            } else {
                                input.value = value || '';
                            }
                        }
                    }
                }
                
                showToast(locale ? `已切换到 ${locale} 语言` : '已切换到默认语言', 'success');
            } else {
                showToast('加载配置失败', 'error');
            }
        } catch (err) {
            console.error('Reload config error:', err);
            showToast('加载配置失败', 'error');
        }
    }
    
    /**
     * 保存部件配置（支持多语言）
     * @param {number} layoutId 布局ID
     * @param {object} configData 配置数据
     * @param {string|null} locale 语言代码，null表示保存为默认值
     */
    async function saveWidgetConfigWithLocale(layoutId, configData, locale) {
        try {
            const apiUrl = `${config.apiBase}/save-widget-config`;
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: configData,
                    locale: locale
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast(result.message || '配置已保存', 'success');
                return true;
            } else {
                showToast(result.message || '保存失败', 'error');
                return false;
            }
        } catch (err) {
            console.error('Save config error:', err);
            showToast('保存失败', 'error');
            return false;
        }
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
                // 使用返回的 preview_html 更新预览（如果有）
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                }
                // 注意：不再调用 refreshPreview()
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
                // 使用返回的 preview_html 更新预览（如果有）
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                }
                // 注意：不再调用 refreshPreview()
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
        const confirmed = await showCustomConfirm(
            '确认删除部件？',
            '确定要删除此部件吗？',
            '确认删除',
            '取消'
        );
        if (!confirmed) {
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
                // 从 iframe 中移除对应部件
                removeWidgetFromIframe(layoutId);
                // 注意：不再调用 refreshPreview()
            } else {
                showToast(result.message || '删除失败', 'error');
            }
        } catch (err) {
            console.error('Delete widget error:', err);
            showToast('删除部件失败', 'error');
        }
    }

    /**
     * 从 iframe 中移除指定部件
     */
    function removeWidgetFromIframe(layoutId) {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;

        try {
            const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
            if (widgetEl) {
                widgetEl.remove();
            }
        } catch (err) {
            // iframe 跨域或其他错误，静默忽略
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

        const confirmed = await showCustomConfirm(
            '确认发布主题？',
            '确定要发布此主题吗？发布后将生成缓存文件。',
            '确认发布',
            '取消'
        );
        if (!confirmed) {
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
     * 打开新窗口预览（草稿预览）
     * 
     * 使用 preview_mode=1 参数，让前台以草稿模式渲染
     * 用户可以在新窗口中预览未发布的更改
     */
    function openPreview() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        // 使用 preview_mode=1 参数以读取草稿数据（前台预览）
        // 可以额外添加 status 参数来明确指定版本
        const status = state.previewStatus || 'draft';
        window.open(`${config.apiPreview}?theme_id=${state.themeId}&page_type=${state.pageType}&preview_mode=1&status=${status}`, '_blank');
    }
    
    /**
     * 打开新窗口预览已发布版本
     */
    function openPublishedPreview() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        // 使用 status=published 明确指定查看已发布版本
        window.open(`${config.apiPreview}?theme_id=${state.themeId}&page_type=${state.pageType}&status=published`, '_blank');
    }
    
    /**
     * 切换编辑器预览版本（draft/published）
     * 
     * @param {string} status - 'draft' 或 'published'
     */
    function switchPreviewStatus(status) {
        if (status !== 'draft' && status !== 'published') {
            console.warn('无效的预览状态:', status);
            return;
        }
        
        state.previewStatus = status;
        
        // 刷新编辑器 iframe 预览
        refreshPreview();
        
        // 更新 UI 状态指示
        updatePreviewStatusUI(status);
        
        showToast(status === 'draft' ? '已切换到草稿版本' : '已切换到已发布版本', 'info');
    }
    
    /**
     * 更新预览状态 UI 指示
     */
    function updatePreviewStatusUI(status) {
        const statusIndicator = document.getElementById('previewStatusIndicator');
        if (statusIndicator) {
            statusIndicator.textContent = status === 'draft' ? '草稿' : '已发布';
            statusIndicator.className = `preview-status-indicator status-${status}`;
        }
        
        // 更新切换按钮状态
        document.querySelectorAll('.preview-status-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.status === status);
        });
    }
    
    /**
     * 获取当前预览状态
     */
    function getPreviewStatus() {
        return state.previewStatus;
    }

    /**
     * 过滤部件（按关键字）
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
     * 按区域过滤部件
     * 当选中一个区域时，只显示可以放置到该区域的部件
     * @param {string|null} areaCode 区域代码（如 header, content, footer），null 表示显示全部
     * @param {Array} rejectTypes 该区域拒绝的部件类型（从 data-wslot-reject 获取）
     */
    function filterWidgetsByArea(areaCode, rejectTypes = []) {
        // 如果没有指定区域，显示所有部件
        if (!areaCode) {
            document.querySelectorAll('.widget-item.draggable').forEach(item => {
                item.style.display = '';
                item.classList.remove('area-matched', 'area-universal', 'area-not-matched', 'area-rejected');
            });
            document.querySelectorAll('.widget-group').forEach(group => {
                group.style.display = '';
                group.classList.remove('collapsed');
            });
            return;
        }

        // 区域互斥规则：每个区域不接受哪些类型的部件
        // content 区域不接受 header 和 footer 类型
        // header 区域不接受 footer 和 content 特定类型
        // footer 区域不接受 header 和 content 特定类型
        const areaExclusiveTypes = {
            'content': ['header', 'footer'],      // content 区域排除 header、footer 类型
            'header': ['footer'],                  // header 区域排除 footer 类型
            'footer': ['header'],                  // footer 区域排除 header 类型
        };

        // 类型到允许区域的映射（与后端 WidgetPositionResolver::inferAreasFromType 保持一致）
        const typeToAreasMap = {
            'header': ['header'],
            'footer': ['footer'],
            'sidebar': ['left_sidebar', 'right_sidebar', 'content'],
            'banner': ['banner', 'content'],
            'carousel': ['banner', 'content'],
            'slider': ['banner', 'content'],
            'product': ['content', 'left_sidebar', 'right_sidebar'],
            'category': ['content', 'left_sidebar', 'right_sidebar'],
            'navigation': ['header', 'left_sidebar'],
            'search': ['header', 'content'],
            'breadcrumb': ['content'],
            'pagination': ['content'],
            'social': ['footer', 'left_sidebar', 'right_sidebar'],
            'newsletter': ['footer', 'left_sidebar', 'right_sidebar'],
            'testimonial': ['content'],
            'faq': ['content'],
            'video': ['content', 'banner'],
            'content': ['content', 'left_sidebar', 'right_sidebar'],
        };
        
        // 位置到区域的映射（与后端 POSITION_TO_AREA_MAP 保持一致）
        const positionToAreaMap = {
            'header': ['header'],
            'banner': ['banner'],
            'sidebar': ['left_sidebar', 'right_sidebar'],
            'left_sidebar': ['left_sidebar'],
            'right_sidebar': ['right_sidebar'],
            'content': ['content', 'banner'],
            'footer': ['footer'],
        };
        
        // 合并传入的拒绝类型和区域互斥规则
        const finalRejectTypes = [...rejectTypes];
        if (areaExclusiveTypes[areaCode]) {
            areaExclusiveTypes[areaCode].forEach(type => {
                if (!finalRejectTypes.includes(type)) {
                    finalRejectTypes.push(type);
                }
            });
        }
        
        let hasMatchInGroup = new Map();

        document.querySelectorAll('.widget-item.draggable').forEach(item => {
            let positionsStr = item.dataset.widgetPosition || '[]';
            let widgetPositions = [];
            const widgetType = item.dataset.widgetType || '';
            const widgetCode = item.dataset.widgetCode || '';
            
            try {
                widgetPositions = JSON.parse(positionsStr);
            } catch (e) {
                widgetPositions = [];
            }

            // 判断部件是否可以放置到该区域（使用与 isAllowedArea 相同的逻辑）
            let allowedAreas = [];
            let isUniversal = false;
            let isExactMatch = false;
            
            if (!widgetPositions || widgetPositions.length === 0) {
                // 没有 position 限制，根据 type 推断
                if (widgetType && typeToAreasMap[widgetType]) {
                    allowedAreas = typeToAreasMap[widgetType];
                } else {
                    // 如果没有类型信息，默认只允许 content 区域
                    allowedAreas = ['content'];
                }
            } else if (widgetPositions.includes('*') || widgetPositions.includes('all')) {
                // 通配符，允许所有
                isUniversal = true;
                allowedAreas = ['header', 'content', 'footer', 'left_sidebar', 'right_sidebar', 'banner'];
            } else {
                // 收集部件允许放置的所有区域
                widgetPositions.forEach(pos => {
                    if (positionToAreaMap[pos]) {
                        allowedAreas = allowedAreas.concat(positionToAreaMap[pos]);
                    }
                });
                allowedAreas = [...new Set(allowedAreas)]; // 去重
                isExactMatch = true;
            }
            
            // 检查部件类型是否被当前区域拒绝
            const isTypeRejected = finalRejectTypes.includes(widgetType);
            
            // 只有当区域允许且类型未被拒绝时才能放置
            const canPlace = allowedAreas.includes(areaCode) && !isTypeRejected;

            // 清除所有匹配相关的类
            item.classList.remove('area-matched', 'area-universal', 'area-not-matched', 'area-rejected');

            if (isTypeRejected) {
                // 类型被拒绝，隐藏部件
                item.style.display = 'none';
                item.classList.add('area-rejected');
            } else if (canPlace) {
                item.style.display = '';
                if (isExactMatch && !isUniversal) {
                    // 精确匹配：推荐，带呼吸高亮动画
                    item.classList.add('area-matched');
                } else if (isUniversal) {
                    // 通用部件：可用但不是首选
                    item.classList.add('area-universal');
                }
            } else {
                item.style.display = 'none';
                item.classList.add('area-not-matched');
            }

            // 记录分组是否有匹配的部件
            const group = item.closest('.widget-group');
            if (group) {
                const groupType = group.dataset.type || '';
                if (canPlace) {
                    hasMatchInGroup.set(groupType, true);
                } else if (!hasMatchInGroup.has(groupType)) {
                    hasMatchInGroup.set(groupType, false);
                }
            }
        });

        // 隐藏没有匹配部件的分组，展开有匹配的分组
        document.querySelectorAll('.widget-group').forEach(group => {
            const groupType = group.dataset.type || '';
            const hasMatch = hasMatchInGroup.get(groupType);
            
            // 如果整个分组类型在拒绝列表中，直接隐藏整个分组
            const isGroupRejected = finalRejectTypes.includes(groupType);
            
            if (isGroupRejected) {
                group.style.display = 'none';
            } else if (hasMatch) {
                group.style.display = '';
                group.classList.remove('collapsed'); // 自动展开
            } else {
                group.style.display = 'none';
            }
        });
        
        console.log('[filterWidgetsByArea] Area:', areaCode, 'Reject types:', finalRejectTypes);
    }

    /**
     * 选中区域
     * @param {HTMLElement} areaElement 区域元素
     */
    /**
     * 滚动部件面板到匹配的部件位置
     * @param {string} areaCode 区域代码
     */
    function scrollToMatchedWidgets(areaCode) {
        // 滚动部件面板到第一个匹配的部件位置
        setTimeout(() => {
            const widgetPanelContent = document.querySelector('#widgetPanel .panel-content');
            if (!widgetPanelContent) return;
            
            // 找到第一个可见的匹配部件（area-matched 或 area-universal）
            const firstMatchedWidget = widgetPanelContent.querySelector('.widget-item.area-matched, .widget-item.area-universal');
            
            if (firstMatchedWidget) {
                // 获取部件所在的组
                const widgetGroup = firstMatchedWidget.closest('.widget-group');
                
                if (widgetGroup) {
                    // 计算滚动位置（组的顶部位置 - 一些间距）
                    const groupTop = widgetGroup.offsetTop;
                    const scrollOffset = Math.max(0, groupTop - 20); // 留出20px间距
                    
                    widgetPanelContent.scrollTo({ 
                        top: scrollOffset, 
                        behavior: 'smooth' 
                    });
                }
            } else {
                // 如果没有找到匹配的部件，滚动到顶部
                widgetPanelContent.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }, 100); // 延迟100ms等待DOM更新和样式应用完成
    }

    function selectArea(areaElement) {
        const areaCode = areaElement.dataset.area;
        const areaName = areaElement.querySelector('.area-label')?.textContent || areaCode;

        // 如果点击的是已选中的区域，只执行滚动，不取消选中
        if (state.selectedArea === areaCode) {
            // 直接滚动到该区域的部件
            scrollToMatchedWidgets(areaCode);
            showToast(`已滚动到 "${areaName}" 区域部件`, 'info');
            return;
        }

        // 移除其他区域的选中状态
        document.querySelectorAll('.preview-area.area-selected').forEach(el => {
            el.classList.remove('area-selected');
        });

        // 选中当前区域
        areaElement.classList.add('area-selected');
        state.selectedArea = areaCode;

        // 按区域过滤部件
        filterWidgetsByArea(areaCode);

        // 滚动到匹配的部件
        scrollToMatchedWidgets(areaCode);

        // 更新部件面板标题（如果有）
        const widgetPanelTitle = document.querySelector('.widget-panel-title, .editor-widget-panel .panel-header h3');
        if (widgetPanelTitle) {
            widgetPanelTitle.innerHTML = `<i class="ri-apps-line"></i> 部件库 <span class="area-filter-badge" onclick="window.ThemeEditor?.deselectArea?.()">${areaName} <i class="ri-close-line" style="margin-left:4px;cursor:pointer;"></i></span>`;
        }

        // 显示提示
        showToast(`已筛选 "${areaName}" 区域的部件`, 'info');

        console.log('[ThemeEditor] Area selected:', areaCode, '- showing compatible widgets');
    }

    /**
     * 取消区域选中
     */
    function deselectArea() {
        // 移除区域选中状态
        document.querySelectorAll('.preview-area.area-selected').forEach(el => {
            el.classList.remove('area-selected');
        });
        state.selectedArea = null;

        // 显示所有部件
        filterWidgetsByArea(null);

        // 恢复部件面板标题
        const widgetPanelTitle = document.querySelector('.widget-panel-title, .editor-widget-panel .panel-header h3');
        if (widgetPanelTitle) {
            widgetPanelTitle.innerHTML = `<i class="ri-apps-line"></i> 部件库`;
        }
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
     * 自定义确认对话框（替代原生 confirm）
     */
    function showCustomConfirm(title, message, confirmText = '确认', cancelText = '取消') {
        return new Promise((resolve) => {
            const dialog = document.createElement('div');
            dialog.className = 'custom-confirm-dialog';
            dialog.innerHTML = `
                <div class="custom-confirm-overlay"></div>
                <div class="custom-confirm-box">
                    <h4 class="custom-confirm-title">${escapeHtml(title)}</h4>
                    <p class="custom-confirm-message">${escapeHtml(message)}</p>
                    <div class="custom-confirm-buttons">
                        <button class="btn btn-secondary btn-cancel">${escapeHtml(cancelText)}</button>
                        <button class="btn btn-primary btn-confirm">${escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;
            
            dialog.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
            `;
            
            // 添加样式
            const style = document.createElement('style');
            style.textContent = `
                .custom-confirm-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                    animation: fadeIn 0.2s ease;
                }
                .custom-confirm-box {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #fff;
                    border-radius: 8px;
                    padding: 24px;
                    min-width: 400px;
                    max-width: 500px;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                    animation: slideDown 0.3s ease;
                }
                .custom-confirm-title {
                    margin: 0 0 12px 0;
                    font-size: 18px;
                    font-weight: 600;
                    color: #333;
                }
                .custom-confirm-message {
                    margin: 0 0 20px 0;
                    font-size: 14px;
                    color: #666;
                    line-height: 1.5;
                }
                .custom-confirm-buttons {
                    display: flex;
                    gap: 10px;
                    justify-content: flex-end;
                }
                .custom-confirm-buttons .btn {
                    padding: 8px 20px;
                    border-radius: 6px;
                    border: none;
                    cursor: pointer;
                    font-size: 14px;
                    transition: all 0.2s;
                }
                .custom-confirm-buttons .btn-cancel {
                    background: #6c757d;
                    color: #fff;
                }
                .custom-confirm-buttons .btn-cancel:hover {
                    background: #5a6268;
                }
                .custom-confirm-buttons .btn-confirm {
                    background: #007bff;
                    color: #fff;
                }
                .custom-confirm-buttons .btn-confirm:hover {
                    background: #0056b3;
                }
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes slideDown {
                    from { transform: translate(-50%, -60%); opacity: 0; }
                    to { transform: translate(-50%, -50%); opacity: 1; }
                }
            `;
            dialog.appendChild(style);
            
            document.body.appendChild(dialog);
            
            // 绑定按钮事件
            const btnConfirm = dialog.querySelector('.btn-confirm');
            const btnCancel = dialog.querySelector('.btn-cancel');
            
            const cleanup = () => {
                dialog.style.opacity = '0';
                setTimeout(() => dialog.remove(), 200);
            };
            
            btnConfirm.addEventListener('click', () => {
                cleanup();
                resolve(true);
            });
            
            btnCancel.addEventListener('click', () => {
                cleanup();
                resolve(false);
            });
            
            // 点击遮罩层关闭
            dialog.querySelector('.custom-confirm-overlay').addEventListener('click', () => {
                cleanup();
                resolve(false);
            });
        });
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
     * 恢复原始布局
     */
    async function handleRestoreLayout() {
        // 显示确认对话框
        const confirmed = await showCustomConfirm(
            '确认恢复原始布局？',
            '此操作将清除当前所有未发布的修改，恢复到已发布的原始布局。此操作不可撤销。',
            '确认恢复',
            '取消'
        );

        if (!confirmed) {
            return;
        }

        try {
            showToast('正在恢复原始布局...', 'info');

            const response = await fetch(`${config.apiBase}/restore-layout`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    page_type: state.pageType,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '已恢复到原始布局', 'success');
                
                // 刷新预览
                setTimeout(() => {
                    refreshPreview();
                }, 500);
            } else {
                showToast(result.message || '恢复失败', 'error');
            }
        } catch (error) {
            console.error('[ThemeEditor] Restore layout error:', error);
            showToast('恢复失败：' + error.message, 'error');
        }
    }

    /**
     * 刷新预览（仅用于手动刷新按钮）
     * 
     * 注意：此函数只刷新 iframe，不会发起额外的 fetch 请求。
     * 部件的添加/修改/删除通过 updateWidgetPreviewInIframe() / removeWidgetFromIframe() 处理。
     */
    function refreshPreview() {
        if (!elements.previewFrame || !state.themeId) {
            return;
        }
        // 只在用户手动点击刷新按钮时重新加载 iframe
        fullReloadPreview();
    }

    /**
     * 批量合并刷新请求 - 已禁用，不再触发 layout-preview 请求
     */
    const schedulePreviewRefresh = debounce(() => {
        // 禁用：不再自动刷新，避免重复请求 layout-preview
        console.log('[ThemeEditor] schedulePreviewRefresh disabled - use manual refresh button');
    }, 150);

    /**
     * 完整刷新 iframe（仅手动刷新按钮使用）
     */
    function fullReloadPreview() {
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
        // 使用 editor_mode=1 标识后台编辑器 iframe
        url.searchParams.set('editor_mode', '1');
        // 支持版本切换：默认 draft，可通过 state.previewStatus 切换
        url.searchParams.set('status', state.previewStatus || 'draft');
        elements.previewFrame.src = url.toString();
    }

    /**
     * 设置 iframe 内链接拦截
     * 将内部链接转换为预览模式 URL，使点击后仍在编辑器内预览
     */
    function setupIframeLinkInterception() {
        const iframe = elements.previewFrame;
        if (!iframe) return;
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
            if (!iframeDoc) {
                console.warn('[ThemeEditor] Cannot access iframe document for link interception');
                return;
            }
            
            // 获取当前站点的基础 URL
            const currentOrigin = window.location.origin;
            const baseUrl = config.apiBase?.replace(/\/theme\/backend\/theme-editor.*$/, '') || '';
            
            // 拦截所有链接点击
            iframeDoc.addEventListener('click', function(e) {
                const link = e.target.closest('a');
                if (!link) return;
                
                const href = link.getAttribute('href');
                if (!href) return;
                
                // 跳过 JavaScript 链接和锚点
                if (href.startsWith('#') || href.startsWith('javascript:')) {
                    return;
                }
                
                e.preventDefault();
                e.stopPropagation();
                
                // 判断是否为内部链接
                let targetUrl;
                try {
                    targetUrl = new URL(href, iframeDoc.baseURI);
                } catch (err) {
                    console.warn('[ThemeEditor] Invalid URL:', href);
                    return;
                }
                
                // 外部链接 - 在新标签页打开
                if (targetUrl.origin !== currentOrigin) {
                    window.open(href, '_blank');
                    showToast('外部链接已在新标签页打开', 'info');
                    return;
                }
                
                // 内部链接 - 转换为预览模式 URL
                // 根据目标路径判断页面类型（pageType = layoutType = 布局目录名）
                const pathname = targetUrl.pathname;
                let pageType = 'homepage';
                let layoutType = 'homepage';
                
                // 路径到页面类型的映射（使用布局目录名）
                if (pathname === '/' || pathname === '' || pathname.endsWith('/index')) {
                    pageType = 'homepage';
                    layoutType = 'homepage';
                } else if (pathname.includes('/category/') || pathname.includes('/catalog/')) {
                    pageType = 'category';
                    layoutType = 'category';
                } else if (pathname.includes('/product/')) {
                    pageType = 'product';
                    layoutType = 'product';
                } else if (pathname.includes('/cart')) {
                    pageType = 'cart';
                    layoutType = 'cart';
                } else if (pathname.includes('/checkout')) {
                    pageType = 'checkout';
                    layoutType = 'checkout';
                } else if (pathname.includes('/account') || pathname.includes('/customer')) {
                    pageType = 'account';
                    layoutType = 'account';
                } else if (pathname.includes('/search')) {
                    pageType = 'search';
                    layoutType = 'search';
                } else {
                    // CMS 或其他页面
                    pageType = 'cms_page';
                    layoutType = 'cms_page';
                }
                
                // 构建预览 URL
                const previewUrl = new URL(config.apiLayoutPreview, currentOrigin);
                previewUrl.searchParams.set('theme_id', state.themeId);
                previewUrl.searchParams.set('layout_type', layoutType);
                previewUrl.searchParams.set('layout_option', 'default');
                previewUrl.searchParams.set('editor_mode', '1');
                previewUrl.searchParams.set('status', state.previewStatus || 'draft');
                previewUrl.searchParams.set('_t', Date.now());
                
                // 更新状态
                state.pageType = pageType;
                state.layoutType = layoutType;
                
                // 更新 iframe
                iframe.src = previewUrl.toString();
                
                // 更新页面类型选择器（如果有）
                if (elements.pageTypeSelect) {
                    elements.pageTypeSelect.value = pageType;
                }
                
                showToast(`已切换到 ${pageType} 布局预览`, 'info');
                console.log('[ThemeEditor] Link intercepted:', href, '-> Preview:', previewUrl.toString());
            }, true); // 使用捕获阶段
            
            // 添加编辑器模式的视觉提示
            iframeDoc.body?.classList.add('editor-mode');
            
            console.log('[ThemeEditor] Link interception setup complete');
        } catch (err) {
            console.warn('[ThemeEditor] Error setting up link interception:', err.message);
        }
    }

    /**
     * 加载布局预览（编译后的页面）
     * 
     * 后台编辑器 iframe 使用 editor_mode=1 参数，
     * 支持通过 status 参数切换 draft/published 版本
     */
    function loadLayoutPreview() {
        if (!elements.previewFrame || !state.themeId) {
            return;
        }

        // 显示加载状态
        if (elements.previewLoading) {
            elements.previewLoading.classList.remove('hidden');
        }

        // 构建预览 URL
        const url = new URL(config.apiLayoutPreview, window.location.origin);
        url.searchParams.set('theme_id', state.themeId);
        url.searchParams.set('layout_type', state.layoutType);
        url.searchParams.set('layout_option', state.layoutOption);
        url.searchParams.set('_t', Date.now());
        // 使用 editor_mode=1 标识后台编辑器 iframe
        url.searchParams.set('editor_mode', '1');
        // 支持版本切换：默认 draft，可通过 state.previewStatus 切换
        url.searchParams.set('status', state.previewStatus || 'draft');

        elements.previewFrame.src = url.toString();

        // 同时获取插槽信息
        fetchLayoutSlots();
    }

    /**
     * 获取布局的插槽信息
     */
    async function fetchLayoutSlots() {
        if (!state.themeId) return;

        try {
            const url = new URL(config.apiCompileLayout, window.location.origin);
            url.searchParams.set('theme_id', state.themeId);
            url.searchParams.set('layout_type', state.layoutType);
            url.searchParams.set('layout_option', state.layoutOption);

            const response = await fetch(url);
            const result = await response.json();

            if (result.success && result.slots) {
                state.slots = result.slots;
                renderSlotsInfo(result.slots);
                
                // 显示插槽面板
                if (elements.slotsInfoPanel) {
                    elements.slotsInfoPanel.classList.add('active');
                }
            }
        } catch (err) {
            console.error('获取插槽信息失败:', err);
        }
    }

    /**
     * 渲染插槽信息列表
     */
    function renderSlotsInfo(slots) {
        if (!elements.slotsInfoList) return;

        let html = '';
        for (const [slotId, slot] of Object.entries(slots)) {
            const acceptTags = (slot.accept || []).map(code => 
                `<span>${escapeHtml(code)}</span>`
            ).join('');

            html += `
                <div class="slot-info-item" data-slot-id="${escapeHtml(slotId)}" onclick="scrollToSlot('${escapeHtml(slotId)}')">
                    <div class="slot-name">${escapeHtml(slot.name || slotId)}</div>
                    <div class="slot-accept">${acceptTags || '<span>任意部件</span>'}</div>
                </div>
            `;
        }

        elements.slotsInfoList.innerHTML = html || '<p class="text-muted text-center py-3">暂无插槽</p>';
    }

    /**
     * 滚动到指定插槽（iframe 内）
     */
    window.scrollToSlot = function(slotId) {
        if (!elements.previewFrame || !elements.previewFrame.contentWindow) return;

        try {
            const iframeDoc = elements.previewFrame.contentDocument || elements.previewFrame.contentWindow.document;
            const slotElement = iframeDoc.querySelector(`[data-slot-id="${slotId}"]`);
            
            if (slotElement) {
                slotElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // 高亮插槽
                slotElement.classList.add('slot-highlight');
                setTimeout(() => slotElement.classList.remove('slot-highlight'), 2000);
            }
        } catch (err) {
            console.error('无法访问 iframe 内容:', err);
        }
    };
    
    /**
     * 全局暴露：切换预览版本
     * 可从 UI 按钮调用：onclick="switchPreviewStatus('draft')" 或 onclick="switchPreviewStatus('published')"
     */
    window.switchPreviewStatus = switchPreviewStatus;
    
    /**
     * 全局暴露：打开草稿预览（新窗口）
     */
    window.openPreview = openPreview;
    
    /**
     * 全局暴露：打开已发布版本预览（新窗口）
     */
    window.openPublishedPreview = openPublishedPreview;
    
    /**
     * 全局暴露：获取当前预览状态
     */
    window.getPreviewStatus = getPreviewStatus;

    /**
     * 全局暴露：取消区域选中（用于清除部件过滤）
     */
    window.ThemeEditor = window.ThemeEditor || {};
    window.ThemeEditor.deselectArea = deselectArea;
    window.ThemeEditor.selectArea = selectArea;
    window.ThemeEditor.filterWidgetsByArea = filterWidgetsByArea;

    // 初始化
    document.addEventListener('DOMContentLoaded', init);
})();
