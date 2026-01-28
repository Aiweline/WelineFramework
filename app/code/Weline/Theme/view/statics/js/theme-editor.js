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
        config.apiCompileLayout = container.dataset.apiCompileLayout || `${config.apiBase}/compile-layout`;
        config.apiLayoutPreview = container.dataset.apiLayoutPreview || `${config.apiBase}/layout-preview`;
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
            layoutTypeSelect: document.getElementById('layoutTypeSelect'),
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
            btnRefreshPreview: document.getElementById('btnRefreshPreview'),
            btnFullscreenPreview: document.getElementById('btnFullscreenPreview'),
        };

        // 当前布局信息
        state.layoutType = 'homepage';
        state.layoutOption = 'default';
        state.slots = {}; // 页面插槽信息

        // 绑定事件
        bindEvents();

        // 初始化拖拽
        initDragAndDrop();

        // 适配部件库预览缩放 & 渲染为 canvas
        renderWidgetPreviewsToCanvas();
        fitWidgetPreviews();
        window.addEventListener('resize', debounce(() => {
            fitWidgetPreviews();
            updateCanvasPreviewSizes();
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

        // 布局类型选择
        if (elements.layoutTypeSelect) {
            elements.layoutTypeSelect.addEventListener('change', function() {
                state.layoutType = this.value;
                loadLayoutPreview();
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
            elements.previewFrame.addEventListener('load', function() {
                if (elements.previewLoading) {
                    elements.previewLoading.classList.add('hidden');
                }
                
                // 设置 iframe 内链接拦截，使链接跳转到预览模式
                setupIframeLinkInterception();
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
     * 将部件预览渲染为 Canvas，避免弹窗等副作用
     * 
     * 修复：html2canvas 会尝试加载 iframe 等外部资源，导致大量 layout-preview 请求
     * 解决方案：在转换前清理 HTML，移除所有 iframe 和外部链接
     */
    function renderWidgetPreviewsToCanvas() {
        if (!window.html2canvas) {
            return;
        }

        const canvases = document.querySelectorAll('.widget-preview-canvas');
        canvases.forEach(async (canvas) => {
            if (canvas.dataset.canvasRendered === '1') {
                return;
            }

            const html = canvas.innerHTML.trim();
            if (!html) {
                return;
            }

            const containerWidth = canvas.clientWidth || 0;
            const temp = document.createElement('div');
            temp.style.position = 'absolute';
            temp.style.left = '-99999px';
            temp.style.top = '0';
            temp.style.width = (containerWidth || 320) + 'px';
            temp.style.background = 'transparent';
            
            // 清理 HTML：移除所有 iframe、外部链接等，避免 html2canvas 加载外部资源
            const cleanedHtml = cleanPreviewHtmlForCanvas(html);
            temp.innerHTML = cleanedHtml;
            document.body.appendChild(temp);

            try {
                const rendered = await window.html2canvas(temp, {
                    backgroundColor: null,
                    scale: 2,
                    useCORS: false, // 禁用 CORS，避免加载外部资源
                    allowTaint: true, // 允许跨域图片（但我们已经移除了外部资源）
                    logging: false, // 禁用日志
                    // 克隆后移除梯度样式，避免 addColorStop 收到非有限值导致报错
                    onclone: (clonedDoc, clonedNode) => {
                        stripGradientsFromClone(clonedNode, clonedDoc);
                    },
                    // 忽略 iframe 和其他可能导致外部请求的元素
                    ignoreElements: (element) => {
                        // 忽略所有 iframe
                        if (element.tagName === 'IFRAME') {
                            return true;
                        }
                        // 忽略包含 layout-preview 链接的元素
                        if (element.href && element.href.includes('layout-preview')) {
                            return true;
                        }
                        // 忽略包含外部 URL 的图片（可选，如果需要显示占位图）
                        if (element.tagName === 'IMG' && element.src && 
                            !element.src.startsWith('data:') && 
                            !element.src.startsWith(window.location.origin)) {
                            return false; // 保留本地图片
                        }
                        return false;
                    },
                });

                canvas.innerHTML = '';
                canvas.appendChild(rendered);
                canvas.dataset.canvasRendered = '1';

                rendered.style.width = '100%';
                rendered.style.height = 'auto';

                updateCanvasPreviewSize(canvas, rendered);
            } catch (err) {
                console.error('Canvas render error:', err);
                // 保持原 HTML 作为回退
            } finally {
                document.body.removeChild(temp);
            }
        });
    }

    /**
     * 清理预览 HTML，移除所有 iframe 和外部链接，避免 html2canvas 触发外部请求
     */
    function cleanPreviewHtmlForCanvas(html) {
        if (!html) return '';
        
        // 创建临时 DOM 来清理 HTML
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        
        // 移除所有 iframe
        tempDiv.querySelectorAll('iframe').forEach(iframe => {
            iframe.remove();
        });
        
        // 移除所有指向 layout-preview 的链接
        tempDiv.querySelectorAll('a[href*="layout-preview"]').forEach(link => {
            link.remove();
        });
        
        // 移除所有指向 layout-preview 的图片 src
        tempDiv.querySelectorAll('img[src*="layout-preview"]').forEach(img => {
            img.remove();
        });
        
        // 移除所有 script 标签（避免执行）
        tempDiv.querySelectorAll('script').forEach(script => {
            script.remove();
        });
        
        return tempDiv.innerHTML;
    }

    /**
     * 在克隆文档中移除所有梯度相关样式，避免 html2canvas 解析时 addColorStop 收到
     * 非有限值（NaN/Infinity）导致 "The provided double value is non-finite" 报错。
     * 同时检查内联样式与计算样式（来自样式表的 gradient）。
     */
    function stripGradientsFromClone(clonedNode, clonedDoc) {
        if (!clonedNode) return;
        const collect = [];
        if (clonedNode.nodeType === 1) collect.push(clonedNode);
        const q = clonedNode.querySelectorAll ? clonedNode.querySelectorAll('*') : [];
        for (let i = 0; i < q.length; i++) collect.push(q[i]);
        const hasGradient = (v) => typeof v === 'string' && /gradient\s*\(/i.test(v);
        const win = (clonedDoc && clonedDoc.defaultView) || null;
        const getComputed = win && typeof win.getComputedStyle === 'function' ? win.getComputedStyle.bind(win) : null;
        collect.forEach(function (el) {
            const s = el.style;
            if (!s) return;
            let bg = s.background || s.backgroundImage || '';
            if (!hasGradient(bg) && getComputed) {
                try {
                    const cs = getComputed(el);
                    if (cs) bg = (cs.background || '') + (cs.backgroundImage || '');
                } catch (e) { /* ignore */ }
            }
            if (hasGradient(bg)) {
                s.background = 'transparent';
                s.backgroundImage = 'none';
            }
        });
    }

    /**
     * 更新 canvas 预览尺寸
     */
    function updateCanvasPreviewSizes() {
        document.querySelectorAll('.widget-preview-canvas canvas').forEach((canvasEl) => {
            const wrapper = canvasEl.parentElement;
            if (!wrapper) return;
            updateCanvasPreviewSize(wrapper, canvasEl);
        });
    }

    function updateCanvasPreviewSize(wrapper, canvasEl) {
        const wrapperWidth = wrapper.clientWidth || 0;
        if (!wrapperWidth || !canvasEl.width) {
            return;
        }
        const ratio = canvasEl.height / canvasEl.width;
        if (ratio && isFinite(ratio)) {
            wrapper.style.height = `${Math.round(wrapperWidth * ratio)}px`;
            wrapper.style.display = 'flex';
            wrapper.style.alignItems = 'center';
            wrapper.style.justifyContent = 'center';
        } else {
            wrapper.style.height = 'auto';
        }
    }

    /**
     * 适配部件库预览缩放（宽度100%，高度自适应）
     */
    function fitWidgetPreviews() {
        const canvases = document.querySelectorAll('.widget-preview-canvas');
        canvases.forEach(canvas => {
            const child = canvas.firstElementChild;
            if (!child) return;

            if (child.tagName && child.tagName.toLowerCase() === 'canvas') {
                updateCanvasPreviewSize(canvas, child);
                return;
            }

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

            if (naturalHeight) {
                canvas.style.height = `${naturalHeight * scale}px`;
                canvas.style.display = 'flex';
                canvas.style.alignItems = 'center';
                canvas.style.justifyContent = 'center';
            } else {
                canvas.style.height = 'auto';
            }
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
        const acceptCodes = slot.accept || [];
        highlightAcceptableWidgets(acceptCodes);
        
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
            const response = await fetch(`${config.apiBase}/widget-config?layout_id=${layoutId}`);
            const result = await response.json();
            
            if (result.success && result.data) {
                const widgetData = result.data;
                const params = widgetData.params || {};
                const widgetConfig = widgetData.config || {};
                
                // 生成配置表单
                const formHtml = generateWidgetConfigForm(layoutId, params, widgetConfig);
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
     */
    function generateWidgetConfigForm(layoutId, params, config) {
        if (!params || Object.keys(params).length === 0) {
            return `<div class="text-muted text-center py-3">
                <i class="ri-settings-3-line d-block mb-2" style="font-size: 24px; opacity: 0.5;"></i>
                <small>该部件无可配置项</small>
            </div>`;
        }
        
        let fieldsHtml = '';
        for (const [key, param] of Object.entries(params)) {
            const label = param.label || key;
            const type = param.type || 'text';
            const value = config[key] ?? param.default ?? '';
            const description = param.description || '';
            const fieldId = `config_${layoutId}_${key}`;
            
            fieldsHtml += `<div class="config-field mb-3">`;
            fieldsHtml += `<label class="form-label" for="${fieldId}">${label}</label>`;
            
            if (type === 'bool' || type === 'boolean') {
                fieldsHtml += `
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="${fieldId}" name="${key}" ${value ? 'checked' : ''}>
                    </div>
                `;
            } else if (type === 'select' && param.options) {
                fieldsHtml += `<select class="form-select form-select-sm" id="${fieldId}" name="${key}">`;
                for (const [optVal, optLabel] of Object.entries(param.options)) {
                    fieldsHtml += `<option value="${optVal}" ${value == optVal ? 'selected' : ''}>${optLabel}</option>`;
                }
                fieldsHtml += `</select>`;
            } else if (type === 'textarea' || type === 'html') {
                fieldsHtml += `<textarea class="form-control form-control-sm" id="${fieldId}" name="${key}" rows="3">${value}</textarea>`;
            } else if (type === 'number') {
                fieldsHtml += `<input type="number" class="form-control form-control-sm" id="${fieldId}" name="${key}" value="${value}" min="${param.min || ''}" max="${param.max || ''}">`;
            } else if (type === 'color') {
                fieldsHtml += `<input type="color" class="form-control form-control-color" id="${fieldId}" name="${key}" value="${value || '#000000'}">`;
            } else {
                fieldsHtml += `<input type="text" class="form-control form-control-sm" id="${fieldId}" name="${key}" value="${value}">`;
            }
            
            if (description) {
                fieldsHtml += `<small class="form-text text-muted">${description}</small>`;
            }
            fieldsHtml += `</div>`;
        }
        
        return `
            <form class="widget-accordion-config-form" data-layout-id="${layoutId}">
                ${fieldsHtml}
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">
                        <i class="ri-save-line"></i> 保存
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-widget" data-layout-id="${layoutId}">
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </div>
            </form>
        `;
    }
    
    /**
     * 绑定手风琴配置表单事件
     */
    function bindAccordionFormEvents(container) {
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
                if (!confirm('确定要删除这个部件吗？')) return;
                
                try {
                    const response = await fetch(config.apiDeleteWidget, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ layout_id: layoutId })
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showToast('部件已删除', 'success');
                        // 从 DOM 移除
                        const accordionItem = this.closest('.slot-widget-accordion-item');
                        accordionItem?.remove();
                        // 从结构视图移除
                        document.querySelector(`.preview-widget-item[data-layout-id="${layoutId}"]`)?.remove();
                    } else {
                        showToast(result.message || '删除失败', 'error');
                    }
                } catch (err) {
                    showToast('删除失败', 'error');
                }
            });
        });
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
                } else if (isNewWidget || retryCount === 0) {
                    // 新部件或首次尝试 - 可能iframe还在加载，重试
                    retryCount++;
                    if (retryCount < maxRetries) {
                        console.log(`[ThemeEditor] Widget ${layoutId} not found, retry ${retryCount}/${maxRetries}`);
                        setTimeout(tryUpdate, retryDelay * retryCount); // 逐渐增加延迟
                        return;
                    }
                    
                    // 重试超限 - 新部件需要刷新整个预览
                    console.log(`[ThemeEditor] New widget ${layoutId} not found in iframe, triggering layout refresh`);
                    loadLayoutPreview();
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

        let pageTypes = ['*'];
        try {
            pageTypes = JSON.parse(this.dataset.widgetPageTypes || '["*"]');
        } catch (err) {
            console.error('Invalid page_types data:', this.dataset.widgetPageTypes);
            pageTypes = ['*'];
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
            // 页面类型和容器属性
            pageTypes: pageTypes,
            isContainer: this.dataset.widgetIsContainer === '1',
        };

        // 检查部件是否支持当前页面类型
        if (!isWidgetAllowedForPageType(pageTypes, state.pageType)) {
            showToast(`部件 "${widgetData.name}" 不支持当前页面类型 "${state.pageType}"`, 'warning');
            e.preventDefault();
            return;
        }

        // 存储到 state 中，以便在 dragover 和 drop 时使用
        state.draggingWidget = widgetData;

        console.log('Drag start - widget:', widgetData.name, 'position:', widgetData.position, 'slot:', widgetData.slot, 'exclusive:', widgetData.exclusive, 'pageTypes:', widgetData.pageTypes);

        e.dataTransfer.setData('application/json', JSON.stringify(widgetData));
        e.dataTransfer.effectAllowed = 'copy';

        // 高亮可放置区域
        highlightAllowedAreas(widgetData.position);
    }

    /**
     * 检查部件是否支持指定的页面类型
     */
    function isWidgetAllowedForPageType(widgetPageTypes, currentPageType) {
        // * 表示所有页面类型都可用
        if (widgetPageTypes.includes('*')) {
            return true;
        }
        // 检查是否包含当前页面类型
        if (widgetPageTypes.includes(currentPageType)) {
            return true;
        }
        // default 类型在所有页面都可用
        if (widgetPageTypes.includes('default')) {
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
        const exclusive = slot.dataset.wslotExclusive === 'true' || slot.dataset.exclusive === 'true';
        
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

        console.log('addWidgetToSlot - data:', data);

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
        console.log('highlightAllowedAreas - positions:', positions, 'widget:', widgetData);
        
        // 位置到区域的映射：定义每个位置标识对应的区域代码
        // 注意：这是 position -> area 的映射
        // 部件定义中的 position 值（如 'content', 'header', 'sidebar'）映射到可放置的区域
        const positionAreaMap = {
            'header': ['header'],
            'content': ['content'],               // content 位置的部件 → 内容区
            'sidebar': ['content', 'left_sidebar', 'right_sidebar'],  // sidebar 位置的部件 → 内容区或侧栏
            'left_sidebar': ['left_sidebar', 'content'],
            'right_sidebar': ['right_sidebar', 'content'],
            'footer': ['footer'],
            'all': ['header', 'content', 'footer'],
            '*': ['header', 'content', 'footer'],
        };

        // 所有可能的区域（简化后的三区域结构）
        const allAreas = ['header', 'content', 'footer'];

        let allowedAreas = [];
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            // 无限制，允许所有
            allowedAreas = [...allAreas];
            console.log('highlightAllowedAreas - no position restriction, allowing all');
        } else if (positions.includes('*') || positions.includes('all')) {
            // 通配符，允许所有
            allowedAreas = [...allAreas];
            console.log('highlightAllowedAreas - wildcard found, allowing all');
        } else {
            // 收集所有允许的区域
            positions.forEach(pos => {
                if (positionAreaMap[pos]) {
                    allowedAreas = allowedAreas.concat(positionAreaMap[pos]);
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
    function isAllowedArea(positions, areaCode) {
        console.log('isAllowedArea - positions:', positions, 'areaCode:', areaCode);
        
        // 如果没有位置限制，允许所有区域
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            console.log('isAllowedArea - no restriction, returning true');
            return true;
        }

        // 如果位置包含 '*' 或 'all'，允许所有区域
        if (positions.includes('*') || positions.includes('all')) {
            console.log('isAllowedArea - wildcard found, returning true');
            return true;
        }

        // 区域映射：定义每个区域代码对应的允许位置标识
        // 这个映射必须与 filterWidgetsByArea 中的映射保持一致！
        // 注意：映射的是部件 position 数组的值，不是部件类型
        const areaPositionMap = {
            'header': ['header'],
            'banner': ['content', 'header'],
            'content': ['content', 'sidebar'],
            'footer': ['footer'],
            'left_sidebar': ['sidebar', 'content'],
            'right_sidebar': ['sidebar', 'content'],
        };

        // 获取目标区域允许的位置标识
        const allowedPositions = areaPositionMap[areaCode] || [areaCode];
        console.log('isAllowedArea - allowedPositions for', areaCode, ':', allowedPositions);
        
        // 双向检查：
        // 1. 部件的 position 是否在区域的允许列表中
        // 2. 区域代码本身是否在部件的 position 列表中
        const result = positions.some(pos => allowedPositions.includes(pos)) ||
                       positions.includes(areaCode);
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
        ];

        // 独占部件类型：这些部件在同一区域只能有一个
        const exclusiveWidgets = [
            'logo',
            'main-nav',
            'search-box',
            'header-container',
            'footer-container',
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

        // 区域代码到位置标识的映射
        // 注意：这里映射的是部件定义中 position 数组的值，不是部件类型
        // 例如：hero-slider 的 position 是 ['content']，不是 ['banner']
        // 部件可以放置的位置由其 position 属性决定：
        //   - ['content'] = 只能放内容区
        //   - ['header'] = 只能放头部
        //   - ['content', 'sidebar'] = 可以放内容区或侧栏
        //   - ['*'] 或 [] = 可以放任何区域
        const areaPositionMap = {
            'header': ['header'],
            'banner': ['content', 'header'],  // banner 区域（如果有）接受 content 和 header 位置的部件
            'content': ['content', 'sidebar'],  // 内容区接受 content 和 sidebar 位置的部件
            'footer': ['footer'],
            'left_sidebar': ['sidebar', 'content'],
            'right_sidebar': ['sidebar', 'content'],
        };
        
        // 区域默认拒绝的部件类型
        const areaRejectMap = {
            'content': ['header', 'footer'],  // 内容区默认拒绝 header 和 footer 类型
        };

        // 获取该区域接受的位置标识
        const acceptedPositions = areaPositionMap[areaCode] || [areaCode];
        
        // 合并默认拒绝类型和传入的拒绝类型
        const finalRejectTypes = [...new Set([...(areaRejectMap[areaCode] || []), ...rejectTypes])];
        
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

            // 检查部件是否被拒绝
            const isRejected = finalRejectTypes.includes(widgetType) || finalRejectTypes.includes(widgetCode);

            // 判断部件是否可以放置到该区域
            // 分为四种情况：
            // 1. 被拒绝：该类型部件不允许放置
            // 2. 精确匹配：部件的 position 与区域匹配（推荐）
            // 3. 通用部件：部件没有指定 position 或包含 '*'（可用但非首选）
            // 4. 不匹配：部件不能放置到该区域
            
            const isUniversal = widgetPositions.length === 0 || widgetPositions.includes('*');
            const isExactMatch = widgetPositions.some(pos => acceptedPositions.includes(pos)) ||
                                 acceptedPositions.some(pos => widgetPositions.includes(pos));
            const canPlace = !isRejected && (isUniversal || isExactMatch);

            // 清除所有匹配相关的类
            item.classList.remove('area-matched', 'area-universal', 'area-not-matched');

            if (canPlace) {
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
    function selectArea(areaElement) {
        const areaCode = areaElement.dataset.area;
        const areaName = areaElement.querySelector('.area-label')?.textContent || areaCode;

        // 如果点击的是已选中的区域，则取消选中（切换行为）
        if (state.selectedArea === areaCode) {
            deselectArea();
            showToast('已显示全部部件', 'info');
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

        // 滚动部件面板到顶部
        // 注意：可滚动区域是 .panel-content，不是 #widgetPanel 或 #widgetList
        const widgetPanelContent = document.querySelector('#widgetPanel .panel-content');
        if (widgetPanelContent) {
            widgetPanelContent.scrollTo({ top: 0, behavior: 'smooth' });
        }
        // 兼容旧版本
        if (elements.widgetPanel) {
            elements.widgetPanel.scrollTop = 0;
        }
        if (elements.widgetList) {
            elements.widgetList.scrollTop = 0;
        }

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
                // 根据目标路径判断页面类型
                const pathname = targetUrl.pathname;
                let pageType = 'home';
                let layoutType = 'homepage';
                
                // 路径到页面类型的映射
                if (pathname === '/' || pathname === '' || pathname.endsWith('/index')) {
                    pageType = 'home';
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
                    pageType = 'cms';
                    layoutType = 'cms';
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
                if (elements.layoutTypeSelect) {
                    elements.layoutTypeSelect.value = layoutType;
                }
                
                showToast(`已切换到 ${layoutType} 布局预览`, 'info');
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
