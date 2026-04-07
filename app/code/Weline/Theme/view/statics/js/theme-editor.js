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
        // 版本控制 API
        apiVersions: '',
        apiSaveVersion: '',
        apiSwitchVersion: '',
        apiRestoreOriginal: '',
        apiPublishVersion: '',
        apiDeleteVersion: '',
        apiRenameVersion: '',
        // 前端预览 API
        apiStartPreview: '',
        apiExitPreview: '',
        apiPublishAndExit: '',
        apiCheckLock: '',
        apiReleaseLock: '',
        apiUpdateActivity: '',
        apiRequestTakeover: '',
        apiCheckTakeoverRequest: '',
        apiForceTakeover: '',
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
        saveInProgress: false,   // 防止拖入保存时重复提交导致保存两个部件
        // 版本控制状态
        versions: [], // 版本列表
        currentVersionId: null, // 当前版本ID
        publishedVersionId: null, // 已发布版本ID
        versionPanelOpen: false, // 版本面板是否展开
        // 嵌套距离：elementsFromPoint 得到的层级栈 [0]=最外，lastHoverPoint 为 iframe 内坐标
        lockHeld: false,
        lockHeartbeatTimer: null,
        lockLifecycleBound: false,
        lockConflictInfo: null,
        nestStack: [],
        nestIndex: 0,
        lastHoverPoint: null,
    };

    // DOM 元素
    let elements = {};

    /** 主题编辑器内联 SVG 图标（不依赖 Remix Icon 字体） */
    var TE_ICONS = {
        delete: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M17 6h5v2h-2v13a1 1 0 01-1 1H5a1 1 0 01-1-1V8H2V6h5V3a1 1 0 011-1h8a1 1 0 011 1v3zm1 2H6v12h12V8zm-9 3h2v6H9v-6zm4 0h2v6h-2v-6zM9 4v2h6V4H9z"/></svg>',
        add: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/></svg>',
        save: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M7 19V13h10v6h2V7.828l-2-2V4H5v15h2zM7 5h6v4H7V5zm0 10v-4h6v4H7z"/></svg>',
        drag: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M8 6h2v2H8V6zm0 5h2v2H8v-2zm0 5h2v2H8v-2zm5-10h2v2h-2V6zm0 5h2v2h-2v-2zm0 5h2v2h-2v-2z"/></svg>',
        close: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 10.586l4.95-4.95 1.414 1.414-4.95 4.95 4.95 4.95-1.414 1.414L12 13.414l-4.95 4.95-1.414-1.414 4.95-4.95-4.95-4.95L7.05 5.636z"/></svg>',
        settings: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 1l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 1z"/></svg>',
        edit: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M16.757 3l-2 2H5v14h14V9.243l2-2V20a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1h12.757zM20.485 2.1L21.9 3.515l-9.192 9.192-1.412.003-.003-1.417L20.485 2.1z"/></svg>',
        arrowDown: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 13.172l4.95-4.95 1.414 1.414L12 16 5.636 9.636 7.05 8.222z"/></svg>',
        image: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1zm1 2v12h14V6H5zm2.5 5a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm7 2l-2-2.5-3 4-2-2.5L6 17h12l-3.5-4z"/></svg>',
        folder: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M3 3h8.414l2 2H21a1 1 0 011 1v12a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1z"/></svg>',
        calendar: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M17 3h4a1 1 0 011 1v16a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1h4V1h2v2h6V1h2v2zm3 8H4v8h16v-8zm-5-6H9v2H7V5H4v4h16V5h-2v2h-2V5z"/></svg>',
        info: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-1-11v6h2v-6h-2zm0-4v2h2V7h-2z"/></svg>',
        layoutGrid: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M3 3h8v8H3V3zm10 0h8v8h-8V3zM3 13h8v8H3v-8zm10 0h8v8h-8v-8z"/></svg>',
        eye: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 110-10 5 5 0 010 10zm0-8a3 3 0 100 6 3 3 0 000-6z"/></svg>',
        loader: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 2a1 1 0 011 1v2a1 1 0 11-2 0V3a1 1 0 011-1zm0 16a1 1 0 011 1v2a1 1 0 11-2 0v-2a1 1 0 011-1zm8-8a1 1 0 01-1 1h-2a1 1 0 110-2h2a1 1 0 011 1zM4 12a1 1 0 01-1 1H1a1 1 0 110-2h2a1 1 0 011 1zm14.071 5.657a1 1 0 01-1.414 1.414l-1.414-1.414a1 1 0 111.414-1.414l1.414 1.414zm-12.728 0a1 1 0 01-1.414-1.414l1.414-1.414a1 1 0 111.414 1.414l-1.414 1.414zm12.728-12.728a1 1 0 01-1.414-1.414l1.414-1.414a1 1 0 111.414 1.414l-1.414 1.414zm-12.728 0a1 1 0 01-1.414 1.414L2.343 4.929A1 1 0 113.757 3.515l1.414 1.414z"/></svg>',
        apps: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M6.75 2.5A1.75 1.75 0 005 4.25v2.5c0 .966.784 1.75 1.75 1.75h2.5A1.75 1.75 0 0011 6.75v-2.5A1.75 1.75 0 009.25 2.5h-2.5zm9 0A1.75 1.75 0 0014 4.25v2.5c0 .966.784 1.75 1.75 1.75h2.5A1.75 1.75 0 0020 6.75v-2.5A1.75 1.75 0 0018.25 2.5h-2.5zm-9 9A1.75 1.75 0 005 13.25v2.5c0 .966.784 1.75 1.75 1.75h2.5A1.75 1.75 0 0011 15.75v-2.5A1.75 1.75 0 009.25 11.5h-2.5zm9 0A1.75 1.75 0 0014 13.25v2.5c0 .966.784 1.75 1.75 1.75h2.5A1.75 1.75 0 0020 15.75v-2.5A1.75 1.75 0 0018.25 11.5h-2.5z"/></svg>',
        palette: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10c-1.5 0-2.5-.5-3.5-1.5l-.5-.5H6a2 2 0 01-2-2v-2.5l-.5-.5C2.5 14.5 2 13.5 2 12 2 6.477 6.477 2 12 2zm0 2a8 8 0 00-1.5 15.938V16h4v-.062A8 8 0 0012 4zm0 2a6 6 0 01.5 11.972V14h-1v-.028A6 6 0 0112 6z"/></svg>',
        link: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M13.06 8.11l1.415 1.415a7 7 0 010 9.9l-.354.353a7 7 0 01-9.9-9.9l1.415-1.414a5 5 0 007.07 7.07l.354-.353a5 5 0 000-7.07l-1.415-1.415 1.415-1.414zm6.718 6.011l-1.414-1.414a7 7 0 010-9.9l.354-.353a7 7 0 019.9 9.9l-1.415 1.414a5 5 0 00-7.07-7.07l-.354.353a5 5 0 000 7.07l1.415 1.415-1.415 1.414zm-2.829-9.9a1 1 0 010 1.414L4.929 19.485a1 1 0 01-1.414-1.414L16.343 5.636a1 1 0 011.414 0z"/></svg>',
        global: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-2-2.086a8 8 0 01-1.744-2.667L8 12l.256-.247A8 8 0 0110 5.086V4h4v1.086a8 8 0 011.744 2.667L16 12l-.256.247A8 8 0 0114 18.914V20h-4v-1.086z"/></svg>',
        inbox: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M3 3h18a1 1 0 011 1v16a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1zm2 2v12h14V5H5zm2 2h10v2H7V7zm0 4h10v2H7v-2zm0 4h7v2H7v-2z"/></svg>',
        cursor: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="1em" height="1em"><path d="M13.85 22.25h-3.7c-.74 0-1.36-.54-1.45-1.27l-.27-1.89c-.27-.14-.53-.29-.79-.46l-1.8.72c-.7.26-1.47-.03-1.81-.65L2.2 15.53c-.35-.66-.2-1.44.36-1.88l1.53-1.19c-.01-.15-.02-.3-.02-.46 0-.6.04-1.22.04-1.87 0-.21-.15-.41-.35-.47L2.4 9.76c-.36-.12-.63-.43-.63-.83 0-.5.4-.9.9-.9h2.97c.71 0 1.32.5 1.47 1.18l1.05 4.2c.18.7.78 1.2 1.5 1.2h6.18c.45 0 .86.25 1.04.64.18.4.08.85-.25 1.12l-4.22 3.5 1.27 1.27c.39.39.39 1.02 0 1.41-.39.39-1.02.39-1.41 0l-2.12-2.12z"/></svg>'
    };
    function iconSvg(name) {
        var svg = TE_ICONS[name];
        return svg ? '<span class="te-icon te-icon-' + name + '">' + svg + '</span>' : '';
    }
    function getCurrentPageType() {
        return state.pageType || state.layoutType || 'homepage';
    }

    function getCurrentWindowUrl() {
        return new URL(window.location.href);
    }

    function getCurrentWindowParam(key) {
        return getCurrentWindowUrl().searchParams.get(key) || '';
    }

    function buildEditorUrl(overrides = {}) {
        const currentUrl = getCurrentWindowUrl();
        const url = new URL(config.apiBase || currentUrl.pathname, window.location.origin);

        currentUrl.searchParams.forEach((value, key) => {
            url.searchParams.set(key, value);
        });

        const params = Object.assign({
            theme_id: state.themeId || 0,
            page_type: getCurrentPageType(),
            editor_area: state.editorArea || 'frontend',
            status: state.previewStatus || 'draft',
        }, overrides || {});

        Object.entries(params).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') {
                url.searchParams.delete(key);
                return;
            }

            url.searchParams.set(key, String(value));
        });

        url.searchParams.set('_t', String(Date.now()));
        return url.toString();
    }

    function buildLayoutPreviewUrl(overrides = {}) {
        const url = new URL(config.apiLayoutPreview, window.location.origin);
        const currentUrl = getCurrentWindowUrl();
        const layoutType = (typeof overrides.layout_type === 'string' && overrides.layout_type)
            ? overrides.layout_type
            : (state.layoutType || getCurrentPageType() || 'homepage');
        const pageType = (typeof overrides.page_type === 'string' && overrides.page_type)
            ? overrides.page_type
            : (state.pageType || layoutType || 'homepage');
        const layoutOption = (typeof overrides.layout_option === 'string' && overrides.layout_option)
            ? overrides.layout_option
            : (state.layoutOption || 'default');
        const previewStatus = (typeof overrides.status === 'string' && overrides.status)
            ? overrides.status
            : (state.previewStatus || 'draft');
        const editorArea = (typeof overrides.editor_area === 'string' && overrides.editor_area)
            ? overrides.editor_area
            : (state.editorArea || 'frontend');
        const themeId = overrides.theme_id || state.themeId || 0;

        url.searchParams.set('theme_id', String(themeId));
        url.searchParams.set('page_type', pageType);
        url.searchParams.set('layout_type', layoutType);
        url.searchParams.set('layout_option', layoutOption);
        url.searchParams.set('editor_mode', String(overrides.editor_mode || '1'));
        url.searchParams.set('preview_mode', String(overrides.preview_mode || 'live'));
        url.searchParams.set('status', previewStatus);
        url.searchParams.set('editor_area', editorArea);

        ['frontend_theme_id', 'backend_theme_id', 'scope', 'version_id'].forEach((key) => {
            const overrideValue = Object.prototype.hasOwnProperty.call(overrides, key) ? overrides[key] : currentUrl.searchParams.get(key);
            if (overrideValue !== null && overrideValue !== undefined && overrideValue !== '') {
                url.searchParams.set(key, String(overrideValue));
            }
        });

        url.searchParams.set('_t', String(overrides._t || Date.now()));
        return url.toString();
    }

    function navigateEditorShell(overrides = {}) {
        const targetUrl = buildEditorUrl(overrides);
        const finalize = () => {
            window.location.href = targetUrl;
        };

        if (state.lockHeld) {
            releaseCurrentEditorLock().finally(finalize);
            return;
        }

        finalize();
    }

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
        config.apiParamRenderForm = container.dataset.apiParamRenderForm || '/theme/backend/widget/paramrender/form';
        config.apiSaveCompiledLayout = container.dataset.apiSaveCompiledLayout || `${config.apiBase}/save-compiled-layout`;
        
        // 版本控制 API 端点
        config.apiVersions = container.dataset.apiVersions || `${config.apiBase}/versions`;
        config.apiSaveVersion = container.dataset.apiSaveVersion || `${config.apiBase}/save-version`;
        config.apiSwitchVersion = container.dataset.apiSwitchVersion || `${config.apiBase}/switch-version`;
        config.apiRestoreOriginal = container.dataset.apiRestoreOriginal || `${config.apiBase}/restore-original`;
        config.apiPublishVersion = container.dataset.apiPublishVersion || `${config.apiBase}/publish-version`;
        config.apiDeleteVersion = container.dataset.apiDeleteVersion || `${config.apiBase}/delete-version`;
        config.apiRenameVersion = container.dataset.apiRenameVersion || `${config.apiBase}/rename-version`;
        
        // 前端预览 API 端点
        config.apiStartPreview = container.dataset.apiStartPreview || `${config.apiBase}/start-preview`;
        config.apiExitPreview = container.dataset.apiExitPreview || `${config.apiBase}/exit-preview`;
        config.apiPublishAndExit = container.dataset.apiPublishAndExit || `${config.apiBase}/publish-and-exit`;
        config.apiCheckLock = container.dataset.apiCheckLock || `${config.apiBase}/check-lock`;
        config.apiReleaseLock = container.dataset.apiReleaseLock || `${config.apiBase}/release-lock`;
        config.apiUpdateActivity = container.dataset.apiUpdateActivity || `${config.apiBase}/update-activity`;
        config.apiRequestTakeover = container.dataset.apiRequestTakeover || `${config.apiBase}/request-takeover`;
        config.apiCheckTakeoverRequest = container.dataset.apiCheckTakeoverRequest || `${config.apiBase}/check-takeover-request`;
        config.apiForceTakeover = container.dataset.apiForceTakeover || `${config.apiBase}/force-takeover`;

        // Preview-related endpoints and call sites (baseline for TDD)
        // - apiRenderWidget: used by renderWidgetPreview()/preview render flows
        // - apiWidgetPreview: legacy per-widget preview fetches (to be removed)
        // - apiLayoutPreview: used by refreshPreviewWidgets() and loadLayoutPreview()
        // - apiCompileLayout: used by fetchLayoutSlots()
        // - apiUpdateConfig/apiSaveWidget: save flows that should return preview_html

        state.themeId = parseInt(container.dataset.themeId) || 0;
        state.pageType = container.dataset.pageType || 'default';
        state.editorArea = container.dataset.editorArea || 'frontend';
        state.previewStatus = container.dataset.previewStatus || getCurrentWindowParam('status') || 'draft';

        // 缓存 DOM 元素
        elements = {
            container: container,
            themeSelect: document.getElementById('themeSelect'),
            pageTypeSelect: document.getElementById('pageTypeSelect'),
            editorAreaSelect: document.getElementById('editorAreaSelect'),
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
            btnFrontendPreview: document.getElementById('btnFrontendPreview'),
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

        // 加载版本列表（初始化时获取当前版本显示）
        if (state.themeId) {
            loadVersions();
        }

        // 若服务端未渲染部件列表（#widgetList 为空），则请求部件接口并渲染
        loadWidgetListIfEmpty();

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

        updatePreviewStatusUI(state.previewStatus);
        initializeEditorLock();
    }

    /**
     * 绑定事件
     */
    function bindEvents() {
        // 主题选择（跳转时默认前端区域）
        if (elements.themeSelect) {
            elements.themeSelect.addEventListener('change', function() {
                const themeId = this.value;
                if (themeId) {
                    navigateEditorShell({
                        theme_id: themeId,
                        page_type: getCurrentPageType(),
                        editor_area: 'frontend',
                    });
                }
            });
        }

        // 前端/后端区域切换（刷新页面以加载对应布局）
        if (elements.editorAreaSelect) {
            elements.editorAreaSelect.addEventListener('change', function() {
                const area = this.value;
                if (state.themeId && area) {
                    navigateEditorShell({
                        theme_id: state.themeId,
                        page_type: getCurrentPageType(),
                        editor_area: area,
                    });
                }
            });
        }

        // 页面类型选择（AJAX 切换，不刷新页面）
        if (elements.pageTypeSelect) {
            elements.pageTypeSelect.addEventListener('change', function() {
                const pageType = this.value;
                if (state.themeId && pageType) {
                    navigateEditorShell({
                        page_type: pageType,
                        version_id: null,
                    });
                    return;
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

        // 组件预览按钮（部件库列表中的「预览」）
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-preview-component');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            const module = btn.dataset.widgetModule || '';
            const code = btn.dataset.widgetCode || '';
            const name = btn.dataset.widgetName || '';
            if (module && code) openComponentPreviewModal(module, code, name);
        });

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

            // 点击区域标签、区域本身、或区域占位符时，选中区域并过滤部件
            const areaLabel = e.target.closest('.area-label');
            const areaDescription = e.target.closest('.area-description');
            const slotPlaceholder = e.target.closest('.slot-placeholder-large');
            const previewArea = e.target.closest('.preview-area');
            
            // 如果点击的是区域标签、区域描述、占位符、或非部件列表的区域空白处
            if (areaLabel || areaDescription || slotPlaceholder || (previewArea && !e.target.closest('.preview-widget-item'))) {
                // 点击区域标签或区域空白处（排除已有部件）
                const area = previewArea || (areaLabel ? areaLabel.closest('.preview-area') : null);
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
        
        // 前端预览按钮
        elements.btnFrontendPreview?.addEventListener('click', openFrontendPreview);

        // 配置表单提交（左侧面板，无提交按钮时仅防误触）
        document.addEventListener('submit', function(e) {
            if (e.target.id === 'widgetConfigForm' || (e.target.classList && e.target.classList.contains('w-param-form'))) {
                e.preventDefault();
                if (e.target.id === 'widgetConfigForm') saveWidgetConfig(e.target);
            }
        });
        
        // 左侧配置面板 i18n 事件委托（覆盖 renderConfigForm 生成的表单）
        if (elements.configContent) {
            elements.configContent.addEventListener('click', async function(e) {
                const i18nBtn = e.target.closest('.w-param-btn-i18n, .btn-i18n-edit');
                if (i18nBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    const fieldKey = i18nBtn.dataset.field;
                    const layoutId = i18nBtn.dataset.layoutId;
                    const panelId = 'i18n_panel_' + layoutId + '_' + fieldKey.replace(/\./g, '_');
                    const panel = document.getElementById(panelId) || i18nBtn.closest('.w-param-field, .config-field, .translatable-field')?.querySelector('.w-param-i18n-panel, .i18n-edit-panel');
                    if (!panel) return;
                    if (panel.style.display === 'none' || !panel.style.display) {
                        panel.style.display = 'block';
                        i18nBtn.classList.add('active');
                        await loadI18nValues(layoutId, fieldKey, panel);
                    } else {
                        panel.style.display = 'none';
                        i18nBtn.classList.remove('active');
                    }
                    return;
                }
                const closeBtn = e.target.closest('[data-close-i18n], .btn-i18n-close');
                if (closeBtn) {
                    const panel = closeBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                    if (panel) {
                        panel.style.display = 'none';
                        const fieldKey = closeBtn.dataset.field || panel.dataset.field;
                        elements.configContent.querySelector(`.w-param-btn-i18n[data-field="${fieldKey}"], .btn-i18n-edit[data-field="${fieldKey}"]`)?.classList.remove('active');
                    }
                    return;
                }
                const saveBtn = e.target.closest('[data-save-i18n], .btn-save-i18n');
                if (saveBtn) {
                    const panel = saveBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                    const fieldKey = saveBtn.dataset.field || panel?.dataset.field;
                    const layoutId = saveBtn.dataset.layoutId || panel?.dataset.layoutId;
                    if (panel && fieldKey && layoutId) {
                        await saveI18nValues(layoutId, fieldKey, panel);
                    }
                    return;
                }
            });
        }

        // 后端渲染的 .w-param-form[data-auto-save="1"]：实时保存
        const themeEditorRoot = document.getElementById('themeEditor');
        if (themeEditorRoot) {
            const autoSaveDebounceByLayout = {};
            function scheduleWidgetConfigAutoSave(form) {
                const layoutId = form.dataset.layoutId;
                if (!layoutId) return;
                if (autoSaveDebounceByLayout[layoutId]) clearTimeout(autoSaveDebounceByLayout[layoutId]);
                autoSaveDebounceByLayout[layoutId] = setTimeout(function() {
                    autoSaveDebounceByLayout[layoutId] = null;
                    saveWidgetConfig(form, true);
                }, 400);
            }
            function getWidgetConfigForm(target) {
                return target.closest && (target.closest('.w-param-form[data-auto-save="1"]') || target.closest('#widgetConfigForm'));
            }
            themeEditorRoot.addEventListener('input', function(e) {
                const form = getWidgetConfigForm(e.target);
                if (form) scheduleWidgetConfigAutoSave(form);
            });
            themeEditorRoot.addEventListener('change', function(e) {
                const form = getWidgetConfigForm(e.target);
                if (form) scheduleWidgetConfigAutoSave(form);
            });
        }
        // 手风琴：在 document 上委托，覆盖 #themeEditor（左侧 slot）和 #widgetConfigModal（弹窗）
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#themeEditor') && !e.target.closest('#widgetConfigModal')) return;
            const wTitle = e.target.closest('.w-param-group-title');
            if (wTitle) {
                if (e.target.closest('a, button, input, select, textarea')) return;
                e.preventDefault();
                const group = wTitle.closest('.w-param-group');
                if (group) group.classList.toggle('w-param-collapsed');
                return;
            }
            const configTitle = e.target.closest('.config-group-title');
            if (configTitle) {
                if (e.target.closest('a, button, input, select')) return;
                e.preventDefault();
                const group = configTitle.closest('.config-group');
                if (group) group.classList.toggle('collapsed');
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
        
        // 配置面板删除按钮（事件委托）
        document.addEventListener('click', function(e) {
            const deleteBtn = e.target.closest('.btn-delete-config');
            if (deleteBtn) {
                e.preventDefault();
                const layoutId = deleteBtn.dataset.layoutId;
                if (!layoutId) return;
                // 尝试从结构面板获取 slotId
                const structureItem = document.querySelector(`.preview-widget-item[data-layout-id="${layoutId}"]`);
                const slotId = structureItem?.closest('[data-slot]')?.dataset.slot || undefined;
                handleWidgetDelete(layoutId, slotId);
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
                
                // 初始化部件 hover 操作按钮（多档延迟以兼容异步渲染的布局）
                setTimeout(() => initWidgetHoverActions(), 100);
                setTimeout(() => initWidgetHoverActions(), 400);
                setTimeout(() => initWidgetHoverActions(), 1200);
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
     * 默认 PC 设计视口宽度（部件在此宽度下布局后整体缩放填满 canvas）
     */
    const WIDGET_PREVIEW_DESIGN_WIDTH = 1200;

    /**
     * 适配部件库预览：按默认 PC 视口缩放，使预览内容填满 widget-preview-canvas，避免大块灰色空白
     */
    function fitWidgetPreviews() {
        const canvases = document.querySelectorAll('.widget-preview-canvas');
        canvases.forEach(canvas => {
            let viewport = canvas.firstElementChild;
            if (!viewport) return;
            if (viewport.classList.contains('widget-preview-placeholder') || viewport.classList.contains('widget-preview-error')) return;

            let inner = null;
            if (viewport.classList.contains('widget-preview-viewport')) {
                inner = viewport.firstElementChild;
            } else {
                viewport = document.createElement('div');
                viewport.className = 'widget-preview-viewport';
                viewport.style.width = WIDGET_PREVIEW_DESIGN_WIDTH + 'px';
                viewport.style.display = 'block';
                inner = document.createElement('div');
                inner.style.width = WIDGET_PREVIEW_DESIGN_WIDTH + 'px';
                inner.style.display = 'block';
                while (canvas.firstChild) inner.appendChild(canvas.firstChild);
                viewport.appendChild(inner);
                canvas.appendChild(viewport);
            }

            viewport.style.width = WIDGET_PREVIEW_DESIGN_WIDTH + 'px';
            viewport.style.height = 'auto';
            viewport.style.overflow = '';
            inner.style.width = WIDGET_PREVIEW_DESIGN_WIDTH + 'px';
            inner.style.transform = '';
            inner.style.transformOrigin = 'top left';

            const canvasWidth = canvas.clientWidth;
            const canvasHeight = canvas.clientHeight;
            const contentHeight = inner.scrollHeight || viewport.scrollHeight || 1;

            if (!canvasWidth || !canvasHeight) return;

            const scale = Math.min(1, canvasWidth / WIDGET_PREVIEW_DESIGN_WIDTH, canvasHeight / contentHeight);
            if (!isFinite(scale) || scale <= 0) return;

            viewport.style.width = (WIDGET_PREVIEW_DESIGN_WIDTH * scale) + 'px';
            viewport.style.height = (contentHeight * scale) + 'px';
            viewport.style.overflow = 'hidden';
            inner.style.transform = 'scale(' + scale + ')';
            inner.style.transformOrigin = 'top left';
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
                // 部件被拖放到插槽（附带 sort_order）
                handleWidgetDropped(data.widget, data.slot, data.sort_order);
                break;
            case 'widget-rejected':
                // 部件被插槽拒绝
                showToast(data.reason || '部件被拒绝', 'error');
                break;
        }
    }

    /**
     * 从 slotId 推断所属的父区域
     * 用于将子插槽（如 logo, footer-social）映射到父区域（header, footer）
     * @param {string} slotId 插槽ID
     * @returns {string} 区域代码（header, content, footer）
     */
    function inferAreaFromSlotId(slotId) {
        if (!slotId) return 'content';
        
        // 已知的 header 子插槽
        const headerSlots = ['logo', 'search', 'user-area', 'navigation', 'header-search', 'account', 'mini-cart-icon', 'language-switcher', 'currency-switcher', 'cart-icon'];
        
        // 已知的 footer 子插槽
        const footerSlots = ['footer-social', 'footer-links', 'footer-copyright', 'footer-payment', 'footer-newsletter'];
        
        // 精确匹配
        if (slotId === 'header') return 'header';
        if (slotId === 'footer') return 'footer';
        if (slotId === 'content') return 'content';
        
        // 检查是否是 header 子插槽
        if (headerSlots.includes(slotId)) return 'header';
        
        // 检查是否是 footer 子插槽（包括前缀匹配）
        if (footerSlots.includes(slotId) || slotId.startsWith('footer-')) return 'footer';
        
        // 检查是否以 header- 开头
        if (slotId.startsWith('header-')) return 'header';
        
        // 默认归属于 content 区域
        return 'content';
    }

    /**
     * 处理插槽选中
     */
    function handleSlotSelected(slot) {
        console.log('插槽被选中:', slot);
        
        // 保存当前选中的插槽
        state.selectedSlot = slot;
        
        // 根据插槽 ID 确定区域并过滤部件
        const slotId = slot.id || '';
        // 优先使用 slot.area（来自 position 属性），否则从 slotId 推断
        let areaCode = slot.area || inferAreaFromSlotId(slotId);
        
        console.log('[handleSlotSelected] slotId:', slotId, 'slot.area:', slot.area, 'resolved areaCode:', areaCode);
        
        // 切换插槽前，先完全重置前次过滤状态
        restoreWidgetOrder();
        
        // 调用区域过滤函数，隐藏不适合该区域的部件
        if (areaCode) {
            filterWidgetsByArea(areaCode);
            state.selectedArea = areaCode;
        }
        
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
        const rawAccept = slot.accept;
        const acceptCodes = Array.isArray(rawAccept) ? rawAccept : (typeof rawAccept === 'string' ? rawAccept.split(',').map(s => s.trim()).filter(Boolean) : []);
        
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
                            ${iconSvg('arrowDown')}
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
                        ${iconSvg('add')} 继续添加部件
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
                
                // 生成配置表单
                const formHtml = await generateWidgetConfigForm(layoutId, params, widgetConfig);
                const searchPlaceholder = (typeof __ !== 'undefined' ? __('搜索配置项') : '搜索配置项');
                const searchWrap = '<div class="w-param-search-wrap mb-2"><input type="text" class="w-param-search form-control form-control-sm" placeholder="' + searchPlaceholder + '" autocomplete="off"></div>';
                configBody.innerHTML = searchWrap + formHtml;
                
                // 绑定表单事件（手风琴 + 配置搜索）
                bindAccordionFormEvents(configBody);
                bindParamSearch(configBody);
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
        if (!params || Object.keys(params).length === 0) {
            return `<div class="config-empty-state">
                ${iconSvg('settings')}
                <p>该部件无可配置项</p>
            </div>`;
        }
        
        // 尝试使用后端 API 渲染
        try {
            const response = await fetch(config.apiParamRenderForm || '/theme/backend/widget/paramrender/form', {
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
            
            if (response.ok) {
                const html = await response.text();
                if (html && !html.includes('alert-danger')) {
                    return html;
                }
            }
        } catch (err) {
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
            const translatable = isFieldTranslatable(param);
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
                            ${value ? `<img src="${value}" alt="预览">` : `<div class="image-placeholder">${iconSvg('image')}<span>点击选择图片</span></div>`}
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
                    <span class="input-group-text">${iconSvg('calendar')}</span>
                    <input type="${inputType}" class="form-control" id="${fieldId}" name="${key}" value="${value || ''}">
                </div>`;
            } else if (type === 'array') {
                fieldHtml += `<div class="array-editor-wrapper" data-field-id="${fieldId}">
                    <div class="array-items-container" id="${fieldId}_items">
                        <div class="array-empty-state"><i class="ri-list-check-2"></i><p>暂无项目</p></div>
                    </div>
                    <div class="array-actions">
                        <button type="button" class="btn btn-outline-primary btn-add-array-item" data-target="${fieldId}">
                            ${iconSvg('add')} 添加项目
                        </button>
                    </div>
                    <input type="hidden" id="${fieldId}" name="${key}" value='${JSON.stringify(value || [])}'>
                </div>`;
            } else if (type === 'icon') {
                fieldHtml += `<div class="icon-picker-wrapper">
                    <div class="icon-preview">
                        <span class="icon-preview-display">${value ? `<i class="${value}"></i>` : (iconSvg('add') || '<span class="te-icon placeholder-icon"></span>')}</span>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-icon-picker" data-target="${fieldId}">
                            ${iconSvg('apps')} 选择图标
                        </button>
                    </div>
                    <input type="hidden" id="${fieldId}" name="${key}" value="${value || ''}">
                </div>`;
            } else {
                fieldHtml += `<input type="text" class="form-control" id="${fieldId}" name="${key}" value="${value}">`;
            }
            fieldHtml += `</div>`;
            
            // 多语言编辑区（统一空容器，由 fetchInstalledLocales 动态填充）
            if (translatable) {
                fieldHtml += `<div class="w-param-i18n-panel i18n-edit-panel" id="i18n_panel_${layoutId}_${key}" data-field="${key}" data-layout-id="${layoutId}" style="display:none;">
                    <div class="w-param-i18n-header i18n-panel-header">
                        <span>${iconSvg('global')} 多语言配置</span>
                        <button type="button" class="btn-i18n-close" data-close-i18n data-field="${key}">${iconSvg('close')}</button>
                    </div>
                    <div class="w-param-i18n-body i18n-panel-body"></div>
                    <div class="w-param-i18n-footer i18n-panel-footer">
                        <button type="button" class="btn btn-sm btn-primary btn-save-i18n" data-save-i18n data-field="${key}" data-layout-id="${layoutId}">
                            ${iconSvg('save')} 保存多语言
                        </button>
                    </div>
                </div>`;
            }
            
            if (description) {
                fieldHtml += `<div class="config-field-description">${iconSvg('info')} ${description}</div>`;
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
                        ${iconSvg('arrowDown')}
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
                        ${iconSvg('palette')}
                        样式设置
                        ${iconSvg('arrowDown')}
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
                        ${iconSvg('link')}
                        链接配置
                        ${iconSvg('arrowDown')}
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
                        ${iconSvg('save')} 保存配置
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-delete-widget" data-layout-id="${layoutId}">
                        ${iconSvg('delete')} 删除
                    </button>
                </div>
            </form>
        `;
    }
    
    /**
     * 绑定配置表单事件（多语言、颜色等）；手风琴已在 #themeEditor 根上委托
     */
    function bindAccordionFormEvents(container) {
        if (!container) return;

        // 统一多语言事件委托（覆盖顶级字段 + 后续动态添加的数组子字段）
        container.addEventListener('click', async function(e) {
            // 多语言编辑按钮（.w-param-btn-i18n 或 .btn-i18n-edit）
            const i18nBtn = e.target.closest('.w-param-btn-i18n, .btn-i18n-edit');
            if (i18nBtn) {
                e.preventDefault();
                e.stopPropagation();
                const fieldKey = i18nBtn.dataset.field;
                const layoutId = i18nBtn.dataset.layoutId;
                const panelId = 'i18n_panel_' + layoutId + '_' + fieldKey.replace(/\./g, '_');
                const panel = document.getElementById(panelId) || i18nBtn.closest('.w-param-field, .config-field')?.querySelector('.w-param-i18n-panel, .i18n-edit-panel');
                if (!panel) return;

                if (panel.style.display === 'none' || !panel.style.display) {
                    panel.style.display = 'block';
                    i18nBtn.classList.add('active');
                    await loadI18nValues(layoutId, fieldKey, panel);
                } else {
                    panel.style.display = 'none';
                    i18nBtn.classList.remove('active');
                }
                return;
            }

            // 关闭按钮
            const closeBtn = e.target.closest('[data-close-i18n], .btn-i18n-close');
            if (closeBtn) {
                const panel = closeBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                if (panel) {
                    panel.style.display = 'none';
                    const fieldKey = closeBtn.dataset.field || panel.dataset.field;
                    container.querySelector(`.w-param-btn-i18n[data-field="${fieldKey}"], .btn-i18n-edit[data-field="${fieldKey}"]`)?.classList.remove('active');
                }
                return;
            }

            // 保存多语言按钮
            const saveBtn = e.target.closest('[data-save-i18n], .btn-save-i18n');
            if (saveBtn) {
                const panel = saveBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                const fieldKey = saveBtn.dataset.field || panel?.dataset.field;
                const layoutId = saveBtn.dataset.layoutId || panel?.dataset.layoutId;
                if (panel && fieldKey && layoutId) {
                    await saveI18nValues(layoutId, fieldKey, panel);
                }
                return;
            }
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
                    preview.innerHTML = iconSvg('add') || '';
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
                        preview.innerHTML = `<img src="${url}" alt="预览" onerror="this.parentElement.innerHTML='<div class="image-placeholder"><span>图片加载失败</span></div>'">`;
                        preview.closest('.image-preview-container')?.classList.add('has-image');
                    } else {
                        preview.innerHTML = '<div class="image-placeholder">' + (iconSvg('image') || '') + '<span>点击选择图片</span></div>';
                        preview.closest('.image-preview-container')?.classList.remove('has-image');
                    }
                });
            }
            
            // 清除按钮
            if (clearBtn && urlInput && preview) {
                clearBtn.addEventListener('click', function() {
                    urlInput.value = '';
                    preview.innerHTML = '<div class="image-placeholder">' + (iconSvg('image') || '') + '<span>点击选择图片</span></div>';
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
                                <div class="array-item-handle">${iconSvg('drag')}</div>
                                <div class="array-item-content">
                                    <input type="text" class="form-control array-item-input" value="">
                                </div>
                                <div class="array-item-actions">
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-array-item">
                                        ${iconSvg('delete')}
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
        container.querySelectorAll('.w-param-form').forEach(form => {
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
        container.querySelectorAll('.w-param-btn-delete-widget, .btn-delete-widget').forEach(btn => {
            btn.addEventListener('click', async function() {
                const layoutId = this.dataset.layoutId;
                
                const confirmed = await showCustomConfirm(
                    '确认删除部件？',
                    '删除后插槽将恢复为原始内容。',
                    '确认删除',
                    '取消'
                );
                if (!confirmed) return;
                
                // 从 iframe 获取 slot_id 和 area
                let slotIdFb = '', areaFb = 'content';
                try {
                    const iframe = elements.previewFrame;
                    if (iframe && iframe.contentDocument) {
                        const wEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                        if (wEl) {
                            slotIdFb = wEl.getAttribute('data-slot-id') || wEl.closest('[data-wslot]')?.getAttribute('data-wslot') || wEl.closest('[data-slot]')?.getAttribute('data-slot') || '';
                            if (wEl.closest('header, [data-wslot-position="header"], .site-header')) areaFb = 'header';
                            else if (wEl.closest('footer, [data-wslot-position="footer"], .site-footer')) areaFb = 'footer';
                        }
                    }
                } catch (e) {}
                
                try {
                    const response = await fetch(config.apiDeleteWidget, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            layout_id: layoutId,
                            theme_id: state.themeId,
                            slot_id: slotIdFb,
                            area: areaFb,
                            layout_type: state.layoutType || 'homepage',
                            layout_option: state.layoutOption || 'default'
                        })
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
                                
                                // 恢复原始内容（不调用 initWidgetHoverActions 避免重复渲染操作按钮）
                                if (slot && !slot.querySelector('[data-layout-id]')) {
                                    if (result.has_original && result.original_html) {
                                        // 有原始内容，恢复模板默认的内容（剥离可能混入的 widget-wrapper）
                                        slot.innerHTML = stripWidgetWrappersFromHtml(result.original_html);
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
     * 配置项搜索：按分组标题、字段 label 过滤 .w-param-group / .config-group
     */
    function bindParamSearch(container) {
        const input = container.querySelector('.w-param-search');
        if (!input) return;
        input.addEventListener('input', function() {
            const kw = (this.value || '').trim().toLowerCase();
            container.querySelectorAll('.w-param-group').forEach(group => {
                const titleEl = group.querySelector(':scope > .w-param-group-title');
                const titleText = (titleEl && titleEl.textContent) ? titleEl.textContent.trim() : '';
                const labels = group.querySelectorAll('.w-param-label, .w-param-array-label, .w-param-field-header .w-param-label');
                const labelTexts = Array.from(labels).map(el => (el.textContent || '').trim());
                const groupMatch = !kw || titleText.toLowerCase().includes(kw);
                const fieldMatch = labelTexts.some(t => t.toLowerCase().includes(kw));
                const show = groupMatch || fieldMatch;
                group.style.display = show ? '' : 'none';
                if (show && kw) {
                    group.classList.remove('w-param-collapsed');
                    group.querySelectorAll('.w-param-field').forEach(field => {
                        const labelEl = field.querySelector('.w-param-label, .w-param-array-label');
                        const t = (labelEl && labelEl.textContent) ? labelEl.textContent.trim().toLowerCase() : '';
                        field.style.display = t.includes(kw) ? '' : 'none';
                    });
                } else if (show) {
                    group.querySelectorAll('.w-param-field').forEach(f => { f.style.display = ''; });
                }
            });
            container.querySelectorAll('.config-group').forEach(group => {
                const titleEl = group.querySelector(':scope > .config-group-title');
                const titleText = (titleEl && titleEl.textContent) ? titleEl.textContent.trim() : '';
                const labels = group.querySelectorAll('.config-field label, .config-field .form-label');
                const labelTexts = Array.from(labels).map(el => (el.textContent || '').trim());
                const groupMatch = !kw || titleText.toLowerCase().includes(kw);
                const fieldMatch = labelTexts.some(t => t.toLowerCase().includes(kw));
                const show = groupMatch || fieldMatch;
                group.style.display = show ? '' : 'none';
                if (show && kw) {
                    group.classList.remove('collapsed');
                    group.querySelectorAll('.config-field').forEach(field => {
                        const labelEl = field.querySelector('label, .form-label');
                        const t = (labelEl && labelEl.textContent) ? labelEl.textContent.trim().toLowerCase() : '';
                        field.style.display = t.includes(kw) ? '' : 'none';
                    });
                } else if (show) {
                    group.querySelectorAll('.config-field').forEach(f => { f.style.display = ''; });
                }
            });
        });
    }
    
    /**
     * 绑定数组项事件
     */
    function bindArrayItemEvents(item, updateCallback) {
        // 删除按钮
        const removeBtn = item.querySelector('.w-param-array-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                const wrapper = item.closest('.w-param-array');
                const minItems = parseInt(wrapper?.dataset?.minItems) || 0;
                const itemsContainer = wrapper?.querySelector('.w-param-array-items');
                const currentCount = itemsContainer?.querySelectorAll('.w-param-array-item').length || 0;
                
                if (currentCount <= minItems) {
                    showToast(`至少需要 ${minItems} 个项目`, 'warning');
                    return;
                }
                
                item.remove();
                
                if (currentCount - 1 === 0 && itemsContainer) {
                    itemsContainer.innerHTML = '<div class="w-param-array-empty"><p>暂无项目</p></div>';
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
        // 兼容 accept 为字符串（如 "footer-container" 或 "a,b,c"）或数组
        const rawAccept = slot.accept;
        const acceptCodes = Array.isArray(rawAccept)
            ? rawAccept
            : (typeof rawAccept === 'string' ? rawAccept.split(',').map(s => s.trim()).filter(Boolean) : []);
        const isExclusive = slot.exclusive === true || isExclusiveSlot(slotId, '');
        const isMultiple = slot.multiple === true;
        
        // 插槽模式标签
        let modeBadge = '';
        if (isExclusive) {
            modeBadge = '<span class="badge bg-warning text-dark"><i class="ri-lock-line"></i> 独占 (仅限1个部件)</span>';
        } else if (isMultiple) {
            modeBadge = '<span class="badge bg-info"><i class="ri-stack-line"></i> 可多个部件</span>';
        }
        
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
                        ${modeBadge ? `<div class="mt-1">${modeBadge}</div>` : ''}
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
                        <small>${isExclusive ? '独占插槽：新部件将替换现有部件' : '将部件拖入此区域'}</small>
                    </div>
                </div>
            </div>
        `;
        
        elements.configContent.innerHTML = html;
    }

    /**
     * 处理部件拖放到插槽（iframe 传入，委托 saveWidget）
     * @param {object} widget - 部件数据
     * @param {object} slot - 插槽数据 { id, position, name, exclusive }
     * @param {number|undefined} iframeSortOrder - iframe 计算的插入位置
     */
    async function handleWidgetDropped(widget, slot, iframeSortOrder) {
        const area = slot.position || slot.id;
        const slotId = slot.id;
        const exclusive = slot.exclusive === true || isExclusiveSlot(slotId, widget.code);

        // sort_order 优先级：独占=0 > iframe 传入 > 结构视图计算
        let sortOrder;
        if (exclusive) sortOrder = 0;
        else if (iframeSortOrder != null) sortOrder = iframeSortOrder;
        else sortOrder = getNextSlotSortOrder(slotId);

        return saveWidget({ area, slotId, widgetData: widget, sortOrder, exclusive, switchToPreview: false });
    }

    /**
     * 处理预览页面中选中部件
     */
    function handlePreviewWidgetSelected(data) {
        const layoutId = data.layoutId;
        const widgetCode = data.widgetCode;
        let widgetConfig = {};
        
        try {
            widgetConfig = JSON.parse(data.config || '{}');
        } catch (e) {
            widgetConfig = {};
        }

        // 打开配置模态框
        openConfigModalForLayout(layoutId, widgetCode, widgetConfig);
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
            // 没有 accept 限制 → 保留区域过滤结果，不做进一步筛选
            return;
        }

        // accept 包含 * 表示接受所有部件，等同于无限制
        if (acceptCodes.includes('*')) {
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

        const widgetList = elements.widgetList;
        if (!widgetList) return;

        let totalChecked = 0, totalMatched = 0, totalHidden = 0;

        // 简单逻辑：遍历所有部件，只显示插槽 accept 列表中的部件
        widgetList.querySelectorAll('.widget-group').forEach(group => {
            const groupContent = group.querySelector('.widget-group-content');
            if (!groupContent) {
                group.style.display = 'none';
                return;
            }

            const allWidgets = Array.from(groupContent.querySelectorAll('.widget-item'));
            let hasMatch = false;

            allWidgets.forEach(widget => {
                totalChecked++;
                const widgetCode = (widget.getAttribute('data-widget-code') || '').toLowerCase();
                const widgetSlot = (widget.getAttribute('data-widget-slot') || '').toLowerCase();
                // 已被区域过滤隐藏的，保持隐藏不参与
                if (widget.style.display === 'none') {
                    totalHidden++;
                    return;
                }
                
                // 简单精确匹配：code 或 slot 与 accept 码一致
                const isMatch = acceptCodes.some(ac => {
                    const code = ac.trim().toLowerCase();
                    return widgetCode === code || (widgetSlot && widgetSlot === code);
                });

                if (isMatch) {
                    hasMatch = true;
                    totalMatched++;
                    widget.style.display = '';
                    widget.classList.add('highlighted');
                } else {
                    widget.style.display = 'none';
                    totalHidden++;
                }
            });

            // 没有匹配部件的分组整体隐藏
            group.style.display = hasMatch ? '' : 'none';
            if (hasMatch && group.classList.contains('collapsed')) {
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

        // 移除所有高亮，恢复被隐藏的部件和分组
        document.querySelectorAll('.widget-item.highlighted').forEach(el => {
            el.classList.remove('highlighted');
        });
        document.querySelectorAll('.widget-item').forEach(el => {
            el.style.display = '';
        });
        document.querySelectorAll('.widget-group').forEach(el => {
            el.style.display = '';
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
     * 获取带页面类型参数的部件API URL（备用，优先使用 w_query）
     */
    function getWidgetsApiUrl() {
        const url = new URL(config.apiWidgets, window.location.origin);
        if (state.pageType) {
            url.searchParams.set('page_type', state.pageType);
        }
        return url.toString();
    }

    /**
     * 统一获取部件信息：优先直接 fetch(apiWidgets)，失败时用 w_query('widget','getAvailableList') 兜底
     * @returns {Promise<{success:boolean, data:Object}>}
     */
    async function fetchWidgetsData() {
        // 1) 优先使用主题编辑器自带接口（保证无 w_query 或 Query 路由异常时仍能加载）
        try {
            const response = await fetch(getWidgetsApiUrl());
            const result = await response.json();
            if (result && result.success && result.data && typeof result.data === 'object') {
                const hasWidgets = Object.keys(result.data).some(function (type) {
                    const group = result.data[type];
                    return group && Array.isArray(group.widgets) && group.widgets.length > 0;
                });
                if (hasWidgets) {
                    return result;
                }
            }
        } catch (err) {
            console.warn('[ThemeEditor] fetch widgets api failed, try w_query:', err);
        }
        // 2) 兜底：w_query（需页面已加载定义 w_query 的脚本）
        if (typeof window.w_query === 'function') {
            try {
                const data = await window.w_query('widget', 'getAvailableList', {
                    page_type: state.pageType || null
                }, { area: 'backend' });
                if (data && typeof data === 'object' && Object.keys(data).length > 0) {
                    return { success: true, data: data };
                }
            } catch (err) {
                console.warn('[ThemeEditor] w_query widget getAvailableList failed:', err);
            }
        }
        return { success: false, data: {} };
    }

    /**
     * 若 #widgetList 内无部件（服务端未输出），则请求部件列表并渲染到面板
     */
    async function loadWidgetListIfEmpty() {
        const listEl = elements.widgetList;
        if (!listEl) return;
        const hasItems = listEl.querySelector('.widget-item');
        if (hasItems) return;
        try {
            const result = await fetchWidgetsData();
            if (result.success && result.data && typeof result.data === 'object' && Object.keys(result.data).length > 0) {
                renderWidgetListToPanel(result.data);
            }
        } catch (err) {
            console.warn('[ThemeEditor] loadWidgetListIfEmpty failed:', err);
        }
    }

    /**
     * 将分组部件数据渲染到 #widgetList（与服务端模板结构一致）
     */
    function renderWidgetListToPanel(data) {
        const listEl = elements.widgetList;
        if (!listEl) return;
        const exclusiveSlots = ['logo', 'search', 'main-nav', 'header-container', 'footer-container', 'content-container'];
        let html = '';
        for (const type in data) {
            const group = data[type];
            if (!group || !Array.isArray(group.widgets)) continue;
            const groupLabel = (group.label || type || '').toString();
            const widgets = group.widgets;
            html += '<div class="widget-group" data-type="' + escapeHtml(type) + '">';
            html += '<div class="widget-group-header" data-toggle="collapse">';
            html += '<i class="ri-arrow-down-s-line toggle-icon"></i><span>' + escapeHtml(groupLabel) + '</span>';
            html += '<span class="widget-count">' + widgets.length + '</span></div>';
            html += '<div class="widget-group-content">';
            for (let i = 0; i < widgets.length; i++) {
                const w = widgets[i];
                if (!w || typeof w !== 'object') continue;
                const wCode = (w.code ?? '').toString();
                const wModule = (w.module ?? '').toString();
                const wType = (w.type ?? '').toString();
                const wName = (w.name ?? wCode ?? '').toString();
                const wDesc = (w.description ?? '').toString();
                const wSlot = (w.slot ?? '').toString();
                const wPageLayouts = w.page_layouts ?? ['*'];
                const wIsContainer = !!(w.is_container ?? false);
                const wExclusive = !!(w.exclusive ?? false) || (wSlot && exclusiveSlots.indexOf(wSlot) !== -1);
                const wCompatible = !!(w.compatible ?? false);
                const wPosition = w.position ?? [];
                const posJson = typeof wPosition === 'string' ? wPosition : JSON.stringify(wPosition);
                const layoutJson = typeof wPageLayouts === 'string' ? wPageLayouts : JSON.stringify(wPageLayouts);
                const previewHtml = (w.preview_html ?? '').toString();
                const itemClass = 'widget-item draggable' + (wIsContainer ? ' widget-container' : '') + (wExclusive ? ' widget-exclusive' : '');
                html += '<div class="' + itemClass + '" draggable="true"';
                html += ' data-widget-code="' + escapeHtml(wCode) + '" data-widget-module="' + escapeHtml(wModule) + '"';
                html += ' data-widget-type="' + escapeHtml(wType) + '" data-widget-name="' + escapeHtml(wName) + '"';
                html += ' data-widget-position="' + escapeHtml(posJson) + '" data-widget-compatible="' + (wCompatible ? '1' : '0') + '"';
                html += ' data-widget-slot="' + escapeHtml(wSlot) + '" data-widget-exclusive="' + (wExclusive ? '1' : '0') + '"';
                html += ' data-widget-page-layouts="' + escapeHtml(layoutJson) + '" data-widget-is-container="' + (wIsContainer ? '1' : '0') + '">';
                html += '<div class="widget-preview"><div class="widget-preview-canvas">' + previewHtml + '</div>';
                html += '<div class="widget-preview-overlay"><div class="widget-preview-title-row d-flex align-items-center justify-content-between gap-2">';
                html += '<div class="widget-preview-title">' + escapeHtml(wName);
                if (wIsContainer) html += ' <span class="badge badge-sm bg-primary ms-1" title="容器部件"><i class="ri-layout-grid-line"></i></span>';
                if (wExclusive) html += ' <span class="badge badge-sm bg-warning ms-1" title="独占部件"><i class="ri-focus-2-line"></i></span>';
                html += '</div>';
                html += '<button type="button" class="btn btn-sm btn-outline-secondary btn-preview-component flex-shrink-0" title="预览" data-widget-module="' + escapeHtml(wModule) + '" data-widget-code="' + escapeHtml(wCode) + '" data-widget-name="' + escapeHtml(wName) + '"><i class="ri-eye-line"></i></button>';
                html += '</div><div class="widget-preview-desc">' + escapeHtml(wDesc) + '</div></div></div></div>';
            }
            html += '</div></div>';
        }
        listEl.innerHTML = html || listEl.innerHTML;
        listEl.querySelectorAll('.widget-group-header').forEach(function (header) {
            header.addEventListener('click', function (e) {
                if (e.target.closest('.widget-item')) return;
                const group = this.closest('.widget-group');
                if (group) group.classList.toggle('collapsed');
            });
        });
        listEl.querySelectorAll('.widget-item.draggable').forEach(function (item) {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragend', handleDragEnd);
        });
        fitWidgetPreviews();
    }

    /**
     * 为已保存的布局打开配置模态框
     */
    async function openConfigModalForLayout(layoutId, widgetCode, currentConfig) {
        // 获取部件参数定义（优先 w_query）
        try {
            const result = await fetchWidgetsData();

            if (result.success) {
                navigateEditorShell({
                    page_type: state.pageType,
                    version_id: null,
                });
                return;
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
                    ${iconSvg('image')} 选择
                </button>
            </div>`;
            if (value) {
                html += `<div class="mt-2 image-preview-container">
                    <img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 100px;">
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="document.getElementById('config_${key}').value='';this.parentElement.remove();">
                        ${iconSvg('delete')}
                    </button>
                </div>`;
            }
        } else if (type === 'file') {
            html += `<div class="input-group">
                <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="文件路径">
                <button type="button" class="btn btn-outline-secondary btn-select-file" data-target="config_${key}" data-accept="${escapeHtml(param.accept || '*')}">
                    ${iconSvg('folder')} 浏览
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
                        <div class="preview-loading">${iconSvg('loader')} 加载中...</div>
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
                        ${iconSvg('save')} 保存配置
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
                previewBox.innerHTML = '<div class="preview-static-hint">' + (iconSvg('eye') || '') + ' 保存后预览更新</div>';
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
     * @param {string|null} targetSlotId 目标插槽ID（新部件时必传，避免依赖全局 state 导致插入错误区域）
     */
    function updateWidgetPreviewInIframe(layoutId, previewHtml, isNewWidget = false, targetSlotId = null) {
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
                    let contentEl = widgetEl.querySelector('.widget-content');
                    if (!contentEl) {
                        // 服务端输出没有 .widget-content 时，先移除旧内容（避免出现两段预览），再插入新容器
                        const actionsEl = widgetEl.querySelector('.widget-hover-actions');
                        while (widgetEl.firstChild) {
                            widgetEl.removeChild(widgetEl.firstChild);
                        }
                        if (actionsEl) {
                            widgetEl.appendChild(actionsEl);
                        }
                        contentEl = iframe.contentDocument.createElement('div');
                        contentEl.className = 'widget-content';
                        widgetEl.appendChild(contentEl);
                    }
                    contentEl.innerHTML = previewHtml;
                    console.log(`[ThemeEditor] Widget ${layoutId} preview updated successfully`);
                    
                    // 高亮更新的部件（短暂视觉反馈）
                    widgetEl.classList.add('widget-updated');
                    setTimeout(() => widgetEl.classList.remove('widget-updated'), 1000);
                } else if (isNewWidget) {
                    // 新部件 - 尝试在对应插槽中插入（优先使用调用方传入的 targetSlotId，避免依赖全局 state 导致插入到错误区域）
                    const slotId = targetSlotId || state.selectedSlot?.id || state.draggingWidget?.slot;
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
                        
                        // 判断是否独占（从 DOM 属性或 isExclusiveSlot 判断）
                        const isExclusive = slotEl.getAttribute('data-wslot-exclusive') === 'true' 
                            || isExclusiveSlot(slotId, state.draggingWidget?.code || '');
                        
                        // 生成操作按钮
                        const actionsHtml = generateWidgetHoverActionsHtml(layoutId, slotId, isExclusive, true, true);
                        
                        // 组装部件内容
                        wrapper.innerHTML = actionsHtml + '<div class="widget-content">' + previewHtml + '</div>';
                        
                        // 整块区域插槽（footer/header）：模板中整块 <footer>/<header> 带 data-wslot，
                        // 若按独占清空会抹掉全部底部/顶部内容，导致“整块变白”。只追加到内部容器，绝不清空整块。
                        const isContainerSlot = slotId === 'footer' || slotId === 'header' ||
                            slotEl.querySelector('.footer-container, .header-container, .footer-inner, .header-inner');
                        const widgetContainer = isContainerSlot
                            ? (slotEl.querySelector('.footer-slot-widgets, .header-slot-widgets, .area-widgets, .slot-widgets') || slotEl)
                            : slotEl;

                        if (isExclusive && !isContainerSlot) {
                            // 独占模式（且非整块区域）：清空插槽后替换为新部件
                            slotEl.innerHTML = '';
                            slotEl.appendChild(wrapper);
                        } else {
                            // 非独占 或 整块区域插槽：只追加，保留原内容
                            widgetContainer.querySelectorAll('.slot-placeholder').forEach(p => p.remove());
                            widgetContainer.appendChild(wrapper);
                        }
                        
                        // 绑定按钮事件（如果还没有绑定）
                        if (!iframe.contentDocument.body._widgetActionsInitialized) {
                            bindWidgetActionEvents(iframe.contentDocument);
                            iframe.contentDocument.body._widgetActionsInitialized = true;
                        }
                        
                        console.log(`[ThemeEditor] New widget ${layoutId} inserted into slot ${slotId} with hover actions`);

                        // 为新插入的部件启用 draggable（委托已绑在 body 上，无需再绑事件）
                        setDraggableOnSlotWidgets(iframe.contentDocument);
                        
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
        if (viewType === 'structure' && state.previewStatus !== 'draft') {
            showToast('已发布预览下仅支持实时预览视图', 'info');
            viewType = 'preview';
        }
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

    // ========== 拖拽排序辅助函数 ==========

    /**
     * 从插槽 DOM 元素提取完整的插槽信息
     * @param {Element} slotEl 插槽 DOM 元素
     * @returns {object} 插槽属性
     */
    function getSlotInfo(slotEl) {
        if (!slotEl) return { exclusive: false, multiple: true, max: -1, currentCount: 0 };
        
        const exclusiveAttr = slotEl.dataset.wslotExclusive || slotEl.dataset.slotExclusive || slotEl.dataset.exclusive;
        const multipleAttr = slotEl.dataset.wslotMultiple || slotEl.dataset.slotMultiple || slotEl.dataset.multiple;
        const maxAttr = slotEl.dataset.wslotMax || slotEl.dataset.slotMax;
        const slotId = slotEl.dataset.wslot || slotEl.dataset.slot;
        
        const exclusive = exclusiveAttr === 'true';
        const multiple = multipleAttr !== 'false'; // 默认允许多个
        const max = maxAttr ? parseInt(maxAttr, 10) : -1; // -1 表示无限制
        
        // 如果 exclusive 为 true，max 固定为 1
        const effectiveMax = exclusive ? 1 : max;
        
        // 统计当前已有部件数量
        let currentCount = 0;
        if (slotEl.classList.contains('preview-area') || slotEl.classList.contains('area-slot')) {
            // 结构视图区域
            const widgetsContainer = slotEl.querySelector('.area-widgets');
            if (widgetsContainer) {
                currentCount = widgetsContainer.querySelectorAll('.preview-widget-item').length;
            }
        } else {
            // 容器插槽
            const slotWidgets = slotEl.querySelector('.slot-widgets');
            if (slotWidgets) {
                currentCount = slotWidgets.querySelectorAll('.preview-widget-item').length;
            } else {
                currentCount = slotEl.querySelectorAll('.preview-widget-item, .widget-wrapper[data-layout-id]').length;
            }
        }
        
        return {
            slotId,
            exclusive,
            multiple,
            max: effectiveMax,
            currentCount,
            isFull: effectiveMax > 0 && currentCount >= effectiveMax,
        };
    }

    /**
     * 计算鼠标在部件列表中的插入位置索引
     * @param {Element} container 包含部件项的容器
     * @param {number} mouseY 鼠标 Y 坐标（clientY）
     * @returns {number} 插入索引（0 = 最前面）
     */
    function getInsertionIndex(container, mouseY) {
        const items = container.querySelectorAll('.preview-widget-item');
        if (items.length === 0) return 0;
        
        for (let i = 0; i < items.length; i++) {
            const rect = items[i].getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            if (mouseY < midY) {
                return i;
            }
        }
        return items.length; // 插入到最后
    }

    /**
     * 显示插入位置指示器
     * @param {Element} container 部件容器
     * @param {number} mouseY 鼠标 Y 坐标
     */
    function showInsertionIndicator(container, mouseY) {
        // 先移除旧的指示器
        removeInsertionIndicators(container);
        
        const items = container.querySelectorAll('.preview-widget-item');
        if (items.length === 0) {
            // 空容器：显示整体高亮
            container.classList.add('drag-insert-empty');
            return;
        }
        
        const insertIndex = getInsertionIndex(container, mouseY);
        
        // 创建指示器
        const indicator = document.createElement('div');
        indicator.className = 'drag-insert-indicator';
        indicator.innerHTML = '<span class="drag-insert-dot"></span><span class="drag-insert-line"></span><span class="drag-insert-dot"></span>';
        
        if (insertIndex < items.length) {
            items[insertIndex].before(indicator);
        } else {
            container.appendChild(indicator);
        }
        
        // 保存插入索引
        state.dragInsertIndex = insertIndex;
    }

    /**
     * 移除所有插入位置指示器
     * @param {Element} [container] 限定范围，不传则移除所有
     */
    function removeInsertionIndicators(container) {
        const scope = container || document;
        scope.querySelectorAll('.drag-insert-indicator').forEach(el => el.remove());
        scope.querySelectorAll('.drag-insert-empty').forEach(el => el.classList.remove('drag-insert-empty'));
        // 移除独占/满额的提示标签
        scope.querySelectorAll('.drag-slot-hint').forEach(el => el.remove());
    }

    /**
     * 显示插槽状态提示（独占替换 / 已满）
     * @param {Element} slotEl 插槽元素
     * @param {string} text 提示文字
     * @param {string} type 'replace' | 'full'
     */
    function showSlotHint(slotEl, text, type) {
        // 避免重复
        slotEl.querySelectorAll('.drag-slot-hint').forEach(el => el.remove());
        
        const hint = document.createElement('div');
        hint.className = `drag-slot-hint drag-slot-hint-${type}`;
        hint.textContent = text;
        slotEl.appendChild(hint);
    }

    // ========== 拖拽数据工具函数（SOLID: 单一职责，共享逻辑抽取） ==========

    /**
     * 从拖拽事件提取部件数据
     * 优先使用 state.draggingWidget（同页面拖拽），回退到 dataTransfer（跨 frame）
     * @param {DragEvent} e
     * @returns {Object|null}
     */
    function getDropWidgetData(e) {
        let data = state.draggingWidget;
        if (!data) {
            try {
                const json = e.dataTransfer.getData('application/json');
                if (json) data = JSON.parse(json);
            } catch (err) { /* ignore */ }
        }
        return data || null;
    }

    /**
     * 解析插槽元素（向上查找最近的 [data-wslot] 或 .container-slot）
     * @param {Element} el
     * @returns {Element|null}
     */
    function resolveSlotElement(el) {
        if (el.dataset && (el.dataset.wslot || el.classList.contains('container-slot'))) return el;
        return el.closest('[data-wslot]') || el.closest('.container-slot');
    }

    /**
     * 检查插槽是否接受该部件（accept/reject 规则）
     * @param {Element} slot DOM 元素
     * @param {Object} widgetData 部件数据
     * @returns {boolean}
     */
    function isSlotAccepted(slot, widgetData) {
        const acceptAttr = slot.dataset.wslotAccept || slot.dataset.accept || '';
        const acceptCodes = acceptAttr ? acceptAttr.split(',').map(s => s.trim()).filter(Boolean) : [];
        const rejectAttr = slot.dataset.wslotReject || '';
        const rejectCodes = rejectAttr ? rejectAttr.split(',').map(s => s.trim()).filter(Boolean) : [];
        const slotId = slot.dataset.wslot || slot.dataset.slot;

        const rejected = rejectCodes.includes(widgetData.type) || rejectCodes.includes(widgetData.code);
        if (rejected) return false;
        // 通配：accept="*" 表示接受任意部件
        if (acceptCodes.includes('*')) return true;
        return (
            (widgetData.slot && widgetData.slot === slotId) ||
            acceptCodes.includes(widgetData.code) ||
            acceptCodes.length === 0
        );
    }

    /**
     * 统一的部件保存函数 — 所有拖拽保存的唯一出口
     * 职责：API 持久化 + 结构视图更新 + 预览刷新
     *
     * @param {Object} params
     * @param {string} params.area - 区域代码 (header/content/footer)
     * @param {string|null} params.slotId - 插槽ID
     * @param {Object} params.widgetData - 部件数据 {code, module, type, name, ...}
     * @param {number} params.sortOrder - 排序顺序
     * @param {boolean} params.exclusive - 是否独占替换
     * @param {boolean} [params.switchToPreview=true] - 保存后是否切换到预览视图
     * @returns {Promise<Object|null>} 保存结果，失败返回 null
     */
    async function saveWidget({ area, slotId, widgetData, sortOrder, exclusive, switchToPreview = true }) {

        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return null;
        }
        if (state.saveInProgress) {
            showToast('正在保存中，请稍候', 'info');
            return null;
        }
        state.saveInProgress = true;

        const payload = {
            theme_id: state.themeId,
            page_type: state.pageType,
            area: area,
            slot_id: slotId || null,
            widget_code: widgetData.code,
            widget_module: widgetData.module,
            widget_type: widgetData.type || '',
            config: widgetData.config || {},
            sort_order: sortOrder,
            exclusive: exclusive,
        };

        try {
            const response = await fetch(config.apiSaveWidget, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            const result = await response.json();


            if (result.success) {
                const widgetName = widgetData.name || widgetData.code;
                const displaySlot = slotId || area;
                showToast(
                    exclusive ? `${widgetName} 已替换到 ${displaySlot}` : `${widgetName} 添加成功`,
                    'success'
                );

                const layoutId = result.data?.layout_id;
                if (layoutId) {
                    addWidgetToStructureView(area, slotId, widgetData, layoutId, exclusive);
                }

                if (switchToPreview) {
                    switchPreviewView('preview');
                }

                if (result.preview_html && layoutId) {
                    // 结构视图拖入时 slotId 可能为 null，用 area 推导插槽 ID，避免找不到插槽而整页刷新导致 footer 变白
                    const targetSlotId = slotId ?? (area === 'footer' ? 'footer' : area === 'header' ? 'header' : null);
                    updateWidgetPreviewInIframe(layoutId, result.preview_html, true, targetSlotId);
                } else {
                    loadLayoutPreview();
                }

                return result;
            } else {
                showToast(result.message || '添加失败', 'error');
                return null;
            }
        } catch (err) {
            console.error('保存部件失败:', err);
            showToast('保存部件失败', 'error');
            return null;
        } finally {
            state.saveInProgress = false;
        }
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
        state.draggingWidget = null;
        state.dragInsertIndex = null;
        this.classList.remove('dragging');

        // 移除区域高亮
        document.querySelectorAll('.preview-area').forEach(area => {
            area.classList.remove('drag-over', 'drag-invalid', 'drag-allowed', 'drag-replace');
        });

        // 移除插槽高亮（支持新旧两种标记方式）
        document.querySelectorAll('.container-slot, [data-wslot]').forEach(slot => {
            slot.classList.remove('drag-over', 'drag-invalid', 'drag-allowed', 'drag-replace');
        });
        
        // 移除所有插入位置指示器和提示
        removeInsertionIndicators();
    }

    /**
     * 拖拽经过 — 区域级别
     * 支持：多部件区域显示插入位置指示器、独占区域显示替换提示、满额区域显示已满提示
     */
    function handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const area = this.classList.contains('preview-area') ? this : this.closest('.preview-area');
        if (!area) return;
        
        const areaCode = area.dataset.area;
        if (!areaCode) return;
        
        const widgetData = state.draggingWidget;
        if (!widgetData) {
            e.dataTransfer.dropEffect = 'none';
            area.classList.add('drag-invalid');
            area.classList.remove('drag-over');
            return;
        }
        
        // 检查是否允许放置
        const allowed = isAllowedArea(widgetData.position, areaCode, widgetData.type);
        if (!allowed) {
            e.dataTransfer.dropEffect = 'none';
            area.classList.add('drag-invalid');
            area.classList.remove('drag-over');
            removeInsertionIndicators(area);
            return;
        }
        
        // 获取插槽信息（exclusive / max / currentCount）
        const info = getSlotInfo(area);
        
        // 独占插槽：显示替换提示
        if (info.exclusive && info.currentCount > 0) {
            e.dataTransfer.dropEffect = 'copy';
            area.classList.add('drag-over', 'drag-replace');
            area.classList.remove('drag-invalid');
            removeInsertionIndicators(area);
            showSlotHint(area, '松开替换现有部件', 'replace');
            return;
        }
        
        // 已满插槽：阻止放置
        if (info.isFull) {
            e.dataTransfer.dropEffect = 'none';
            area.classList.add('drag-invalid');
            area.classList.remove('drag-over', 'drag-replace');
            removeInsertionIndicators(area);
            showSlotHint(area, `已满（${info.currentCount}/${info.max}）`, 'full');
            return;
        }
        
        // 多部件区域：显示插入位置指示器
        e.dataTransfer.dropEffect = 'copy';
        area.classList.add('drag-over');
        area.classList.remove('drag-invalid', 'drag-replace');
        
        const widgetsContainer = area.querySelector('.area-widgets');
        if (widgetsContainer) {
            showInsertionIndicator(widgetsContainer, e.clientY);
        }
    }

    /**
     * 拖拽离开
     */
    function handleDragLeave(e) {
        const area = this.classList.contains('preview-area') ? this : this.closest('.preview-area');
        if (area) {
            // 只有当真正离开区域时才清理
            if (!area.contains(e.relatedTarget)) {
                area.classList.remove('drag-over', 'drag-replace');
                removeInsertionIndicators(area);
            }
        }
    }

    /**
     * 放置 — 区域级别
     * 支持排序插入、独占替换、满额阻止
     */
    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation(); // 防止同一 drop 触发多次保存

        // 检查是否是插槽区域的放置（由 handleSlotDrop 处理）
        if (e.target.closest('[data-wslot], .container-slot, .slot-widgets')) return;

        const area = this.classList.contains('preview-area') ? this : this.closest('.preview-area');
        if (!area) return;

        // 清理视觉状态
        area.classList.remove('drag-over', 'drag-invalid', 'drag-replace');
        removeInsertionIndicators(area);

        const areaCode = area.dataset.area;
        if (!areaCode) return;

        // 获取部件数据（委托 getDropWidgetData）
        const widgetData = getDropWidgetData(e);
        if (!widgetData) {
            showToast('无法获取部件数据', 'error');
            return;
        }

        // 权限检查
        if (!isAllowedArea(widgetData.position, areaCode, widgetData.type)) {
            showToast('该部件不能放置在此区域', 'warning');
            return;
        }

        // 插槽状态检查
        const info = getSlotInfo(area);

        // 满额检查（独占插槽不受此限制，会走替换逻辑）
        if (!info.exclusive && info.isFull) {
            showToast(`插槽已满（${info.currentCount}/${info.max}），无法添加更多部件`, 'warning');
            return;
        }

        // 使用拖拽时计算的插入索引，如果没有则追加到末尾
        const sortOrder = state.dragInsertIndex != null ? state.dragInsertIndex : getNextSortOrder(areaCode);
        state.dragInsertIndex = null;

        saveWidget({ area: areaCode, slotId: null, widgetData, sortOrder, exclusive: info.exclusive });
    }

    /**
     * 容器内插槽 - 拖拽经过
     * 支持：accept/reject 过滤、独占替换提示、满额阻止、多部件排序指示
     */
    function handleSlotDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const slot = resolveSlotElement(this);
        if (!slot) return;
        
        const widgetData = state.draggingWidget;
        if (!widgetData) {
            e.dataTransfer.dropEffect = 'none';
            return;
        }
        
        // accept / reject 检查（委托 isSlotAccepted）
        if (!isSlotAccepted(slot, widgetData)) {
            e.dataTransfer.dropEffect = 'none';
            slot.classList.add('drag-invalid');
            slot.classList.remove('drag-over', 'drag-replace');
            removeInsertionIndicators(slot);
            return;
        }
        
        // 获取插槽容量信息
        const info = getSlotInfo(slot);
        
        // 独占插槽：有部件时显示替换提示
        if (info.exclusive && info.currentCount > 0) {
            e.dataTransfer.dropEffect = 'copy';
            slot.classList.add('drag-over', 'drag-replace');
            slot.classList.remove('drag-invalid');
            removeInsertionIndicators(slot);
            showSlotHint(slot, '松开替换现有部件', 'replace');
            return;
        }
        
        // 已满：阻止放置
        if (info.isFull) {
            e.dataTransfer.dropEffect = 'none';
            slot.classList.add('drag-invalid');
            slot.classList.remove('drag-over', 'drag-replace');
            removeInsertionIndicators(slot);
            showSlotHint(slot, `已满（${info.currentCount}/${info.max}）`, 'full');
            return;
        }
        
        // 多部件插槽：显示插入位置指示器
        e.dataTransfer.dropEffect = 'copy';
        slot.classList.add('drag-over');
        slot.classList.remove('drag-invalid', 'drag-replace');
        
        // 在插槽的部件容器中显示插入指示器
        const widgetsContainer = slot.querySelector('.slot-widgets') || slot;
        showInsertionIndicator(widgetsContainer, e.clientY);
    }

    /**
     * 容器内插槽 - 拖拽离开
     */
    function handleSlotDragLeave(e) {
        const slot = resolveSlotElement(this);
        if (slot) {
            if (!slot.contains(e.relatedTarget)) {
                slot.classList.remove('drag-over', 'drag-invalid', 'drag-replace');
                removeInsertionIndicators(slot);
            }
        }
    }

    /**
     * 容器内插槽 - 放置
     * 支持：accept/reject 过滤、独占替换、满额阻止、排序插入
     */
    function handleSlotDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation(); // 防止同一 drop 触发多次保存

        const slot = resolveSlotElement(this);
        if (!slot) return;

        // 清理视觉状态
        slot.classList.remove('drag-over', 'drag-invalid', 'drag-replace');
        removeInsertionIndicators(slot);

        const slotId = slot.dataset.wslot || slot.dataset.slot;
        const slotName = slot.dataset.wslotName || slotId;
        const areaCode = slot.dataset.area || slot.closest('.preview-area')?.dataset.area || 'content';
        if (!slotId) return;

        // 获取部件数据（委托 getDropWidgetData）
        const widgetData = getDropWidgetData(e);
        if (!widgetData) {
            showToast('无法获取部件数据', 'error');
            return;
        }

        // accept / reject 验证（委托 isSlotAccepted）
        if (!isSlotAccepted(slot, widgetData)) {
            showToast(`部件 "${widgetData.name}" 不能放入插槽 "${slotName}"`, 'warning');
            return;
        }

        // 获取插槽容量信息
        const info = getSlotInfo(slot);

        // 独占替换
        if (info.exclusive) {
            saveWidget({ area: areaCode, slotId, widgetData, sortOrder: 0, exclusive: true });
            return;
        }

        // 满额阻止
        if (info.isFull) {
            showToast(`插槽 "${slotName}" 已满（${info.currentCount}/${info.max}），无法添加更多部件`, 'warning');
            return;
        }

        // 多部件插槽：使用拖拽位置决定排序
        const sortOrder = state.dragInsertIndex != null ? state.dragInsertIndex : getNextSlotSortOrder(slotId);
        state.dragInsertIndex = null;

        saveWidget({ area: areaCode, slotId, widgetData, sortOrder, exclusive: false });
    }

    /**
     * 添加部件到容器内插槽（委托 saveWidget）
     * @param {string} area 区域代码
     * @param {string} slotId 插槽ID
     * @param {object} widgetData 部件数据
     * @param {object} options 选项 { exclusive, sort_order }
     */
    async function addWidgetToSlot(area, slotId, widgetData, options = {}) {
        const exclusive = options.exclusive !== undefined
            ? options.exclusive
            : (widgetData.exclusive || isExclusiveSlot(slotId, widgetData.code));
        const sortOrder = options.sort_order != null ? options.sort_order : getNextSlotSortOrder(slotId);

        return saveWidget({ area, slotId, widgetData, sortOrder, exclusive });
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
                            ${iconSvg('edit')}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-widget" title="删除">
                            ${iconSvg('delete')}
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
        // 注意：container 类型部件应严格按其 position 属性过滤
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
            'social': ['footer', 'left_sidebar', 'right_sidebar', 'content'],
            'newsletter': ['footer', 'left_sidebar', 'right_sidebar', 'content'],
            'testimonial': ['content'],
            'faq': ['content'],
            'video': ['content', 'banner'],
            'content': ['content', 'left_sidebar', 'right_sidebar'],
            'container': [],  // container 类型必须有明确的 position，不使用 type 推断
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
                // container 类型没有 position 时不允许放置（必须有明确 position）
                if (widgetType === 'container' && allowedAreas.length === 0) {
                    console.log('highlightAllowedAreas - container type requires explicit position');
                } else {
                    console.log('highlightAllowedAreas - inferred from type:', widgetType, '-> areas:', allowedAreas);
                }
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
        // 注意：container 类型部件应严格按其 position 属性过滤
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
            'social': ['footer', 'left_sidebar', 'right_sidebar', 'content'],
            'newsletter': ['footer', 'left_sidebar', 'right_sidebar', 'content'],
            'testimonial': ['content'],
            'faq': ['content'],
            'video': ['content', 'banner'],
            'content': ['content', 'left_sidebar', 'right_sidebar'],
            'container': [],  // container 类型必须有明确的 position，不使用 type 推断
        };
        
        // 如果没有 position 限制，根据 type 推断
        if (!positions || !Array.isArray(positions) || positions.length === 0) {
            if (widgetType && typeToAreasMap[widgetType]) {
                // 使用类型推断的区域
                // 注意：container 类型在 typeToAreasMap 中为空数组，必须有明确 position
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
     * 添加部件到区域（委托 saveWidget）
     * @param {string} area 区域代码
     * @param {object} widgetData 部件数据
     * @param {object} options 选项 { slotId, exclusive, sort_order }
     */
    async function addWidget(area, widgetData, options = {}) {
        const slotId = options.slotId || widgetData.slot || null;
        const exclusive = options.exclusive !== undefined ? options.exclusive : isExclusiveSlot(slotId, widgetData.code);
        const sortOrder = options.sort_order != null ? options.sort_order : getNextSortOrder(area);

        return saveWidget({ area, slotId, widgetData, sortOrder, exclusive });
    }

    /**
     * 判断插槽是否为独占类型（兜底逻辑）
     * 
     * 优先从插槽 DOM 的 data-wslot-exclusive="true" 判断，
     * 此函数仅作为 DOM 属性不可用时的兜底。
     * 
     * 独占插槽：同一插槽只能有一个部件，新部件会替换旧部件。
     * 
     * 与模板保持一致：
     * - exclusive=true 的插槽：header, logo, search, navigation, footer,
     *   footer-social, footer-copyright, widget-hero, list-grid, list-pagination
     * - multiple=true 的插槽：user-area, footer-links, widget-featured,
     *   widget-main, widget-sidebar-*, widget-bottom 等
     */
    function isExclusiveSlot(slotId, widgetCode) {
        // 独占插槽列表 — 与 <w:slot exclusive="true"> 和 data-wslot-exclusive="true" 一致
        const exclusiveSlots = [
            // Header 区域
            'header',             // 整体头部
            'logo',               // Logo 只能有一个
            'search',             // 搜索框只能有一个
            'navigation',         // 导航菜单只能有一个
            // Footer 区域
            'footer',             // 整体底部
            'footer-social',      // 社交媒体只能有一个
            'footer-copyright',   // 版权信息只能有一个
            // Content 容器
            'widget-hero',        // Hero 轮播只能有一个
            // 产品列表页
            'list-grid',          // 产品网格只能有一个
            'list-pagination',    // 分页只能有一个
        ];

        // 独占部件 code：这些部件自身声明了独占
        const exclusiveWidgets = [
            'logo',
            'header-container',
            'footer-container',
            'full-header',
            'content-container',
            'footer-copyright',
            'footer-social',
            'footer-payment',
            'footer-newsletter',
            'header-search',
            'main-nav',
            'category-menu',
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
        moveDown: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 13.1716L16.9497 8.22185L15.5355 6.80764L12 10.3431L8.46447 6.80764L7.05025 8.22185L12 13.1716ZM12 18L17.6569 12.3432L16.2426 10.929L12 15.1716L7.75736 10.929L6.34315 12.3432L12 18Z"></path></svg>`,
        info: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-1-11v6h2v-6h-2zm0-4v2h2V7h-2z"/></svg>`,
        penetrateUp: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 10.828L7.05 15.778 5.636 14.364 12 8l6.364 6.364L16.95 15.778 12 10.828z"/></svg>`,
        penetrateDown: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 13.172l4.95-4.95 1.414 1.414L12 16l-6.364-6.364L7.05 10.222 12 13.172z"/></svg>`
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
        
        // 嵌套距离：信息、上级、下级（仅这三者按栈下标；替换/删除/移动仍用最外层）
        html += `<button class="btn-widget-info" title="信息" data-action="info" data-layout-id="${layoutId}">
                    ${WIDGET_ACTION_ICONS.info}
                 </button>`;
        html += `<button class="btn-penetrate-up" title="上级" data-action="penetrate-up" style="display:none;">
                    ${WIDGET_ACTION_ICONS.penetrateUp}
                 </button>`;
        html += `<button class="btn-penetrate-down" title="下穿" data-action="penetrate-down" style="display:none;">
                    ${WIDGET_ACTION_ICONS.penetrateDown}
                 </button>`;
        
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
     * 用 elementsFromPoint 得到该点从外到内的 .widget-wrapper 层级栈（嵌套距离）
     * @param {Document} doc - iframe contentDocument
     * @param {number} x - clientX
     * @param {number} y - clientY
     * @returns {string[]} layoutId 数组，[0] 最外
     */
    function getWidgetStackAtPoint(doc, x, y) {
        if (!doc || typeof doc.elementsFromPoint !== 'function') return [];
        const list = doc.elementsFromPoint(x, y);
        const seen = new Set();
        const stack = [];
        for (const el of list) {
            const wrapper = el.closest && el.closest('.widget-wrapper[data-layout-id]');
            if (!wrapper) continue;
            const id = wrapper.getAttribute('data-layout-id');
            if (id && !seen.has(id)) {
                seen.add(id);
                stack.push(id);
            }
        }
        return stack;
    }

    /**
     * 按嵌套距离（栈下标）决定在哪一层显示操作条：仅给 stack[nestIndex] 的 wrapper 加 .show-actions
     */
    function setShowActionsByNest() {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        const doc = iframe.contentDocument;
        doc.querySelectorAll('.widget-wrapper.show-actions').forEach(w => w.classList.remove('show-actions'));
        const stack = state.nestStack;
        const idx = state.nestIndex;
        if (!stack.length || idx < 0 || idx >= stack.length) return;
        const layoutId = stack[idx];
        const wrapper = doc.querySelector(`.widget-wrapper[data-layout-id="${layoutId}"]`) ||
            doc.querySelector(`[data-layout-id="${layoutId}"]`);
        if (wrapper) {
            wrapper.classList.add('show-actions');
        }
        updateNestButtons();
    }

    /**
     * 更新当前可见操作条上的「上级/下级」按钮显隐
     */
    function updateNestButtons() {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;
        const doc = iframe.contentDocument;
        const bar = doc.querySelector('.widget-wrapper.show-actions .widget-hover-actions');
        if (!bar) return;
        const upBtn = bar.querySelector('.btn-penetrate-up');
        const downBtn = bar.querySelector('.btn-penetrate-down');
        if (!upBtn || !downBtn) return;
        const stack = state.nestStack;
        const idx = state.nestIndex;
        upBtn.style.display = idx > 0 ? '' : 'none';
        downBtn.style.display = stack.length > 1 && idx < stack.length - 1 ? '' : 'none';
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
            .widget-wrapper.show-actions {
                outline: 2px solid #4a90d9;
                outline-offset: 2px;
                z-index: 100;
            }
            .widget-wrapper.selected {
                outline: 2px solid #4a90d9;
                outline-offset: 2px;
                box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.2);
            }
            /* Hover 操作按钮容器 - hover 时显示，嵌套时仅显示当前悬停层 */
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
            .widget-wrapper.show-actions .widget-hover-actions {
                display: flex;
            }
            /* 部件 hover 时显示操作栏（删除、排序、拖拽等），仅直接子级工具栏显示 */
            .widget-wrapper:hover > .widget-hover-actions {
                display: flex !important;
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
        
        // 同时注入 Remix Icon 如果不存在（使用本地资源，禁止外网 CDN）
        if (!iframeDoc.querySelector('link[href*="remixicon"]')) {
            const link = iframeDoc.createElement('link');
            link.rel = 'stylesheet';
            link.href = (typeof window.WELINE_REMIXICON_CSS_URL !== 'undefined' && window.WELINE_REMIXICON_CSS_URL)
                ? window.WELINE_REMIXICON_CSS_URL
                : (document.querySelector('link[href*="remixicon"]')?.href || '');
            if (link.href) {
                iframeDoc.head.appendChild(link);
            }
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
        
        // 查找所有部件包装器（服务端输出 .widget-wrapper[data-layout-id]；无 class 时用 [data-layout-id] 且无父级同属性避免重复）
        let widgetWrappers = Array.from(iframeDoc.querySelectorAll('.widget-wrapper[data-layout-id]'));
        if (widgetWrappers.length === 0) {
            widgetWrappers = Array.from(iframeDoc.querySelectorAll('[data-layout-id]')).filter(function (el) {
                var parent = el.parentElement;
                return !parent || !parent.closest('[data-layout-id]');
            });
        }
        
        widgetWrappers.forEach((wrapper) => {
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
        
        // 嵌套距离：mousemove 用 elementsFromPoint 更新栈，按 nestIndex 决定在哪一层显示操作条
        bindPenetrateStateEvents(iframeDoc);
        
        // 绑定 slot 点击事件（选中 slot 后过滤部件并滚动）
        bindSlotClickEvents(iframeDoc);
        
        // 初始化拖拽排序
        initWidgetSortable();
        
        console.log('[ThemeEditor] Widget hover actions initialized,', widgetWrappers.length, 'widgets processed');
    }

    /**
     * 绑定嵌套距离：mousemove 用 elementsFromPoint 更新栈，按 nestIndex 决定在哪一层显示操作条
     */
    function bindPenetrateStateEvents(doc) {
        if (!doc || !doc.body) return;
        if (doc.body._penetrateStateBound) return;
        doc.body._penetrateStateBound = true;
        
        doc.body.addEventListener('mousemove', function(e) {
            state.lastHoverPoint = { x: e.clientX, y: e.clientY };
            const stack = getWidgetStackAtPoint(doc, e.clientX, e.clientY);
            const prevTop = state.nestStack[0];
            state.nestStack = stack;
            if (stack[0] !== prevTop) {
                state.nestIndex = 0;
            }
            if (!stack.length) {
                doc.querySelectorAll('.widget-wrapper.show-actions').forEach(w => w.classList.remove('show-actions'));
                return;
            }
            setShowActionsByNest();
        });
        
        doc.body.addEventListener('mouseleave', function() {
            state.nestStack = [];
            state.nestIndex = 0;
            state.lastHoverPoint = null;
            doc.querySelectorAll('.widget-wrapper.show-actions').forEach(w => w.classList.remove('show-actions'));
        });
    }

    /**
     * 绑定部件操作按钮事件
     */
    function bindWidgetActionEvents(doc) {
        // 防止重复绑定 — 每个 iframe document 只绑定一次
        if (doc.body._widgetActionsEventsBound) return;
        doc.body._widgetActionsEventsBound = true;
        
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
            const bar = button.closest('.widget-hover-actions');
            const layoutId = bar ? bar.dataset.layoutId : button.dataset.layoutId;
            const slotId = button.dataset.slotId;
            
            switch (action) {
                case 'info': {
                    const effectiveLayoutId = (state.nestStack && state.nestStack.length && state.nestStack[state.nestIndex] != null)
                        ? state.nestStack[state.nestIndex]
                        : layoutId;
                    const item = document.querySelector(`.preview-widget-item[data-layout-id="${effectiveLayoutId}"]`);
                    if (item) {
                        selectWidget(item);
                    } else {
                        loadWidgetConfigForAccordion(effectiveLayoutId);
                        const accordionBody = document.querySelector(`.slot-widget-body[data-layout-id="${effectiveLayoutId}"]`);
                        if (accordionBody && accordionBody.closest('.collapse')) {
                            const collapse = accordionBody.closest('.collapse');
                            if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                                const bs = bootstrap.Collapse.getOrCreateInstance(collapse);
                                if (bs) bs.show();
                            }
                        }
                    }
                    break;
                }
                case 'penetrate-up':
                    if (state.nestIndex > 0) {
                        state.nestIndex--;
                        setShowActionsByNest();
                    }
                    break;
                case 'penetrate-down':
                    if (state.nestIndex < state.nestStack.length - 1) {
                        state.nestIndex++;
                        setShowActionsByNest();
                    }
                    break;
                case 'replace':
                case 'delete':
                case 'move-up':
                case 'move-down': {
                    const topmostLayoutId = (state.nestStack && state.nestStack[0]) ? state.nestStack[0] : layoutId;
                    let topmostSlotId = slotId;
                    const iframe = elements.previewFrame;
                    if (iframe && iframe.contentDocument) {
                        const topW = iframe.contentDocument.querySelector(`[data-layout-id="${topmostLayoutId}"]`);
                        if (topW) {
                            topmostSlotId = topW.getAttribute('data-slot-id') ||
                                topW.closest('[data-wslot]')?.getAttribute('data-wslot') ||
                                topW.closest('[data-slot]')?.getAttribute('data-slot') || slotId;
                        }
                    }
                    if (action === 'replace') handleWidgetReplace(topmostLayoutId, topmostSlotId);
                    else if (action === 'delete') handleWidgetDelete(topmostLayoutId, topmostSlotId);
                    else if (action === 'move-up') handleWidgetMoveUp(topmostLayoutId);
                    else if (action === 'move-down') handleWidgetMoveDown(topmostLayoutId);
                    break;
                }
            }
        }, true); // 使用捕获阶段
    }

    /**
     * 绑定 iframe 内 slot 的点击事件
     * 点击 slot 时：选中该 slot，过滤右侧部件列表，并滚动到匹配的部件
     */
    function bindSlotClickEvents(doc) {
        if (!doc || !doc.body) return;
        
        // 防止重复绑定 — 每个 iframe document 只绑定一次
        if (doc.body._slotClickEventsBound) return;
        doc.body._slotClickEventsBound = true;
        
        // 使用事件委托，监听所有 slot 的点击
        doc.body.addEventListener('click', function(e) {
            // 操作按钮点击始终跳过
            if (e.target.closest('.widget-hover-actions')) {
                return;
            }
            
            // 查找点击的 slot 元素（支持多种标记方式）
            const slotEl = e.target.closest('[data-slot]') || 
                          e.target.closest('[data-wslot]') ||
                          e.target.closest('.content-slot');
            
            if (!slotEl) return;
            
            // 如果点击的是 widget-wrapper 内的子插槽，优先处理子插槽选择
            // 只有点击 widget-wrapper 但没有命中子插槽时才跳过
            const inWidgetWrapper = e.target.closest('.widget-wrapper') || e.target.closest('[data-layout-id]');
            if (inWidgetWrapper && !e.target.closest('[data-wslot]') && !e.target.closest('[data-slot]')) {
                return;
            }
            
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
            
            // 读取 slot 的 position 属性（区域定位）
            const positionAttr = slotEl.getAttribute('data-wslot-position') || 
                                slotEl.getAttribute('data-position') || '';
            
            console.log('[ThemeEditor] Slot clicked in iframe:', slotId, 'accept:', acceptAttr, 'position:', positionAttr);
            
            // 构造 slot 信息，area 优先使用 position 属性
            const slotInfo = {
                id: slotId,
                name: slotName,
                accept: acceptAttr,
                area: positionAttr || ''  // 使用 position 作为区域
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
        
        // 从 iframe DOM 推断 area（header/footer/content）
        let area = 'content';
        try {
            const iframe = elements.previewFrame;
            if (iframe && iframe.contentDocument) {
                const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                if (widgetEl) {
                    // 检查是否在 header/footer 区域
                    if (widgetEl.closest('header, [data-wslot-position="header"], .site-header')) {
                        area = 'header';
                    } else if (widgetEl.closest('footer, [data-wslot-position="footer"], .site-footer')) {
                        area = 'footer';
                    }
                }
            }
        } catch (e) { /* iframe access error */ }
        
        try {
            // 调用删除 API - 传递 slot_id 和 area 作为后端 fallback
            const response = await fetch(config.apiDeleteWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    theme_id: state.themeId,
                    slot_id: slotId,
                    area: area,
                    layout_type: state.layoutType || 'homepage',
                    layout_option: state.layoutOption || 'default'
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
                        
                        const remainingWidgets = slot ? slot.querySelector('[data-layout-id]') : null;
                        
                        // 恢复原始内容（不调用 initWidgetHoverActions 避免重复渲染操作按钮）
                        if (slot && !remainingWidgets) {
                            if (result.has_original && result.original_html) {
                                // 剥离 original_html 中可能包含的 widget-wrapper（后端渲染可能带入其他 widget）
                                slot.innerHTML = stripWidgetWrappersFromHtml(result.original_html);
                            } else {
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
                
                showToast('部件已删除', 'success');
                
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
     * 处理部件上移 — 先交换 DOM 再走 persistSlotSortOrder 统一持久化
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

        // DOM 交换：把当前部件插到前一个部件之前
        prevWidget.parentNode.insertBefore(widgetEl, prevWidget);

        // 解析 slotId 并统一持久化
        const slotId = widgetEl.getAttribute('data-slot-id') ||
                       widgetEl.closest('[data-wslot]')?.getAttribute('data-wslot') || '';
        await persistSlotSortOrder(slotId);
    }

    /**
     * 处理部件下移 — 先交换 DOM 再走 persistSlotSortOrder 统一持久化
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

        // DOM 交换：把当前部件插到下一个部件之后
        nextWidget.parentNode.insertBefore(widgetEl, nextWidget.nextSibling);

        // 解析 slotId 并统一持久化
        const slotId = widgetEl.getAttribute('data-slot-id') ||
                       widgetEl.closest('[data-wslot]')?.getAttribute('data-wslot') || '';
        await persistSlotSortOrder(slotId);
    }

    /**
     * 交换两个部件的排序（保留用于非插槽内场景，插槽内排序请用 persistSlotSortOrder）
     * @deprecated 插槽内排序已改用 persistSlotSortOrder，此函数仅作备用
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
                const iframe = elements.previewFrame;
                if (iframe && iframe.contentDocument) {
                    const el1 = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId1}"]`);
                    const el2 = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId2}"]`);

                    if (el1 && el2) {
                        if (el1.compareDocumentPosition(el2) & Node.DOCUMENT_POSITION_FOLLOWING) {
                            el2.parentNode.insertBefore(el1, el2.nextSibling);
                        } else {
                            el2.parentNode.insertBefore(el1, el2);
                        }

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
     * 为 iframe 中所有符合条件的部件设置 draggable 属性（幂等）
     * 独占插槽内的部件不设置 draggable。
     *
     * @param {Document} iframeDoc iframe 的 contentDocument
     */
    function setDraggableOnSlotWidgets(iframeDoc) {
        if (!iframeDoc) return;

        const widgets = iframeDoc.querySelectorAll('.widget-wrapper[data-layout-id], [data-layout-id]');
        widgets.forEach(widget => {
            const slotId = widget.getAttribute('data-slot-id') ||
                           widget.closest('[data-wslot]')?.getAttribute('data-wslot') || '';

            if (isExclusiveSlot(slotId, '')) {
                widget.removeAttribute('draggable');
            } else {
                widget.setAttribute('draggable', 'true');
            }
        });
    }

    /**
     * 初始化部件拖拽排序功能
     *
     * 采用事件委托：在 iframe body 上只绑定一次 drag 事件，
     * 新插入的部件无需再次绑定即可自动参与排序。
     */
    function initWidgetSortable() {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;

        const iframeDoc = iframe.contentDocument;

        // 1) 为当前所有部件设置 draggable
        setDraggableOnSlotWidgets(iframeDoc);

        // 2) 事件委托：只绑定一次（通过标记防重复）
        if (iframeDoc.body._sortableDelegationBound) {
            console.log('[ThemeEditor] Widget sortable delegation already bound, skipped');
            return;
        }
        iframeDoc.body._sortableDelegationBound = true;

        // —— 辅助函数：从事件目标找到最近的带 data-layout-id 的 widget-wrapper ——
        function resolveWidget(target) {
            return target.closest('.widget-wrapper[data-layout-id]') ||
                   target.closest('[data-layout-id]');
        }

        // —— 辅助函数：取 widget 所属 slotId ——
        function getWidgetSlotId(widget) {
            return widget.getAttribute('data-slot-id') ||
                   widget.closest('[data-wslot]')?.getAttribute('data-wslot') || '';
        }

        // —— dragstart ——
        iframeDoc.body.addEventListener('dragstart', function(e) {
            const widget = resolveWidget(e.target);
            if (!widget) return;

            const slotId = getWidgetSlotId(widget);
            if (isExclusiveSlot(slotId, '')) return;

            e.stopPropagation();

            const layoutId = widget.getAttribute('data-layout-id');
            e.dataTransfer.setData('text/plain', layoutId);
            e.dataTransfer.effectAllowed = 'move';

            widget.classList.add('dragging');

            state.sortDragging = {
                layoutId: layoutId,
                slotId: slotId,
                element: widget
            };
        });

        // —— dragend ——
        iframeDoc.body.addEventListener('dragend', function(e) {
            if (state.sortDragging && state.sortDragging.element) {
                state.sortDragging.element.classList.remove('dragging');
            }
            state.sortDragging = null;

            // 移除所有拖拽指示器
            iframeDoc.querySelectorAll('.drag-over-top, .drag-over-bottom').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
        });

        // —— dragover ——
        iframeDoc.body.addEventListener('dragover', function(e) {
            if (!state.sortDragging) return;

            const targetWidget = resolveWidget(e.target);
            if (!targetWidget) return;

            // 同一 slot 内才允许排序
            const targetSlotId = getWidgetSlotId(targetWidget);
            if (targetSlotId !== state.sortDragging.slotId) {
                e.dataTransfer.dropEffect = 'none';
                return;
            }

            // 不能放到自己身上
            if (targetWidget === state.sortDragging.element) return;

            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';

            // 计算鼠标位置，决定插入到上方还是下方
            const rect = targetWidget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;

            targetWidget.classList.remove('drag-over-top', 'drag-over-bottom');
            if (e.clientY < midY) {
                targetWidget.classList.add('drag-over-top');
            } else {
                targetWidget.classList.add('drag-over-bottom');
            }
        });

        // —— dragleave ——
        iframeDoc.body.addEventListener('dragleave', function(e) {
            const targetWidget = resolveWidget(e.target);
            if (targetWidget) {
                targetWidget.classList.remove('drag-over-top', 'drag-over-bottom');
            }
        });

        // —— drop ——
        iframeDoc.body.addEventListener('drop', async function(e) {
            if (!state.sortDragging) return;

            const targetWidget = resolveWidget(e.target);
            if (!targetWidget) return;

            const targetSlotId = getWidgetSlotId(targetWidget);
            if (targetSlotId !== state.sortDragging.slotId) return;

            e.preventDefault();
            e.stopPropagation();

            targetWidget.classList.remove('drag-over-top', 'drag-over-bottom');

            const sourceLayoutId = state.sortDragging.layoutId;
            const targetLayoutId = targetWidget.getAttribute('data-layout-id');
            if (sourceLayoutId === targetLayoutId) return;

            // 计算插入位置
            const rect = targetWidget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            const insertBefore = e.clientY < midY;

            // 交给统一入口：DOM 移动 + 持久化
            await saveWidgetSortOrder(sourceLayoutId, targetLayoutId, insertBefore, state.sortDragging.slotId);
        });

        console.log('[ThemeEditor] Widget sortable initialized with delegation');
    }

    /**
     * 统一排序持久化 — 所有"插槽内顺序变更"的唯一出口
     *
     * 读取 iframe 中指定 slotId 对应 DOM 的当前顺序，
     * 收集 { layoutId: index } 并 POST /update-sort 保存。
     *
     * @param {string} slotId 插槽 ID（data-wslot 值）
     * @returns {Promise<boolean>} 是否保存成功
     */
    async function persistSlotSortOrder(slotId) {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument || !slotId) return false;

        const iframeDoc = iframe.contentDocument;
        const slotEl = iframeDoc.querySelector(`[data-wslot="${slotId}"]`) ||
                       iframeDoc.querySelector(`[data-slot="${slotId}"]`);
        if (!slotEl) return false;

        // 找到装部件的容器：取第一个带 data-layout-id 的节点的 parentNode
        const firstWidget = slotEl.querySelector('[data-layout-id]');
        const container = firstWidget ? firstWidget.parentNode : slotEl;

        // 只取容器下直接子级中带 data-layout-id 的部件，避免嵌套 slot 内部件混入
        const widgets = Array.from(container.children).filter(el => el.hasAttribute('data-layout-id'));
        if (widgets.length === 0) return true; // 无部件则不需要排序

        const sortData = {};
        widgets.forEach((widget, index) => {
            sortData[widget.getAttribute('data-layout-id')] = index;
        });

        try {
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
                updateSiblingMoveButtons(slotId);
                return true;
            } else {
                loadLayoutPreview();
                showToast(result.message || '排序保存失败', 'error');
                return false;
            }
        } catch (err) {
            console.error('[ThemeEditor] persistSlotSortOrder error:', err);
            loadLayoutPreview();
            showToast('保存排序时发生错误', 'error');
            return false;
        }
    }

    /**
     * 在 iframe 中移动部件 DOM 位置，然后持久化该 slot 的排序
     *
     * @param {string} sourceLayoutId 被移动的部件
     * @param {string} targetLayoutId 目标部件（拖放到它上面/下面）
     * @param {boolean} insertBefore true=插到 target 前面，false=插到 target 后面
     * @param {string} slotId 所在插槽 ID
     */
    async function saveWidgetSortOrder(sourceLayoutId, targetLayoutId, insertBefore, slotId) {
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;

        const iframeDoc = iframe.contentDocument;
        const sourceEl = iframeDoc.querySelector(`[data-layout-id="${sourceLayoutId}"]`);
        const targetEl = iframeDoc.querySelector(`[data-layout-id="${targetLayoutId}"]`);

        if (!sourceEl || !targetEl) return;

        // 在 DOM 中移动
        if (insertBefore) {
            targetEl.parentNode.insertBefore(sourceEl, targetEl);
        } else {
            targetEl.parentNode.insertBefore(sourceEl, targetEl.nextSibling);
        }

        // 委托给统一排序持久化
        await persistSlotSortOrder(slotId);
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
     * 取消选中并关闭配置面板
     */
    function deselectWidget() {
        document.querySelectorAll('.preview-widget-item.selected').forEach(el => {
            el.classList.remove('selected');
        });
        state.selectedWidget = null;

        // 关闭配置面板
        if (elements.configPanel) {
            elements.configPanel.classList.remove('show');
        }

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
     * 打开组件预览弹窗（PC / iPad / Mobile / 响应式）
     */
    async function openComponentPreviewModal(widgetModule, widgetCode, widgetName) {
        const modal = document.getElementById('componentPreviewModal');
        const titleEl = document.getElementById('componentPreviewModalTitle');
        const loadingEl = document.getElementById('componentPreviewLoading');
        const inners = modal ? modal.querySelectorAll('.component-preview-inner') : [];
        const responsiveViewport = document.getElementById('componentPreviewViewportResponsive');
        const responsiveInput = document.getElementById('componentPreviewResponsiveWidth');
        const responsiveLabel = document.getElementById('componentPreviewResponsiveWidthLabel');

        if (!modal || !inners.length) return;

        if (titleEl) titleEl.textContent = (widgetName || widgetCode || '') + ' - ' + (typeof __ !== 'undefined' ? __('组件预览') : '组件预览');
        loadingEl?.classList.add('visible');
        inners.forEach(el => { el.innerHTML = ''; });

        const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
        modalInstance.show();

        try {
            const url = new URL(config.apiWidgetPreview, window.location.origin);
            url.searchParams.set('widget_module', widgetModule);
            url.searchParams.set('widget_code', widgetCode);
            const res = await fetch(url.toString());
            const data = await res.json();
            const html = (data && data.html) ? data.html : (data.success === false ? '<div class="widget-preview-error">' + (data.message || '') + '</div>' : '');
            inners.forEach(el => { el.innerHTML = html; });
        } catch (err) {
            const errMsg = err && err.message ? err.message : String(err);
            inners.forEach(el => { el.innerHTML = '<div class="widget-preview-error">' + (errMsg || '加载失败') + '</div>'; });
        }
        loadingEl?.classList.remove('visible');

        if (responsiveInput && responsiveViewport && responsiveLabel) {
            const updateResponsiveWidth = function() {
                const w = parseInt(responsiveInput.value, 10) || 768;
                responsiveViewport.style.width = w + 'px';
                responsiveLabel.textContent = w + 'px';
            };
            responsiveInput.oninput = updateResponsiveWidth;
            updateResponsiveWidth();
        }
    }

    /**
     * 加载部件配置（用于模态框）
     */
    async function loadWidgetConfigForModal(widgetElement, modalBody) {
        modalBody.setAttribute('data-theme-editor-config-modal', '1');
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

        // 获取部件参数定义（优先 w_query）
        try {
            const result = await fetchWidgetsData();

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
                    // 优先使用后端渲染的完整表单（含 array 的 item_schema：图片、标题、副标题、链接等），便于轮播每项完整编辑
                    const formHtml = await generateWidgetConfigForm(layoutId, widgetMeta.params, widgetConfig);
                    if (formHtml && formHtml.trim() && !formHtml.includes('alert-danger') && (formHtml.includes('w-param-form') || formHtml.includes('<form'))) {
                        const typeIcons = { 'header': 'ri-layout-top-line', 'footer': 'ri-layout-bottom-line', 'sidebar': 'ri-layout-left-line', 'banner': 'ri-image-line', 'carousel': 'ri-slideshow-line', 'product': 'ri-shopping-bag-line', 'category': 'ri-folder-line', 'navigation': 'ri-menu-line', 'search': 'ri-search-line', 'social': 'ri-share-line', 'newsletter': 'ri-mail-line', 'content': 'ri-file-text-line' };
                        const icon = typeIcons[widgetType] || 'ri-widgets-line';
                        const widgetName = escapeHtml(widgetMeta.name || widgetCode || '');
                        const widgetDesc = escapeHtml((widgetMeta.description || '') + '');
                        const headerHtml = `<div class="widget-config-panel"><div class="config-header"><div class="config-widget-info"><div class="widget-icon"><i class="${icon}"></i></div><div class="widget-meta"><h4 class="widget-name">${widgetName}</h4><p class="widget-desc">${widgetDesc}</p></div></div></div>`;
                        const searchPlaceholder = (typeof __ !== 'undefined' ? __('搜索配置项') : '搜索配置项');
                        const searchWrap = '<div class="w-param-search-wrap mb-2"><input type="text" class="w-param-search form-control form-control-sm" placeholder="' + searchPlaceholder + '" autocomplete="off"></div>';
                        modalBody.innerHTML = headerHtml + searchWrap + formHtml + '<div class="config-actions"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' + (typeof __ !== 'undefined' ? __('关闭') : '关闭') + '</button></div></div>';
                        const form = modalBody.querySelector('.w-param-form');
                        if (form) {
                            form.id = 'widgetConfigFormModal';
                            form.setAttribute('data-layout-id', layoutId);
                            if (widgetElement) form.setAttribute('data-widget-element-id', 'widget_' + layoutId);
                            const debounceMs = 400;
                            let autoSaveTimer = null;
                            function scheduleAutoSave() {
                                if (autoSaveTimer) clearTimeout(autoSaveTimer);
                                autoSaveTimer = setTimeout(function() { autoSaveTimer = null; saveWidgetConfigFromModal(form, widgetElement, { autoSave: true }); }, debounceMs);
                            }
                            form.addEventListener('input', scheduleAutoSave);
                            form.addEventListener('change', scheduleAutoSave);
                        }
                        if (typeof window.WidgetParamTypesInit === 'function') window.WidgetParamTypesInit(modalBody);
                        bindAccordionFormEvents(modalBody);
                        bindParamSearch(modalBody);
                    } else {
                        renderConfigFormToModal({
                            layout_id: layoutId,
                            widget_code: widgetCode,
                            widget_module: widgetModule,
                            widget_type: widgetType,
                            config: widgetConfig,
                            meta: widgetMeta,
                        }, widgetMeta.params || {}, modalBody, widgetElement);
                    }
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

        // 获取部件参数定义（优先 w_query）
        try {
            const result = await fetchWidgetsData();

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
        const savedConfig = widget.config || {};

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
                </div>
                <form class="config-form" id="widgetConfigFormModal" data-layout-id="${widget.layout_id}" data-widget-element-id="${widgetElement ? 'widget_' + widget.layout_id : ''}">
        `;

        if (Object.keys(params).length === 0) {
            html += `<div class="config-empty-state">
                ${iconSvg('settings')}
                <p>该部件暂无可配置参数</p>
            </div>`;
        } else {
            for (const key in params) {
                const param = params[key];
                const type = param.type || 'string';
                const label = param.label || key;
                const defaultVal = param.default || '';
                const value = savedConfig[key] !== undefined ? savedConfig[key] : defaultVal;
                const required = param.required || false;
                const description = param.description || '';
                const placeholder = param.placeholder || '';
                const options = param.options || {};
                const translatable = isFieldTranslatable(param);

                html += `<div class="config-field${translatable ? ' translatable-field' : ''}">`;
                html += `<label class="config-label" for="config_${key}">`;
                html += escapeHtml(label);
                if (required) html += ' <span class="required-mark">*</span>';
                if (translatable) html += ' <i class="ri-translate-2 translatable-icon" title="支持多语言"></i>';
                html += `</label>`;
                html += `<div class="config-field-input">`;

                if (type === 'string') {
                    html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'number') {
                    html += `<input type="number" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'boolean') {
                    html += `<div class="form-check form-switch">
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
                    html += `<div class="input-with-icon">
                        <i class="ri-link"></i>
                        <input type="url" class="form-control" id="config_${key}" name="${key}" 
                               value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>
                    </div>`;
                } else if (type === 'image') {
                    html += `<div class="input-group">
                        <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="图片 URL 或上传">
                        <button type="button" class="btn btn-outline-secondary btn-select-image" data-target="config_${key}" title="选择图片">
                            ${iconSvg('image')}
                        </button>
                    </div>`;
                    if (value) {
                        html += `<div class="mt-2"><img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 80px; border-radius: 8px;"></div>`;
                    }
                } else if (type === 'color') {
                    html += `<div class="color-picker-wrapper">
                        <input type="color" class="form-control-color" id="config_${key}_picker" value="${escapeHtml(value || '#000000')}">
                        <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="#000000" style="font-family: monospace;">
                    </div>`;
                } else if (type === 'textarea') {
                    html += `<textarea class="form-control" id="config_${key}" name="${key}" rows="4" 
                             placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
                } else {
                    html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                             value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
                }

                html += `</div>`; // .config-field-input

                if (description) {
                    html += `<div class="config-field-description">${escapeHtml(description)}</div>`;
                }

                html += `</div>`; // .config-field
            }
        }

        html += `
                    <div class="config-actions">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            ${iconSvg('close')} 关闭
                        </button>
                    </div>
                </form>
            </div>
        `;

        modalBody.innerHTML = html;
        
        // 实时保存：任意修改后防抖保存，不关闭模态框
        const form = document.getElementById('widgetConfigFormModal');
        if (form) {
            const layoutId = form.dataset.layoutId;
            const debounceMs = 400;
            let autoSaveTimer = null;
            function scheduleAutoSave() {
                if (autoSaveTimer) clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(function() {
                    autoSaveTimer = null;
                    saveWidgetConfigFromModal(form, widgetElement, { autoSave: true });
                }, debounceMs);
            }
            form.addEventListener('input', scheduleAutoSave);
            form.addEventListener('change', scheduleAutoSave);
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
        const savedConfig = widget.config || {};

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
                        </select>
                    </div>
                </div>
                <form class="config-form" id="widgetConfigForm" data-layout-id="${widget.layout_id}">
        `;

        if (Object.keys(params).length === 0) {
            html += `<div class="config-empty-state">
                ${iconSvg('settings')}
                <p>该部件暂无可配置参数</p>
            </div>`;
        } else {
            // 按 group 分组（默认分组为 "基础配置"）
            const groups = {};
            for (const key in params) {
                const param = params[key];
                const groupName = param.group || '基础配置';
                if (!groups[groupName]) groups[groupName] = [];
                groups[groupName].push({ key, ...param });
            }
            
            const groupIcons = {
                '基础配置': 'ri-settings-3-line',
                '样式': 'ri-palette-line',
                '布局': 'ri-layout-line',
                '数据': 'ri-database-2-line',
                '高级': 'ri-code-s-slash-line',
            };
            
            for (const groupName in groups) {
                const groupFields = groups[groupName];
                const groupIcon = groupIcons[groupName] || 'ri-folder-settings-line';
                const isSingleGroup = Object.keys(groups).length === 1;
                
                // 单分组不显示分组标题，直接展示字段
                if (!isSingleGroup) {
                    html += `<div class="config-group">
                        <h5 class="config-group-title" onclick="this.parentElement.classList.toggle('collapsed')">
                            <i class="${groupIcon}"></i> ${escapeHtml(groupName)}
                            ${iconSvg('arrowDown')}
                        </h5>
                        <div class="config-fields">`;
                }
                
                for (const field of groupFields) {
                    const key = field.key;
                    const type = field.type || 'string';
                    const label = field.label || key;
                    const defaultVal = field.default || '';
                    const value = savedConfig[key] !== undefined ? savedConfig[key] : defaultVal;
                    const required = field.required || false;
                    const description = field.description || '';
                    const placeholder = field.placeholder || '';
                    const options = field.options || {};
                    const translatable = isFieldTranslatable(field);
                    
                    html += `<div class="config-field${translatable ? ' translatable-field' : ''}">`;
                    html += `<label class="config-label" for="config_${key}">`;
                    html += escapeHtml(label);
                    if (required) html += ' <span class="required-mark">*</span>';
                    if (translatable) html += ' <i class="ri-translate-2 translatable-icon" title="支持多语言"></i>';
                    html += `</label>`;
                    html += `<div class="config-field-input">`;

                    if (type === 'string') {
                        html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                                 value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                    } else if (type === 'number') {
                        html += `<input type="number" class="form-control" id="config_${key}" name="${key}" 
                                 value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                    } else if (type === 'boolean') {
                        html += `<div class="form-check form-switch">
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
                        html += `<div class="input-with-icon">
                            <i class="ri-link"></i>
                            <input type="url" class="form-control" id="config_${key}" name="${key}" 
                                   value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>
                        </div>`;
                    } else if (type === 'image') {
                        html += `<div class="input-group">
                            <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="图片 URL 或上传">
                            <button type="button" class="btn btn-outline-secondary btn-select-image" data-target="config_${key}" title="选择图片">
                                ${iconSvg('image')}
                            </button>
                        </div>`;
                        if (value) {
                            html += `<div class="mt-2"><img src="${escapeHtml(value)}" class="img-thumbnail" style="max-height: 80px; border-radius: 8px;"></div>`;
                        }
                    } else if (type === 'color') {
                        html += `<div class="color-picker-wrapper">
                            <input type="color" class="form-control-color" id="config_${key}_picker" value="${escapeHtml(value || '#000000')}">
                            <input type="text" class="form-control" id="config_${key}" name="${key}" value="${escapeHtml(value)}" placeholder="#000000" style="font-family: monospace;">
                        </div>`;
                    } else if (type === 'textarea') {
                        html += `<textarea class="form-control" id="config_${key}" name="${key}" rows="4" 
                                 placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
                    } else {
                        html += `<input type="text" class="form-control" id="config_${key}" name="${key}" 
                                 value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
                    }

                    html += `</div>`; // .config-field-input

                    if (description) {
                        html += `<div class="config-field-description">${escapeHtml(description)}</div>`;
                    }

                    html += `</div>`; // .config-field
                }
                
                if (!isSingleGroup) {
                    html += `</div></div>`; // .config-fields, .config-group
                }
            }
        }

        html += `
                    <div class="config-actions">
                        <button type="button" class="btn btn-outline-danger btn-delete-config" data-layout-id="${widget.layout_id}" title="删除此部件">
                            ${iconSvg('delete')} 删除
                        </button>
                    </div>
                </form>
            </div>
        `;

        elements.configContent.innerHTML = html;
        
        // 动态填充语言切换器
        const langSwitcher = document.getElementById('configLangSwitcher');
        if (langSwitcher) {
            fetchInstalledLocales().then(locales => {
                for (const loc of locales) {
                    const opt = document.createElement('option');
                    opt.value = loc.code;
                    opt.textContent = loc.name;
                    langSwitcher.appendChild(opt);
                }
            });
            langSwitcher.addEventListener('change', async function() {
                const locale = this.value || null;
                const layoutId = this.dataset.widgetLayoutId;
                await reloadWidgetConfigWithLocale(layoutId, locale);
            });
        }
    }

    // ─── 统一多语言 i18n 逻辑 ───────────────────────────────────

    let _installedLocalesCache = null;

    /**
     * 获取已安装语言列表（缓存结果）
     * @returns {Promise<Array<{code:string,name:string,flag:string}>>}
     */
    async function fetchInstalledLocales() {
        if (_installedLocalesCache) return _installedLocalesCache;
        try {
            const resp = await fetch(`${config.apiBase}/installed-locales`);
            const result = await resp.json();
            if (result.success && result.locales) {
                _installedLocalesCache = result.locales;
                return _installedLocalesCache;
            }
        } catch (err) {
            console.error('fetchInstalledLocales error:', err);
        }
        return [{ code: 'zh_Hans_CN', name: '简体中文', flag: '' }, { code: 'en_US', name: 'English', flag: '' }];
    }

    /**
     * 确保面板内已渲染语言行（动态填充空容器）
     */
    async function ensurePanelRendered(panel) {
        const body = panel.querySelector('.w-param-i18n-body, .i18n-panel-body');
        if (!body || body.children.length > 0) return;

        const locales = await fetchInstalledLocales();
        const fieldKey = panel.dataset.field;
        const p = 'w-param-';
        let html = '';
        for (const loc of locales) {
            html += `<div class="${p}i18n-row">`;
            html += `<label class="${p}i18n-label">${loc.flag ? '<span class="lang-flag-svg">' + loc.flag + '</span> ' : ''}${escapeHtml(loc.code)}</label>`;
            html += `<input type="text" class="${p}input i18n-input" data-locale="${escapeHtml(loc.code)}" data-field="${escapeHtml(fieldKey)}" placeholder="${escapeHtml(loc.name)}">`;
            html += `</div>`;
        }
        body.innerHTML = html;
    }

    /**
     * 从面板的 data 属性推导出 config 路径
     *   顶级字段: fieldKey
     *   数组子字段: arrayKey.arrayIndex.leafFieldKey
     */
    function resolvePanelFieldPath(panel) {
        const fieldKey = panel.dataset.field;
        return fieldKey;
    }

    /**
     * 从 config 对象按路径取值（支持 slides.0.title）
     */
    function getConfigValueByPath(configObj, path) {
        const parts = path.split('.');
        let cur = configObj;
        for (const p of parts) {
            if (cur == null || typeof cur !== 'object') return '';
            cur = cur[p];
        }
        return cur ?? '';
    }

    /**
     * 加载面板内所有语言的值
     */
    async function loadI18nValues(layoutId, fieldKey, panel) {
        await ensurePanelRendered(panel);
        const inputs = panel.querySelectorAll('.i18n-input');
        const locales = [...new Set([...inputs].map(inp => inp.dataset.locale))];

        for (const locale of locales) {
            try {
                const apiUrl = `${config.apiBase}/widget-config?layout_id=${layoutId}&locale=${locale}`;
                const resp = await fetch(apiUrl);
                const result = await resp.json();
                if (result.success && result.data && result.data.config) {
                    const value = getConfigValueByPath(result.data.config, fieldKey);
                    const input = panel.querySelector(`.i18n-input[data-locale="${locale}"]`);
                    if (input) input.value = value;
                }
            } catch (err) {
                console.error(`Load i18n ${locale} error:`, err);
            }
        }
    }

    /**
     * 保存面板内所有语言的值
     */
    async function saveI18nValues(layoutId, fieldKey, panel) {
        const inputs = panel.querySelectorAll('.i18n-input');
        let successCount = 0;

        for (const input of inputs) {
            const locale = input.dataset.locale;
            const value = input.value;
            try {
                const resp = await fetch(`${config.apiBase}/save-widget-config`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        layout_id: layoutId,
                        config: { [fieldKey]: value },
                        locale: locale
                    })
                });
                const result = await resp.json();
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
     * @param {HTMLFormElement} form
     * @param {HTMLElement|null} widgetElement
     * @param {{ autoSave?: boolean }} options - autoSave: true 表示实时保存，不关闭模态框、不弹“配置已保存”
     */
    async function saveWidgetConfigFromModal(form, widgetElement, options) {
        const autoSave = options && options.autoSave === true;
        const layoutId = form.dataset.layoutId;
        if (!layoutId) return;

        const formData = new FormData(form);
        const configData = {};

        formData.forEach((value, key) => {
            configData[key] = value;
        });

        // 处理复选框（未选中时不会在 FormData 中）
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!formData.has(checkbox.name)) {
                configData[checkbox.name] = false;
            } else {
                configData[checkbox.name] = true;
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
                    config: configData,
                }),
            });

            const result = await response.json();

            if (result.success) {
                if (!autoSave) showToast('配置已保存', 'success');
                // 更新部件的 data-config
                if (widgetElement) {
                    widgetElement.dataset.config = JSON.stringify(configData);
                }
                if (!autoSave) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('widgetConfigModal'));
                    if (modal) modal.hide();
                }
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                }
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
     * @param {HTMLFormElement} form
     * @param {boolean} silent - 为 true 时不显示“配置已保存”提示（用于实时保存）
     */
    async function saveWidgetConfig(form, silent) {
        const layoutId = form.dataset.layoutId;
        if (!layoutId) return;

        const formData = new FormData(form);
        const configData = {};

        formData.forEach((value, key) => {
            configData[key] = value;
        });

        // 处理复选框（未选中时不会在 FormData 中）
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!formData.has(checkbox.name)) {
                configData[checkbox.name] = false;
            } else {
                configData[checkbox.name] = true;
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
                    config: configData,
                }),
            });

            const result = await response.json();

            if (result.success) {
                if (!silent) showToast('配置已保存', 'success');
                if (state.selectedWidget) {
                    state.selectedWidget.dataset.config = JSON.stringify(configData);
                }
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                }
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
            '删除后插槽将恢复为原始内容。',
            '确认删除',
            '取消'
        );
        if (!confirmed) {
            return;
        }

        const layoutId = widgetElement.dataset.layoutId;
        if (!layoutId) return;

        // 从 widgetElement 或 iframe 获取 slot_id 和 area
        const slotIdFromEl = widgetElement.dataset.slotId || '';
        let areaFromEl = 'content';
        try {
            const iframe = elements.previewFrame;
            if (iframe && iframe.contentDocument) {
                const iframeWidget = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                if (iframeWidget) {
                    if (iframeWidget.closest('header, [data-wslot-position="header"], .site-header')) {
                        areaFromEl = 'header';
                    } else if (iframeWidget.closest('footer, [data-wslot-position="footer"], .site-footer')) {
                        areaFromEl = 'footer';
                    }
                }
            }
        } catch (e) { /* iframe access error */ }

        try {
            const response = await fetch(config.apiDeleteWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    theme_id: state.themeId,
                    slot_id: slotIdFromEl,
                    area: areaFromEl,
                    layout_type: state.layoutType || 'homepage',
                    layout_option: state.layoutOption || 'default'
                }),
            });

            const result = await response.json();

            if (result.success) {
                // 从 iframe 中移除部件并恢复原始内容
                const iframe = elements.previewFrame;
                if (iframe && iframe.contentDocument) {
                    const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
                    if (widgetEl) {
                        const slot = widgetEl.closest('[data-wslot], [data-slot]');
                        
                        // 移除部件元素
                        widgetEl.remove();
                        
                        // 恢复原始内容（不调用 initWidgetHoverActions 避免重复渲染操作按钮）
                        if (slot && !slot.querySelector('[data-layout-id]')) {
                            if (result.has_original && result.original_html) {
                                // 剥离可能混入的 widget-wrapper
                                slot.innerHTML = stripWidgetWrappersFromHtml(result.original_html);
                            } else {
                                const slotName = slot.getAttribute('data-wslot-name') || slot.getAttribute('data-name') || result.slot_id || '';
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
                                        <p style="margin: 0; font-size: 14px;">拖入部件到此插槽</p>
                                    </div>
                                `;
                            }
                        }
                    }
                }
                
                // 从结构视图移除
                widgetElement.remove();
                showToast('部件已删除', 'success');
                deselectWidget();
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
     * 保存布局为新版本
     */
    async function saveLayout() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        try {
            // 弹出输入版本名称的对话框
            const versionName = await showPromptDialog(
                '保存新版本',
                '请输入版本名称（可选）：',
                '',
                '保存',
                '取消'
            );

            // 如果用户取消了对话框
            if (versionName === null) {
                return;
            }

            showToast('正在保存版本...', 'info');

            const response = await fetch(config.apiSaveVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    page_type: state.pageType,
                    version_name: versionName || undefined,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '版本已保存', 'success');
                // 刷新版本列表
                await loadVersions();
            } else {
                showToast(result.message || '保存失败', 'error');
            }
        } catch (error) {
            console.error('[ThemeEditor] Save version error:', error);
            showToast('保存版本失败：' + error.message, 'error');
        }
    }

    /**
     * 发布主题（发布当前版本）
     */
    async function publishTheme() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        const confirmed = await showCustomConfirm(
            '确认发布主题？',
            '确定要发布当前版本吗？发布后将生成缓存文件，前台将显示此版本的布局。',
            '确认发布',
            '取消'
        );
        if (!confirmed) {
            return;
        }

        try {
            showToast('正在发布...', 'info');

            const response = await fetch(config.apiPublishVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    frontend_theme_id: getCurrentWindowParam('frontend_theme_id') || state.themeId,
                    backend_theme_id: getCurrentWindowParam('backend_theme_id') || '',
                    editor_area: state.editorArea || 'frontend',
                    page_type: state.pageType,
                    status: state.previewStatus || 'draft',
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '发布成功', 'success');
                // 刷新版本列表以更新发布状态
                await loadVersions();
            } else {
                showToast(result.message || '发布失败', 'error');
            }
        } catch (err) {
            console.error('[ThemeEditor] Publish error:', err);
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
        window.open(buildLayoutPreviewUrl(), '_blank');
    }
    
    /**
     * 打开前端真实 URL 预览
     * 
     * 生成预览 Token 并跳转到真实的前端页面
     * 预览模式下会显示可拖动的退出预览浮窗
     */
    async function openFrontendPreview() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        try {
            showToast('正在启动预览...', 'info');

            const response = await fetch(config.apiStartPreview, {
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

            if (result.success && result.data && result.data.preview_url) {
                // 打开新窗口预览
                window.open(result.data.preview_url, '_blank');
                showToast('预览已在新窗口打开', 'success');
            } else {
                showToast(result.message || '启动预览失败', 'error');
            }
        } catch (err) {
            console.error('[ThemeEditor] Start preview error:', err);
            showToast('启动预览失败', 'error');
        }
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
        window.open(buildLayoutPreviewUrl({status: 'published'}), '_blank');
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
        clearSlotSelection();
        deselectArea();
        deselectWidget();
        if (status !== 'draft') {
            switchPreviewView('preview');
        }
        
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
        const structureTab = document.querySelector('.preview-tab[data-view="structure"]');
        if (structureTab) {
            const enabled = status === 'draft';
            structureTab.disabled = !enabled;
            structureTab.classList.toggle('disabled', !enabled);
            structureTab.setAttribute('aria-disabled', enabled ? 'false' : 'true');
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
        // 注意：container 类型部件应严格按其 position 属性过滤
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
            'social': ['footer', 'left_sidebar', 'right_sidebar', 'content'],
            'newsletter': ['footer', 'left_sidebar', 'right_sidebar', 'content'],
            'testimonial': ['content'],
            'faq': ['content'],
            'video': ['content', 'banner'],
            'content': ['content', 'left_sidebar', 'right_sidebar'],
            'container': [],  // container 类型必须有明确的 position，不使用 type 推断
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
                    // container 类型没有 position 时不允许放置（必须有明确 position）
                    if (widgetType === 'container' && allowedAreas.length === 0) {
                        allowedAreas = [];
                    }
                } else {
                    // 如果没有类型信息，默认只允许 content 区域
                    allowedAreas = ['content'];
                }
            } else if (widgetPositions.includes('*') || widgetPositions.includes('all')) {
                // 通配符，允许所有区域（但仍受 areaExclusiveTypes 约束）
                isUniversal = true;
                allowedAreas = ['header', 'content', 'footer', 'left_sidebar', 'right_sidebar', 'banner'];
                // 对于通配符部件，如果类型是 header/footer，从允许区域中移除不兼容的区域
                if (widgetType === 'header') {
                    allowedAreas = allowedAreas.filter(a => a !== 'footer');
                } else if (widgetType === 'footer') {
                    allowedAreas = allowedAreas.filter(a => a !== 'header');
                }
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
            widgetPanelTitle.innerHTML = `${iconSvg('apps')} 部件库 <span class="area-filter-badge" onclick="window.ThemeEditor?.deselectArea?.()">${areaName} ${iconSvg('close')}</span>`;
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
            widgetPanelTitle.innerHTML = `${iconSvg('apps')} 部件库`;
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

    const TRANSLATABLE_TYPES = ['string', 'textarea', 'html', 'text'];
    /**
     * 推断字段是否可翻译：显式声明优先，否则文本类默认 true
     */
    function isFieldTranslatable(param) {
        if (param.hasOwnProperty('i18n')) return !!param.i18n;
        const type = param.type || 'string';
        return TRANSLATABLE_TYPES.includes(type);
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

    /**
     * 从 HTML 字符串中剥离 widget-wrapper 和操作按钮
     * 
     * 后端渲染 original_html 时，SlotRendererService 可能将其他 widget 注入到模板中，
     * 导致 "原始内容" 实际包含 widget-wrapper 元素。
     * 此函数将这些 wrapper 的内容提取出来（保留子内容），移除操作按钮。
     * 
     * @param {string} html - 原始 HTML 字符串
     * @returns {string} - 剥离后的 HTML
     */
    function stripWidgetWrappersFromHtml(html) {
        if (!html) return html;
        
        // 使用临时 DOM 解析
        const temp = document.createElement('div');
        temp.innerHTML = html;
        
        // 移除所有 widget-hover-actions 操作按钮
        temp.querySelectorAll('.widget-hover-actions').forEach(el => el.remove());
        
        // 将 widget-wrapper[data-layout-id] 替换为其内部内容（展开子节点）
        temp.querySelectorAll('.widget-wrapper[data-layout-id]').forEach(wrapper => {
            while (wrapper.firstChild) {
                wrapper.parentNode.insertBefore(wrapper.firstChild, wrapper);
            }
            wrapper.remove();
        });
        
        // 移除残留的 data-layout-id 属性（防止 initWidgetHoverActions 误识别）
        temp.querySelectorAll('[data-layout-id]').forEach(el => {
            el.removeAttribute('data-layout-id');
        });
        
        return temp.innerHTML;
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
     * 恢复原始布局（带自动备份）
     * 
     * 新行为：
     * 1. 自动创建当前状态的备份版本
     * 2. 清空工作区恢复到主题模板原始状态
     * 3. 创建新的"原始布局"版本
     */
    async function handleRestoreLayout() {
        // 显示确认对话框
        const confirmed = await showCustomConfirm(
            '确认恢复原始布局？',
            '此操作将清空当前布局，恢复到主题模板的原始状态（不包含任何部件）。\n\n系统会自动创建当前状态的备份，您可以随时切换回来。',
            '确认恢复',
            '取消'
        );

        if (!confirmed) {
            return;
        }

        try {
            showToast('正在恢复原始布局...', 'info');

            const response = await fetch(config.apiRestoreOriginal, {
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
                
                // 刷新版本列表
                await loadVersions();
                
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
        elements.previewFrame.src = buildLayoutPreviewUrl();
        return;

        const currentSrc = elements.previewFrame.src;
        const url = new URL(currentSrc, window.location.origin);
        url.searchParams.set('_t', Date.now());
        // 使用 editor_mode=1 标识后台编辑器 iframe
        url.searchParams.set('editor_mode', '1');
        // 实时编辑预览：live 模式（不固定版本号）
        url.searchParams.set('preview_mode', 'live');
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
            
            // iframe 内区域点击事件处理，用于过滤部件面板
            iframeDoc.addEventListener('click', function(e) {
                // data-editor-interactive 标记的元素保持原生交互，编辑器不拦截
                if (e.target.closest('[data-editor-interactive]')) return;
                
                // 检查是否点击了区域元素
                const themeArea = e.target.closest('.theme-area[data-area]');
                if (themeArea && !e.target.closest('[data-layout-id]') && !e.target.closest('a')) {
                    const areaCode = themeArea.getAttribute('data-area');
                    if (areaCode) {
                        // 调用父窗口的 filterWidgetsByArea 函数
                        filterWidgetsByArea(areaCode);
                        // 更新状态
                        state.selectedArea = areaCode;
                        // 高亮当前区域
                        iframeDoc.querySelectorAll('.theme-area').forEach(el => el.classList.remove('area-selected'));
                        themeArea.classList.add('area-selected');
                        // 显示提示
                        const areaName = areaCode.charAt(0).toUpperCase() + areaCode.slice(1);
                        showToast(`已筛选 "${areaName}" 区域的部件`, 'info');
                        return;
                    }
                }
            });
            
            // 拦截所有链接点击
            iframeDoc.addEventListener('click', function(e) {
                // data-editor-interactive 标记的元素保持原生交互，编辑器不拦截
                if (e.target.closest('[data-editor-interactive]')) return;
                
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
                showToast(`宸插垏鎹㈠埌 ${pageType} 甯冨眬`, 'info');
                console.log('[ThemeEditor] Link intercepted:', href, '-> Editor page type:', pageType);
                navigateEditorShell({
                    page_type: pageType,
                });
                return;

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
        elements.previewFrame.src = buildLayoutPreviewUrl();
        fetchLayoutSlots();
        return;

        const url = new URL(config.apiLayoutPreview, window.location.origin);
        url.searchParams.set('theme_id', state.themeId);
        url.searchParams.set('layout_type', state.layoutType);
        url.searchParams.set('layout_option', state.layoutOption);
        url.searchParams.set('_t', Date.now());
        // 使用 editor_mode=1 标识后台编辑器 iframe
        url.searchParams.set('editor_mode', '1');
        // 支持版本切换：默认 draft，可通过 state.previewStatus 切换
        url.searchParams.set('status', state.previewStatus || 'draft');
        url.searchParams.set('editor_area', state.editorArea || 'frontend');

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
            url.searchParams.set('page_type', getCurrentPageType());
            url.searchParams.set('layout_type', state.layoutType);
            url.searchParams.set('layout_option', state.layoutOption);
            url.searchParams.set('editor_area', state.editorArea || 'frontend');
            url.searchParams.set('status', state.previewStatus || 'draft');

            const response = await fetch(url);
            const contentType = response.headers.get('Content-Type') || '';
            if (!response.ok || contentType.indexOf('application/json') === -1) {
                const text = await response.text();
                if (text && text.trim().startsWith('<')) {
                    console.warn('获取插槽信息: 接口返回非 JSON（可能为错误页或登录页），已跳过');
                }
                return;
            }
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
            const rawAccept = slot.accept;
            const acceptArr = Array.isArray(rawAccept) ? rawAccept : (typeof rawAccept === 'string' ? rawAccept.split(',').map(s => s.trim()).filter(Boolean) : []);
            const acceptTags = acceptArr.map(code =>
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

    // ==================== 版本控制功能 ====================

    /**
     * 加载版本列表
     */
    async function loadVersions() {
        if (!state.themeId) {
            return;
        }

        try {
            const url = new URL(config.apiVersions, window.location.origin);
            url.searchParams.set('theme_id', state.themeId);
            url.searchParams.set('page_type', state.pageType);
            url.searchParams.set('limit', '20');

            const response = await fetch(url.toString());
            const result = await response.json();

            if (result.success && result.data) {
                state.versions = result.data.versions || [];
                state.currentVersionId = result.data.current_version_id;
                state.publishedVersionId = result.data.published_version_id;

                // 更新版本面板 UI
                renderVersionPanel();
            }
        } catch (error) {
            console.error('[ThemeEditor] Load versions error:', error);
        }
    }

    /**
     * 渲染版本面板
     */
    function renderVersionPanel() {
        const versionList = document.getElementById('versionList');
        const currentVersionDisplay = document.getElementById('currentVersionDisplay');

        if (!versionList) {
            return;
        }

        // 更新当前版本显示
        if (currentVersionDisplay) {
            const currentVersion = state.versions.find(v => v.version_id === state.currentVersionId);
            currentVersionDisplay.textContent = currentVersion ? currentVersion.display_name : '无版本';
        }

        // 渲染版本列表
        if (state.versions.length === 0) {
            versionList.innerHTML = '<div class="version-item empty">暂无版本记录</div>';
            return;
        }

        let html = '';
        for (const version of state.versions) {
            const isCurrent = version.version_id === state.currentVersionId;
            const isPublished = version.version_id === state.publishedVersionId;
            const isAutoBackup = version.is_auto_backup;

            html += `
                <div class="version-item ${isCurrent ? 'current' : ''} ${isAutoBackup ? 'backup' : ''}" 
                     data-version-id="${version.version_id}">
                    <div class="version-info">
                        <span class="version-name">
                            ${isAutoBackup ? '<i class="fa fa-history"></i>' : '<i class="fa fa-tag"></i>'}
                            ${escapeHtml(version.display_name)}
                        </span>
                        <span class="version-badges">
                            ${isCurrent ? '<span class="badge badge-primary">当前</span>' : ''}
                            ${isPublished ? '<span class="badge badge-success">已发布</span>' : ''}
                            ${isAutoBackup ? '<span class="badge badge-secondary">备份</span>' : ''}
                        </span>
                    </div>
                    <div class="version-meta">
                        <span class="version-date">${formatDate(version.created_at)}</span>
                        ${version.description ? `<span class="version-desc">${escapeHtml(version.description)}</span>` : ''}
                    </div>
                    <div class="version-actions">
                        ${!isCurrent ? `<button class="btn btn-sm btn-outline-primary" onclick="switchToVersion(${version.version_id})">
                            <i class="fa fa-undo"></i> 切换
                        </button>` : ''}
                        ${!isCurrent && !isPublished ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteVersion(${version.version_id})">
                            <i class="fa fa-trash"></i>
                        </button>` : ''}
                    </div>
                </div>
            `;
        }

        versionList.innerHTML = html;
    }

    /**
     * 切换到指定版本
     */
    async function switchToVersion(versionId) {
        if (!state.themeId || !versionId) {
            return;
        }

        const confirmed = await showCustomConfirm(
            '切换版本',
            '确定要切换到此版本吗？当前工作区的未保存修改将被替换。',
            '确认切换',
            '取消'
        );

        if (!confirmed) {
            return;
        }

        try {
            showToast('正在切换版本...', 'info');

            const response = await fetch(config.apiSwitchVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    page_type: state.pageType,
                    version_id: versionId,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '已切换版本', 'success');

                // 刷新版本列表
                await loadVersions();

                // 刷新预览
                refreshPreview();
            } else {
                showToast(result.message || '切换失败', 'error');
            }
        } catch (error) {
            console.error('[ThemeEditor] Switch version error:', error);
            showToast('切换版本失败：' + error.message, 'error');
        }
    }

    /**
     * 删除版本
     */
    async function deleteVersion(versionId) {
        if (!versionId) {
            return;
        }

        const confirmed = await showCustomConfirm(
            '删除版本',
            '确定要删除此版本吗？此操作不可撤销。',
            '确认删除',
            '取消'
        );

        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(config.apiDeleteVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    version_id: versionId,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '版本已删除', 'success');
                // 刷新版本列表
                await loadVersions();
            } else {
                showToast(result.message || '删除失败', 'error');
            }
        } catch (error) {
            console.error('[ThemeEditor] Delete version error:', error);
            showToast('删除版本失败：' + error.message, 'error');
        }
    }

    /**
     * 切换版本面板显示/隐藏
     */
    function toggleVersionPanel() {
        const panel = document.getElementById('versionPanel');
        if (!panel) return;

        state.versionPanelOpen = !state.versionPanelOpen;

        if (state.versionPanelOpen) {
            panel.classList.add('open');
            // 加载版本列表
            loadVersions();
        } else {
            panel.classList.remove('open');
        }
    }

    /**
     * 显示提示输入对话框
     */
    function showPromptDialog(title, message, defaultValue = '', confirmText = '确定', cancelText = '取消') {
        return new Promise((resolve) => {
            // 创建对话框容器
            const container = document.createElement('div');
            container.className = 'prompt-dialog-container';
            container.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            container.innerHTML = `
                <div class="prompt-dialog-overlay" style="
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                "></div>
                <div class="prompt-dialog-box" style="
                    position: relative;
                    background: #fff;
                    border-radius: 8px;
                    padding: 24px;
                    min-width: 400px;
                    max-width: 500px;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                    animation: slideDown 0.3s ease;
                ">
                    <h4 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 600; color: #333;">${escapeHtml(title)}</h4>
                    <p style="margin: 0 0 16px 0; font-size: 14px; color: #666; line-height: 1.5;">${escapeHtml(message)}</p>
                    <input type="text" class="prompt-input" value="${escapeHtml(defaultValue)}" placeholder="版本名称" style="
                        width: 100%;
                        padding: 10px 12px;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        font-size: 14px;
                        margin-bottom: 20px;
                        box-sizing: border-box;
                    ">
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button class="cancel-btn" style="
                            padding: 8px 20px;
                            border-radius: 6px;
                            border: none;
                            cursor: pointer;
                            font-size: 14px;
                            background: #6c757d;
                            color: #fff;
                        ">${escapeHtml(cancelText)}</button>
                        <button class="confirm-btn" style="
                            padding: 8px 20px;
                            border-radius: 6px;
                            border: none;
                            cursor: pointer;
                            font-size: 14px;
                            background: #007bff;
                            color: #fff;
                        ">${escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(container);

            const input = container.querySelector('.prompt-input');
            const confirmBtn = container.querySelector('.confirm-btn');
            const cancelBtn = container.querySelector('.cancel-btn');
            const overlay = container.querySelector('.prompt-dialog-overlay');

            // 聚焦输入框
            setTimeout(() => input.focus(), 100);

            // 清理函数
            const cleanup = () => {
                container.remove();
            };

            // 确认
            confirmBtn.addEventListener('click', () => {
                const value = input.value.trim();
                cleanup();
                resolve(value);
            });

            // 取消
            cancelBtn.addEventListener('click', () => {
                cleanup();
                resolve(null);
            });
            
            // 点击遮罩关闭
            overlay.addEventListener('click', () => {
                cleanup();
                resolve(null);
            });

            // 回车确认
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    const value = input.value.trim();
                    cleanup();
                    resolve(value);
                }
            });

            // ESC 取消
            container.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    cleanup();
                    resolve(null);
                }
            });
        });
    }

    /**
     * 格式化日期
     */
    function renderEditorLockOverlay(lockInfo) {
        if (!elements.container) {
            return;
        }

        let overlay = document.getElementById('themeEditorLockOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'themeEditorLockOverlay';
            overlay.style.cssText = `
                position: absolute;
                inset: 0;
                z-index: 2000;
                background: rgba(15, 23, 42, 0.72);
                backdrop-filter: blur(2px);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            `;
            elements.container.style.position = 'relative';
            elements.container.appendChild(overlay);
        }

        const userName = lockInfo && lockInfo.user_name ? lockInfo.user_name : '其他用户';
        overlay.innerHTML = `
            <div style="max-width: 460px; width: 100%; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.22);">
                <h3 style="margin: 0 0 12px; font-size: 20px; color: #1f2937;">当前页面正在被编辑</h3>
                <p style="margin: 0 0 10px; color: #4b5563; line-height: 1.6;">
                    ${escapeHtml(userName)} 正在编辑当前主题页面。为了避免互相覆盖，当前会话已被锁定为只读等待状态。
                </p>
                <p style="margin: 0 0 18px; color: #6b7280; line-height: 1.6;">
                    对方释放后刷新页面即可重新进入编辑。
                </p>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="themeEditorLockReload" class="btn btn-primary">刷新重试</button>
                </div>
            </div>
        `;

        overlay.querySelector('#themeEditorLockReload')?.addEventListener('click', () => {
            window.location.reload();
        });
    }

    function clearEditorLockOverlay() {
        document.getElementById('themeEditorLockOverlay')?.remove();
    }

    function stopLockHeartbeat() {
        if (state.lockHeartbeatTimer) {
            clearInterval(state.lockHeartbeatTimer);
            state.lockHeartbeatTimer = null;
        }
    }

    async function refreshEditorLockActivity() {
        if (!state.lockHeld || !config.apiUpdateActivity || !state.themeId) {
            return false;
        }

        try {
            const response = await fetch(config.apiUpdateActivity, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    page_type: getCurrentPageType(),
                }),
            });

            const result = await response.json();
            if (!(result && result.success)) {
                state.lockHeld = false;
                stopLockHeartbeat();
                renderEditorLockOverlay(state.lockConflictInfo);
                return false;
            }

            return true;
        } catch (error) {
            console.warn('[ThemeEditor] Lock heartbeat failed:', error);
            return false;
        }
    }

    function startLockHeartbeat() {
        stopLockHeartbeat();
        state.lockHeartbeatTimer = setInterval(() => {
            refreshEditorLockActivity();
        }, 60000);
    }

    function bindLockLifecycle() {
        if (state.lockLifecycleBound) {
            return;
        }

        window.addEventListener('beforeunload', () => {
            releaseCurrentEditorLock({ keepalive: true });
        });

        state.lockLifecycleBound = true;
    }

    async function releaseCurrentEditorLock(options = {}) {
        if (!state.lockHeld || !config.apiReleaseLock || !state.themeId) {
            return false;
        }

        const keepalive = options.keepalive === true;
        const payload = JSON.stringify({
            theme_id: state.themeId,
            page_type: getCurrentPageType(),
        });

        state.lockHeld = false;
        stopLockHeartbeat();

        try {
            if (keepalive) {
                fetch(config.apiReleaseLock, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: payload,
                    keepalive: true,
                });
                return true;
            }

            const response = await fetch(config.apiReleaseLock, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: payload,
            });

            return response.ok;
        } catch (error) {
            console.warn('[ThemeEditor] Release lock failed:', error);
            return false;
        }
    }

    async function initializeEditorLock() {
        if (!state.themeId || !config.apiCheckLock) {
            return;
        }

        try {
            const url = new URL(config.apiCheckLock, window.location.origin);
            url.searchParams.set('theme_id', String(state.themeId));
            url.searchParams.set('page_type', getCurrentPageType());

            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json();

            if (result && result.success) {
                state.lockHeld = true;
                state.lockConflictInfo = null;
                clearEditorLockOverlay();
                startLockHeartbeat();
                bindLockLifecycle();
                return;
            }

            state.lockHeld = false;
            state.lockConflictInfo = (result && result.data && result.data.lock_info) ? result.data.lock_info : null;
            renderEditorLockOverlay(state.lockConflictInfo);
            showToast(result?.message || '当前页面正被其他用户编辑', 'warning');
        } catch (error) {
            console.warn('[ThemeEditor] Failed to initialize editor lock:', error);
        }
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return '刚刚';
        if (diffMins < 60) return `${diffMins}分钟前`;
        if (diffHours < 24) return `${diffHours}小时前`;
        if (diffDays < 7) return `${diffDays}天前`;

        return date.toLocaleDateString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    // 全局暴露版本控制函数
    window.switchToVersion = switchToVersion;
    window.deleteVersion = deleteVersion;
    window.toggleVersionPanel = toggleVersionPanel;
    window.loadVersions = loadVersions;
    window.ThemeEditor.loadVersions = loadVersions;
    window.ThemeEditor.toggleVersionPanel = toggleVersionPanel;

    // 初始化
    document.addEventListener('DOMContentLoaded', init);
})();
