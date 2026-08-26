/* Weline UI source: js/cms-preview-bridge.js */
(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.Weline = root.Weline || {};
        root.Weline.Theme = root.Weline.Theme || {};
        root.Weline.Theme.CmsPreviewBridge = api;
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    const MESSAGE_TYPE = 'weline:cms-theme-context';
    const PROTOCOL = 'weline.cms-theme-editor.v2';
    const VERSION = 2;
    let requestSequence = 0;

    function normalizeLocale(value) {
        const source = String(value || '').trim().replace(/-/g, '_');
        const parts = source ? source.split('_') : [];
        if (parts.length < 1 || parts.length > 3 || !/^[A-Za-z]{2,3}$/.test(parts[0])) {
            return '';
        }
        const normalized = [parts[0].toLowerCase()];
        if (parts[1]) {
            if (/^[A-Za-z]{4}$/.test(parts[1])) {
                normalized.push(parts[1][0].toUpperCase() + parts[1].slice(1).toLowerCase());
            } else if (/^(?:[A-Za-z]{2}|[0-9]{3})$/.test(parts[1])) {
                normalized.push(parts[1].toUpperCase());
            } else {
                return '';
            }
        }
        if (parts[2]) {
            if (!/^(?:[A-Za-z]{2}|[0-9]{3})$/.test(parts[2]) || parts[1].length !== 4) {
                return '';
            }
            normalized.push(parts[2].toUpperCase());
        }
        return normalized.join('_');
    }

    function normalizeLayoutOption(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^[._/-]+|[._/-]+$/g, '')
            .slice(0, 100);
    }

    function normalizeContext(context) {
        const value = context && typeof context === 'object' ? context : {};
        const claim = function (camelCase, snakeCase, fallback = null) {
            if (Object.prototype.hasOwnProperty.call(value, camelCase)
                && value[camelCase] !== null
                && value[camelCase] !== undefined
            ) {
                return value[camelCase];
            }
            if (Object.prototype.hasOwnProperty.call(value, snakeCase)
                && value[snakeCase] !== null
                && value[snakeCase] !== undefined
            ) {
                return value[snakeCase];
            }
            return fallback;
        };
        const boundedId = function (candidate) {
            if (typeof candidate === 'number') {
                return Number.isSafeInteger(candidate) && candidate >= 0 ? candidate : 0;
            }
            const text = String(candidate ?? '').trim();
            if (!/^(?:0|[1-9][0-9]{0,15})$/.test(text)) {
                return 0;
            }
            const number = Number(text);
            return Number.isSafeInteger(number) && number >= 0 ? number : 0;
        };
        const boundedText = function (candidate, maxLength) {
            return String(candidate ?? '').trim().slice(0, maxLength);
        };
        const storeMode = String(claim('storeMode', 'store_mode', 'normal')).trim().toLowerCase();
        return {
            pageId: boundedId(claim('pageId', 'page_id', 0)),
            websiteId: boundedId(claim('websiteId', 'website_id', 0)),
            storeId: boundedId(claim('storeId', 'store_id', 0)),
            websiteCode: boundedText(claim('websiteCode', 'website_code', ''), 255),
            storeCode: boundedText(claim('storeCode', 'store_code', ''), 64),
            scope: boundedText(value.scope, 768),
            storeMode: ['normal', 'dev', 'test'].includes(storeMode) ? storeMode : 'normal',
            locale: normalizeLocale(value.locale),
            layoutOption: normalizeLayoutOption(claim('layoutOption', 'layout_option', '')),
        };
    }

    function isProtocolMessage(data, action) {
        return Boolean(data)
            && typeof data === 'object'
            && data.type === MESSAGE_TYPE
            && data.protocol === PROTOCOL
            && data.version === VERSION
            && data.action === action;
    }

    function createParentBridge(options) {
        const hostWindow = options.hostWindow;
        const targetWindow = options.targetWindow;
        const origin = options.origin || hostWindow.location.origin;
        const timeoutMs = Math.max(1, Number(options.timeoutMs) || 2000);
        let ready = false;
        let started = false;
        let pending = null;

        function clearPendingTimer() {
            if (pending && pending.timer) {
                hostWindow.clearTimeout ? hostWindow.clearTimeout(pending.timer) : clearTimeout(pending.timer);
                pending.timer = null;
            }
        }

        function finishPending(result) {
            if (!pending) {
                return;
            }
            const current = pending;
            clearPendingTimer();
            pending = null;
            current.resolve(result);
        }

        function failPending(reason) {
            if (!pending) {
                return;
            }
            finishPending({
                ok: false,
                fallback: false,
                reason,
                context: pending.context,
            });
        }

        function scheduleTimeout(reason) {
            clearPendingTimer();
            const schedule = hostWindow.setTimeout ? hostWindow.setTimeout.bind(hostWindow) : setTimeout;
            pending.timer = schedule(function () {
                // Without a v2 handshake/ACK the parent cannot prove that the
                // Theme iframe is clean. Fail closed for both readiness and ACK
                // timeouts; a URL reload could otherwise discard editor state.
                failPending(reason);
            }, timeoutMs);
        }

        function sendPending() {
            if (!pending || !ready) {
                return;
            }
            if (!post({
                type: MESSAGE_TYPE,
                protocol: PROTOCOL,
                version: VERSION,
                action: 'set-context',
                requestId: pending.requestId,
                context: pending.context,
            })) {
                failPending('post-failed');
                return;
            }
            scheduleTimeout('ack-timeout');
        }

        function requestReady() {
            return post({
                type: MESSAGE_TYPE,
                protocol: PROTOCOL,
                version: VERSION,
                action: 'probe',
            });
        }

        function post(payload) {
            try {
                targetWindow.postMessage(payload, origin);
                return true;
            } catch (error) {
                return false;
            }
        }

        function onMessage(event) {
            if (!event || event.origin !== origin || event.source !== targetWindow) {
                return;
            }
            if (isProtocolMessage(event.data, 'ready')) {
                ready = true;
                sendPending();
                return;
            }
            if (!pending || !isProtocolMessage(event.data, 'ack') || event.data.requestId !== pending.requestId) {
                return;
            }
            finishPending({
                ok: event.data.ok === true,
                dirty: event.data.dirty === true,
                reason: String(event.data.reason || ''),
                context: normalizeContext(event.data.context || pending.context),
            });
        }

        return {
            start() {
                if (started) {
                    return;
                }
                hostWindow.addEventListener('message', onMessage);
                started = true;
                // The child may have emitted its one-shot ready message before
                // the parent listener was attached (for example from a warm
                // browser cache). Probe explicitly so a dirty editor can never
                // be mistaken for an unavailable bridge and reloaded.
                requestReady();
            },
            isReady() {
                return ready;
            },
            setContext(context) {
                if (pending) {
                    finishPending({ ok: false, reason: 'superseded', context: pending.context });
                }
                return new Promise(function (resolve) {
                    pending = {
                        requestId: `cms-context-${Date.now()}-${++requestSequence}`,
                        context: normalizeContext(context),
                        resolve,
                        timer: null,
                    };
                    if (ready) {
                        sendPending();
                    } else {
                        if (!requestReady()) {
                            failPending('post-failed');
                        } else {
                            scheduleTimeout('not-ready');
                        }
                    }
                });
            },
            destroy() {
                clearPendingTimer();
                if (pending) {
                    finishPending({ ok: false, reason: 'destroyed', context: pending.context });
                }
                if (started) {
                    hostWindow.removeEventListener('message', onMessage);
                }
                started = false;
                ready = false;
            },
        };
    }

    function createChildBridge(options) {
        const hostWindow = options.hostWindow;
        const parentWindow = options.parentWindow;
        const origin = options.origin || hostWindow.location.origin;
        const applyContext = typeof options.applyContext === 'function' ? options.applyContext : async function () {};
        const isDirty = typeof options.isDirty === 'function' ? options.isDirty : function () { return false; };
        let started = false;
        let applyQueue = Promise.resolve();

        function post(payload) {
            try {
                parentWindow.postMessage(
                    Object.assign({ type: MESSAGE_TYPE, protocol: PROTOCOL, version: VERSION }, payload),
                    origin,
                );
                return true;
            } catch (error) {
                return false;
            }
        }

        function onMessage(event) {
            if (!event || event.origin !== origin || event.source !== parentWindow) {
                return;
            }
            if (isProtocolMessage(event.data, 'probe')) {
                post({ action: 'ready' });
                return;
            }
            if (!isProtocolMessage(event.data, 'set-context')) {
                return;
            }
            const requestId = String(event.data.requestId || '');
            if (!requestId) {
                return;
            }
            const context = normalizeContext(event.data.context);
            // Context switches mutate a shared editor state. Serialize them so
            // rapid Store/Locale changes cannot finish out of order.
            applyQueue = applyQueue.catch(function () {}).then(async function () {
                if (isDirty()) {
                    post({ action: 'ack', requestId, ok: false, dirty: true, reason: 'dirty', context });
                    return;
                }
                try {
                    await applyContext(context);
                    post({ action: 'ack', requestId, ok: true, context });
                } catch (error) {
                    post({
                        action: 'ack',
                        requestId,
                        ok: false,
                        dirty: isDirty(),
                        reason: error && error.message ? String(error.message) : 'apply-failed',
                        context,
                    });
                }
            });
        }

        return {
            start() {
                if (started) {
                    return;
                }
                hostWindow.addEventListener('message', onMessage);
                started = true;
                post({ action: 'ready' });
            },
            destroy() {
                if (started) {
                    hostWindow.removeEventListener('message', onMessage);
                }
                started = false;
            },
        };
    }

    return {
        MESSAGE_TYPE,
        PROTOCOL,
        VERSION,
        normalizeLocale,
        normalizeLayoutOption,
        normalizeContext,
        createParentBridge,
        createChildBridge,
    };
});

/* Weline UI source: js/theme-editor-toolbar-overflow.js */
/**
 * Theme Editor toolbar overflow.
 *
 * Keeps every toolbar action available while delegating popup positioning,
 * viewport collision handling, focus restoration and teardown to Weline.UI.
 */
(function registerThemeEditorToolbarOverflow() {
    'use strict';

    const UI = window.Weline?.UI;
    if (!UI) {
        throw new Error('Weline.UI must be loaded before Theme Editor toolbar overflow.');
    }

    const roots = () => [...document.querySelectorAll('[data-w-component~="toolbar-overflow"]')]
        .filter((element) => element instanceof HTMLElement);

    const flexGap = (element) => {
        const style = window.getComputedStyle(element);
        return Number.parseFloat(style.columnGap || style.gap) || 0;
    };

    const horizontalPadding = (element) => {
        const style = window.getComputedStyle(element);
        return (Number.parseFloat(style.paddingInlineStart || style.paddingLeft) || 0)
            + (Number.parseFloat(style.paddingInlineEnd || style.paddingRight) || 0);
    };

    function intrinsicWidth(element) {
        if (!(element instanceof HTMLElement)) return 0;
        const previous = {
            flex: element.style.flex,
            inlineSize: element.style.inlineSize,
            maxInlineSize: element.style.maxInlineSize,
            minInlineSize: element.style.minInlineSize,
        };
        element.style.flex = '0 0 auto';
        element.style.inlineSize = 'max-content';
        element.style.maxInlineSize = 'none';
        element.style.minInlineSize = '0';
        const width = Math.ceil(element.getBoundingClientRect().width || element.scrollWidth || 0);
        element.style.flex = previous.flex;
        element.style.inlineSize = previous.inlineSize;
        element.style.maxInlineSize = previous.maxInlineSize;
        element.style.minInlineSize = previous.minInlineSize;
        return width;
    }

    function availableWidth(root) {
        const parent = root.parentElement;
        if (!(parent instanceof HTMLElement)) return Math.max(96, root.clientWidth || 96);

        const parentWidth = parent.clientWidth - horizontalPadding(parent);
        const gap = flexGap(parent);
        let siblingWidth = 0;

        if (root.classList.contains('toolbar-right')) {
            const left = parent.querySelector('.toolbar-left');
            if (left instanceof HTMLElement && Math.abs(root.offsetTop - left.offsetTop) > 1) {
                return Math.max(96, Math.floor(parentWidth));
            }
            const leftNeed = Math.min(intrinsicWidth(left), Math.floor(parentWidth * 0.58));
            siblingWidth = leftNeed + gap;
        } else if (root.classList.contains('preview-actions')) {
            siblingWidth = intrinsicWidth(parent.querySelector('.preview-tabs')) + gap;
        } else {
            const siblings = [...parent.children].filter((element) => (
                element !== root && element instanceof HTMLElement && !element.hidden
            ));
            siblingWidth = siblings.reduce((sum, element) => sum + intrinsicWidth(element), 0)
                + gap * siblings.length;
        }

        return Math.max(96, Math.floor(parentWidth - siblingWidth));
    }

    function usedWidth(root, more) {
        const children = [...root.children].filter((element) => (
            element instanceof HTMLElement && !(element === more && more.hidden)
        ));
        if (children.length === 0) return 0;
        return children.reduce((sum, element) => sum + element.offsetWidth, 0)
            + flexGap(root) * Math.max(0, children.length - 1);
    }

    function scheduleAllLayouts() {
        cancelAnimationFrame(scheduleAllLayouts.frame || 0);
        scheduleAllLayouts.frame = requestAnimationFrame(() => {
            const ordered = roots().sort((left, right) => {
                const rank = (element) => {
                    if (element.classList.contains('toolbar-right')) return 0;
                    if (element.classList.contains('toolbar-selects')) return 1;
                    if (element.classList.contains('preview-actions')) return 2;
                    return 3;
                };
                return rank(left) - rank(right);
            });
            ordered.forEach((root) => UI.get(root, 'toolbar-overflow')?.layout());
        });
    }

    UI.define('toolbar-overflow', ({ element, listen, emit, floating }) => {
        const itemsHost = element.querySelector('[data-w-toolbar-overflow-items]');
        const more = element.querySelector('[data-w-toolbar-overflow-more]');
        const trigger = element.querySelector('[data-w-toolbar-overflow-toggle]');
        const menu = element.querySelector('[data-w-toolbar-overflow-menu]');
        if (!(itemsHost instanceof HTMLElement)
            || !(more instanceof HTMLElement)
            || !(trigger instanceof HTMLElement)
            || !(menu instanceof HTMLElement)) {
            return {};
        }

        const portal = floating.portal(menu, 'toolbar-overflow');
        const placement = () => element.dataset.wPlacement || 'bottom-end';
        let pointerReference = null;
        let layoutFrame = 0;
        let destroyed = false;

        const restoreItems = () => {
            while (menu.firstChild) itemsHost.append(menu.firstChild);
        };

        const close = (reason = '', restoreFocus = false, force = false) => {
            if (menu.hidden || (!force && !emit('before-close', { reason }))) return false;
            menu.hidden = true;
            menu.dataset.state = 'closed';
            menu.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            element.dataset.state = 'closed';
            monitor.unobserve(menu);
            monitor.reset();
            floating.clear(menu);
            portal.restore();
            pointerReference = null;
            if (restoreFocus && trigger.isConnected) trigger.focus({ preventScroll: true });
            emit('close', { reason }, false);
            scheduleLayout();
            return true;
        };

        const monitor = floating.monitor(
            trigger,
            () => menu,
            placement,
            () => close('anchor-hidden', false, true),
        );

        const open = (reference = null) => {
            if (!menu.hidden || menu.childElementCount === 0 || !emit('before-open')) return false;
            roots().forEach((root) => {
                if (root !== element) UI.get(root, 'toolbar-overflow')?.close('peer');
            });
            portal.mount();
            menu.hidden = false;
            menu.dataset.state = 'open';
            menu.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            element.dataset.state = 'open';
            monitor.observe(menu);
            const stableReference = reference || floating.capture(trigger, null, 'element');
            if (monitor.place(stableReference)?.anchorVisible === false) {
                close('anchor-hidden', false, true);
                return false;
            }
            queueMicrotask(() => menu.querySelector('button:not([disabled]), a[href], select:not([disabled])')?.focus());
            emit('open', {}, false);
            return true;
        };

        const layout = () => {
            if (destroyed || !menu.hidden) return;
            restoreItems();
            more.hidden = true;
            element.style.removeProperty('inline-size');
            element.style.removeProperty('max-inline-size');

            const limit = availableWidth(element);
            element.style.setProperty('max-inline-size', `${limit}px`);

            if (usedWidth(element, more) > limit + 1) {
                more.hidden = false;
                const movable = [...itemsHost.children].filter((item) => (
                    item instanceof HTMLElement && !item.hasAttribute('data-w-toolbar-overflow-pin')
                ));
                let guard = 0;
                while (usedWidth(element, more) > limit + 1 && movable.length > 0 && guard < 64) {
                    guard += 1;
                    const item = movable.pop();
                    if (item instanceof HTMLElement && item.parentElement === itemsHost) {
                        menu.insertBefore(item, menu.firstChild);
                    }
                }
                if (menu.childElementCount === 0) more.hidden = true;
            }

            if (more.hidden) {
                menu.hidden = true;
                menu.dataset.state = 'closed';
                menu.setAttribute('aria-hidden', 'true');
                trigger.setAttribute('aria-expanded', 'false');
                element.dataset.state = 'closed';
            }
        };

        function scheduleLayout() {
            cancelAnimationFrame(layoutFrame);
            layoutFrame = requestAnimationFrame(layout);
        }

        const resizeObserver = typeof ResizeObserver === 'function'
            ? new ResizeObserver(scheduleAllLayouts)
            : null;
        resizeObserver?.observe(element);
        if (element.parentElement) resizeObserver?.observe(element.parentElement);
        const shell = element.closest('.editor-toolbar, .preview-toolbar, .theme-editor-container');
        if (shell instanceof HTMLElement) resizeObserver?.observe(shell);

        listen(trigger, 'pointerdown', (event) => {
            if (!event.isPrimary || event.button !== 0) return;
            pointerReference = floating.capture(trigger, event, 'element');
        });
        listen(trigger, 'click', (event) => {
            event.preventDefault();
            if (!menu.hidden) {
                close('trigger');
                return;
            }
            const reference = pointerReference
                && performance.now() - pointerReference.capturedAt < 1200
                ? pointerReference
                : floating.capture(trigger, null, 'element');
            pointerReference = null;
            open(reference);
        });
        listen(document, 'pointerdown', (event) => {
            if (!element.contains(event.target) && !portal.contains(event.target)) close('outside');
        });
        listen(document, 'keydown', (event) => {
            if (menu.hidden || event.key !== 'Escape' || event.defaultPrevented || !portal.isTopmost()) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            close('escape', true);
        });
        listen(menu, 'click', (event) => {
            if (event.target instanceof Element && event.target.closest('button, a[href]')) {
                queueMicrotask(() => close('action'));
            }
        });
        listen(window, 'pagehide', () => close('pagehide', false, true));
        listen(window, 'pageshow', () => close('history-restore', false, true));

        menu.hidden = true;
        menu.dataset.state = 'closed';
        menu.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        element.dataset.state = 'closed';
        scheduleAllLayouts();

        return {
            open,
            close,
            layout,
            destroy() {
                close('unmount', false, true);
                destroyed = true;
                cancelAnimationFrame(layoutFrame);
                resizeObserver?.disconnect();
                monitor.destroy();
                portal.destroy();
                restoreItems();
                element.style.removeProperty('max-inline-size');
            },
        };
    });

    UI.mount(document);
})();

/* Weline UI source: js/theme-editor.js */
/**
 * 主题编辑器交互脚本
 */
(function() {
    'use strict';

    // 编辑器作用域切换保持一次用户触发的顶层文档导航，以便新页面签发后台 Worker 证明。

    const Weline = window.Weline = window.Weline || {};
    Weline.Theme = Weline.Theme || {};
    const EditorApi = Weline.Theme.Editor = Weline.Theme.Editor || {};

    // 编辑器配置 - 从 DOM 获取后台 URL
    const config = {
        apiBase: '',
        apiSaveWidget: '',
        apiUpdateConfig: '',
        apiDeleteWidget: '',
        apiWidgets: '',
        apiDefaultInjections: '',
        apiApplyDefaultInjection: '',
        apiInitSlotDefaults: '',
        apiPublish: '',
        apiPreview: '',
        apiCompileLayout: '',
        apiLayoutOptions: '',
        apiLayoutConfig: '',
        apiSaveLayoutSelection: '',
        apiSaveLayoutConfig: '',
        apiAiTranslateConfig: '',
        apiVirtualThemeAiCatalog: '',
        apiVirtualThemeCreateDraft: '',
        apiVirtualThemeBlockAction: '',
        apiVirtualThemeSource: '',
        apiVirtualThemeSaveSource: '',
        apiVirtualThemePublishVersion: '',
        apiLayoutPreview: '',
        apiParamRenderForm: '',
        defaultLocale: 'zh_Hans_CN',
        autoSaveDelay: 1000,
        // 版本控制 API
        apiVersions: '',
        apiSaveVersion: '',
        apiSwitchVersion: '',
        apiRestoreOriginal: '',
        apiResetDraftResources: '',
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
        apiScopedWorkspace: '',
        apiPublishScopedWorkspace: '',
    };

    // 状态管理
    const state = {
        themeId: 0,
        pageType: 'default',
        layoutType: 'default',
        layoutOption: 'default',
        layoutOptionsByType: {},
        selectedWidget: null,
        configMode: 'layout',
        selectedArea: null, // 当前选中的区域代码
        isDragging: false,
        hasChanges: false,
        draggingWidget: null, // 当前拖拽的部件数据
        previewDragSessionId: '',
        previewDropCandidate: null,
        previewDropCommittedSessionId: '',
        previewDragCancelled: false,
        previewDropFallbackTimer: null,
        lastPreviewInsertSortOrder: null,
        selectedSlot: null, // 当前选中的插槽
        originalWidgetOrder: new Map(), // 保存原始部件顺序
        originalGroupOrder: [], // 保存原始分组顺序
        previewRefreshInFlight: false,
        previewRefreshQueued: false,
        previewNavigationSequence: 0,
        previewArrayItemIndexByLayout: {},
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
        virtualThemeAiCatalog: null,
        layoutLock: { enabled: false },
        layoutIdentity: {},
        sidePanels: { configOpen: true, widgetOpen: true },
        slotsPanelOpen: false,
        interactionMode: 'edit', // edit：插槽 hover/面板；preview：真预览
        interactionModePanelsSnapshot: null,
        selectionTarget: 'default', // default | slot | widget
        linkBlockEnabled: false, // 独立：阻止 a 跳转，可与选中目标叠加
        fullscreenSidePanelsSnapshot: null,
        editorFullscreenFallback: false,
        configLocale: '',
        widgetLibraryTab: 'general',
        widgetLibraryRenderMode: 'widgets',
        widgetLibraryTabCounts: { general: 0, basic: 0, applications: 0 },
        defaultInjectionLib: {
            items: [],
            total: 0,
            loading: false,
            applyingKey: '',
            renderAfterLoad: false,
        },
        scopeIdentity: null,
        legacyScopeReadonly: false,
        scopedWorkspaces: {},
        pendingScopedMutation: Promise.resolve(),
    };
    const scheduledEditorAutoSaves = new Map();
    const activeEditorAutoSaves = new Map();
    const failedEditorAutoSaves = new Map();

    function runEditorAutoSave(key, callback) {
        failedEditorAutoSaves.delete(key);
        const predecessor = activeEditorAutoSaves.get(key);
        const task = (predecessor ? predecessor.catch(() => undefined) : Promise.resolve())
            .then(callback);
        activeEditorAutoSaves.set(key, task);
        task.then(
            () => {
                if (activeEditorAutoSaves.get(key) === task) {
                    activeEditorAutoSaves.delete(key);
                    failedEditorAutoSaves.delete(key);
                }
            },
            (error) => {
                if (activeEditorAutoSaves.get(key) === task) {
                    activeEditorAutoSaves.delete(key);
                    failedEditorAutoSaves.set(key, { callback, error });
                }
            },
        );
        return task;
    }

    function scheduleEditorAutoSave(key, callback, delay = 400) {
        const previous = scheduledEditorAutoSaves.get(key);
        if (previous?.timer) clearTimeout(previous.timer);
        failedEditorAutoSaves.delete(key);
        const record = { callback, timer: null };
        record.timer = setTimeout(() => {
            if (scheduledEditorAutoSaves.get(key) !== record) return;
            scheduledEditorAutoSaves.delete(key);
            runEditorAutoSave(key, callback);
        }, delay);
        scheduledEditorAutoSaves.set(key, record);
    }

    function cancelEditorAutoSave(key) {
        const pending = scheduledEditorAutoSaves.get(key);
        if (pending?.timer) clearTimeout(pending.timer);
        scheduledEditorAutoSaves.delete(key);
        failedEditorAutoSaves.delete(key);
    }

    async function flushPendingEditorMutations() {
        const retry = new Map();
        failedEditorAutoSaves.forEach((entry, key) => retry.set(key, entry.callback));
        scheduledEditorAutoSaves.forEach((entry, key) => {
            if (entry.timer) clearTimeout(entry.timer);
            retry.set(key, entry.callback);
        });
        scheduledEditorAutoSaves.clear();

        const tasks = Array.from(activeEditorAutoSaves.values());
        retry.forEach((callback, key) => tasks.push(runEditorAutoSave(key, callback)));
        const results = await Promise.allSettled(tasks);
        const failed = results.find((result) => result.status === 'rejected');
        if (failed) throw failed.reason;
        await state.pendingScopedMutation;
    }

    function enforceLegacyScopeReadonly(container) {
        container.setAttribute('aria-readonly', 'true');
        container.querySelectorAll('button, input, select, textarea, [contenteditable], [draggable="true"]').forEach((element) => {
            if ('disabled' in element) element.disabled = true;
            element.setAttribute('aria-disabled', 'true');
            element.removeAttribute('contenteditable');
            element.setAttribute('draggable', 'false');
        });
    }

    // DOM 元素
    let elements = {};
    const SIDE_PANEL_STORAGE_KEY = 'weline.theme.editor.sidePanels.v1';
    const SLOTS_PANEL_STORAGE_KEY = 'weline.theme.editor.slotsPanel.v1';
    const INTERACTION_MODE_STORAGE_KEY = 'weline.theme.editor.interactionMode.v1';
    const SELECTION_TARGET_STORAGE_KEY = 'weline.theme.editor.selectionTarget.v1';
    const LINK_BLOCK_STORAGE_KEY = 'weline.theme.editor.linkBlock.v1';
    const DASHBOARD_BASIC_WIDGET_CODES = new Set([
        'alert', 'badge', 'button', 'card', 'dropdown', 'form', 'field',
        'field-group', 'form-actions', 'form-group', 'grid', 'input',
        'loading', 'message', 'modal', 'pagination', 'section', 'table', 'tabs',
        'text', 'image', 'divider', 'spacer'
    ]);
    const WIDGET_LIBRARY_FILTER_PARAM_KEYS = [
        'widget_allow_groups',
        'widget_reject_groups',
        'widget_allow_codes',
        'widget_reject_codes',
        'widget_allow_widgets',
        'widget_reject_widgets',
        'widget_allow_supports',
        'widget_reject_supports',
        'widget_allow_protocols',
        'widget_reject_protocols'
    ];

    function notifyDashboardEditor(eventName, detail = {}) {
        if (!window.parent || window.parent === window) {
            return;
        }
        if ((state.pageType || '') !== 'dashboard') {
            return;
        }
        try {
            window.parent.postMessage({
                type: `weline-theme-editor:${eventName}`,
                detail: detail,
            }, window.location.origin);
        } catch (error) {
        }
    }

    function notifyDashboardLayoutMutated(reason, detail = {}) {
        notifyDashboardEditor('layout-mutated', {
            reason: reason,
            ...detail,
        });
    }

    function notifyDashboardLayoutSaved(reason, detail = {}) {
        notifyDashboardEditor('saved', {
            reason: reason,
            ...detail,
        });
    }

    let cmsContextBridge = null;

    function canonicalCmsStoreScope(websiteCode, storeCode, storeMode) {
        const normalizeSegment = (value, fallback) => {
            const normalized = String(value || fallback || '').trim().toLowerCase();
            return /^[a-z0-9][a-z0-9_-]{0,254}$/.test(normalized) ? normalized : '';
        };
        const website = normalizeSegment(websiteCode, 'default');
        const store = normalizeSegment(storeCode, 'default');
        const mode = String(storeMode || 'normal').trim().toLowerCase();
        if (!website || !store || !['normal', 'dev', 'test'].includes(mode)) {
            return '';
        }
        const storageScope = `${website}.${store === 'default' ? '__store__' : store}.default`;
        return mode === 'normal' ? storageScope : `${storageScope}~${mode}`;
    }

    function cmsStoreScopeIdentity(context) {
        const websiteId = Number.parseInt(context.websiteId ?? context.website_id, 10);
        const websiteCode = String(context.websiteCode || context.website_code || '').trim().toLowerCase();
        const storeCode = String(context.storeCode || context.store_code || '').trim().toLowerCase();
        const storeMode = String(context.storeMode || context.store_mode || 'normal').trim().toLowerCase();
        if (!Number.isSafeInteger(websiteId) || websiteId < 0
            || !/^[a-z0-9][a-z0-9_-]{0,254}$/.test(websiteCode)
            || !/^[a-z0-9][a-z0-9_-]{0,63}$/.test(storeCode)
            || !['normal', 'dev', 'test'].includes(storeMode)
        ) {
            throw new Error('cms-store-context-invalid');
        }
        return {
            scope_kind: 'store',
            website_id: websiteId,
            website_code: websiteCode,
            store_code: storeCode,
            channel_code: null,
            store_mode: storeMode,
            context_version: String(state.scopeIdentity?.context_version || 'v1'),
        };
    }

    async function applyCmsEditorContext(context) {
        const protocol = Weline.Theme.CmsPreviewBridge;
        const locale = protocol ? protocol.normalizeLocale(context.locale) : String(context.locale || '').trim();
        const requestedLayout = protocol
            ? protocol.normalizeLayoutOption(context.layoutOption)
            : normalizeLayoutOptionValue(context.layoutOption);
        const scope = String(context.scope || '').trim();
        const storeMode = String(context.storeMode || context.store_mode || 'normal').trim().toLowerCase();
        const pageId = parseInt(context.pageId || context.page_id || 0, 10) || 0;
        const websiteId = Number.parseInt(context.websiteId ?? context.website_id, 10);
        const websiteCode = String(context.websiteCode || context.website_code || '').trim().toLowerCase();
        const storeId = Number.parseInt(context.storeId ?? context.store_id, 10);
        const storeCode = String(context.storeCode || context.store_code || '').trim().toLowerCase();
        const lockSource = String(state.layoutLock?.lock_source || state.layoutLock?.source || '').trim().toLowerCase();
        const lockedTargetType = String(state.layoutLock?.target_type || '').trim().toLowerCase();
        const lockedTargetId = parseInt(state.layoutLock?.target_id || 0, 10) || 0;
        const lockedWebsiteId = Number.parseInt(state.layoutLock?.website_id, 10);
        const lockedWebsiteCode = String(state.layoutLock?.website_code || '').trim().toLowerCase();
        const canonicalScope = canonicalCmsStoreScope(websiteCode, storeCode, storeMode);
        if (!isLayoutLocked() || lockSource !== 'cms' || lockedTargetType !== 'cms_page') {
            throw new Error('cms-layout-lock-required');
        }
        if (pageId <= 0 || lockedTargetId <= 0 || lockedTargetId !== pageId) {
            throw new Error('cms-target-mismatch');
        }
        if (!Number.isSafeInteger(websiteId) || websiteId < 0
            || !Number.isSafeInteger(storeId) || storeId <= 0
            || !locale || !requestedLayout || !canonicalScope || scope !== canonicalScope
        ) {
            throw new Error('cms-context-invalid');
        }
        if ((Number.isSafeInteger(lockedWebsiteId) && lockedWebsiteId !== websiteId)
            || (lockedWebsiteCode && lockedWebsiteCode !== websiteCode)
        ) {
            throw new Error('cms-website-mismatch');
        }
        const nextScopeIdentity = cmsStoreScopeIdentity(context);
        const nextLayoutOption = resolveLayoutOptionForType(state.layoutType, requestedLayout);
        if (nextLayoutOption !== requestedLayout) {
            throw new Error('cms-layout-option-invalid');
        }

        await flushPendingEditorMutations();
        if (state.hasChanges) {
            throw new Error('dirty');
        }

        const previousEditorState = {
            configLocale: state.configLocale,
            scopeIdentity: state.scopeIdentity,
            layoutLock: state.layoutLock,
            layoutIdentity: state.layoutIdentity,
            scopedWorkspaces: state.scopedWorkspaces,
            pendingScopedMutation: state.pendingScopedMutation,
            selectedWidget: state.selectedWidget,
            selectedSlot: state.selectedSlot,
            currentVersionId: state.currentVersionId,
            publishedVersionId: state.publishedVersionId,
            versions: state.versions,
            layoutOption: state.layoutOption,
            lockHeld: state.lockHeld,
            lockConflictInfo: state.lockConflictInfo,
        };
        const previousUrl = window.location.href;
        const previousHistoryState = window.history.state;
        const currentLockPayload = state.lockHeld ? buildLayoutVersionIdentityPayload() : null;
        const nextLockPayload = buildCmsEditorLockPayload({
            locale,
            layoutOption: nextLayoutOption,
            pageId,
            scope: canonicalScope,
            scopeIdentity: nextScopeIdentity,
            storeMode,
        });
        const reusesCurrentLock = currentLockPayload !== null
            && editorLockIdentityKey(currentLockPayload) === editorLockIdentityKey(nextLockPayload);
        let acquiredNextLock = reusesCurrentLock;
        if (!acquiredNextLock) {
            const acquireResult = await acquireEditorLockPayload(nextLockPayload);
            if (!(acquireResult && acquireResult.success)) {
                throw new Error(acquireResult?.message || 'cms-context-lock-acquire-failed');
            }
            acquiredNextLock = true;
        }
        if (currentLockPayload !== null && !reusesCurrentLock) {
            if (!(await releaseEditorLockPayload(currentLockPayload))) {
                if (acquiredNextLock) {
                    await releaseEditorLockPayload(nextLockPayload);
                }
                state.lockHeld = false;
                state.lockConflictInfo = null;
                stopLockHeartbeat();
                renderEditorLockOverlay(null, 'unavailable');
                throw new Error('cms-context-lock-release-failed');
            }
        }

        try {
            state.configLocale = locale;
            syncThemeEditorLocaleDataset();
            state.scopeIdentity = nextScopeIdentity;
            state.layoutLock = {
                ...state.layoutLock,
                layout_option: requestedLayout,
                scope: canonicalScope,
                locale_code: locale,
                store_mode: storeMode,
                website_id: websiteId,
                website_code: websiteCode,
                store_id: storeId,
                store_code: storeCode,
            };
            state.layoutIdentity = {
                ...(state.layoutIdentity || {}),
                scope: canonicalScope,
                layout_option: requestedLayout,
                locale,
                locale_code: locale,
                target_type: 'cms_page',
                target_id: pageId,
            };
            state.scopedWorkspaces = {};
            state.pendingScopedMutation = Promise.resolve();
            state.selectedWidget = null;
            state.selectedSlot = null;
            state.currentVersionId = null;
            state.publishedVersionId = null;
            state.versions = [];
            syncConfigLocaleSwitchers();
            state.layoutOption = nextLayoutOption;
            state.layoutLock.layout_option = state.layoutOption;
            state.layoutIdentity.layout_option = state.layoutOption;
            renderLayoutOptionSelect(state.layoutType, state.layoutOption);
            syncEditorUrlState({
                theme_id: state.themeId,
                page_type: getCurrentPageType(),
                layout_option: state.layoutOption || 'default',
                locale: state.configLocale || null,
                locale_code: state.configLocale || null,
                scope: canonicalScope,
                store_mode: storeMode || 'normal',
                website_id: websiteId,
                website_code: websiteCode,
                store_id: storeId,
                store_code: storeCode,
                version_id: null,
            });
            state.lockHeld = acquiredNextLock;
            state.lockConflictInfo = null;
            clearEditorLockOverlay();
            startLockHeartbeat();
            bindLockLifecycle();
        } catch (contextApplyError) {
            let oldLockRestored = reusesCurrentLock && previousEditorState.lockHeld === true;
            let newLockReleased = true;
            let rollbackConflictInfo = null;
            if (!reusesCurrentLock) {
                if (currentLockPayload !== null) {
                    const reacquireResult = await acquireEditorLockPayload(currentLockPayload);
                    oldLockRestored = !!(reacquireResult && reacquireResult.success);
                    rollbackConflictInfo = reacquireResult?.data?.lock_info || null;
                }
                if (acquiredNextLock) {
                    newLockReleased = await releaseEditorLockPayload(nextLockPayload);
                }
            }

            Object.assign(state, previousEditorState);
            try {
                window.history.replaceState(previousHistoryState, '', previousUrl);
                syncConfigLocaleSwitchers();
                renderLayoutOptionSelect(state.layoutType, state.layoutOption);
            } catch (restoreError) {
                console.warn('[ThemeEditor] CMS context UI rollback failed:', restoreError);
            }

            if (oldLockRestored && newLockReleased && previousEditorState.lockHeld === true) {
                state.lockHeld = true;
                state.lockConflictInfo = null;
                clearEditorLockOverlay();
                startLockHeartbeat();
            } else {
                state.lockHeld = false;
                state.lockConflictInfo = rollbackConflictInfo;
                stopLockHeartbeat();
                renderEditorLockOverlay(rollbackConflictInfo, rollbackConflictInfo ? 'conflict' : 'unavailable');
            }
            throw contextApplyError;
        }
        showPreviewLoadingImmediate();
        loadLayoutPreview({
            locale: state.configLocale,
            locale_code: state.configLocale,
            scope: canonicalScope,
            store_mode: storeMode || 'normal',
            layout_option: state.layoutOption,
        });
        Promise.resolve(loadLayoutConfig({
            locale: state.configLocale,
            locale_code: state.configLocale,
            scope: canonicalScope,
            store_mode: storeMode || 'normal',
            silent: true,
        })).catch((error) => {
            console.warn('[ThemeEditor] CMS context config refresh failed:', error);
        });
        Promise.resolve(loadVersions()).catch((error) => {
            console.warn('[ThemeEditor] CMS context version refresh failed:', error);
        });
    }

    function initCmsContextBridge() {
        if (!window.parent || window.parent === window || !Weline.Theme.CmsPreviewBridge) {
            return;
        }
        if (cmsContextBridge) {
            cmsContextBridge.destroy();
        }
        cmsContextBridge = Weline.Theme.CmsPreviewBridge.createChildBridge({
            hostWindow: window,
            parentWindow: window.parent,
            isDirty: () => state.hasChanges === true,
            applyContext: applyCmsEditorContext,
        });
        cmsContextBridge.start();
    }

    function isCompactEditorViewport() {
        return typeof window.matchMedia === 'function'
            ? window.matchMedia('(max-width: 1100px)').matches
            : window.innerWidth <= 1100;
    }

    function readSidePanelPreference() {
        try {
            const raw = window.localStorage ? window.localStorage.getItem(SIDE_PANEL_STORAGE_KEY) : '';
            if (!raw) {
                return null;
            }
            const data = JSON.parse(raw);
            if (!data || typeof data !== 'object') {
                return null;
            }

            return {
                configOpen: data.configOpen === true,
                widgetOpen: data.widgetOpen === true,
            };
        } catch (error) {
            return null;
        }
    }

    function saveSidePanelPreference() {
        try {
            if (!window.localStorage) {
                return;
            }
            window.localStorage.setItem(SIDE_PANEL_STORAGE_KEY, JSON.stringify({
                configOpen: state.sidePanels.configOpen === true,
                widgetOpen: state.sidePanels.widgetOpen === true,
            }));
        } catch (error) {
        }
    }

    function notifyEditorViewportChanged() {
        const emitResize = function() {
            try {
                if (elements.previewFrame && elements.previewFrame.contentWindow) {
                    elements.previewFrame.contentWindow.dispatchEvent(new Event('resize'));
                }
            } catch (error) {
            }

            scheduleFitWidgetPreviews();
        };

        emitResize();
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(emitResize);
        }
        window.setTimeout(emitResize, 240);
    }

    function applySidePanelState() {
        if (!elements.container) {
            return;
        }
        const compact = isCompactEditorViewport();
        // 紧凑视口预览空间有限：同一时刻只允许一个侧栏打开；布局仍为网格推挤，不覆盖预览。
        if (compact && state.sidePanels.configOpen === true && state.sidePanels.widgetOpen === true) {
            state.sidePanels.configOpen = false;
        }
        elements.container.classList.toggle('editor-compact-mode', compact);
        elements.container.classList.toggle('panel-config-open', state.sidePanels.configOpen === true);
        elements.container.classList.toggle('panel-widget-open', state.sidePanels.widgetOpen === true);
        elements.container.classList.toggle('panel-config-collapsed', state.sidePanels.configOpen !== true);
        elements.container.classList.toggle('panel-widget-collapsed', state.sidePanels.widgetOpen !== true);
        notifyEditorViewportChanged();
    }

    function initSidePanels() {
        const preference = readSidePanelPreference();
        const defaultOpen = !isCompactEditorViewport();
        state.sidePanels.configOpen = preference ? preference.configOpen : defaultOpen;
        state.sidePanels.widgetOpen = preference ? preference.widgetOpen : defaultOpen;
        applySidePanelState();

        window.addEventListener('resize', debounce(function() {
            applySidePanelState();
        }, 120));
    }

    function setSidePanelOpen(panel, open, persist = true) {
        const shouldOpen = open === true;
        const compact = isCompactEditorViewport();
        if (panel === 'config') {
            state.sidePanels.configOpen = shouldOpen;
            if (compact && shouldOpen) {
                state.sidePanels.widgetOpen = false;
            }
        } else if (panel === 'widget') {
            state.sidePanels.widgetOpen = shouldOpen;
            if (compact && shouldOpen) {
                state.sidePanels.configOpen = false;
            }
        }

        applySidePanelState();
        if (persist) {
            saveSidePanelPreference();
        }
        if (panel === 'widget' && shouldOpen) {
            scheduleFitWidgetPreviews();
        }
    }

    function readSlotsPanelPreference() {
        try {
            const raw = window.localStorage ? window.localStorage.getItem(SLOTS_PANEL_STORAGE_KEY) : '';
            if (!raw) {
                return null;
            }
            const data = JSON.parse(raw);
            if (!data || typeof data !== 'object') {
                return null;
            }

            return {
                open: data.open === true,
            };
        } catch (error) {
            return null;
        }
    }

    function saveSlotsPanelPreference() {
        try {
            if (!window.localStorage) {
                return;
            }
            window.localStorage.setItem(SLOTS_PANEL_STORAGE_KEY, JSON.stringify({
                open: state.slotsPanelOpen === true,
            }));
        } catch (error) {
        }
    }

    function applySlotsPanelState() {
        const dock = elements.slotsInfoDock;
        if (!dock) {
            return;
        }

        const open = state.slotsPanelOpen === true;
        dock.classList.toggle('slots-panel-open', open);
        dock.classList.toggle('slots-panel-collapsed', !open);

        const toggle = dock.querySelector('[data-theme-editor-action="toggle-slots-panel"]');
        if (toggle instanceof HTMLElement) {
            toggle.setAttribute('aria-expanded', String(open));
        }
    }

    function initSlotsPanel() {
        const preference = readSlotsPanelPreference();
        state.slotsPanelOpen = preference ? preference.open === true : false;
        applySlotsPanelState();
    }

    function setSlotsPanelOpen(open, persist = true) {
        state.slotsPanelOpen = open === true;
        applySlotsPanelState();
        if (persist) {
            saveSlotsPanelPreference();
        }
    }

    function isEditInteractionMode() {
        return state.interactionMode !== 'preview';
    }

    function isPreviewInteractionMode() {
        return state.interactionMode === 'preview';
    }

    function normalizeInteractionMode(mode) {
        return mode === 'preview' ? 'preview' : 'edit';
    }

    function readStoredInteractionMode() {
        try {
            if (!window.sessionStorage) {
                return '';
            }
            return normalizeInteractionMode(window.sessionStorage.getItem(INTERACTION_MODE_STORAGE_KEY) || '');
        } catch (error) {
            return '';
        }
    }

    function persistInteractionMode(mode) {
        const next = normalizeInteractionMode(mode);
        try {
            if (window.sessionStorage) {
                window.sessionStorage.setItem(INTERACTION_MODE_STORAGE_KEY, next);
            }
        } catch (error) {
            // Ignore quota / private-mode failures; in-memory state still works.
        }
        return next;
    }

    function resolveInitialInteractionMode() {
        const fromUrl = normalizeInteractionMode(getCurrentWindowParam('interaction_mode'));
        if (getCurrentWindowParam('interaction_mode')) {
            return fromUrl;
        }
        const fromStore = readStoredInteractionMode();
        return fromStore || 'edit';
    }

    function notifyPreviewInteractionMode(mode = state.interactionMode) {
        const previewWindow = elements.previewFrame?.contentWindow;
        if (!previewWindow) {
            return;
        }
        previewWindow.postMessage({
            source: 'weline-theme-editor',
            type: 'interaction-mode',
            mode: normalizeInteractionMode(mode),
            selection_target: normalizeSelectionTarget(state.selectionTarget),
            link_block: state.linkBlockEnabled === true,
        }, window.location.origin);
    }

    function normalizeSelectionTarget(mode) {
        const value = String(mode || '').trim().toLowerCase();
        if (value === 'slot' || value === 'widget') {
            return value;
        }
        return 'default';
    }

    function normalizeLinkBlockEnabled(value) {
        if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
            return true;
        }
        return false;
    }

    function readStoredSelectionTarget() {
        try {
            if (!window.sessionStorage) {
                return '';
            }
            return normalizeSelectionTarget(window.sessionStorage.getItem(SELECTION_TARGET_STORAGE_KEY) || '');
        } catch (error) {
            return '';
        }
    }

    function persistSelectionTarget(mode) {
        const next = normalizeSelectionTarget(mode);
        try {
            if (window.sessionStorage) {
                window.sessionStorage.setItem(SELECTION_TARGET_STORAGE_KEY, next);
            }
        } catch (error) {
            // Ignore quota / private-mode failures.
        }
        return next;
    }

    function resolveInitialSelectionTarget() {
        const fromUrl = getCurrentWindowParam('selection_target');
        if (fromUrl) {
            return normalizeSelectionTarget(fromUrl);
        }
        return readStoredSelectionTarget() || 'default';
    }

    function readStoredLinkBlockEnabled() {
        try {
            if (!window.sessionStorage) {
                return null;
            }
            const raw = window.sessionStorage.getItem(LINK_BLOCK_STORAGE_KEY);
            if (raw == null || raw === '') {
                return null;
            }
            return normalizeLinkBlockEnabled(raw);
        } catch (error) {
            return null;
        }
    }

    function persistLinkBlockEnabled(enabled) {
        const next = normalizeLinkBlockEnabled(enabled);
        try {
            if (window.sessionStorage) {
                window.sessionStorage.setItem(LINK_BLOCK_STORAGE_KEY, next ? '1' : '0');
            }
        } catch (error) {
            // Ignore quota / private-mode failures.
        }
        return next;
    }

    function resolveInitialLinkBlockEnabled() {
        const fromUrl = getCurrentWindowParam('link_block');
        if (fromUrl != null && fromUrl !== '') {
            return normalizeLinkBlockEnabled(fromUrl);
        }
        const fromStore = readStoredLinkBlockEnabled();
        return fromStore == null ? false : fromStore;
    }

    function notifyPreviewSelectionTarget(mode = state.selectionTarget) {
        const previewWindow = elements.previewFrame?.contentWindow;
        if (!previewWindow) {
            return;
        }
        previewWindow.postMessage({
            source: 'weline-theme-editor',
            type: 'selection-target',
            mode: normalizeSelectionTarget(mode),
        }, window.location.origin);
    }

    function notifyPreviewLinkBlock(enabled = state.linkBlockEnabled) {
        const previewWindow = elements.previewFrame?.contentWindow;
        if (!previewWindow) {
            return;
        }
        previewWindow.postMessage({
            source: 'weline-theme-editor',
            type: 'link-block',
            enabled: normalizeLinkBlockEnabled(enabled),
        }, window.location.origin);
    }

    function applySelectionTargetUi() {
        if (!elements.container) {
            return;
        }
        const target = normalizeSelectionTarget(state.selectionTarget);
        elements.container.setAttribute('data-selection-target', target);
        document.querySelectorAll('[data-theme-editor-action="set-selection-target"]').forEach((btn) => {
            const mode = normalizeSelectionTarget(btn.getAttribute('data-selection-target'));
            const active = mode === target;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', String(active));
            btn.disabled = isPreviewInteractionMode();
        });
    }

    function applyLinkBlockUi() {
        if (!elements.container) {
            return;
        }
        const enabled = state.linkBlockEnabled === true;
        elements.container.setAttribute('data-link-block', enabled ? '1' : '0');
        document.querySelectorAll('[data-theme-editor-action="toggle-link-block"]').forEach((btn) => {
            btn.classList.toggle('active', enabled);
            btn.setAttribute('aria-pressed', String(enabled));
            btn.disabled = isPreviewInteractionMode();
        });
    }

    function setSelectionTarget(mode, options = {}) {
        const next = normalizeSelectionTarget(mode);
        const prev = normalizeSelectionTarget(state.selectionTarget);
        const force = options.force === true;
        if (next === prev && !force) {
            applySelectionTargetUi();
            notifyPreviewSelectionTarget(next);
            return;
        }
        state.selectionTarget = next;
        persistSelectionTarget(next);
        if (options.syncUrl !== false) {
            syncEditorUrlState({
                selection_target: next === 'default' ? null : next,
            });
        }
        applySelectionTargetUi();
        notifyPreviewSelectionTarget(next);
    }

    function setLinkBlockEnabled(enabled, options = {}) {
        const next = normalizeLinkBlockEnabled(enabled);
        const prev = state.linkBlockEnabled === true;
        const force = options.force === true;
        if (next === prev && !force) {
            applyLinkBlockUi();
            notifyPreviewLinkBlock(next);
            return;
        }
        state.linkBlockEnabled = next;
        persistLinkBlockEnabled(next);
        if (options.syncUrl !== false) {
            syncEditorUrlState({
                link_block: next ? '1' : null,
            });
        }
        applyLinkBlockUi();
        notifyPreviewLinkBlock(next);
    }

    function clearPreviewEditChrome() {
        try {
            const iframeDoc = elements.previewFrame?.contentDocument
                || elements.previewFrame?.contentWindow?.document
                || null;
            if (!iframeDoc) {
                return;
            }
            iframeDoc.querySelectorAll('.widget-wrapper.show-actions, .widget-wrapper.selected').forEach((el) => {
                el.classList.remove('show-actions', 'selected');
            });
            iframeDoc.querySelectorAll('.slot-active, [data-state="selected"]').forEach((el) => {
                el.classList.remove('slot-active');
                if (el.getAttribute('data-state') === 'selected') {
                    el.removeAttribute('data-state');
                }
            });
            iframeDoc.querySelectorAll('.slot-info-card').forEach((el) => el.remove());
        } catch (error) {
            // Cross-origin or unloaded iframe: ignore.
        }
    }

    function applyInteractionModeUi() {
        if (!elements.container) {
            return;
        }
        const preview = isPreviewInteractionMode();
        elements.container.classList.toggle('interaction-preview-mode', preview);
        elements.container.setAttribute('data-interaction-mode', state.interactionMode || 'edit');
        document.querySelectorAll('[data-theme-editor-action="set-interaction-mode"]').forEach((btn) => {
            const mode = normalizeInteractionMode(btn.getAttribute('data-interaction-mode'));
            const active = mode === normalizeInteractionMode(state.interactionMode);
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', String(active));
        });
    }

    function setInteractionMode(mode, options = {}) {
        const next = normalizeInteractionMode(mode);
        const prev = normalizeInteractionMode(state.interactionMode);
        const force = options.force === true;

        if (next === prev && !force) {
            applyInteractionModeUi();
            notifyPreviewInteractionMode(next);
            applySelectionTargetUi();
            notifyPreviewSelectionTarget(state.selectionTarget);
            applyLinkBlockUi();
            notifyPreviewLinkBlock(state.linkBlockEnabled);
            return;
        }

        if (next === 'preview') {
            cancelPreviewDragSession();
            // iframe 重载 force 重应用时不得覆盖进入预览前的面板快照
            if (prev !== 'preview' || !state.interactionModePanelsSnapshot) {
                state.interactionModePanelsSnapshot = {
                    configOpen: state.sidePanels.configOpen === true,
                    widgetOpen: state.sidePanels.widgetOpen === true,
                    slotsPanelOpen: state.slotsPanelOpen === true,
                };
            }
            setSidePanelOpen('config', false, false);
            setSidePanelOpen('widget', false, false);
            setSlotsPanelOpen(false, false);
            if (options.skipViewSwitch !== true) {
                switchPreviewView('preview');
            }
            clearPreviewEditChrome();
        } else if (prev === 'preview' && state.interactionModePanelsSnapshot && options.restorePanels !== false) {
            const snap = state.interactionModePanelsSnapshot;
            state.interactionModePanelsSnapshot = null;
            setSidePanelOpen('config', snap.configOpen === true, false);
            setSidePanelOpen('widget', snap.widgetOpen === true, false);
            setSlotsPanelOpen(snap.slotsPanelOpen === true, false);
        }

        state.interactionMode = next;
        persistInteractionMode(next);
        if (options.syncUrl !== false) {
            syncEditorUrlState({
                interaction_mode: next,
            });
        }
        applyInteractionModeUi();
        applySelectionTargetUi();
        applyLinkBlockUi();
        notifyPreviewInteractionMode(next);
        notifyPreviewSelectionTarget(state.selectionTarget);
        notifyPreviewLinkBlock(state.linkBlockEnabled);
    }

    function openWidgetPanelForSlotSelection(slot) {
        if (isPreviewInteractionMode()) {
            setInteractionMode('edit');
        }
        setSidePanelOpen('widget', true);

        loadWidgetListIfEmpty().then(function() {
            const currentSlotId = state.selectedSlot?.id || '';
            if (slot && currentSlotId === (slot.id || '')) {
                applySlotWidgetRecommendations(slot);
                scrollToHighlightedWidgets();
            }
        }).catch(function(err) {
            console.warn('[ThemeEditor] load widget library for slot selection failed:', err);
        });
    }

    function openConfigPanelForWidgetSelection() {
        if (isPreviewInteractionMode()) {
            setInteractionMode('edit');
        }
        setSidePanelOpen('config', true);
    }

    function getEditorFullscreenElement() {
        return document.fullscreenElement
            || document.webkitFullscreenElement
            || document.mozFullScreenElement
            || document.msFullscreenElement
            || null;
    }

    function isEditorFullscreen() {
        return state.editorFullscreenFallback === true || getEditorFullscreenElement() === elements.container;
    }

    function setFullscreenButtonState(active) {
        if (!elements.btnFullscreenPreview) {
            return;
        }

        elements.btnFullscreenPreview.classList.toggle('active', active);
        elements.btnFullscreenPreview.title = active ? translateUiText('退出全屏') : translateUiText('全屏预览');
        elements.btnFullscreenPreview.setAttribute('aria-pressed', active ? 'true' : 'false');

        const icon = elements.btnFullscreenPreview.querySelector('.w-icon');
        if (icon) {
            icon.replaceWith(getEditorUi().icon.create(active ? 'fullscreen-exit' : 'fullscreen', { size: 'sm' }));
        }

        Array.from(elements.btnFullscreenPreview.childNodes)
            .filter(node => node.nodeType === Node.TEXT_NODE)
            .forEach(node => node.remove());
        elements.btnFullscreenPreview.appendChild(document.createTextNode(' ' + (active ? translateUiText('退出全屏') : translateUiText('全屏'))));
    }

    function enterEditorFullscreenUi(fallback = false) {
        state.editorFullscreenFallback = fallback === true;
        if (elements.container) {
            elements.container.classList.add('editor-fullscreen-mode');
            elements.container.classList.toggle('editor-fullscreen-fallback', state.editorFullscreenFallback === true);
        }
        setFullscreenButtonState(true);

        if (!state.fullscreenSidePanelsSnapshot) {
            state.fullscreenSidePanelsSnapshot = {
                configOpen: state.sidePanels.configOpen === true,
                widgetOpen: state.sidePanels.widgetOpen === true,
            };
        }
        state.sidePanels.configOpen = true;
        state.sidePanels.widgetOpen = true;
        applySidePanelState();
    }

    function exitEditorFullscreenUi() {
        state.editorFullscreenFallback = false;
        if (elements.container) {
            elements.container.classList.remove('editor-fullscreen-mode', 'editor-fullscreen-fallback');
        }
        setFullscreenButtonState(false);

        if (state.fullscreenSidePanelsSnapshot) {
            state.sidePanels.configOpen = state.fullscreenSidePanelsSnapshot.configOpen;
            state.sidePanels.widgetOpen = state.fullscreenSidePanelsSnapshot.widgetOpen;
            state.fullscreenSidePanelsSnapshot = null;
        }
        applySidePanelState();
    }

    function handleEditorFullscreenChanged() {
        if (getEditorFullscreenElement() === elements.container) {
            enterEditorFullscreenUi(false);
            return;
        }

        if (state.editorFullscreenFallback !== true) {
            exitEditorFullscreenUi();
        }
    }

    async function toggleEditorFullscreen() {
        if (!elements.container) {
            return;
        }

        if (state.editorFullscreenFallback === true) {
            exitEditorFullscreenUi();
            return;
        }

        if (getEditorFullscreenElement() === elements.container) {
            const exitFullscreen = document.exitFullscreen
                || document.webkitExitFullscreen
                || document.mozCancelFullScreen
                || document.msExitFullscreen;
            if (exitFullscreen) {
                await exitFullscreen.call(document);
            }
            return;
        }

        const requestFullscreen = elements.container.requestFullscreen
            || elements.container.webkitRequestFullscreen
            || elements.container.mozRequestFullScreen
            || elements.container.msRequestFullscreen;
        if (!requestFullscreen || document.fullscreenEnabled === false) {
            enterEditorFullscreenUi(true);
            return;
        }

        try {
            await requestFullscreen.call(elements.container);
        } catch (error) {
            console.warn('[ThemeEditor] native fullscreen unavailable, fallback to editor fullscreen:', error);
            enterEditorFullscreenUi(true);
        }
    }

    function getEditorUi() {
        const ui = window.Weline?.UI;
        if (!ui) {
            throw new Error('Weline.UI must be loaded before Theme Editor.');
        }
        return ui;
    }

    function showEditorModal(modal) {
        if (!modal) {
            return false;
        }
        return getEditorUi().dialog.open(modal);
    }

    function hideEditorModal(modal) {
        if (!modal) {
            return false;
        }
        return getEditorUi().dialog.close(modal, 'editor-action');
    }

    /** 主题编辑器只从 Weline SVG 注册表取图标，不维护第二份图形数据。 */
    const EDITOR_ICON_NAMES = Object.freeze({
        add: "plus",
        apps: "grid",
        arrowDown: "chevron-down",
        delete: "trash",
        drag: "drag",
        global: "globe",
        layoutGrid: "grid",
        loader: "spinner",
    });

    const WIDGET_TYPE_ICON_NAMES = Object.freeze({
        banner: 'image',
        breadcrumb: 'branch',
        carousel: 'slideshow',
        category: 'folder',
        container: 'grid',
        content: 'file',
        faq: 'help',
        footer: 'layout-footer',
        header: 'layout-header',
        navigation: 'menu',
        newsletter: 'mail',
        pagination: 'more-horizontal',
        product: 'box',
        search: 'search',
        sidebar: 'layout-sidebar',
        slider: 'slideshow',
        social: 'share',
        testimonial: 'quote',
        video: 'play',
    });
    const EDITOR_PICKER_ICON_NAMES = Object.freeze([
        'home', 'user', 'settings', 'search', 'menu', 'close',
        'arrow-left', 'arrow-right', 'arrow-up', 'arrow-down',
        'check', 'plus', 'minus', 'edit', 'trash', 'eye', 'eye-off',
        'heart', 'star', 'pin', 'mail', 'phone', 'calendar', 'clock',
        'bell', 'share', 'link', 'image', 'file', 'folder', 'download',
        'upload', 'refresh', 'info', 'help', 'warning', 'circle',
    ]);

    function normalizeSemanticIconName(value) {
        const name = String(value || '').trim().toLowerCase();
        return /^[a-z][a-z0-9-]{0,63}$/.test(name) && !/^(?:mdi|fa[brs]?|ri)-/.test(name)
            ? name
            : '';
    }

    function iconSvg(name) {
        const semanticName = EDITOR_ICON_NAMES[name] || name;
        const icon = getEditorUi().icon.create(semanticName, { size: "sm" });
        return `<span class="w-theme-editor-icon" data-icon="${semanticName}">${icon.outerHTML}</span>`;
    }

    function widgetTypeIconName(type) {
        return WIDGET_TYPE_ICON_NAMES[String(type || '').toLowerCase()] || 'widgets';
    }

    function getCurrentPageType() {
        return state.pageType || state.layoutType || 'homepage';
    }

    function normalizeLayoutOptionValue(value) {
        return String(value || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '').trim();
    }

    function normalizeLayoutOptionsByType(rawOptions) {
        const result = {};
        if (!rawOptions || typeof rawOptions !== 'object') {
            return result;
        }

        Object.entries(rawOptions).forEach(([layoutType, options]) => {
            const type = normalizeLayoutOptionValue(layoutType);
            if (!type || !Array.isArray(options)) {
                return;
            }
            result[type] = options
                .map((option) => {
                    if (typeof option === 'string') {
                        return {
                            value: normalizeLayoutOptionValue(option),
                            label: option,
                            description: '',
                            file: ''
                        };
                    }
                    if (!option || typeof option !== 'object') {
                        return null;
                    }
                    const value = normalizeLayoutOptionValue(option.value);
                    if (!value) {
                        return null;
                    }
                    return {
                        value,
                        label: String(option.label || option.name || value),
                        description: String(option.description || ''),
                        file: String(option.file || '')
                    };
                })
                .filter(Boolean);
        });

        return result;
    }

    function parseLayoutOptionsByType(value) {
        if (!value) {
            return {};
        }
        if (typeof value === 'object') {
            return normalizeLayoutOptionsByType(value);
        }
        try {
            return normalizeLayoutOptionsByType(JSON.parse(String(value)));
        } catch (error) {
            console.warn('[ThemeEditor] Invalid layout options payload:', error);
            return {};
        }
    }

    function parseLayoutLock(value) {
        if (!value) {
            return { enabled: false };
        }
        if (typeof value === 'object') {
            return value && value.enabled ? value : { enabled: false };
        }
        try {
            const parsed = JSON.parse(String(value));
            return parsed && parsed.enabled ? parsed : { enabled: false };
        } catch (error) {
            console.warn('[ThemeEditor] Invalid layout lock payload:', error);
            return { enabled: false };
        }
    }

    function parseLayoutIdentityDataset(dataset) {
        if (!dataset) {
            return {};
        }

        const scope = String(dataset.scope || '').trim();
        const localeCode = String(dataset.localeCode || dataset.configLocale || '').trim();
        const targetType = String(
            dataset.themeLayoutTargetType
            || dataset.themeLayoutSourceTargetType
            || dataset.targetType
            || ''
        ).trim();
        const targetIdRaw = dataset.themeLayoutTargetId
            || dataset.themeLayoutSourceTargetId
            || dataset.targetId
            || '';
        const targetId = parseInt(targetIdRaw || 0, 10) || 0;
        if (scope === '' && localeCode === '' && targetType === '' && targetId <= 0) {
            return {};
        }

        const payload = {};
        if (scope !== '') {
            payload.scope = scope;
        }
        if (localeCode !== '') {
            payload.locale = localeCode;
            payload.locale_code = localeCode;
        }
        if (targetType !== '') {
            payload.target_type = targetType;
            payload.theme_layout_target_type = targetType;
            payload.theme_layout_source_target_type = targetType;
        }
        if (targetId > 0 || targetType !== '') {
            payload.target_id = targetId;
            payload.theme_layout_target_id = targetId;
            payload.theme_layout_source_target_id = targetId;
        }

        return payload;
    }

    function isLayoutLocked() {
        return Boolean(state.layoutLock && state.layoutLock.enabled);
    }

    function getUrlLayoutIdentityPayload() {
        const scope = String(getCurrentWindowParam('scope') || '').trim();
        const localeCode = String(getCurrentWindowParam('locale_code') || getCurrentWindowParam('locale') || '').trim();
        const targetType = String(
            getCurrentWindowParam('theme_layout_target_type')
            || getCurrentWindowParam('theme_layout_source_target_type')
            || getCurrentWindowParam('target_type')
            || ''
        ).trim();
        const targetIdRaw = getCurrentWindowParam('theme_layout_target_id')
            || getCurrentWindowParam('theme_layout_source_target_id')
            || getCurrentWindowParam('target_id')
            || '';
        const targetId = parseInt(targetIdRaw || 0, 10) || 0;
        const sourceTargetType = String(
            getCurrentWindowParam('theme_layout_source_target_type')
            || targetType
            || ''
        ).trim();
        const sourceTargetIdRaw = getCurrentWindowParam('theme_layout_source_target_id')
            || (targetType !== '' ? String(targetId) : '');
        const sourceTargetId = parseInt(sourceTargetIdRaw || 0, 10) || 0;
        const hasIdentity = scope !== ''
            || localeCode !== ''
            || targetType !== ''
            || targetId > 0
            || sourceTargetType !== ''
            || sourceTargetId > 0;

        if (!hasIdentity) {
            return {};
        }

        const payload = {};
        if (scope !== '') {
            payload.scope = scope;
        }
        if (localeCode !== '') {
            payload.locale = localeCode;
            payload.locale_code = localeCode;
        }
        if (targetType !== '') {
            payload.target_type = targetType;
            payload.theme_layout_target_type = targetType;
        }
        if (targetId > 0 || targetType !== '') {
            payload.target_id = targetId;
            payload.theme_layout_target_id = targetId;
        }
        if (sourceTargetType !== '') {
            payload.theme_layout_source_target_type = sourceTargetType;
        }
        if (sourceTargetId > 0 || sourceTargetType !== '') {
            payload.theme_layout_source_target_id = sourceTargetId;
        }

        return payload;
    }

    function getLayoutLockVirtualPayload() {
        if (!isLayoutLocked()) {
            return {
                ...(state.layoutIdentity || {}),
                ...getUrlLayoutIdentityPayload(),
            };
        }

        const cmsLock = String(state.layoutLock.lock_source || state.layoutLock.source || '').toLowerCase() === 'cms';
        return {
            scope: cmsLock ? (getCurrentWindowParam('scope') || state.layoutLock.scope || 'default') : (state.layoutLock.scope || 'default'),
            locale: getLegacyLocaleCode(),
            locale_code: getLegacyLocaleCode(),
            store_mode: getCurrentWindowParam('store_mode') || state.layoutLock.store_mode || 'normal',
            website_id: parseInt(getCurrentWindowParam('website_id') || state.layoutLock.website_id || 0, 10) || 0,
            website_code: getCurrentWindowParam('website_code') || state.layoutLock.website_code || '',
            store_id: parseInt(getCurrentWindowParam('store_id') || state.layoutLock.store_id || 0, 10) || 0,
            store_code: getCurrentWindowParam('store_code') || state.layoutLock.store_code || '',
            target_type: state.layoutLock.target_type || 'global',
            target_id: parseInt(state.layoutLock.target_id || 0, 10) || 0,
        };
    }

    function getEffectiveLayoutType(fallback = 'homepage') {
        const lockedType = isLayoutLocked()
            ? normalizeLayoutOptionValue(state.layoutLock?.page_type || state.layoutLock?.layout_type || '')
            : '';
        return lockedType
            || normalizeLayoutOptionValue(state.layoutType || getCurrentPageType() || fallback)
            || fallback;
    }

    function getEffectivePageType(fallback = 'homepage') {
        const lockedType = isLayoutLocked()
            ? normalizeLayoutOptionValue(state.layoutLock?.page_type || state.layoutLock?.layout_type || '')
            : '';
        return lockedType
            || normalizeLayoutOptionValue(state.pageType || getEffectiveLayoutType(fallback))
            || fallback;
    }

    function getEffectiveLayoutOption(fallback = 'default') {
        const lockedOption = isLayoutLocked()
            ? normalizeLayoutOptionValue(state.layoutLock?.layout_option || '')
            : '';
        return lockedOption
            || normalizeLayoutOptionValue(state.layoutOption || fallback)
            || fallback;
    }

    function getEffectiveEditorArea(fallback = 'frontend') {
        const lockedArea = isLayoutLocked() ? String(state.layoutLock?.area || '') : '';
        const area = lockedArea || state.editorArea || fallback;
        return area === 'backend' ? 'backend' : 'frontend';
    }

    function appendLayoutLockRuntimeParams(url) {
        if (!isLayoutLocked() || !url || !url.searchParams) {
            return;
        }
        const payload = getLayoutLockVirtualPayload();
        if (payload.scope) {
            url.searchParams.set('scope', String(payload.scope));
        }
        if (payload.locale_code) {
            url.searchParams.set('locale', String(payload.locale_code));
            url.searchParams.set('locale_code', String(payload.locale_code));
        }
        if (payload.store_mode) {
            url.searchParams.set('store_mode', String(payload.store_mode));
        }
        ['website_id', 'website_code', 'store_id', 'store_code'].forEach((key) => {
            if (payload[key] !== null && payload[key] !== undefined && payload[key] !== '') {
                url.searchParams.set(key, String(payload[key]));
            }
        });
        const targetType = String(payload.target_type || '');
        const targetId = parseInt(payload.target_id || 0, 10) || 0;
        if (targetType && targetType !== 'global') {
            url.searchParams.set('theme_layout_target_type', targetType);
            url.searchParams.set('theme_layout_target_id', String(targetId));
            url.searchParams.set('theme_layout_source_target_type', targetType);
            url.searchParams.set('theme_layout_source_target_id', String(targetId));
        }
    }

    function appendThemeLayoutRuntimeParams(url, overrides = {}) {
        if (!url || !url.searchParams) {
            return;
        }

        appendLayoutLockRuntimeParams(url);

        const currentUrl = getCurrentWindowUrl();
        [
            'scope',
            'locale',
            'locale_code',
            'store_mode',
            'website_id',
            'website_code',
            'store_id',
            'store_code',
            'target_type',
            'target_id',
            'theme_layout_target_type',
            'theme_layout_target_id',
            'theme_layout_source_target_type',
            'theme_layout_source_target_id',
        ].forEach((key) => {
            if (url.searchParams.has(key)) {
                return;
            }
            const value = Object.prototype.hasOwnProperty.call(overrides, key)
                ? overrides[key]
                : currentUrl.searchParams.get(key);
            if (value !== null && value !== undefined && value !== '') {
                url.searchParams.set(key, String(value));
            }
        });
    }

    function buildLayoutLockVirtualIdentityPayload(extra = {}) {
        const layoutType = getEffectiveLayoutType();
        const layoutOption = getEffectiveLayoutOption();
        return {
            theme_id: state.themeId || 0,
            area: getEffectiveEditorArea(),
            page_type: layoutType,
            layout_type: layoutType,
            layout_option: layoutOption,
            ...getLayoutLockVirtualPayload(),
            ...extra,
        };
    }

    function buildLayoutVersionIdentityPayload(extra = {}) {
        const layoutType = getEffectiveLayoutType();
        const layoutOption = getEffectiveLayoutOption();
        const identityPayload = getLayoutLockVirtualPayload();
        const editorContext = buildTypedEditorContext('layout');
        const editorLocale = editorContext.locale === 'default' ? '' : editorContext.locale;
        const scope = identityPayload.scope || 'default';
        const targetType = identityPayload.target_type || 'global';
        const targetId = parseInt(identityPayload.target_id || 0, 10) || 0;
        const payload = {
            theme_id: state.themeId || 0,
            page_type: layoutType,
            layout_type: layoutType,
            layout_option: layoutOption,
            scope,
            locale: editorLocale,
            locale_code: editorLocale,
            store_mode: identityPayload.store_mode || getCurrentWindowParam('store_mode') || 'normal',
            target_type: targetType,
            target_id: targetId,
            editor_context: editorContext,
        };
        if (targetType && targetType !== 'global') {
            payload.theme_layout_target_type = targetType;
            payload.theme_layout_target_id = targetId;
            payload.theme_layout_source_target_type = targetType;
            payload.theme_layout_source_target_id = targetId;
        }
        return {
            ...payload,
            ...extra,
        };
    }

    function buildCmsEditorLockPayload({
        locale,
        layoutOption,
        pageId,
        scope,
        scopeIdentity,
        storeMode,
    }) {
        const layoutType = getEffectiveLayoutType();
        const targetType = 'cms_page';
        const targetId = parseInt(pageId || 0, 10) || 0;
        const normalizedLocale = String(locale || '').trim();
        const normalizedLayoutOption = String(layoutOption || '').trim();
        return {
            theme_id: state.themeId || 0,
            page_type: layoutType,
            layout_type: layoutType,
            layout_option: normalizedLayoutOption,
            scope: String(scope || '').trim(),
            locale: normalizedLocale,
            locale_code: normalizedLocale,
            store_mode: String(storeMode || 'normal').trim().toLowerCase(),
            target_type: targetType,
            target_id: targetId,
            theme_layout_target_type: targetType,
            theme_layout_target_id: targetId,
            theme_layout_source_target_type: targetType,
            theme_layout_source_target_id: targetId,
            editor_context: {
                scope: { identity: { ...(scopeIdentity || {}) } },
                area: getEffectiveEditorArea(),
                resource_type: 'layout',
                theme_id: state.themeId || 0,
                layout_type: layoutType,
                layout_option: normalizedLayoutOption,
                locale: normalizedLocale,
                target_type: targetType,
                target_id: targetId,
            },
        };
    }

    function editorLockIdentityKey(payload) {
        const context = payload?.editor_context || {};
        const identity = context?.scope?.identity || {};
        return JSON.stringify([
            parseInt(payload?.theme_id || context.theme_id || 0, 10) || 0,
            String(payload?.page_type || context.layout_type || ''),
            String(context.area || ''),
            String(context.resource_type || 'layout'),
            String(context.layout_option || payload?.layout_option || ''),
            String(context.locale || payload?.locale || ''),
            String(context.target_type || payload?.target_type || ''),
            parseInt(context.target_id ?? payload?.target_id ?? 0, 10) || 0,
            String(identity.scope_kind || ''),
            Number.parseInt(identity.website_id, 10),
            String(identity.website_code || ''),
            String(identity.store_code || ''),
            String(identity.channel_code || ''),
            String(identity.store_mode || payload?.store_mode || 'normal'),
            String(identity.context_version || ''),
        ]);
    }

    async function saveLockedVirtualLayoutDraft() {
        if (!config.apiVirtualThemeCreateDraft) {
            throw new Error('Virtual layout draft endpoint is unavailable');
        }
        const payload = buildLayoutLockVirtualIdentityPayload({
            use_ai: 0,
            request_id: `virtual-layout-save-${Date.now()}`,
            instructions: 'Save the current locked virtual layout as a draft version without AI changes.',
        });
        const result = await apiJson(config.apiVirtualThemeCreateDraft, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!result.success) {
            throw new Error(result.message || 'Save virtual layout draft failed');
        }
        const data = result.data || {};
        if (data.layout_option) {
            await refreshLayoutOptions({
                layout_type: payload.layout_type,
                layout_option: data.layout_option,
                silent: true,
            });
        }
        return data;
    }

    async function loadLockedVirtualLayoutSource() {
        if (!config.apiVirtualThemeSource) {
            throw new Error('Virtual layout source endpoint is unavailable');
        }
        const url = new URL(config.apiVirtualThemeSource, window.location.origin);
        Object.entries(buildLayoutLockVirtualIdentityPayload()).forEach(([key, value]) => {
            if (value !== undefined && value !== null && String(value) !== '') {
                url.searchParams.set(key, String(value));
            }
        });
        url.searchParams.set('_t', String(Date.now()));
        const result = await apiJson(url.toString(), { method: 'GET' });
        if (!result.success) {
            throw new Error(result.message || 'Load virtual layout source failed');
        }
        return result.data || {};
    }

    async function publishLatestLockedVirtualLayoutVersion() {
        if (!config.apiVirtualThemePublishVersion) {
            throw new Error('Virtual layout publish endpoint is unavailable');
        }
        const source = await loadLockedVirtualLayoutSource();
        const versionId = parseInt(source.version_id || source.draft_version_id || 0, 10) || 0;
        if (versionId <= 0) {
            throw new Error('No virtual layout draft version is available to publish');
        }
        const result = await apiJson(config.apiVirtualThemePublishVersion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(buildLayoutLockVirtualIdentityPayload({ version_id: versionId })),
        });
        if (!result.success) {
            throw new Error(result.message || 'Publish virtual layout version failed');
        }
        return result.data || {};
    }

    function ensureSelectOption(select, value, label = '') {
        if (!select || !value) {
            return;
        }
        const normalized = normalizeLayoutOptionValue(value);
        if (!normalized) {
            return;
        }
        const exists = Array.from(select.options || []).some(option => normalizeLayoutOptionValue(option.value) === normalized);
        if (exists) {
            return;
        }
        const option = document.createElement('option');
        option.value = normalized;
        option.textContent = label || normalized;
        select.appendChild(option);
    }

    function enforceLayoutLock() {
        if (!isLayoutLocked()) {
            return;
        }
        const lock = state.layoutLock || {};
        if (lock.page_type || lock.layout_type) {
            const lockedType = normalizeLayoutOptionValue(lock.page_type || lock.layout_type) || state.pageType;
            state.pageType = lockedType;
            state.layoutType = lockedType;
            if (elements.pageTypeSelect) {
                elements.pageTypeSelect.value = lockedType;
            }
        }
        if (lock.layout_option) {
            state.layoutOption = normalizeLayoutOptionValue(lock.layout_option) || state.layoutOption || 'default';
            if (elements.layoutOptionSelect) {
                ensureSelectOption(elements.layoutOptionSelect, state.layoutOption, state.layoutOption);
                elements.layoutOptionSelect.value = state.layoutOption;
            }
        }
        if (lock.area) {
            state.editorArea = lock.area === 'backend' ? 'backend' : 'frontend';
            if (elements.editorAreaSelect) {
                elements.editorAreaSelect.value = state.editorArea;
            }
        }
        const lockedControls = [
            elements.themeSelect,
            elements.scopeSelect,
            elements.pageTypeSelect,
            elements.layoutOptionSelect,
            elements.editorAreaSelect,
        ];
        lockedControls.forEach((select) => {
            if (select) {
                select.disabled = true;
                select.dataset.layoutLocked = '1';
            }
        });
        window.WelineScopeSelect?.scopeSelect?.setDisabled(true);
        if (elements.btnSave) {
            elements.btnSave.disabled = false;
            elements.btnSave.title = translateUiText('Save locked virtual layout draft');
        }
        if (elements.btnPublish) {
            elements.btnPublish.disabled = false;
            elements.btnPublish.title = translateUiText('Publish locked virtual layout version');
        }
    }

    function isThemeMutationBlockedByLayoutLock(url, method) {
        if (!isLayoutLocked() || String(method || 'GET').toUpperCase() === 'GET') {
            return false;
        }
        const path = String(url || '');
        if (path.includes('/virtual-theme/')) {
            return false;
        }
        if ([
            '/save-widget',
            '/update-config',
            '/delete-widget',
            '/swap-widget-order',
            '/update-sort',
            '/save-widget-config',
            '/publish',
        ].some((endpoint) => path.includes(endpoint))) {
            return false;
        }

        return [
            '/save-layout-selection',
            '/save-layout-config',
            '/save-compiled-layout',
            '/publish-and-exit',
        ].some((endpoint) => path.includes(endpoint));
    }

    function getLayoutOptionsForType(layoutType) {
        const type = normalizeLayoutOptionValue(layoutType || getCurrentPageType() || 'homepage');
        return Array.isArray(state.layoutOptionsByType[type]) ? state.layoutOptionsByType[type] : [];
    }

    function getFallbackLayoutOption(layoutType) {
        const options = getLayoutOptionsForType(layoutType);
        const defaultOption = options.find(option => option.value === 'default');
        return (defaultOption || options[0] || { value: 'default' }).value || 'default';
    }

    function resolveLayoutOptionForType(layoutType, requestedOption = '') {
        const requested = normalizeLayoutOptionValue(requestedOption);
        const options = getLayoutOptionsForType(layoutType);
        if (isLayoutLocked() && requested) {
            return requested;
        }
        if (requested && options.some(option => option.value === requested)) {
            return requested;
        }
        return getFallbackLayoutOption(layoutType);
    }

    function renderLayoutOptionSelect(layoutType = state.layoutType, selectedOption = state.layoutOption) {
        if (!elements.layoutOptionSelect) {
            return;
        }

        const options = getLayoutOptionsForType(layoutType);
        const selected = resolveLayoutOptionForType(layoutType, selectedOption);
        const renderOptions = options.length > 0 ? options.slice() : [{
            value: selected || 'default',
            label: selected === 'default' ? 'Default' : selected,
            description: '',
            file: ''
        }];
        if (isLayoutLocked() && selected && !renderOptions.some(option => normalizeLayoutOptionValue(option.value) === selected)) {
            renderOptions.push({
                value: selected,
                label: selected,
                description: translateUiText('Virtual layout locked option'),
                file: ''
            });
        }

        elements.layoutOptionSelect.innerHTML = renderOptions.map(option => {
            const value = normalizeLayoutOptionValue(option.value);
            const label = option.label || value;
            const description = option.description ? ` title="${escapeHtml(option.description)}"` : '';
            return `<option value="${escapeHtml(value)}"${value === selected ? ' selected' : ''}${description}>${escapeHtml(label)}</option>`;
        }).join('');
        elements.layoutOptionSelect.value = selected;
        elements.layoutOptionSelect.disabled = isLayoutLocked();
        elements.layoutOptionSelect.dataset.singleOption = renderOptions.length <= 1 ? '1' : '0';
        elements.layoutOptionSelect.title = renderOptions.length <= 1
            ? translateUiText('当前布局类型只有一个布局选项')
            : translateUiText('选择布局选项');
        enforceLayoutLock();
    }

    function setCurrentLayoutSelection(layoutType, layoutOption = '') {
        const nextType = normalizeLayoutOptionValue(layoutType || getCurrentPageType() || 'homepage') || 'homepage';
        state.pageType = nextType;
        state.layoutType = nextType;
        state.layoutOption = resolveLayoutOptionForType(nextType, layoutOption || state.layoutOption);
        if (elements.pageTypeSelect && elements.pageTypeSelect.value !== nextType) {
            elements.pageTypeSelect.value = nextType;
        }
        renderLayoutOptionSelect(nextType, state.layoutOption);
    }

    function getCurrentWindowUrl() {
        return new URL(window.location.href);
    }

    function getCurrentWindowParam(key) {
        return getCurrentWindowUrl().searchParams.get(key) || '';
    }

    function appendWidgetLibraryFilterParams(url) {
        const currentUrl = getCurrentWindowUrl();
        WIDGET_LIBRARY_FILTER_PARAM_KEYS.forEach(function (key) {
            const value = currentUrl.searchParams.get(key);
            if (value !== null && value !== '') {
                url.searchParams.set(key, value);
            }
        });
    }

    function normalizeRequestHeaders(headers) {
        const result = {};
        if (!headers || typeof headers !== 'object') {
            return result;
        }
        if (typeof headers.forEach === 'function') {
            headers.forEach((value, name) => {
                if (name) {
                    result[String(name)] = String(value);
                }
            });
            return result;
        }
        Object.keys(headers).forEach((name) => {
            const value = headers[name];
            if (value !== undefined && value !== null) {
                result[String(name)] = String(value);
            }
        });
        return result;
    }

    function resolveSameOriginEditorUrl(url) {
        try {
            const resolved = new URL(String(url), window.location.href);
            return resolved.origin === window.location.origin ? resolved.toString() : '';
        } catch (error) {
            return '';
        }
    }

    function hasValidTypedEditorContextParam(url) {
        const encoded = url?.searchParams?.get('editor_context') || '';
        if (!encoded) {
            return false;
        }
        try {
            const parsed = JSON.parse(encoded);
            return Boolean(
                parsed
                && typeof parsed === 'object'
                && !Array.isArray(parsed)
                && parsed.scope
                && typeof parsed.scope === 'object'
            );
        } catch (error) {
            return false;
        }
    }

    let themeEditorResourcePromise = null;

    function resolveThemeEditorApiHost() {
        if (window.parent && window.parent !== window) {
            try {
                if (
                    window.parent.location.origin === window.location.origin
                    && window.parent.Weline
                    && (typeof window.parent.Weline.load === 'function' || window.parent.Weline.Api)
                ) {
                    return window.parent;
                }
            } catch (error) {
                // Cross-origin parents must not supply an authenticated backend API.
            }
        }

        return window;
    }

    function resolveThemeEditorResource() {
        if (themeEditorResourcePromise) {
            return themeEditorResourcePromise;
        }
        const apiHost = resolveThemeEditorApiHost();
        const apiPromise = typeof apiHost.Weline.load === 'function'
            ? apiHost.Weline.load('api')
            : Promise.resolve(apiHost.Weline.Api);
        themeEditorResourcePromise = Promise.resolve(apiPromise)
            .then((api) => {
                if (!api || typeof api.resource !== 'function') {
                    throw new Error('Weline.Api.resource is unavailable.');
                }
                return api.resource('theme');
            })
            .then((resource) => {
                if (!resource || typeof resource.editorRequest !== 'function') {
                    throw new Error('Weline theme editor API is unavailable.');
                }
                return resource;
            })
            .catch((error) => {
                themeEditorResourcePromise = null;
                throw error;
            });
        return themeEditorResourcePromise;
    }

    async function apiRequest(url, options = {}) {
        const headers = normalizeRequestHeaders(options.headers);
        let body = options.body ?? null;
        if (body instanceof URLSearchParams) {
            body = body.toString();
        } else if (body !== null && typeof body !== 'string') {
            body = JSON.stringify(body);
        }
        let requestUrl = resolveSameOriginEditorUrl(url);
        if (!requestUrl) {
            throw new Error('Theme Editor only accepts same-origin API URLs.');
        }
        const method = String(options.method || 'GET').toUpperCase();
        if (state.scopeIdentity) {
            const defaultContext = buildTypedEditorContext('layout');
            headers['X-Weline-Editor-Context'] = JSON.stringify(defaultContext);
            const resolvedUrl = new URL(requestUrl);
            if ((method === 'GET' || method === 'HEAD') && !hasValidTypedEditorContextParam(resolvedUrl)) {
                resolvedUrl.searchParams.set('editor_context', JSON.stringify(defaultContext));
                requestUrl = resolvedUrl.toString();
            } else if (method !== 'GET' && method !== 'HEAD' && typeof body === 'string') {
                let attached = false;
                const trimmedBody = body.trim();
                if (trimmedBody.startsWith('{')) {
                    try {
                        const payload = JSON.parse(body);
                        if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
                            if (!Object.prototype.hasOwnProperty.call(payload, 'editor_context')) {
                                payload.editor_context = defaultContext;
                            }
                            body = JSON.stringify(payload);
                            attached = true;
                        }
                    } catch (error) {
                    }
                }
                if (!attached && /(?:^|&)\w+=/.test(body)) {
                    const params = new URLSearchParams(body);
                    if (!params.has('editor_context')) {
                        params.set('editor_context', JSON.stringify(defaultContext));
                    }
                    body = params.toString();
                }
            }
        }
        const params = {
            url: requestUrl,
            method,
        };
        if (isThemeMutationBlockedByLayoutLock(params.url, params.method)) {
            throw new Error('Virtual layout lock mode only allows virtual layout draft actions');
        }
        if (Object.keys(headers).length > 0) {
            params.headers = headers;
        }
        if (body !== null) {
            params.body = body;
        }
        const resource = await resolveThemeEditorResource();
        return resource.editorRequest(params);
    }

    async function unwrapApiPayload(response) {
        if (response && typeof response.json === 'function') {
            return response.json();
        }
        if (response && typeof response.text === 'function') {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                return text;
            }
        }
        if (response && Object.prototype.hasOwnProperty.call(response, 'data') && !Object.prototype.hasOwnProperty.call(response, 'success')) {
            return response.data;
        }
        return response;
    }

    function describeNonJsonApiResponse(text, response = null) {
        const status = response && typeof response.status === 'number' ? response.status : 0;
        const normalized = String(text || '').replace(/\s+/g, ' ').trim();
        if (/data-login-form|管理员登录|admin\/login|login-form/i.test(normalized)) {
            return '登录状态已失效，请刷新后台后重新登录';
        }
        if (status >= 400) {
            return `接口请求失败（HTTP ${status}）`;
        }
        return '接口没有返回 JSON，请刷新页面后重试';
    }

    async function apiJson(url, options = {}) {
        const response = await apiRequest(url, options);
        if (response && typeof response.text === 'function') {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(describeNonJsonApiResponse(text, response));
            }
        }
        const result = await unwrapApiPayload(response);
        if (typeof result === 'string') {
            try {
                return JSON.parse(result);
            } catch (e) {
                throw new Error(describeNonJsonApiResponse(result));
            }
        }
        return result || {};
    }

    async function apiText(url, options = {}) {
        const result = await unwrapApiPayload(await apiRequest(url, options));
        if (typeof result === 'string') {
            return result;
        }
        return result && typeof result.html === 'string' ? result.html : JSON.stringify(result || {});
    }

    function storageScopeForIdentity(identity) {
        if (!identity || identity.scope_kind === 'global') return 'default.default.default';
        const website = String(identity.website_code || '').toLowerCase();
        if (identity.scope_kind === 'website') {
            return website === 'default' ? 'default.__website__.default' : website + '.default.default';
        }
        const store = String(identity.store_code || '').toLowerCase() === 'default'
            ? '__store__'
            : String(identity.store_code || '').toLowerCase();
        if (identity.scope_kind === 'store') return website + '.' + store + '.default';
        const channel = String(identity.channel_code || '').toLowerCase() === 'default'
            ? '__channel__'
            : String(identity.channel_code || '').toLowerCase();
        return website + '.' + store + '.' + channel;
    }

    function legacyStorageScopeForIdentity(identity) {
        const scope = storageScopeForIdentity(identity);
        const mode = String(identity?.store_mode || 'normal').toLowerCase();
        const ownsMode = identity?.scope_kind === 'store' || identity?.scope_kind === 'channel';
        return ownsMode && mode !== 'normal' ? scope + '~' + mode : scope;
    }

    function restoreScopeSelector(scope) {
        window.WelineScopeSelect?.scopeSelect?.setValue(String(scope || ''), false);
    }

    async function switchScope(nextScope) {
        nextScope = String(nextScope || '').trim();
        const currentScope = String(
            state.layoutIdentity?.scope || storageScopeForIdentity(state.scopeIdentity)
        ).trim();
        if (!nextScope || nextScope === currentScope) {
            restoreScopeSelector(currentScope);
            return;
        }
        if (state.saveInProgress) {
            restoreScopeSelector(currentScope);
            showToast(translateUiText('当前修改仍在保存，请稍后再切换 Scope'), 'warning');
            return;
        }
        try {
            await flushPendingEditorMutations();
        } catch (error) {
            restoreScopeSelector(currentScope);
            showToast(error?.message || translateUiText('Scope 修改保存失败，已停留在当前 Scope'), 'error');
            return;
        }
        if (state.lockHeld && !(await releaseCurrentEditorLock())) {
            restoreScopeSelector(currentScope);
            showToast(translateUiText('旧 Scope 编辑锁释放失败，已停留在当前 Scope'), 'error');
            return;
        }
        showPreviewLoadingImmediate();
        navigateEditorShell({
            scope: nextScope,
            scope_kind: null,
            website_id: null,
            website_code: null,
            store_code: null,
            channel_code: null,
            store_mode: null,
            context_version: null,
            theme_id: null,
            frontend_theme_id: null,
            backend_theme_id: null,
            page_type: null,
            layout_option: null,
            version_id: null,
        });
    }

    function buildTypedEditorContext(resourceType, overrides = {}) {
        const targetType = String(state.layoutIdentity?.target_type || 'global');
        const targetId = parseInt(state.layoutIdentity?.target_id || 0, 10) || 0;
        const context = {
            scope: { identity: state.scopeIdentity },
            area: overrides.area || getEffectiveEditorArea(),
            resource_type: resourceType,
            theme_id: resourceType === 'theme_binding' ? 0 : (parseInt(overrides.theme_id || state.themeId, 10) || 0),
            layout_type: overrides.layout_type || getEffectiveLayoutType(),
            layout_option: overrides.layout_option || getEffectiveLayoutOption(),
            locale: overrides.locale || getScopedEditorLocale(),
            target_type: overrides.target_type || targetType,
            target_id: Object.prototype.hasOwnProperty.call(overrides, 'target_id') ? overrides.target_id : targetId,
        };
        if (resourceType === 'theme_binding' || resourceType === 'appearance') {
            context.layout_type = 'default';
            context.layout_option = 'default';
            context.locale = 'default';
            context.target_type = 'global';
            context.target_id = 0;
        } else if (resourceType === 'meta') {
            context.locale = 'default';
        }
        return context;
    }

    function scopedWorkspaceKey(resourceType, options = {}) {
        const context = buildTypedEditorContext(resourceType, options);
        const identity = context.scope?.identity || {};
        return [
            resourceType,
            identity.scope_kind || 'global',
            identity.website_id ?? '',
            identity.website_code || '',
            identity.store_code || '',
            identity.channel_code || '',
            identity.store_mode || 'normal',
            context.area,
            context.theme_id,
            context.layout_type,
            context.layout_option,
            context.locale,
            context.target_type,
            context.target_id,
        ].join('|');
    }

    function getScopedWorkspaceState(resourceType, options = {}) {
        return state.scopedWorkspaces[scopedWorkspaceKey(resourceType, options)] || null;
    }

    async function loadScopedWorkspace(resourceType, options = {}) {
        if (!config.apiScopedWorkspace || !state.scopeIdentity) return null;
        const url = new URL(config.apiScopedWorkspace, window.location.origin);
        url.searchParams.set('editor_context', JSON.stringify(buildTypedEditorContext(resourceType, options)));
        url.searchParams.set('_t', String(Date.now()));
        const result = await apiJson(url.toString());
        if (!result?.success) throw new Error(result?.message || 'Load scoped workspace failed');
        const key = scopedWorkspaceKey(resourceType, options);
        state.scopedWorkspaces[key] = result.data || {};
        renderThemeBindingOwnership();
        renderScopedConflictPanel();
        return state.scopedWorkspaces[key];
    }

    function queueScopedChanges(resourceType, changes, options = {}) {
        const run = async () => {
            const key = scopedWorkspaceKey(resourceType, options);
            const workspace = state.scopedWorkspaces[key]
                || await loadScopedWorkspace(resourceType, options);
            if (!workspace) throw new Error('Scoped workspace is unavailable');
            const result = await apiJson(config.apiScopedWorkspace, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    editor_context: buildTypedEditorContext(resourceType, options),
                    expected_revision: Number(workspace.revision || 0),
                    expected_parent_release_id: workspace.expected_parent_release_id ?? null,
                    changes,
                    summary: options.summary || '',
                }),
            });
            if (!result?.success) throw new Error(result?.message || 'Save scoped changes failed');
            const next = { ...workspace, ...(result.data || {}) };
            next.expected_parent_release_id = result.data?.expected_parent_release_id ?? workspace.expected_parent_release_id ?? null;
            next.draft_revision_id = result.data?.revision_id ?? workspace.draft_revision_id ?? null;
            state.scopedWorkspaces[key] = next;
            state.hasChanges = true;
            renderThemeBindingOwnership();
            renderScopedConflictPanel();
            return next;
        };
        const queued = Promise.resolve(state.pendingScopedMutation).catch(() => {}).then(run);
        // Callers still receive the rejecting promise so they can restore the
        // previous selector/value. The queue tail itself must settle, otherwise
        // one failed request would poison every later flush and context switch.
        state.pendingScopedMutation = queued.catch(() => undefined);
        return queued;
    }

    async function publishScopedWorkspace(resourceType, options = {}) {
        const key = scopedWorkspaceKey(resourceType, options);
        const workspace = state.scopedWorkspaces[key]
            || await loadScopedWorkspace(resourceType, options);
        if (!workspace || Number(workspace.revision || 0) <= 0) return null;
        if (workspace.draft_revision_id
            && Number(workspace.draft_revision_id) === Number(workspace.published_revision_id || 0)
        ) {
            return null;
        }
        const result = await apiJson(config.apiPublishScopedWorkspace, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                editor_context: buildTypedEditorContext(resourceType, options),
                expected_revision: Number(workspace.revision || 0),
                expected_parent_release_id: workspace.expected_parent_release_id ?? null,
                reason: options.reason || 'theme_editor_publish',
            }),
        });
        if (!result?.success) {
            if (result?.message === 'theme_scope_structural_conflict') {
                await loadScopedWorkspace(resourceType, options);
            }
            throw new Error(result?.message || 'Publish scoped workspace failed');
        }
        state.scopedWorkspaces[key] = await loadScopedWorkspace(resourceType, options);
        return result.data || null;
    }

    async function publishLoadedScopedWorkspaces(reason = 'theme_editor_publish') {
        await flushPendingEditorMutations();
        const currentResources = ['theme_binding'];
        if (state.themeId) {
            currentResources.push('layout', 'meta', 'appearance');
            if (String(getActiveConfigLocale() || '').trim()) currentResources.push('i18n');
        }
        for (const resourceType of currentResources) {
            if (!getScopedWorkspaceState(resourceType)) {
                await loadScopedWorkspace(resourceType);
            }
        }
        const currentKeys = currentScopedWorkspaceKeys();
        const entries = Object.entries(state.scopedWorkspaces)
            .filter(([key]) => currentKeys.has(key))
            .filter(([, workspace]) => workspace && Number(workspace.revision || 0) > 0)
            .filter(([, workspace]) => Number(workspace.draft_revision_id || 0) !== Number(workspace.published_revision_id || 0))
            .sort(([, left], [, right]) => {
                const priority = { theme_binding: 0, layout: 1, meta: 2, appearance: 3, i18n: 4 };
                return (priority[left?.context?.resource_type] ?? 9) - (priority[right?.context?.resource_type] ?? 9);
            });

        for (const [key, workspace] of entries) {
            const editorContext = workspace.context;
            if (!editorContext || !editorContext.scope) continue;
            const result = await apiJson(config.apiPublishScopedWorkspace, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    editor_context: editorContext,
                    expected_revision: Number(workspace.revision || 0),
                    expected_parent_release_id: workspace.expected_parent_release_id ?? null,
                    reason,
                }),
            });
            if (!result?.success) {
                if (result?.message === 'theme_scope_structural_conflict') {
                    await loadScopedWorkspace(
                        workspace.context?.resource_type || 'layout',
                        scopedOptionsFromContext(workspace.context || {}),
                    );
                }
                throw new Error(result?.message || 'Publish scoped workspace failed');
            }
            state.scopedWorkspaces[key] = {
                ...workspace,
                published_release_id: result.data?.release_id ?? workspace.published_release_id ?? null,
                published_revision_id: workspace.draft_revision_id ?? workspace.published_revision_id ?? null,
                expected_parent_release_id: result.data?.parent_release_id ?? workspace.expected_parent_release_id ?? null,
                status: 'active',
                conflicts: [],
            };
        }
        state.hasChanges = false;
        renderThemeBindingOwnership();
        renderScopedConflictPanel();
    }

    function renderThemeBindingOwnership() {
        const badge = document.getElementById('themeBindingSource');
        const inheritButton = document.getElementById('themeBindingInherit');
        if (!badge) return;
        const workspace = getScopedWorkspaceState('theme_binding');
        const owned = Array.isArray(workspace?.owned_paths) && workspace.owned_paths.includes('/theme_id');
        badge.textContent = owned
            ? translateUiText('本级修改')
            : `${translateUiText('继承自')} ${formatScopeSource(sourceScopeForScopedPath(workspace, '/theme_id'))}`;
        badge.dataset.owned = owned ? 'true' : 'false';
        if (inheritButton) inheritButton.hidden = !owned;
    }

    function formatScopeSource(storageScope) {
        const scope = String(storageScope || '').trim();
        if (!scope || scope === 'theme-package-default') return translateUiText('主题包默认值');
        if (scope === 'default.default.default') return 'Global';
        const segments = scope.split('.');
        if (segments.length !== 3) return scope;
        if (segments[1] === '__website__' || (segments[1] === 'default' && segments[2] === 'default')) {
            return `Website · ${segments[1] === '__website__' ? 'default' : segments[0]}`;
        }
        if (segments[2] === 'default') {
            return `Store · ${segments[1] === '__store__' ? 'default' : segments[1]}`;
        }
        return `Channel · ${segments[2] === '__channel__' ? 'default' : segments[2]}`;
    }

    function scopedOptionsFromContext(context) {
        return {
            area: context?.area,
            theme_id: context?.theme_id,
            layout_type: context?.layout_type,
            layout_option: context?.layout_option,
            locale: context?.locale,
            target_type: context?.target_type,
            target_id: context?.target_id,
        };
    }

    function currentScopedWorkspaceKeys() {
        const resources = ['theme_binding'];
        if (state.themeId) {
            resources.push('layout', 'meta', 'appearance');
            if (String(getActiveConfigLocale() || '').trim()) resources.push('i18n');
        }
        return new Set(resources.map((resourceType) => scopedWorkspaceKey(resourceType)));
    }

    function conflictNodeLabel(nodeUid, node) {
        const code = String(node?.widget_code || node?.widget_type || '').trim();
        return code ? `${code} · ${nodeUid}` : nodeUid;
    }

    function renderScopedConflictPanel() {
        const panel = elements.scopeConflictPanel || document.getElementById('themeScopeConflictPanel');
        const list = elements.scopeConflictList || panel?.querySelector('[data-theme-scope-conflict-list]');
        if (!(panel instanceof HTMLElement) || !(list instanceof HTMLElement)) return;

        const currentKeys = currentScopedWorkspaceKeys();
        const rows = Object.entries(state.scopedWorkspaces || {}).flatMap(([key, workspace]) => {
            if (!currentKeys.has(key)) return [];
            const conflicts = Array.isArray(workspace?.conflicts) ? workspace.conflicts : [];
            return conflicts.map((conflict) => ({ workspace, conflict }));
        });
        panel.hidden = rows.length === 0;
        list.replaceChildren();
        if (rows.length === 0) return;

        rows.forEach(({ workspace, conflict }) => {
            const item = document.createElement('article');
            item.className = 'theme-scope-conflict';
            const path = String(conflict?.path || '');
            const nodeUid = validNodeUid(conflict?.node_uid || path.split('/')[2] || '');
            const code = String(conflict?.code || 'theme_scope_structural_conflict');
            const title = document.createElement('div');
            title.className = 'theme-scope-conflict__title';
            title.textContent = `${code} · ${path || nodeUid}`;
            item.append(title);

            const actions = document.createElement('div');
            actions.className = 'theme-scope-conflict__actions';
            const options = scopedOptionsFromContext(workspace?.context || {});
            const resourceType = workspace?.context?.resource_type || 'layout';

            const reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'w-button';
            reset.dataset.size = 'sm';
            reset.dataset.variant = 'outline';
            reset.textContent = translateUiText('重置为继承');
            reset.addEventListener('click', async () => {
                try {
                    await queueScopedChanges(resourceType, [{ op: 'inherit', path }], {
                        ...options,
                        summary: 'structural_conflict_reset',
                    });
                    showToast(translateUiText('冲突路径已恢复继承'), 'success');
                    loadLayoutPreview();
                } catch (error) {
                    showToast(error?.message || translateUiText('冲突重置失败'), 'error');
                }
            });
            actions.append(reset);

            const draftNodes = workspace?.draft_payload?.nodes || {};
            const previousNode = nodeUid ? workspace?.published_payload?.nodes?.[nodeUid] : null;
            const currentNode = nodeUid ? draftNodes?.[nodeUid] : null;
            if (nodeUid && previousNode && typeof previousNode === 'object'
                && ['parent_deleted_owned_node', 'move_node_missing'].includes(code)
            ) {
                const rebaseline = document.createElement('button');
                rebaseline.type = 'button';
                rebaseline.className = 'w-button';
                rebaseline.dataset.size = 'sm';
                rebaseline.dataset.tone = 'warning';
                rebaseline.textContent = translateUiText('重新基线化');
                rebaseline.addEventListener('click', async () => {
                    try {
                        await queueScopedChanges(resourceType, [{
                            op: 'add_node',
                            path: `/nodes/${nodeUid}`,
                            node_uid: nodeUid,
                            value: { ...previousNode, node_uid: nodeUid },
                        }], {
                            ...options,
                            summary: 'structural_conflict_rebaseline',
                        });
                        showToast(translateUiText('节点已转为本级新增，请确认位置后发布'), 'success');
                        loadLayoutPreview();
                    } catch (error) {
                        showToast(error?.message || translateUiText('重新基线化失败'), 'error');
                    }
                });
                actions.append(rebaseline);
            }

            const candidates = Object.entries(draftNodes).filter(([uid, node]) =>
                validNodeUid(uid) && uid !== nodeUid && node && typeof node === 'object');
            if (nodeUid && ['move_anchor_missing', 'add_anchor_missing'].includes(code) && candidates.length) {
                const anchor = document.createElement('select');
                anchor.className = 'w-select theme-scope-conflict__anchor';
                anchor.setAttribute('aria-label', translateUiText('选择新锚点'));
                candidates.forEach(([uid, node]) => {
                    const option = document.createElement('option');
                    option.value = uid;
                    option.textContent = conflictNodeLabel(uid, node);
                    anchor.append(option);
                });
                const relocate = document.createElement('button');
                relocate.type = 'button';
                relocate.className = 'w-button';
                relocate.dataset.size = 'sm';
                relocate.dataset.tone = 'primary';
                relocate.textContent = translateUiText('重新定位');
                relocate.addEventListener('click', async () => {
                    const anchorUid = validNodeUid(anchor.value);
                    if (!anchorUid) return;
                    try {
                        await queueScopedChanges(resourceType, [{
                            op: code === 'add_anchor_missing' ? 'add_node' : 'move_node',
                            path: `/nodes/${nodeUid}`,
                            node_uid: nodeUid,
                            anchor_uid: anchorUid,
                            position: 'after',
                            ...(code === 'add_anchor_missing' && (currentNode || previousNode)
                                ? { value: { ...(currentNode || previousNode), node_uid: nodeUid } }
                                : {}),
                        }], {
                            ...options,
                            summary: 'structural_conflict_relocate',
                        });
                        showToast(translateUiText('冲突节点已重新定位'), 'success');
                        loadLayoutPreview();
                    } catch (error) {
                        showToast(error?.message || translateUiText('重新定位失败'), 'error');
                    }
                });
                actions.append(anchor, relocate);
            }
            item.append(actions);
            list.append(item);
        });
    }

    function readDraftPayloadPath(payload, path) {
        let cursor = payload;
        const segments = String(path || '').replace(/^\//, '').split('/').map((segment) =>
            segment.replace(/~1/g, '/').replace(/~0/g, '~'));
        for (const segment of segments) {
            if (!cursor || typeof cursor !== 'object' || !Object.prototype.hasOwnProperty.call(cursor, segment)) {
                return { exists: false, value: null };
            }
            cursor = cursor[segment];
        }
        return { exists: true, value: cursor };
    }

    function scopedOwnershipRules(workspace) {
        if (Array.isArray(workspace?.owned_rules)) return workspace.owned_rules;
        if (Array.isArray(workspace?.changes)) {
            return workspace.changes.map((change) => ({
                path: change?.path,
                operation: change?.op || change?.operation || 'set',
            }));
        }
        return (Array.isArray(workspace?.owned_paths) ? workspace.owned_paths : [])
            .map((path) => ({ path, operation: 'set' }));
    }

    function scopedRuleOwnsPath(rule, path) {
        const rulePath = String(rule?.path || '').replace(/\/$/, '');
        path = String(path || '').replace(/\/$/, '');
        if (!rulePath || !path) return false;
        if (path === rulePath || rulePath.startsWith(`${path}/`)) return true;
        if (!path.startsWith(`${rulePath}/`)) return false;
        if (String(rule?.operation || 'set') !== 'move_node') return true;
        const relative = path.slice(rulePath.length + 1);
        return ['parent_uid', 'anchor_uid', 'position'].includes(relative);
    }

    function isScopedPathOwned(rules, path) {
        return Array.isArray(rules) && rules.some((rule) => scopedRuleOwnsPath(rule, path));
    }

    function canRestoreScopedPath(rules, path) {
        if (!Array.isArray(rules)) return false;
        path = String(path || '').replace(/\/$/, '');
        return rules.some((rule) => {
            const rulePath = String(rule?.path || '').replace(/\/$/, '');
            return rulePath === path || rulePath.startsWith(`${path}/`);
        });
    }

    function sourceScopeForScopedPath(workspace, path) {
        const hasProvenanceContract = Array.isArray(workspace?.inherited_source_rules);
        const rules = hasProvenanceContract
            ? workspace.inherited_source_rules
            : [];
        let best = null;
        rules.forEach((rule) => {
            const rulePath = String(rule?.path || '').replace(/\/$/, '');
            if (!scopedRuleOwnsPath(rule, path)) return;
            const precedence = Number(rule?.precedence ?? Number.MAX_SAFE_INTEGER);
            if (!best
                || precedence < best.precedence
                || (precedence === best.precedence && rulePath.length > best.path.length)
            ) {
                best = {
                    path: rulePath,
                    precedence,
                    source: String(rule?.source_scope || ''),
                };
            }
        });
        return best?.source || (hasProvenanceContract
            ? 'theme-package-default'
            : (workspace?.parent_source_scope || 'theme-package-default'));
    }

    function renderLayoutConfigOwnership(container, workspace, locale = '') {
        if (!(container instanceof HTMLElement) || !workspace) return;
        const normalizedLocale = String(locale || '').trim();
        const prefix = normalizedLocale ? '/translations/layout' : '/values';
        const owned = scopedOwnershipRules(workspace);
        container.querySelectorAll('.w-param-field[data-field-key]').forEach((field) => {
            const key = String(field.dataset.fieldKey || '');
            if (!key) return;
            const path = `${prefix}/${jsonPointerSegment(key)}`;
            const isOwned = isScopedPathOwned(owned, path);
            const canRestore = canRestoreScopedPath(owned, path);
            field.dataset.scopeOwned = isOwned ? 'true' : 'false';
            let status = field.querySelector(':scope > .w-param-field-header .theme-config-ownership');
            const header = field.querySelector(':scope > .w-param-field-header');
            if (!header) return;
            if (!status) {
                status = document.createElement('span');
                status.className = 'theme-config-ownership';
                header.appendChild(status);
            }
            status.innerHTML = '';
            const badge = document.createElement('span');
            badge.className = 'w-badge theme-config-ownership__badge';
            badge.dataset.owned = isOwned ? 'true' : 'false';
            badge.textContent = isOwned
                ? translateUiText('本级修改')
                : `${translateUiText('继承自')} ${formatScopeSource(sourceScopeForScopedPath(workspace, path))}`;
            status.appendChild(badge);
            if (canRestore) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'w-button theme-config-ownership__inherit';
                button.dataset.variant = 'link';
                button.dataset.size = 'sm';
                button.textContent = translateUiText('恢复继承');
                button.addEventListener('click', async () => {
                    try {
                        const resourceType = normalizedLocale ? 'i18n' : 'meta';
                        const next = await queueScopedChanges(resourceType, [{ op: 'inherit', path }], {
                            locale: normalizedLocale || 'default',
                            summary: 'layout_config_inherit',
                        });
                        const inherited = readDraftPayloadPath(next?.draft_payload || {}, path);
                        if (inherited.exists) {
                            field.querySelectorAll('[name]').forEach((control) => {
                                if (String(control.name || '').replace(/\[\]$/, '') === key) {
                                    setConfigControlValue(control, inherited.value);
                                }
                            });
                        }
                        renderLayoutConfigOwnership(container, next, normalizedLocale);
                        showToast(translateUiText('已恢复继承（发布后生效）'), 'success');
                    } catch (error) {
                        showToast(error?.message || translateUiText('恢复继承失败'), 'error');
                    }
                });
                status.appendChild(button);
            }
        });
    }

    function renderWidgetConfigOwnership(container, nodeUid, workspace, locale = '') {
        if (!(container instanceof HTMLElement) || !workspace) return;
        nodeUid = validNodeUid(nodeUid);
        if (!nodeUid) return;
        container.dataset.scopeNodeUid = nodeUid;
        const normalizedLocale = String(locale || '').trim();
        const prefix = normalizedLocale ? `/translations/${nodeUid}` : `/nodes/${nodeUid}/config`;
        const owned = scopedOwnershipRules(workspace);
        container.querySelectorAll('.w-param-field[data-field-key]').forEach((field) => {
            const key = String(field.dataset.fieldKey || '');
            if (!key) return;
            const path = `${prefix}/${jsonPointerSegment(key)}`;
            const isOwned = isScopedPathOwned(owned, path);
            const canRestore = canRestoreScopedPath(owned, path);
            field.dataset.scopeOwned = isOwned ? 'true' : 'false';
            const header = field.querySelector(':scope > .w-param-field-header');
            if (!header) return;
            let status = header.querySelector('.theme-config-ownership');
            if (!status) {
                status = document.createElement('span');
                status.className = 'theme-config-ownership';
                header.appendChild(status);
            }
            status.innerHTML = '';
            const badge = document.createElement('span');
            badge.className = 'w-badge theme-config-ownership__badge';
            badge.dataset.owned = isOwned ? 'true' : 'false';
            badge.textContent = isOwned
                ? translateUiText('本级修改')
                : `${translateUiText('继承自')} ${formatScopeSource(sourceScopeForScopedPath(workspace, path))}`;
            status.appendChild(badge);
            if (canRestore) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'w-button theme-config-ownership__inherit';
                button.dataset.variant = 'link';
                button.dataset.size = 'sm';
                button.textContent = translateUiText('恢复继承');
                button.addEventListener('click', async () => {
                    try {
                        const resourceType = normalizedLocale ? 'i18n' : 'layout';
                        const next = await queueScopedChanges(resourceType, [{ op: 'inherit', path }], {
                            locale: normalizedLocale || 'default',
                            summary: 'widget_config_inherit',
                        });
                        const inherited = readDraftPayloadPath(next?.draft_payload || {}, path);
                        if (inherited.exists) {
                            field.querySelectorAll('[name]').forEach((control) => {
                                if (String(control.name || '').replace(/\[\]$/, '') === key) {
                                    setConfigControlValue(control, inherited.value);
                                }
                            });
                        }
                        renderWidgetConfigOwnership(container, nodeUid, next, normalizedLocale);
                        showToast(translateUiText('已恢复继承（发布后生效）'), 'success');
                    } catch (error) {
                        showToast(error?.message || translateUiText('恢复继承失败'), 'error');
                    }
                });
                status.appendChild(button);
            }
        });
    }

    function jsonPointerSegment(value) {
        return String(value ?? '').replace(/~/g, '~0').replace(/\//g, '~1');
    }

    function validNodeUid(value) {
        const uid = String(value || '').toLowerCase();
        return /^[a-f0-9]{32}$/.test(uid) ? uid : '';
    }

    function scopedValuesEqual(left, right) {
        if (Object.is(left, right)) return true;
        if (Array.isArray(left) || Array.isArray(right)) {
            if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) return false;
            return left.every((value, index) => scopedValuesEqual(value, right[index]));
        }
        if (!left || !right || typeof left !== 'object' || typeof right !== 'object') return false;
        const leftKeys = Object.keys(left).sort();
        const rightKeys = Object.keys(right).sort();
        if (leftKeys.length !== rightKeys.length) return false;
        return leftKeys.every((key, index) => key === rightKeys[index]
            && scopedValuesEqual(left[key], right[key]));
    }

    function scopedConfigCommands(prefix, values, basePayload = null) {
        if (!values || typeof values !== 'object' || Array.isArray(values)) return [];
        return Object.entries(values).flatMap(([key, value]) => {
            if (value === undefined) return [];
            const path = `${prefix}/${jsonPointerSegment(key)}`;
            const current = basePayload && typeof basePayload === 'object'
                ? readDraftPayloadPath(basePayload, path)
                : { exists: false, value: null };
            if (current.exists && scopedValuesEqual(current.value, value)) return [];
            return [{ op: 'set', path, value }];
        });
    }

    async function queueAddedLayoutNode(payload, responseData) {
        const nodeUid = validNodeUid(responseData?.node_uid);
        if (!nodeUid) throw new Error(translateUiText('布局节点缺少稳定 UID，未写入 Scope 草稿'));
        const node = {
            node_uid: nodeUid,
            area: String(payload.area || 'content'),
            slot_id: payload.slot_id || null,
            widget_code: String(payload.widget_code || ''),
            widget_module: String(payload.widget_module || ''),
            widget_type: String(payload.widget_type || ''),
            config: payload.config && typeof payload.config === 'object' ? payload.config : {},
            sort_order: Number(payload.sort_order || 0),
            is_active: true,
        };
        await queueScopedChanges('layout', [{
            op: 'add_node',
            path: `/nodes/${nodeUid}`,
            node_uid: nodeUid,
            value: node,
        }], { summary: 'layout_node_added' });
    }

    async function queueRemovedLayoutNode(response, hints = {}) {
        const nodeUid = resolveRemovedLayoutNodeUid(response, hints);
        if (!nodeUid) {
            console.warn('[ThemeEditor] Skip scoped remove_node tombstone: missing node_uid', hints);
            return;
        }
        await queueScopedChanges('layout', [{
            op: 'remove_node',
            path: `/nodes/${nodeUid}`,
            node_uid: nodeUid,
        }], { summary: 'layout_node_removed' });
    }

    function resolveRemovedLayoutNodeUid(response, hints = {}) {
        const direct = validNodeUid(
            response?.node_uid
            || response?.data?.node_uid
            || hints.nodeUid
        );
        if (direct) {
            return direct;
        }
        const layoutId = String(hints.layoutId || response?.layout_id || '').trim();
        if (!layoutId) {
            return '';
        }
        const workspace = getScopedWorkspaceState('layout');
        const nodes = workspace?.draft_payload?.nodes;
        if (!nodes || typeof nodes !== 'object') {
            return '';
        }
        for (const uid of Object.keys(nodes)) {
            const node = nodes[uid];
            if (!node || typeof node !== 'object') {
                continue;
            }
            if (String(node.layout_id || '') === layoutId) {
                const resolved = validNodeUid(node.node_uid || uid);
                if (resolved) {
                    return resolved;
                }
            }
        }
        return '';
    }

    function resolveWidgetContextFromIframe(identityInput) {
        const context = {
            nodeUid: '',
            widgetModule: '',
            widgetType: '',
            widgetCode: '',
            slotId: '',
            area: '',
            templateRef: '',
        };
        const layoutId = typeof identityInput === 'object'
            ? String(identityInput?.layoutId || '').trim()
            : String(identityInput || '').trim();
        const templateRef = typeof identityInput === 'object'
            ? String(identityInput?.templateRef || '').trim()
            : '';
        context.templateRef = templateRef;
        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument || (!layoutId && !templateRef)) {
            return context;
        }
        try {
            const selector = layoutId
                ? dataLayoutIdSelector(layoutId)
                : dataTemplateRefSelector(templateRef);
            const widgetEl = iframe.contentDocument.querySelector(selector);
            if (!widgetEl) {
                return context;
            }
            context.nodeUid = validNodeUid(widgetEl.getAttribute('data-node-uid') || widgetEl.dataset.nodeUid || '');
            context.widgetModule = String(widgetEl.getAttribute('data-widget-module') || widgetEl.dataset.widgetModule || '').trim();
            context.widgetType = String(widgetEl.getAttribute('data-widget-type') || widgetEl.dataset.widgetType || '').trim();
            context.widgetCode = String(widgetEl.getAttribute('data-widget-code') || widgetEl.dataset.widgetCode || '').trim();
            context.templateRef = String(widgetEl.getAttribute('data-template-ref') || widgetEl.dataset.templateRef || templateRef || '').trim();
            context.slotId = String(widgetEl.getAttribute('data-slot-id') || widgetEl.dataset.slotId || '').trim();
            if (widgetEl.closest('header, [data-wslot-position="header"], .site-header')) {
                context.area = 'header';
            } else if (widgetEl.closest('footer, [data-wslot-position="footer"], .site-footer')) {
                context.area = 'footer';
            } else {
                context.area = 'content';
            }
        } catch (error) {
            console.warn('[ThemeEditor] resolveWidgetContextFromIframe failed:', error);
        }
        return context;
    }

    function resolveWidgetDeleteTarget(button, bar) {
        const layoutId = String(bar?.dataset?.layoutId || button?.dataset?.layoutId || '').trim();
        const templateRef = String(bar?.dataset?.templateRef || button?.dataset?.templateRef || '').trim();
        const stackTop = (state.nestStack && state.nestStack.length) ? String(state.nestStack[0]).trim() : '';
        const identity = stackTop || layoutId || templateRef;
        const isTemplate = identity.startsWith('tpl:') || (!layoutId && templateRef !== '');
        return {
            layoutId: isTemplate ? '' : (layoutId || (/^\d+$/.test(identity) ? identity : '')),
            templateRef: isTemplate ? (templateRef || identity) : templateRef,
            slotId: String(button?.dataset?.slotId || bar?.dataset?.slotId || '').trim(),
        };
    }

    function buildWidgetDeletePayload(identityInput, slotId, area, widgetContext = {}) {
        const layoutId = typeof identityInput === 'object'
            ? String(identityInput?.layoutId || '').trim()
            : String(identityInput || '').trim();
        const templateRef = typeof identityInput === 'object'
            ? String(identityInput?.templateRef || '').trim()
            : '';
        return {
            layout_id: layoutId || 0,
            template_ref: templateRef || widgetContext.templateRef || '',
            theme_id: state.themeId,
            slot_id: slotId || widgetContext.slotId || '',
            area: area || widgetContext.area || 'content',
            layout_type: state.layoutType || 'homepage',
            layout_option: state.layoutOption || 'default',
            editor_area: state.editorArea || 'frontend',
            widget_module: widgetContext.widgetModule || '',
            widget_type: widgetContext.widgetType || '',
            widget_code: widgetContext.widgetCode || '',
            ...getLayoutLockVirtualPayload(),
        };
    }

    function resolveWidgetElementSelector(identityInput) {
        const layoutId = typeof identityInput === 'object'
            ? String(identityInput?.layoutId || '').trim()
            : String(identityInput || '').trim();
        const templateRef = typeof identityInput === 'object'
            ? String(identityInput?.templateRef || '').trim()
            : '';
        if (layoutId) {
            return dataLayoutIdSelector(layoutId);
        }
        if (templateRef) {
            return dataTemplateRefSelector(templateRef);
        }
        return '';
    }

    async function queueLayoutNodePlacementOwnership(nodes, summary = 'layout_node_placement_changed') {
        if (!Array.isArray(nodes) || nodes.length === 0) return null;
        await state.pendingScopedMutation;
        const current = getScopedWorkspaceState('layout') || await loadScopedWorkspace('layout');
        const draftPayload = current?.draft_payload || {};
        const changes = [];
        nodes.forEach((node) => {
            const nodeUid = validNodeUid(node?.node_uid);
            if (!nodeUid) {
                throw new Error(translateUiText('布局节点缺少稳定 UID，未写入位置 Scope 草稿'));
            }
            ['area', 'sort_order'].forEach((field) => {
                if (!Object.prototype.hasOwnProperty.call(node, field)) return;
                const value = field === 'sort_order' ? Number(node[field] || 0) : String(node[field] || '');
                const path = `/nodes/${nodeUid}/${field}`;
                const existing = readDraftPayloadPath(draftPayload, path);
                if (!existing.exists || !scopedValuesEqual(existing.value, value)) {
                    changes.push({ op: 'set', path, value });
                }
            });
        });
        if (changes.length === 0) return current;
        return queueScopedChanges('layout', changes, { summary });
    }

    async function queueWidgetConfigOwnership(nodeUid, configValues, locale = '') {
        nodeUid = validNodeUid(nodeUid);
        if (!nodeUid) throw new Error(translateUiText('布局节点缺少稳定 UID，未写入配置 Scope 草稿'));
        const normalizedLocale = String(locale || '').trim();
        const resourceType = normalizedLocale ? 'i18n' : 'layout';
        const prefix = normalizedLocale
            ? `/translations/${nodeUid}`
            : `/nodes/${nodeUid}/config`;
        await state.pendingScopedMutation;
        const options = { locale: normalizedLocale || 'default' };
        const current = getScopedWorkspaceState(resourceType, options)
            || await loadScopedWorkspace(resourceType, options);
        const changes = scopedConfigCommands(prefix, configValues, current?.draft_payload || {});
        if (changes.length === 0) return current;
        return queueScopedChanges(resourceType, changes, {
            ...options,
            summary: normalizedLocale ? 'widget_i18n_changed' : 'widget_config_changed',
        }).then((workspace) => {
            document.querySelectorAll(`[data-scope-node-uid="${nodeUid}"]`).forEach((container) => {
                renderWidgetConfigOwnership(container, nodeUid, workspace, normalizedLocale);
            });
            return workspace;
        });
    }

    async function queueLayoutConfigOwnership(configValues, locale = '') {
        const normalizedLocale = String(locale || '').trim();
        const resourceType = normalizedLocale ? 'i18n' : 'meta';
        const prefix = normalizedLocale ? '/translations/layout' : '/values';
        await state.pendingScopedMutation;
        const options = { locale: normalizedLocale || 'default' };
        const current = getScopedWorkspaceState(resourceType, options)
            || await loadScopedWorkspace(resourceType, options);
        const changes = scopedConfigCommands(prefix, configValues, current?.draft_payload || {});
        if (changes.length === 0) return current;
        const workspace = await queueScopedChanges(resourceType, changes, {
            ...options,
            summary: normalizedLocale ? 'layout_i18n_changed' : 'layout_config_changed',
        });
        const panel = elements.configContent?.querySelector('.layout-config-panel');
        if (panel) renderLayoutConfigOwnership(panel, workspace, normalizedLocale);
        return workspace;
    }

    function buildEditorUrl(overrides = {}) {
        const currentUrl = getCurrentWindowUrl();
        const url = new URL(config.apiBase || currentUrl.pathname, window.location.origin);

        currentUrl.searchParams.forEach((value, key) => {
            url.searchParams.set(key, value);
        });

        const previewArea = (typeof overrides.preview_area === 'string' && overrides.preview_area)
            ? overrides.preview_area
            : (
                (typeof overrides.editor_area === 'string' && overrides.editor_area)
                    ? overrides.editor_area
                    : (state.editorArea || currentUrl.searchParams.get('preview_area') || 'frontend')
            );

        const params = Object.assign({
            theme_id: state.themeId || 0,
            page_type: getCurrentPageType(),
            layout_option: state.layoutOption || 'default',
            status: state.previewStatus || 'draft',
            interaction_mode: normalizeInteractionMode(state.interactionMode || 'edit'),
            selection_target: normalizeSelectionTarget(state.selectionTarget || 'default'),
            link_block: state.linkBlockEnabled === true ? '1' : null,
        }, overrides || {});
        params.editor_area = previewArea;
        params.preview_area = previewArea;
        if (Object.prototype.hasOwnProperty.call(params, 'interaction_mode')) {
            params.interaction_mode = normalizeInteractionMode(params.interaction_mode);
        }
        if (Object.prototype.hasOwnProperty.call(params, 'selection_target')) {
            const target = normalizeSelectionTarget(params.selection_target);
            params.selection_target = target === 'default' ? null : target;
        }
        if (Object.prototype.hasOwnProperty.call(params, 'link_block')) {
            params.link_block = normalizeLinkBlockEnabled(params.link_block) ? '1' : null;
        }

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
        const currentUrl = getCurrentWindowUrl();
        const layoutType = (typeof overrides.layout_type === 'string' && overrides.layout_type)
            ? overrides.layout_type
            : getEffectiveLayoutType();
        const pageType = (typeof overrides.page_type === 'string' && overrides.page_type)
            ? overrides.page_type
            : getEffectivePageType(layoutType || 'homepage');
        const layoutOption = (typeof overrides.layout_option === 'string' && overrides.layout_option)
            ? overrides.layout_option
            : getEffectiveLayoutOption();
        const previewStatus = (typeof overrides.status === 'string' && overrides.status)
            ? overrides.status
            : (state.previewStatus || 'draft');
        const requestedPreviewArea = (typeof overrides.preview_area === 'string' && overrides.preview_area)
            ? overrides.preview_area
            : (
                (typeof overrides.editor_area === 'string' && overrides.editor_area)
                    ? overrides.editor_area
                    : (getEffectiveEditorArea(currentUrl.searchParams.get('preview_area') || 'frontend'))
            );
        const previewArea = requestedPreviewArea === 'backend' ? 'backend' : 'frontend';
        const themeId = overrides.theme_id || state.themeId || 0;
        const endpoint = previewArea === 'frontend'
            ? (config.apiFrontendLayoutPreview || '/theme/frontend/theme-preview/content')
            : config.apiLayoutPreview;
        const url = new URL(endpoint, window.location.origin);

        url.searchParams.set('theme_id', String(themeId));
        url.searchParams.set('page_type', pageType);
        url.searchParams.set('layout_type', layoutType);
        url.searchParams.set('layout_option', layoutOption);
        url.searchParams.set('editor_mode', String(overrides.editor_mode || '1'));
        url.searchParams.set('preview_mode', String(overrides.preview_mode || 'live'));
        url.searchParams.set(
            'interaction_mode',
            normalizeInteractionMode(
                Object.prototype.hasOwnProperty.call(overrides, 'interaction_mode')
                    ? overrides.interaction_mode
                    : (state.interactionMode || 'edit')
            )
        );
        url.searchParams.set('status', previewStatus);
        url.searchParams.set('editor_area', previewArea);
        url.searchParams.set('preview_area', previewArea);
        const previewLocale = getPreviewLocaleForRequest(overrides);
        if (previewLocale) {
            url.searchParams.set('locale', previewLocale);
        }

        ['frontend_theme_id', 'backend_theme_id', 'version_id', 'theme_public_route'].forEach((key) => {
            const overrideValue = Object.prototype.hasOwnProperty.call(overrides, key) ? overrides[key] : currentUrl.searchParams.get(key);
            if (overrideValue !== null && overrideValue !== undefined && overrideValue !== '') {
                url.searchParams.set(key, String(overrideValue));
            }
        });
        appendThemeLayoutRuntimeParams(url, overrides);
        url.searchParams.set('editor_context', JSON.stringify(buildTypedEditorContext('layout', {
            area: previewArea,
            theme_id: themeId,
            layout_type: layoutType,
            layout_option: layoutOption,
            locale: previewLocale || 'default',
            target_type: Object.prototype.hasOwnProperty.call(overrides, 'target_type')
                ? overrides.target_type
                : (state.layoutIdentity?.target_type || 'global'),
            target_id: Object.prototype.hasOwnProperty.call(overrides, 'target_id')
                ? overrides.target_id
                : (state.layoutIdentity?.target_id || 0),
        })));

        url.searchParams.set('_t', String(overrides._t || Date.now()));
        return url.toString();
    }

    /**
     * Frontend preview is a capability URL. The typed editor context describes
     * what may be rendered, while the server-issued token authorizes that exact
     * Scope/Theme/Layout/Locale snapshot. Never treat the raw typed context as
     * an authorization credential.
     */
    async function buildAuthorizedLayoutPreviewUrl(overrides = {}) {
        const rawUrl = buildLayoutPreviewUrl(overrides);
        const url = new URL(rawUrl, window.location.origin);
        if (url.searchParams.get('preview_area') !== 'frontend') {
            return rawUrl;
        }

        const themeId = parseInt(url.searchParams.get('theme_id') || '0', 10) || 0;
        const layoutType = url.searchParams.get('layout_type') || getEffectiveLayoutType();
        const layoutOption = url.searchParams.get('layout_option') || getEffectiveLayoutOption();
        const rawLocale = url.searchParams.get('locale') || '';
        const locale = rawLocale === 'default' ? '' : rawLocale;
        let editorContext = buildTypedEditorContext('layout', {
            area: 'frontend',
            theme_id: themeId,
            layout_type: layoutType,
            layout_option: layoutOption,
            locale: locale || 'default',
        });
        try {
            const encodedContext = url.searchParams.get('editor_context');
            if (encodedContext) {
                editorContext = JSON.parse(encodedContext);
            }
        } catch (error) {
            throw new Error(translateUiText('预览上下文无效'));
        }

        const versionId = parseInt(url.searchParams.get('version_id') || '0', 10) || null;
        const payload = buildLayoutVersionIdentityPayload({
            theme_id: themeId,
            frontend_theme_id: parseInt(url.searchParams.get('frontend_theme_id') || String(themeId), 10) || themeId,
            backend_theme_id: parseInt(url.searchParams.get('backend_theme_id') || '0', 10) || 0,
            page_type: layoutType,
            layout_type: layoutType,
            layout_option: layoutOption,
            editor_area: 'frontend',
            preview_area: 'frontend',
            preview_mode: url.searchParams.get('preview_mode') || 'live',
            status: url.searchParams.get('status') || state.previewStatus || 'draft',
            locale,
            locale_code: locale,
            target_type: editorContext.target_type || 'global',
            target_id: parseInt(editorContext.target_id || 0, 10) || 0,
            editor_context: editorContext,
            version_id: versionId,
        });
        if (!versionId) {
            delete payload.version_id;
        }

        const result = await apiJson(config.apiStartPreview, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        });
        const token = String(result?.data?.token || '').trim();
        if (!result?.success || !token) {
            throw new Error(result?.message || translateUiText('预览授权失败'));
        }

        url.searchParams.set('weline_preview_token', token);
        return url.toString();
    }

    async function setLayoutPreviewSource(overrides = {}) {
        if (!elements.previewFrame || !state.themeId) {
            return '';
        }
        const navigationSequence = ++state.previewNavigationSequence;
        try {
            const previewUrl = await buildAuthorizedLayoutPreviewUrl(overrides);
            if (navigationSequence !== state.previewNavigationSequence || !elements.previewFrame) {
                return '';
            }
            elements.previewFrame.src = previewUrl;
            return previewUrl;
        } catch (error) {
            if (navigationSequence === state.previewNavigationSequence) {
                elements.previewLoading?.classList.add('hidden');
                showToast(error?.message || translateUiText('预览加载失败'), 'error');
            }
            console.error('[ThemeEditor] Authorized preview error:', error);
            return '';
        }
    }

    function navigateEditorShell(overrides = {}) {
        const targetUrl = buildEditorUrl(overrides);
        if (state.lockHeld) {
            releaseCurrentEditorLock({keepalive: true});
        }
        window.location.href = targetUrl;
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
        config.apiDefaultInjections = container.dataset.apiDefaultInjections || `${config.apiBase}/default-injections`;
        config.apiApplyDefaultInjection = container.dataset.apiApplyDefaultInjection || `${config.apiBase}/apply-default-injection`;
        config.apiInitSlotDefaults = container.dataset.apiInitSlotDefaults || `${config.apiBase}/init-slot-defaults`;
        config.apiPublish = container.dataset.apiPublish || `${config.apiBase}/publish`;
        config.apiPreview = container.dataset.apiPreview || `${config.apiBase}/preview`;
        config.apiRenderWidget = container.dataset.apiRenderWidget || `${config.apiBase}/render-widget`;
        config.apiWidgetPreview = container.dataset.apiWidgetPreview || `${config.apiBase}/widget-preview`;
        config.apiCompileLayout = container.dataset.apiCompileLayout || `${config.apiBase}/compile-layout`;
        config.apiLayoutOptions = container.dataset.apiLayoutOptions || `${config.apiBase}/layout-options`;
        config.apiLayoutConfig = container.dataset.apiLayoutConfig || `${config.apiBase}/layout-config`;
        config.apiSaveLayoutSelection = container.dataset.apiSaveLayoutSelection || `${config.apiBase}/save-layout-selection`;
        config.apiSaveLayoutConfig = container.dataset.apiSaveLayoutConfig || `${config.apiBase}/save-layout-config`;
        config.apiAiTranslateConfig = container.dataset.apiAiTranslateConfig || `${config.apiBase}/ai-translate-config`;
        config.apiVirtualThemeAiCatalog = container.dataset.apiVirtualThemeAiCatalog || '/theme/backend/virtual-theme/ai-catalog';
        config.apiVirtualThemeCreateDraft = container.dataset.apiVirtualThemeCreateDraft || '/theme/backend/virtual-theme/create-draft';
        config.apiVirtualThemeBlockAction = container.dataset.apiVirtualThemeBlockAction || '/theme/backend/virtual-theme/block-action';
        config.apiVirtualThemeSource = container.dataset.apiVirtualThemeSource || '/theme/backend/virtual-theme/source';
        config.apiVirtualThemeSaveSource = container.dataset.apiVirtualThemeSaveSource || '/theme/backend/virtual-theme/save-source';
        config.apiVirtualThemePublishVersion = container.dataset.apiVirtualThemePublishVersion || '/theme/backend/virtual-theme/publish-version';
        config.apiLayoutPreview = container.dataset.apiLayoutPreview || `${config.apiBase}/layout-preview`;
        config.apiFrontendLayoutPreview = container.dataset.apiFrontendLayoutPreview || '/theme/frontend/theme-preview/content';
        config.apiParamRenderForm = container.dataset.apiParamRenderForm || '/theme/backend/widget/paramrender/form';
        config.apiSaveCompiledLayout = container.dataset.apiSaveCompiledLayout || `${config.apiBase}/save-compiled-layout`;
        config.defaultLocale = container.dataset.defaultLocale || config.defaultLocale || 'zh_Hans_CN';

        // 版本控制 API 端点
        config.apiVersions = container.dataset.apiVersions || `${config.apiBase}/versions`;
        config.apiSaveVersion = container.dataset.apiSaveVersion || `${config.apiBase}/save-version`;
        config.apiSwitchVersion = container.dataset.apiSwitchVersion || `${config.apiBase}/switch-version`;
        config.apiRestoreOriginal = container.dataset.apiRestoreOriginal || `${config.apiBase}/restore-original`;
        config.apiResetDraftResources = container.dataset.apiResetDraftResources || `${config.apiBase}/reset-draft-resources`;
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
        config.apiScopedWorkspace = container.dataset.apiScopedWorkspace || `${config.apiBase}/scoped-workspace`;
        config.apiPublishScopedWorkspace = container.dataset.apiPublishScopedWorkspace || `${config.apiBase}/publish-scoped-workspace`;

        // Preview-related endpoints and call sites (baseline for TDD)
        // - apiRenderWidget: used by renderWidgetPreview()/preview render flows
        // - apiWidgetPreview: legacy per-widget preview fetches (to be removed)
        // - apiLayoutPreview: used by refreshPreviewWidgets() and loadLayoutPreview()
        // - apiCompileLayout: used by fetchLayoutSlots()
        // - apiSaveWidget/save-widget-config: save flows that should return preview_html

        state.themeId = parseInt(container.dataset.themeId) || 0;
        state.pageType = container.dataset.pageType || 'default';
        state.editorArea = container.dataset.editorArea || 'frontend';
        state.previewStatus = container.dataset.previewStatus || getCurrentWindowParam('status') || 'draft';
        state.layoutOptionsByType = parseLayoutOptionsByType(container.dataset.layoutOptions || '{}');
        state.layoutLock = parseLayoutLock(container.dataset.layoutLock || '{}');
        state.layoutIdentity = parseLayoutIdentityDataset(container.dataset || {});
        state.legacyScopeReadonly = container.dataset.legacyScopeReadonly === '1';
        try {
            state.scopeIdentity = JSON.parse(container.dataset.scopeIdentity || '{}');
        } catch (error) {
            state.scopeIdentity = null;
        }
        state.configLocale = Weline.Theme.CmsPreviewBridge
            ? Weline.Theme.CmsPreviewBridge.normalizeLocale(
                getCurrentWindowParam('locale') || container.dataset.configLocale || ''
            )
            : String(getCurrentWindowParam('locale') || container.dataset.configLocale || '').trim();
        syncThemeEditorLocaleDataset();
        _installedLocalesCache = parseInstalledLocales(container.dataset.installedLocales || '[]');

        // 缓存 DOM 元素
        elements = {
            container: container,
            themeSelect: document.getElementById('themeSelect'),
            themeBindingInherit: document.getElementById('themeBindingInherit'),
            scopeSelect: document.getElementById('scopeSelect'),
            pageTypeSelect: document.getElementById('pageTypeSelect'),
            layoutOptionSelect: document.getElementById('layoutOptionSelect'),
            editorAreaSelect: document.getElementById('editorAreaSelect'),
            editorLangSwitcher: document.getElementById('editorLangSwitcher'),
            scopeConflictPanel: document.getElementById('themeScopeConflictPanel'),
            scopeConflictList: document.querySelector('[data-theme-scope-conflict-list]'),
            configPanel: document.getElementById('configPanel'),
            configContent: document.getElementById('configContent'),
            configPanelTitle: document.querySelector('#configPanel .panel-title'),
            configModeLayout: document.getElementById('configModeLayout'),
            configModeWidget: document.getElementById('configModeWidget'),
            widgetPanel: document.getElementById('widgetPanel'),
            widgetLibraryTabs: document.getElementById('widgetLibraryTabs'),
            widgetList: document.getElementById('widgetList'),
            widgetSearch: document.getElementById('widgetSearch'),
            previewFrame: document.getElementById('previewFrame'),
            previewLoading: document.getElementById('previewLoading'),
            slotsInfoPanel: document.getElementById('slotsInfoPanel'),
            slotsInfoDock: document.getElementById('slotsInfoDock'),
            slotsInfoList: document.getElementById('slotsInfoList'),
            btnPreview: document.getElementById('btnPreview'),
            btnSave: document.getElementById('btnSave'),
            btnPublish: document.getElementById('btnPublish'),
            btnFrontendPreview: document.getElementById('btnFrontendPreview'),
            btnRestoreLayout: document.getElementById('btnRestoreLayout'),
            btnResetDraftResources: document.getElementById('btnResetDraftResources'),
            resetDraftModal: document.getElementById('themeEditorResetDraftModal'),
            btnResetDraftConfirm: document.getElementById('btnResetDraftConfirm'),
            btnResetDraftCancel: document.getElementById('btnResetDraftCancel'),
            btnResetDraftAllResources: document.getElementById('btnResetDraftAllResources'),
            btnRefreshPreview: document.getElementById('btnRefreshPreview'),
            btnFullscreenPreview: document.getElementById('btnFullscreenPreview'),
        };

        initSidePanels();
        initSlotsPanel();
        setInteractionMode(resolveInitialInteractionMode(), {
            force: true,
            skipViewSwitch: true,
            restorePanels: false,
        });
        setSelectionTarget(resolveInitialSelectionTarget(), {
            force: true,
            syncUrl: false,
        });
        setLinkBlockEnabled(resolveInitialLinkBlockEnabled(), {
            force: true,
            syncUrl: false,
        });
        initWidgetLibraryTabs();

        // 当前布局信息
        // pageType 和 layoutType 现在是同一个概念，直接使用 pageType
        state.layoutType = state.pageType || 'homepage';
        state.layoutOption = resolveLayoutOptionForType(
            state.layoutType,
            container.dataset.layoutOption || getCurrentWindowParam('layout_option') || 'default'
        );
        renderLayoutOptionSelect(state.layoutType, state.layoutOption);
        if (state.legacyScopeReadonly) {
            enforceLegacyScopeReadonly(container);
            console.warn('[ThemeEditor] Legacy Scope is compatibility-read-only:', state.layoutIdentity?.scope || '');
            return;
        }
        Promise.resolve(loadScopedWorkspace('theme_binding')).catch((error) => {
            console.warn('[ThemeEditor] Scoped theme binding load failed:', error);
        });
        if (state.themeId) {
            Promise.resolve(loadScopedWorkspace('layout')).catch((error) => {
                console.warn('[ThemeEditor] Scoped layout load failed:', error);
            });
        }
        initEditorLanguageSwitcher();
        state.slots = {}; // 页面插槽信息
        state.missingSlotWarnings = [];

        // 绑定事件
        bindEvents();
        initCmsContextBridge();
        // 部件库与预览并行：优先发起部件列表请求，不再等待 iframe load
        deferWidgetLibraryLoad();
        if (state.themeId) {
            loadLayoutPreview();
        }

        // 初始化拖拽
        initDragAndDrop();

        // 适配部件库预览缩放
        initWidgetPreviewFitObserver();
        scheduleFitWidgetPreviews();
        hydrateWidgetLibraryPreviews();
        window.addEventListener('resize', debounce(() => {
            scheduleFitWidgetPreviews();
        }, 200));

        // 加载版本列表（初始化时获取当前版本显示）
        if (state.themeId) {
            loadVersions();
        }
        if (state.themeId) {
            refreshDefaultInjectionApplications({ render: false, silent: true });
        }
        if (state.themeId) {
            setConfigMode('layout');
            loadLayoutConfig({ silent: true });
        }

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

    function setI18nPanelOpen(panel, trigger, open) {
        if (!(panel instanceof HTMLElement)) return;
        panel.hidden = !open;
        panel.dataset.state = open ? 'open' : 'closed';
        panel.setAttribute('aria-hidden', String(!open));
        if (trigger instanceof HTMLElement) {
            trigger.setAttribute('aria-expanded', String(open));
            trigger.classList.toggle('active', open);
        }
    }

    /**
     * 绑定事件
     */
    function bindEvents() {
        document.addEventListener('click', function(e) {
            const actionButton = e.target.closest('[data-theme-editor-action]');
            if (!actionButton) {
                return;
            }
            const action = actionButton.getAttribute('data-theme-editor-action') || '';
            if (action === 'set-interaction-mode') {
                e.preventDefault();
                setInteractionMode(actionButton.getAttribute('data-interaction-mode') || 'edit');
            } else if (action === 'set-selection-target') {
                e.preventDefault();
                if (isPreviewInteractionMode()) {
                    return;
                }
                setSelectionTarget(actionButton.getAttribute('data-selection-target') || 'default');
            } else if (action === 'toggle-link-block') {
                e.preventDefault();
                if (isPreviewInteractionMode()) {
                    return;
                }
                setLinkBlockEnabled(!(state.linkBlockEnabled === true));
            } else if (action === 'open-reset-draft-modal') {
                e.preventDefault();
                openResetDraftModal();
            } else if (action === 'open-config-panel') {
                e.preventDefault();
                if (isPreviewInteractionMode()) {
                    return;
                }
                setSidePanelOpen('config', state.sidePanels.configOpen !== true);
            } else if (action === 'close-config-panel') {
                e.preventDefault();
                setSidePanelOpen('config', false);
            } else if (action === 'open-widget-panel') {
                e.preventDefault();
                if (isPreviewInteractionMode()) {
                    return;
                }
                setSidePanelOpen('widget', state.sidePanels.widgetOpen !== true);
            } else if (action === 'close-widget-panel') {
                e.preventDefault();
                setSidePanelOpen('widget', false);
            } else if (action === 'toggle-slots-panel') {
                e.preventDefault();
                if (isPreviewInteractionMode()) {
                    return;
                }
                setSlotsPanelOpen(state.slotsPanelOpen !== true);
            } else if (action === 'close-slots-panel') {
                e.preventDefault();
                setSlotsPanelOpen(false);
            } else if (action === 'save-new-version') {
                e.preventDefault();
                elements.btnSave?.click();
            }
        });

        const versionPopoverRoot = document.querySelector('.version-selector-wrapper[data-w-component~="popover"]');
        if (versionPopoverRoot instanceof HTMLElement) {
            versionPopoverRoot.addEventListener('weline:ui:popover:open', function() {
                state.versionPanelOpen = true;
                loadVersions({ notifyOnError: true });
            });
            versionPopoverRoot.addEventListener('weline:ui:popover:close', function() {
                state.versionPanelOpen = false;
            });
        }

        if (elements.configModeLayout) {
            elements.configModeLayout.addEventListener('click', function() {
                setConfigMode('layout');
                loadLayoutConfig();
            });
        }
        if (elements.configModeWidget) {
            elements.configModeWidget.addEventListener('click', function() {
                setConfigMode('widget');
                showWidgetConfigState();
            });
        }
        if (elements.editorLangSwitcher) {
            elements.editorLangSwitcher.addEventListener('change', function() {
                handleConfigLocaleSwitcherChange(this);
            });
        }
        const delegatedLocaleChangeHandler = function(e) {
            const langSwitcher = e.target && e.target.closest ? e.target.closest('#configLangSwitcher') : null;
            if (!langSwitcher) {
                return;
            }
            handleConfigLocaleSwitcherChange(langSwitcher);
        };
        document.addEventListener('change', delegatedLocaleChangeHandler, true);
        document.addEventListener('input', delegatedLocaleChangeHandler, true);
        // Scope is the root selector. Any change performs a typed, full-context reload.
        if (elements.scopeSelect) {
            elements.scopeSelect.addEventListener('change', function() {
                Promise.resolve(switchScope(this.value)).catch((error) => {
                    console.error('[ThemeEditor] Scope switch failed:', error);
                    restoreScopeSelector(state.layoutIdentity?.scope || storageScopeForIdentity(state.scopeIdentity));
                    showToast(error?.message || translateUiText('Scope 切换失败'), 'error');
                });
            });
        }

        // Theme selection is a scoped draft binding; runtime changes only after publish.
        if (elements.themeSelect) {
            elements.themeSelect.addEventListener('change', async function() {
                const themeId = this.value;
                if (themeId) {
                    const previousThemeId = state.themeId;
                    try {
                        await flushPendingEditorMutations();
                    } catch (error) {
                        this.value = String(previousThemeId || '');
                        showToast(error?.message || translateUiText('当前修改保存失败，已停留在原主题'), 'error');
                        return;
                    }
                    showPreviewLoadingImmediate();
                    state.themeId = parseInt(themeId, 10) || 0;
                    try {
                        await queueScopedChanges('theme_binding', [{ op: 'set', path: '/theme_id', value: state.themeId }], {
                            summary: 'theme_binding_changed',
                        });
                        await refreshLayoutOptions({ layout_option: '', silent: true });
                    } catch (error) {
                        console.error('[ThemeEditor] refresh layout options error:', error);
                        state.themeId = previousThemeId;
                        elements.themeSelect.value = String(previousThemeId || '');
                        showToast(error?.message || translateUiText('主题草稿保存失败'), 'error');
                        return;
                    }
                    syncEditorUrlState({
                        theme_id: state.themeId,
                        page_type: getCurrentPageType(),
                        layout_option: state.layoutOption || 'default',
                        preview_area: state.editorArea || 'frontend',
                    });
                    loadLayoutPreview();
                    loadLayoutConfig({ silent: true });
                    loadVersions();
                    reloadWidgetLibrary({ silent: true });
                }
            });
        }
        if (elements.themeBindingInherit) {
            elements.themeBindingInherit.addEventListener('click', async function() {
                const previousThemeId = state.themeId;
                try {
                    await flushPendingEditorMutations();
                    showPreviewLoadingImmediate();
                    const workspace = await queueScopedChanges('theme_binding', [{ op: 'inherit', path: '/theme_id' }], {
                        summary: 'theme_binding_inherit',
                    });
                    const inheritedThemeId = parseInt(workspace?.draft_payload?.theme_id || 0, 10) || 0;
                    if (inheritedThemeId > 0) {
                        state.themeId = inheritedThemeId;
                        elements.themeSelect.value = String(inheritedThemeId);
                    }
                    await refreshLayoutOptions({ layout_option: '', silent: true });
                    syncEditorUrlState({
                        theme_id: state.themeId,
                        page_type: getCurrentPageType(),
                        layout_option: state.layoutOption || 'default',
                        preview_area: state.editorArea || 'frontend',
                    });
                    loadLayoutPreview();
                    loadLayoutConfig({ silent: true });
                    loadVersions();
                    reloadWidgetLibrary({ silent: true });
                    showToast(translateUiText('主题已恢复继承（发布后生效）'), 'success');
                } catch (error) {
                    state.themeId = previousThemeId;
                    elements.themeSelect.value = String(previousThemeId || '');
                    showToast(error?.message || translateUiText('恢复主题继承失败'), 'error');
                }
            });
        }

        // 编辑区域是 Theme 的直接上游。切换时完成旧上下文增量并完整重载全部下游状态。
        if (elements.editorAreaSelect) {
            elements.editorAreaSelect.addEventListener('change', async function() {
                const area = this.value;
                const previousArea = state.editorArea || 'frontend';
                if (!area || area === previousArea) return;
                if (state.saveInProgress) {
                    this.value = previousArea;
                    showToast(translateUiText('当前修改仍在保存，请稍后再切换编辑区域'), 'warning');
                    return;
                }
                try {
                    await flushPendingEditorMutations();
                } catch (error) {
                    this.value = previousArea;
                    showToast(error?.message || translateUiText('当前修改保存失败，已停留在原编辑区域'), 'error');
                    return;
                }
                if (state.lockHeld && !(await releaseCurrentEditorLock())) {
                    this.value = previousArea;
                    showToast(translateUiText('旧编辑区域锁释放失败，已停留在原编辑区域'), 'error');
                    return;
                }
                showPreviewLoadingImmediate();
                navigateEditorShell({
                    editor_area: area,
                    preview_area: area,
                    theme_id: null,
                    frontend_theme_id: null,
                    backend_theme_id: null,
                    page_type: null,
                    layout_option: null,
                    version_id: null,
                });
            });
        }

        // 页面类型选择（Query 切换，不刷新页面）
        if (elements.pageTypeSelect) {
            elements.pageTypeSelect.addEventListener('change', async function() {
                const pageType = this.value;
                if (state.themeId && pageType) {
                    const previousPageType = state.pageType;
                    const previousLayoutOption = state.layoutOption;
                    try {
                        await flushPendingEditorMutations();
                    } catch (error) {
                        this.value = previousPageType;
                        showToast(error?.message || translateUiText('当前修改保存失败，已停留在原布局类型'), 'error');
                        return;
                    }
                    showPreviewLoadingImmediate();
                    setCurrentLayoutSelection(pageType, '');
                    initWidgetLibraryTabs();
                    try {
                        await refreshLayoutOptions({ layout_type: pageType, layout_option: '', silent: true });
                    } catch (error) {
                        console.error('[ThemeEditor] refresh layout options error:', error);
                        setCurrentLayoutSelection(previousPageType, previousLayoutOption);
                        this.value = previousPageType;
                        showToast(error?.message || translateUiText('布局类型切换失败，已恢复原布局'), 'error');
                        return;
                    }
                    syncEditorUrlState({
                        theme_id: state.themeId,
                        page_type: pageType,
                        layout_option: state.layoutOption || 'default',
                        version_id: null,
                    });
                    loadLayoutPreview();
                    loadLayoutConfig({ silent: true });
                    loadVersions();
                    reloadWidgetLibrary({ silent: true });
                    showToast(__('已切换到: ') + this.options[this.selectedIndex].text, 'info');
                }
            });
        }

        if (elements.layoutOptionSelect) {
            elements.layoutOptionSelect.addEventListener('click', function() {
                if (this.dataset.singleOption === '1') {
                    showToast(translateUiText('当前布局类型只有一个布局选项'), 'info');
                }
            });

            elements.layoutOptionSelect.addEventListener('change', async function() {
                const layoutOption = normalizeLayoutOptionValue(this.value) || 'default';
                if (!state.themeId || !layoutOption) {
                    return;
                }
                const previousLayoutOption = state.layoutOption;
                try {
                    await flushPendingEditorMutations();
                } catch (error) {
                    this.value = previousLayoutOption;
                    showToast(error?.message || translateUiText('当前修改保存失败，已停留在原布局选项'), 'error');
                    return;
                }
                showPreviewLoadingImmediate();
                state.layoutOption = layoutOption;
                renderLayoutOptionSelect(state.layoutType, state.layoutOption);
                syncEditorUrlState({
                    theme_id: state.themeId,
                    page_type: getCurrentPageType(),
                    layout_option: state.layoutOption,
                    version_id: null,
                });
                try {
                    await saveLayoutSelection();
                } catch (error) {
                    console.error('[ThemeEditor] save layout option error:', error);
                    state.layoutOption = previousLayoutOption;
                    renderLayoutOptionSelect(state.layoutType, previousLayoutOption);
                    showToast(error.message || translateUiText('布局选项切换失败，已恢复原布局'), 'error');
                    return;
                }
                loadLayoutPreview();
                loadLayoutConfig({ silent: true });
                refreshDefaultInjectionApplications({ render: state.widgetLibraryTab === 'applications', silent: true });
            });
        }

        // 关闭插槽面板按钮已改为 data-theme-editor-action=close-slots-panel，由统一 action 处理器接管

        // 部件搜索：服务端关键词检索（在当前插槽过滤条件内），重置分页
        if (elements.widgetSearch) {
            elements.widgetSearch.addEventListener('input', debounce(function() {
                const lib = getWidgetLibState();
                lib.keyword = (this.value || '').trim();
                loadWidgetLibrary({ reset: true, silent: true });
            }, 350));
        }

        if (elements.widgetLibraryTabs) {
            elements.widgetLibraryTabs.addEventListener('click', function(e) {
                const tabButton = e.target.closest('[data-widget-library-tab]');
                if (!tabButton) {
                    return;
                }
                e.preventDefault();
                setWidgetLibraryTab(tabButton.getAttribute('data-widget-library-tab') || 'general');
            });
        }

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.w-theme-editor-apply-default-injection');
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            applyDefaultInjection(
                btn.getAttribute('data-injection-key') || '',
                btn,
                btn.getAttribute('data-apply-scope') || 'current'
            );
        });

	        // 组件预览按钮（部件库列表中的「预览」）
	        document.addEventListener('click', function(e) {
	            const btn = e.target.closest('.w-theme-editor-preview-component');
	            if (!btn) return;
	            e.preventDefault();
            e.stopPropagation();
            const module = btn.dataset.widgetModule || '';
            const code = btn.dataset.widgetCode || '';
	            const name = btn.dataset.widgetName || '';
	            if (module && code) openComponentPreviewModal(module, code, name);
	        });

	        // 部件添加按钮（部件库列表中的「添加到当前插槽」）
	        document.addEventListener('click', function(e) {
	            const btn = e.target.closest('.w-theme-editor-add-component');
	            if (!btn) return;
		            e.preventDefault();
		            e.stopPropagation();
		            const item = btn.closest('.widget-item[data-widget-code]');
                    if (!isWidgetLibraryItemActive(item)) {
                        showToast(translateUiText('请先切换到对应部件分类'), 'warning');
                        return;
                    }
		            addWidgetFromLibraryItem(item);
		        });

        document.addEventListener('click', function(e) {
            const clearImageBtn = e.target.closest('[data-config-image-clear]');
            if (clearImageBtn) {
                e.preventDefault();
                const fieldId = clearImageBtn.getAttribute('data-target') || '';
                const field = fieldId ? document.getElementById(fieldId) : null;
                if (field) {
                    field.value = '';
                }
                clearImageBtn.closest('.image-preview-container')?.remove();
                return;
            }

            const groupTitle = e.target.closest('[data-config-group-toggle]');
            if (groupTitle) {
                groupTitle.closest('.config-group')?.classList.toggle('collapsed');
                return;
            }

            const slotInfoItem = e.target.closest('.slot-info-item[data-slot-id]');
            if (slotInfoItem) {
                e.preventDefault();
                scrollToSlot(slotInfoItem.getAttribute('data-slot-id') || '');
                return;
            }

            const versionButton = e.target.closest('[data-version-action][data-version-id]');
            if (versionButton) {
                e.preventDefault();
                const versionId = parseInt(versionButton.getAttribute('data-version-id') || '', 10);
                if (!Number.isFinite(versionId) || versionId <= 0) {
                    return;
                }
                const action = versionButton.getAttribute('data-version-action');
                if (action === 'preview') {
                    previewVersion(versionId);
                } else if (action === 'switch') {
                    switchToVersion(versionId);
                } else if (action === 'delete') {
                    deleteVersion(versionId);
                }
            }
        });

        // 部件分组折叠/展开
        document.querySelectorAll('.widget-group-header').forEach(header => {
            header.addEventListener('click', function(e) {
                // 如果点击的是分组内的部件项，不触发折叠
                if (e.target.closest('.widget-item')) {
                    return;
                }
                const group = this.closest('.widget-group');
                if (group) toggleWidgetGroup(group);
            });
        });

        // 点击预览区的部件或区域
        document.addEventListener('click', function(e) {
            if (e.target.closest('.w-theme-editor-edit-widget')) {
                e.stopPropagation();
                const widget = e.target.closest('.preview-widget-item');
                if (widget) {
                    openConfigModal(widget);
                }
                return;
            }

            if (e.target.closest('.w-theme-editor-delete-widget')) {
                e.stopPropagation();
                const widget = e.target.closest('.preview-widget-item');
                if (widget) {
                    deleteWidget(widget);
                }
                return;
            }

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
            if (e.target.closest('.w-theme-editor-edit-widget')) {
                e.stopPropagation();
                const widget = e.target.closest('.preview-widget-item');
                if (widget) {
                    openConfigModal(widget);
                }
                return;
            }

            // 删除按钮
            if (e.target.closest('.w-theme-editor-delete-widget')) {
                const widget = e.target.closest('.preview-widget-item');
                if (widget) {
                    deleteWidget(widget);
                }
                return;
            }
        });

        // 关闭配置面板
        document.getElementById('closeConfigPanel')?.addEventListener('click', function(event) {
            event.preventDefault();
            setSidePanelOpen('config', false);
        });

        // 保存按钮
        elements.btnSave?.addEventListener('click', saveLayout);

        // 发布按钮
        elements.btnPublish?.addEventListener('click', publishTheme);

        // 预览按钮
        elements.btnPreview?.addEventListener('click', openPreview);

        // 前端预览按钮
        elements.btnFrontendPreview?.addEventListener('click', openFrontendPreview);

        // FormRenderer 默认 GET：取消 prepare-submit，避免未拦截时整页导航
        document.addEventListener('weline:form:prepare-submit', function(e) {
            const form = e.detail?.form || e.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            const isWidgetConfigForm = form.id === 'widgetConfigForm'
                || form.classList.contains('w-param-form')
                || form.classList.contains('widget-accordion-config-form')
                || form.classList.contains('config-form');
            if (isWidgetConfigForm) {
                e.preventDefault();
            }
        });

        // 配置表单提交（左侧面板显式「保存配置」）
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            const isWidgetConfigForm = form.id === 'widgetConfigForm'
                || form.classList.contains('w-param-form')
                || form.classList.contains('widget-accordion-config-form')
                || form.classList.contains('config-form');
            if (!isWidgetConfigForm) {
                return;
            }
            e.preventDefault();
            if (form.id === 'widgetConfigForm' || form.dataset.layoutId || form.dataset.templateRef) {
                Promise.resolve(saveWidgetConfig(form)).catch(() => undefined);
            }
        });

        // 左侧配置面板 i18n 事件委托（覆盖 renderConfigForm 生成的表单）
        if (elements.configContent) {
            elements.configContent.addEventListener('click', async function(e) {
                const i18nBtn = e.target.closest('.w-param-btn-i18n, .w-theme-editor-i18n-edit');
                if (i18nBtn) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    const fieldKey = i18nBtn.dataset.field;
                    const layoutId = i18nBtn.dataset.layoutId;
                    const panelId = 'i18n_panel_' + layoutId + '_' + fieldKey.replace(/\./g, '_');
                    const panel = document.getElementById(panelId) || i18nBtn.closest('.w-param-field, .config-field, .translatable-field')?.querySelector('.w-param-i18n-panel, .i18n-edit-panel');
                    if (!panel) return;
                    if (panel.hidden) {
                        setI18nPanelOpen(panel, i18nBtn, true);
                        await loadI18nValues(layoutId, fieldKey, panel);
                    } else {
                        setI18nPanelOpen(panel, i18nBtn, false);
                    }
                    return;
                }
                const closeBtn = e.target.closest('[data-close-i18n], .w-theme-editor-i18n-close');
                if (closeBtn) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    const panel = closeBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                    if (panel) {
                        const fieldKey = closeBtn.dataset.field || panel.dataset.field;
                        const trigger = elements.configContent.querySelector(`.w-param-btn-i18n[data-field="${fieldKey}"], .w-theme-editor-i18n-edit[data-field="${fieldKey}"]`);
                        setI18nPanelOpen(panel, trigger, false);
                    }
                    return;
                }
                const aiBtn = e.target.closest('[data-ai-i18n], .w-theme-editor-ai-i18n');
                if (aiBtn) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    const panel = aiBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                    const fieldKey = aiBtn.dataset.field || panel?.dataset.field;
                    // TE-CAP-020: template widgets may have empty layout_id; AI translate only needs source text.
                    const layoutId = aiBtn.dataset.layoutId || panel?.dataset.layoutId || '';
                    if (panel && fieldKey) {
                        await translateI18nValues(layoutId, fieldKey, panel, aiBtn);
                    } else {
                        showToast(translateUiText('无法定位多语言字段'), 'warning');
                    }
                    return;
                }
                const saveBtn = e.target.closest('[data-save-i18n], .w-theme-editor-save-i18n');
                if (saveBtn) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
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
            function scheduleWidgetConfigAutoSave(form) {
                const layoutId = form.dataset.layoutId;
                if (!layoutId) return;
                scheduleEditorAutoSave(
                    `widget-config:${layoutId}`,
                    () => saveWidgetConfig(form, true),
                );
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
                if (e.target.closest('a, input, select, textarea')) return;
                e.preventDefault();
                const group = wTitle.closest('.w-param-group');
                if (group) {
                    const ariaExpanded = wTitle.getAttribute('aria-expanded');
                    const currentlyExpanded = ariaExpanded === 'true'
                        || (ariaExpanded === null && !group.classList.contains('w-param-collapsed'));
                    setParamGroupExpanded(group, !currentlyExpanded);
                }
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
            if (e.target.classList.contains('w-theme-editor-color-input')) {
                const textInput = e.target.parentElement.querySelector('input[type="text"]');
                if (textInput) {
                    textInput.value = e.target.value;
                }
            }
        });

        // 配置面板删除按钮（事件委托）
        document.addEventListener('click', function(e) {
            const deleteBtn = e.target.closest('.w-theme-editor-delete-config');
            if (deleteBtn) {
                e.preventDefault();
                const layoutId = deleteBtn.dataset.layoutId;
                if (!layoutId) return;
                // 尝试从结构面板获取 slotId
                const structureItem = document.querySelector(`.preview-widget-item${dataLayoutIdSelector(layoutId)}`);
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
                cancelPreviewDragSession();
                if (elements.previewLoading) {
                    elements.previewLoading.classList.add('hidden');
                }

                // 预览 load 时若部件库尚未就绪则兜底初始化（正常路径已在 deferWidgetLibraryLoad 并行发起）
                initWidgetLibraryOnce();

                // 设置 iframe 内链接拦截，使链接跳转到预览模式
                setupIframeLinkInterception();

                // 初始化部件 hover 操作按钮（多档延迟以兼容异步渲染的布局）
                setTimeout(() => initWidgetHoverActions(), 100);
                setTimeout(() => initWidgetHoverActions(), 400);
                setTimeout(() => initWidgetHoverActions(), 1200);
                setTimeout(() => setInteractionMode(state.interactionMode || 'edit', {
                    force: true,
                    skipViewSwitch: true,
                    restorePanels: false,
                    syncUrl: false,
                }), 50);
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

        if (elements.btnResetDraftConfirm) {
            elements.btnResetDraftConfirm.addEventListener('click', () => {
                executeResetDraftResources(false).catch((error) => {
                    console.error('[ThemeEditor] Reset draft error:', error);
                    showToast(translateUiText('重置失败：') + (error?.message || ''), 'error');
                });
            });
        }
        if (elements.btnResetDraftAllResources) {
            elements.btnResetDraftAllResources.addEventListener('click', () => {
                executeResetDraftResources(true).catch((error) => {
                    console.error('[ThemeEditor] Reset all draft error:', error);
                    showToast(translateUiText('重置失败：') + (error?.message || ''), 'error');
                });
            });
        }
        if (elements.btnResetDraftCancel && elements.resetDraftModal) {
            elements.btnResetDraftCancel.addEventListener('click', () => closeResetDraftModal());
        }

        // 刷新预览按钮
        if (elements.btnRefreshPreview) {
            elements.btnRefreshPreview.addEventListener('click', refreshPreview);
        }

        // 全屏预览按钮
        if (elements.btnFullscreenPreview) {
            elements.btnFullscreenPreview.addEventListener('click', function() {
                toggleEditorFullscreen().catch(function(error) {
                    console.warn('[ThemeEditor] fullscreen toggle failed:', error);
                    showToast(translateUiText('无法进入全屏编辑'), 'warning');
                });
            });
        }

        document.addEventListener('fullscreenchange', handleEditorFullscreenChanged);
        document.addEventListener('webkitfullscreenchange', handleEditorFullscreenChanged);
        document.addEventListener('mozfullscreenchange', handleEditorFullscreenChanged);
        document.addEventListener('MSFullscreenChange', handleEditorFullscreenChanged);
        document.addEventListener('keydown', handlePreviewDragCancel, true);

        // 监听 iframe 消息（预览页面与编辑器通信）
        window.addEventListener('message', handleIframeMessage);
    }

    function syncEditorUrlState(overrides = {}) {
        const url = getCurrentWindowUrl();
        const params = Object.assign({
            theme_id: state.themeId || 0,
            page_type: getCurrentPageType(),
            layout_option: state.layoutOption || 'default',
            editor_area: state.editorArea || 'frontend',
            preview_area: state.editorArea || 'frontend',
            status: state.previewStatus || 'draft',
            interaction_mode: normalizeInteractionMode(state.interactionMode || 'edit'),
            selection_target: normalizeSelectionTarget(state.selectionTarget || 'default'),
            link_block: state.linkBlockEnabled === true ? '1' : null,
        }, overrides || {});
        if (Object.prototype.hasOwnProperty.call(params, 'interaction_mode')) {
            params.interaction_mode = normalizeInteractionMode(params.interaction_mode);
        }
        if (Object.prototype.hasOwnProperty.call(params, 'selection_target')) {
            const target = normalizeSelectionTarget(params.selection_target);
            params.selection_target = target === 'default' ? null : target;
        }
        if (Object.prototype.hasOwnProperty.call(params, 'link_block')) {
            params.link_block = normalizeLinkBlockEnabled(params.link_block) ? '1' : null;
        }

        Object.entries(params).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') {
                url.searchParams.delete(key);
                return;
            }
            url.searchParams.set(key, String(value));
        });

        url.searchParams.set('_t', String(Date.now()));
        window.history.replaceState({}, '', url.toString());
    }

    function showPreviewLoadingImmediate() {
        if (elements.previewLoading) {
            elements.previewLoading.classList.remove('hidden');
        }
    }

    /**
     * 默认 PC 设计视口宽度（部件在此宽度下布局后整体缩放填满 canvas）
     */
    const WIDGET_PREVIEW_DESIGN_WIDTH = 1200;
    let widgetPreviewFitObserver = null;

    function measureWidgetPreviewContentHeight(inner) {
        if (!inner) {
            return 72;
        }
        let maxHeight = 0;
        inner.childNodes.forEach(function (node) {
            if (!node || node.nodeType !== 1) {
                return;
            }
            const el = /** @type {HTMLElement} */ (node);
            if (el.matches('style, script')) {
                return;
            }
            const height = Math.max(el.offsetHeight || 0, el.scrollHeight || 0);
            if (height > maxHeight) {
                maxHeight = height;
            }
        });
        if (maxHeight > 0) {
            return Math.max(40, Math.min(maxHeight, 600));
        }
        const raw = inner.scrollHeight || 0;
        return Math.max(40, Math.min(raw || 72, 600));
    }

    function initWidgetPreviewFitObserver() {
        const panel = elements.widgetPanel;
        if (!panel || panel.dataset.previewFitObserver === '1') {
            return;
        }
        panel.dataset.previewFitObserver = '1';
        if (typeof ResizeObserver === 'undefined') {
            return;
        }
        widgetPreviewFitObserver = new ResizeObserver(function () {
            scheduleFitWidgetPreviews();
        });
        widgetPreviewFitObserver.observe(panel);
    }

    function scheduleFitWidgetPreviews() {
        fitWidgetPreviews();
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(function () {
                fitWidgetPreviews();
            });
        }
    }

    function isWidgetPreviewFallbackHtml(previewHtml) {
        const html = String(previewHtml || '').trim();
        return html === ''
            || html.includes('widget-preview-placeholder')
            || html.includes('widget-preview-error');
    }

    function isWidgetPreviewFallbackCanvas(canvas) {
        if (!canvas) {
            return false;
        }
        return String(canvas.innerHTML || '').trim() === ''
            || !!canvas.querySelector('.widget-preview-placeholder, .widget-preview-error');
    }

    function normalizeWidgetPreviewCode(widgetCode) {
        const parts = String(widgetCode || '').replace(/\\/g, '/').toLowerCase().split('/').filter(Boolean);
        return parts.length ? parts[parts.length - 1] : '';
    }

    function isBasicThemeComponentPreviewCode(widgetCode) {
        return [
            'alert',
            'badge',
            'button',
            'card',
            'dropdown',
            'form-group',
            'loading',
            'message',
            'modal',
            'pagination',
            'section',
            'grid',
            'text',
            'image',
            'divider',
            'spacer',
            'table',
            'tabs'
        ].includes(normalizeWidgetPreviewCode(widgetCode));
    }

    function getPreviewHtmlText(html) {
        const probe = document.createElement('div');
        probe.innerHTML = sanitizeHtmlForEditorPreview(String(html || ''));
        probe.querySelectorAll('style, script').forEach((node) => node.remove());
        return String(probe.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function buildClientComponentPreviewHtml(widgetCode, widgetName) {
        const code = normalizeWidgetPreviewCode(widgetCode);
        const escName = escapeHtml(widgetName || widgetCode || '');
        const text = {
            alertTitle: translateUiText('系统提示'),
            alertBody: translateUiText('这是一条状态提示信息。'),
            enabled: translateUiText('已启用'),
            backend: translateUiText('后台'),
            primary: translateUiText('主要操作'),
            secondary: translateUiText('次要'),
            cardTitle: translateUiText('数据卡片'),
            cardSubtitle: translateUiText('用于组织内容'),
            cardBody: translateUiText('这里展示核心信息、摘要或操作入口。'),
            action: translateUiText('选择操作'),
            detail: translateUiText('查看详情'),
            copy: translateUiText('复制视图'),
            metric: translateUiText('指标'),
            status: translateUiText('状态'),
            trend: translateUiText('趋势'),
            order: translateUiText('订单'),
            ok: translateUiText('正常'),
            payment: translateUiText('支付'),
            stable: translateUiText('稳定'),
            label: translateUiText('字段名称'),
            placeholder: translateUiText('请输入内容'),
            username: translateUiText('管理员'),
            syncing: translateUiText('正在同步数据'),
            messageTitle: translateUiText('操作完成'),
            messageBody: translateUiText('数据已保存，可以继续编辑。'),
            modalTitle: translateUiText('确认发布'),
            modalBody: translateUiText('公开后同站点后台用户可见。'),
            cancel: translateUiText('取消'),
            confirm: translateUiText('确认'),
            previous: translateUiText('上一页'),
            next: translateUiText('下一页'),
            tabOverview: translateUiText('概览'),
            tabSettings: translateUiText('设置'),
            sectionTitle: translateUiText('精选区块'),
            sectionSubtitle: translateUiText('Section'),
            sectionBody: translateUiText('拖入卡片、文本、图片或表单组件'),
            gridItem: translateUiText('栅格项'),
            textTitle: translateUiText('清晰表达你的页面主张'),
            textBody: translateUiText('用标题和段落快速搭建页面内容。'),
            imageAlt: translateUiText('图片媒体'),
            dividerLabel: translateUiText('内容分隔'),
            spacerLabel: translateUiText('留白间距'),
        };

        if (code === 'alert') {
            return '<div class="te-component-preview te-component-preview-alert"><div class="w-alert" data-tone="info" role="alert">' + iconSvg('info') + '<strong>'
                + escapeHtml(text.alertTitle) + '</strong><span>' + escapeHtml(text.alertBody) + '</span></div></div>';
        }
        if (code === 'badge') {
            return '<div class="te-component-preview te-component-preview-badge"><span class="w-badge" data-tone="success">'
                + escapeHtml(text.enabled) + '</span><span class="w-badge" data-tone="info">' + escapeHtml(text.backend) + '</span></div>';
        }
        if (code === 'button') {
            return '<div class="te-component-preview te-component-preview-button"><button type="button" class="w-button" data-tone="primary">' + iconSvg('bolt') + ' '
                + escapeHtml(text.primary) + '</button><button type="button" class="w-button" data-tone="neutral" data-size="sm">' + escapeHtml(text.secondary) + '</button></div>';
        }
        if (code === 'card') {
            return '<div class="te-component-preview te-component-preview-card"><div class="w-card"><div class="w-card__header"><h3 class="w-card__title">'
                + escapeHtml(text.cardTitle) + '</h3><p class="w-card__subtitle">' + escapeHtml(text.cardSubtitle)
                + '</p></div><div class="w-card__body">' + escapeHtml(text.cardBody) + '</div></div></div>';
        }
        if (code === 'dropdown') {
            return '<div class="te-component-preview te-component-preview-dropdown"><div class="w-theme-editor-menu-preview"><button type="button" class="w-button" data-tone="neutral">'
                + escapeHtml(text.action) + '</button><div class="w-menu"><a class="w-menu__item" href="#">'
                + escapeHtml(text.detail) + '</a><a class="w-menu__item" href="#">' + escapeHtml(text.copy) + '</a></div></div></div>';
        }
        if (code === 'section') {
            return '<div class="te-component-preview te-component-preview-section"><section class="w-builder-section w-builder-section--subtle"><header class="w-builder-section__header"><p class="w-builder-section__eyebrow">'
                + escapeHtml(text.sectionSubtitle) + '</p><h2 class="w-builder-section__title">' + escapeHtml(text.sectionTitle)
                + '</h2><p class="w-builder-section__description">' + escapeHtml(text.sectionBody) + '</p></header><div class="w-builder-slot-placeholder">'
                + escapeHtml(text.sectionBody) + '</div></section></div>';
        }
        if (code === 'grid') {
            return '<div class="te-component-preview te-component-preview-grid"><div class="w-builder-grid w-theme-editor-preview-grid"><div class="w-card"><div class="w-card__body">'
                + escapeHtml(text.gridItem) + ' 1</div></div><div class="w-card"><div class="w-card__body">' + escapeHtml(text.gridItem)
                + ' 2</div></div><div class="w-card"><div class="w-card__body">' + escapeHtml(text.gridItem) + ' 3</div></div></div></div>';
        }
        if (code === 'text') {
            return '<div class="te-component-preview te-component-preview-text"><div class="w-builder-text w-builder-text--sm"><p class="w-builder-text__eyebrow">Text</p><h2 class="w-builder-text__title">'
                + escapeHtml(text.textTitle) + '</h2><p class="w-builder-text__body">' + escapeHtml(text.textBody) + '</p></div></div>';
        }
        if (code === 'image') {
            return '<div class="te-component-preview te-component-preview-image"><figure class="w-builder-image w-builder-image--16x9"><div class="w-builder-image__placeholder">'
                + escapeHtml(text.imageAlt) + '</div></figure></div>';
        }
        if (code === 'divider') {
            return '<div class="te-component-preview te-component-preview-divider"><div class="w-builder-divider"><span class="w-builder-divider__label">'
                + escapeHtml(text.dividerLabel) + '</span></div></div>';
        }
        if (code === 'spacer') {
            return '<div class="te-component-preview te-component-preview-spacer"><div class="w-builder-spacer w-builder-spacer--md"></div><span>'
                + escapeHtml(text.spacerLabel) + '</span></div>';
        }
        if (code === 'form-group') {
            return '<div class="te-component-preview te-component-preview-form-group"><label class="w-field__label">'
                + escapeHtml(text.label) + '</label><input class="w-input" type="text" value="' + escapeHtml(text.username)
                + '" placeholder="' + escapeHtml(text.placeholder) + '"></div>';
        }
        if (code === 'loading') {
            return '<div class="te-component-preview te-component-preview-loading"><span class="w-spinner" aria-hidden="true"></span><span>'
                + escapeHtml(text.syncing) + '</span></div>';
        }
        if (code === 'message') {
            return '<div class="te-component-preview te-component-preview-message"><div class="w-alert" data-tone="success">' + iconSvg('check-circle') + '<div><strong>'
                + escapeHtml(text.messageTitle) + '</strong><span>' + escapeHtml(text.messageBody) + '</span></div></div></div>';
        }
        if (code === 'modal') {
            return '<div class="te-component-preview te-component-preview-modal"><div class="w-theme-editor-dialog-preview"><div class="w-theme-editor-dialog-preview__header"><strong>'
                + escapeHtml(text.modalTitle) + '</strong><span aria-hidden="true">×</span></div><div class="w-theme-editor-dialog-preview__body">'
                + escapeHtml(text.modalBody) + '</div><div class="w-theme-editor-dialog-preview__footer"><button type="button" class="w-button" data-tone="neutral" data-size="sm">'
                + escapeHtml(text.cancel) + '</button><button type="button" class="w-button" data-tone="primary" data-size="sm">'
                + escapeHtml(text.confirm) + '</button></div></div></div>';
        }
        if (code === 'pagination') {
            return '<div class="te-component-preview te-component-preview-pagination"><nav class="w-pagination"><a href="#">'
                + escapeHtml(text.previous) + '</a><a href="#">1</a><a class="active" href="#">2</a><a href="#">3</a><a href="#">'
                + escapeHtml(text.next) + '</a></nav></div>';
        }
        if (code === 'table') {
            return '<div class="te-component-preview te-component-preview-table"><table class="w-table w-table-striped"><thead><tr><th>'
                + escapeHtml(text.metric) + '</th><th>' + escapeHtml(text.status) + '</th><th>' + escapeHtml(text.trend)
                + '</th></tr></thead><tbody><tr><td>' + escapeHtml(text.order) + '</td><td>' + escapeHtml(text.ok)
                + '</td><td>+12%</td></tr><tr><td>' + escapeHtml(text.payment) + '</td><td>' + escapeHtml(text.stable)
                + '</td><td>+4%</td></tr></tbody></table></div>';
        }
        if (code === 'tabs') {
            return '<div class="te-component-preview te-component-preview-tabs"><div class="w-tabs"><div class="w-tab-list"><button type="button" class="active">'
                + escapeHtml(text.tabOverview) + '</button><button type="button">' + escapeHtml(text.tabSettings)
                + '</button></div><div class="w-tab-panel">' + escapeHtml(text.cardBody) + '</div></div></div>';
        }

        return '<div class="te-component-preview te-component-preview-generic"><strong>' + escName + '</strong><span>' + escapeHtml(widgetCode || '') + '</span></div>';
    }

    function normalizeWidgetCanvasPreview(canvas) {
        if (!canvas) {
            return false;
        }
        const item = canvas.closest?.('.widget-item[data-widget-code]');
        const widgetCode = canvas.dataset.widgetCode || item?.dataset?.widgetCode || item?.getAttribute?.('data-widget-code') || '';
        if (!isBasicThemeComponentPreviewCode(widgetCode)) {
            return false;
        }
        if (widgetCode && !canvas.dataset.widgetCode) {
            canvas.dataset.widgetCode = widgetCode;
        }
        if (canvas.firstElementChild?.classList?.contains('te-component-preview')) {
            canvas.dataset.previewLoaded = '1';
            return true;
        }

        const widgetName = item?.dataset?.widgetName || widgetCode;
        const rawHtml = String(canvas.innerHTML || '').trim();
        const visibleText = getPreviewHtmlText(rawHtml);
        if (!rawHtml || !visibleText || isWidgetPreviewFallbackHtml(rawHtml)) {
            canvas.innerHTML = buildClientComponentPreviewHtml(widgetCode, widgetName);
        } else {
            canvas.innerHTML = '<div class="te-component-preview te-component-preview-' + escapeHtml(normalizeWidgetPreviewCode(widgetCode)) + '">' + rawHtml + '</div>';
        }
        canvas.dataset.previewLoaded = '1';
        return true;
    }

    function hydrateWidgetLibraryPreviews(root) {
        const scope = root || elements.widgetList || document;
        scope.querySelectorAll('.widget-preview-canvas').forEach((canvas) => {
            if (normalizeWidgetCanvasPreview(canvas)) {
                scheduleFitWidgetPreviews();
                return;
            }
            const item = canvas.closest?.('.widget-item[data-widget-code]');
            const widgetCode = canvas.dataset.widgetCode || item?.dataset?.widgetCode || item?.getAttribute?.('data-widget-code') || '';
            const widgetName = item?.dataset?.widgetName || widgetCode;
            if (isWidgetPreviewFallbackCanvas(canvas)) {
                // Loading the library must stay local: per-widget rendering is an
                // explicit user action from the preview button, not an eager API fan-out.
                canvas.innerHTML = buildClientComponentPreviewHtml(widgetCode, widgetName);
                canvas.dataset.previewLoaded = '1';
            }
            scheduleFitWidgetPreviews();
        });
    }

    function mountWidgetPreviewHtml(canvas, previewHtml) {
        if (!canvas) {
            return;
        }
        canvas.innerHTML = '';
        const html = sanitizeHtmlForEditorPreview(previewHtml).trim();
        if (!html) {
            return;
        }
        canvas.insertAdjacentHTML('beforeend', html);
        if (!isWidgetPreviewFallbackHtml(html)) {
            canvas.dataset.previewLoaded = '1';
        }
    }

    /**
     * 适配部件库预览：按默认 PC 视口缩放，使预览内容填满 widget-preview-canvas，避免大块灰色空白
     */
    function fitWidgetPreviews() {
        const canvases = document.querySelectorAll('.widget-preview-canvas');
        canvases.forEach(canvas => {
            let viewport = canvas.firstElementChild;
            if (!viewport) return;
            if (viewport.classList.contains('widget-preview-placeholder') || viewport.classList.contains('widget-preview-error')) return;
            if (viewport.classList.contains('te-component-preview')) {
                canvas.dataset.previewLoaded = '1';
                return;
            }

            let inner = null;
            if (viewport.classList.contains('widget-preview-viewport')) {
                inner = viewport.firstElementChild;
            } else {
                viewport = document.createElement('div');
                viewport.className = 'widget-preview-viewport';
                inner = document.createElement('div');
                while (canvas.firstChild) inner.appendChild(canvas.firstChild);
                viewport.appendChild(inner);
                canvas.appendChild(viewport);
            }

            inner.classList.add('w-theme-editor-preview-inner');
            viewport.style.setProperty('--w-theme-editor-preview-design-width', WIDGET_PREVIEW_DESIGN_WIDTH + 'px');
            viewport.style.setProperty('--w-theme-editor-preview-viewport-width', WIDGET_PREVIEW_DESIGN_WIDTH + 'px');
            viewport.style.setProperty('--w-theme-editor-preview-viewport-height', 'auto');
            viewport.style.setProperty('--w-theme-editor-preview-scale', '1');

            const canvasWidth = canvas.clientWidth;
            const canvasHeight = canvas.clientHeight;
            const contentHeight = measureWidgetPreviewContentHeight(inner);

            if (!canvasWidth || !canvasHeight) {
                canvas.dataset.previewFitPending = '1';
                return;
            }
            delete canvas.dataset.previewFitPending;

            const scale = Math.min(1, canvasWidth / WIDGET_PREVIEW_DESIGN_WIDTH, canvasHeight / contentHeight);
            if (!isFinite(scale) || scale <= 0) return;

            viewport.style.setProperty('--w-theme-editor-preview-viewport-width', (WIDGET_PREVIEW_DESIGN_WIDTH * scale) + 'px');
            viewport.style.setProperty('--w-theme-editor-preview-viewport-height', (contentHeight * scale) + 'px');
            viewport.style.setProperty('--w-theme-editor-preview-scale', String(scale));
        });
    }

    /**
     * 处理来自 iframe 的消息
     */
    function createPreviewDragSessionId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'weline-drag-' + window.crypto.randomUUID();
        }
        return 'weline-drag-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    }

    function clearPreviewDropCandidate(sessionId = '') {
        const candidate = state.previewDropCandidate;
        if (sessionId && candidate && candidate.sessionId !== sessionId) {
            return;
        }
        state.previewDropCandidate = null;
    }

    function previewSlotHasCapacity(slot, widgetData) {
        const exclusive = slot.exclusive === true
            || slot.exclusive === 'true'
            || widgetData.exclusive === true
            || isExclusiveSlot(slot.id, widgetData.code);
        if (exclusive) return true;

        const currentCount = Number.parseInt(slot.current_count, 10);
        const maxWidgets = Number.parseInt(slot.max, 10);
        const multiple = !(slot.multiple === false || slot.multiple === 'false');
        if (!multiple && Number.isFinite(currentCount) && currentCount >= 1) return false;
        return !(Number.isFinite(maxWidgets)
            && maxWidgets > 0
            && Number.isFinite(currentCount)
            && currentCount >= maxWidgets);
    }

    function rememberPreviewDropCandidate(data) {
        const sessionId = String(data?.session_id || '');
        const widgetData = state.draggingWidget || data?.widget;
        const slot = data?.slot;
        if (!sessionId
            || sessionId !== state.previewDragSessionId
            || !widgetData?.code
            || !slot?.id
            || !isSlotDataAccepted(slot, widgetData)
            || !previewSlotHasCapacity(slot, widgetData)) {
            return null;
        }

        const sortOrder = Number.parseInt(data.sort_order, 10);
        if (!Number.isFinite(sortOrder) || sortOrder < 0) {
            return null;
        }

        const placement = ['inside', 'before', 'after'].includes(data.placement)
            ? data.placement
            : 'inside';
        const candidate = {
            sessionId,
            slot,
            sortOrder,
            placement,
            referenceLayoutId: String(data.reference_layout_id || ''),
            updatedAt: Date.now(),
        };
        state.previewDropCandidate = candidate;
        return candidate;
    }

    function previewFrameContainsDragEnd(event) {
        const frame = elements.previewFrame;
        if (!frame) return false;

        const clientX = Number(event?.clientX);
        const clientY = Number(event?.clientY);
        if (!Number.isFinite(clientX) || !Number.isFinite(clientY) || (clientX === 0 && clientY === 0)) {
            return null;
        }

        const rect = frame.getBoundingClientRect();
        return clientX >= rect.left
            && clientX <= rect.right
            && clientY >= rect.top
            && clientY <= rect.bottom;
    }

    function shouldCommitPreviewDropOnDragEnd(event, sessionId) {
        const candidate = state.previewDropCandidate;
        if (state.previewDragCancelled
            || !candidate
            || candidate.sessionId !== sessionId
            || Date.now() - candidate.updatedAt > 750) {
            return false;
        }

        // Chromium 在跨 iframe dragend 时偶尔返回 (0, 0)。此时依赖 iframe 的边界清理和短 TTL；
        // 坐标有效时则必须仍位于预览 frame 内，防止拖出后误插入。
        return previewFrameContainsDragEnd(event) !== false;
    }

    async function commitPreviewDropFromMessage(data) {
        if (data?.missing_data) {
            showToast('无法获取部件数据，请重新拖拽', 'error');
            return null;
        }

        const sessionId = String(data?.session_id || state.previewDragSessionId || '');
        if (sessionId && state.previewDropCommittedSessionId === sessionId) {
            return null;
        }

        const widgetData = state.draggingWidget || data?.widget;
        const slot = data?.slot;
        const sortOrder = Number.parseInt(data?.sort_order, 10);
        if (!widgetData?.code || !slot?.id || !Number.isFinite(sortOrder) || sortOrder < 0) {
            return null;
        }
        if (!isSlotDataAccepted(slot, widgetData) || !previewSlotHasCapacity(slot, widgetData)) {
            if (data?.reason) {
                showToast(data.reason, 'warning');
            }
            return null;
        }

        state.previewDropCommittedSessionId = sessionId;
        state.previewDropCandidate = null;
        state.lastPreviewInsertSortOrder = sortOrder;
        return handleWidgetDropped(widgetData, slot, sortOrder);
    }

    async function commitPreviewDropCandidate(sessionId) {
        if (!sessionId || state.previewDropCommittedSessionId === sessionId) {
            return null;
        }

        const candidate = state.previewDropCandidate;
        const widgetData = state.draggingWidget;
        if (!candidate
            || candidate.sessionId !== sessionId
            || !widgetData?.code
            || !isSlotDataAccepted(candidate.slot, widgetData)
            || !previewSlotHasCapacity(candidate.slot, widgetData)) {
            return null;
        }

        // 先标记、后发请求，保证 iframe drop 与 dragend fallback 竞争时最多提交一次。
        state.previewDropCommittedSessionId = sessionId;
        state.previewDropCandidate = null;
        state.lastPreviewInsertSortOrder = candidate.sortOrder;
        return handleWidgetDropped(widgetData, candidate.slot, candidate.sortOrder);
    }

    function cancelPreviewDragSession() {
        const sessionId = state.previewDragSessionId;
        clearPreviewDropCandidate(sessionId);
        if (!sessionId) return;

        state.previewDragCancelled = true;
        notifyPreviewDragState('cancel', null, sessionId);
    }

    function finishPreviewDragSession(sessionId) {
        if (!sessionId || state.previewDragSessionId !== sessionId) return;

        if (state.previewDropFallbackTimer) {
            clearTimeout(state.previewDropFallbackTimer);
            state.previewDropFallbackTimer = null;
        }
        notifyPreviewDragState('end', null, sessionId);
        state.isDragging = false;
        state.dragInsertIndex = null;
        state.draggingWidget = null;
        state.previewDragSessionId = '';
        state.previewDropCandidate = null;
        state.previewDropCommittedSessionId = '';
        state.previewDragCancelled = false;
    }

    function handlePreviewDragCancel(event) {
        if (event.key !== 'Escape' || !state.isDragging) return;
        cancelPreviewDragSession();
    }

    function handleIframeMessage(e) {
        if (e.origin !== window.location.origin || e.source !== elements.previewFrame?.contentWindow) return;
        const data = e.data;
        if (!data || data.source !== 'weline-theme-preview' || !data.type) return;

        console.log('收到 iframe 消息:', data);

        if (data.type === 'slot-selected') {
            if (isPreviewInteractionMode()) {
                setInteractionMode('edit');
            }
            handleSlotSelected(data.slot);
            return;
        }
        if (data.type === 'slot-init-defaults') {
            if (isPreviewInteractionMode()) {
                setInteractionMode('edit');
            }
            void initSlotDefaultsFromPreview(data).catch((error) => {
                console.error('[ThemeEditor] Slot init defaults failed:', error);
                showToast(error?.message || translateUiText('初始化失败'), 'error');
            });
            return;
        }

        if (isPreviewInteractionMode() && [
            'widget-selected',
            'slot-clicked',
            'drop-candidate',
            'drop-candidate-clear',
            'widget-dropped',
            'widget-rejected',
        ].includes(data.type)) {
            return;
        }

        switch (data.type) {
            case 'widget-selected':
                // 预览页面中选中了部件
                handlePreviewWidgetSelected(data);
                break;
            case 'slot-clicked':
                // 预览页面中点击了插槽（旧版）
                handlePreviewSlotClicked(data);
                break;
            case 'drop-candidate':
                // iframe 只报告当前指针候选；最终接纳、容量与保存由父编辑器统一处理。
                rememberPreviewDropCandidate(data);
                break;
            case 'drop-candidate-clear':
                clearPreviewDropCandidate(data.session_id);
                break;
            case 'widget-dropped': {
                // iframe drop 消息自带 widget/slot/sort_order，不依赖 dragend 时尚未清空的 draggingWidget。
                void commitPreviewDropFromMessage(data).catch((error) => {
                    console.error('[ThemeEditor] Preview drop commit failed:', error);
                });
                break;
            }
            case 'widget-rejected':
                clearPreviewDropCandidate(data.session_id || state.previewDragSessionId);
                showToast(data.reason || '部件被拒绝', 'error');
                break;
            case 'locale-change': {
                const nextLocale = String(data.locale || '').trim();
                void Promise.resolve(setActiveConfigLocale(nextLocale, { toast: true, forceReload: true }))
                    .catch((error) => {
                        console.error('[ThemeEditor] Preview locale change failed:', error);
                        showToast(error?.message || translateUiText('语言切换失败，已恢复原语言'), 'error');
                    });
                break;
            }
            case 'slot-hover-sync':
                // 预览引擎切换 hover 目标后，父页再 sync 一次 anchored-float（对齐部件 show-actions）
                syncActiveSlotToolbarFloat();
                break;
        }
    }

    async function initSlotDefaultsFromPreview(data) {
        const slotId = String(data?.slot_id || '').trim();
        if (!slotId) {
            showToast(translateUiText('缺少插槽标识'), 'warning');
            return;
        }

        const payload = buildLayoutVersionIdentityPayload({
            slot_id: slotId,
            area: data?.area || '',
            editor_area: getEffectiveEditorArea(state.editorArea || 'frontend'),
        });
        const result = await apiJson(config.apiInitSlotDefaults || `${config.apiBase}/init-slot-defaults`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!result || !result.success) {
            showToast(result?.message || translateUiText('初始化失败'), 'error');
            return;
        }

        await loadScopedWorkspace('layout');
        loadLayoutPreview();
        showToast(result.message || translateUiText('已初始化插槽默认部件'), 'success');
        if (state.widgetLibraryTab === 'applications') {
            await refreshDefaultInjectionApplications({ render: true, silent: true });
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
    function normalizeSelectedSlot(slot) {
        if (!slot || typeof slot !== 'object') {
            return null;
        }

        const rawAccept = slot.accept ?? '*';
        const rawReject = slot.reject ?? '';
        const accept = Array.isArray(rawAccept)
            ? rawAccept.join(',')
            : String(rawAccept);
        const reject = Array.isArray(rawReject)
            ? rawReject.join(',')
            : String(rawReject);

        return {
            ...slot,
            accept,
            reject,
        };
    }

    function buildSlotSelectionFromAreaElement(areaElement) {
        if (!areaElement) {
            return null;
        }

        const areaCode = areaElement.dataset.area || '';
        const slotId = areaElement.dataset.slot || areaElement.dataset.wslot || areaCode;
        if (!slotId) {
            return null;
        }

        const slotName = areaElement.dataset.slotName
            || areaElement.querySelector('.slot-placeholder-large .placeholder-title')?.textContent?.trim()
            || areaElement.querySelector('.area-label')?.textContent?.trim()
            || slotId;

        return normalizeSelectedSlot({
            id: slotId,
            name: slotName,
            accept: areaElement.dataset.slotAccept || areaElement.dataset.accept || areaCode || '*',
            reject: areaElement.dataset.slotReject || areaElement.dataset.reject || '',
            area: areaCode || inferAreaFromSlotId(slotId),
            exclusive: areaElement.dataset.slotExclusive === 'true' || areaElement.dataset.exclusive === 'true',
            multiple: areaElement.dataset.slotMultiple !== 'false' && areaElement.dataset.multiple !== 'false',
            max: areaElement.dataset.slotMax || areaElement.dataset.max || '',
            min: areaElement.dataset.slotMin || areaElement.dataset.min || '',
            source: 'structure',
        });
    }

    function shouldFilterWidgetsByArea(areaCode) {
        return ['header', 'content', 'footer', 'left_sidebar', 'right_sidebar', 'banner'].includes(normalizeCode(areaCode));
    }

    function slotPrefersBasicWidgetTab(slot) {
        const acceptCodes = normalizeCodeList(slot?.accept ?? []);
        const specific = acceptCodes.filter(code => code && code !== '*');
        return specific.length > 0 && specific.every(code => code === 'builder-component' || code.startsWith('builder-'));
    }

    function applySlotWidgetFilter(slot, options = {}) {
        const normalizedSlot = normalizeSelectedSlot(slot);
        if (!normalizedSlot) {
            applyWidgetLibraryTabVisibility();
            return 0;
        }

        const slotId = normalizedSlot.id || '';
        const areaCode = normalizedSlot.area || inferAreaFromSlotId(slotId);
        const rejectTypes = normalizeCodeList(normalizedSlot.reject || '');

        restoreWidgetOrder();

        if (options.autoSwitchTab !== false && slotPrefersBasicWidgetTab(normalizedSlot)) {
            setWidgetLibraryTab('basic', { silent: true, skipSlotRefresh: true });
        }

        if (areaCode && shouldFilterWidgetsByArea(areaCode)) {
            filterWidgetsByArea(areaCode, rejectTypes);
            state.selectedArea = areaCode;
        } else {
            filterWidgetsByArea(null, rejectTypes);
            state.selectedArea = null;
        }

        return highlightAcceptableWidgets(normalizedSlot.accept);
    }

    function applySlotWidgetRecommendations(slot) {
        return applySlotWidgetFilter(slot, { autoSwitchTab: true });
    }

    function handleSlotSelected(slot) {
        const normalizedSlot = normalizeSelectedSlot(slot);
        if (!normalizedSlot) {
            return;
        }

        console.log('插槽被选中:', normalizedSlot);

        state.selectedSlot = normalizedSlot;

        const slotId = normalizedSlot.id || '';
        const areaCode = normalizedSlot.area || inferAreaFromSlotId(slotId);

        console.log('[handleSlotSelected] slotId:', slotId, 'slot.area:', normalizedSlot.area, 'resolved areaCode:', areaCode);

        openWidgetPanelForSlotSelection(normalizedSlot);
        applySlotWidgetRecommendations(normalizedSlot);

        // 滚动到高亮的部件（延迟以等待DOM更新）
        scrollToHighlightedWidgets();

        // 更新左侧配置面板，显示插槽信息
        renderSlotInfoPanel(normalizedSlot);

        // 显示提示
        showToast(`已选中插槽: ${normalizedSlot.name || normalizedSlot.id}`, 'info');
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
            <div class="slot-loading w-theme-editor-loading-state">
                <span class="w-spinner" role="status"><span class="w-visually-hidden">加载中...</span></span>
                <p class="w-text w-theme-editor-status-copy" data-tone="muted">加载插槽配置...</p>
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
            `${dataAttributeSelector('data-wslot', slotId)} .weline-template-widget[data-template-ref]`,
            `${dataAttributeSelector('data-wslot', slotId)} .widget-wrapper[data-layout-id]`,
            `${dataAttributeSelector('data-wslot', slotId)} [data-layout-id]`,
            `.area-widgets${dataAttributeSelector('data-area', slotId)} .preview-widget-item`,
            `.area-widgets${dataAttributeSelector('data-area', slotId)} [data-layout-id]`,
            `.${cssIdentifier(slotId)}-slot-widgets .preview-widget-item`,
            `.container-slot${dataAttributeSelector('data-slot', slotId)} .preview-widget-item`,
            `.area-slot${dataAttributeSelector('data-slot', slotId)} .preview-widget-item`,
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
                const slotContainer = iframeDoc.querySelector(dataAttributeSelector('data-wslot', slotId));

                if (slotContainer) {
                    console.log(`findWidgetsInSlot: 找到 slot 容器，检查内部内容...`);
                    // 检查容器内是否有任何带 data-layout-id 或 widget 相关类名的元素
                    const innerWidgets = slotContainer.querySelectorAll(
                        '[data-layout-id], .weline-template-widget[data-template-ref], .widget-wrapper[data-weline-template-widget="1"], .widget-wrapper, .widget-content'
                    );

                    if (innerWidgets.length > 0) {
                        innerWidgets.forEach(el => {
                            const identity = readWidgetIdentityFromElement(el);
                            if (identity.identity) {
                                widgets.push(identity.wrapper || el);
                            }
                        });
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
            const identity = readWidgetIdentityFromElement(el);
            if (!identity.identity) {
                continue;
            }

            const widgetName = identity.widgetName || identity.widgetCode || '未知部件';
            widgetsData.push({
                element: identity.wrapper || el,
                layoutId: identity.layoutId,
                templateRef: identity.templateRef,
                widgetCode: identity.widgetCode,
                widgetModule: identity.widgetModule,
                widgetType: identity.widgetType,
                widgetName,
            });
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
            const collapseId = `widgetConfig_${String(widget.layoutId || widget.templateRef || i).replace(/[^\w:-]/g, '_')}`;
            const identityAttr = widget.layoutId
                ? `data-layout-id="${escapeHtml(widget.layoutId)}"`
                : `data-template-ref="${escapeHtml(widget.templateRef)}"`;
            const bodyIdentityAttr = widget.layoutId
                ? `data-layout-id="${escapeHtml(widget.layoutId)}"`
                : `data-template-ref="${escapeHtml(widget.templateRef)}"`;

            const icon = widgetTypeIconName(widget.widgetType);

            accordionHtml += `
                <div class="slot-widget-accordion-item ${widget.templateRef ? 'is-template-widget' : ''}" ${identityAttr}
                     data-w-component="disclosure" data-state="${isFirst ? 'open' : 'closed'}">
                    <button type="button" class="slot-widget-header"
                            data-w-disclosure-trigger aria-controls="${collapseId}"
                            aria-expanded="${isFirst ? 'true' : 'false'}">
                        <div class="widget-header-left">
                            ${iconSvg(icon)}
                            <span class="widget-name">${escapeHtml(widget.widgetName)}</span>
                            ${widget.templateRef ? '<span class="widget-template-badge">模板</span>' : ''}
                        </div>
                        <div class="widget-header-right">
                            <span class="widget-type-badge">${widget.widgetType || 'widget'}</span>
                            ${iconSvg('arrowDown')}
                        </div>
                    </button>
                    <div id="${collapseId}" class="slot-widget-body" ${bodyIdentityAttr} ${isFirst ? '' : 'hidden'}>
                        <div class="widget-config-loading w-theme-editor-loading-state">
                            <span class="w-spinner" role="status"><span class="w-visually-hidden">加载中...</span></span>
                        </div>
                    </div>
                </div>
            `;
        }

        const html = `
            <div class="slot-config-panel">
                <div class="slot-config-header">
                    <div class="slot-icon">
                        ${iconSvg('grid')}
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
                    <button type="button" class="w-button" data-tone="primary" data-variant="outline" data-size="sm" data-w-fill="true">
                        ${iconSvg('add')} 继续添加部件
                    </button>
                </div>
            </div>
        `;

        elements.configContent.innerHTML = html;
        getEditorUi().mount(elements.configContent);

        // 为每个部件加载配置表单
        for (const widget of widgetsData) {
            if (widget.layoutId) {
                loadWidgetConfigForAccordion(widget.layoutId);
            } else if (widget.templateRef) {
                loadWidgetConfigForAccordion(widget.templateRef, widget.element);
            }
        }

        // 绑定手风琴头部点击事件（加载配置）
        document.querySelectorAll('.slot-widget-header').forEach(header => {
            header.addEventListener('click', function() {
                const body = this.nextElementSibling;
                const layoutId = body?.dataset.layoutId;
                const templateRef = body?.dataset.templateRef;
                if (body?.querySelector('.widget-config-loading')) {
                    if (layoutId) {
                        loadWidgetConfigForAccordion(layoutId);
                    } else if (templateRef) {
                        loadWidgetConfigForAccordion(templateRef);
                    }
                }
            });
        });
    }

    /**
     * 加载部件配置到手风琴面板
     */
    function setConfigMode(mode) {
        state.configMode = mode === 'widget' ? 'widget' : 'layout';
        elements.configModeLayout?.classList.toggle('active', state.configMode === 'layout');
        elements.configModeWidget?.classList.toggle('active', state.configMode === 'widget');
        if (elements.configPanelTitle) {
            elements.configPanelTitle.textContent = state.configMode === 'layout' ? '布局配置' : '部件配置';
        }
        if (elements.configPanel) {
            elements.configPanel.classList.add('active');
        }
    }

    function showWidgetConfigState() {
        if (!elements.configContent) {
            return;
        }
        if (state.selectedWidget) {
            loadWidgetConfig(state.selectedWidget);
            return;
        }
        elements.configContent.innerHTML = `
            <div class="no-widget-selected">
                ${iconSvg('cursor')}
                <p>点击预览区域中的部件进行配置</p>
            </div>
        `;
    }

    function buildLayoutConfigUrl(locale) {
        const url = new URL(config.apiLayoutConfig, window.location.origin);
        url.searchParams.set('theme_id', String(state.themeId || 0));
        url.searchParams.set('layout_type', getEffectiveLayoutType());
        url.searchParams.set('layout_option', getEffectiveLayoutOption());
        url.searchParams.set('editor_area', getEffectiveEditorArea());
        url.searchParams.set('preview_area', getEffectiveEditorArea());
        url.searchParams.set('scope', getCurrentWindowParam('scope') || 'default');
        const lockPayload = getLayoutLockVirtualPayload();
        Object.entries(lockPayload).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                url.searchParams.set(key, String(value));
            }
        });
        if (locale) {
            url.searchParams.set('locale', locale);
        }
        url.searchParams.set('_t', String(Date.now()));
        return url.toString();
    }

    async function loadLayoutConfig(options = {}) {
        if (!elements.configContent || !state.themeId) {
            return;
        }
        setConfigMode('layout');
        if (!options.silent) {
            elements.configContent.innerHTML = '<div class="widget-config-loading">加载布局配置...</div>';
        }
        try {
            const locale = Object.prototype.hasOwnProperty.call(options, 'locale')
                ? (options.locale || '')
                : getActiveConfigLocale();
            const payload = await apiJson(buildLayoutConfigUrl(locale));
            if (!payload.success) {
                throw new Error(payload.message || 'Load layout config failed');
            }
            const data = payload.data || {};
            elements.configContent.innerHTML = `
                <div class="layout-config-panel" data-config-target="layout">
                    ${data.form_html || '<div class="w-param-empty-state"><p>当前布局没有配置字段</p></div>'}
                </div>
            `;
            bindLayoutConfigEvents(elements.configContent);
            const resourceType = locale ? 'i18n' : 'meta';
            const workspace = await loadScopedWorkspace(resourceType, { locale: locale || 'default' });
            renderLayoutConfigOwnership(
                elements.configContent.querySelector('.layout-config-panel'),
                workspace,
                locale,
            );
            return true;
        } catch (error) {
            console.error('[ThemeEditor] loadLayoutConfig error:', error);
            if (!options.silent) {
                elements.configContent.innerHTML = `<div class="w-param-empty-state"><p>${escapeHtml(error.message || '布局配置加载失败')}</p></div>`;
            }
            if (options.throwOnError === true) {
                throw error;
            }
            return false;
        }
    }

    function bindLayoutConfigEvents(container) {
        const form = container.querySelector('.layout-config-form');
        if (!form) {
            return;
        }
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            cancelEditorAutoSave('layout-config');
            const locale = getActiveConfigLocale();
            try {
                await runEditorAutoSave(
                    'layout-config',
                    () => saveLayoutConfig(form, locale),
                );
            } catch (error) {
                console.error('[ThemeEditor] layout config save error:', error);
                showToast(error?.message || translateUiText('布局配置保存失败'), 'error');
            }
        });

        function shouldIgnoreLayoutConfigAutoSave(target) {
            if (!target || !target.closest) {
                return true;
            }
            if (target.closest('.w-param-i18n-panel, .i18n-edit-panel')) {
                return true;
            }
            if (target.closest('.btn-save-layout-config, [type="submit"]')) {
                return true;
            }
            return false;
        }

        function scheduleLayoutConfigAutoSave() {
            const locale = getActiveConfigLocale();
            scheduleEditorAutoSave('layout-config', async () => {
                try {
                    await saveLayoutConfig(form, locale, { silent: true });
                } catch (error) {
                    console.error('[ThemeEditor] layout config auto-save error:', error);
                    showToast(error?.message || translateUiText('布局配置自动保存失败'), 'error');
                    throw error;
                }
            });
        }

        form.addEventListener('input', function(e) {
            if (shouldIgnoreLayoutConfigAutoSave(e.target)) {
                return;
            }
            scheduleLayoutConfigAutoSave();
        });
        form.addEventListener('change', function(e) {
            if (shouldIgnoreLayoutConfigAutoSave(e.target)) {
                return;
            }
            scheduleLayoutConfigAutoSave();
        });
    }

    async function saveLayoutConfig(form, locale, options = {}) {
        const silent = options.silent === true;
        const configData = collectWidgetConfigData(form);
        const editorArea = getEffectiveEditorArea();
        const effectiveLocale = locale === undefined ? getActiveConfigLocale() : (locale || '');
        const payload = {
            theme_id: state.themeId || 0,
            layout_type: getEffectiveLayoutType(),
            layout_option: getEffectiveLayoutOption(),
            editor_area: editorArea,
            preview_area: editorArea,
            scope: getCurrentWindowParam('scope') || 'default',
            locale: effectiveLocale,
            config: configData,
            ...getLayoutLockVirtualPayload()
        };
        const result = await apiJson(config.apiSaveLayoutConfig, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!result.success) {
            throw new Error(result.message || 'Save layout config failed');
        }
        await queueLayoutConfigOwnership(configData, effectiveLocale);
        if (!silent) {
            showToast(result.message || '布局配置已保存', 'success');
        }
        fetchLayoutSlots();
        if (options.refreshPreview !== false) {
            loadLayoutPreview();
        }
    }

    async function refreshLayoutOptions(options = {}) {
        if (!state.themeId || !config.apiLayoutOptions) {
            renderLayoutOptionSelect(state.layoutType, state.layoutOption);
            return;
        }

        const layoutType = normalizeLayoutOptionValue(options.layout_type || state.layoutType || getCurrentPageType() || 'homepage') || 'homepage';
        const requestedLayoutOption = normalizeLayoutOptionValue(
            Object.prototype.hasOwnProperty.call(options, 'layout_option')
                ? options.layout_option
                : state.layoutOption
        );
        const editorArea = options.editor_area || state.editorArea || 'frontend';
        const url = new URL(config.apiLayoutOptions, window.location.origin);
        url.searchParams.set('theme_id', String(state.themeId || 0));
        url.searchParams.set('layout_type', layoutType);
        url.searchParams.set('page_type', layoutType);
        url.searchParams.set('layout_option', requestedLayoutOption);
        url.searchParams.set('editor_area', editorArea);
        url.searchParams.set('preview_area', editorArea);
        url.searchParams.set('scope', getCurrentWindowParam('scope') || 'default');
        url.searchParams.set('_t', String(Date.now()));

        const payload = await apiJson(url.toString(), { silent: options.silent === true });
        if (!payload.success) {
            throw new Error(payload.message || 'Load layout options failed');
        }

        const data = payload.data || {};
        state.layoutOptionsByType = parseLayoutOptionsByType(data.layout_options_by_type || {});
        state.layoutOption = resolveLayoutOptionForType(layoutType, data.layout_option || requestedLayoutOption);
        renderLayoutOptionSelect(layoutType, state.layoutOption);
    }

    async function saveLayoutSelection(options = {}) {
        if (!state.themeId || !config.apiSaveLayoutSelection) {
            return;
        }

        const result = await apiJson(config.apiSaveLayoutSelection, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                theme_id: state.themeId || 0,
                layout_type: state.layoutType || getCurrentPageType() || 'homepage',
                page_type: getCurrentPageType() || 'homepage',
                layout_option: state.layoutOption || 'default',
                editor_area: state.editorArea || 'frontend',
                preview_area: state.editorArea || 'frontend',
                scope: getCurrentWindowParam('scope') || 'default'
            }),
            silent: options.silent === true
        });

        if (!result.success) {
            throw new Error(result.message || 'Save layout option failed');
        }

        const data = result.data || {};
        if (data.layout_options_by_type) {
            state.layoutOptionsByType = parseLayoutOptionsByType(data.layout_options_by_type);
        }
        state.layoutOption = resolveLayoutOptionForType(state.layoutType, data.layout_option || state.layoutOption);
        renderLayoutOptionSelect(state.layoutType, state.layoutOption);
        await queueScopedChanges('layout', [{
            op: 'set',
            path: '/selection/layout_option',
            value: state.layoutOption,
        }], {
            layout_option: 'default',
            summary: 'layout_selection_changed',
        });
        if (!options.silent) {
            showToast(result.message || 'Layout option saved.', 'success');
        }
    }

    async function loadWidgetConfigForAccordion(identity, widgetElement = null) {
        const identityValue = String(identity || '').trim();
        const isTemplate = identityValue.startsWith('tpl:');
        const selector = isTemplate
            ? dataTemplateRefSelector(identityValue)
            : dataLayoutIdSelector(identityValue);
        const configBody = document.querySelector(`.slot-widget-body${selector}`);
        if (!configBody) return;

        // 如果已加载，跳过
        if (!configBody.querySelector('.widget-config-loading')) return;

        if (isTemplate) {
            let resolvedElement = widgetElement;
            if (!resolvedElement) {
                try {
                    const iframeDoc = elements.previewFrame?.contentDocument || elements.previewFrame?.contentWindow?.document;
                    resolvedElement = iframeDoc?.querySelector(selector) || null;
                } catch (err) {
                    console.warn('[ThemeEditor] Unable to resolve template widget for accordion:', err);
                }
            }
            if (!resolvedElement) {
                configBody.innerHTML = `<div class="w-theme-editor-empty-copy"><small>未找到模板内嵌部件</small></div>`;
                return;
            }

            state.selectedWidget = resolvedElement;
            try {
                const widgetIdentity = readWidgetIdentityFromElement(resolvedElement);
                let widgetConfig = {};
                try {
                    widgetConfig = JSON.parse(widgetIdentity.config || '{}') || {};
                } catch (e) {
                    widgetConfig = {};
                }

                const result = await fetchWidgetsData();
                if (!result.success) {
                    throw new Error('widget library unavailable');
                }

                let widgetMeta = null;
                for (const type in result.data) {
                    const widgets = result.data[type].widgets || [];
                    for (const w of widgets) {
                        if (w.module === widgetIdentity.widgetModule && w.code === widgetIdentity.widgetCode) {
                            widgetMeta = w;
                            break;
                        }
                    }
                    if (widgetMeta) break;
                }

                if (!widgetMeta) {
                    configBody.innerHTML = `<div class="w-theme-editor-empty-copy"><small>未找到部件配置信息</small></div>`;
                    return;
                }

                const formHtml = await generateWidgetConfigForm('', widgetMeta.params || {}, widgetConfig);
                const searchPlaceholder = (typeof __ !== 'undefined' ? __('搜索配置项') : '搜索配置项');
                configBody.innerHTML = `
                    <div class="w-param-search-wrap">
                        <input type="text" class="w-param-search w-input w-theme-editor-control-sm" placeholder="${searchPlaceholder}" autocomplete="off">
                    </div>
                    <form class="widget-accordion-config-form w-param-form" data-template-ref="${escapeHtml(identityValue)}">
                        ${formHtml}
                    </form>
                `;
                bindAccordionFormEvents(configBody);
                bindParamSearch(configBody);
            } catch (err) {
                console.error('Load template widget config error:', err);
                configBody.innerHTML = `<div class="w-theme-editor-empty-copy" data-tone="danger"><small>加载配置失败</small></div>`;
            }
            return;
        }

        try {
            const locale = getActiveConfigLocale();
            const apiUrl = buildSavedWidgetConfigUrl(identityValue, locale);
            const result = await apiJson(apiUrl);

            if (result.success && result.data) {
                const widgetData = result.data;
                const params = widgetData.params || {};
                const widgetConfig = widgetData.config || {};

                // 生成配置表单
                const formHtml = await generateWidgetConfigForm(identityValue, params, widgetConfig);
                const searchPlaceholder = (typeof __ !== 'undefined' ? __('搜索配置项') : '搜索配置项');
                const searchWrap = '<div class="w-param-search-wrap"><input type="text" class="w-param-search w-input w-theme-editor-control-sm" placeholder="' + searchPlaceholder + '" autocomplete="off"></div>';
                configBody.innerHTML = searchWrap + formHtml;

                // 绑定表单事件（手风琴 + 配置搜索）
                bindAccordionFormEvents(configBody);
                bindParamSearch(configBody);
                const resourceType = locale ? 'i18n' : 'layout';
                const workspace = await loadScopedWorkspace(resourceType, { locale: locale || 'default' });
                renderWidgetConfigOwnership(configBody, widgetData.node_uid, workspace, locale);
            } else {
                configBody.innerHTML = `<div class="w-theme-editor-empty-copy">
                    <span class="w-theme-editor-empty-icon">${iconSvg('settings')}</span>
                    <small>该部件无可配置项</small>
                </div>`;
            }
        } catch (err) {
            console.error('Load widget config error:', err);
            configBody.innerHTML = `<div class="w-theme-editor-empty-copy" data-tone="danger">
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
            const html = await apiText(config.apiParamRenderForm || '/theme/backend/widget/paramrender/form', {
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

            if (html && !html.includes('alert-danger')) {
                return html;
            }
        } catch (err) {
            console.debug?.('[ThemeEditor] Backend form render failed, using fallback:', err);
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
        const normalizeArrayFieldValue = (value, fallback = []) => {
            if (Array.isArray(value)) {
                return value;
            }

            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (trimmed !== '') {
                    try {
                        const parsed = JSON.parse(trimmed);
                        if (Array.isArray(parsed)) {
                            return parsed;
                        }
                    } catch (e) {
                        // Keep non-JSON strings as scalar config values.
                    }
                }
            }

            return Array.isArray(fallback) ? fallback : [];
        };

        const normalizeArrayItemSchema = (param) => {
            const candidates = [
                param?.item_schema,
                param?.itemSchema,
                param?.schema?.fields,
                param?.schema,
                param?.fields
            ];

            for (const candidate of candidates) {
                if (!candidate) {
                    continue;
                }

                if (Array.isArray(candidate)) {
                    const schema = {};
                    candidate.forEach(field => {
                        const fieldKey = field?.key || field?.name || field?.code;
                        if (fieldKey) {
                            schema[fieldKey] = field;
                        }
                    });
                    if (Object.keys(schema).length > 0) {
                        return schema;
                    }
                    continue;
                }

                if (typeof candidate === 'object') {
                    return candidate;
                }
            }

            return {};
        };

        const normalizeOptions = (options) => {
            if (!options) {
                return {};
            }
            if (Array.isArray(options)) {
                return options.reduce((result, option) => {
                    if (option && typeof option === 'object') {
                        const value = option.value ?? option.key ?? option.code ?? option.id ?? '';
                        if (value !== '') {
                            result[value] = option.label ?? option.name ?? value;
                        }
                    } else if (option !== null && option !== undefined) {
                        result[option] = option;
                    }
                    return result;
                }, {});
            }
            return options;
        };

        const getArrayItemFieldValue = (item, fieldKey, fieldParam) => {
            if (item && typeof item === 'object' && !Array.isArray(item)) {
                return item[fieldKey] ?? fieldParam.default ?? '';
            }
            return fieldParam.default ?? '';
        };

        const renderFallbackMediaImageField = (inputId, fieldKey, value, fieldParam) => {
            return renderTypedFileImageControl(inputId, fieldKey, value, fieldParam, {
                includeName: false,
                arrayItem: true,
            });
        };

        const renderFallbackArrayItemField = (fieldId, itemIndex, fieldKey, fieldParam, item) => {
            fieldParam = fieldParam && typeof fieldParam === 'object' ? fieldParam : { type: fieldParam || 'string' };
            const label = fieldParam.label || fieldKey;
            const type = getParamUiType(fieldParam);
            const value = getArrayItemFieldValue(item, fieldKey, fieldParam);
            const inputId = `${fieldId}_${itemIndex}_${fieldKey}`;
            const escapedValue = escapeHtml(value);
            let html = `<div class="array-item-field" data-array-field="${escapeHtml(fieldKey)}">`;
            html += `<label class="array-item-label" for="${escapeHtml(inputId)}">${escapeHtml(label)}</label>`;

            if (isBooleanParamType(type)) {
                const checked = value === true || value === 1 || value === '1' || value === 'true' || value === 'on';
                html += `<input type="checkbox" class="w-theme-editor-choice-input" id="${escapeHtml(inputId)}" data-field="${escapeHtml(fieldKey)}" ${checked ? 'checked' : ''}>`;
            } else if (type === 'select' && fieldParam.options) {
                const options = normalizeOptions(fieldParam.options);
                html += `<select class="w-select w-theme-editor-control-sm" id="${escapeHtml(inputId)}" data-field="${escapeHtml(fieldKey)}">`;
                for (const [optVal, optLabel] of Object.entries(options)) {
                    const selected = String(value) === String(optVal) ? ' selected' : '';
                    html += `<option value="${escapeHtml(optVal)}"${selected}>${escapeHtml(optLabel)}</option>`;
                }
                html += `</select>`;
            } else if (type === 'textarea' || type === 'html') {
                html += `<textarea class="w-textarea w-theme-editor-control-sm" id="${escapeHtml(inputId)}" data-field="${escapeHtml(fieldKey)}" rows="2">${escapedValue}</textarea>`;
            } else if (type === 'number') {
                html += `<input type="number" class="w-input w-theme-editor-control-sm" id="${escapeHtml(inputId)}" data-field="${escapeHtml(fieldKey)}" value="${escapedValue}" min="${escapeHtml(fieldParam.min || '')}" max="${escapeHtml(fieldParam.max || '')}" step="${escapeHtml(fieldParam.step || '')}">`;
            } else if (type === 'url') {
                html += `<input type="url" class="w-input w-theme-editor-control-sm" id="${escapeHtml(inputId)}" data-field="${escapeHtml(fieldKey)}" value="${escapedValue}" placeholder="https://">`;
            } else if (['image', 'image_picker', 'media_image', 'file_image'].includes(type)) {
                html += renderFallbackMediaImageField(inputId, fieldKey, value, fieldParam);
            } else {
                html += `<input type="text" class="w-input w-theme-editor-control-sm" id="${escapeHtml(inputId)}" data-field="${escapeHtml(fieldKey)}" value="${escapedValue}">`;
            }

            html += `</div>`;
            return html;
        };

        const renderFallbackArrayItem = (fieldId, key, item, itemIndex, itemSchema, sortable = true) => {
            const schemaKeys = Object.keys(itemSchema);
            const indexLabel = itemIndex === '__INDEX__' ? '' : Number(itemIndex) + 1;
            let html = `<div class="array-item" data-index="${escapeHtml(itemIndex)}"${sortable ? ' data-w-reorder-item' : ''}>`;
            if (sortable) {
                html += `<button type="button" class="array-item-handle" data-w-reorder-handle aria-label="拖拽或使用方向键排序" title="拖拽或使用方向键排序">${iconSvg('drag')}</button>`;
            }
            html += `<div class="array-item-content">`;

            if (schemaKeys.length === 0) {
                const value = item === null || item === undefined || typeof item === 'object' ? '' : item;
                html += `<input type="text" class="w-input array-item-input" value="${escapeHtml(value)}">`;
            } else {
                html += `<div class="array-item-title">${indexLabel ? `第 ${indexLabel} 项` : '新项目'}</div>`;
                html += `<div class="array-item-fields">`;
                schemaKeys.forEach(fieldKey => {
                    html += renderFallbackArrayItemField(fieldId, itemIndex, fieldKey, itemSchema[fieldKey] || {}, item || {});
                });
                html += `</div>`;
            }

            html += `</div>`;
            html += `<div class="array-item-actions">
                <button type="button" class="w-button w-theme-editor-remove-array-item" data-tone="danger" data-variant="outline" data-size="sm" title="删除">
                    ${iconSvg('delete')}
                </button>
            </div>`;
            html += `</div>`;
            return html;
        };

        const renderFallbackNavTreeField = (fieldId, key, value, param, layoutId) => {
            const maxDepth = param.max_depth || 3;
            const items = normalizeArrayFieldValue(value, param.default);
            const safeFieldId = escapeHtml(fieldId);
            const safeKey = escapeHtml(key);
            const boot = {
                tree: items,
                page_candidates: [],
                category_candidates: [],
                max_depth: maxDepth,
                labels: {
                    title: '导航树',
                    pages: '页面',
                    categories: '分类',
                    add_custom: '添加自定义',
                    detail: '详情',
                    remove: '删除',
                    empty: '暂无节点，可从左侧拖入或添加自定义',
                    name: '名称',
                    url: '链接',
                    tag_page: '页面',
                    tag_category: '分类',
                    tag_custom: '自定义',
                    description: '描述',
                    image: '图片',
                    ref: '引用',
                    save_detail: '保存',
                    cancel: '取消',
                    indent_hint: '拖拽调整顺序与层级（最多三级）',
                    i18n: '多语言',
                    i18n_name: '名称翻译',
                    i18n_description: '描述翻译',
                    image_pick: '从媒体库选择',
                    has_description: '描述',
                    has_image: '已设图片',
                },
                item_schema: param.item_schema || {},
                locales: [],
            };
            const payloadJson = escapeHtml(JSON.stringify(boot));
            const hiddenValue = escapeHtml(JSON.stringify(items));
            return `<div class="w-param-nav-tree" data-w-component="nav-tree" data-field-id="${safeFieldId}" data-key="${safeKey}" data-max-depth="${maxDepth}">
                <div class="w-nav-tree-editor" id="${safeFieldId}_editor"></div>
                <input type="hidden" id="${safeFieldId}" name="${safeKey}" value='${hiddenValue}'>
                <textarea class="w-nav-tree-boot-data" id="${safeFieldId}_nav_tree_boot" hidden readonly aria-hidden="true">${payloadJson}</textarea>
            </div>`;
        };

        const isNavTreeParam = (param, type) => type === 'nav_tree'
            || type === 'all_menu_tree'
            || param?.schema_type === 'all_menu_tree';

        const renderField = (key, param, layoutId, config) => {
            const label = param.label || key;
            const type = getParamUiType(param);
            const semanticType = param.type || type;
            const value = config[key] ?? param.default ?? '';
            const isNavTreeField = isNavTreeParam(param, type);
            const isArrayField = !isNavTreeField && (type === 'array'
                || type === 'list'
                || Array.isArray(value)
                || Array.isArray(param.default)
                || (param.item_schema && typeof param.item_schema === 'object')
                || (typeof type === 'string' && type.endsWith('_items')));
            const scalarValue = (value === null || value === undefined || typeof value === 'object') ? '' : value;
            const arrayValue = normalizeArrayFieldValue(value, param.default);
            const arrayItemSchema = normalizeArrayItemSchema(param);
            const description = param.description || '';
            const translatable = isFieldTranslatable(param);
            const fieldId = `config_${layoutId}_${key}`;
            const fieldClass = translatable ? 'config-field translatable-field' : 'config-field';
            const safeKey = escapeHtml(key);
            const safeLayoutId = escapeHtml(layoutId || '');
            const safeFieldId = escapeHtml(fieldId);
            const safeLabel = escapeHtml(label);
            const safeDescription = escapeHtml(description);
            const safeValue = escapeHtml(value ?? '');
            const safeScalarValue = escapeHtml(scalarValue);

            let fieldHtml = `<div class="${fieldClass}" data-field-key="${safeKey}" data-translatable="${translatable}">`;

            // 字段头部：标签 + 多语言按钮
            fieldHtml += `<div class="config-field-header">
                <label class="config-label" for="${safeFieldId}">${safeLabel}</label>
                ${translatable ? `<button type="button" class="w-button w-theme-editor-i18n-edit" data-tone="neutral" data-variant="outline" data-size="sm" aria-expanded="false" data-field="${safeKey}" data-layout-id="${safeLayoutId}" title="编辑多语言">
                    ${iconSvg('language')}
                    <span>多语言</span>
                </button>` : ''}
            </div>`;

            // 输入控件
            fieldHtml += `<div class="config-field-input">`;
            if (isBooleanParamType(semanticType)) {
                fieldHtml += renderBooleanSelect(fieldId, key, key, value, false, param);
            } else if (type === 'select' && param.options) {
                fieldHtml += `<select class="w-select" id="${safeFieldId}" name="${safeKey}">`;
                for (const [optVal, optLabel] of Object.entries(param.options)) {
                    fieldHtml += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(optLabel)}</option>`;
                }
                fieldHtml += `</select>`;
            } else if (type === 'textarea' || type === 'html') {
                fieldHtml += `<textarea class="w-textarea" id="${safeFieldId}" name="${safeKey}" rows="3">${safeValue}</textarea>`;
            } else if (type === 'number') {
                fieldHtml += `<input type="number" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${safeValue}" min="${escapeHtml(param.min || '')}" max="${escapeHtml(param.max || '')}">`;
            } else if (type === 'color') {
                fieldHtml += `<div class="color-picker-wrapper">
                    <input type="color" class="w-theme-editor-color-input" id="${safeFieldId}_picker" value="${escapeHtml(value || '#000000')}">
                    <input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${escapeHtml(value || '#000000')}" placeholder="#000000">
                </div>`;
            } else if (type === 'url') {
                fieldHtml += `<div class="input-with-icon">
                    ${iconSvg('link')}
                    <input type="url" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${safeValue}" placeholder="https://">
                </div>`;
            } else if (['image', 'image_picker', 'media_image', 'file_image'].includes(type)) {
                fieldHtml += renderTypedFileImageControl(fieldId, key, value, param);
            } else if (type === 'range' || type === 'slider') {
                const min = param.min || 0;
                const max = param.max || 100;
                const step = param.step || 1;
                fieldHtml += `<div class="range-slider-wrapper">
                    <div class="range-slider-container">
                        <input type="range" class="form-range" id="${safeFieldId}_slider" min="${escapeHtml(min)}" max="${escapeHtml(max)}" step="${escapeHtml(step)}" value="${escapeHtml(value || min)}">
                    </div>
                    <div class="range-value-display">
                        <input type="number" class="w-input range-value-input" id="${safeFieldId}" name="${safeKey}" min="${escapeHtml(min)}" max="${escapeHtml(max)}" step="${escapeHtml(step)}" value="${escapeHtml(value || min)}">
                    </div>
                </div>`;
            } else if (type === 'datetime' || type === 'date' || type === 'time') {
                const inputType = type === 'date' ? 'date' : (type === 'time' ? 'time' : 'datetime-local');
                fieldHtml += `<div class="w-field__group">
                    <span class="w-field__addon">${iconSvg('calendar')}</span>
                    <input type="${inputType}" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${safeValue}">
                </div>`;
            } else if (isArrayField) {
                const sortable = param.sortable !== false;
                const arrayItemsHtml = arrayValue.length > 0
                    ? arrayValue.map((item, index) => renderFallbackArrayItem(fieldId, key, item, index, arrayItemSchema, sortable)).join('')
                    : '<div class="array-empty-state">' + iconSvg('list') + '<p>暂无项目</p></div>';
                const templateHtml = renderFallbackArrayItem(fieldId, key, {}, '__INDEX__', arrayItemSchema, sortable);
                const reorderAttributes = sortable
                    ? ' data-w-component="reorder-list" data-w-reorder-axis="vertical" data-w-reorder-announcement="已移动到第 {position} 项，共 {total} 项"'
                    : '';
                fieldHtml += `<div class="array-editor-wrapper" data-field-id="${safeFieldId}" data-max-items="${escapeHtml(param.max_items || param.maxItems || '')}">
                    <div class="array-items-container" id="${safeFieldId}_items"${reorderAttributes}>
                        ${arrayItemsHtml}
                    </div>
                    <div class="array-actions">
                        <button type="button" class="w-button w-theme-editor-add-array-item" data-tone="primary" data-variant="outline" data-target="${safeFieldId}">
                            ${iconSvg('add')} 添加项目
                        </button>
                    </div>
                    <template>${templateHtml}</template>
                    <input type="hidden" id="${safeFieldId}" name="${safeKey}" value='${escapeHtml(JSON.stringify(arrayValue))}'>
                </div>`;
            } else if (isNavTreeField) {
                fieldHtml += renderFallbackNavTreeField(fieldId, key, value, param, layoutId);
            } else if (type === 'icon') {
                const iconValue = normalizeSemanticIconName(value);
                const iconPanelId = `${safeFieldId}_panel`;
                const iconNames = Array.isArray(param.icons) && param.icons.length > 0
                    ? [...new Set(param.icons.map(normalizeSemanticIconName).filter(Boolean))]
                    : EDITOR_PICKER_ICON_NAMES;
                const iconOptions = iconNames.map((name) => `
                    <button type="button" class="w-icon-picker__option" role="option"
                        data-w-icon-value="${escapeHtml(name)}" aria-selected="${String(name === iconValue)}"
                        aria-label="${escapeHtml(name)}" title="${escapeHtml(name)}">
                        ${iconSvg(name)}
                    </button>`).join('');
                fieldHtml += `<div class="w-icon-picker" data-w-component="icon-picker" data-w-placement="bottom-start"
                    data-state="closed" data-w-empty-label="未选择图标">
                    <input type="hidden" id="${safeFieldId}" name="${safeKey}" value="${escapeHtml(iconValue)}" data-w-icon-input>
                    <button type="button" class="w-icon-picker__trigger" data-w-icon-trigger
                        aria-expanded="false" aria-controls="${iconPanelId}">
                        <span class="w-icon-picker__preview" data-w-icon-preview>${iconValue ? iconSvg(iconValue) : ''}</span>
                        <span class="w-icon-picker__text" data-w-icon-text>${escapeHtml(iconValue || '未选择图标')}</span>
                    </button>
                    <button type="button" class="w-button w-icon-picker__clear" data-tone="quiet" data-size="sm"
                        data-icon-only="true" data-w-icon-clear aria-label="清除图标" ${iconValue ? '' : 'hidden'}>
                        ${iconSvg('close')}
                    </button>
                    <div class="w-icon-picker__panel" id="${iconPanelId}" data-w-icon-panel
                        data-state="closed" aria-hidden="true" hidden>
                        <input type="search" class="w-input w-icon-picker__search" placeholder="搜索图标…"
                            autocomplete="off" data-w-icon-search>
                        <div class="w-icon-picker__list" role="listbox">${iconOptions}</div>
                        <p class="w-icon-picker__empty" data-w-icon-empty hidden>没有匹配图标</p>
                        <div class="w-icon-picker__custom">
                            <input type="text" class="w-input" value="${escapeHtml(iconValue)}"
                                placeholder="输入 Weline 图标名称" pattern="[a-z][a-z0-9-]{0,63}" maxlength="64"
                                data-w-icon-custom>
                            <button type="button" class="w-button" data-tone="primary" data-size="sm" data-w-icon-apply>应用</button>
                        </div>
                    </div>
                </div>`;
            } else {
                fieldHtml += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${safeScalarValue}">`;
            }
            fieldHtml += `</div>`;

            // 多语言编辑区（统一空容器，由 fetchInstalledLocales 动态填充）
            if (translatable) {
                fieldHtml += `<div class="w-param-i18n-panel i18n-edit-panel" id="i18n_panel_${safeLayoutId}_${safeKey}" data-field="${safeKey}" data-layout-id="${safeLayoutId}" data-state="closed" aria-hidden="true" hidden>
                    <div class="w-param-i18n-header i18n-panel-header">
                        <span>${iconSvg('global')} 多语言配置</span>
                        <button type="button" class="w-button w-theme-editor-i18n-close" data-tone="quiet" data-size="sm" data-icon-only="true" data-close-i18n data-field="${safeKey}" aria-label="关闭多语言配置">${iconSvg('close')}</button>
                    </div>
                    <div class="w-param-i18n-body i18n-panel-body"></div>
                    <div class="w-param-i18n-footer i18n-panel-footer">
                        <button type="button" class="w-button w-theme-editor-ai-i18n" data-tone="neutral" data-variant="outline" data-size="sm" data-ai-i18n data-field="${safeKey}" data-layout-id="${safeLayoutId}">
                            AI翻译
                        </button>
                        <button type="button" class="w-button w-theme-editor-save-i18n" data-tone="primary" data-size="sm" data-save-i18n data-field="${safeKey}" data-layout-id="${safeLayoutId}">
                            ${iconSvg('save')} 保存多语言
                        </button>
                    </div>
                </div>`;
            }

            if (description) {
                fieldHtml += `<div class="config-field-description">${iconSvg('info')} ${safeDescription}</div>`;
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
                        ${iconSvg('info')}
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
            <form class="widget-accordion-config-form w-param-form" data-layout-id="${escapeHtml(layoutId)}">
                ${groupsHtml}
                <div class="config-actions">
                    <button type="submit" class="w-button" data-tone="primary">
                        ${iconSvg('save')} 保存配置
                    </button>
                    <button type="button" class="w-button w-theme-editor-delete-widget" data-tone="danger" data-variant="outline" data-layout-id="${escapeHtml(layoutId)}">
                        ${iconSvg('delete')} 删除
                    </button>
                </div>
            </form>
        `;
    }

    function initWidgetParamPickers(container) {
        if (!container) {
            return;
        }
        const widgetParams = Weline.Widget?.Params;
        if (typeof widgetParams?.mount === 'function') {
            widgetParams.mount(container);
        } else if (typeof widgetParams?.mountMedia === 'function') {
            widgetParams.mountMedia(container);
        }
    }

    function replaceSelectOptions(select, placeholder, options, currentValues, getValue, getLabel) {
        select.replaceChildren();
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        select.appendChild(placeholderOption);
        options.forEach((item) => {
            const option = document.createElement('option');
            const value = String(getValue(item) ?? '');
            option.value = value;
            option.textContent = String(getLabel(item) ?? value);
            option.selected = currentValues.includes(value);
            select.appendChild(option);
        });
    }

    function markSelectLoadFailure(select) {
        replaceSelectOptions(select, '加载失败', [], [], () => '', () => '');
    }

    function initFallbackEavSelects(container) {
        if (!container) return;

        container.querySelectorAll('.eav-attribute-select:not([data-w-eav-bound])').forEach(async (select) => {
            select.dataset.wEavBound = '1';
            const url = new URL('/weline/eav/api/options/attributes', window.location.origin);
            url.searchParams.set('entity_code', select.dataset.entityCode || 'product');
            try {
                const result = await apiJson(url.toString(), { silent: true });
                const attributes = result?.success ? result?.data?.attributes : [];
                replaceSelectOptions(
                    select,
                    '-- 请选择属性 --',
                    Array.isArray(attributes) ? attributes : [],
                    [String(select.dataset.currentValue || '')],
                    (attribute) => attribute.code,
                    (attribute) => `${attribute.name || attribute.code} (${attribute.code})`,
                );
            } catch (error) {
                console.error('[ThemeEditor] EAV attribute load failed:', error);
                markSelectLoadFailure(select);
            }
        });

        container.querySelectorAll('.eav-options-select:not([data-w-eav-bound])').forEach(async (select) => {
            select.dataset.wEavBound = '1';
            const attributeCode = String(select.dataset.attributeCode || '');
            if (!attributeCode) {
                replaceSelectOptions(select, '-- 请先选择属性 --', [], [], () => '', () => '');
                return;
            }
            const url = new URL('/weline/eav/api/options', window.location.origin);
            url.searchParams.set('entity_code', select.dataset.entityCode || 'product');
            url.searchParams.set('attribute_code', attributeCode);
            let currentValues = [];
            try {
                const parsed = JSON.parse(select.dataset.currentValues || '[]');
                currentValues = Array.isArray(parsed) ? parsed.map(String) : [];
            } catch (_error) {
                currentValues = [];
            }
            try {
                const result = await apiJson(url.toString(), { silent: true });
                const options = result?.success ? result?.data?.options : [];
                replaceSelectOptions(
                    select,
                    '-- 请选择 --',
                    Array.isArray(options) ? options : [],
                    currentValues,
                    (option) => option.id,
                    (option) => option.value,
                );
            } catch (error) {
                console.error('[ThemeEditor] EAV option load failed:', error);
                markSelectLoadFailure(select);
            }
        });
    }

    /**
     * 绑定配置表单事件（多语言、颜色等）；手风琴已在 #themeEditor 根上委托
     */
    function bindAccordionFormEvents(container) {
        if (!container) return;

        if (typeof Weline?.Widget?.Params?.mount === 'function') {
            Weline.Widget.Params.mount(container);
        }

        // 统一多语言事件委托（覆盖顶级字段 + 后续动态添加的数组子字段）。
        // 模态框容器会重复填充，委托只能安装一次，避免一次点击开关两次。
        if (container.dataset.wParamI18nDelegateBound !== '1') {
            container.dataset.wParamI18nDelegateBound = '1';
            container.addEventListener('click', async function(e) {
            // 多语言编辑按钮（.w-param-btn-i18n 或 .w-theme-editor-i18n-edit）
            const i18nBtn = e.target.closest('.w-param-btn-i18n, .w-theme-editor-i18n-edit');
            if (i18nBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const fieldKey = i18nBtn.dataset.field;
                const layoutId = i18nBtn.dataset.layoutId;
                const panelId = 'i18n_panel_' + layoutId + '_' + fieldKey.replace(/\./g, '_');
                const panel = document.getElementById(panelId) || i18nBtn.closest('.w-param-field, .config-field')?.querySelector('.w-param-i18n-panel, .i18n-edit-panel');
                if (!panel) return;

                if (panel.hidden) {
                    setI18nPanelOpen(panel, i18nBtn, true);
                    await loadI18nValues(layoutId, fieldKey, panel);
                } else {
                    setI18nPanelOpen(panel, i18nBtn, false);
                }
                return;
            }

            // 关闭按钮
            const closeBtn = e.target.closest('[data-close-i18n], .w-theme-editor-i18n-close');
            if (closeBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const panel = closeBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                if (panel) {
                    const fieldKey = closeBtn.dataset.field || panel.dataset.field;
                    const trigger = container.querySelector(`.w-param-btn-i18n[data-field="${fieldKey}"], .w-theme-editor-i18n-edit[data-field="${fieldKey}"]`);
                    setI18nPanelOpen(panel, trigger, false);
                }
                return;
            }

            const aiBtn = e.target.closest('[data-ai-i18n], .w-theme-editor-ai-i18n');
            if (aiBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const panel = aiBtn.closest('.w-param-i18n-panel, .i18n-edit-panel');
                const fieldKey = aiBtn.dataset.field || panel?.dataset.field;
                // TE-CAP-020: template widgets may have empty layout_id; AI translate only needs source text.
                const layoutId = aiBtn.dataset.layoutId || panel?.dataset.layoutId || '';
                if (panel && fieldKey) {
                    await translateI18nValues(layoutId, fieldKey, panel, aiBtn);
                } else {
                    showToast(translateUiText('无法定位多语言字段'), 'warning');
                }
                return;
            }

            // 保存多语言按钮
            const saveBtn = e.target.closest('[data-save-i18n], .w-theme-editor-save-i18n');
            if (saveBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
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

        // 颜色选择器同步
        container.querySelectorAll('.color-picker-wrapper').forEach(wrapper => {
            const picker = wrapper.querySelector('.w-theme-editor-color-input');
            const text = wrapper.querySelector('.w-input');
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
                        if (renderSafeImagePreview(preview, url)) {
                            preview.closest('.image-preview-container')?.classList.add('has-image');
                        } else {
                            preview.innerHTML = '<div class="image-placeholder">' + (iconSvg('image') || '') + '<span>点击选择图片</span></div>';
                            preview.closest('.image-preview-container')?.classList.remove('has-image');
                        }
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
                            if (preview && renderSafeImagePreview(preview, e.target.result)) {
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
            const addBtn = wrapper.querySelector('.w-theme-editor-add-array-item');
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
                hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            };
            const reindexArrayItems = () => {
                itemsContainer?.querySelectorAll('.array-item').forEach((item, index) => {
                    item.dataset.index = String(index);
                    const title = item.querySelector('.array-item-title');
                    if (title) title.textContent = `第 ${index + 1} 项`;
                });
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
                                    <input type="text" class="w-input array-item-input" value="">
                                </div>
                                <div class="array-item-actions">
                                    <button type="button" class="w-button w-theme-editor-remove-array-item" data-tone="danger" data-variant="outline" data-size="sm">
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
                        rememberWidgetPreviewArrayItem(newItem);
                        bindArrayItemEvents(newItem, updateHiddenValue);
                        initWidgetParamPickers(newItem);
                    }

                    updateHiddenValue();
                });
            }

            // 绑定现有项的事件
            itemsContainer?.querySelectorAll('.array-item').forEach(item => {
                bindArrayItemEvents(item, updateHiddenValue);
            });
            if (itemsContainer && !itemsContainer.dataset.wThemeEditorReorderInited) {
                itemsContainer.dataset.wThemeEditorReorderInited = '1';
                itemsContainer.addEventListener('weline:ui:reorder-list:change', function(event) {
                    if (event.target !== itemsContainer) return;
                    reindexArrayItems();
                    if (event.detail?.item instanceof HTMLElement) {
                        rememberWidgetPreviewArrayItem(event.detail.item);
                    }
                    updateHiddenValue();
                });
            }
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
                textarea.style.setProperty('--w-theme-editor-textarea-height', 'auto');
                textarea.style.setProperty('--w-theme-editor-textarea-height', textarea.scrollHeight + 'px');
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
                const configData = collectWidgetConfigData(this);

                try {
                    const result = await apiJson(getWidgetConfigSaveUrl(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            layout_id: layoutId,
                            config: configData,
                            locale: getActiveConfigLocale() || null,
                        })
                    });

                    if (result.success) {
                        const normalizedConfig = (result && result.config && typeof result.config === 'object') ? result.config : configData;
                        await queueWidgetConfigOwnership(result.node_uid, normalizedConfig, getActiveConfigLocale());
                        showToast('配置已保存', 'success');
                        if (!getActiveConfigLocale() && state.selectedWidget) {
                            state.selectedWidget.dataset.config = JSON.stringify(normalizedConfig);
                        }
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
        container.querySelectorAll('.w-param-btn-delete-widget, .w-theme-editor-delete-widget').forEach(btn => {
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
                        const wEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
                        if (wEl) {
                            slotIdFb = wEl.getAttribute('data-slot-id') || wEl.closest('[data-wslot]')?.getAttribute('data-wslot') || wEl.closest('[data-slot]')?.getAttribute('data-slot') || '';
                            if (wEl.closest('header, [data-wslot-position="header"], .site-header')) areaFb = 'header';
                            else if (wEl.closest('footer, [data-wslot-position="footer"], .site-footer')) areaFb = 'footer';
                        }
                    }
                } catch (e) {}

                try {
                    const widgetContext = resolveWidgetContextFromIframe(layoutId);
                    const result = await apiJson(config.apiDeleteWidget, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(buildWidgetDeletePayload(layoutId, slotIdFb, areaFb, widgetContext))
                    });

                    if (result.success) {
                        await queueRemovedLayoutNode(result, {
                            layoutId,
                            nodeUid: widgetContext.nodeUid,
                        });
                        showToast('部件已删除', 'success');

                        // 恢复iframe预览区的原始内容
                        const iframe = elements.previewFrame;
                        if (iframe && iframe.contentDocument) {
                            const widgetEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
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
                                            <div class="slot-placeholder w-theme-editor-slot-placeholder">
                                                ${iconSvg('inbox')}
                                                <p class="w-theme-editor-slot-placeholder__title">插槽原本为空</p>
                                                <p class="w-theme-editor-slot-placeholder__hint">拖入部件或点击选择新部件</p>
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
                        document.querySelector(`.preview-widget-item${dataLayoutIdSelector(layoutId)}`)?.remove();

                        // 关闭配置面板
                        elements.configPanel.classList.remove('show');
                        await refreshDefaultInjectionApplications({ render: state.widgetLibraryTab === 'applications', silent: true });
                    } else {
                        showToast(result.message || '删除失败', 'error');
                    }
                } catch (err) {
                    console.error('Delete widget error:', err);
                    showToast('删除失败', 'error');
                }
            });
        });

        initFallbackEavSelects(container);
        initWidgetParamPickers(container);
    }

    function setParamGroupExpanded(group, expanded) {
        if (!(group instanceof HTMLElement)) return;
        const title = group.querySelector(':scope > .w-param-group-title');
        const fields = group.querySelector(':scope > .w-param-fields');
        group.classList.toggle('w-param-collapsed', !expanded);
        group.dataset.state = expanded ? 'open' : 'closed';
        if (title instanceof HTMLElement) title.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (fields instanceof HTMLElement) fields.hidden = !expanded;
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
                group.hidden = !show;
                if (show && kw) {
                    setParamGroupExpanded(group, true);
                    group.querySelectorAll('.w-param-field').forEach(field => {
                        const labelEl = field.querySelector('.w-param-label, .w-param-array-label');
                        const t = (labelEl && labelEl.textContent) ? labelEl.textContent.trim().toLowerCase() : '';
                        field.hidden = !t.includes(kw);
                    });
                } else if (show) {
                    group.querySelectorAll('.w-param-field').forEach(f => { f.hidden = false; });
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
                group.hidden = !show;
                if (show && kw) {
                    group.classList.remove('collapsed');
                    group.querySelectorAll('.config-field').forEach(field => {
                        const labelEl = field.querySelector('label, .form-label');
                        const t = (labelEl && labelEl.textContent) ? labelEl.textContent.trim().toLowerCase() : '';
                        field.hidden = !t.includes(kw);
                    });
                } else if (show) {
                    group.querySelectorAll('.config-field').forEach(f => { f.hidden = false; });
                }
            });
        });
    }

    /**
     * 绑定数组项事件
     */
    function rememberWidgetPreviewArrayItem(item) {
        const form = item.closest('form[data-layout-id]');
        const layoutId = form?.dataset?.layoutId;
        const itemsContainer = item.closest('.array-items-container') || item.closest('.w-param-array-items');
        if (!form || !layoutId || !itemsContainer) {
            return;
        }

        const itemSelector = item.classList.contains('w-param-array-item') ? '.w-param-array-item' : '.array-item';
        const itemIndex = Array.from(itemsContainer.querySelectorAll(itemSelector)).indexOf(item);
        if (itemIndex < 0) {
            return;
        }

        const indexValue = String(itemIndex);
        form.dataset.previewArrayItemIndex = indexValue;
        state.previewArrayItemIndexByLayout[String(layoutId)] = indexValue;
    }

    function activateWidgetPreviewArrayItem(layoutId, itemIndex) {
        const index = Number.parseInt(itemIndex, 10);
        if (!Number.isFinite(index) || index < 0) {
            return;
        }

        const iframe = elements.previewFrame;
        const doc = iframe?.contentDocument || iframe?.contentWindow?.document;
        if (!doc) {
            return;
        }

        const widgetEl = doc.querySelector(dataLayoutIdSelector(layoutId));
        if (!widgetEl) {
            return;
        }

        const slides = Array.from(widgetEl.querySelectorAll('.widget-hero-slider .slide[data-index], .slider-wrapper .slide[data-index]'));
        if (slides.length <= index) {
            return;
        }

        slides.forEach((slide, position) => {
            const slideIndex = Number.parseInt(slide.dataset.index || String(position), 10);
            const active = slideIndex === index || position === index;
            slide.classList.toggle('active', active);
            if (!active) {
                slide.classList.remove('prev');
            }
        });

        const dots = Array.from(widgetEl.querySelectorAll('.widget-hero-slider .dot[data-index], .slider-dots .dot[data-index]'));
        dots.forEach((dot, position) => {
            const dotIndex = Number.parseInt(dot.dataset.index || String(position), 10);
            dot.classList.toggle('active', dotIndex === index || position === index);
        });
    }

    function bindArrayItemEvents(item, updateCallback) {
        // 删除按钮
        const removeBtn = item.querySelector('.w-param-array-remove, .w-theme-editor-remove-array-item');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                rememberWidgetPreviewArrayItem(item);
                const wrapper = item.closest('.w-param-array') || item.closest('.array-editor-wrapper');
                const isParamArray = wrapper?.classList.contains('w-param-array');
                const itemSelector = isParamArray ? '.w-param-array-item' : '.array-item';
                const emptyHtml = isParamArray
                    ? '<div class="w-param-array-empty"><p>暂无项目</p></div>'
                    : '<div class="array-empty-state">' + iconSvg('list') + '<p>暂无项目</p></div>';
                const minItems = parseInt(wrapper?.dataset?.minItems) || 0;
                const itemsContainer = wrapper?.querySelector(isParamArray ? '.w-param-array-items' : '.array-items-container');
                const currentCount = itemsContainer?.querySelectorAll(itemSelector).length || 0;

                if (currentCount <= minItems) {
                    showToast(`至少需要 ${minItems} 个项目`, 'warning');
                    return;
                }

                item.remove();

                if (currentCount - 1 === 0 && itemsContainer) {
                    itemsContainer.innerHTML = emptyHtml;
                }

                updateCallback?.();
            });
        }

        // 输入变化
        item.querySelectorAll('input, select, textarea').forEach(input => {
            const handleArrayItemChange = () => {
                rememberWidgetPreviewArrayItem(item);
                updateCallback?.();
            };
            input.addEventListener('input', handleArrayItemChange);
            input.addEventListener('change', handleArrayItemChange);
        });

        item.querySelectorAll('.array-image-field input[data-field]').forEach(input => {
            input.addEventListener('input', function() {
                const preview = this.closest('.array-image-field')?.querySelector('.array-image-preview');
                if (!preview) {
                    return;
                }

                const url = this.value.trim();
                preview.innerHTML = '';
                if (url) {
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = '';
                    preview.appendChild(img);
                } else {
                    preview.innerHTML = iconSvg('image');
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
            modeBadge = '<span class="w-badge" data-tone="warning">' + iconSvg('lock') + ' 独占 (仅限1个部件)</span>';
        } else if (isMultiple) {
            modeBadge = '<span class="w-badge" data-tone="info">' + iconSvg('stack') + ' 可多个部件</span>';
        }

        // 生成接受的部件列表 HTML
        let acceptHtml = '';
        if (acceptCodes.length === 0 || acceptCodes.includes('*')) {
            acceptHtml = '<span class="w-badge" data-tone="success">接受所有部件</span>';
        } else {
            acceptHtml = acceptCodes.map(code =>
                `<span class="w-badge" data-tone="primary">${escapeHtml(code)}</span>`
            ).join('');
        }

        const html = `
            <div class="slot-empty-panel">
                <div class="slot-empty-header">
                    <div class="slot-icon">
                        ${iconSvg('grid')}
                    </div>
                    <div class="slot-title">
                        <h5>${slotName}</h5>
                        <span class="slot-id w-text" data-tone="muted">ID: ${escapeHtml(slotId)}</span>
                        ${modeBadge ? `<div class="w-theme-editor-slot-mode">${modeBadge}</div>` : ''}
                    </div>
                </div>

                <div class="slot-empty-state">
                    <div class="empty-icon">
                        ${iconSvg('inbox')}
                    </div>
                    <h6>该插槽暂无部件</h6>
                    <p class="w-text" data-tone="muted">此区域目前显示的是原生 HTML 内容</p>
                </div>

                <div class="slot-accept-info">
                    <label>${iconSvg('check-circle')} 可接受的部件：</label>
                    <div class="slot-accept-list">
                        ${acceptHtml}
                    </div>
                </div>

                <div class="slot-action-hint">
                    <div class="action-hint-box">
                        ${iconSvg('drag')}
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
     * @param {object|null} widget - 部件数据
     * @param {object} slot - 插槽数据 { id, position, name, exclusive }
     * @param {number|undefined} iframeSortOrder - iframe 计算的插入位置
     */
    async function handleWidgetDropped(widget, slot, iframeSortOrder) {
        const widgetData = widget || state.draggingWidget;
        if (!widgetData || !widgetData.code) {
            showToast('无法获取拖拽部件数据，请重新拖拽', 'error');
            return null;
        }
        if (!slot || !slot.id) {
            showToast('无法识别目标插槽', 'error');
            return null;
        }

        if (!isSlotDataAccepted(slot, widgetData)) {
            showToast(`部件 "${widgetData.name || widgetData.code}" 不能放入插槽 "${slot.name || slot.id}"`, 'warning');
            return null;
        }

        const area = slot.position || slot.area || slot.id;
        const slotId = slot.id;
        const exclusive = slot.exclusive === true || widgetData.exclusive === true || isExclusiveSlot(slotId, widgetData.code);

        const maxWidgets = Number.parseInt(slot.max, 10);
        const currentCount = Number.parseInt(slot.current_count, 10);
        const singleSlotFull = !exclusive
            && (slot.multiple === false || slot.multiple === 'false')
            && Number.isFinite(currentCount)
            && currentCount >= 1;
        if (singleSlotFull) {
            showToast(`插槽 "${slot.name || slotId}" 仅允许一个组件`, 'warning');
            return null;
        }
        if (!exclusive && Number.isFinite(maxWidgets) && maxWidgets > 0 && Number.isFinite(currentCount) && currentCount >= maxWidgets) {
            showToast(`插槽 "${slot.name || slotId}" 已满（${currentCount}/${maxWidgets}），无法添加更多部件`, 'warning');
            return null;
        }

        // sort_order 优先级：独占=0 > iframe 传入 > 结构视图计算
        let sortOrder;
        if (exclusive) sortOrder = 0;
        else if (iframeSortOrder != null) sortOrder = iframeSortOrder;
        else sortOrder = getNextSlotSortOrder(slotId);

        return saveWidget({ area, slotId, widgetData, sortOrder, exclusive, switchToPreview: false });
    }

    function normalizeCode(code) {
        return String(code || '').trim().toLowerCase();
    }

    function normalizeCodeList(value) {
        if (value == null || value === false) {
            return [];
        }

        let items = [];
        if (Array.isArray(value)) {
            value.forEach(item => {
                if (Array.isArray(item)) {
                    items = items.concat(item);
                } else if (item && typeof item === 'object') {
                    items = items.concat(Object.keys(item));
                    Object.values(item).forEach(slotConfig => {
                        if (slotConfig && typeof slotConfig === 'object') {
                            items.push(slotConfig.id, slotConfig.code, slotConfig.slot);
                        }
                    });
                } else {
                    items.push(item);
                }
            });
        } else if (typeof value === 'object') {
            items = items.concat(Object.keys(value));
            Object.values(value).forEach(item => {
                if (item && typeof item === 'object') {
                    items.push(item.id, item.code, item.slot);
                }
            });
        } else {
            const raw = String(value).trim();
            if (raw.startsWith('[') || raw.startsWith('{')) {
                try {
                    return normalizeCodeList(JSON.parse(raw));
                } catch (err) {
                    // Fall through to comma parsing.
                }
            }
            items = raw.split(',');
        }

        const seen = new Set();
        items.forEach(item => {
            const code = normalizeCode(item);
            if (code) {
                seen.add(code);
            }
        });
        return Array.from(seen);
    }

    function normalizeAcceptCodes(accept) {
        return normalizeCodeList(accept);
    }

    function expandPageLayoutSupportCodes(pageLayouts) {
        const layouts = normalizeCodeList(pageLayouts);
        const codes = [];
        const layoutType = normalizeCode(state.layoutType || getCurrentPageType() || '');
        const layoutOption = normalizeCode(state.layoutOption || 'default');

        layouts.forEach(layout => {
            if (!layout || layout === '*') {
                return;
            }
            codes.push(`layout-${layout}`);
            if (layoutType && layout === layoutType && layoutOption && layoutOption !== 'default') {
                codes.push(`layout-${layoutType}-${layoutOption}`);
            }
        });

        return codes;
    }

    /**
     * 布局变体 accept 与通用 layout 码互通（与后端 ThemePlaceableRegistry 一致）。
     */
    function expandAcceptCodesForLayout(acceptCodes) {
        const normalized = normalizeCodeList(acceptCodes);
        const expanded = new Set(normalized);

        normalized.forEach(accept => {
            const match = accept.match(/^layout-([^-]+)-([^-]+)-(.+)$/);
            if (match) {
                expanded.add(`layout-${match[1]}-${match[3]}`);
            }
        });

        return Array.from(expanded);
    }

    function collectWidgetSupportCodes(widgetData) {
        const codes = [
            widgetData?.code,
            widgetData?.type,
            widgetData?.slot,
        ];

        normalizeCodeList(widgetData?.position || []).forEach(code => codes.push(code));
        normalizeCodeList(widgetData?.supports || []).forEach(code => codes.push(code));
        normalizeCodeList(widgetData?.slots || []).forEach(code => codes.push(code));
        expandPageLayoutSupportCodes(widgetData?.pageLayouts || widgetData?.page_layouts || []).forEach(code => codes.push(code));

        return normalizeCodeList(codes);
    }

    function collectWidgetElementSupportCodes(widgetEl) {
        let pageLayouts = widgetEl.getAttribute('data-widget-page-layouts') || '[]';
        try {
            pageLayouts = JSON.parse(pageLayouts);
        } catch (err) {
            pageLayouts = pageLayouts.split(',').map(s => s.trim()).filter(Boolean);
        }

        return collectWidgetSupportCodes({
            code: widgetEl.getAttribute('data-widget-code') || '',
            type: widgetEl.getAttribute('data-widget-type') || '',
            slot: widgetEl.getAttribute('data-widget-slot') || '',
            position: widgetEl.getAttribute('data-widget-position') || '',
            supports: widgetEl.getAttribute('data-widget-supports') || '',
            slots: widgetEl.getAttribute('data-widget-slots') || '',
            pageLayouts: pageLayouts,
        });
    }

    function slotAcceptsWidgetCodes(acceptCodes, rejectCodes, slotId, widgetCodes) {
        const normalizedAccept = expandAcceptCodesForLayout(acceptCodes);
        const normalizedReject = normalizeCodeList(rejectCodes);
        const normalizedWidgetCodes = normalizeCodeList(widgetCodes);
        const normalizedSlotId = normalizeCode(slotId);

        if (normalizedReject.some(code => normalizedWidgetCodes.includes(code))) {
            return false;
        }

        if (normalizedSlotId && normalizedWidgetCodes.includes(normalizedSlotId)) {
            return true;
        }

        return normalizedAccept.length === 0
            || normalizedAccept.includes('*')
            || normalizedAccept.some(accept => normalizedWidgetCodes.includes(accept));
    }

    function isGenericSlotAcceptCode(code) {
        return [
            'header', 'footer', 'content', 'container', 'banner', 'carousel', 'slider',
            'product', 'category', 'navigation', 'search', 'breadcrumb', 'pagination',
            'social', 'newsletter', 'testimonial', 'faq', 'video', 'sidebar',
            'left_sidebar', 'right_sidebar'
        ].includes(normalizeCode(code));
    }

    function getRecommendationAcceptCodes(acceptCodes) {
        const normalizedAccept = expandAcceptCodesForLayout(acceptCodes);
        const specificAccept = normalizedAccept.filter(code => code !== '*' && !isGenericSlotAcceptCode(code));

        return specificAccept.length > 0 ? specificAccept : normalizedAccept;
    }

    function removeWidgetRecommendationEmptyState() {
        elements.widgetList?.querySelector('.widget-recommendation-empty')?.remove();
    }

    function showWidgetRecommendationEmptyState(slot) {
        const widgetList = elements.widgetList;
        if (!widgetList) {
            return;
        }

        removeWidgetRecommendationEmptyState();
        const emptyState = document.createElement('div');
        emptyState.className = 'widget-recommendation-empty';
        emptyState.innerHTML = `
            <div class="empty-icon">${iconSvg('inbox')}</div>
            <div class="empty-title">当前插槽暂无匹配部件</div>
            <div class="empty-desc">${escapeHtml(slot?.name || slot?.id || '该插槽')} 没有同类型可推荐部件</div>
        `;
        widgetList.appendChild(emptyState);
    }

    function clearWidgetRecommendations(slot) {
        const widgetList = elements.widgetList;
        if (!widgetList) {
            return;
        }

        widgetList.querySelectorAll('.widget-item').forEach(widget => {
            widget.hidden = true;
            widget.classList.remove('highlighted', 'area-matched', 'area-universal', 'area-not-matched', 'area-rejected');
        });
        widgetList.querySelectorAll('.widget-group').forEach(group => {
            group.hidden = true;
        });
        showWidgetRecommendationEmptyState(slot);
    }

    function isSlotDataAccepted(slot, widgetData) {
        return slotAcceptsWidgetCodes(
            slot.accept,
            slot.reject,
            slot.id,
            collectWidgetSupportCodes(widgetData)
        );
    }

    function dataAttributeSelector(attrName, value) {
        const safeValue = String(value || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        return `[${attrName}="${safeValue}"]`;
    }

    function dataLayoutIdSelector(layoutId) {
        return dataAttributeSelector('data-layout-id', layoutId);
    }

    function dataTemplateRefSelector(templateRef) {
        return dataAttributeSelector('data-template-ref', templateRef);
    }

    function dataWidgetIdentitySelector(identity) {
        const value = String(identity || '').trim();
        if (!value) {
            return '';
        }
        if (value.startsWith('tpl:')) {
            return dataTemplateRefSelector(value);
        }
        return dataLayoutIdSelector(value);
    }

    function resolveWidgetWrapper(element) {
        if (!element || element.nodeType !== 1) {
            return null;
        }
        if (element.matches('.weline-template-widget[data-template-ref], .widget-wrapper[data-layout-id], .widget-wrapper[data-weline-template-widget="1"]')) {
            return element;
        }
        return element.closest('.weline-template-widget[data-template-ref], .widget-wrapper[data-layout-id], .widget-wrapper[data-weline-template-widget="1"]');
    }

    function readWidgetIdentityFromElement(element) {
        const wrapper = resolveWidgetWrapper(element) || element;
        const layoutId = String(wrapper?.dataset?.layoutId || wrapper?.getAttribute?.('data-layout-id') || '').trim();
        const templateRef = String(wrapper?.dataset?.templateRef || wrapper?.getAttribute?.('data-template-ref') || '').trim();
        return {
            wrapper,
            layoutId,
            templateRef,
            identity: layoutId || templateRef,
            isTemplate: layoutId === '' && templateRef !== '',
            widgetCode: String(wrapper?.dataset?.widgetCode || wrapper?.getAttribute?.('data-widget-code') || '').trim(),
            widgetModule: String(wrapper?.dataset?.widgetModule || wrapper?.getAttribute?.('data-widget-module') || '').trim(),
            widgetType: String(wrapper?.dataset?.widgetType || wrapper?.getAttribute?.('data-widget-type') || '').trim(),
            widgetName: String(wrapper?.dataset?.widgetName || wrapper?.getAttribute?.('data-widget-name') || '').trim(),
            config: String(wrapper?.dataset?.config || wrapper?.getAttribute?.('data-config') || '{}'),
        };
    }

    function cssIdentifier(value) {
        const stringValue = String(value || '');
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(stringValue);
        }
        return stringValue.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    function firstNonEmptyValue(...values) {
        for (const value of values) {
            if (value === null || value === undefined) {
                continue;
            }
            if (Array.isArray(value) && !value.length) {
                continue;
            }
            if (String(value) === '') {
                continue;
            }
            return value;
        }
        return '';
    }

    function readSlotAttr(slotElement, attrName) {
        return slotElement ? slotElement.getAttribute(attrName) : null;
    }

    function findIframeSlotElement(iframeDoc, slotId) {
        if (!iframeDoc || !slotId) {
            return null;
        }

        return iframeDoc.querySelector([
            dataAttributeSelector('data-wslot', slotId),
            dataAttributeSelector('data-slot', slotId),
            dataAttributeSelector('data-slot-id', slotId)
        ].join(', '));
    }

    function buildSlotInfoFromElement(slotId, slotElement) {
        const catalogSlotCandidate = state.slots ? state.slots[slotId] : null;
        const catalogSlot = catalogSlotCandidate && typeof catalogSlotCandidate === 'object' ? catalogSlotCandidate : {};
        const position = firstNonEmptyValue(
            readSlotAttr(slotElement, 'data-wslot-position'),
            readSlotAttr(slotElement, 'data-position'),
            catalogSlot.position,
            catalogSlot.area,
            inferAreaFromSlotId(slotId)
        );
        const exclusiveAttr = readSlotAttr(slotElement, 'data-wslot-exclusive');
        const multipleAttr = readSlotAttr(slotElement, 'data-wslot-multiple');

        return {
            id: slotId,
            name: firstNonEmptyValue(
                readSlotAttr(slotElement, 'data-wslot-name'),
                readSlotAttr(slotElement, 'data-slot-name'),
                readSlotAttr(slotElement, 'data-name'),
                catalogSlot.name,
                slotId
            ),
            accept: firstNonEmptyValue(
                readSlotAttr(slotElement, 'data-wslot-accept'),
                readSlotAttr(slotElement, 'data-accept'),
                catalogSlot.accept,
                ''
            ),
            reject: firstNonEmptyValue(
                readSlotAttr(slotElement, 'data-wslot-reject'),
                readSlotAttr(slotElement, 'data-reject'),
                catalogSlot.reject,
                ''
            ),
            max: firstNonEmptyValue(readSlotAttr(slotElement, 'data-wslot-max'), catalogSlot.max, ''),
            min: firstNonEmptyValue(readSlotAttr(slotElement, 'data-wslot-min'), catalogSlot.min, ''),
            exclusive: exclusiveAttr !== null ? exclusiveAttr === 'true' : catalogSlot.exclusive === true,
            multiple: multipleAttr !== null ? multipleAttr !== 'false' : catalogSlot.multiple !== false,
            area: position
        };
    }

    function resolvePreviewWidgetElement(data) {
        const layoutId = data.layoutId || '';
        const templateRef = data.templateRef || '';
        let widgetElement = null;

        if (layoutId) {
            const selector = dataLayoutIdSelector(layoutId);
            try {
                const iframeDoc = elements.previewFrame?.contentDocument || elements.previewFrame?.contentWindow?.document;
                widgetElement = iframeDoc?.querySelector(`.preview-widget${selector}, .widget-wrapper${selector}, [data-widget-code]${selector}`) || null;
            } catch (err) {
                console.warn('[ThemeEditor] Unable to read selected preview widget:', err);
            }
            if (!widgetElement) {
                widgetElement = document.querySelector(`.preview-widget-item${selector}`);
            }
        }

        if (!widgetElement && templateRef) {
            try {
                const iframeDoc = elements.previewFrame?.contentDocument || elements.previewFrame?.contentWindow?.document;
                widgetElement = iframeDoc?.querySelector(dataAttributeSelector('data-template-ref', templateRef)) || null;
            } catch (err) {
                console.warn('[ThemeEditor] Unable to read template widget:', err);
            }
        }

        if (!widgetElement) {
            widgetElement = document.createElement('div');
        }

        if (layoutId) {
            widgetElement.dataset.layoutId = layoutId;
        }
        if (templateRef) {
            widgetElement.dataset.templateRef = templateRef;
        }
        widgetElement.dataset.widgetCode = data.widgetCode || widgetElement.dataset.widgetCode || '';
        widgetElement.dataset.widgetName = data.widgetName || widgetElement.dataset.widgetName || '';
        widgetElement.dataset.widgetModule = data.widgetModule || widgetElement.dataset.widgetModule || '';
        widgetElement.dataset.widgetType = data.widgetType || widgetElement.dataset.widgetType || '';
        widgetElement.dataset.config = data.config || widgetElement.dataset.config || '{}';
        if (data.slotId) {
            widgetElement.dataset.slotId = data.slotId;
        }

        return widgetElement;
    }

    function markPreviewWidgetSelected(layoutId) {
        if (!layoutId) {
            return;
        }

        const selector = String(layoutId).startsWith('tpl:')
            ? dataAttributeSelector('data-template-ref', layoutId)
            : dataLayoutIdSelector(layoutId);
        document.querySelectorAll('.preview-widget-item.selected').forEach(el => {
            el.classList.remove('selected');
        });
        document.querySelector(`.preview-widget-item${selector}`)?.classList.add('selected');

        try {
            const iframeDoc = elements.previewFrame?.contentDocument || elements.previewFrame?.contentWindow?.document;
            iframeDoc?.querySelectorAll('.preview-widget.selected, .widget-wrapper.selected, .weline-template-widget.selected').forEach(el => {
                el.classList.remove('selected');
            });
            iframeDoc?.querySelector(`.preview-widget${selector}, .widget-wrapper${selector}, [data-widget-code]${selector}, .weline-template-widget${selector}`)?.classList.add('selected');
        } catch (err) {
            console.warn('[ThemeEditor] Unable to mark selected preview widget:', err);
        }
    }

    function handleIframeWidgetElementClick(target) {
        const widgetWrapper = target?.closest?.(
            '.widget-wrapper[data-layout-id], .preview-widget[data-layout-id], [data-widget-code][data-layout-id], '
            + '.weline-template-widget[data-template-ref], .widget-wrapper[data-weline-template-widget="1"]'
        );
        if (!widgetWrapper) {
            return false;
        }

        const nestedSlot = target.closest('[data-wslot], [data-slot], .content-slot');
        if (nestedSlot && nestedSlot.closest('.widget-wrapper[data-layout-id], .weline-template-widget') === widgetWrapper) {
            // 点在子槽上时交给 slot 选择；点在模板部件本体上继续
            if (target.closest('[data-wslot], [data-slot]') !== widgetWrapper && nestedSlot.contains(widgetWrapper) === false) {
                return false;
            }
        }

        handlePreviewWidgetSelected({
            type: 'widget-selected',
            layoutId: widgetWrapper.dataset.layoutId || widgetWrapper.getAttribute('data-layout-id') || '',
            templateRef: widgetWrapper.dataset.templateRef || widgetWrapper.getAttribute('data-template-ref') || '',
            widgetCode: widgetWrapper.dataset.widgetCode || widgetWrapper.getAttribute('data-widget-code') || '',
            widgetModule: widgetWrapper.dataset.widgetModule || widgetWrapper.getAttribute('data-widget-module') || '',
            widgetType: widgetWrapper.dataset.widgetType || widgetWrapper.getAttribute('data-widget-type') || '',
            widgetName: widgetWrapper.dataset.widgetName || widgetWrapper.getAttribute('data-widget-name') || '',
            config: widgetWrapper.dataset.config || widgetWrapper.getAttribute('data-config') || '{}',
            slotId: widgetWrapper.closest('[data-wslot], [data-slot]')?.getAttribute('data-wslot')
                || widgetWrapper.closest('[data-wslot], [data-slot]')?.getAttribute('data-slot')
                || ''
        });
        return true;
    }

    /**
     * 处理预览页面中选中部件
     */
    function handlePreviewWidgetSelected(data) {
        const widgetElement = resolvePreviewWidgetElement(data);
        if (data.templateRef && widgetElement && !widgetElement.dataset.layoutId) {
            widgetElement.dataset.templateRef = data.templateRef;
            if (data.slotId) {
                widgetElement.dataset.slotId = data.slotId;
            }
        }
        state.selectedWidget = widgetElement;
        state.selectedSlot = null;
        openConfigPanelForWidgetSelection();
        setConfigMode('widget');
        markPreviewWidgetSelected(widgetElement.dataset.layoutId || widgetElement.dataset.templateRef || '');
        loadWidgetConfig(widgetElement);
    }

    /**
     * 模板内嵌部件首次改参时物化到布局（copy-on-write）。
     * @returns {Promise<string|null>} layout_id
     */
    async function materializeTemplateWidgetIfNeeded(widgetElement, configData) {
        if (!widgetElement) return null;
        const existingLayoutId = widgetElement.dataset.layoutId || widgetElement.getAttribute('data-layout-id') || '';
        if (existingLayoutId) return existingLayoutId;

        const templateRef = widgetElement.dataset.templateRef || widgetElement.getAttribute('data-template-ref') || '';
        if (!templateRef) return null;

        const slotEl = widgetElement.closest('[data-wslot], [data-slot]');
        const slotId = widgetElement.dataset.slotId
            || slotEl?.getAttribute('data-wslot')
            || slotEl?.getAttribute('data-slot')
            || '';
        const area = slotEl?.getAttribute('data-wslot-position')
            || (slotEl?.closest('header') ? 'header' : (slotEl?.closest('footer') ? 'footer' : 'content'));

        let baseConfig = {};
        try {
            baseConfig = JSON.parse(widgetElement.dataset.config || widgetElement.getAttribute('data-config') || '{}') || {};
        } catch (e) {
            baseConfig = {};
        }
        const mergedConfig = Object.assign({}, baseConfig, configData || {}, { template_ref: templateRef });

        const result = await saveWidget({
            area,
            slotId,
            widgetData: {
                code: widgetElement.dataset.widgetCode || widgetElement.getAttribute('data-widget-code') || '',
                module: widgetElement.dataset.widgetModule || widgetElement.getAttribute('data-widget-module') || '',
                type: widgetElement.dataset.widgetType || widgetElement.getAttribute('data-widget-type') || '',
                name: widgetElement.dataset.widgetName || widgetElement.getAttribute('data-widget-name') || '',
                config: mergedConfig,
            },
            sortOrder: 0,
            exclusive: false,
            switchToPreview: false,
        });
        const layoutId = result?.data?.layout_id || null;
        if (layoutId) {
            widgetElement.dataset.layoutId = String(layoutId);
            widgetElement.setAttribute('data-layout-id', String(layoutId));
        }
        return layoutId ? String(layoutId) : null;
    }

    /**
     * 处理预览页面中点击插槽
     */
    function handlePreviewSlotClicked(data) {
        handleSlotSelected({
            id: data.slot || '',
            name: data.name || data.slot || '',
            accept: data.accept ?? '',
            reject: data.reject || '',
            area: data.area || data.position || '',
            exclusive: data.exclusive === true || data.exclusive === 'true',
            multiple: data.multiple !== false && data.multiple !== 'false',
        });
    }

    /**
     * 高亮可接受的部件并排序
     */
    function highlightAcceptableWidgets(acceptCodes) {
        const slot = state.selectedSlot || { id: '', accept: acceptCodes || [], reject: '' };
        const normalizedAccept = getRecommendationAcceptCodes(slot.accept ?? acceptCodes ?? []);
        const normalizedReject = normalizeCodeList(slot.reject ?? []);
        removeWidgetRecommendationEmptyState();

        if (!normalizedAccept || normalizedAccept.length === 0) {
            clearWidgetRecommendations(slot);
            // 选中 slot 后必须给出明确推荐；没有可匹配契约时清空右侧列表，避免沿用上次推荐。
            return;
        }

        // accept 包含 * 表示接受所有部件，等同于无限制
        if (normalizedAccept.includes('*')) {
            applyWidgetLibraryTabVisibility();
            return document.querySelectorAll('.widget-item:not([hidden]):not(.widget-library-tab-hidden)').length;
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
        if (!widgetList) return 0;

        let totalChecked = 0, totalMatched = 0, totalHidden = 0;

        // 简单逻辑：遍历所有部件，只显示插槽 accept 列表中的部件
        widgetList.querySelectorAll('.widget-group').forEach(group => {
            const groupContent = group.querySelector('.widget-group-content');
            if (!groupContent) {
                group.hidden = true;
                return;
            }

            const allWidgets = Array.from(groupContent.querySelectorAll('.widget-item'));
            let hasMatch = false;

            allWidgets.forEach(widget => {
                totalChecked++;
                // 已被区域过滤隐藏的，保持隐藏不参与
                if (widget.hidden) {
                    totalHidden++;
                    return;
                }

                const isMatch = slotAcceptsWidgetCodes(
                    normalizedAccept,
                    normalizedReject,
                    slot.id || '',
                    collectWidgetElementSupportCodes(widget)
                );

                if (isMatch) {
                    hasMatch = true;
                    totalMatched++;
                    widget.hidden = false;
                    widget.classList.add('highlighted');
                } else {
                    widget.hidden = true;
                    totalHidden++;
                }
            });

            // 没有匹配部件的分组整体隐藏
            group.hidden = !hasMatch;
            if (hasMatch && group.classList.contains('collapsed')) {
                group.classList.remove('collapsed');
            }
        });

        if (totalMatched === 0) {
            clearWidgetRecommendations(slot);
        }

        applyWidgetLibraryTabVisibility();
        return totalMatched;

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
        removeWidgetRecommendationEmptyState();

        // 移除所有高亮，恢复被隐藏的部件和分组
        document.querySelectorAll('.widget-item.highlighted').forEach(el => {
            el.classList.remove('highlighted');
        });
        document.querySelectorAll('.widget-item').forEach(el => {
            el.hidden = false;
        });
        document.querySelectorAll('.widget-group').forEach(el => {
            el.hidden = false;
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
        applyWidgetLibraryTabVisibility();
    }

    /**
     * 获取带页面类型参数的部件 API URL（由 Weline.Api resource 使用）
     */
    function getWidgetsApiUrl() {
        const url = new URL(config.apiWidgets, window.location.origin);
        if (state.pageType) {
            url.searchParams.set('page_type', state.pageType);
        }
        if (state.themeId) {
            url.searchParams.set('theme_id', String(state.themeId));
        }
        if (state.editorArea) {
            url.searchParams.set('editor_area', state.editorArea);
        }
        appendWidgetLibraryFilterParams(url);
        return url.toString();
    }

    function invalidateWidgetsCatalogCache() {
        state.widgetsCatalogCache = null;
        state.widgetsCatalogPrefetch = null;
    }

    /** 通过 Theme Editor 的 Weline.Api 业务接口获取当前主题、区域和插槽过滤后的部件列表。 */
    async function fetchWidgetsData() {
        if (state.widgetsCatalogCache) {
            return state.widgetsCatalogCache;
        }
        if (state.widgetsCatalogPrefetch) {
            return state.widgetsCatalogPrefetch;
        }
        state.widgetsCatalogPrefetch = (async function () {
            try {
                const result = await apiJson(getWidgetsApiUrl());
                if (result && result.success && result.data && typeof result.data === 'object') {
                    const hasWidgets = Object.keys(result.data).some(function (type) {
                        const group = result.data[type];
                        return group && Array.isArray(group.widgets) && group.widgets.length > 0;
                    });
                    if (hasWidgets) {
                        state.widgetsCatalogCache = result;
                        return result;
                    }
                }
            } catch (err) {
                console.warn('[ThemeEditor] editor widgets query bridge failed:', err);
            }
            return { success: false, data: {} };
        })();
        try {
            return await state.widgetsCatalogPrefetch;
        } finally {
            state.widgetsCatalogPrefetch = null;
        }
    }

    function prefetchWidgetsCatalog() {
        if (!state.themeId || state.widgetsCatalogCache || state.widgetsCatalogPrefetch) {
            return;
        }
        fetchWidgetsData().catch(function () {
            return { success: false, data: {} };
        });
    }

    /**
     * 若 #widgetList 内无部件（服务端未输出），则请求部件列表并渲染到面板
     */
    async function loadWidgetListIfEmpty() {
        const listEl = elements.widgetList;
        if (!listEl) return;
        const hasItems = listEl.querySelector('.widget-item');
        if (hasItems) return;
        await reloadWidgetLibrary({ silent: true });
    }

    /**
     * 部件库分页状态（懒加载 + 无限滚动 + 插槽/关键词过滤）
     */
    function getWidgetLibState() {
        if (!state.widgetLib) {
            state.widgetLib = {
                offset: 0,
                limit: 50,
                total: 0,
                hasMore: true,
                loading: false,
                initialized: false,
                slot: null,
                slotArea: null,
                slotLabel: '',
                keyword: '',
            };
        }
        return state.widgetLib;
    }

    function isDashboardLayoutMode() {
        return normalizeCode(state.pageType || state.layoutType || '') === 'dashboard'
            && normalizeCode(state.editorArea || '') === 'backend';
    }

    function isDashboardWidgetLibraryMode() {
        return true;
    }

    function normalizeCodeTail(code) {
        const parts = normalizeCode(code).replace(/\\/g, '/').split('/').filter(Boolean);
        return parts.length ? parts[parts.length - 1] : '';
    }

    function isDashboardContractWidget(widgetData) {
        if (!isDashboardLayoutMode()) {
            return false;
        }
        const codes = [
            widgetData?.code,
            widgetData?.type,
            widgetData?.slot,
        ];

        normalizeCodeList(widgetData?.position || []).forEach(code => codes.push(code));
        normalizeCodeList(widgetData?.supports || []).forEach(code => codes.push(code));
        normalizeCodeList(widgetData?.slots || []).forEach(code => codes.push(code));

        return normalizeCodeList(codes).some(code => code === 'dashboard' || code.startsWith('dashboard-'));
    }

    function getDashboardWidgetLibraryTab(widgetData) {
        if (isDashboardContractWidget(widgetData)) {
            return 'general';
        }

        const moduleName = normalizeCode(widgetData?.module || '');
        const widgetType = normalizeCode(widgetData?.type || '');
        const widgetCode = normalizeCode(widgetData?.code || '');
        const widgetCodeTail = normalizeCodeTail(widgetCode);
        const groupType = normalizeCode(widgetData?.group_type || widgetData?.groupType || '');
        const groupLabel = normalizeCode(widgetData?.group_label || widgetData?.groupLabel || '');
        const supportCodes = collectWidgetSupportCodes(widgetData);
        const isThemeComponent = moduleName === 'weline_theme'
            && (widgetType === 'theme_component' || widgetCode.includes('/') || widgetCode.startsWith('basic/'));
        const basicByCode = widgetCode.startsWith('basic/') || DASHBOARD_BASIC_WIDGET_CODES.has(widgetCodeTail);
        const basicByProtocol = supportCodes.some(code => code === 'builder-component' || code.startsWith('builder-'));
        const basicByGroup = groupType === 'basic'
            || groupType === 'base'
            || groupLabel.includes('基础')
            || groupLabel.includes('base');

        return isThemeComponent && (basicByCode || basicByGroup || basicByProtocol) ? 'basic' : 'general';
    }

    function getWidgetLibraryDataFromElement(item) {
        if (!item) {
            return {};
        }

        let pageLayouts = item.dataset.widgetPageLayouts || '[]';
        try {
            pageLayouts = JSON.parse(pageLayouts);
        } catch (err) {
            pageLayouts = normalizeCodeList(pageLayouts);
        }

        const group = item.closest('.widget-group');
        return {
            code: item.dataset.widgetCode || '',
            module: item.dataset.widgetModule || '',
            type: item.dataset.widgetType || '',
            slot: item.dataset.widgetSlot || '',
            position: item.dataset.widgetPosition || '',
            supports: item.dataset.widgetSupports || '',
            slots: item.dataset.widgetSlots || '',
            page_layouts: pageLayouts,
            group_type: group?.dataset.type || '',
            group_label: group?.querySelector('.widget-group-header span:not(.widget-count)')?.textContent || '',
        };
    }

    function resolveWidgetLibraryItemTab(item) {
        const tab = getDashboardWidgetLibraryTab(getWidgetLibraryDataFromElement(item));
        if (item) {
            item.dataset.widgetLibraryTab = tab;
        }
        return tab;
    }

    function resetWidgetLibraryInlineFilter() {
        const listEl = elements.widgetList;
        if (!listEl) {
            return;
        }
        removeWidgetRecommendationEmptyState();
        listEl.querySelectorAll('.widget-item').forEach(item => {
            item.hidden = false;
            item.classList.remove('highlighted', 'area-matched', 'area-universal', 'area-not-matched', 'area-rejected');
        });
        listEl.querySelectorAll('.widget-group').forEach(group => {
            group.hidden = false;
        });
    }

    function setWidgetLibraryTab(tab, options = {}) {
        const previousMode = state.widgetLibraryRenderMode || 'widgets';
        state.widgetLibraryTab = tab === 'basic' || tab === 'applications' ? tab : 'general';
        if (state.widgetLibraryTab === 'applications') {
            applyWidgetLibraryTabVisibility();
            loadDefaultInjectionLibrary({ silent: options.silent === true });
        } else if (previousMode === 'applications') {
            reloadWidgetLibrary({ silent: true });
        } else if (!options.silent && !options.skipSlotRefresh && state.selectedSlot) {
            applySlotWidgetFilter(state.selectedSlot, { autoSwitchTab: false });
        } else {
            applyWidgetLibraryTabVisibility();
        }
    }

    function initWidgetLibraryTabs() {
        if (!elements.widgetLibraryTabs) {
            return;
        }
        elements.widgetLibraryTabs.hidden = false;
        bindWidgetLibraryTabButtons();
        setWidgetLibraryTab(state.widgetLibraryTab || 'general', { silent: true });
    }

    function bindWidgetLibraryTabButtons() {
        if (!elements.widgetLibraryTabs) {
            return;
        }

        elements.widgetLibraryTabs.querySelectorAll('[data-widget-library-tab]').forEach(button => {
            if (button.dataset.widgetLibraryTabBound === '1') {
                return;
            }
            button.dataset.widgetLibraryTabBound = '1';
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                setWidgetLibraryTab(this.getAttribute('data-widget-library-tab') || 'general');
            });
        });
    }

    function updateWidgetLibraryTabCounts() {
        if (!elements.widgetLibraryTabs) {
            return;
        }

        const counts = { ...(state.widgetLibraryTabCounts || { general: 0, basic: 0, applications: 0 }) };
        if (state.widgetLibraryRenderMode !== 'applications') {
            counts.general = 0;
            counts.basic = 0;
            elements.widgetList?.querySelectorAll('.widget-item').forEach(item => {
                const tab = resolveWidgetLibraryItemTab(item);
                counts[tab === 'basic' ? 'basic' : 'general']++;
            });
        }
        counts.applications = state.defaultInjectionLib?.total || state.defaultInjectionLib?.items?.length || 0;
        state.widgetLibraryTabCounts = counts;

        elements.widgetLibraryTabs.querySelectorAll('[data-widget-library-tab-count]').forEach(el => {
            const tab = el.getAttribute('data-widget-library-tab-count') || 'general';
            el.textContent = String(counts[tab] || 0);
        });
    }

    function applyWidgetLibraryTabVisibility() {
        const listEl = elements.widgetList;
        const tabsEl = elements.widgetLibraryTabs;
        if (!listEl) {
            return;
        }

        if (!state.selectedSlot) {
            resetWidgetLibraryInlineFilter();
        }

        const dashboardMode = isDashboardWidgetLibraryMode();
        if (tabsEl) {
            tabsEl.hidden = !dashboardMode;
            tabsEl.querySelectorAll('[data-widget-library-tab]').forEach(button => {
                button.classList.toggle('active', (button.getAttribute('data-widget-library-tab') || 'general') === state.widgetLibraryTab);
            });
        }

        if (state.widgetLibraryTab === 'applications') {
            updateWidgetLibraryTabCounts();
            return;
        }

        listEl.querySelectorAll('.widget-item').forEach(item => {
            const tab = resolveWidgetLibraryItemTab(item);
            const active = !dashboardMode || tab === state.widgetLibraryTab;
            item.classList.toggle('widget-library-tab-hidden', !active);
            item.classList.toggle('widget-tab-disabled', !active);
            item.draggable = active;
        });

        listEl.querySelectorAll('.widget-group').forEach(group => {
            const items = Array.from(group.querySelectorAll('.widget-item'));
            const hasActiveItem = !dashboardMode
                || items.some(item => resolveWidgetLibraryItemTab(item) === state.widgetLibraryTab && !item.hidden);
            group.dataset.widgetLibraryTab = hasActiveItem && dashboardMode ? state.widgetLibraryTab : (group.dataset.widgetLibraryTab || 'general');
            group.classList.toggle('widget-library-tab-hidden', !hasActiveItem);
        });

        updateWidgetLibraryTabCounts();
    }

    function isWidgetLibraryItemActive(item) {
        if (!item || !isDashboardWidgetLibraryMode()) {
            return true;
        }
        const tab = resolveWidgetLibraryItemTab(item);
        return tab === state.widgetLibraryTab && !item.classList.contains('widget-library-tab-hidden');
    }

    /**
     * 预加载部件库：编辑器初始化后立即与预览 iframe 并行拉取，打开右侧面板时列表通常已就绪。
     * iframe load 与短兜底定时器仅作二次保障。
     */
    function deferWidgetLibraryLoad() {
        if (!state.themeId) {
            return;
        }
        queueMicrotask(function () {
            initWidgetLibraryOnce();
            prefetchWidgetsCatalog();
        });
        setTimeout(function () { initWidgetLibraryOnce(); }, 3000);
    }

    /**
     * 仅初始化一次部件库（由 iframe load 或兜底定时器触发）
     */
    function initWidgetLibraryOnce() {
        const lib = getWidgetLibState();
        if (lib.initialized || !state.themeId) {
            return;
        }
        lib.initialized = true;
        initWidgetInfiniteScroll();
        loadWidgetLibrary({ reset: true, silent: true });
    }

    /**
     * 构造部件库分页接口 URL
     */
    function buildWidgetLibUrl(lib) {
        const url = new URL(config.apiWidgets, window.location.origin);
        if (state.pageType) url.searchParams.set('page_type', state.pageType);
        if (state.themeId) url.searchParams.set('theme_id', String(state.themeId));
        if (state.editorArea) url.searchParams.set('editor_area', state.editorArea);
        appendWidgetLibraryFilterParams(url);
        url.searchParams.set('offset', String(lib.offset));
        url.searchParams.set('limit', String(lib.limit));
        if (lib.keyword) url.searchParams.set('keyword', lib.keyword);
        if (lib.slot) {
            url.searchParams.set('slot_id', lib.slot);
            if (lib.slotArea) url.searchParams.set('area', lib.slotArea);
        }
        return url.toString();
    }

    function buildDefaultInjectionUrl() {
        const url = new URL(config.apiDefaultInjections || `${config.apiBase}/default-injections`, window.location.origin);
        const payload = buildLayoutVersionIdentityPayload({
            editor_area: getEffectiveEditorArea(state.editorArea || 'frontend'),
        });
        Object.keys(payload).forEach(function(key) {
            const value = payload[key];
            if (value !== undefined && value !== null && value !== '') {
                url.searchParams.set(
                    key,
                    typeof value === 'object' ? JSON.stringify(value) : String(value)
                );
            }
        });
        const lib = getWidgetLibState();
        if (lib.keyword) {
            url.searchParams.set('keyword', lib.keyword);
        }
        return url.toString();
    }

    async function loadDefaultInjectionLibrary(options = {}) {
        const listEl = elements.widgetList;
        if (!listEl || !state.themeId || !config.apiDefaultInjections) {
            return;
        }
        const defaults = state.defaultInjectionLib;
        const shouldRender = options.render !== false && state.widgetLibraryTab === 'applications';
        if (defaults.loading) {
            if (shouldRender) {
                defaults.renderAfterLoad = true;
                listEl.innerHTML = '<div class="widget-list-loading">'
                    + '<span class="widget-list-loading-text">' + escapeHtml(translateUiText('应用建议加载中...')) + '</span></div>';
            }
            return;
        }

        defaults.loading = true;
        if (shouldRender) {
            state.widgetLibraryRenderMode = 'applications';
        }
        if (shouldRender && options.silent !== true) {
            listEl.innerHTML = '<div class="widget-list-loading">'
                + '<span class="w-spinner" role="status"><span class="w-visually-hidden">' + escapeHtml(translateUiText('加载中...')) + '</span></span>'
                + '<span class="widget-list-loading-text">' + escapeHtml(translateUiText('应用建议加载中...')) + '</span></div>';
        } else if (shouldRender) {
            listEl.innerHTML = '<div class="widget-list-loading">'
                + '<span class="widget-list-loading-text">' + escapeHtml(translateUiText('应用建议加载中...')) + '</span></div>';
        }

        try {
            const result = await apiJson(buildDefaultInjectionUrl());
            const items = result && Array.isArray(result.items) ? result.items : [];
            defaults.items = items;
            defaults.total = typeof result.total === 'number' ? result.total : items.length;
            if (shouldRender) {
                renderDefaultInjectionItems(items);
            }
            updateWidgetLibraryTabCounts();
        } catch (err) {
            console.warn('[ThemeEditor] loadDefaultInjectionLibrary failed:', err);
            defaults.items = [];
            defaults.total = 0;
            if (shouldRender) {
                listEl.innerHTML = '<div class="widget-list-loading"><span class="widget-list-loading-text">'
                    + escapeHtml(translateUiText('应用建议加载失败')) + '</span></div>';
            }
            updateWidgetLibraryTabCounts();
        } finally {
            defaults.loading = false;
            if (defaults.renderAfterLoad && state.widgetLibraryTab === 'applications') {
                defaults.renderAfterLoad = false;
                renderDefaultInjectionItems(defaults.items || []);
            } else {
                defaults.renderAfterLoad = false;
            }
        }
    }

    async function refreshDefaultInjectionApplications(options = {}) {
        if (!state.themeId || !config.apiDefaultInjections) {
            return;
        }
        const render = options.render === true || state.widgetLibraryTab === 'applications';
        if (!render) {
            await loadDefaultInjectionLibrary({ silent: true, render: false });
            return;
        }
        await loadDefaultInjectionLibrary({ silent: options.silent === true, render: true });
    }

    function renderDefaultInjectionItems(items) {
        const listEl = elements.widgetList;
        if (!listEl) {
            return;
        }
        state.widgetLibraryRenderMode = 'applications';
        listEl.querySelector('.widget-load-more-hint')?.remove();
        if (!Array.isArray(items) || items.length === 0) {
            listEl.innerHTML = '<div class="widget-list-loading"><span class="widget-list-loading-text">'
                + escapeHtml(translateUiText('当前布局没有声明默认应用部件')) + '</span></div>';
            updateWidgetLibraryTabCounts();
            return;
        }

        listEl.innerHTML = '';
        items.forEach(function(item) {
            const itemEl = createDefaultInjectionItem(item);
            if (itemEl) {
                listEl.appendChild(itemEl);
            }
        });
        updateWidgetLibraryTabCounts();
    }

    function isDefaultInjectionApplied(item) {
        if (!item || typeof item !== 'object') {
            return false;
        }
        if (item.injection_status === 'missing') {
            return false;
        }
        return item.injection_status === 'applied'
            || item.applied === true
            || item.applied === 1;
    }

    function createDefaultInjectionItem(item) {
        if (!item || typeof item !== 'object') {
            return null;
        }
        const applied = isDefaultInjectionApplied(item);
        const el = document.createElement('div');
        el.className = 'widget-default-injection-item' + (applied ? ' is-applied' : '');
        el.dataset.injectionKey = item.injection_key || '';
        el.dataset.injectionStatus = applied ? 'applied' : 'missing';
        const name = item.name || item.code || translateUiText('未命名部件');
        const moduleText = [item.module, item.type, item.code].filter(Boolean).join(' / ');
        const targetParts = [
            item.layout_type || item.page_type || '',
            item.layout_option ? `${translateUiText('布局')}: ${item.layout_option}` : '',
            item.slot_id ? `${translateUiText('Slot')}: ${item.slot_id}` : '',
            item.area ? `${translateUiText('区域')}: ${item.area}` : '',
        ].filter(Boolean);
        const reason = item.reason || item.description || '';
        const statusBadge = applied
            ? '<span class="widget-default-injection-badge is-applied">' + iconSvg('check') + escapeHtml(translateUiText('已应用')) + '</span>'
            : (item.required
                ? '<span class="widget-default-injection-badge">' + iconSvg('warning') + escapeHtml(translateUiText('强烈推荐')) + '</span>'
                : '');
        const scopePrompt = applied
            ? translateUiText('已在当前布局身份中')
            : translateUiText('恢复范围：仅当前布局身份');

        el.innerHTML = '<div class="widget-default-injection-header">'
            + '<div class="widget-default-injection-title">'
            + '<div class="widget-default-injection-name">' + escapeHtml(name) + '</div>'
            + '<div class="widget-default-injection-module">' + escapeHtml(moduleText) + '</div>'
            + '</div>'
            + statusBadge
            + '</div>'
            + '<div class="widget-default-injection-target">' + escapeHtml(targetParts.join(' · ')) + '</div>'
            + (reason ? '<div class="widget-default-injection-reason">' + escapeHtml(reason) + '</div>' : '')
            + '<div class="widget-default-injection-scope">' + iconSvg('info') + '<span>' + escapeHtml(scopePrompt) + '</span></div>'
            + (applied
                ? ''
                : ('<div class="widget-default-injection-actions">'
                    + '<button type="button" class="btn-apply-default-injection w-theme-editor-apply-default-injection w-theme-editor-apply-default-injection-current" data-apply-scope="current" data-injection-key="' + escapeHtml(item.injection_key || '') + '" title="' + escapeHtml(translateUiText('只恢复当前布局身份')) + '">'
                    + iconSvg('cursor') + '<span>' + escapeHtml(translateUiText('应用当前身份')) + '</span>'
                    + '</button>'
                    + '</div>'));

        return el;
    }

    async function applyDefaultInjection(injectionKey, buttonEl, applyScope = 'current') {
        injectionKey = String(injectionKey || '').trim();
        if (!injectionKey) {
            showToast(translateUiText('缺少应用项标识'), 'warning');
            return;
        }
        applyScope = String(applyScope || 'current').trim() === 'all' ? 'all' : 'current';
        const defaults = state.defaultInjectionLib;
        if (defaults.applyingKey) {
            showToast(translateUiText('正在应用推荐部件，请稍候'), 'info');
            return;
        }

        defaults.applyingKey = injectionKey + ':' + applyScope;
        if (buttonEl) {
            buttonEl.disabled = true;
        }

        try {
            const payload = buildLayoutVersionIdentityPayload({
                injection_key: injectionKey,
                apply_scope: applyScope,
                editor_area: getEffectiveEditorArea(state.editorArea || 'frontend'),
            });
            const result = await apiJson(config.apiApplyDefaultInjection || `${config.apiBase}/apply-default-injection`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            if (!result || !result.success) {
                showToast(result?.message || translateUiText('应用失败'), 'error');
                return;
            }

            await loadScopedWorkspace('layout');

            const data = result.data || {};
            const layoutId = data.layout_id || '';
            const widgetData = data.widget || {
                module: data.module || '',
                type: data.type || '',
                code: data.code || '',
                name: data.name || data.code || '',
                config: data.config || {},
            };
            widgetData.module = widgetData.module || data.module || '';
            widgetData.type = widgetData.type || data.type || '';
            widgetData.code = widgetData.code || data.code || '';
            widgetData.name = widgetData.name || data.name || widgetData.code;
            widgetData.config = data.config || widgetData.default_config || {};

            if (layoutId) {
                addWidgetToStructureView(data.area || 'content', data.slot_id || null, widgetData, layoutId, data.exclusive === true);
                notifyDashboardLayoutMutated('default-injection-applied', {
                    layoutId: layoutId,
                    slotId: data.slot_id || null,
                    area: data.area || null,
                });
            }

            if (result.preview_html && layoutId) {
                const targetSlotId = data.slot_id || (data.area === 'footer' ? 'footer' : data.area === 'header' ? 'header' : null);
                updateWidgetPreviewInIframe(layoutId, result.preview_html, true, targetSlotId);
            } else {
                loadLayoutPreview();
            }

            const appliedCount = Number(result.applied_count || data.applied_count || 0);
            const successMessage = result.message
                || (applyScope === 'all' && appliedCount > 1
                    ? translateUiText('已应用到所有布局身份')
                    : translateUiText('已应用推荐部件'));
            showToast(successMessage, 'success');
            await refreshDefaultInjectionApplications({ render: state.widgetLibraryTab === 'applications', silent: true });
        } catch (err) {
            console.error('[ThemeEditor] apply default injection failed:', err);
            showToast(err?.message || translateUiText('应用失败'), 'error');
        } finally {
            defaults.applyingKey = '';
            if (buttonEl) {
                buttonEl.disabled = false;
            }
        }
    }

    /**
     * 加载部件库（分页）。reset=true 时清空并从第 0 页开始；否则加载下一页。
     */
    async function loadWidgetLibrary(options = {}) {
        const listEl = elements.widgetList;
        if (!listEl || !state.themeId) {
            return;
        }
        if (state.widgetLibraryTab === 'applications') {
            await loadDefaultInjectionLibrary(options);
            return;
        }
        const lib = getWidgetLibState();
        if (lib.loading) {
            return;
        }
        if (options.reset) {
            lib.offset = 0;
            lib.total = 0;
            lib.hasMore = true;
            listEl.innerHTML = '<div class="widget-list-loading" id="widgetListLoading">'
                + '<span class="w-spinner" role="status"><span class="w-visually-hidden">' + escapeHtml(translateUiText('加载中...')) + '</span></span>'
                + '<span class="widget-list-loading-text">' + escapeHtml(translateUiText('部件库加载中...')) + '</span></div>';
        } else if (!lib.hasMore) {
            return;
        }
        lib.loading = true;
        state.widgetLibraryRenderMode = 'widgets';
        try {
            const result = await apiJson(buildWidgetLibUrl(lib));
            const items = (result && Array.isArray(result.items)) ? result.items : [];
            if (result && typeof result.total === 'number') {
                lib.total = result.total;
            }
            if (options.reset) {
                const loadingEl = document.getElementById('widgetListLoading');
                if (loadingEl) loadingEl.remove();
            }
            appendWidgetItems(items);
            lib.offset += items.length;
            lib.hasMore = !!(result && result.has_more);
            if (lib.offset === 0 && items.length === 0) {
                const emptyText = lib.slot
                    ? translateUiText('该插槽暂无可用部件')
                    : translateUiText('当前主题暂无可用部件');
                listEl.innerHTML = '<div class="widget-list-loading"><span class="widget-list-loading-text">'
                    + escapeHtml(emptyText) + '</span></div>';
                if (!options.silent) showToast(emptyText, 'info');
            }
            renderWidgetLoadMoreHint();
            applyWidgetLibraryTabVisibility();
        } catch (err) {
            console.warn('[ThemeEditor] loadWidgetLibrary failed:', err);
            if (options.reset) {
                const loadingEl = document.getElementById('widgetListLoading');
                if (loadingEl) {
                    loadingEl.innerHTML = '<span class="widget-list-loading-text">'
                        + escapeHtml(translateUiText('当前主题暂无可用部件')) + '</span>';
                }
            }
        } finally {
            lib.loading = false;
        }
    }

    /**
     * 兼容旧调用：主题/区域/页面类型变化时重新加载部件库（重置分页，保留插槽/关键词过滤）
     */
    async function reloadWidgetLibrary(options = {}) {
        invalidateWidgetsCatalogCache();
        const lib = getWidgetLibState();
        lib.initialized = true;
        initWidgetInfiniteScroll();
        await loadWidgetLibrary({ reset: true, silent: options.silent !== false });
        if (state.widgetLibraryTab !== 'applications') {
            refreshDefaultInjectionApplications({ render: false, silent: true });
        }
    }

    /**
     * 绑定部件库无限滚动（仅绑定一次）
     */
    function initWidgetInfiniteScroll() {
        const scroller = (elements.widgetPanel && elements.widgetPanel.querySelector('.panel-content'))
            || elements.widgetList;
        if (!scroller || scroller.dataset.infiniteScrollBound === '1') {
            return;
        }
        scroller.dataset.infiniteScrollBound = '1';
        scroller.addEventListener('scroll', function () {
            const lib = getWidgetLibState();
            if (state.widgetLibraryTab === 'applications') return;
            if (lib.loading || !lib.hasMore) return;
            if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 200) {
                loadWidgetLibrary({ silent: true });
            }
        });
    }

    /**
     * 在列表底部显示"加载更多/已全部加载"提示
     */
    function renderWidgetLoadMoreHint() {
        const listEl = elements.widgetList;
        if (!listEl) return;
        if (state.widgetLibraryRenderMode === 'applications') {
            listEl.querySelector('.widget-load-more-hint')?.remove();
            return;
        }
        let hint = listEl.querySelector('.widget-load-more-hint');
        const lib = getWidgetLibState();
        if (lib.total <= 0 || (lib.offset === 0)) {
            if (hint) hint.remove();
            return;
        }
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'widget-load-more-hint';
            listEl.appendChild(hint);
        } else {
            listEl.appendChild(hint);
        }
        if (lib.hasMore) {
            hint.textContent = translateUiText('下滑加载更多...') + ' (' + lib.offset + '/' + lib.total + ')';
        } else {
            hint.textContent = translateUiText('已全部加载') + ' (' + lib.total + ')';
        }
    }

    /**
     * 确保指定分组容器存在，返回其 .widget-group-content 元素
     */
    function ensureWidgetGroup(listEl, type, label, libraryTab = 'general') {
        let group = listEl.querySelector('.widget-group[data-type="' + cssEscape(type) + '"]');
        if (group) {
            group.dataset.widgetLibraryTab = libraryTab || group.dataset.widgetLibraryTab || 'general';
            return group.querySelector('.widget-group-content');
        }
        group = document.createElement('div');
        group.className = 'widget-group';
        group.setAttribute('data-type', type);
        group.dataset.state = 'open';
        group.dataset.widgetLibraryTab = libraryTab || 'general';
        group.innerHTML = '<button type="button" class="widget-group-header" aria-expanded="true">'
            + iconSvg('chevron-down').replace('w-theme-editor-icon', 'w-theme-editor-icon w-theme-editor-toggle-icon') + '<span>' + escapeHtml(label) + '</span>'
            + '<span class="widget-count">0</span></button>'
            + '<div class="widget-group-content"></div>';
        group.querySelector('.widget-group-header').addEventListener('click', function (e) {
            if (e.target.closest('.widget-item')) return;
            toggleWidgetGroup(group);
        });
        listEl.appendChild(group);
        return group.querySelector('.widget-group-content');
    }

    function toggleWidgetGroup(group) {
        if (!(group instanceof HTMLElement)) return;
        const opening = group.dataset.state === 'closed';
        group.dataset.state = opening ? 'open' : 'closed';
        const header = group.querySelector(':scope > .widget-group-header');
        const content = group.querySelector(':scope > .widget-group-content');
        header?.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (content) content.hidden = !opening;
    }

    /**
     * 简单 CSS 选择器属性值转义
     */
    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    /**
     * 追加一批部件项到列表（按 group_type 归组）
     */
    function appendWidgetItems(items) {
        const listEl = elements.widgetList;
        if (!listEl || !Array.isArray(items) || items.length === 0) return;
        items.forEach(function (w) {
            if (!w || typeof w !== 'object') return;
            const libraryTab = getDashboardWidgetLibraryTab(w);
            w.widget_library_tab = libraryTab;
            const originalType = (w.group_type ?? w.type ?? 'other').toString();
            const type = libraryTab === 'basic' ? 'builder-components' : originalType;
            const label = libraryTab === 'basic' ? translateUiText('基础组件 / HTML 搭建') : (w.group_label ?? type).toString();
            const contentEl = ensureWidgetGroup(listEl, type, label, libraryTab);
            if (!contentEl) return;
            const itemEl = createWidgetLibraryItem(w);
            if (!itemEl) return;
            itemEl.addEventListener('dragstart', handleDragStart);
            itemEl.addEventListener('dragend', handleDragEnd);
            contentEl.appendChild(itemEl);
            const group = contentEl.closest('.widget-group');
            const countEl = group && group.querySelector('.widget-count');
            if (countEl) {
                countEl.textContent = String(contentEl.querySelectorAll('.widget-item').length);
            }
        });
        hydrateWidgetLibraryPreviews(listEl);
        scheduleFitWidgetPreviews();
        applyWidgetLibraryTabVisibility();
    }

    /**
     * 生成部件标题兜底，避免后端元数据为空时卡片信息区变成空白。
     */
    function humanizeWidgetCode(code) {
        const tail = String(code || '').replace(/\\/g, '/').split('/').filter(Boolean).pop() || '';
        return tail
            .split(/[-_]+/)
            .filter(Boolean)
            .map(part => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ');
    }

    function resolveWidgetLibraryName(name, code) {
        const text = String(name ?? '').trim();
        if (text !== '') {
            return text;
        }

        return humanizeWidgetCode(code) || String(code || '').trim() || translateUiText('未命名部件');
    }

    function resolveWidgetLibraryDescription(description, code) {
        const text = String(description ?? '').trim();
        if (text !== '') {
            return text;
        }

        return String(code || '').trim() !== ''
            ? translateUiText('可拖拽到页面插槽的基础组件')
            : '';
    }

    /**
     * 创建部件库列表项（预览 HTML 单独注入 canvas，避免字符串拼接破坏 DOM）
     */
    function createWidgetLibraryItem(w) {
        const exclusiveSlots = ['logo', 'search', 'main-nav', 'header-container', 'footer-container', 'content-container'];
        const wCode = (w.code ?? '').toString();
        const wModule = (w.module ?? '').toString();
        const wType = (w.type ?? '').toString();
        const wName = resolveWidgetLibraryName(w.name, wCode);
        const wDesc = resolveWidgetLibraryDescription(w.description, wCode);
        const wSlot = (w.slot ?? '').toString();
        const wSupports = normalizeCodeList(w.supports ?? []);
        const wSlots = normalizeCodeList(w.slots ?? []);
        const wSupportCodes = [...new Set([...wSupports, ...wSlots])].join(',');
        const wPageLayouts = w.page_layouts ?? ['*'];
        const wIsContainer = !!(w.is_container ?? false);
        const wExclusive = !!(w.exclusive ?? false) || (wSlot && exclusiveSlots.indexOf(wSlot) !== -1);
        const wCompatible = !!(w.compatible ?? false);
        const wPosition = w.position ?? [];
        const posJson = typeof wPosition === 'string' ? wPosition : JSON.stringify(wPosition);
        const layoutJson = typeof wPageLayouts === 'string' ? wPageLayouts : JSON.stringify(wPageLayouts);
        const previewHtml = (w.preview_html ?? '').toString();
        const libraryTab = (w.widget_library_tab ?? getDashboardWidgetLibraryTab(w)).toString() === 'basic' ? 'basic' : 'general';

        const itemEl = document.createElement('div');
        itemEl.className = 'widget-item draggable'
            + (wIsContainer ? ' widget-container' : '')
            + (wExclusive ? ' widget-exclusive' : '');
        itemEl.draggable = libraryTab === state.widgetLibraryTab || !isDashboardWidgetLibraryMode();
        itemEl.dataset.widgetCode = wCode;
        itemEl.dataset.widgetModule = wModule;
        itemEl.dataset.widgetType = wType;
        itemEl.dataset.widgetName = wName;
        itemEl.dataset.widgetPosition = posJson;
        itemEl.dataset.widgetCompatible = wCompatible ? '1' : '0';
        itemEl.dataset.widgetSlot = wSlot;
        itemEl.dataset.widgetExclusive = wExclusive ? '1' : '0';
        itemEl.dataset.widgetSupports = wSupportCodes;
        itemEl.dataset.widgetSlots = wSlots.join(',');
        itemEl.dataset.widgetPageLayouts = layoutJson;
        itemEl.dataset.widgetIsContainer = wIsContainer ? '1' : '0';
        itemEl.dataset.widgetLibraryTab = libraryTab;

        const previewWrap = document.createElement('div');
        previewWrap.className = 'widget-preview';

        const canvas = document.createElement('div');
        canvas.className = 'widget-preview-canvas';
        canvas.dataset.widgetModule = wModule;
        canvas.dataset.widgetCode = wCode;
        canvas.dataset.widgetArea = state.editorArea || 'frontend';
        mountWidgetPreviewHtml(canvas, previewHtml);

        const overlay = document.createElement('div');
        overlay.className = 'widget-preview-overlay';

        const titleRow = document.createElement('div');
        titleRow.className = 'widget-preview-title-row';

        const titleEl = document.createElement('div');
        titleEl.className = 'widget-preview-title';
        titleEl.title = wName;
        titleEl.textContent = wName;
        if (wIsContainer) {
            titleEl.insertAdjacentHTML('beforeend', ' <span class="w-badge" data-tone="primary" title="容器部件">' + iconSvg('grid') + '</span>');
        }
        if (wExclusive) {
            titleEl.insertAdjacentHTML('beforeend', ' <span class="w-badge" data-tone="warning" title="独占部件">' + iconSvg('eye') + '</span>');
        }

	        const previewBtn = document.createElement('button');
	        previewBtn.type = 'button';
	        previewBtn.className = 'w-button w-theme-editor-preview-component';
	        previewBtn.dataset.tone = 'neutral';
	        previewBtn.dataset.variant = 'outline';
	        previewBtn.dataset.size = 'sm';
	        previewBtn.dataset.iconOnly = 'true';
	        previewBtn.title = translateUiText('预览');
        previewBtn.dataset.widgetModule = wModule;
        previewBtn.dataset.widgetCode = wCode;
	        previewBtn.dataset.widgetName = wName;
	        previewBtn.setAttribute('aria-label', translateUiText('预览'));
	        previewBtn.innerHTML = iconSvg('eye');

	        const addBtn = document.createElement('button');
	        addBtn.type = 'button';
	        addBtn.className = 'w-button w-theme-editor-add-component';
	        addBtn.dataset.tone = 'primary';
	        addBtn.dataset.size = 'sm';
	        addBtn.dataset.iconOnly = 'true';
	        addBtn.title = translateUiText('添加到当前插槽');
	        addBtn.setAttribute('aria-label', translateUiText('添加到当前插槽'));
	        addBtn.innerHTML = iconSvg('plus');

	        const actionGroup = document.createElement('div');
	        actionGroup.className = 'w-theme-editor-widget-actions';
	        actionGroup.appendChild(addBtn);
	        actionGroup.appendChild(previewBtn);

	        titleRow.appendChild(titleEl);
	        titleRow.appendChild(actionGroup);

        const descEl = document.createElement('div');
        descEl.className = 'widget-preview-desc';
        descEl.title = wDesc;
        descEl.textContent = wDesc;

        overlay.appendChild(titleRow);
        overlay.appendChild(descEl);
        previewWrap.appendChild(overlay);
        previewWrap.appendChild(canvas);
        itemEl.appendChild(previewWrap);

        if (isWidgetPreviewFallbackHtml(previewHtml)) {
            // The library renders a local fallback during hydration.  Network
            // rendering is reserved for the explicit preview dialog action.
            canvas.dataset.previewPending = '1';
        }

        return itemEl;
    }

    /**
     * 构造单个部件项 HTML（与服务端模板结构保持一致）
     */
    function getWidgetItemHtml(w) {
        const exclusiveSlots = ['logo', 'search', 'main-nav', 'header-container', 'footer-container', 'content-container'];
        const wCode = (w.code ?? '').toString();
        const wModule = (w.module ?? '').toString();
        const wType = (w.type ?? '').toString();
        const wName = resolveWidgetLibraryName(w.name, wCode);
        const wDesc = resolveWidgetLibraryDescription(w.description, wCode);
        const wSlot = (w.slot ?? '').toString();
        const wSupports = normalizeCodeList(w.supports ?? []);
        const wSlots = normalizeCodeList(w.slots ?? []);
        const wSupportCodes = [...new Set([...wSupports, ...wSlots])].join(',');
        const wPageLayouts = w.page_layouts ?? ['*'];
        const wIsContainer = !!(w.is_container ?? false);
        const wExclusive = !!(w.exclusive ?? false) || (wSlot && exclusiveSlots.indexOf(wSlot) !== -1);
        const wCompatible = !!(w.compatible ?? false);
        const wPosition = w.position ?? [];
        const posJson = typeof wPosition === 'string' ? wPosition : JSON.stringify(wPosition);
        const layoutJson = typeof wPageLayouts === 'string' ? wPageLayouts : JSON.stringify(wPageLayouts);
        const previewHtml = (w.preview_html ?? '').toString();
        const libraryTab = (w.widget_library_tab ?? getDashboardWidgetLibraryTab(w)).toString() === 'basic' ? 'basic' : 'general';
        const itemClass = 'widget-item draggable' + (wIsContainer ? ' widget-container' : '') + (wExclusive ? ' widget-exclusive' : '');
        let html = '<div class="' + itemClass + '" draggable="' + ((!isDashboardWidgetLibraryMode() || libraryTab === state.widgetLibraryTab) ? 'true' : 'false') + '"';
        html += ' data-widget-code="' + escapeHtml(wCode) + '" data-widget-module="' + escapeHtml(wModule) + '"';
        html += ' data-widget-type="' + escapeHtml(wType) + '" data-widget-name="' + escapeHtml(wName) + '"';
        html += ' data-widget-position="' + escapeHtml(posJson) + '" data-widget-compatible="' + (wCompatible ? '1' : '0') + '"';
        html += ' data-widget-slot="' + escapeHtml(wSlot) + '" data-widget-exclusive="' + (wExclusive ? '1' : '0') + '"';
        html += ' data-widget-supports="' + escapeHtml(wSupportCodes) + '" data-widget-slots="' + escapeHtml(wSlots.join(',')) + '"';
        html += ' data-widget-page-layouts="' + escapeHtml(layoutJson) + '" data-widget-is-container="' + (wIsContainer ? '1' : '0') + '"';
        html += ' data-widget-library-tab="' + escapeHtml(libraryTab) + '">';
        html += '<div class="widget-preview">';
        html += '<div class="widget-preview-overlay"><div class="widget-preview-title-row">';
        html += '<div class="widget-preview-title" title="' + escapeHtml(wName) + '">' + escapeHtml(wName);
        if (wIsContainer) html += ' <span class="w-badge" data-tone="primary" title="容器部件">' + iconSvg('grid') + '</span>';
        if (wExclusive) html += ' <span class="w-badge" data-tone="warning" title="独占部件">' + iconSvg('eye') + '</span>';
        html += '</div>';
	        html += '<div class="w-theme-editor-widget-actions">';
	        html += '<button type="button" class="w-button w-theme-editor-add-component" data-tone="primary" data-size="sm" data-icon-only="true" title="添加到当前插槽" aria-label="添加到当前插槽">' + iconSvg('plus') + '</button>';
	        html += '<button type="button" class="w-button w-theme-editor-preview-component" data-tone="neutral" data-variant="outline" data-size="sm" data-icon-only="true" title="预览" data-widget-module="' + escapeHtml(wModule) + '" data-widget-code="' + escapeHtml(wCode) + '" data-widget-name="' + escapeHtml(wName) + '">' + iconSvg('eye') + '</button>';
	        html += '</div>';
        html += '</div><div class="widget-preview-desc" title="' + escapeHtml(wDesc) + '">' + escapeHtml(wDesc) + '</div></div>';
        html += '<div class="widget-preview-canvas" data-widget-module="' + escapeHtml(wModule)
            + '" data-widget-code="' + escapeHtml(wCode)
            + '" data-widget-area="' + escapeHtml(state.editorArea || 'frontend') + '">' + previewHtml + '</div>';
        html += '</div></div>';
        return html;
    }

    /**
     * 设置/清除部件库的插槽过滤条件，并刷新搜索框中的插槽标签（chip）
     * @param {string|null} slotId  插槽/区域代码，null 表示清除
     * @param {string} slotLabel    展示名
     * @param {string|null} slotArea 区域代码（可选，传给后端 getWidgetsForSlot）
     */
    function setWidgetSlotFilter(slotId, slotLabel, slotArea) {
        const lib = getWidgetLibState();
        lib.slot = slotId || null;
        lib.slotArea = slotArea || null;
        lib.slotLabel = slotLabel || '';
        renderWidgetSlotChip();
        loadWidgetLibrary({ reset: true, silent: true });
    }

    /**
     * 渲染搜索框上方的"当前插槽"标签（可点击移除）
     */
    function renderWidgetSlotChip() {
        const search = elements.widgetPanel && elements.widgetPanel.querySelector('.panel-search');
        if (!search) return;
        let chip = search.querySelector('#widgetSlotChip');
        const lib = getWidgetLibState();
        if (!lib.slot) {
            if (chip) chip.remove();
            if (elements.widgetSearch) {
                elements.widgetSearch.placeholder = translateUiText('搜索部件...');
            }
            return;
        }
        if (!chip) {
            chip = document.createElement('div');
            chip.id = 'widgetSlotChip';
            chip.className = 'widget-slot-chip';
            search.insertBefore(chip, search.firstChild);
        }
        chip.innerHTML = '<span class="widget-slot-chip-label">' + iconSvg('cursor') + ' '
            + escapeHtml(translateUiText('插槽')) + '：' + escapeHtml(lib.slotLabel || lib.slot) + '</span>'
            + '<button type="button" class="widget-slot-chip-clear" title="' + escapeHtml(translateUiText('移除插槽过滤')) + '">'
            + iconSvg('close') + '</button>';
        const clearBtn = chip.querySelector('.widget-slot-chip-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                setWidgetSlotFilter(null, '', null);
            });
        }
        if (elements.widgetSearch) {
            elements.widgetSearch.placeholder = translateUiText('在该插槽内搜索...');
        }
    }

    /**
     * 为已保存的布局打开配置模态框
     */
    async function openConfigModalForLayout(layoutId, widgetCode, currentConfig) {
        // 通过 Weline.Api 获取部件参数定义
        try {
            const result = await fetchWidgetsData();

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
        const type = getParamUiType(param);
        const semanticType = param.type || type;
        const label = param.label || key;
        const required = param.required || false;
        const description = param.description || '';
        const placeholder = param.placeholder || '';
        const options = param.options || {};
        const fieldId = `config_${key}`;
        const safeKey = escapeHtml(key);
        const safeFieldId = escapeHtml(fieldId);
        const safeValue = escapeHtml(value);

        let html = `<div class="w-theme-editor-field" data-field-type="${escapeHtml(type)}">`;
        html += `<label for="${safeFieldId}" class="form-label">${escapeHtml(label)}`;
        if (required) html += ' <span class="w-text" data-tone="danger">*</span>';
        html += `</label>`;

        if (type === 'string' || type === 'text') {
            html += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}"
                     value="${safeValue}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
        } else if (type === 'number') {
            const min = param.min !== undefined ? `min="${escapeHtml(param.min)}"` : '';
            const max = param.max !== undefined ? `max="${escapeHtml(param.max)}"` : '';
            const step = param.step !== undefined ? `step="${escapeHtml(param.step)}"` : '';
            html += `<input type="number" class="w-input" id="${safeFieldId}" name="${safeKey}"
                     value="${safeValue}" placeholder="${escapeHtml(placeholder)}" ${min} ${max} ${step} ${required ? 'required' : ''}>`;
        } else if (isBooleanParamType(semanticType)) {
            html += renderBooleanSelect(`config_${key}`, key, key, value, required, param);
        } else if (type === 'select') {
            html += `<select class="w-select" id="${safeFieldId}" name="${safeKey}" ${required ? 'required' : ''}>
                <option value="">-- 请选择 --</option>`;
            for (const optVal in options) {
                html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
            }
            html += `</select>`;
        } else if (type === 'multiselect') {
            const selectedValues = Array.isArray(value) ? value : (value ? String(value).split(',') : []);
            html += `<select class="w-select w-theme-editor-multiselect" id="${safeFieldId}" name="${safeKey}[]" multiple ${required ? 'required' : ''}>`;
            for (const optVal in options) {
                const isSelected = selectedValues.includes(String(optVal));
                html += `<option value="${escapeHtml(optVal)}" ${isSelected ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
            }
            html += `</select>`;
            html += `<small class="w-field__hint">按住 Ctrl/Cmd 可多选</small>`;
        } else if (type === 'url') {
            html += `<input type="url" class="w-input" id="${safeFieldId}" name="${safeKey}"
                     value="${safeValue}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>`;
        } else if (['image', 'image_picker', 'media_image', 'file_image'].includes(type)) {
            html += renderTypedFileImageControl(fieldId, key, value, param);
        } else if (type === 'file') {
            html += `<div class="w-field__group">
                <input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${safeValue}" placeholder="文件路径">
                <button type="button" class="w-button w-theme-editor-select-file" data-tone="neutral" data-variant="outline" data-target="${safeFieldId}" data-accept="${escapeHtml(param.accept || '*')}">
                    ${iconSvg('folder')} 浏览
                </button>
            </div>`;
        } else if (type === 'color') {
            html += `<div class="w-field__group">
                <input type="color" class="w-input w-theme-editor-color-input w-theme-editor-color-swatch" id="${safeFieldId}_picker" value="${escapeHtml(value || '#000000')}">
                <input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}" value="${safeValue}" placeholder="#000000">
            </div>`;
        } else if (type === 'textarea') {
            html += `<textarea class="w-textarea" id="${safeFieldId}" name="${safeKey}" rows="${escapeHtml(param.rows || 4)}"
                     placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${safeValue}</textarea>`;
        } else if (type === 'rich_text') {
            html += `<textarea class="w-textarea rich-text-editor" id="${safeFieldId}" name="${safeKey}" rows="${escapeHtml(param.rows || 6)}"
                     placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${safeValue}</textarea>`;
            html += `<small class="w-field__hint">支持 HTML 格式</small>`;
        } else if (type === 'date') {
            html += `<input type="date" class="w-input" id="${safeFieldId}" name="${safeKey}"
                     value="${safeValue}" ${required ? 'required' : ''}>`;
        } else if (type === 'datetime') {
            html += `<input type="datetime-local" class="w-input" id="${safeFieldId}" name="${safeKey}"
                     value="${safeValue}" ${required ? 'required' : ''}>`;
        } else if (type === 'eav_select') {
            // EAV 属性选择器
            const entityCode = param.entity_code || 'product';
            const safeEntityCode = escapeHtml(entityCode);
            html += `<select class="w-select eav-attribute-select" id="${safeFieldId}" name="${safeKey}"
                     data-entity-code="${safeEntityCode}" data-current-value="${safeValue}" ${required ? 'required' : ''}>
                <option value="">-- 加载中... --</option>
            </select>`;
        } else if (type === 'eav_options') {
            // EAV 属性选项选择器
            const entityCode = param.entity_code || 'product';
            const attributeCode = param.attribute_code || '';
            const multiple = param.multiple || false;
            const safeEntityCode = escapeHtml(entityCode);
            const safeAttributeCode = escapeHtml(attributeCode);
            const currentValues = multiple
                ? (Array.isArray(value) ? value : (value ? String(value).split(',') : []))
                : [value];
            html += `<select class="w-select eav-options-select${multiple ? ' w-theme-editor-multiselect' : ''}" id="${safeFieldId}" name="${safeKey}${multiple ? '[]' : ''}"
                     data-entity-code="${safeEntityCode}" data-attribute-code="${safeAttributeCode}"
                     data-current-values="${escapeHtml(JSON.stringify(currentValues.map(String)))}"
                     ${multiple ? 'multiple' : ''} ${required ? 'required' : ''}>
                <option value="">-- 加载中... --</option>
            </select>`;
        } else if (type === 'checkbox_group') {
            html += `<div class="checkbox-group" id="${safeFieldId}_group">`;
            const selectedValues = Array.isArray(value) ? value : (value ? String(value).split(',') : []);
            for (const optVal in options) {
                const isChecked = selectedValues.includes(String(optVal));
                const safeOptId = `${safeFieldId}_${escapeHtml(optVal)}`;
                html += `<label class="w-check" for="${safeOptId}">
                    <input type="checkbox" id="${safeOptId}"
                           name="${safeKey}[]" value="${escapeHtml(optVal)}" ${isChecked ? 'checked' : ''}>
                    <span>${escapeHtml(options[optVal])}</span>
                </label>`;
            }
            html += `</div>`;
        } else if (type === 'radio') {
            html += `<div class="radio-group" id="${safeFieldId}_group">`;
            for (const optVal in options) {
                const isChecked = String(value) === String(optVal);
                const safeOptId = `${safeFieldId}_${escapeHtml(optVal)}`;
                html += `<label class="w-check" for="${safeOptId}">
                    <input type="radio" id="${safeOptId}"
                           name="${safeKey}" value="${escapeHtml(optVal)}" ${isChecked ? 'checked' : ''}>
                    <span>${escapeHtml(options[optVal])}</span>
                </label>`;
            }
            html += `</div>`;
        } else {
            // 默认为文本输入
            html += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}"
                     value="${safeValue}" ${required ? 'required' : ''}>`;
        }

        if (description) {
            html += `<small class="w-field__hint">${escapeHtml(description)}</small>`;
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

        const modalTitle = modal.querySelector('.w-dialog__title');
        const modalBody = modal.querySelector('.w-dialog__body');

        if (modalTitle) {
            modalTitle.textContent = widgetMeta.name || widgetMeta.code;
        }

        if (modalBody) {
            const params = widgetMeta.params || {};
            let formHtml = `<form id="modalConfigForm" data-layout-id="${escapeHtml(layoutId)}" data-widget-code="${escapeHtml(widgetMeta.code || '')}" data-widget-module="${escapeHtml(widgetMeta.module || '')}">`;

            // 添加预览区域
            formHtml += `
                <div class="config-preview-area">
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
                <div class="w-theme-editor-form-actions">
                    <button type="submit" class="w-button w-theme-editor-flex-fill" data-tone="primary">
                        ${iconSvg('save')} 保存配置
                    </button>
                    <button type="button" class="w-button" data-w-action="dialog.close">
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
                form.querySelectorAll('.w-theme-editor-color-input').forEach(colorPicker => {
                    colorPicker.addEventListener('input', function() {
                        const textInput = this.parentElement.querySelector('input[type="text"]');
                        if (textInput) {
                            textInput.value = this.value;
                        }
                    });
                });
            }

            // 显示模态框
            showEditorModal(modal);

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

        const configData = collectWidgetConfigData(form);

        try {
            const result = await apiJson(getWidgetConfigSaveUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: configData,
                    locale: getActiveConfigLocale() || null,
                }),
            });

            if (result.success) {
                const normalizedConfig = (result && result.config && typeof result.config === 'object') ? result.config : configData;
                await queueWidgetConfigOwnership(result.node_uid, normalizedConfig, getActiveConfigLocale());
                showToast('配置已保存', 'success');

                // T012: 使用返回的 preview_html 更新预览，不再触发 layout-preview 请求
                if (result.preview_html) {
                    const previewBox = document.getElementById('modalWidgetPreview');
                    if (previewBox) {
                        previewBox.innerHTML = sanitizeHtmlForEditorPreview(result.preview_html);
                    }

                    // 更新 iframe 中对应部件的预览（如果存在）
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                }

                // 关闭模态框
                hideEditorModal(document.getElementById('widgetConfigModal'));

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
     * @param {number|null} sortOrder 插入序号（与持久化 sort_order 对齐）
     */
    function getIframeSlotWidgetRoots(container) {
        if (!container) return [];
        const candidates = Array.from(container.querySelectorAll(
            ':scope > .widget-wrapper[data-layout-id], :scope > [data-layout-id].widget-wrapper, :scope > .widget-wrapper[data-widget-code]'
        ));
        return candidates.filter((el, index) => !candidates.slice(0, index).some((parent) => parent.contains(el)));
    }

    function insertWidgetWrapperAtSortOrder(container, wrapper, sortOrder) {
        if (!container || !wrapper) return;
        container.querySelectorAll(':scope > .slot-placeholder').forEach((placeholder) => placeholder.remove());
        const existing = getIframeSlotWidgetRoots(container);
        const index = Number.isFinite(Number(sortOrder))
            ? Math.max(0, Math.min(Number(sortOrder), existing.length))
            : existing.length;
        if (index >= existing.length) {
            container.appendChild(wrapper);
            return;
        }
        existing[index].before(wrapper);
    }

    function updateWidgetPreviewInIframe(layoutId, previewHtml, isNewWidget = false, targetSlotId = null, sortOrder = null) {
        const iframe = elements.previewFrame;
        const safePreviewHtml = sanitizeHtmlForEditorPreview(previewHtml);
        if (!iframe) {
            console.warn('[ThemeEditor] iframe not found; skip local preview update');
            if (isNewWidget) {
                loadLayoutPreview();
            }
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
                    console.warn('[ThemeEditor] iframe not accessible after retries; skip full preview reload');
                    if (isNewWidget) {
                        loadLayoutPreview();
                    }
                    return;
                }

                const widgetEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));

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
                    contentEl.innerHTML = safePreviewHtml;
                    activateWidgetPreviewArrayItem(layoutId, state.previewArrayItemIndexByLayout[String(layoutId)]);
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
                        const safeSlotId = cssIdentifier(slotId);
                        slotEl = iframe.contentDocument.querySelector(dataAttributeSelector('data-wslot', slotId)) ||
                                 iframe.contentDocument.querySelector(dataAttributeSelector('data-slot', slotId)) ||
                                 iframe.contentDocument.querySelector(`.slot-${safeSlotId}`) ||
                                 iframe.contentDocument.querySelector(`#slot-${safeSlotId}`);
                    }

                    if (slotEl) {
                        // 确保 iframe 中有样式
                        injectStylesIntoIframe();

                        // 找到插槽，插入新部件
                        const wrapper = iframe.contentDocument.createElement('div');
                        wrapper.setAttribute('data-layout-id', layoutId);
                        wrapper.setAttribute('data-slot-id', slotId);
                        wrapper.classList.add('widget-wrapper', 'widget-new');
                        wrapper.classList.add('w-theme-editor-position-context');

                        // 判断是否独占（从 DOM 属性或 isExclusiveSlot 判断）
                        const isExclusive = slotEl.getAttribute('data-wslot-exclusive') === 'true'
                            || isExclusiveSlot(slotId, state.draggingWidget?.code || '');

                        // 生成操作按钮
                        const actionsHtml = generateWidgetHoverActionsHtml(layoutId, slotId, isExclusive, true, true);

                        // 组装部件内容
                        wrapper.innerHTML = actionsHtml + '<div class="widget-content">' + safePreviewHtml + '</div>';

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
                            const insertOrder = sortOrder != null
                                ? sortOrder
                                : state.lastPreviewInsertSortOrder;
                            insertWidgetWrapperAtSortOrder(widgetContainer, wrapper, insertOrder);
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
                    // Existing widget config save must stay local; full iframe reload flashes the whole page.
                    console.warn(`[ThemeEditor] Existing widget ${layoutId} not found; skip full preview reload`);
                }
            } catch (err) {
                // iframe 跨域或其他错误
                console.warn('[ThemeEditor] Error updating iframe:', err.message);

                retryCount++;
                if (retryCount < maxRetries) {
                    setTimeout(tryUpdate, retryDelay);
                    return;
                }

                console.warn('[ThemeEditor] iframe update failed after retries; skip full preview reload');
                if (isNewWidget) {
                    loadLayoutPreview();
                }
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
        cancelPreviewDragSession();
        if (viewType === 'structure' && isPreviewInteractionMode()) {
            setInteractionMode('edit', { skipViewSwitch: true });
        }
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
	                const json = e.dataTransfer.getData('application/json') || e.dataTransfer.getData('text/plain');
	                if (json) data = JSON.parse(json);
	            } catch (err) { /* ignore */ }
	        }
	        return data || null;
	    }

	    function parseWidgetListAttribute(value, fallback = []) {
	        if (value == null || value === '') {
	            return fallback;
	        }
	        try {
	            const parsed = JSON.parse(value);
	            return Array.isArray(parsed) ? parsed : normalizeCodeList(parsed);
	        } catch (error) {
	            const normalized = normalizeCodeList(value);
	            return normalized.length ? normalized : fallback;
	        }
	    }

	    function readWidgetDataFromElement(widgetEl) {
	        if (!widgetEl) {
	            return null;
	        }
	        const code = widgetEl.dataset.widgetCode || widgetEl.getAttribute('data-widget-code') || '';
	        if (!code) {
	            return null;
	        }
	        return {
	            code,
	            module: widgetEl.dataset.widgetModule || widgetEl.getAttribute('data-widget-module') || 'Weline_Widget',
	            type: widgetEl.dataset.widgetType || widgetEl.getAttribute('data-widget-type') || 'content',
	            name: widgetEl.dataset.widgetName || widgetEl.getAttribute('data-widget-name') || code,
	            position: parseWidgetListAttribute(widgetEl.dataset.widgetPosition || widgetEl.getAttribute('data-widget-position'), []),
	            compatible: (widgetEl.dataset.widgetCompatible || widgetEl.getAttribute('data-widget-compatible')) !== '0',
	            slot: widgetEl.dataset.widgetSlot || widgetEl.getAttribute('data-widget-slot') || null,
	            supports: normalizeCodeList(widgetEl.dataset.widgetSupports || widgetEl.getAttribute('data-widget-supports') || ''),
	            slots: normalizeCodeList(widgetEl.dataset.widgetSlots || widgetEl.getAttribute('data-widget-slots') || ''),
	            exclusive: ['1', 'true'].includes(String(widgetEl.dataset.widgetExclusive || widgetEl.getAttribute('data-widget-exclusive') || '').toLowerCase()),
	            pageLayouts: parseWidgetListAttribute(widgetEl.dataset.widgetPageLayouts || widgetEl.getAttribute('data-widget-page-layouts'), ['*']),
	            isContainer: (widgetEl.dataset.widgetIsContainer || widgetEl.getAttribute('data-widget-is-container')) === '1',
	        };
	    }

	    function resolveWidgetLibraryAddSlot(widgetData) {
	        const selectedSlot = state.selectedSlot ? normalizePlacementSlotInfo(state.selectedSlot) : null;
	        if (selectedSlot) {
	            return isSlotDataAccepted(selectedSlot, widgetData) ? selectedSlot : null;
	        }

	        const selectedWidgetInnerSlot = resolveSelectedWidgetInnerSlot(widgetData);
	        if (selectedWidgetInnerSlot) {
	            return selectedWidgetInnerSlot;
	        }

	        const selectedWidgetParentSlot = resolveSelectedWidgetParentSlot(widgetData);
	        if (selectedWidgetParentSlot) {
	            return selectedWidgetParentSlot;
	        }

	        const slots = collectWidgetPlacementSlots();
	        if (!slots.length) {
	            return null;
	        }
	        const requestedSlot = widgetData.slot
	            ? slots.find(slot => slot.id === widgetData.slot && isSlotDataAccepted(slot, widgetData))
	            : null;
	        if (requestedSlot) {
	            return requestedSlot;
	        }

	        return slots.find(slot => slot.area === 'content' && isSlotDataAccepted(slot, widgetData))
	            || slots.find(slot => isSlotDataAccepted(slot, widgetData))
	            || slots.find(slot => slot.area === 'content')
	            || slots[0]
	            || null;
	    }

	    function resolveSelectedWidgetInnerSlot(widgetData) {
	        const selectedWidget = state.selectedWidget;
	        const layoutId = selectedWidget?.dataset?.layoutId || selectedWidget?.getAttribute?.('data-layout-id') || '';
	        if (!selectedWidget || !layoutId) {
	            return null;
	        }

	        const anchorInfo = resolveAnchorPlacementInfo(layoutId);
	        const parentSlotId = String(anchorInfo?.slotId || '').trim();
	        const candidates = [];
	        if (selectedWidget.matches?.('[data-wslot], [data-slot], [data-slot-id]')) {
	            candidates.push(selectedWidget);
	        }
	        selectedWidget.querySelectorAll?.('[data-wslot], [data-slot], [data-slot-id]').forEach(slotEl => {
	            candidates.push(slotEl);
	        });

	        for (const slotEl of candidates) {
	            const slotId = String(
	                slotEl.getAttribute('data-wslot')
	                || slotEl.getAttribute('data-slot')
	                || slotEl.getAttribute('data-slot-id')
	                || ''
	            ).trim();
	            if (!slotId || slotId === parentSlotId || isSyntheticContainerSlotId(slotId)) {
	                continue;
	            }
	            const slot = normalizePlacementSlotInfo({
	                ...(state.slots?.[slotId] || {}),
	                ...buildSlotInfoFromElement(slotId, slotEl),
	                id: slotId,
	                source: 'selected_widget_inner',
	            });
	            if (slot && isSlotDataAccepted(slot, widgetData)) {
	                return slot;
	            }
	        }

	        return null;
	    }

	    function resolveSelectedWidgetParentSlot(widgetData) {
	        const selectedWidget = state.selectedWidget;
	        const layoutId = selectedWidget?.dataset?.layoutId || selectedWidget?.getAttribute?.('data-layout-id') || '';
	        if (!layoutId) {
	            return null;
	        }

	        const anchorInfo = resolveAnchorPlacementInfo(layoutId);
	        const slotId = String(anchorInfo?.slotId || '').trim();
	        if (!slotId || isSyntheticContainerSlotId(slotId)) {
	            return null;
	        }

	        const catalogSlot = state.slots?.[slotId] && typeof state.slots[slotId] === 'object'
	            ? state.slots[slotId]
	            : {};
	        const slot = normalizePlacementSlotInfo({
	            ...catalogSlot,
	            ...(anchorInfo?.slotEl ? buildSlotInfoFromElement(slotId, anchorInfo.slotEl) : {}),
	            id: slotId,
	            area: anchorInfo?.area || catalogSlot.area || inferAreaFromSlotId(slotId),
	            source: 'selected_widget_parent',
	        });

	        return slot && isSlotDataAccepted(slot, widgetData) ? slot : null;
	    }

	    async function addWidgetFromLibraryItem(widgetEl) {
	        const widgetData = readWidgetDataFromElement(widgetEl);
	        if (!widgetData) {
	            showToast('无法读取部件数据', 'error');
	            return null;
	        }
	        if (!isWidgetAllowedForLayout(widgetData.pageLayouts, state.layoutType, widgetData)) {
	            showToast(`部件 "${widgetData.name}" 不支持当前布局 "${state.layoutType}"`, 'warning');
	            return null;
	        }

	        const slot = resolveWidgetLibraryAddSlot(widgetData);
	        if (!slot) {
	            const selectedName = state.selectedSlot?.name || state.selectedSlot?.id || '';
	            showToast(selectedName ? `部件 "${widgetData.name}" 不能放入插槽 "${selectedName}"` : '请先选择要放置的 slot', 'warning');
	            return null;
	        }

	        const sortOrder = getNextSlotSortOrder(slot.id);
	        return handleWidgetDropped(widgetData, slot, sortOrder);
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
        const acceptCodes = normalizeCodeList(acceptAttr);
        const rejectAttr = slot.dataset.wslotReject || '';
        const rejectCodes = normalizeCodeList(rejectAttr);
        const slotId = slot.dataset.wslot || slot.dataset.slot;

        return slotAcceptsWidgetCodes(
            acceptCodes,
            rejectCodes,
            slotId,
            collectWidgetSupportCodes(widgetData)
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
            page_type: getEffectivePageType(state.pageType || 'homepage'),
            layout_type: getEffectiveLayoutType(state.layoutType || state.pageType || 'homepage'),
            layout_option: getEffectiveLayoutOption(state.layoutOption || 'default'),
            editor_area: getEffectiveEditorArea(state.editorArea || 'frontend'),
            editor_context: buildTypedEditorContext('layout'),
            scope: { identity: state.scopeIdentity },
            ...getLayoutLockVirtualPayload(),
            area: area,
            slot_id: slotId || null,
            widget_code: widgetData.code,
            widget_module: widgetData.module,
            widget_type: widgetData.type || '',
            config: widgetData.config || {},
            sort_order: sortOrder,
            // 模板内嵌 CoW：带 template_ref 的物化不得独占清空同槽其它实例
            exclusive: (widgetData.config && widgetData.config.template_ref) ? false : exclusive,
        };

        try {
            const result = await apiJson(config.apiSaveWidget, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });


            if (result.success) {
                await queueAddedLayoutNode(payload, result.data || {});
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
                notifyDashboardLayoutMutated('widget-added', {
                    layoutId: layoutId || null,
                    slotId: slotId || null,
                    area: area || null,
                });

                if (switchToPreview) {
                    switchPreviewView('preview');
                }

                if (result.preview_html && layoutId) {
                    // 结构视图拖入时 slotId 可能为 null，用 area 推导插槽 ID，避免找不到插槽而整页刷新导致 footer 变白
                    const targetSlotId = slotId ?? (
                        area === 'footer' ? 'footer'
                            : area === 'header' ? 'header'
                                : area === 'content' ? 'content'
                                    : null
                    );
                    updateWidgetPreviewInIframe(layoutId, result.preview_html, true, targetSlotId, sortOrder);
                } else {
                    loadLayoutPreview();
                }

                state.lastPreviewInsertSortOrder = null;
                return result;
            } else {
                showToast(result.message || '添加失败', 'error');
                return null;
            }
        } catch (err) {
            console.error('保存部件失败:', err);
            const message = err && err.message ? err.message : '保存部件失败';
            showToast(message || '保存部件失败', 'error');
            return null;
        } finally {
            state.saveInProgress = false;
        }
    }

    /**
     * 拖拽开始
     */
    function notifyPreviewDragState(phase, widgetData = null, sessionId = state.previewDragSessionId) {
        const previewWindow = elements.previewFrame?.contentWindow;
        if (!previewWindow || !sessionId) return;

        previewWindow.postMessage({
            source: 'weline-theme-editor',
            type: 'drag-state',
            phase,
            session_id: sessionId,
            widget: widgetData
        }, window.location.origin);
    }

    function handleDragStart(e) {
        if (isPreviewInteractionMode()) {
            e.preventDefault();
            showToast(translateUiText('当前为预览模式，请先切换到编辑'), 'info');
            return;
        }
        if (!isWidgetLibraryItemActive(this)) {
            showToast(translateUiText('请先切换到对应部件分类'), 'warning');
            e.preventDefault();
            return;
        }

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
            supports: normalizeCodeList(this.dataset.widgetSupports || ''),
            slots: normalizeCodeList(this.dataset.widgetSlots || ''),
            exclusive: this.dataset.widgetExclusive === '1' || this.dataset.widgetExclusive === 'true',
            // 布局和容器属性
            pageLayouts: pageLayouts,
            isContainer: this.dataset.widgetIsContainer === '1',
        };

        // 检查部件是否支持当前布局
        if (!isWidgetAllowedForLayout(pageLayouts, state.layoutType, widgetData)) {
            showToast(`部件 "${widgetData.name}" 不支持当前布局 "${state.layoutType}"`, 'warning');
            e.preventDefault();
            return;
        }

        if (state.previewDropFallbackTimer) {
            clearTimeout(state.previewDropFallbackTimer);
            state.previewDropFallbackTimer = null;
        }
        if (state.previewDragSessionId) {
            notifyPreviewDragState('end', null, state.previewDragSessionId);
        }

        state.isDragging = true;
        state.previewDragSessionId = createPreviewDragSessionId();
        state.previewDropCandidate = null;
        state.previewDropCommittedSessionId = '';
        state.previewDragCancelled = false;
        this.classList.add('dragging');

        // 存储到 state 中，以便在 dragover 和 drop 时使用
        state.draggingWidget = widgetData;

        console.log('Drag start - widget:', widgetData.name, 'position:', widgetData.position, 'slot:', widgetData.slot, 'exclusive:', widgetData.exclusive, 'pageLayouts:', widgetData.pageLayouts);

        const dragPayload = JSON.stringify(widgetData);
        e.dataTransfer.setData('application/json', dragPayload);
        // Chromium can drop custom MIME payloads when dragging into an iframe; text/plain survives that boundary.
        e.dataTransfer.setData('text/plain', dragPayload);
        e.dataTransfer.effectAllowed = 'copy';
        notifyPreviewDragState('start', widgetData);

        // 高亮可放置区域
        highlightAllowedAreas(widgetData.position);

        // 手机/平板从部件 drawer 开始拖拽后立即露出完整画布；拖拽数据和 drag image 已建立。
        if (isCompactEditorViewport()) {
            const dragSessionId = state.previewDragSessionId;
            requestAnimationFrame(() => {
                if (state.isDragging && state.previewDragSessionId === dragSessionId) {
                    setSidePanelOpen('widget', false, false);
                }
            });
        }
    }

    /**
     * 检查部件是否支持指定的布局
     */
    function isWidgetAllowedForLayout(widgetPageLayouts, currentLayout, widgetData = null) {
        const layouts = normalizeCodeList(widgetPageLayouts);
        const layout = normalizeCode(currentLayout);

        if (layouts.includes('*')) {
            return true;
        }
        if (layout && layouts.includes(layout)) {
            return true;
        }
        if (layouts.includes('default')) {
            return true;
        }

        const layoutOption = normalizeCode(state.layoutOption || 'default');
        const widgetCodes = collectWidgetSupportCodes(widgetData);
        if (layout && widgetCodes.includes(`layout-${layout}`)) {
            return true;
        }
        const supportPrefix = layout ? `layout-${layout}-` : '';
        if (supportPrefix && widgetCodes.some(code => code === `layout-${layout}` || code.startsWith(supportPrefix))) {
            return true;
        }
        if (layout && layoutOption && layoutOption !== 'default') {
            const optionPrefix = `layout-${layout}-${layoutOption}-`;
            if (widgetCodes.some(code => code.startsWith(optionPrefix) || code === `layout-${layout}-${layoutOption}`)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 拖拽结束
     */
    function handleDragEnd(e) {
        const sessionId = state.previewDragSessionId;
        const releasePoint = {
            clientX: e.clientX,
            clientY: e.clientY,
        };

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

        // 给 iframe 最后的 candidate/drop 消息一个事件循环窗口。若 Chromium 丢失 drop，
        // 使用同一候选兜底；session 去重保证正常 drop 与 fallback 不会保存两次。
        state.previewDropFallbackTimer = setTimeout(() => {
            state.previewDropFallbackTimer = null;
            if (shouldCommitPreviewDropOnDragEnd(releasePoint, sessionId)
                && !state.previewDragCancelled) {
                void commitPreviewDropCandidate(sessionId).catch((error) => {
                    console.error('[ThemeEditor] Preview dragend fallback failed:', error);
                });
            }
            finishPreviewDragSession(sessionId);
        }, 96);
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
        const slotId = area.dataset.wslot || area.dataset.slot || null;

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
        const sortOrder = state.dragInsertIndex != null
            ? state.dragInsertIndex
            : (slotId ? getNextSlotSortOrder(slotId) : getNextSortOrder(areaCode));
        state.dragInsertIndex = null;

        saveWidget({ area: areaCode, slotId, widgetData, sortOrder, exclusive: info.exclusive });
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
    function addWidgetToStructureView(area, slotId, widgetData, layoutId, exclusive = false, templateRef = '') {
        // 部件类型图标映射
        const icon = widgetTypeIconName(widgetData.type);
        const widgetName = widgetData.name || widgetData.code;
        const widgetDomId = `widget_${String(layoutId || templateRef || '').replace(/[^\w:-]/g, '_')}`;
        const safeLayoutId = escapeHtml(layoutId || '');
        const safeTemplateRef = escapeHtml(templateRef || '');
        const safeSlotId = escapeHtml(slotId || '');
        const safeWidgetCode = escapeHtml(widgetData.code || '');
        const safeWidgetModule = escapeHtml(widgetData.module || '');
        const safeWidgetType = escapeHtml(widgetData.type || '');

        // 创建部件项 HTML
        const widgetHtml = `
            <div class="preview-widget-item widget-new${safeTemplateRef ? ' is-template-widget' : ''}"
                 id="${escapeHtml(widgetDomId)}"
                 ${safeLayoutId ? `data-layout-id="${safeLayoutId}"` : ''}
                 ${safeTemplateRef ? `data-template-ref="${safeTemplateRef}"` : ''}
                 data-slot-id="${safeSlotId}"
                 data-widget-code="${safeWidgetCode}"
                 data-widget-module="${safeWidgetModule}"
                 data-widget-type="${safeWidgetType}"
                 data-config='${escapeHtml(JSON.stringify(widgetData.config || {}))}'>
                <div class="widget-header">
                    <span class="widget-name">
                        ${iconSvg(icon)}
                        ${escapeHtml(widgetName)}
                        ${safeTemplateRef ? '<span class="widget-template-badge">模板</span>' : ''}
                    </span>
                    <div class="widget-actions">
                        <button type="button" class="w-button w-theme-editor-edit-widget" data-tone="primary" data-variant="outline" data-size="sm" title="编辑">
                            ${iconSvg('edit')}
                        </button>
                        <button type="button" class="w-button w-theme-editor-delete-widget" data-tone="danger" data-variant="outline" data-size="sm" title="删除">
                            ${iconSvg('delete')}
                        </button>
                    </div>
                </div>
                <div class="widget-preview">
                    <span class="w-text" data-tone="muted">点击配置此部件</span>
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
            targetContainer = document.querySelector(`.area-widgets${dataAttributeSelector('data-area', area)}`);
        }

        // 如果还是没有，查找旧版插槽容器（兼容性）
        if (!targetContainer && slotId) {
            targetContainer = document.querySelector(`.slot-widgets${dataAttributeSelector('data-slot', slotId)}`);
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
        const newWidget = targetContainer.querySelector(dataLayoutIdSelector(layoutId));
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
        const slotWidgets = document.querySelector(`.slot-widgets${dataAttributeSelector('data-slot', slotId)}`);
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
            const widgetCodes = collectWidgetSupportCodes(widgetData);

            // 查找所有插槽（包括新旧两种标记方式）
            document.querySelectorAll('.container-slot, [data-wslot]').forEach(slot => {
                // 获取插槽ID（兼容新旧属性）
                const slotId = slot.dataset.wslot || slot.dataset.slot;
                // 获取接受的部件类型（兼容新旧属性）
                const acceptAttr = slot.dataset.wslotAccept || slot.dataset.accept || '';
                const acceptCodes = normalizeCodeList(acceptAttr);
                const rejectCodes = normalizeCodeList(slot.dataset.wslotReject || '');

                const matches = slotAcceptsWidgetCodes(acceptCodes, rejectCodes, slotId, widgetCodes);

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
        penetrateDown: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 13.172l4.95-4.95 1.414 1.414L12 16l-6.364-6.364L7.05 10.222 12 13.172z"/></svg>`,
        aiEdit: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2zM5 14l.8 2.2L8 17l-2.2.8L5 20l-.8-2.2L2 17l2.2-.8zM19 13l.8 2.2L22 16l-2.2.8L19 19l-.8-2.2L16 16l2.2-.8z"></path></svg>`,
        config: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M15.5 5.5L18.5 8.5L8.75 18.25H5.75V15.25L15.5 5.5ZM17 4L20 7L21.06 5.94C21.65 5.35 21.65 4.4 21.06 3.81L20.19 2.94C19.6 2.35 18.65 2.35 18.06 2.94L17 4ZM3 20H21V22H3V20Z"></path></svg>`,
        aiRebuild: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2L13.09 8.26L19 6L15.74 11.35L21 15L14.73 15.9L15 22L12 16.5L9 22L9.27 15.9L3 15L8.26 11.35L5 6L10.91 8.26L12 2Z"></path></svg>`,
        aiImage: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M5 3H19C20.1 3 21 3.9 21 5V19C21 20.1 20.1 21 19 21H5C3.9 21 3 20.1 3 19V5C3 3.9 3.9 3 5 3ZM5 5V14.5L8.5 11L12.5 15L15 12L19 16.5V5H5ZM7.5 10C8.33 10 9 9.33 9 8.5S8.33 7 7.5 7S6 7.67 6 8.5S6.67 10 7.5 10Z"></path></svg>`
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
    function generateWidgetHoverActionsHtml(identityOrLayoutId, slotId, isExclusive, isFirst = true, isLast = true) {
        let layoutId = '';
        let templateRef = '';
        if (identityOrLayoutId && typeof identityOrLayoutId === 'object') {
            layoutId = String(identityOrLayoutId.layoutId || '').trim();
            templateRef = String(identityOrLayoutId.templateRef || '').trim();
        } else {
            layoutId = String(identityOrLayoutId || '').trim();
        }
        const safeLayoutId = escapeHtml(layoutId || '');
        const safeTemplateRef = escapeHtml(templateRef || '');
        const safeSlotId = escapeHtml(slotId || '');
        let html = `<div class="widget-hover-actions"`
            + ` data-w-component="anchored-float"`
            + ` data-w-float-self="1"`
            + ` data-w-placement="top-end"`
            + ` data-w-portal="0"`
            + (safeLayoutId ? ` data-layout-id="${safeLayoutId}"` : '')
            + (safeTemplateRef ? ` data-template-ref="${safeTemplateRef}"` : '')
            + '>';

        // 嵌套距离：信息、上级、下级（仅这三者按栈下标；替换/删除/移动仍用最外层）
        html += `<button class="w-theme-editor-widget-info" title="信息" data-action="info" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}">
                    ${WIDGET_ACTION_ICONS.info}
                 </button>`;
        html += `<button class="w-theme-editor-penetrate-up" title="上级" data-action="penetrate-up" hidden>
                    ${WIDGET_ACTION_ICONS.penetrateUp}
                 </button>`;
        html += `<button class="w-theme-editor-penetrate-down" title="下穿" data-action="penetrate-down" hidden>
                    ${WIDGET_ACTION_ICONS.penetrateDown}
                 </button>`;
        html += `<button class="w-theme-editor-widget-config" title="配置" data-action="config" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" data-slot-id="${safeSlotId}">
                    ${WIDGET_ACTION_ICONS.config}
                 </button>`;
        html += `<button class="w-theme-editor-widget-ai-edit" title="AI编辑" data-action="ai-edit" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" data-slot-id="${safeSlotId}">
                    ${WIDGET_ACTION_ICONS.aiEdit}
                 </button>`;
        html += `<button class="w-theme-editor-widget-ai-rebuild" title="AI重建" data-action="ai-rebuild" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" data-slot-id="${safeSlotId}">
                    ${WIDGET_ACTION_ICONS.aiRebuild}
                 </button>`;
        html += `<button class="w-theme-editor-widget-ai-image" title="AI图片资源重新生成" data-action="ai-image" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" data-slot-id="${safeSlotId}">
                    ${WIDGET_ACTION_ICONS.aiImage}
                 </button>`;

        // 替换按钮 - 所有部件都有
        html += `<button class="w-theme-editor-widget-replace" title="替换部件" data-action="replace" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" data-slot-id="${safeSlotId}">
                    ${WIDGET_ACTION_ICONS.replace}
                 </button>`;

        // 删除按钮 - 所有部件都有
        html += `<button class="w-theme-editor-widget-delete" title="删除部件" data-action="delete" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" data-slot-id="${safeSlotId}">
                    ${WIDGET_ACTION_ICONS.delete}
                 </button>`;

        // 非独占部件显示上下移动按钮
        if (!isExclusive) {
            html += `<button class="w-theme-editor-widget-move-up" title="上移" data-action="move-up" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" ${isFirst ? 'disabled' : ''}>
                        ${WIDGET_ACTION_ICONS.moveUp}
                     </button>`;
            html += `<button class="w-theme-editor-widget-move-down" title="下移" data-action="move-down" data-layout-id="${safeLayoutId}" data-template-ref="${safeTemplateRef}" ${isLast ? 'disabled' : ''}>
                        ${WIDGET_ACTION_ICONS.moveDown}
                     </button>`;
        }

        html += '</div>';
        return html;
    }

    function getWidgetSiblingIdentity(wrapper) {
        const identity = readWidgetIdentityFromElement(wrapper);
        return identity.identity;
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
            const wrapper = el.closest && el.closest(
                '.widget-wrapper[data-layout-id], .weline-template-widget[data-template-ref], .widget-wrapper[data-weline-template-widget="1"]'
            );
            if (!wrapper) continue;
            const id = getWidgetSiblingIdentity(wrapper);
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
        const identity = stack[idx];
        const wrapper = doc.querySelector(`.widget-wrapper${dataWidgetIdentitySelector(identity)}`) ||
            doc.querySelector(`.weline-template-widget${dataWidgetIdentitySelector(identity)}`) ||
            doc.querySelector(dataWidgetIdentitySelector(identity));
        if (wrapper) {
            wrapper.classList.add('show-actions');
            const bar = wrapper.querySelector(':scope > .widget-hover-actions');
            const floatApi = bar && window.Weline?.UI?.get?.(bar, 'anchored-float');
            if (floatApi?.sync) floatApi.sync();
            else if (floatApi?.place) floatApi.place();
            else if (bar) bar.dispatchEvent(new CustomEvent('weline:anchored-float:place'));
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
        const upBtn = bar.querySelector('.w-theme-editor-penetrate-up');
        const downBtn = bar.querySelector('.w-theme-editor-penetrate-down');
        if (!upBtn || !downBtn) return;
        const stack = state.nestStack;
        const idx = state.nestIndex;
        upBtn.hidden = !(idx > 0);
        downBtn.hidden = !(stack.length > 1 && idx < stack.length - 1);
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

        const overlayCss = elements.container?.dataset.editorOverlayCss || "";
        const overlayUrl = resolveSameOriginEditorUrl(overlayCss);
        if (!overlayUrl) {
            throw new Error("Theme Editor iframe overlay CSS URL is missing or cross-origin.");
        }
        const link = iframeDoc.createElement("link");
        link.id = "widget-hover-styles";
        link.rel = "stylesheet";
        link.href = overlayUrl;
        iframeDoc.head.appendChild(link);

        console.log("[ThemeEditor] Overlay stylesheet mounted in iframe");
    }

    function syncSlotToolbarFloatFromParent(toolbar) {
        if (!(toolbar instanceof HTMLElement)) {
            return;
        }
        const floatApi = window.Weline?.UI?.get?.(toolbar, 'anchored-float');
        if (floatApi?.sync) {
            floatApi.sync();
            return;
        }
        if (floatApi?.place) {
            floatApi.place();
            return;
        }
        toolbar.dispatchEvent(new CustomEvent('weline:anchored-float:place'));
    }

    function hideSlotToolbarFloatFromParent(toolbar) {
        if (!(toolbar instanceof HTMLElement)) {
            return;
        }
        const floatApi = window.Weline?.UI?.get?.(toolbar, 'anchored-float');
        if (floatApi?.hide) {
            floatApi.hide();
            return;
        }
        toolbar.dispatchEvent(new CustomEvent('weline:anchored-float:hide'));
    }

    function resolveSlotElementForToolbar(bar, doc) {
        if (!(bar instanceof HTMLElement) || !doc) {
            return null;
        }

        const fromDom = bar.closest('[data-wslot], [data-slot], .content-slot');
        if (fromDom instanceof HTMLElement) {
            return fromDom;
        }

        const slotId = String(
            bar.dataset.slotId
            || bar.dataset.wslot
            || bar.getAttribute('data-slot-id')
            || ''
        ).trim();
        if (slotId) {
            const byId = doc.querySelector(dataAttributeSelector('data-wslot', slotId))
                || doc.querySelector(dataAttributeSelector('data-slot', slotId));
            if (byId instanceof HTMLElement) {
                return byId;
            }
        }

        const hovered = doc.querySelector('[data-wslot][data-w-slot-hover-target="true"], [data-slot][data-w-slot-hover-target="true"], .content-slot[data-w-slot-hover-target="true"], .content-slot.slot-hover-target');
        if (hovered instanceof HTMLElement) {
            return hovered;
        }

        return null;
    }

    function syncSlotToolbarOwnerMeta(toolbar, owner) {
        if (!(toolbar instanceof HTMLElement) || !(owner instanceof HTMLElement)) {
            return;
        }
        const slotId = owner.getAttribute('data-wslot') || owner.getAttribute('data-slot') || '';
        if (!slotId) {
            return;
        }
        toolbar.dataset.slotId = slotId;
        const area = owner.getAttribute('data-wslot-position') || owner.getAttribute('data-position') || '';
        const name = owner.getAttribute('data-wslot-name') || owner.getAttribute('data-name') || '';
        if (area) {
            toolbar.dataset.wslotPosition = area;
        }
        if (name) {
            toolbar.dataset.wslotName = name;
        }
    }

    /**
     * 父页挂载插槽选择条 anchored-float（对齐 initWidgetHoverActions 对部件操作条的处理）。
     */
    function highlightPreviewSlotSelection(doc, slotEl) {
        if (!doc || !(slotEl instanceof HTMLElement)) {
            return;
        }
        doc.querySelectorAll('[data-wslot].slot-active').forEach(function(el) {
            el.classList.remove('slot-active');
        });
        slotEl.classList.add('slot-active');
    }

    /**
     * 插槽工具条点击由父页捕获阶段委托（对齐部件 bindWidgetActionEvents，避免 .widget-hover-actions 吞掉无 data-action 的按钮）。
     */
    function bindSlotToolbarActionEvents(doc) {
        if (!doc || !doc.body || doc.body._slotToolbarActionEventsBound) {
            return;
        }
        doc.body._slotToolbarActionEventsBound = true;

        doc.body.addEventListener('click', function(e) {
            const bar = e.target.closest('.widget-hover-actions[data-slot-hover-actions="1"]');
            if (!bar) {
                return;
            }
            const button = e.target.closest('button');
            if (!button || !bar.contains(button)) {
                return;
            }

            const action = String(button.dataset.action || '').trim();
            const isSelect = action === 'slot-select' || button.classList.contains('slot-select-btn');
            const isInit = action === 'slot-init-defaults' || button.classList.contains('slot-init-btn');
            if (!isSelect && !isInit) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            const slotEl = resolveSlotElementForToolbar(bar, doc);
            if (!(slotEl instanceof HTMLElement)) {
                showToast(translateUiText('缺少插槽标识'), 'warning');
                return;
            }

            if (isPreviewInteractionMode()) {
                setInteractionMode('edit');
            }

            syncSlotToolbarOwnerMeta(bar, slotEl);

            const slotId = String(
                slotEl.getAttribute('data-wslot')
                || slotEl.getAttribute('data-slot')
                || bar.dataset.slotId
                || ''
            ).trim();
            if (!slotId) {
                showToast(translateUiText('缺少插槽标识'), 'warning');
                return;
            }
            if (isSelect) {
                handleSlotSelected(buildSlotInfoFromElement(slotId, slotEl));
                highlightPreviewSlotSelection(doc, slotEl);
                return;
            }

            void initSlotDefaultsFromPreview({
                slot_id: slotId,
                area: slotEl.getAttribute('data-wslot-position')
                    || slotEl.getAttribute('data-position')
                    || bar.dataset.wslotPosition
                    || '',
                name: slotEl.getAttribute('data-wslot-name')
                    || slotEl.getAttribute('data-name')
                    || bar.dataset.wslotName
                    || '',
            }).catch(function(error) {
                console.error('[ThemeEditor] Slot init defaults failed:', error);
                showToast(error?.message || translateUiText('初始化失败'), 'error');
            });
        }, true);

        console.log('[ThemeEditor] Slot toolbar action events bound in iframe (capture)');
    }

    function initSlotToolbarFloats(iframeDoc) {
        if (!iframeDoc) {
            return;
        }
        let attached = 0;
        iframeDoc.querySelectorAll('[data-wslot] > .widget-hover-actions, [data-wslot] > .slot-toolbar').forEach((toolbar) => {
            if (!(toolbar instanceof HTMLElement)) {
                return;
            }
            if (!toolbar.classList.contains('widget-hover-actions')) {
                toolbar.classList.add('widget-hover-actions');
            }
            toolbar.setAttribute('data-slot-hover-actions', '1');
            toolbar.setAttribute('data-w-component', 'anchored-float');
            toolbar.setAttribute('data-w-float-self', '1');
            toolbar.setAttribute('data-w-placement', 'top-end');
            toolbar.setAttribute('data-w-portal', '0');
            const ownerSlot = toolbar.parentElement?.matches?.('[data-wslot], [data-slot], .content-slot')
                ? toolbar.parentElement
                : toolbar.closest('[data-wslot], [data-slot], .content-slot');
            syncSlotToolbarOwnerMeta(toolbar, ownerSlot);
            if (window.Weline?.UI?.floating?.attach) {
                window.Weline.UI.floating.attach(toolbar, {
                    placement: 'top-end',
                    portal: false,
                    self: true,
                });
                toolbar.dataset.wThemeSlotFloatAttached = '1';
                hideSlotToolbarFloatFromParent(toolbar);
                attached += 1;
            } else if (window.Weline?.UI?.mount) {
                window.Weline.UI.mount(toolbar);
                toolbar.dataset.wThemeSlotFloatAttached = '1';
                hideSlotToolbarFloatFromParent(toolbar);
                attached += 1;
            }
        });
        bindSlotToolbarFloatSync(iframeDoc);
        bindSlotToolbarActionEvents(iframeDoc);
        if (attached) {
            console.log('[ThemeEditor] Slot toolbars anchored-float attached,', attached);
        }
    }

    function bindSlotToolbarFloatSync(doc) {
        if (!doc || !doc.body || doc.body._slotToolbarFloatSyncBound) {
            return;
        }
        doc.body._slotToolbarFloatSyncBound = true;
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.type !== 'attributes' || !(m.target instanceof Element)) {
                    return;
                }
                if (!m.target.hasAttribute('data-wslot')) {
                    return;
                }
                const toolbar = m.target.querySelector(':scope > .widget-hover-actions, :scope > .slot-toolbar');
                if (!toolbar) {
                    return;
                }
                const hovered = m.target.getAttribute('data-w-slot-hover-target') === 'true'
                    || m.target.classList.contains('slot-hover-target');
                if (!hovered) {
                    hideSlotToolbarFloatFromParent(toolbar);
                    return;
                }
                if (toolbar.dataset.wThemeSlotFloatAttached !== '1' && window.Weline?.UI?.floating?.attach) {
                    window.Weline.UI.floating.attach(toolbar, {
                        placement: 'top-end',
                        portal: false,
                        self: true,
                    });
                    toolbar.dataset.wThemeSlotFloatAttached = '1';
                }
                syncSlotToolbarFloatFromParent(toolbar);
            });
        });
        observer.observe(doc.body, {
            subtree: true,
            attributes: true,
            attributeFilter: ['data-w-slot-hover-target', 'class'],
        });
    }

    function syncActiveSlotToolbarFloat() {
        const iframeDoc = elements.previewFrame?.contentDocument;
        if (!iframeDoc) {
            return;
        }
        const target = iframeDoc.querySelector('[data-wslot][data-w-slot-hover-target="true"]');
        const toolbar = target && target.querySelector(':scope > .widget-hover-actions, :scope > .slot-toolbar');
        if (toolbar) {
            syncSlotToolbarFloatFromParent(toolbar);
        }
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

        // 查找所有部件包装器（布局行 + 模板内嵌 w:widget）
        let widgetWrappers = Array.from(iframeDoc.querySelectorAll('.widget-wrapper[data-layout-id], .weline-template-widget[data-template-ref]'));
        if (widgetWrappers.length === 0) {
            widgetWrappers = Array.from(iframeDoc.querySelectorAll('[data-layout-id], [data-weline-template-widget="1"][data-template-ref]')).filter(function (el) {
                var parent = el.parentElement;
                while (parent) {
                    if (parent.matches('[data-layout-id], [data-weline-template-widget="1"][data-template-ref]')) {
                        return false;
                    }
                    parent = parent.parentElement;
                }
                return true;
            });
        }

        widgetWrappers.forEach((wrapper) => {
            const identity = readWidgetIdentityFromElement(wrapper);
            if (!identity.identity) {
                return;
            }
            const layoutId = identity.layoutId;
            const slotId = wrapper.getAttribute('data-slot-id') ||
                           wrapper.closest('[data-wslot]')?.getAttribute('data-wslot') ||
                           wrapper.closest('[data-slot]')?.getAttribute('data-slot') || '';
            const isExclusive = isExclusiveSlot(slotId, '');

            // 检查是否已有操作按钮
            if (wrapper.querySelector('.widget-hover-actions')) return;

            // 确保是 relative 定位
            if (getComputedStyle(wrapper).position === 'static') {
                wrapper.classList.add('w-theme-editor-position-context');
            }
            wrapper.classList.add('widget-wrapper');

            // 计算同层部件位置
            const siblings = wrapper.parentElement?.querySelectorAll(
                '[data-layout-id], .weline-template-widget[data-template-ref], .widget-wrapper[data-weline-template-widget="1"]'
            ) || [];
            const siblingArray = Array.from(siblings).filter(el => readWidgetIdentityFromElement(el).identity);
            const currentIndex = siblingArray.indexOf(wrapper);
            const isFirst = currentIndex === 0;
            const isLast = currentIndex === siblingArray.length - 1;

            // 添加操作按钮
            const actionsHtml = generateWidgetHoverActionsHtml(identity, slotId, isExclusive, isFirst, isLast);
            wrapper.insertAdjacentHTML('afterbegin', actionsHtml);
            const actionsEl = wrapper.querySelector(':scope > .widget-hover-actions');
            if (actionsEl && window.Weline?.UI?.floating?.attach) {
                window.Weline.UI.floating.attach(actionsEl, {
                    placement: 'top-end',
                    portal: false,
                    self: true,
                });
            } else if (actionsEl && window.Weline?.UI?.mount) {
                window.Weline.UI.mount(actionsEl);
            }
        });

        // 绑定按钮事件
        bindWidgetActionEvents(iframeDoc);

        // 嵌套距离：mousemove 用 elementsFromPoint 更新栈，按 nestIndex 决定在哪一层显示操作条
        bindPenetrateStateEvents(iframeDoc);

        // 绑定 slot 点击事件（选中 slot 后过滤部件并滚动）
        bindSlotClickEvents(iframeDoc);

        // 插槽选择条：与部件同源，由父页 Weline.UI.floating.attach 贴边（不依赖 iframe 内是否已加载 UI）
        initSlotToolbarFloats(iframeDoc);

        // 初始化拖拽排序
        initWidgetSortable();

        console.log('[ThemeEditor] Widget hover actions initialized,', widgetWrappers.length, 'widgets processed');
        syncTemplateWidgetsToStructureView();
    }

    function syncTemplateWidgetsToStructureView() {
        let iframeDoc = null;
        try {
            iframeDoc = elements.previewFrame?.contentDocument || elements.previewFrame?.contentWindow?.document;
        } catch (err) {
            console.warn('[ThemeEditor] Unable to sync template widgets to structure view:', err);
            return;
        }
        if (!iframeDoc) {
            return;
        }

        iframeDoc.querySelectorAll('.weline-template-widget[data-template-ref]:not([data-layout-id])').forEach((wrapper) => {
            const identity = readWidgetIdentityFromElement(wrapper);
            if (!identity.templateRef) {
                return;
            }
            if (document.querySelector(`.preview-widget-item${dataTemplateRefSelector(identity.templateRef)}`)) {
                return;
            }

            const slotEl = wrapper.closest('[data-wslot], [data-slot]');
            const slotId = slotEl?.getAttribute('data-wslot') || slotEl?.getAttribute('data-slot') || '';
            const area = slotEl?.getAttribute('data-wslot-position')
                || (slotEl?.closest('header') ? 'header' : (slotEl?.closest('footer') ? 'footer' : 'content'));

            let widgetConfig = {};
            try {
                widgetConfig = JSON.parse(identity.config || '{}') || {};
            } catch (e) {
                widgetConfig = {};
            }

            addWidgetToStructureView(area, slotId, {
                code: identity.widgetCode,
                module: identity.widgetModule,
                type: identity.widgetType,
                name: identity.widgetName || identity.widgetCode,
                config: widgetConfig,
            }, '', false, identity.templateRef);
        });
    }

    /**
     * 绑定嵌套距离：mousemove 用 elementsFromPoint 更新栈，按 nestIndex 决定在哪一层显示操作条。
     * 类似 tooltip sticky hover：移到操作条保持显示，离开后短延迟再切换/关闭，便于穿过 gap。
     */
    function bindPenetrateStateEvents(doc) {
        if (!doc || !doc.body) return;
        if (doc.body._penetrateStateBound) return;
        doc.body._penetrateStateBound = true;

        let nestHoverClearTimer = 0;
        let nestHoverPendingKey = '';
        const NEST_HOVER_STICKY_MS = 180;

        function nestStackKey(stack) {
            return (Array.isArray(stack) ? stack : []).map(function(id) {
                return String(id || '');
            }).join('|');
        }

        function clearNestHoverClearTimer() {
            if (nestHoverClearTimer) {
                window.clearTimeout(nestHoverClearTimer);
                nestHoverClearTimer = 0;
            }
            nestHoverPendingKey = '';
        }

        function clearNestHoverActions() {
            state.nestStack = [];
            state.nestIndex = 0;
            state.lastHoverPoint = null;
            doc.querySelectorAll('.widget-wrapper.show-actions').forEach(w => w.classList.remove('show-actions'));
        }

        function scheduleNestHoverTransition(nextStack) {
            const pending = Array.isArray(nextStack) ? nextStack.slice() : [];
            const pendingKey = nestStackKey(pending);
            if (nestHoverClearTimer && nestHoverPendingKey === pendingKey) {
                return;
            }
            clearNestHoverClearTimer();
            nestHoverPendingKey = pendingKey;
            nestHoverClearTimer = window.setTimeout(function() {
                nestHoverClearTimer = 0;
                nestHoverPendingKey = '';
                if (!pending.length) {
                    clearNestHoverActions();
                    return;
                }
                state.nestStack = pending;
                state.nestIndex = 0;
                setShowActionsByNest();
            }, NEST_HOVER_STICKY_MS);
        }

        function keepNestHoverFromActionsBar(target) {
            const bar = target && target.closest ? target.closest('.widget-hover-actions') : null;
            if (!bar) {
                return false;
            }
            clearNestHoverClearTimer();
            const wrapper = bar.closest(
                '.widget-wrapper[data-layout-id], .weline-template-widget[data-template-ref], .widget-wrapper[data-weline-template-widget="1"]'
            );
            if (wrapper) {
                const identity = getWidgetSiblingIdentity(wrapper);
                if (identity) {
                    if (!state.nestStack.length || state.nestStack.indexOf(identity) < 0) {
                        // 从操作条所属 wrapper 重建外→内栈（仅含自身祖先链上的部件）。
                        const chain = [];
                        let node = wrapper;
                        while (node && node !== doc.body) {
                            if (node.matches && node.matches(
                                '.widget-wrapper[data-layout-id], .weline-template-widget[data-template-ref], .widget-wrapper[data-weline-template-widget="1"]'
                            )) {
                                const id = getWidgetSiblingIdentity(node);
                                if (id) chain.unshift(id);
                            }
                            node = node.parentElement;
                        }
                        state.nestStack = chain.length ? chain : [identity];
                    }
                    const idx = state.nestStack.indexOf(identity);
                    state.nestIndex = idx >= 0 ? idx : 0;
                }
            }
            setShowActionsByNest();
            return true;
        }

        doc.body.addEventListener('mousemove', function(e) {
            if (isPreviewInteractionMode()) {
                return;
            }
            // 插槽模式：部件操作条不参与命中，避免挡住大槽/空槽选择。
            if (normalizeSelectionTarget(state.selectionTarget) === 'slot') {
                clearNestHoverClearTimer();
                clearNestHoverActions();
                return;
            }
            state.lastHoverPoint = { x: e.clientX, y: e.clientY };

            if (keepNestHoverFromActionsBar(e.target)) {
                return;
            }

            const stack = getWidgetStackAtPoint(doc, e.clientX, e.clientY);
            const currentIdentity = (state.nestStack && state.nestStack.length && state.nestStack[state.nestIndex] != null)
                ? state.nestStack[state.nestIndex]
                : null;

            if (currentIdentity) {
                const currentWrapper = doc.querySelector(`.widget-wrapper${dataWidgetIdentitySelector(currentIdentity)}`) ||
                    doc.querySelector(`.weline-template-widget${dataWidgetIdentitySelector(currentIdentity)}`) ||
                    doc.querySelector(dataWidgetIdentitySelector(currentIdentity));
                if (currentWrapper && currentWrapper.contains(e.target)) {
                    clearNestHoverClearTimer();
                    const prevTop = state.nestStack[0];
                    state.nestStack = stack.length ? stack : state.nestStack;
                    if (stack.length && stack[0] !== prevTop) {
                        const keepIdx = stack.indexOf(currentIdentity);
                        state.nestIndex = keepIdx >= 0 ? keepIdx : 0;
                    }
                    setShowActionsByNest();
                    return;
                }

                // 离开部件本体（含浮到外侧操作条的空隙）：保持当前条，延迟切换。
                setShowActionsByNest();
                scheduleNestHoverTransition(stack);
                return;
            }

            clearNestHoverClearTimer();
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
            scheduleNestHoverTransition([]);
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
            if (isPreviewInteractionMode()) {
                return;
            }
            if (e.target.closest('.widget-hover-actions[data-slot-hover-actions="1"]')) {
                return;
            }
            const button = e.target.closest('.widget-hover-actions button');

            // 立即阻止事件传播（在检查之前）
            if (e.target.closest('.widget-hover-actions')) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
            }

            if (!button) {
                if (handleIframeWidgetElementClick(e.target)) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                }
                return;
            }

            console.log('[ThemeEditor] Widget action button clicked:', button.dataset.action);

            const action = button.dataset.action;
            const bar = button.closest('.widget-hover-actions');
            const layoutId = bar ? bar.dataset.layoutId : button.dataset.layoutId;
            const slotId = button.dataset.slotId;

            switch (action) {
                case 'info':
                case 'config': {
                    const effectiveIdentity = (state.nestStack && state.nestStack.length && state.nestStack[state.nestIndex] != null)
                        ? state.nestStack[state.nestIndex]
                        : (bar?.dataset?.layoutId || bar?.dataset?.templateRef || layoutId);
                    const item = document.querySelector(`.preview-widget-item${dataWidgetIdentitySelector(effectiveIdentity)}`);
                    if (item) {
                        selectWidget(item);
                    } else if (String(effectiveIdentity || '').startsWith('tpl:')) {
                        handlePreviewWidgetSelected({
                            type: 'widget-selected',
                            templateRef: effectiveIdentity,
                            slotId: slotId || '',
                        });
                    } else {
                        openConfigPanelForWidgetSelection();
                        setConfigMode('widget');
                        loadWidgetConfigForAccordion(effectiveIdentity);
                        const accordionBody = document.querySelector(`.slot-widget-body${dataWidgetIdentitySelector(effectiveIdentity)}`);
                            if (accordionBody) {
                                const disclosure = accordionBody.closest('[data-w-component~="disclosure"]');
                                if (disclosure) {
                                    getEditorUi().mount(disclosure);
                                    getEditorUi().get(disclosure, 'disclosure')?.open();
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
                case 'ai-edit':
                case 'ai-rebuild':
                case 'ai-image': {
                    const effectiveLayoutId = (state.nestStack && state.nestStack.length && state.nestStack[state.nestIndex] != null)
                        ? state.nestStack[state.nestIndex]
                        : layoutId;
                    let effectiveSlotId = slotId;
                    const iframe = elements.previewFrame;
                    if (iframe && iframe.contentDocument) {
                        const targetWidget = iframe.contentDocument.querySelector(`[data-layout-id="${effectiveLayoutId}"]`);
                        if (targetWidget) {
                            effectiveSlotId = targetWidget.getAttribute('data-slot-id') ||
                                targetWidget.closest('[data-wslot]')?.getAttribute('data-wslot') ||
                                targetWidget.closest('[data-slot]')?.getAttribute('data-slot') || slotId;
                        }
                    }
                    handleWidgetAiAction(action, effectiveLayoutId, effectiveSlotId, button);
                    break;
                }
                case 'replace':
                case 'delete':
                case 'move-up':
                case 'move-down': {
                    const deleteTarget = resolveWidgetDeleteTarget(button, bar);
                    const topmostLayoutId = deleteTarget.layoutId || deleteTarget.templateRef;
                    let topmostSlotId = deleteTarget.slotId || slotId;
                    const iframe = elements.previewFrame;
                    const widgetSelector = resolveWidgetElementSelector(deleteTarget);
                    if (iframe && iframe.contentDocument && widgetSelector) {
                        const topW = iframe.contentDocument.querySelector(widgetSelector);
                        if (topW) {
                            topmostSlotId = topW.getAttribute('data-slot-id') ||
                                topW.closest('[data-wslot]')?.getAttribute('data-wslot') ||
                                topW.closest('[data-slot]')?.getAttribute('data-slot') || topmostSlotId;
                        }
                    }
                    if (action === 'replace') handleWidgetReplace(topmostLayoutId, topmostSlotId);
                    else if (action === 'delete') handleWidgetDelete(deleteTarget, topmostSlotId);
                    else if (action === 'move-up') handleWidgetMoveUp(topmostLayoutId);
                    else if (action === 'move-down') handleWidgetMoveDown(topmostLayoutId);
                    break;
                }
            }
        }, true); // 使用捕获阶段
    }

    function getWidgetAiActionLabel(action) {
        switch (action) {
            case 'ai-edit':
                return 'AI编辑';
            case 'ai-rebuild':
                return 'AI重建';
            case 'ai-image':
                return 'AI图片资源重新生成';
            default:
                return 'AI操作';
        }
    }

    function setWidgetAiButtonLoading(button, loading) {
        if (!button) return;
        if (loading) {
            button.dataset.originalTitle = button.getAttribute('title') || '';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.setAttribute('title', 'AI处理中');
            return;
        }
        button.disabled = false;
        button.removeAttribute('aria-busy');
        if (button.dataset.originalTitle) {
            button.setAttribute('title', button.dataset.originalTitle);
        }
        delete button.dataset.originalTitle;
    }

    async function loadVirtualThemeAiCatalog(force = false) {
        if (!force && state.virtualThemeAiCatalog) {
            return state.virtualThemeAiCatalog;
        }
        const url = new URL(config.apiVirtualThemeAiCatalog, window.location.origin);
        url.searchParams.set('adapter_code', 'theme');
        const result = await apiJson(url.toString(), { silent: true });
        if (!result.success) {
            throw new Error(result.message || 'AI目录加载失败');
        }
        state.virtualThemeAiCatalog = result.data || {};
        return state.virtualThemeAiCatalog;
    }

    function normalizeCatalogItems(catalog, key) {
        const bucket = catalog && catalog[key] ? catalog[key] : {};
        return Array.isArray(bucket.items) ? bucket.items : [];
    }

    function renderAiSkillChoices(items) {
        if (!items.length) {
            return '<div class="w-theme-editor-ai-dialog__empty">暂无可选技能</div>';
        }
        return items.map((item) => {
            const code = String(item.code || '');
            const checked = item.locked || item.manual ? 'checked' : '';
            const disabled = item.readonly && !item.locked && !item.manual ? 'disabled' : '';
            return `<label class="w-theme-editor-ai-dialog__choice">
                <input type="checkbox" name="virtual_ai_skill" value="${escapeHtml(code)}" ${checked} ${disabled}>
                <span><strong>${escapeHtml(item.name || code)}</strong><br><small>${escapeHtml(item.description || code)}</small></span>
            </label>`;
        }).join('');
    }

    function renderAiStyleChoices(items) {
        const auto = `<label class="w-theme-editor-ai-dialog__choice">
            <input type="radio" name="virtual_ai_style" value="" checked>
            <span><strong>自动匹配</strong><br><small>由 Theme AI 根据当前指令选择方向</small></span>
        </label>`;
        if (!items.length) {
            return auto;
        }
        return auto + items.map((item) => {
            const code = String(item.code || '');
            return `<label class="w-theme-editor-ai-dialog__choice">
                <input type="radio" name="virtual_ai_style" value="${escapeHtml(code)}">
                <span><strong>${escapeHtml(item.name || code)}</strong><br><small>${escapeHtml(item.description || code)}</small></span>
            </label>`;
        }).join('');
    }

    function readCheckedValues(root, selector) {
        return Array.from(root.querySelectorAll(selector))
            .filter(input => input.checked && !input.disabled)
            .map(input => String(input.value || '').trim())
            .filter(Boolean);
    }

    async function openVirtualThemeAiDialog(action, layoutId, slotId) {
        let catalog = {};
        try {
            catalog = await loadVirtualThemeAiCatalog(false);
        } catch (err) {
            console.warn('[ThemeEditor] AI catalog unavailable:', err);
        }
        const label = getWidgetAiActionLabel(action);
        const skillItems = normalizeCatalogItems(catalog, 'skills');
        const styleItems = normalizeCatalogItems(catalog, 'styles');

        return new Promise((resolve) => {
            const container = document.createElement('dialog');
            container.className = 'w-dialog w-theme-editor-ai-dialog';
            container.dataset.wComponent = 'dialog';
            container.dataset.state = 'closed';
            container.dataset.size = 'lg';
            container.dataset.wClosable = 'true';
            container.dataset.wBackdrop = 'dismissible';
            container.innerHTML = `
                <header class="w-dialog__header">
                    <h2 class="w-dialog__title">${escapeHtml(label)}</h2>
                    <button type="button" class="w-button" data-w-close data-tone="quiet" data-size="sm"
                            data-virtual-ai-cancel aria-label="关闭"></button>
                </header>
                <div class="w-dialog__body w-theme-editor-ai-dialog__body">
                    <label class="w-field w-theme-editor-ai-dialog__field">
                        <span class="w-field__label">指令</span>
                        <textarea class="w-textarea w-theme-editor-ai-dialog__textarea" data-virtual-ai-instructions
                                  placeholder="说明这个 block 要如何变化" autofocus></textarea>
                    </label>
                    <fieldset class="w-theme-editor-ai-dialog__field">
                        <legend class="w-theme-editor-ai-dialog__label">技能</legend>
                        <div class="w-theme-editor-ai-dialog__grid">${renderAiSkillChoices(skillItems)}</div>
                    </fieldset>
                    <fieldset class="w-theme-editor-ai-dialog__field">
                        <legend class="w-theme-editor-ai-dialog__label">方向</legend>
                        <div class="w-theme-editor-ai-dialog__grid">${renderAiStyleChoices(styleItems)}</div>
                    </fieldset>
                    <input type="hidden" data-virtual-ai-layout-id value="${escapeHtml(layoutId)}">
                    <input type="hidden" data-virtual-ai-slot-id value="${escapeHtml(slotId || '')}">
                </div>
                <footer class="w-dialog__footer">
                    <button type="button" class="w-button" data-tone="neutral" data-virtual-ai-cancel>取消</button>
                    <button type="button" class="w-button" data-tone="primary" data-virtual-ai-confirm>${escapeHtml(label)}</button>
                </footer>
            `;
            document.body.appendChild(container);
            getEditorUi().mount(container);
            let result = null;
            let settled = false;
            const finish = () => {
                if (settled) return;
                settled = true;
                getEditorUi().unmount(container);
                container.remove();
                resolve(result);
            };
            container.addEventListener('close', finish, { once: true });
            container.querySelectorAll('[data-virtual-ai-cancel]').forEach((button) => {
                button.addEventListener('click', () => getEditorUi().dialog.close(container, 'cancel'));
            });
            container.querySelector('[data-virtual-ai-confirm]')?.addEventListener('click', () => {
                result = {
                    instructions: String(container.querySelector('[data-virtual-ai-instructions]')?.value || ''),
                    selected_skill_codes: readCheckedValues(container, 'input[name="virtual_ai_skill"]'),
                    selected_style_codes: readCheckedValues(container, 'input[name="virtual_ai_style"]'),
                    use_ai: true,
                };
                getEditorUi().dialog.close(container, 'confirm');
            });
            if (!getEditorUi().dialog.open(container)) {
                finish();
            }
        });
    }

    async function handleWidgetAiAction(action, layoutId, slotId, button) {
        if (!layoutId) {
            showToast('缺少 block 身份', 'warning');
            return;
        }
        const dialog = await openVirtualThemeAiDialog(action, layoutId, slotId);
        if (!dialog) {
            return;
        }

        setWidgetAiButtonLoading(button, true);
        try {
            const result = await apiJson(config.apiVirtualThemeBlockAction, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: action,
                    theme_id: state.themeId || 0,
                    area: state.editorArea || 'frontend',
                    page_type: state.layoutType || getCurrentPageType() || 'homepage',
                    layout_type: state.layoutType || getCurrentPageType() || 'homepage',
                    layout_option: state.layoutOption || 'default',
                    layout_id: layoutId,
                    slot_id: slotId || '',
                    request_id: `block-${action}-${layoutId}-${Date.now()}`,
                    instructions: dialog.instructions,
                    selected_skill_codes: dialog.selected_skill_codes,
                    selected_style_codes: dialog.selected_style_codes,
                    use_ai: 1,
                    ...getLayoutLockVirtualPayload(),
                })
            });
            if (!result.success) {
                throw new Error(result.message || `${getWidgetAiActionLabel(action)}失败`);
            }

            const data = result.data || {};
            if (data.layout_option) {
                await refreshLayoutOptions({
                    layout_type: state.layoutType || getCurrentPageType() || 'homepage',
                    layout_option: data.layout_option,
                    silent: true
                });
            }
            showToast(result.message || `${getWidgetAiActionLabel(action)}草稿已生成`, 'success');
        } catch (err) {
            console.error('[ThemeEditor] block AI action failed:', err);
            showToast(err.message || `${getWidgetAiActionLabel(action)}失败`, 'error');
        } finally {
            setWidgetAiButtonLoading(button, false);
        }
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
            if (isPreviewInteractionMode()) {
                return;
            }
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
            const rejectAttr = slotEl.getAttribute('data-wslot-reject') || '';

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
                reject: rejectAttr,
                max: slotEl.getAttribute('data-wslot-max') || '',
                min: slotEl.getAttribute('data-wslot-min') || '',
                exclusive: slotEl.getAttribute('data-wslot-exclusive') === 'true',
                multiple: slotEl.getAttribute('data-wslot-multiple') !== 'false',
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
                const slotEl = iframe.contentDocument.querySelector(dataAttributeSelector('data-wslot', slotId)) ||
                               iframe.contentDocument.querySelector(dataAttributeSelector('data-slot', slotId));
                if (slotEl) {
                    slotInfo.accept = slotEl.getAttribute('data-wslot-accept') ||
                                      slotEl.getAttribute('data-accept') || '*';
                    slotInfo.reject = slotEl.getAttribute('data-wslot-reject') || '';
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
            document.querySelectorAll('.widget-item').forEach(item => {
                if (slotAcceptsWidgetCodes(
                    state.selectedSlot.accept,
                    state.selectedSlot.reject,
                    state.selectedSlot.id || slotId,
                    collectWidgetElementSupportCodes(item)
                )) {
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
    async function handleWidgetDelete(identityInput, slotId) {
        const deleteTarget = typeof identityInput === 'object' && identityInput !== null
            ? identityInput
            : {
                layoutId: String(identityInput || '').trim(),
                templateRef: String(identityInput || '').startsWith('tpl:') ? String(identityInput || '').trim() : '',
                slotId: String(slotId || '').trim(),
            };
        console.log('[ThemeEditor] Delete widget:', deleteTarget, 'in slot:', slotId);

        if (!deleteTarget.layoutId && !deleteTarget.templateRef) {
            showToast('缺少部件标识，无法删除', 'error');
            return;
        }

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

        let area = 'content';
        try {
            const iframe = elements.previewFrame;
            const widgetSelector = resolveWidgetElementSelector(deleteTarget);
            if (iframe && iframe.contentDocument && widgetSelector) {
                const widgetEl = iframe.contentDocument.querySelector(widgetSelector);
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

        const widgetContext = resolveWidgetContextFromIframe(deleteTarget);
        const effectiveSlotId = slotId || deleteTarget.slotId || widgetContext.slotId || '';

        try {
            // 调用删除 API - 传递 slot_id 和 area 作为后端 fallback
            const result = await apiJson(config.apiDeleteWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(buildWidgetDeletePayload(deleteTarget, effectiveSlotId, area, widgetContext))
            });

            if (result.success) {
                await queueRemovedLayoutNode(result, {
                    layoutId: deleteTarget.layoutId || result.layout_id,
                    nodeUid: widgetContext.nodeUid,
                });
                // 从 iframe 中移除部件并恢复原始内容
                const iframe = elements.previewFrame;
                const widgetSelector = resolveWidgetElementSelector(deleteTarget);
                if (iframe && iframe.contentDocument && widgetSelector) {
                    const widgetEl = iframe.contentDocument.querySelector(widgetSelector);
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
                                    <div class="slot-placeholder w-theme-editor-slot-placeholder">
                                        ${iconSvg('inbox')}
                                        <p class="w-theme-editor-slot-placeholder__title">插槽原本为空</p>
                                        <p class="w-theme-editor-slot-placeholder__hint">拖入部件或点击选择新部件</p>
                                    </div>
                                `;
                            }
                        }
                    }
                }

                // 从结构视图中移除
                const structureSelector = deleteTarget.layoutId
                    ? dataLayoutIdSelector(deleteTarget.layoutId)
                    : dataTemplateRefSelector(deleteTarget.templateRef);
                const structureItem = document.querySelector(`.preview-widget-item${structureSelector}`);
                if (structureItem) {
                    structureItem.remove();
                }

                showToast('部件已删除', 'success');
                notifyDashboardLayoutMutated('widget-deleted', {
                    layoutId: deleteTarget.layoutId || result.layout_id || null,
                    templateRef: deleteTarget.templateRef || null,
                    slotId: effectiveSlotId || slotId || null,
                });
                await refreshDefaultInjectionApplications({ render: state.widgetLibraryTab === 'applications', silent: true });

                // 更新同层部件的移动按钮状态（如果有的话）
                const refreshSlotId = effectiveSlotId || slotId;
                if (refreshSlotId) {
                    updateSiblingMoveButtons(refreshSlotId);
                }
            } else {
                showToast(result.message || '删除失败', 'error');
            }
        } catch (err) {
            console.error('[ThemeEditor] Delete widget error:', err);
            showToast(err?.message || '删除部件时发生错误', 'error');
        }
    }

    /**
     * 处理部件上移 — 先交换 DOM 再走 persistSlotSortOrder 统一持久化
     */
    async function handleWidgetMoveUp(layoutId) {
        console.log('[ThemeEditor] Move up widget:', layoutId);

        const iframe = elements.previewFrame;
        if (!iframe || !iframe.contentDocument) return;

        const widgetEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
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

        const widgetEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
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
            const result = await apiJson(config.apiBase + '/swap-widget-order', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    layout_id_1: layoutId1,
                    layout_id_2: layoutId2
                })
            });

            if (result.success) {
                await queueLayoutNodePlacementOwnership(result.data?.nodes || [], 'layout_nodes_swapped');
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
        let slotEl = iframe.contentDocument.querySelector(dataAttributeSelector('data-wslot', slotId)) ||
                     iframe.contentDocument.querySelector(dataAttributeSelector('data-slot', slotId));

        if (!slotEl) return;

        // 获取插槽内所有部件
        const widgets = slotEl.querySelectorAll('[data-layout-id]');

        widgets.forEach((widget, index) => {
            const upBtn = widget.querySelector('.w-theme-editor-widget-move-up');
            const downBtn = widget.querySelector('.w-theme-editor-widget-move-down');

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
        const slotEl = iframeDoc.querySelector(dataAttributeSelector('data-wslot', slotId)) ||
                       iframeDoc.querySelector(dataAttributeSelector('data-slot', slotId));
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
            const result = await apiJson(config.apiBase + '/update-sort', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    theme_id: state.themeId,
                    sort_data: sortData
                })
            });

            if (result.success) {
                await queueLayoutNodePlacementOwnership(result.data?.nodes || [], 'layout_nodes_sorted');
                showToast('排序已保存', 'success');
                updateSiblingMoveButtons(slotId);
                notifyDashboardLayoutMutated('widget-sorted', {
                    slotId: slotId || null,
                });
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
        const areaWidgets = document.querySelector(`.area-widgets${dataAttributeSelector('data-area', area)}`);
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

        const identity = readWidgetIdentityFromElement(widgetElement);
        if (identity.isTemplate) {
            handlePreviewWidgetSelected({
                type: 'widget-selected',
                templateRef: identity.templateRef,
                widgetCode: identity.widgetCode || widgetElement.dataset.widgetCode || '',
                widgetModule: identity.widgetModule || widgetElement.dataset.widgetModule || '',
                widgetType: identity.widgetType || widgetElement.dataset.widgetType || '',
                widgetName: identity.widgetName || widgetElement.dataset.widgetName || '',
                config: identity.config || widgetElement.dataset.config || '{}',
                slotId: widgetElement.dataset.slotId || widgetElement.getAttribute('data-slot-id') || '',
            });
            return;
        }

        state.selectedWidget = widgetElement;
        state.selectedSlot = null;

        // 加载配置面板
        openConfigPanelForWidgetSelection();
        setConfigMode('widget');
        loadWidgetConfig(widgetElement);
        markPreviewWidgetSelected(identity.layoutId || '');
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
                    ${iconSvg('cursor')}
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
        // 显示加载状态
        modalBody.innerHTML = `
            <div class="w-theme-editor-loading-state">
                <span class="w-spinner" role="status"><span class="w-visually-hidden">加载中...</span></span>
            </div>
        `;

        // 打开模态框
        showEditorModal(modal);

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
        const renderPreviewError = function(el, message) {
            if (!el) return;
            el.innerHTML = '';
            const error = document.createElement('div');
            error.className = 'widget-preview-error';
            error.textContent = message || (typeof __ !== 'undefined' ? __('加载失败') : '加载失败');
            el.appendChild(error);
        };

        if (!modal || !inners.length) return;

        if (titleEl) titleEl.textContent = (widgetName || widgetCode || '') + ' - ' + (typeof __ !== 'undefined' ? __('组件预览') : '组件预览');
        loadingEl?.classList.add('visible');
        inners.forEach(el => { el.innerHTML = ''; });

        showEditorModal(modal);

        try {
            const url = new URL(config.apiWidgetPreview, window.location.origin);
            url.searchParams.set('widget_module', widgetModule);
            url.searchParams.set('widget_code', widgetCode);
            url.searchParams.set('editor_area', state.editorArea || 'frontend');
            url.searchParams.set('theme_id', String(state.themeId || 0));
            url.searchParams.set('_t', String(Date.now()));
            const data = await apiJson(url.toString());
            if (data && data.html) {
                let html = sanitizeHtmlForEditorPreview(data.html);
                if (isBasicThemeComponentPreviewCode(widgetCode)) {
                    const visibleText = getPreviewHtmlText(html);
                    html = (!visibleText || isWidgetPreviewFallbackHtml(html))
                        ? buildClientComponentPreviewHtml(widgetCode, widgetName)
                        : '<div class="te-component-preview te-component-preview-' + escapeHtml(normalizeWidgetPreviewCode(widgetCode)) + '">' + html + '</div>';
                }
                inners.forEach(el => { el.innerHTML = html; });
            } else if (data && data.success === false) {
                inners.forEach(el => renderPreviewError(el, data.message || ''));
            } else {
                inners.forEach(el => { el.innerHTML = ''; });
            }
        } catch (err) {
            const errMsg = err && err.message ? err.message : String(err);
            inners.forEach(el => renderPreviewError(el, errMsg || ''));
        }
        loadingEl?.classList.remove('visible');

        if (responsiveInput && responsiveViewport && responsiveLabel) {
            const updateResponsiveWidth = function() {
                const w = parseInt(responsiveInput.value, 10) || 768;
                responsiveViewport.style.setProperty('--component-preview-width', w + 'px');
                responsiveLabel.textContent = w + 'px';
            };
            responsiveInput.oninput = updateResponsiveWidth;
            updateResponsiveWidth();
        }
    }

    /**
     * 加载部件配置（用于模态框）
     */
    function buildSavedWidgetConfigUrl(layoutId, locale) {
        const apiUrl = new URL(`${config.apiBase}/widget-config`, window.location.origin);
        apiUrl.searchParams.set('layout_id', layoutId);
        if (locale) {
            apiUrl.searchParams.set('locale', locale);
        }
        return apiUrl.toString();
    }

    async function loadSavedWidgetConfig(layoutId, locale) {
        const result = await apiJson(buildSavedWidgetConfigUrl(layoutId, locale));
        if (!result || !result.success || !result.data) {
            throw new Error((result && result.message) || 'Widget config load failed');
        }
        return result.data;
    }

    function getWidgetElementMeta(widgetElement, widgetCode, params) {
        const name = widgetElement?.querySelector('.widget-name')?.textContent?.replace(/\s+/g, ' ').trim() || widgetCode || '';
        const description = widgetElement?.querySelector('.widget-preview')?.textContent?.replace(/\s+/g, ' ').trim() || '';
        return { name, description, params: params || {} };
    }

    async function renderWidgetConfigModalWithMeta(layoutId, widgetMeta, widgetConfig, modalBody, widgetElement, widgetCode, widgetType, widgetModule) {
        const params = (widgetMeta && widgetMeta.params && typeof widgetMeta.params === 'object') ? widgetMeta.params : {};
        const formHtml = await generateWidgetConfigForm(layoutId, params, widgetConfig);
        if (formHtml && formHtml.trim() && !formHtml.includes('alert-danger') && (formHtml.includes('w-param-form') || formHtml.includes('<form'))) {
        const icon = widgetTypeIconName(widgetType);
            const widgetName = escapeHtml(widgetMeta?.name || widgetCode || '');
            const widgetDesc = escapeHtml((widgetMeta?.description || '') + '');
            const headerHtml = `<div class="widget-config-panel"><div class="config-header"><div class="config-widget-info"><div class="widget-icon">${iconSvg(icon)}</div><div class="widget-meta"><h4 class="widget-name">${widgetName}</h4><p class="widget-desc">${widgetDesc}</p></div></div></div>`;
            const searchWrap = '<div class="w-param-search-wrap"><input type="text" class="w-param-search w-input w-theme-editor-control-sm" placeholder="Search config" autocomplete="off"></div>';
            modalBody.innerHTML = headerHtml + searchWrap + formHtml + '<div class="config-actions"><button type="button" class="w-button" data-w-action="dialog.close">Close</button></div></div>';
            const form = modalBody.querySelector('.w-param-form');
            if (form) {
                form.id = 'widgetConfigFormModal';
                form.setAttribute('data-layout-id', layoutId);
                if (widgetElement) {
                    form.setAttribute('data-widget-element-id', 'widget_' + layoutId);
                }
                function scheduleAutoSave() {
                    scheduleEditorAutoSave(
                        `widget-config-modal:${layoutId}`,
                        () => saveWidgetConfigFromModal(form, widgetElement, { autoSave: true }),
                    );
                }
                form.addEventListener('input', scheduleAutoSave);
                form.addEventListener('change', scheduleAutoSave);
            }
            bindAccordionFormEvents(modalBody);
            bindParamSearch(modalBody);
            return;
        }

        renderConfigFormToModal({
            layout_id: layoutId,
            widget_code: widgetCode,
            widget_module: widgetModule,
            widget_type: widgetType,
            config: widgetConfig,
            meta: widgetMeta,
        }, params, modalBody, widgetElement);
    }

    async function loadWidgetConfigForModal(widgetElement, modalBody) {
        modalBody.setAttribute('data-theme-editor-config-modal', '1');
        const layoutId = widgetElement.dataset.layoutId;
        const widgetModule = widgetElement.dataset.widgetModule;
        const widgetCode = widgetElement.dataset.widgetCode;
        const widgetType = widgetElement.dataset.widgetType;
        let widgetConfig = {};

        if (layoutId) {
            try {
                const savedData = await loadSavedWidgetConfig(layoutId, getActiveConfigLocale());
                const params = (savedData.params && typeof savedData.params === 'object') ? savedData.params : {};
                const savedConfig = (savedData.config && typeof savedData.config === 'object') ? savedData.config : {};
                const meta = getWidgetElementMeta(widgetElement, savedData.widget_code || widgetCode, params);
                await renderWidgetConfigModalWithMeta(
                    layoutId,
                    meta,
                    savedConfig,
                    modalBody,
                    widgetElement,
                    savedData.widget_code || widgetCode,
                    savedData.widget_type || widgetType,
                    savedData.widget_module || widgetModule
                );
                return;
            } catch (err) {
                console.warn('[ThemeEditor] saved widget config endpoint failed, try widget library:', err);
            }
        }

        try {
            widgetConfig = JSON.parse(widgetElement.dataset.config || '{}');
        } catch (e) {
            widgetConfig = {};
        }

        // 通过 Weline.Api 获取部件参数定义
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
        const icon = widgetTypeIconName(widgetType);
                        const widgetName = escapeHtml(widgetMeta.name || widgetCode || '');
                        const widgetDesc = escapeHtml((widgetMeta.description || '') + '');
                        const headerHtml = `<div class="widget-config-panel"><div class="config-header"><div class="config-widget-info"><div class="widget-icon">${iconSvg(icon)}</div><div class="widget-meta"><h4 class="widget-name">${widgetName}</h4><p class="widget-desc">${widgetDesc}</p></div></div></div>`;
                        const searchPlaceholder = (typeof __ !== 'undefined' ? __('搜索配置项') : '搜索配置项');
                        const searchWrap = '<div class="w-param-search-wrap"><input type="text" class="w-param-search w-input w-theme-editor-control-sm" placeholder="' + searchPlaceholder + '" autocomplete="off"></div>';
                        modalBody.innerHTML = headerHtml + searchWrap + formHtml + '<div class="config-actions"><button type="button" class="w-button" data-w-action="dialog.close">' + (typeof __ !== 'undefined' ? __('关闭') : '关闭') + '</button></div></div>';
                        const form = modalBody.querySelector('.w-param-form');
                        if (form) {
                            form.id = 'widgetConfigFormModal';
                            form.setAttribute('data-layout-id', layoutId);
                            if (widgetElement) form.setAttribute('data-widget-element-id', 'widget_' + layoutId);
                            function scheduleAutoSave() {
                                scheduleEditorAutoSave(
                                    `widget-config-modal:${layoutId}`,
                                    () => saveWidgetConfigFromModal(form, widgetElement, { autoSave: true }),
                                );
                            }
                            form.addEventListener('input', scheduleAutoSave);
                            form.addEventListener('change', scheduleAutoSave);
                        }
                        if (typeof Weline.Widget?.Params?.mount === 'function') Weline.Widget.Params.mount(modalBody);
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
                    modalBody.innerHTML = '<p class="w-text" data-tone="muted">未找到部件配置信息</p>';
                }
            }
        } catch (err) {
            console.error('Load config error:', err);
            modalBody.innerHTML = '<p class="w-text" data-tone="danger">加载配置失败</p>';
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

        if (layoutId) {
            try {
                const savedData = await loadSavedWidgetConfig(layoutId);
                const params = (savedData.params && typeof savedData.params === 'object') ? savedData.params : {};
                const savedConfig = (savedData.config && typeof savedData.config === 'object') ? savedData.config : {};
                const meta = getWidgetElementMeta(widgetElement, savedData.widget_code || widgetCode, params);
                await renderConfigFormWithBackend({
                    layout_id: layoutId,
                    widget_code: savedData.widget_code || widgetCode,
                    widget_module: savedData.widget_module || widgetModule,
                    widget_type: savedData.widget_type || widgetType,
                    config: savedConfig,
                    meta,
                }, params);
                return;
            } catch (err) {
                console.warn('[ThemeEditor] saved widget config endpoint failed, try widget library:', err);
            }
        }

        try {
            widgetConfig = JSON.parse(widgetElement.dataset.config || '{}');
        } catch (e) {
            widgetConfig = {};
        }

        // 通过 Weline.Api 获取部件参数定义
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
                    await renderConfigFormWithBackend({
                        layout_id: layoutId,
                        widget_code: widgetCode,
                        widget_module: widgetModule,
                        widget_type: widgetType,
                        config: widgetConfig,
                        meta: widgetMeta,
                    }, widgetMeta.params || {});
                } else {
                    elements.configContent.innerHTML = '<p class="w-text" data-tone="muted">未找到部件配置信息</p>';
                }
            }
        } catch (err) {
            console.error('Load config error:', err);
            elements.configContent.innerHTML = '<p class="w-text" data-tone="danger">加载配置失败</p>';
        }
    }

    /**
     * 渲染配置表单到模态框
     */
    function renderConfigFormToModal(widget, params, modalBody, widgetElement) {
        const icon = widgetTypeIconName(widget.widget_type);
        const widgetName = widget.meta?.name || widget.widget_code;
        const widgetDesc = widget.meta?.description || '';
        const savedConfig = widget.config || {};
        const safeLayoutId = escapeHtml(widget.layout_id || '');
        const safeWidgetElementId = widgetElement ? escapeHtml('widget_' + widget.layout_id) : '';

        let html = `
            <div class="widget-config-panel">
                <div class="config-header">
                    <div class="config-widget-info">
                        <div class="widget-icon">
                            ${iconSvg(icon)}
                        </div>
                        <div class="widget-meta">
                            <h4 class="widget-name">${escapeHtml(widgetName)}</h4>
                            <p class="widget-desc">${escapeHtml(widgetDesc)}</p>
                        </div>
                    </div>
                </div>
                <form class="config-form" id="widgetConfigFormModal" data-layout-id="${safeLayoutId}" data-widget-element-id="${safeWidgetElementId}">
        `;

        if (Object.keys(params).length === 0) {
            html += `<div class="config-empty-state">
                ${iconSvg('settings')}
                <p>该部件暂无可配置参数</p>
            </div>`;
        } else {
            for (const key in params) {
                const param = params[key];
                const type = getParamUiType(param);
                const semanticType = param.type || type;
                const label = param.label || key;
                const defaultVal = param.default || '';
                const value = savedConfig[key] !== undefined ? savedConfig[key] : defaultVal;
                const required = param.required || false;
                const description = param.description || '';
                const placeholder = param.placeholder || '';
                const options = param.options || {};
                const translatable = isFieldTranslatable(param);
                const safeKey = escapeHtml(key);
                const safeFieldId = escapeHtml(`config_${key}`);
                const safeValue = escapeHtml(value);

                html += `<div class="config-field${translatable ? ' translatable-field' : ''}">`;
                html += `<label class="config-label" for="${safeFieldId}">`;
                html += escapeHtml(label);
                if (required) html += ' <span class="required-mark">*</span>';
                if (translatable) html += ' <span class="translatable-icon" title="支持多语言">' + iconSvg('language') + '</span>';
                html += `</label>`;
                html += `<div class="config-field-input">`;

                if (type === 'string') {
                    html += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}"
                             value="${safeValue}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (type === 'number') {
                    html += `<input type="number" class="w-input" id="${safeFieldId}" name="${safeKey}"
                             value="${safeValue}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                } else if (isBooleanParamType(semanticType)) {
                    html += renderBooleanSelect(`config_${key}`, key, key, value, required, param);
                } else if (type === 'select') {
                    html += `<select class="w-select" id="${safeFieldId}" name="${safeKey}" ${required ? 'required' : ''}>
                        <option value="">-- 请选择 --</option>`;
                    for (const optVal in options) {
                        html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
                    }
                    html += `</select>`;
                } else if (type === 'url') {
                    html += `<div class="input-with-icon">
                        ${iconSvg('link')}
                        <input type="url" class="w-input" id="${safeFieldId}" name="${safeKey}"
                               value="${safeValue}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>
                    </div>`;
                } else if (['image', 'image_picker', 'media_image', 'file_image'].includes(type)) {
                    html += renderTypedFileImageControl(`config_${key}`, key, value, param);
                } else if (type === 'color') {
                    html += `<div class="color-picker-wrapper">
                        <input type="color" class="w-theme-editor-color-input" id="${safeFieldId}_picker" value="${escapeHtml(value || '#000000')}">
                        <input type="text" class="w-input w-theme-editor-color-value" id="${safeFieldId}" name="${safeKey}" value="${safeValue}" placeholder="#000000">
                    </div>`;
                } else if (type === 'textarea') {
                    html += `<textarea class="w-textarea" id="${safeFieldId}" name="${safeKey}" rows="4"
                             placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${safeValue}</textarea>`;
                } else {
                    html += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}"
                             value="${safeValue}" ${required ? 'required' : ''}>`;
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
                        <button type="button" class="w-button" data-w-action="dialog.close">
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
            function scheduleAutoSave() {
                scheduleEditorAutoSave(
                    `widget-config-modal:${layoutId}`,
                    () => saveWidgetConfigFromModal(form, widgetElement, { autoSave: true }),
                );
            }
            form.addEventListener('input', scheduleAutoSave);
            form.addEventListener('change', scheduleAutoSave);
        }

        // 绑定颜色选择器同步
        modalBody.querySelectorAll('.w-theme-editor-color-input').forEach(colorPicker => {
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
    async function renderConfigFormWithBackend(widget, params) {
        const icon = widgetTypeIconName(widget.widget_type);
        const widgetName = widget.meta?.name || widget.widget_code;
        const widgetDesc = widget.meta?.description || '';
        const layoutId = widget.layout_id || '';
        const formHtml = await generateWidgetConfigForm(layoutId, params, widget.config || {});
        const searchPlaceholder = (typeof __ !== 'undefined' ? __('Search config') : 'Search config');

        elements.configContent.innerHTML = `
            <div class="widget-config-panel">
                <div class="config-header">
                    <div class="config-widget-info">
                        <div class="widget-icon">
                            ${iconSvg(icon)}
                        </div>
                        <div class="widget-meta">
                            <h4 class="widget-name">${escapeHtml(widgetName)}</h4>
                            <p class="widget-desc">${escapeHtml(widgetDesc)}</p>
                        </div>
                    </div>
                    <div class="config-lang-switcher">
                        <select class="w-select w-theme-editor-control-sm" id="configLangSwitcher" data-widget-layout-id="${layoutId}">
                            <option value="">&#40664;&#35748;&#65288;&#20840;&#35821;&#35328;&#65289;</option>
                        </select>
                    </div>
                </div>
                <div class="w-param-search-wrap">
                    <input type="text" class="w-param-search w-input w-theme-editor-control-sm" placeholder="${searchPlaceholder}" autocomplete="off">
                </div>
                ${formHtml}
            </div>
        `;

        const form = elements.configContent.querySelector('.w-param-form, .widget-accordion-config-form');
        if (form) {
            form.id = 'widgetConfigForm';
            form.setAttribute('data-layout-id', layoutId);
        }
        bindAccordionFormEvents(elements.configContent);
        bindParamSearch(elements.configContent);
        bindConfigLangSwitcher();
    }

    function bindConfigLangSwitcher() {
        const langSwitcher = document.getElementById('configLangSwitcher');
        if (!langSwitcher) return;
        fetchInstalledLocales().then(locales => {
            populateLocaleSelect(langSwitcher, locales);
        });
        if (langSwitcher.dataset.localeChangeBound !== '1') {
            langSwitcher.dataset.localeChangeBound = '1';
            langSwitcher.addEventListener('change', function() {
                handleConfigLocaleSwitcherChange(langSwitcher);
            });
            langSwitcher.addEventListener('input', function() {
                handleConfigLocaleSwitcherChange(langSwitcher);
            });
        }
    }

    function renderConfigForm(widget, params) {
        const icon = widgetTypeIconName(widget.widget_type);
        const widgetName = widget.meta?.name || widget.widget_code;
        const widgetDesc = widget.meta?.description || '';
        const savedConfig = widget.config || {};

        let html = `
            <div class="widget-config-panel">
                <div class="config-header">
                    <div class="config-widget-info">
                        <div class="widget-icon">
                            ${iconSvg(icon)}
                        </div>
                        <div class="widget-meta">
                            <h4 class="widget-name">${escapeHtml(widgetName)}</h4>
                            <p class="widget-desc">${escapeHtml(widgetDesc)}</p>
                        </div>
                    </div>
                    <div class="config-lang-switcher">
                        <select class="w-select w-theme-editor-control-sm" id="configLangSwitcher" data-widget-layout-id="${widget.layout_id}">
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
                '基础配置': 'settings',
                '样式': 'palette',
                '布局': 'grid',
                '数据': 'database',
                '高级': 'code',
            };

            for (const groupName in groups) {
                const groupFields = groups[groupName];
                const groupIcon = groupIcons[groupName] || 'settings';
                const isSingleGroup = Object.keys(groups).length === 1;

                // 单分组不显示分组标题，直接展示字段
                if (!isSingleGroup) {
                    html += `<div class="config-group">
                        <h5 class="config-group-title" data-config-group-toggle>
                            ${iconSvg(groupIcon)} ${escapeHtml(groupName)}
                            ${iconSvg('arrowDown')}
                        </h5>
                        <div class="config-fields">`;
                }

                for (const field of groupFields) {
                    const key = field.key;
                    const type = getParamUiType(field);
                    const semanticType = field.type || type;
                    const label = field.label || key;
                    const defaultVal = field.default || '';
                    const value = savedConfig[key] !== undefined ? savedConfig[key] : defaultVal;
                    const required = field.required || false;
                    const description = field.description || '';
                    const placeholder = field.placeholder || '';
                    const options = field.options || {};
                    const translatable = isFieldTranslatable(field);
                    const safeKey = escapeHtml(key);
                    const safeFieldId = escapeHtml(`config_${key}`);
                    const safeValue = escapeHtml(value);

                    html += `<div class="config-field${translatable ? ' translatable-field' : ''}">`;
                    html += `<label class="config-label" for="${safeFieldId}">`;
                    html += escapeHtml(label);
                    if (required) html += ' <span class="required-mark">*</span>';
                    if (translatable) html += ' <span class="translatable-icon" title="支持多语言">' + iconSvg('language') + '</span>';
                    html += `</label>`;
                    html += `<div class="config-field-input">`;

                    if (type === 'string') {
                        html += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}"
                                 value="${safeValue}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                    } else if (type === 'number') {
                        html += `<input type="number" class="w-input" id="${safeFieldId}" name="${safeKey}"
                                 value="${safeValue}" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>`;
                    } else if (isBooleanParamType(semanticType)) {
                        html += renderBooleanSelect(`config_${key}`, key, key, value, required, field);
                    } else if (type === 'select') {
                        html += `<select class="w-select" id="${safeFieldId}" name="${safeKey}" ${required ? 'required' : ''}>
                            <option value="">-- 请选择 --</option>`;
                        for (const optVal in options) {
                            html += `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(options[optVal])}</option>`;
                        }
                        html += `</select>`;
                    } else if (type === 'url') {
                        html += `<div class="input-with-icon">
                            ${iconSvg('link')}
                            <input type="url" class="w-input" id="${safeFieldId}" name="${safeKey}"
                                   value="${safeValue}" placeholder="${escapeHtml(placeholder || 'https://')}" ${required ? 'required' : ''}>
                        </div>`;
                    } else if (['image', 'image_picker', 'media_image', 'file_image'].includes(type)) {
                        html += renderTypedFileImageControl(`config_${key}`, key, value, field);
                    } else if (type === 'color') {
                        html += `<div class="color-picker-wrapper">
                            <input type="color" class="w-theme-editor-color-input" id="${safeFieldId}_picker" value="${escapeHtml(value || '#000000')}">
                            <input type="text" class="w-input w-theme-editor-color-value" id="${safeFieldId}" name="${safeKey}" value="${safeValue}" placeholder="#000000">
                        </div>`;
                    } else if (type === 'textarea') {
                        html += `<textarea class="w-textarea" id="${safeFieldId}" name="${safeKey}" rows="4"
                                 placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${safeValue}</textarea>`;
                    } else {
                        html += `<input type="text" class="w-input" id="${safeFieldId}" name="${safeKey}"
                                 value="${safeValue}" ${required ? 'required' : ''}>`;
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
                        <button type="button" class="w-button w-theme-editor-delete-config" data-tone="danger" data-variant="outline" data-layout-id="${widget.layout_id}" title="删除此部件">
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
                populateLocaleSelect(langSwitcher, locales);
            });
            if (langSwitcher.dataset.localeChangeBound !== '1') {
                langSwitcher.dataset.localeChangeBound = '1';
                langSwitcher.addEventListener('change', function() {
                    handleConfigLocaleSwitcherChange(langSwitcher);
                });
                langSwitcher.addEventListener('input', function() {
                    handleConfigLocaleSwitcherChange(langSwitcher);
                });
            }
        }
    }

    // ─── 统一多语言 i18n 逻辑 ───────────────────────────────────

    let _installedLocalesCache = null;

    /**
     * 获取已安装语言列表（缓存结果）
     * @returns {Promise<Array<{code:string,name:string,flag:string}>>}
     */
    async function fetchInstalledLocales() {
        if (Array.isArray(_installedLocalesCache) && _installedLocalesCache.length > 0) {
            return _installedLocalesCache;
        }
        try {
            const result = await apiJson(`${config.apiBase}/installed-locales`);
            if (result.success && Array.isArray(result.locales) && result.locales.length > 0) {
                _installedLocalesCache = result.locales;
                return _installedLocalesCache;
            }
        } catch (err) {
            console.error('fetchInstalledLocales error:', err);
        }
        return [{ code: 'zh_Hans_CN', name: '简体中文', flag: '' }, { code: 'en_US', name: 'English', flag: '' }];
    }

    function parseInstalledLocales(value) {
        if (!value) {
            return null;
        }
        if (Array.isArray(value)) {
            return value.length > 0 ? value : null;
        }
        try {
            const parsed = JSON.parse(String(value));
            return Array.isArray(parsed) && parsed.length > 0 ? parsed : null;
        } catch (error) {
            console.warn('[ThemeEditor] Invalid installed locales payload:', error);
            return null;
        }
    }

    function getActiveConfigLocale() {
        return String(state.configLocale || '').trim();
    }

    function syncThemeEditorLocaleDataset() {
        const container = document.getElementById('themeEditor');
        if (!container) {
            return;
        }
        const locale = getActiveConfigLocale();
        container.dataset.configLocale = locale;
        container.dataset.localeCode = locale;
    }

    /** Scoped layout / preview identity locale (matches buildTypedEditorContext). */
    function getScopedEditorLocale(overrides = {}) {
        const locale = Object.prototype.hasOwnProperty.call(overrides, 'locale')
            ? String(overrides.locale || '').trim()
            : getActiveConfigLocale();
        return locale || 'default';
    }

    /** Legacy theme_layout.locale_code: empty string means default/all locales. */
    function getLegacyLocaleCode(overrides = {}) {
        const locale = getScopedEditorLocale(overrides);
        return locale === 'default' ? '' : locale;
    }

    function getPreviewLocaleForRequest(overrides = {}) {
        return getScopedEditorLocale(overrides);
    }

    function getWidgetConfigSaveUrl() {
        return `${config.apiBase}/save-widget-config`;
    }

    function formatLocaleOptionLabel(locale) {
        const code = String(locale?.code || '').trim();
        const name = String(locale?.name || code).replace(/\s+/g, ' ').trim();
        if (!code) {
            return name || '未知语言';
        }
        if (!name || name === code) {
            return code;
        }
        return `${code} · ${name}`;
    }

    function populateLocaleSelect(select, locales) {
        if (!select) {
            return;
        }
        select.innerHTML = '';
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = '默认（全语言）';
        select.appendChild(defaultOption);

        (Array.isArray(locales) ? locales : []).forEach((locale) => {
            const code = String(locale?.code || '').trim();
            if (!code) {
                return;
            }
            const option = document.createElement('option');
            option.value = code;
            option.textContent = formatLocaleOptionLabel(locale);
            option.title = String(locale?.name || code);
            select.appendChild(option);
        });

        ensureLocaleOption(select, getActiveConfigLocale());
        select.value = getActiveConfigLocale();
        if (select.value !== getActiveConfigLocale()) {
            select.value = '';
        }
        const selected = select.options[select.selectedIndex];
        select.title = selected ? selected.textContent : '默认（全语言）';
    }

    function ensureLocaleOption(select, locale) {
        const normalizedLocale = String(locale || '').trim();
        if (!select || !normalizedLocale) {
            return;
        }
        const hasOption = Array.from(select.options || []).some((option) => option.value === normalizedLocale);
        if (hasOption) {
            return;
        }

        const sourceOption = Array.from(document.querySelectorAll('#editorLangSwitcher option, #configLangSwitcher option'))
            .find((option) => option.value === normalizedLocale);
        const option = document.createElement('option');
        option.value = normalizedLocale;
        option.textContent = sourceOption ? sourceOption.textContent : normalizedLocale;
        option.title = sourceOption ? (sourceOption.title || sourceOption.textContent || normalizedLocale) : normalizedLocale;
        select.appendChild(option);
    }

    function syncConfigLocaleSwitchers() {
        const locale = getActiveConfigLocale();
        [elements.editorLangSwitcher, document.getElementById('configLangSwitcher')]
            .filter(Boolean)
            .forEach((select) => {
                ensureLocaleOption(select, locale);
                select.value = locale;
                if (select.value !== locale) {
                    select.value = '';
                }
                const selected = select.options[select.selectedIndex];
                select.title = selected ? selected.textContent : '默认（全语言）';
            });
    }

    async function handleConfigLocaleSwitcherChange(langSwitcher) {
        if (!langSwitcher) {
            return;
        }
        const locale = langSwitcher.value || '';
        const layoutId = langSwitcher.dataset.widgetLayoutId || '';
        const switchKey = `${layoutId}|${locale}`;
        if (state.configLocaleChangeInFlight === switchKey) {
            return;
        }

        state.configLocaleChangeInFlight = switchKey;
        try {
            await setActiveConfigLocale(locale, { layoutId });
        } catch (error) {
            console.error('[ThemeEditor] config locale switch failed:', error);
            syncConfigLocaleSwitchers();
            showToast(error?.message || translateUiText('语言切换失败，已恢复原语言'), 'error');
        } finally {
            if (state.configLocaleChangeInFlight === switchKey) {
                state.configLocaleChangeInFlight = '';
            }
        }
    }

    function initEditorLanguageSwitcher() {
        if (!elements.editorLangSwitcher) {
            return;
        }
        fetchInstalledLocales().then((locales) => {
            populateLocaleSelect(elements.editorLangSwitcher, locales);
        });
    }

    async function setActiveConfigLocale(locale, options = {}) {
        const previousLocale = getActiveConfigLocale();
        const nextLocale = String(locale || '').trim();
        const sameLocale = nextLocale === previousLocale;
        if (sameLocale && !options.forceReload) {
            syncConfigLocaleSwitchers();
            return;
        }

        if (sameLocale && options.forceReload) {
            syncThemeEditorLocaleDataset();
            syncConfigLocaleSwitchers();
            if (options.reload === false) {
                return;
            }
            loadLayoutPreview({ locale: nextLocale || getActiveConfigLocale() });
            if (options.toast !== false) {
                showToast(
                    nextLocale ? `${translateUiText('已切换到')} ${nextLocale}` : translateUiText('已切换到默认语言'),
                    'success',
                );
            }
            return;
        }

        try {
            await flushPendingEditorMutations();
        } catch (error) {
            state.configLocale = previousLocale;
            syncConfigLocaleSwitchers();
            const saveError = new Error(translateUiText('当前修改保存失败，已停留在原语言'));
            saveError.cause = error;
            throw saveError;
        }

        state.configLocale = nextLocale;
        syncThemeEditorLocaleDataset();
        syncConfigLocaleSwitchers();

        if (options.reload === false) {
            return;
        }

        try {
            const widgetLayoutId = options.layoutId
                || document.getElementById('widgetConfigForm')?.dataset?.layoutId
                || state.selectedWidget?.dataset?.layoutId
                || '';
            if (widgetLayoutId && (state.configMode === 'widget' || document.getElementById('widgetConfigForm'))) {
                await reloadWidgetConfigWithLocale(widgetLayoutId, getActiveConfigLocale(), {
                    sync: false,
                    toast: false,
                    refreshPreview: false,
                });
                if (options.toast !== false) {
                    showToast(
                        nextLocale ? `${translateUiText('已切换到')} ${nextLocale}` : translateUiText('已切换到默认语言'),
                        'success',
                    );
                }
                if (options.refreshPreview !== false) {
                    loadLayoutPreview({ locale: getActiveConfigLocale() });
                    return;
                }
                return;
            }

            await loadLayoutConfig({
                locale: getActiveConfigLocale(),
                silent: options.silent === true,
                throwOnError: true,
            });
            if (options.refreshPreview !== false) {
                loadLayoutPreview({ locale: getActiveConfigLocale() });
            }
        } catch (error) {
            state.configLocale = previousLocale;
            syncConfigLocaleSwitchers();
            if (state.configMode === 'layout') {
                await loadLayoutConfig({ locale: previousLocale, silent: true });
            }
            const switchError = new Error(translateUiText('语言切换失败，已恢复原语言'));
            switchError.cause = error;
            throw switchError;
        }
    }

    function decodeHtmlEntitiesOnce(value) {
        if (!value || !/[&][a-z#0-9]+;/i.test(value)) {
            return String(value || '');
        }
        const textarea = document.createElement('textarea');
        textarea.innerHTML = String(value);
        return textarea.value;
    }

    function stripXmlDeclaration(value) {
        return String(value || '').trim().replace(/<\?xml[^?]*\?>/i, '').trim();
    }

    function normalizeLocaleFlagSvg(flagValue) {
        const rawFlag = stripXmlDeclaration(decodeHtmlEntitiesOnce(flagValue));
        if (!/^<svg\b[\s\S]*<\/svg>\s*$/i.test(rawFlag)) {
            return '';
        }
        const safeFlagHtml = sanitizeHtmlForEditorPreview(rawFlag).trim();
        return /^<svg\b[\s\S]*<\/svg>\s*$/i.test(safeFlagHtml) ? safeFlagHtml : '';
    }

    function normalizeLocaleFlagText(flagValue) {
        const rawFlag = stripXmlDeclaration(decodeHtmlEntitiesOnce(flagValue));
        if (!rawFlag || /^<|&lt;/i.test(rawFlag)) {
            return '';
        }
        // Keep only tiny glyph-style flags, never long markup or source text.
        return rawFlag.length <= 8 ? rawFlag : '';
    }

    function localeBadgeFromCode(code) {
        const parts = String(code || '').replace(/-/g, '_').split('_').filter(Boolean);
        const token = parts.length > 1 ? parts[parts.length - 1] : (parts[0] || '??');
        return token.slice(0, 2).toUpperCase();
    }

    function appendLocaleLabelBadge(label, loc) {
        const flagSvg = normalizeLocaleFlagSvg(loc.flag || '');
        const flagText = flagSvg ? '' : normalizeLocaleFlagText(loc.flag || '');
        const badge = document.createElement('span');
        badge.className = flagSvg ? 'lang-flag-svg' : 'lang-flag-badge';
        badge.setAttribute('aria-hidden', 'true');
        if (flagSvg) {
            badge.innerHTML = flagSvg;
        } else {
            badge.textContent = flagText || localeBadgeFromCode(loc.code || '');
        }
        label.appendChild(badge);
    }

    /**
     * 确保面板内已渲染语言行（动态填充空容器）
     */
    async function ensurePanelRendered(panel) {
        const body = panel.querySelector('.w-param-i18n-body, .i18n-panel-body');
        if (!body || body.children.length > 0) return;

        const locales = await fetchInstalledLocales();
        const fieldKey = panel.dataset.field || '';
        const p = 'w-param-';
        const fragment = document.createDocumentFragment();
        for (const loc of locales) {
            const row = document.createElement('div');
            row.className = `${p}i18n-row`;

            const label = document.createElement('label');
            label.className = `${p}i18n-label`;
            label.title = [loc.name || '', loc.code || ''].filter(Boolean).join(' ');
            appendLocaleLabelBadge(label, loc);
            const code = document.createElement('span');
            code.className = 'lang-code';
            code.textContent = String(loc.code || '');
            label.appendChild(code);

            const input = document.createElement('input');
            input.type = 'text';
            input.className = `${p}input i18n-input`;
            input.dataset.locale = String(loc.code || '');
            input.dataset.field = fieldKey;
            input.placeholder = String(loc.name || '');

            row.appendChild(label);
            row.appendChild(input);
            fragment.appendChild(row);
        }
        body.replaceChildren(fragment);
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

    function isLayoutConfigPanel(panel) {
        return !!panel.closest('.layout-config-panel, .layout-config-form');
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
                let result;
                if (isLayoutConfigPanel(panel)) {
                    result = await apiJson(buildLayoutConfigUrl(locale));
                } else {
                    result = await apiJson(`${config.apiBase}/widget-config?layout_id=${layoutId}&locale=${locale}`);
                }
                const data = result.data || {};
                if (result.success && data.config) {
                    const value = getConfigValueByPath(data.config, fieldKey);
                    const input = panel.querySelector(`.i18n-input[data-locale="${locale}"]`);
                    if (input) input.value = value;
                }
            } catch (err) {
                console.error(`Load i18n ${locale} error:`, err);
            }
        }
    }

    function translateUiText(text) {
        return (typeof __ === 'function') ? __(text) : text;
    }

    function readI18nMainFieldValue(fieldKey, panel) {
        const form = panel.closest('form') || panel.closest('.layout-config-panel, .slot-widget-body, .config-field') || elements.configContent;
        if (!form) {
            return '';
        }

        const controls = Array.from(form.querySelectorAll('[name]')).filter(control => control.getAttribute('name') === fieldKey);
        for (const control of controls) {
            const type = (control.getAttribute('type') || '').toLowerCase();
            if (type === 'radio') {
                if (control.checked) {
                    return String(control.value || '');
                }
                continue;
            }
            if (type === 'checkbox') {
                if (control.checked) {
                    return String(control.value || '1');
                }
                continue;
            }
            if (typeof control.value !== 'undefined' && String(control.value).trim() !== '') {
                return String(control.value);
            }
        }

        return '';
    }

    function resolveI18nSourceValue(fieldKey, panel, inputs) {
        const preferred = inputs.find(input => input.dataset.locale === 'zh_Hans_CN' && String(input.value || '').trim() !== '');
        if (preferred) {
            return {
                sourceLocale: preferred.dataset.locale,
                sourceText: String(preferred.value || ''),
            };
        }

        const mainValue = readI18nMainFieldValue(fieldKey, panel);
        if (String(mainValue).trim() !== '') {
            return {
                sourceLocale: 'zh_Hans_CN',
                sourceText: String(mainValue),
            };
        }

        const firstFilled = inputs.find(input => String(input.value || '').trim() !== '');
        if (firstFilled) {
            return {
                sourceLocale: firstFilled.dataset.locale || 'zh_Hans_CN',
                sourceText: String(firstFilled.value || ''),
            };
        }

        return {
            sourceLocale: 'zh_Hans_CN',
            sourceText: '',
        };
    }

    function setI18nAiButtonLoading(button, loading) {
        if (!button) {
            return;
        }
        if (loading) {
            button.dataset.originalText = button.textContent || translateUiText('AI翻译');
            button.disabled = true;
            button.textContent = translateUiText('正在翻译...');
            button.setAttribute('aria-busy', 'true');
            return;
        }

        button.disabled = false;
        button.textContent = button.dataset.originalText || translateUiText('AI翻译');
        button.removeAttribute('aria-busy');
        delete button.dataset.originalText;
    }

    async function translateI18nValues(layoutId, fieldKey, panel, button) {
        await ensurePanelRendered(panel);
        const inputs = Array.from(panel.querySelectorAll('.i18n-input'));
        const { sourceLocale, sourceText } = resolveI18nSourceValue(fieldKey, panel, inputs);
        if (String(sourceText).trim() === '') {
            showToast(translateUiText('请先填写源文案'), 'warning');
            return;
        }

        const targetInputs = inputs.filter(input => input.dataset.locale && input.dataset.locale !== sourceLocale);
        const targetLocales = [...new Set(targetInputs.map(input => input.dataset.locale).filter(Boolean))];
        if (targetLocales.length === 0) {
            showToast(translateUiText('没有需要翻译的目标语言'), 'warning');
            return;
        }

        setI18nAiButtonLoading(button, true);
        try {
            const result = await apiJson(config.apiAiTranslateConfig, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    source_text: sourceText,
                    source_locale: sourceLocale,
                    target_locales: targetLocales,
                    field_key: fieldKey,
                    layout_id: layoutId,
                    layout_type: state.layoutType || getCurrentPageType() || 'homepage',
                    layout_option: state.layoutOption || 'default',
                    context: isLayoutConfigPanel(panel) ? 'layout_config' : 'widget_config'
                })
            });
            if (!result.success) {
                throw new Error(result.message || translateUiText('AI翻译失败'));
            }

            const translations = (result.data && result.data.translations) || result.translations || {};
            let filledCount = 0;
            for (const input of targetInputs) {
                const locale = input.dataset.locale;
                if (!Object.prototype.hasOwnProperty.call(translations, locale)) {
                    continue;
                }
                input.value = String(translations[locale] ?? '');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                filledCount++;
            }

            if (filledCount > 0) {
                showToast(result.message || translateUiText('AI翻译已回填'), 'success');
            } else {
                showToast(translateUiText('AI翻译未返回目标语言结果'), 'warning');
            }
        } catch (err) {
            console.error('AI i18n translate error:', err);
            showToast(err.message || translateUiText('AI翻译失败'), 'error');
        } finally {
            setI18nAiButtonLoading(button, false);
        }
    }

    /**
     * 保存面板内所有语言的值
     */
    async function saveI18nValues(layoutId, fieldKey, panel) {
        const inputs = panel.querySelectorAll('.i18n-input');
        let successCount = 0;
        const activeLocale = getActiveConfigLocale();
        let activePreviewHtml = '';

        for (const input of inputs) {
            const locale = input.dataset.locale;
            const value = input.value;
            try {
                let result;
                if (isLayoutConfigPanel(panel)) {
                    result = await apiJson(config.apiSaveLayoutConfig, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            theme_id: state.themeId || 0,
                            layout_type: state.layoutType || getCurrentPageType() || 'homepage',
                            layout_option: state.layoutOption || 'default',
                            editor_area: state.editorArea || 'frontend',
                            scope: getCurrentWindowParam('scope') || 'default',
                            config: { [fieldKey]: value },
                            locale: locale,
                            ...getLayoutLockVirtualPayload()
                        })
                    });
                } else {
                    result = await apiJson(getWidgetConfigSaveUrl(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            layout_id: layoutId,
                            config: { [fieldKey]: value },
                            locale: locale
                        })
                    });
                }
                if (result.success) {
                    if (isLayoutConfigPanel(panel)) {
                        await queueLayoutConfigOwnership({ [fieldKey]: value }, locale || '');
                    } else {
                        await queueWidgetConfigOwnership(result.node_uid, { [fieldKey]: value }, locale || '');
                    }
                    successCount++;
                    if (!isLayoutConfigPanel(panel) && locale === activeLocale && result.preview_html) {
                        activePreviewHtml = result.preview_html;
                    }
                }
            } catch (err) {
                console.error(`Save i18n ${locale} error:`, err);
            }
        }

        if (successCount > 0) {
            showToast(`已保存 ${successCount} 种语言的翻译`, 'success');
            if (activePreviewHtml) {
                updateWidgetPreviewInIframe(layoutId, activePreviewHtml);
            }
        } else {
            showToast('保存失败', 'error');
        }
    }

    function setConfigControlValue(control, value) {
        if (!control) {
            return;
        }
        if (control.type === 'checkbox') {
            if (Array.isArray(value)) {
                control.checked = value.map(String).includes(String(control.value));
            } else if (control.value && !['on', '1', 'true'].includes(String(control.value).toLowerCase())) {
                control.checked = String(value) === String(control.value);
            } else {
                control.checked = value === true || value === 1 || value === '1' || value === 'true' || value === 'on';
            }
            return;
        }
        if (control.type === 'radio') {
            control.checked = String(value ?? '') === String(control.value);
            return;
        }
        if (control.tagName === 'SELECT' && control.multiple) {
            const selectedValues = Array.isArray(value) ? value.map(String) : [String(value ?? '')];
            Array.from(control.options).forEach((option) => {
                option.selected = selectedValues.includes(String(option.value));
            });
            return;
        }
        if (control.type === 'hidden' && (Array.isArray(value) || (value && typeof value === 'object'))) {
            control.value = JSON.stringify(value);
            return;
        }
        control.value = value === null || value === undefined ? '' : String(value);
    }

    function updateWidgetConfigFormValues(form, params, widgetConfig) {
        if (!form) {
            return;
        }
        const keys = new Set([
            ...Object.keys(params || {}),
            ...Object.keys(widgetConfig || {}),
        ]);
        keys.forEach((key) => {
            const param = params?.[key] || {};
            const value = Object.prototype.hasOwnProperty.call(widgetConfig || {}, key)
                ? widgetConfig[key]
                : (Object.prototype.hasOwnProperty.call(param, 'default') ? param.default : '');
            const controls = form.querySelectorAll([
                dataAttributeSelector('name', key),
                dataAttributeSelector('name', `${key}[]`),
            ].join(','));
            controls.forEach((control) => setConfigControlValue(control, value));
        });
    }

    /**
     * 重新加载部件配置（支持多语言）
     * @param {string} layoutId 布局ID
     * @param {string|null} locale 语言代码，null表示默认语言
     */
    async function reloadWidgetConfigWithLocale(layoutId, locale, options = {}) {
        if (!layoutId) return;
        const normalizedLocale = String(locale || '').trim();
        if (options.sync !== false) {
            state.configLocale = normalizedLocale;
            syncThemeEditorLocaleDataset();
            syncConfigLocaleSwitchers();
        }

        try {
            const result = await apiJson(buildSavedWidgetConfigUrl(layoutId, normalizedLocale));

            if (result.success && result.data) {
                const widgetData = result.data;
                const params = widgetData.params || {};
                const widgetConfig = widgetData.config || {};

                // 更新表单中的值
                const form = document.getElementById('widgetConfigForm');
                if (form) {
                    updateWidgetConfigFormValues(form, params, widgetConfig);
                }

                const previewHtml = widgetData.preview_html || result.preview_html || '';
                if (previewHtml) {
                    updateWidgetPreviewInIframe(layoutId, previewHtml);
                } else if (options.refreshPreview !== false) {
                    loadLayoutPreview();
                }

                if (options.toast !== false) {
                    showToast(normalizedLocale ? `已切换到 ${normalizedLocale} 语言` : '已切换到默认语言', 'success');
                }
                return true;
            } else {
                throw new Error(result.message || translateUiText('加载配置失败'));
            }
        } catch (err) {
            console.error('Reload config error:', err);
            if (options.toast !== false) {
                showToast(err?.message || translateUiText('加载配置失败'), 'error');
            }
            throw err;
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
            const result = await apiJson(getWidgetConfigSaveUrl(), {
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

            if (result.success) {
                const normalizedConfig = (result && result.config && typeof result.config === 'object') ? result.config : configData;
                await queueWidgetConfigOwnership(result.node_uid, normalizedConfig, locale || '');
                showToast(result.message || '配置已保存', 'success');
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                    fetchLayoutSlots();
                }
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
     * 收集表单配置，保留数组字段与多选字段的结构。
     */
    function collectWidgetConfigData(form) {
        const formData = new FormData(form);
        const configData = {};

        formData.forEach((value, rawKey) => {
            let key = rawKey;
            if (key.endsWith('[]')) {
                key = key.slice(0, -2);
            }
            if (Object.prototype.hasOwnProperty.call(configData, key)) {
                if (!Array.isArray(configData[key])) {
                    configData[key] = [configData[key]];
                }
                configData[key].push(value);
            } else {
                configData[key] = value;
            }
        });

        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!checkbox.name) {
                return;
            }
            let key = checkbox.name;
            if (key.endsWith('[]')) {
                key = key.slice(0, -2);
            }
            if (!key || checkbox.closest('.w-param-array-item')) {
                return;
            }
            if (!formData.has(checkbox.name)) {
                configData[key] = false;
            } else if (!Array.isArray(configData[key])) {
                configData[key] = true;
            }
        });

        form.querySelectorAll('.array-editor-wrapper input[type="hidden"][name], .w-param-array input[type="hidden"][name]').forEach(input => {
            let key = input.name;
            if (!key) {
                return;
            }
            if (key.endsWith('[]')) {
                key = key.slice(0, -2);
            }

            const rawValue = String(input.value || '').trim();
            if (rawValue === '') {
                configData[key] = [];
                return;
            }

            try {
                const parsedValue = JSON.parse(rawValue);
                if (Array.isArray(parsedValue)) {
                    configData[key] = parsedValue;
                }
            } catch (e) {
                // Keep the submitted value if it is not valid JSON.
            }
        });

        return configData;
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

        const configData = collectWidgetConfigData(form);

        try {
            const locale = getActiveConfigLocale();
            const result = await apiJson(getWidgetConfigSaveUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: configData,
                    locale: locale || null,
                }),
            });

            if (result.success) {
                // 更新部件的 data-config
                const normalizedConfig = (result && result.config && typeof result.config === 'object') ? result.config : configData;
                await queueWidgetConfigOwnership(result.node_uid, normalizedConfig, locale || '');
                if (!autoSave) showToast('配置已保存', 'success');
                if (!locale && widgetElement) {
                    widgetElement.dataset.config = JSON.stringify(normalizedConfig);
                }
                if (!autoSave) {
                    hideEditorModal(document.getElementById('widgetConfigModal'));
                }
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                    fetchLayoutSlots();
                }
            } else {
                throw new Error(result.message || translateUiText('保存失败'));
            }
        } catch (err) {
            console.error('Save config error:', err);
            showToast(err?.message || translateUiText('保存配置失败'), 'error');
            throw err;
        }
    }

    /**
     * 保存部件配置（左侧面板）
     * @param {HTMLFormElement} form
     * @param {boolean} silent - 为 true 时不显示“配置已保存”提示（用于实时保存）
     */
    async function saveWidgetConfig(form, silent) {
        let layoutId = form.dataset.layoutId;
        const configData = collectWidgetConfigData(form);
        const locale = getActiveConfigLocale();

        if (!layoutId && state.selectedWidget) {
            layoutId = await materializeTemplateWidgetIfNeeded(state.selectedWidget, configData);
            if (layoutId) {
                form.dataset.layoutId = layoutId;
            }
        }
        if (!layoutId && form.dataset.templateRef && state.selectedWidget) {
            layoutId = await materializeTemplateWidgetIfNeeded(state.selectedWidget, configData);
            if (layoutId) {
                form.dataset.layoutId = layoutId;
                delete form.dataset.templateRef;
            }
        }
        if (!layoutId) return;

        try {
            const result = await apiJson(getWidgetConfigSaveUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    layout_id: layoutId,
                    config: Object.assign({}, configData, state.selectedWidget?.dataset?.templateRef
                        ? { template_ref: state.selectedWidget.dataset.templateRef }
                        : {}),
                    locale: locale || null,
                }),
            });

            if (result.success) {
                const normalizedConfig = (result && result.config && typeof result.config === 'object') ? result.config : configData;
                await queueWidgetConfigOwnership(result.node_uid, normalizedConfig, locale || '');
                if (!silent) showToast('配置已保存', 'success');
                if (!locale && state.selectedWidget) {
                    state.selectedWidget.dataset.config = JSON.stringify(normalizedConfig);
                }
                if (result.preview_html) {
                    updateWidgetPreviewInIframe(layoutId, result.preview_html);
                    fetchLayoutSlots();
                } else if (locale) {
                    // TE-CAP-017: never full-reload the preview iframe on config save.
                    console.warn('[ThemeEditor] save-widget-config missing preview_html for locale; skip full preview reload', locale);
                }
            } else {
                throw new Error(result.message || translateUiText('保存失败'));
            }
        } catch (err) {
            console.error('Save config error:', err);
            showToast(err?.message || translateUiText('保存配置失败'), 'error');
            throw err;
        }
    }

    async function refreshSlotSelectionAfterWidgetDelete(slotInfo, fallbackSlotId, fallbackArea) {
        await fetchLayoutSlots();

        const slotId = String((slotInfo && slotInfo.id) || fallbackSlotId || '').trim();
        if (!slotId || isSyntheticContainerSlotId(slotId)) {
            return;
        }

        const catalogSlotCandidate = state.slots ? state.slots[slotId] : null;
        const catalogSlot = catalogSlotCandidate && typeof catalogSlotCandidate === 'object' ? catalogSlotCandidate : {};
        const normalizedSlot = normalizeSelectedSlot({
            ...catalogSlot,
            ...(slotInfo || {}),
            id: slotId,
            area: firstNonEmptyValue(
                slotInfo && slotInfo.area,
                catalogSlot.area,
                fallbackArea,
                inferAreaFromSlotId(slotId)
            ),
            source: (slotInfo && slotInfo.source) || catalogSlot.source || 'delete_restore'
        });

        if (normalizedSlot) {
            handleSlotSelected(normalizedSlot);
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
        let restoredSlotId = slotIdFromEl;
        let restoredSlotInfo = null;
        let areaFromEl = 'content';
        try {
            const iframe = elements.previewFrame;
            if (iframe && iframe.contentDocument) {
                const iframeWidget = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
                if (iframeWidget) {
                    if (iframeWidget.closest('header, [data-wslot-position="header"], .site-header')) {
                        areaFromEl = 'header';
                    } else if (iframeWidget.closest('footer, [data-wslot-position="footer"], .site-footer')) {
                        areaFromEl = 'footer';
                    }
                }
            }
        } catch (e) { /* iframe access error */ }

        const widgetContext = resolveWidgetContextFromIframe(layoutId);

        try {
            const result = await apiJson(config.apiDeleteWidget, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildWidgetDeletePayload(layoutId, slotIdFromEl, areaFromEl, widgetContext)),
            });

            if (result.success) {
                await queueRemovedLayoutNode(result, {
                    layoutId,
                    nodeUid: widgetContext.nodeUid,
                });
                // 从 iframe 中移除部件并恢复原始内容
                const iframe = elements.previewFrame;
                if (iframe && iframe.contentDocument) {
                    const widgetEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
                    if (widgetEl) {
                        const slot = widgetEl.closest('[data-wslot], [data-slot], [data-slot-id]');
                        const actualSlotId = slot ? firstNonEmptyValue(
                            slot.getAttribute('data-wslot'),
                            slot.getAttribute('data-slot'),
                            slot.getAttribute('data-slot-id'),
                            slotIdFromEl,
                            result.slot_id
                        ) : firstNonEmptyValue(slotIdFromEl, result.slot_id);
                        if (slot && actualSlotId) {
                            restoredSlotId = actualSlotId;
                            restoredSlotInfo = buildSlotInfoFromElement(actualSlotId, slot);
                        }

                        // 移除部件元素
                        widgetEl.remove();

                        // 恢复原始内容（不调用 initWidgetHoverActions 避免重复渲染操作按钮）
                        if (slot && !slot.querySelector('[data-layout-id]')) {
                            if (result.has_original && result.original_html) {
                                // 剥离可能混入的 widget-wrapper
                                slot.innerHTML = stripWidgetWrappersFromHtml(result.original_html);
                            } else {
                                const slotName = slot.getAttribute('data-wslot-name') || slot.getAttribute('data-name') || restoredSlotId || result.slot_id || '';
                                slot.innerHTML = `
                                    <div class="slot-placeholder w-theme-editor-slot-placeholder">
                                        ${iconSvg('inbox')}
                                        <p class="w-theme-editor-slot-placeholder__title">拖入部件到此插槽</p>
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
                await refreshSlotSelectionAfterWidgetDelete(restoredSlotInfo, restoredSlotId || result.slot_id || slotIdFromEl, areaFromEl);
                refreshDefaultInjectionApplications({ render: state.widgetLibraryTab === 'applications', silent: true });
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
            const widgetEl = iframe.contentDocument.querySelector(dataLayoutIdSelector(layoutId));
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
                'Save new version',
                'Enter a version name (optional).',
                '',
                'Save',
                'Cancel'
            );

            // 如果用户取消了对话框
            if (versionName === null) {
                return;
            }

            await flushPendingEditorMutations();
            showToast('Saving version...', 'info');

            const result = await apiJson(config.apiSaveVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload({
                    version_name: versionName || undefined,
                })),
            });

            if (result.success) {
                showToast(result.message || 'Version saved', 'success');
                // 刷新版本列表
                try {
                    await loadVersions();
                } finally {
                    notifyDashboardLayoutSaved('version-saved', {
                        versionId: result.data?.version_id || null,
                    });
                }
            } else {
                showToast(result.message || 'Save failed', 'error');
            }
        } catch (error) {
            console.error('[ThemeEditor] Save version error:', error);
            showToast('Save version failed: ' + error.message, 'error');
        }
    }

    function buildFormBody(payload) {
        const body = new URLSearchParams();
        Object.entries(payload || {}).forEach(([key, value]) => {
            if (value === undefined || value === null) {
                return;
            }
            body.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
        });
        return body.toString();
    }

    async function publishEmbeddedLayout(options = {}) {
        if (!state.themeId) {
            const message = translateUiText('请先选择主题');
            showToast(message, 'warning');
            throw new Error(message);
        }

        try {
            if (options.silent !== true) {
                showToast(translateUiText('正在保存布局...'), 'info');
            }

            await publishLoadedScopedWorkspaces(options.reason || 'embedded_layout_publish');

            const result = await apiJson(config.apiPublish, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: buildFormBody(buildLayoutVersionIdentityPayload({
                    editor_area: state.editorArea || 'frontend',
                    scoped_release_published: true,
                })),
            });

            if (!result || result.success === false) {
                throw new Error((result && result.message) || translateUiText('保存失败'));
            }

            if (options.silent !== true) {
                showToast(result.message || translateUiText('布局已保存'), 'success');
            }

            notifyDashboardLayoutSaved(options.reason || 'embedded-layout-published', {
                pageType: getEffectivePageType(state.pageType || 'homepage'),
                layoutOption: getEffectiveLayoutOption(state.layoutOption || 'default'),
            });

            return result;
        } catch (error) {
            console.error('[ThemeEditor] Publish embedded layout error:', error);
            if (options.silent !== true) {
                showToast(error.message || translateUiText('保存失败'), 'error');
            }
            throw error;
        }
    }

    const dashboardSaveCloseRequests = new Set();

    function postDashboardSaveCloseResult(requestId, payload) {
        if (!window.parent || window.parent === window) {
            return;
        }
        try {
            window.parent.postMessage({
                type: 'weline-theme-editor:save-close-result',
                requestId,
                ...payload,
            }, '*');
        } catch (error) {
        }
    }

    function handleDashboardSaveCloseMessage(event) {
        if (!event.data || typeof event.data !== 'object') {
            return;
        }
        if (event.data.type !== 'weline-dashboard-editor:save-close') {
            return;
        }

        const requestId = String(event.data.requestId || '');
        if (requestId && dashboardSaveCloseRequests.has(requestId)) {
            return;
        }
        if (requestId) {
            dashboardSaveCloseRequests.add(requestId);
        }
        publishEmbeddedLayout({
            reason: 'dashboard-save-close',
            silent: false,
        }).then((result) => {
            postDashboardSaveCloseResult(requestId, {
                success: true,
                message: result && result.message ? result.message : translateUiText('布局已保存'),
                data: result && result.data ? result.data : null,
            });
        }).catch((error) => {
            postDashboardSaveCloseResult(requestId, {
                success: false,
                message: error && error.message ? error.message : translateUiText('保存失败'),
            });
        }).finally(() => {
            if (requestId) {
                window.setTimeout(() => {
                    dashboardSaveCloseRequests.delete(requestId);
                }, 120000);
            }
        });
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
            'Confirm publish theme?',
            'Publish the current version and refresh generated cache files?',
            'Publish',
            'Cancel'
        );
        if (!confirmed) {
            return;
        }

        try {
            showToast('Publishing...', 'info');

            await publishLoadedScopedWorkspaces('theme_editor_publish');

            const result = await apiJson(config.apiPublishVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload({
                    frontend_theme_id: getCurrentWindowParam('frontend_theme_id') || state.themeId,
                    backend_theme_id: getCurrentWindowParam('backend_theme_id') || '',
                    editor_area: state.editorArea || 'frontend',
                    status: state.previewStatus || 'draft',
                    scoped_release_published: true,
                })),
            });

            if (result.success) {
                showToast(result.message || '发布成功', 'success');
                // 刷新版本列表以更新发布状态
                try {
                    await loadVersions();
                } finally {
                    notifyDashboardLayoutSaved('version-published', {
                        versionId: result.data?.version_id || null,
                    });
                }
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
    async function openPreview() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        const previewWindow = window.open('about:blank', '_blank');
        try {
            await flushPendingEditorMutations();
            const previewUrl = await buildAuthorizedLayoutPreviewUrl();
            if (previewWindow) {
                previewWindow.location.href = previewUrl;
            } else {
                window.open(previewUrl, '_blank');
            }
        } catch (error) {
            previewWindow?.close();
            showToast(error?.message || translateUiText('当前修改保存失败，无法打开预览'), 'error');
        }
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
            await flushPendingEditorMutations();
            showToast('正在启动预览...', 'info');

            const result = await apiJson(config.apiStartPreview, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload({
                    frontend_theme_id: getCurrentWindowParam('frontend_theme_id') || state.themeId,
                    backend_theme_id: getCurrentWindowParam('backend_theme_id') || '',
                    editor_area: 'frontend',
                    status: state.previewStatus || 'draft',
                    locale: getPreviewLocaleForRequest(),
                })),
            });

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
    async function openPublishedPreview() {
        if (!state.themeId) {
            showToast('请先选择主题', 'warning');
            return;
        }

        const previewWindow = window.open('about:blank', '_blank');
        try {
            // 使用 status=published 明确指定查看已发布版本
            const previewUrl = await buildAuthorizedLayoutPreviewUrl({status: 'published'});
            if (previewWindow) {
                previewWindow.location.href = previewUrl;
            } else {
                window.open(previewUrl, '_blank');
            }
        } catch (error) {
            previewWindow?.close();
            showToast(error?.message || translateUiText('已发布版本预览启动失败'), 'error');
        }
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
                item.hidden = false;
            } else {
                item.hidden = true;
            }
        });

        // 隐藏空分组
        document.querySelectorAll('.widget-group').forEach(group => {
            const visibleWidgets = group.querySelectorAll('.widget-item.draggable:not([hidden])');
            if (visibleWidgets.length === 0) {
                group.hidden = true;
            } else {
                group.hidden = false;
            }
        });
        applyWidgetLibraryTabVisibility();
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
                item.hidden = false;
                item.classList.remove('area-matched', 'area-universal', 'area-not-matched', 'area-rejected');
            });
            document.querySelectorAll('.widget-group').forEach(group => {
                group.hidden = false;
                group.classList.remove('collapsed');
            });
            applyWidgetLibraryTabVisibility();
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
                item.hidden = true;
                item.classList.add('area-rejected');
            } else if (canPlace) {
                item.hidden = false;
                if (isExactMatch && !isUniversal) {
                    // 精确匹配：推荐，带呼吸高亮动画
                    item.classList.add('area-matched');
                } else if (isUniversal) {
                    // 通用部件：可用但不是首选
                    item.classList.add('area-universal');
                }
            } else {
                item.hidden = true;
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
                group.hidden = true;
            } else if (hasMatch) {
                group.hidden = false;
                group.classList.remove('collapsed'); // 自动展开
            } else {
                group.hidden = true;
            }
        });

        console.log('[filterWidgetsByArea] Area:', areaCode, 'Reject types:', finalRejectTypes);
        applyWidgetLibraryTabVisibility();
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
        const slotInfo = buildSlotSelectionFromAreaElement(areaElement);
        const activateAreaSlot = function() {
            if (!slotInfo) {
                setSidePanelOpen('widget', true);
                return;
            }

            state.selectedSlot = slotInfo;
            openWidgetPanelForSlotSelection(slotInfo);
            applySlotWidgetRecommendations(slotInfo);
            renderSlotInfoPanel(slotInfo);
        };

        // 如果点击的是已选中的区域，只执行滚动，不取消选中
        if (state.selectedArea === areaCode) {
            activateAreaSlot();
            // 直接滚动到该区域的部件
            scrollToMatchedWidgets(areaCode);
            showToast(slotInfo ? `已选中插槽: ${slotInfo.name || slotInfo.id}` : `已滚动到 "${areaName}" 区域部件`, 'info');
            return;
        }

        // 移除其他区域的选中状态
        document.querySelectorAll('.preview-area.area-selected').forEach(el => {
            el.classList.remove('area-selected');
        });

        // 选中当前区域
        areaElement.classList.add('area-selected');
        state.selectedArea = areaCode;

        // 按区域/插槽在服务端重新过滤加载部件库（分页），并在搜索框显示当前插槽标签
        setWidgetSlotFilter(areaCode, areaName, areaCode);
        activateAreaSlot();

        // 显示提示
        showToast(slotInfo ? `已选中插槽: ${slotInfo.name || slotInfo.id}` : `已筛选 "${areaName}" 区域的部件`, 'info');

        console.log('[ThemeEditor] Area selected:', areaCode, '- reloading slot-filtered widgets');
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

        // 清除插槽过滤，恢复完整部件库（分页）
        const lib = getWidgetLibState();
        if (lib.slot) {
            setWidgetSlotFilter(null, '', null);
        }
    }

    /**
     * 显示提示
     */
    function showToast(message, type = 'info') {
        const tone = type === 'error' ? 'danger' : ['success', 'warning', 'info'].includes(type) ? type : 'info';
        return getEditorUi().toast.show(String(message ?? ''), { tone, duration: 3000 });
    }

    /**
     * 自定义确认对话框（替代原生 confirm）
     */
    function showCustomConfirm(title, message, confirmText = '确认', cancelText = '取消') {
        return getEditorUi().dialog.confirm(String(message ?? ''), {
            title: String(title ?? ''),
            confirmLabel: String(confirmText),
            cancelLabel: String(cancelText),
            size: 'sm',
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
        const type = getParamUiType(param);
        return TRANSLATABLE_TYPES.includes(type);
    }

    function getParamUiType(param) {
        if (!param || typeof param !== 'object') {
            return 'string';
        }
        return param.ui_type || param.input || param.type || 'string';
    }

    function isBooleanParamType(type) {
        return type === 'bool' || type === 'boolean';
    }

    function normalizeThemeFileImageNode(value) {
        let node = value;
        if (typeof node === 'string') {
            const trimmed = node.trim();
            if (!trimmed || !trimmed.startsWith('{')) return null;
            try { node = JSON.parse(trimmed); } catch (_error) { return null; }
        }
        if (!node || typeof node !== 'object' || Array.isArray(node) || node.type !== 'file-image') return null;
        const usage = node.usage;
        if (!usage || typeof usage !== 'object' || Number(usage.version) !== 1 || !usage.asset_id || !usage.locale_code) return null;
        return { type: 'file-image', usage };
    }

    function renderTypedFileImageControl(fieldId, fieldKey, value, fieldParam = {}, options = {}) {
        const node = normalizeThemeFileImageNode(value);
        const storedValue = node
            ? JSON.stringify(node)
            : ((typeof value === 'string' || typeof value === 'number') ? String(value).trim() : '');
        const legacyPreviewUrl = node ? '' : sanitizeLegacyImagePreviewUrl(storedValue, '');
        const hasValue = storedValue !== '';
        const mediaOptions = fieldParam?.media_options || {};
        const defaultDir = mediaOptions.default_directory || fieldParam?.default_directory || 'banner';
        const recommendW = mediaOptions.recommend_width || fieldParam?.recommend_width || '';
        const recommendH = mediaOptions.recommend_height || fieldParam?.recommend_height || '';
        const placeholder = node
            ? String(node.usage.alt || node.usage.asset_id)
            : translateUiText(hasValue ? '旧图片地址仅兼容显示，请从媒体库重新选择' : '从媒体库选择');
        const safeId = escapeHtml(fieldId);
        const safeKey = escapeHtml(fieldKey);
        const inputClass = options.arrayItem ? ' class="w-param-array-item-input"' : '';
        const nameAttr = options.includeName === false ? '' : ` name="${safeKey}"`;
        let html = '<div class="w-param-media-image">';
        html += `<div class="w-param-image-preview${hasValue ? ' w-param-has-image' : ''}" id="${safeId}_preview">`;
        if (legacyPreviewUrl) {
            html += `<img src="${escapeHtml(legacyPreviewUrl)}" alt="${escapeHtml(translateUiText('预览'))}">`;
        }
        html += `<div class="w-param-image-placeholder"${legacyPreviewUrl ? ' hidden' : ''}>${escapeHtml(placeholder)}</div>`;
        html += '<div class="w-param-image-actions">';
        html += `<button type="button" class="w-button w-param-image-select w-param-media-image-select" data-tone="primary" data-variant="outline" data-size="sm" data-target="${safeId}" data-field="${safeKey}" data-default-dir="${escapeHtml(defaultDir)}"`;
        if (recommendW) html += ` data-recommend-w="${escapeHtml(String(recommendW))}"`;
        if (recommendH) html += ` data-recommend-h="${escapeHtml(String(recommendH))}"`;
        html += `>${escapeHtml(translateUiText('选择'))}</button>`;
        if (hasValue) {
            html += `<button type="button" class="w-button w-param-image-clear" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" data-target="${safeId}" aria-label="${escapeHtml(translateUiText('清除图片'))}">×</button>`;
        }
        html += '</div></div>';
        html += `<input type="hidden"${inputClass} id="${safeId}"${nameAttr} data-field="${safeKey}" value="${escapeHtml(storedValue)}" data-preview="${safeId}_preview" data-clear-label="${escapeHtml(translateUiText('清除图片'))}">`;
        html += '</div>';
        return html;
    }

    function normalizeBooleanSelectValue(value) {
        if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
            return '1';
        }
        if (value === false || value === 0 || value === '0' || value === 'false' || value === 'off') {
            return '0';
        }
        return value ? '1' : '0';
    }

    function getBooleanSelectOptions(key, param) {
        const explicitOptions = param && param.options && Object.keys(param.options).length > 0 ? param.options : null;
        if (explicitOptions) {
            return explicitOptions;
        }
        const normalizedKey = String(key || '').toLowerCase();
        if (normalizedKey.startsWith('show') || normalizedKey.includes('visible')) {
            return { '1': '显示', '0': '隐藏' };
        }
        return { '1': '是', '0': '否' };
    }

    function renderBooleanSelect(fieldId, name, key, value, required, param) {
        const selectedValue = normalizeBooleanSelectValue(value);
        const options = getBooleanSelectOptions(key, param);
        let html = `<select class="w-select" id="${escapeHtml(fieldId)}" name="${escapeHtml(name)}" ${required ? 'required' : ''}>`;
        for (const [optVal, optLabel] of Object.entries(options)) {
            html += `<option value="${escapeHtml(optVal)}" ${String(optVal) === selectedValue ? 'selected' : ''}>${escapeHtml(optLabel)}</option>`;
        }
        html += `</select>`;
        return html;
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

    function sanitizeUrlForAttribute(value, fallback = '') {
        if (value === null || value === undefined) return fallback;
        const raw = String(value).trim();
        if (!raw) return fallback;

        const normalized = raw.replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();
        if (normalized.startsWith('javascript:') || normalized.startsWith('vbscript:')) {
            return fallback;
        }
        if (normalized.startsWith('data:') && !/^data:image\/(png|jpe?g|gif|webp|svg\+xml)/i.test(raw)) {
            return fallback;
        }

        return raw;
    }

    function sanitizeLegacyImagePreviewUrl(value, fallback = '') {
        if (value === null || value === undefined) return fallback;
        const raw = String(value).trim();
        if (!raw
            || raw.length > 8192
            || /[\u0000-\u001F\u007F\\]/.test(raw)
            || raw.startsWith('//')
        ) {
            return fallback;
        }
        try {
            const parsed = new URL(raw, document.baseURI);
            if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) {
                return fallback;
            }
            const explicitScheme = /^[a-z][a-z0-9+.-]*:/i.exec(raw);
            if (explicitScheme && !/^https?:$/i.test(explicitScheme[0])) {
                return fallback;
            }
            return raw;
        } catch (_error) {
            return fallback;
        }
    }

    function renderSafeImagePreview(preview, rawUrl) {
        if (!preview) return false;

        const safeUrl = sanitizeUrlForAttribute(rawUrl, '');
        if (!safeUrl) return false;

        preview.innerHTML = '';
        const img = document.createElement('img');
        img.src = safeUrl;
        img.alt = '预览';
        img.addEventListener('error', function() {
            preview.innerHTML = '<div class="image-placeholder"><span>图片加载失败</span></div>';
        });
        preview.appendChild(img);
        return true;
    }

    function sanitizeHtmlForEditorPreview(html) {
        if (!html) return '';

        const template = document.createElement('template');
        template.innerHTML = String(html);

        template.content
            .querySelectorAll('script, link, meta, object, embed, iframe, frame, frameset, base, form, input, textarea, select, option')
            .forEach(el => el.remove());

        template.content.querySelectorAll('style').forEach(el => {
            if (!isSafeEditorPreviewCss(el.textContent || '')) {
                el.remove();
            }
        });

        template.content.querySelectorAll('*').forEach(el => {
            Array.from(el.attributes).forEach(attr => {
                const name = attr.name.toLowerCase();
                const value = String(attr.value || '').trim().replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();

                if (name.startsWith('on') || name === 'srcdoc') {
                    el.removeAttribute(attr.name);
                    return;
                }

                if ((name === 'href' || name === 'src' || name === 'xlink:href' || name === 'formaction') && (
                    value.startsWith('javascript:') ||
                    value.startsWith('vbscript:') ||
                    (value.startsWith('data:') && !value.startsWith('data:image/'))
                )) {
                    el.removeAttribute(attr.name);
                    return;
                }

                if (name === 'style' && !isSafeEditorPreviewCss(attr.value || '')) {
                    el.removeAttribute(attr.name);
                }
            });
        });

        return template.innerHTML;
    }

    function isSafeEditorPreviewCss(css) {
        const value = String(css || '').trim();
        if (!value) {
            return true;
        }
        if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(value)) {
            return false;
        }

        return !/@import\b|expression\s*\(|behavior\s*:|-moz-binding\s*:|url\s*\(\s*['"]?\s*(?:javascript|vbscript):|url\s*\(\s*['"]?\s*data:(?!image\/(?:png|gif|jpe?g|webp|bmp|svg\+xml);base64,)/i.test(value);
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

        return sanitizeHtmlForEditorPreview(temp.innerHTML);
    }

    function buildStructurePlaceholderHtml(areaEl, areaCode) {
        const defaults = {
            header: {
                icon: 'layout-header',
                title: 'Header Area',
                text: 'Drag into header widget',
                tips: [
                    ['image', 'Logo'],
                    ['menu', 'Navigation'],
                    ['search', 'Search'],
                    ['user', 'Account'],
                    ['cart', 'Shopping Cart'],
                ],
            },
            content: {
                icon: 'grid',
                title: 'Content Area',
                text: 'Drag widgets or containers here',
                tips: [
                    ['layout-row', 'Row layout'],
                    ['layout-column', 'Column layout'],
                    ['image', 'Banner'],
                    ['slideshow', 'Carousel'],
                    ['box', 'Product'],
                ],
            },
            footer: {
                icon: 'layout-footer',
                title: 'Footer Area',
                text: 'Drag in footer components',
                tips: [
                    ['link', 'Links'],
                    ['mail', 'Subscribe'],
                    ['share', 'Social'],
                    ['copyright', 'Copyright'],
                ],
            },
        };

        const meta = defaults[areaCode] || defaults.content;
        const title = areaEl?.dataset?.slotName || meta.title;
        const tipsHtml = meta.tips
            .map(([icon, label]) => `<span class="tip-item">${iconSvg(icon)} ${escapeHtml(label)}</span>`)
            .join('');

        return `
            <div class="slot-placeholder-large">
                <div class="placeholder-icon">
                    ${iconSvg(meta.icon)}
                </div>
                <div class="placeholder-title">${escapeHtml(title)}</div>
                <div class="placeholder-text">${escapeHtml(meta.text)}</div>
                <div class="placeholder-tips">
                    ${tipsHtml}
                </div>
            </div>
        `;
    }

    function resetStructureViewToEmptySlots() {
        document.querySelectorAll('#previewViewStructure .area-slot').forEach(areaEl => {
            const areaCode = areaEl.dataset.area || areaEl.dataset.slot || 'content';
            const widgetsContainer = areaEl.querySelector('.area-widgets');
            if (!widgetsContainer) {
                return;
            }

            widgetsContainer.querySelectorAll('.preview-widget-item').forEach(el => el.remove());
            widgetsContainer.querySelectorAll('.slot-placeholder, .content-slot-placeholder, .slot-placeholder-large').forEach(el => el.remove());
            widgetsContainer.insertAdjacentHTML('beforeend', buildStructurePlaceholderHtml(areaEl, areaCode));
        });
    }

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

            const result = await apiJson(config.apiRestoreOriginal, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload()),
            });

            if (result.success) {
                await loadScopedWorkspace('layout');
                showToast(result.message || '已恢复到原始布局', 'success');
                clearSlotSelection();
                deselectArea();
                deselectWidget();
                resetStructureViewToEmptySlots();

                // 刷新版本列表
                await loadVersions();
                await fetchLayoutSlots();

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

    function openResetDraftModal() {
        const modal = elements.resetDraftModal || document.getElementById('themeEditorResetDraftModal');
        if (!(modal instanceof HTMLElement)) {
            return;
        }
        resetDraftModalDefaults(modal);
        showEditorModal(modal);
    }

    function closeResetDraftModal() {
        const modal = elements.resetDraftModal || document.getElementById('themeEditorResetDraftModal');
        if (!(modal instanceof HTMLElement)) {
            return;
        }
        hideEditorModal(modal);
    }

    function resetDraftModalDefaults(modal) {
        const defaultChecked = new Set(['layout', 'meta', 'appearance']);
        modal.querySelectorAll('input[name="reset_resource"]').forEach((input) => {
            input.checked = defaultChecked.has(String(input.value || ''));
        });
        const currentScope = modal.querySelector('input[name="reset_layout_scope"][value="current_layout"]');
        if (currentScope instanceof HTMLInputElement) {
            currentScope.checked = true;
        }
        const danger = modal.querySelector('.w-theme-editor-reset-draft__danger');
        if (danger instanceof HTMLDetailsElement) {
            danger.open = false;
        }
    }

    function collectResetDraftSelections(selectAll = false) {
        const modal = elements.resetDraftModal || document.getElementById('themeEditorResetDraftModal');
        const resources = [];
        if (selectAll) {
            ['layout', 'meta', 'appearance', 'theme_binding', 'i18n'].forEach((resource) => resources.push(resource));
            if (modal instanceof HTMLElement) {
                modal.querySelectorAll('input[name="reset_resource"]').forEach((input) => {
                    input.checked = true;
                });
            }
        } else if (modal instanceof HTMLElement) {
            modal.querySelectorAll('input[name="reset_resource"]:checked').forEach((input) => {
                const value = String(input.value || '').trim();
                if (value) {
                    resources.push(value);
                }
            });
        }
        let layoutScope = 'current_layout';
        if (modal instanceof HTMLElement) {
            const checkedScope = modal.querySelector('input[name="reset_layout_scope"]:checked');
            if (checkedScope instanceof HTMLInputElement && checkedScope.value) {
                layoutScope = checkedScope.value;
            }
        }
        return { resources, layout_scope: layoutScope };
    }

    async function executeResetDraftResources(selectAll = false) {
        const selection = collectResetDraftSelections(selectAll);
        if (!selection.resources.length) {
            showToast(translateUiText('请至少选择一种资源类型'), 'warning');
            return;
        }

        const confirmed = await showCustomConfirm(
            translateUiText(selectAll ? '确认重置所有草稿资源？' : '确认重置选中草稿资源？'),
            translateUiText('仅清理当前编辑草稿物化，不会删除版本历史或已发布内容。'),
            translateUiText('确认重置'),
            translateUiText('取消'),
        );
        if (!confirmed) {
            return;
        }

        showToast(translateUiText('正在重置当前编辑草稿...'), 'info');
        const result = await apiJson(config.apiResetDraftResources, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(buildLayoutVersionIdentityPayload({
                resources: selection.resources,
                layout_scope: selection.layout_scope,
            })),
        });

        if (!result?.success) {
            showToast(result?.message || translateUiText('重置失败'), 'error');
            return;
        }

        closeResetDraftModal();
        for (const resourceType of selection.resources) {
            try {
                await loadScopedWorkspace(resourceType);
            } catch (error) {
                console.warn('[ThemeEditor] reload scoped workspace after reset failed:', resourceType, error);
            }
        }
        if (selection.resources.includes('layout')) {
            await refreshDefaultInjectionApplications({
                render: state.widgetLibraryTab === 'applications',
                silent: true,
            });
        }
        clearSlotSelection();
        deselectArea();
        deselectWidget();
        await fetchLayoutSlots();
        showToast(result.message || translateUiText('当前编辑草稿已重置'), 'success');
        setTimeout(() => {
            refreshPreview();
        }, 300);
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
        setLayoutPreviewSource();
        fetchLayoutSlots();
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

                // 禁链开关：彻底阻止跳转，不切换预览页、不新开标签。
                if (state.linkBlockEnabled === true) {
                    if (link.closest('.slot-toolbar, .widget-hover-actions')) {
                        return;
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }

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
                let themePublicRoute = '';

                const pathSegments = pathname.split('/').filter(Boolean);
                const productIdx = pathSegments.findIndex((seg) => seg.toLowerCase() === 'product');
                const categoryIdx = pathSegments.findIndex((seg) => seg.toLowerCase() === 'category');

                // 路径到页面类型的映射（使用布局目录名）
                if (pathname === '/' || pathname === '' || pathname.endsWith('/index')) {
                    pageType = 'homepage';
                    layoutType = 'homepage';
                } else if (pathname.includes('/category/') || pathname.includes('/catalog/')) {
                    pageType = 'category';
                    layoutType = 'category';
                    if (categoryIdx >= 0 && pathSegments[categoryIdx + 1]) {
                        themePublicRoute = 'category/' + pathSegments.slice(categoryIdx + 1).join('/').toLowerCase();
                    }
                } else if (pathname.includes('/product/')) {
                    pageType = 'product';
                    layoutType = 'product';
                    if (productIdx >= 0 && pathSegments[productIdx + 1]) {
                        themePublicRoute = 'product/' + String(pathSegments[productIdx + 1]).toLowerCase();
                    }
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

                // 构建预览 URL：选中布局，并携带具体产品/分类 public route
                showToast(`已切换到 ${pageType} 布局`, 'info');
                console.log('[ThemeEditor] Link intercepted:', href, '-> Editor page type:', pageType, themePublicRoute || '');
                const shellOverrides = {
                    page_type: pageType,
                    layout_option: resolveLayoutOptionForType(pageType, ''),
                };
                if (themePublicRoute) {
                    shellOverrides.theme_public_route = themePublicRoute;
                } else {
                    shellOverrides.theme_public_route = null;
                }
                navigateEditorShell(shellOverrides);
                return;

                const previewUrl = new URL(config.apiLayoutPreview, currentOrigin);
                previewUrl.searchParams.set('theme_id', state.themeId);
                previewUrl.searchParams.set('layout_type', layoutType);
                previewUrl.searchParams.set('layout_option', 'default');
                previewUrl.searchParams.set('editor_mode', '1');
                previewUrl.searchParams.set('interaction_mode', normalizeInteractionMode(state.interactionMode || 'edit'));
                const selectionTarget = normalizeSelectionTarget(state.selectionTarget || 'default');
                if (selectionTarget === 'default') {
                    previewUrl.searchParams.delete('selection_target');
                } else {
                    previewUrl.searchParams.set('selection_target', selectionTarget);
                }
                if (state.linkBlockEnabled === true) {
                    previewUrl.searchParams.set('link_block', '1');
                } else {
                    previewUrl.searchParams.delete('link_block');
                }
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

            // 同步交互模式（预览态不强制 editor-mode）
            notifyPreviewInteractionMode(state.interactionMode || 'edit');
            notifyPreviewSelectionTarget(state.selectionTarget || 'default');
            notifyPreviewLinkBlock(state.linkBlockEnabled === true);
            if (isEditInteractionMode()) {
                iframeDoc.body?.classList.add('editor-mode');
            } else {
                iframeDoc.body?.classList.remove('editor-mode');
            }

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
    function loadLayoutPreview(overrides = {}) {
        if (!elements.previewFrame || !state.themeId) {
            return;
        }

        // 显示加载状态
        if (elements.previewLoading) {
            elements.previewLoading.classList.remove('hidden');
        }

        // 构建预览 URL
        const navigation = setLayoutPreviewSource(overrides);
        fetchLayoutSlots(overrides);
        return navigation;

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
    async function fetchLayoutSlots(overrides = {}) {
        if (!state.themeId) return;

        try {
            const url = new URL(config.apiCompileLayout, window.location.origin);
            url.searchParams.set('theme_id', state.themeId);
            url.searchParams.set('page_type', overrides.page_type || getEffectivePageType());
            url.searchParams.set('layout_type', overrides.layout_type || getEffectiveLayoutType());
            url.searchParams.set('layout_option', overrides.layout_option || getEffectiveLayoutOption());
            const editorArea = overrides.editor_area || getEffectiveEditorArea();
            url.searchParams.set('editor_area', editorArea);
            url.searchParams.set('preview_area', editorArea);
            url.searchParams.set('preview_mode', overrides.preview_mode || 'live');
            url.searchParams.set('status', overrides.status || state.previewStatus || 'draft');
            url.searchParams.set('include_html', '0');
            const previewLocale = getPreviewLocaleForRequest(overrides);
            if (previewLocale) {
                url.searchParams.set('locale', previewLocale);
            }
            appendThemeLayoutRuntimeParams(url, overrides);
            if (overrides.version_id) {
                url.searchParams.set('version_id', String(overrides.version_id));
            }

            const result = await apiJson(url.toString(), { silent: true });
            if (result.success && result.slots) {
                state.slots = mergeSlotInfoMaps(result.slots, collectDomSlotsForInfo(result.slots));
                state.missingSlotWarnings = Array.isArray(result.missing_slot_warnings) ? result.missing_slot_warnings : [];
                renderSlotsInfo(state.slots, state.missingSlotWarnings);
            }
        } catch (err) {
            console.error('获取插槽信息失败:', err);
        }
    }

    function normalizeSlotInfoMap(slots) {
        if (!slots) {
            return {};
        }

        if (Array.isArray(slots)) {
            return slots.reduce((map, slot) => {
                const slotId = String(slot?.id || slot?.slot_id || '').trim();
                if (slotId && !isSyntheticContainerSlotId(slotId)) {
                    map[slotId] = { ...slot, id: slotId };
                }
                return map;
            }, {});
        }

        if (typeof slots === 'object') {
            return Object.entries(slots).reduce((map, [slotId, slot]) => {
                const normalizedId = String(slot?.id || slot?.slot_id || slotId || '').trim();
                if (normalizedId && !isSyntheticContainerSlotId(normalizedId)) {
                    map[normalizedId] = {
                        ...(slot && typeof slot === 'object' ? slot : {}),
                        id: normalizedId,
                    };
                }
                return map;
            }, {});
        }

        return {};
    }

    function isSyntheticContainerSlotId(slotId) {
        // Generated wrapper helpers are not real drop targets. Component slots must use
        // their declared IDs, e.g. section-content:279 or grid-items:285.
        return /^container:[1-9][0-9]*$/.test(String(slotId || '').trim());
    }

    function mergeSlotInfoMaps(...slotMaps) {
        const merged = {};
        slotMaps.forEach((slotMap) => {
            Object.entries(normalizeSlotInfoMap(slotMap)).forEach(([slotId, slot]) => {
                merged[slotId] = {
                    ...(merged[slotId] || {}),
                    ...slot,
                    id: slotId,
                };
            });
        });
        return merged;
    }

    function isSlotInfoElement(element) {
        if (!element || element.nodeType !== 1) {
            return false;
        }
        if (element.ownerDocument === document
            && element.closest('#widgetPanel, #widgetList, .editor-widget-panel, .widget-list, .widget-group, .widget-item, .widget-preview')) {
            return false;
        }
        if (element.matches('.preview-widget-item, .widget-wrapper, .preview-widget, [data-widget-code][data-layout-id]')) {
            return false;
        }
        if (element.hasAttribute('data-wslot')) {
            return true;
        }
        if (element.matches('.area-slot, .content-slot, .header-slot, .footer-slot, .container-slot, .slot-widgets')) {
            return true;
        }
        return element.hasAttribute('data-slot')
            && !element.hasAttribute('data-layout-id')
            && !element.hasAttribute('data-widget-code');
    }

    function getSlotIdFromElement(element) {
        return String(
            element?.getAttribute('data-wslot')
            || element?.getAttribute('data-slot')
            || element?.getAttribute('data-slot-id')
            || ''
        ).trim();
    }

    function collectDomSlotsFromDocument(doc) {
        const slots = {};
        if (!doc) {
            return slots;
        }

        doc.querySelectorAll('[data-wslot], [data-slot], [data-slot-id], .area-slot, .content-slot, .header-slot, .footer-slot, .container-slot, .slot-widgets')
            .forEach((element) => {
                if (!isSlotInfoElement(element)) {
                    return;
                }
                const slotId = getSlotIdFromElement(element);
                if (!slotId || isSyntheticContainerSlotId(slotId)) {
                    return;
                }
                slots[slotId] = {
                    ...buildSlotInfoFromElement(slotId, element),
                    source: 'dom',
                };
            });

        return slots;
    }

    function collectDomSlotsForInfo(catalogSlots = {}) {
        const previousSlots = state.slots;
        state.slots = mergeSlotInfoMaps(previousSlots, catalogSlots);

        try {
            return mergeSlotInfoMaps(
                collectDomSlotsFromDocument(document),
                collectDomSlotsFromDocument(getPreviewDocument())
            );
        } finally {
            state.slots = previousSlots;
        }
    }

    /**
     * 渲染插槽信息列表
     */
    function renderSlotsInfo(slots, missingSlotWarnings = []) {
        if (!elements.slotsInfoList) return;

        slots = mergeSlotInfoMaps(slots, collectDomSlotsForInfo(slots));
        state.slots = slots;

        let html = renderMissingSlotWarnings(missingSlotWarnings);
        for (const [slotId, slot] of Object.entries(slots)) {
            const rawAccept = slot.accept;
            const acceptArr = Array.isArray(rawAccept) ? rawAccept : (typeof rawAccept === 'string' ? rawAccept.split(',').map(s => s.trim()).filter(Boolean) : []);
            const acceptTags = acceptArr.map(code =>
                `<span>${escapeHtml(code)}</span>`
            ).join('');

            html += `
                <div class="slot-info-item" data-slot-id="${escapeHtml(slotId)}">
                    <div class="slot-name">${escapeHtml(slot.name || slotId)}</div>
                    <div class="slot-accept">${acceptTags || '<span>任意部件</span>'}</div>
                </div>
            `;
        }

        elements.slotsInfoList.innerHTML = html || '<p class="w-theme-editor-empty-copy">暂无插槽</p>';
    }

    /**
     * 滚动到指定插槽（iframe 内）
     */
    function renderMissingSlotWarnings(warnings) {
        if (!Array.isArray(warnings) || warnings.length === 0) {
            return '';
        }

        const items = warnings.slice(0, 8).map(warning => {
            const key = warning.logical_key || warning.relative_path || 'unknown';
            const slotIds = Array.isArray(warning.missing_slot_ids) ? warning.missing_slot_ids : [];
            const slots = slotIds.map(slotId => `<code>${escapeHtml(String(slotId))}</code>`).join(', ');
            return `<li><strong>${escapeHtml(String(key))}</strong>: ${slots}</li>`;
        }).join('');
        const remaining = warnings.length > 8 ? `<li>另有 ${warnings.length - 8} 个覆写文件存在相同问题。</li>` : '';

        return `
            <div class="slot-contract-warning">
                <div class="slot-contract-warning-title">默认 slot 缺失</div>
                <div class="slot-contract-warning-desc">当前主题覆写了 Weline_Theme 默认文件，但缺少默认 slot，相关插件可能找不到挂载点。</div>
                <ul>${items}${remaining}</ul>
            </div>
        `;
    }

    /**
     * 滚动并选中预览里的目标 slot。
     */
    function scrollToSlot(slotId) {
        const normalizedSlotId = String(slotId || '').trim();
        if (!normalizedSlotId) {
            return;
        }

        let slotElement = null;

        try {
            if (elements.previewFrame && elements.previewFrame.contentWindow) {
                const iframeDoc = elements.previewFrame.contentDocument || elements.previewFrame.contentWindow.document;
                slotElement = findIframeSlotElement(iframeDoc, normalizedSlotId);

                if (slotElement) {
                    iframeDoc.querySelectorAll('[data-wslot], [data-slot], [data-slot-id], .content-slot').forEach(element => {
                        element.classList.remove('slot-selected');
                    });
                    slotElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    slotElement.classList.add('slot-highlight', 'slot-selected');
                    setTimeout(() => slotElement.classList.remove('slot-highlight'), 2000);
                }
            }
        } catch (err) {
            console.error('Scroll to slot failed:', err);
        }

        handleSlotSelected(buildSlotInfoFromElement(normalizedSlotId, slotElement));
    }

    /**
     * 全局暴露：取消区域选中（用于清除部件过滤）
     */
    function getPreviewDocument() {
        try {
            return elements.previewFrame?.contentDocument || elements.previewFrame?.contentWindow?.document || null;
        } catch (err) {
            console.warn('[ThemeEditor] Unable to access preview document:', err);
            return null;
        }
    }

    function normalizePlacementSlotInfo(slot) {
        if (!slot || typeof slot !== 'object') {
            return null;
        }
        const id = String(slot.id || slot.slot_id || '').trim();
        if (!id) {
            return null;
        }
        const accept = normalizeCodeList(slot.accept ?? slot.accept_codes ?? []);
        const reject = normalizeCodeList(slot.reject ?? slot.reject_codes ?? []);
        return {
            id,
            slot_id: id,
            name: slot.name || id,
            area: slot.area || inferAreaFromSlotId(id),
            accept,
            reject,
            exclusive: slot.exclusive === true || slot.exclusive === 'true',
            multiple: slot.multiple !== false && slot.multiple !== 'false',
            max: slot.max ?? '',
            min: slot.min ?? '',
            source: slot.source || 'registry',
        };
    }

    function collectWidgetPlacementSlots() {
        const slotsById = new Map();
        Object.entries(state.slots || {}).forEach(([slotId, slot]) => {
            if (isSyntheticContainerSlotId(slotId)) {
                return;
            }
            const normalized = normalizePlacementSlotInfo({
                ...(slot && typeof slot === 'object' ? slot : {}),
                id: slotId,
                source: 'registry',
            });
            if (normalized) {
                slotsById.set(normalized.id, normalized);
            }
        });

        const iframeDoc = getPreviewDocument();
        if (iframeDoc) {
            iframeDoc.querySelectorAll('[data-wslot], [data-slot], [data-slot-id]').forEach(slotEl => {
                const slotId = slotEl.getAttribute('data-wslot') || slotEl.getAttribute('data-slot') || slotEl.getAttribute('data-slot-id') || '';
                if (!slotId || isSyntheticContainerSlotId(slotId)) {
                    return;
                }
                const normalized = normalizePlacementSlotInfo({
                    ...buildSlotInfoFromElement(slotId, slotEl),
                    source: 'dom',
                });
                if (normalized) {
                    slotsById.set(normalized.id, {
                        ...(slotsById.get(normalized.id) || {}),
                        ...normalized,
                    });
                }
            });
        }

        return Array.from(slotsById.values());
    }

    function buildWidgetPlacementSlotTree(slots, anchors) {
        const areas = ['header', 'content', 'footer'];
        const innerSlotIds = new Set();
        anchors.forEach(anchor => {
            (anchor.inner_slots || []).forEach(innerSlot => {
                if (innerSlot?.id) {
                    innerSlotIds.add(innerSlot.id);
                }
            });
        });
        const root = {
            id: 'page',
            label: '页面',
            type: 'page',
            children: areas.map(area => ({
                id: area,
                label: area === 'header' ? '页头' : area === 'footer' ? '页脚' : '内容',
                type: 'area',
                area,
                children: [],
            })),
        };
        const areaNodes = new Map(root.children.map(node => [node.area, node]));

        slots.forEach(slot => {
            if (innerSlotIds.has(slot.id)) {
                return;
            }
            const area = slot.area || inferAreaFromSlotId(slot.id);
            const areaNode = areaNodes.get(area) || areaNodes.get('content');
            areaNode.children.push({
                id: slot.id,
                label: slot.name || slot.id,
                type: 'slot',
                area,
                slot,
                children: [],
            });
        });

        const slotNodes = new Map();
        root.children.forEach(areaNode => {
            areaNode.children.forEach(slotNode => slotNodes.set(slotNode.id, slotNode));
        });

        const anchorNodes = new Map();
        anchors.forEach(anchor => {
            const anchorNode = {
                id: `widget:${anchor.layout_id}`,
                label: anchor.widget_name || anchor.widget_code || anchor.layout_id,
                type: 'widget',
                area: anchor.area,
                anchor,
                children: [],
            };
            (anchor.inner_slots || []).forEach(innerSlot => {
                const innerSlotNode = {
                    id: innerSlot.id,
                    label: innerSlot.name || innerSlot.id,
                    type: 'slot',
                    area: innerSlot.area || anchor.area,
                    slot: innerSlot,
                    children: [],
                };
                anchorNode.children.push(innerSlotNode);
                slotNodes.set(innerSlot.id, innerSlotNode);
            });
            anchorNodes.set(String(anchor.layout_id), anchorNode);
        });

        anchors.forEach(anchor => {
            const slotNode = slotNodes.get(anchor.slot_id);
            const anchorNode = anchorNodes.get(String(anchor.layout_id));
            if (!slotNode || !anchorNode) {
                return;
            }
            if (slotNode.children.some(child => child.id === anchorNode.id)) {
                return;
            }
            slotNode.children.push(anchorNode);
        });

        return root;
    }

    function collectWidgetPlacementAnchors() {
        const anchors = [];
        const seen = new Set();
        const iframeDoc = getPreviewDocument();
        const collectFromElement = (element, source) => {
            if (!element) {
                return;
            }
            const identity = readWidgetIdentityFromElement(element);
            const anchorId = identity.identity;
            if (!anchorId || seen.has(anchorId)) {
                return;
            }
            seen.add(anchorId);
            const slotEl = element.closest('[data-wslot], [data-slot], [data-slot-id]');
            const slotId = slotEl?.getAttribute('data-wslot') || slotEl?.getAttribute('data-slot') || slotEl?.getAttribute('data-slot-id') || '';
            const area = slotEl?.getAttribute('data-wslot-position') || slotEl?.getAttribute('data-position') || inferAreaFromSlotId(slotId);
            const innerSlots = [];
            element.querySelectorAll?.('[data-wslot], [data-slot], [data-slot-id]')?.forEach(inner => {
                if (inner === slotEl) {
                    return;
                }
                const innerSlotId = inner.getAttribute('data-wslot') || inner.getAttribute('data-slot') || inner.getAttribute('data-slot-id') || '';
                if (isSyntheticContainerSlotId(innerSlotId)) {
                    return;
                }
                const normalized = normalizePlacementSlotInfo({
                    ...buildSlotInfoFromElement(innerSlotId, inner),
                    source: 'inner_dom',
                });
                if (normalized) {
                    innerSlots.push(normalized);
                }
            });
            anchors.push({
                layout_id: identity.layoutId || anchorId,
                template_ref: identity.templateRef || '',
                is_template: identity.isTemplate,
                widget_code: identity.widgetCode || element.dataset.widgetCode || element.getAttribute('data-widget-code') || '',
                widget_module: identity.widgetModule || element.dataset.widgetModule || element.getAttribute('data-widget-module') || '',
                widget_type: identity.widgetType || element.dataset.widgetType || element.getAttribute('data-widget-type') || '',
                widget_name: identity.widgetName || element.dataset.widgetName || element.getAttribute('data-widget-name') || '',
                slot_id: slotId,
                area,
                source,
                inner_slots: innerSlots,
            });
        };

        if (iframeDoc) {
            iframeDoc.querySelectorAll(
                '.widget-wrapper[data-layout-id], .preview-widget[data-layout-id], [data-widget-code][data-layout-id], .weline-template-widget[data-template-ref]'
            ).forEach(el => collectFromElement(el, 'preview'));
        }
        document.querySelectorAll('.preview-widget-item[data-layout-id], .preview-widget-item[data-template-ref]').forEach(el => collectFromElement(el, 'structure'));

        return anchors;
    }

    function getSelectedWidgetPlacementTarget(slots, anchors) {
        if (state.selectedSlot) {
            const selected = normalizePlacementSlotInfo(state.selectedSlot);
            if (selected) {
                return {
                    type: 'slot',
                    area: selected.area,
                    slot_id: selected.id,
                    insert_mode: 'into_slot',
                    slot: selected,
                };
            }
        }
        if (state.selectedWidget) {
            const identity = readWidgetIdentityFromElement(state.selectedWidget);
            const anchor = anchors.find(item => String(item.layout_id) === String(identity.identity)
                || (identity.templateRef && String(item.template_ref) === String(identity.templateRef)));
            if (anchor) {
                return {
                    type: 'widget',
                    area: anchor.area,
                    slot_id: anchor.slot_id,
                    anchor_layout_id: anchor.layout_id,
                    insert_mode: 'after',
                    anchor,
                };
            }
        }
        const firstSlot = slots.find(slot => slot.area === 'content') || slots[0] || null;
        return firstSlot ? {
            type: 'slot',
            area: firstSlot.area,
            slot_id: firstSlot.id,
            insert_mode: 'into_slot',
            slot: firstSlot,
        } : null;
    }

    function getWidgetPlacementContext() {
        const slots = collectWidgetPlacementSlots();
        const anchors = collectWidgetPlacementAnchors();
        return {
            theme_id: state.themeId,
            page_type: state.pageType,
            layout_type: state.layoutType,
            layout_option: state.layoutOption,
            editor_area: state.editorArea || 'frontend',
            preview_status: state.previewStatus || 'draft',
            slots,
            slot_tree: buildWidgetPlacementSlotTree(slots, anchors),
            widget_anchors: anchors,
            selected_target: getSelectedWidgetPlacementTarget(slots, anchors),
        };
    }

    function collectThemeAiCssVariables(sourceDoc, sourceName) {
        const result = {};
        if (!sourceDoc || !sourceDoc.documentElement || !window.getComputedStyle) {
            return result;
        }
        let styles = null;
        try {
            styles = sourceDoc.defaultView?.getComputedStyle(sourceDoc.documentElement) || window.getComputedStyle(sourceDoc.documentElement);
        } catch (error) {
            return result;
        }
        if (!styles) {
            return result;
        }
        const allowed = /^(--w-theme-editor-|--theme-|--weline-|--color-|--font-|--space-|--spacing-|--radius-|--shadow-|--layout-)/i;
        for (let i = 0; i < styles.length; i++) {
            const name = styles[i];
            if (!name || !name.startsWith('--') || !allowed.test(name)) {
                continue;
            }
            const value = String(styles.getPropertyValue(name) || '').trim();
            if (value !== '') {
                result[`${sourceName}.${name}`] = value;
            }
            if (Object.keys(result).length >= 80) {
                break;
            }
        }
        return result;
    }

    function collectThemeAiCurrentValues() {
        const values = {
            theme_id: state.themeId,
            page_type: state.pageType,
            layout_type: state.layoutType,
            layout_option: state.layoutOption,
            editor_area: state.editorArea || 'frontend',
            preview_status: state.previewStatus || 'draft',
        };
        const themeSelect = document.querySelector('#themeSelect');
        const layoutSelect = document.querySelector('#layoutOptionSelect');
        const areaSelect = document.querySelector('#editorAreaSelect, [name="editor_area"]');
        if (themeSelect) {
            values.theme_label = themeSelect.options?.[themeSelect.selectedIndex]?.textContent?.trim() || '';
        }
        if (layoutSelect) {
            values.layout_option_label = layoutSelect.options?.[layoutSelect.selectedIndex]?.textContent?.trim() || '';
        }
        if (areaSelect) {
            values.editor_area_label = areaSelect.options?.[areaSelect.selectedIndex]?.textContent?.trim() || '';
        }
        return values;
    }

    function getThemeWidgetAiContext() {
        const iframeDoc = getPreviewDocument();
        return {
            provider: 'Weline_Theme',
            kind: 'theme_editor_context',
            guidance: 'Optional reference only. Use these current theme values and variables when they help the user request; user prompt and Widget slot protocol have priority.',
            current_values: collectThemeAiCurrentValues(),
            css_variables: {
                editor: collectThemeAiCssVariables(document, 'editor'),
                preview: collectThemeAiCssVariables(iframeDoc, 'preview'),
            },
            placement_context: getWidgetPlacementContext(),
        };
    }

    function registerThemeWidgetAiContextProvider() {
        const provider = {
            id: 'theme',
            label: '主题上下文',
            description: '当前主题、布局、slot 树、CSS 变量和当前值。默认参考，可取消。',
            defaultSelected: true,
            optional: true,
            getContext: getThemeWidgetAiContext,
        };
        const widgetAi = Weline.Widget?.AI;
        if (typeof widgetAi?.registerContextProvider !== 'function') {
            console.warn('[ThemeEditor] Widget AI context provider API is unavailable.');
            return;
        }
        widgetAi.registerContextProvider(provider);
    }

    function resolveAnchorPlacementInfo(layoutId) {
        const iframeDoc = getPreviewDocument();
        const widgetEl = iframeDoc?.querySelector(dataLayoutIdSelector(layoutId)) || document.querySelector(`.preview-widget-item${dataLayoutIdSelector(layoutId)}`);
        if (!widgetEl) {
            return null;
        }
        const slotEl = widgetEl.closest('[data-wslot], [data-slot], [data-slot-id]');
        const slotId = slotEl?.getAttribute('data-wslot') || slotEl?.getAttribute('data-slot') || slotEl?.getAttribute('data-slot-id') || '';
        const area = slotEl?.getAttribute('data-wslot-position') || slotEl?.getAttribute('data-position') || inferAreaFromSlotId(slotId);
        const siblingContainer = widgetEl.parentElement;
        const siblings = siblingContainer ? Array.from(siblingContainer.children).filter(el => el.hasAttribute('data-layout-id')) : [];
        const index = siblings.findIndex(el => String(el.getAttribute('data-layout-id')) === String(layoutId));
        return { widgetEl, slotEl, slotId, area, index: index < 0 ? 0 : index };
    }

    function normalizeProviderWidgetData(widgetData) {
        const defaultConfig = widgetData.default_config || widgetData.defaultConfig || {};
        return {
            code: widgetData.code || widgetData.widget_code || '',
            module: widgetData.module || 'Weline_Widget',
            type: widgetData.type || 'content',
            name: widgetData.name || widgetData.code || widgetData.widget_code || 'Widget',
            position: widgetData.position || [],
            compatible: widgetData.compatible !== false,
            slot: widgetData.slot || null,
            supports: widgetData.supports || [],
            slots: widgetData.slots || [],
            exclusive: widgetData.exclusive === true || widgetData.exclusive === 'true',
            pageLayouts: widgetData.page_layouts || widgetData.pageLayouts || ['*'],
            isContainer: widgetData.is_container === true || widgetData.isContainer === true,
            config: widgetData.config || defaultConfig || {},
        };
    }

    async function deleteLayoutWidgetForPlacement(layoutId, slotId, area) {
        if (!layoutId) {
            return true;
        }
        const widgetContext = resolveWidgetContextFromIframe(layoutId);
        const result = await apiJson(config.apiDeleteWidget, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(buildWidgetDeletePayload(
                layoutId,
                slotId || widgetContext.slotId,
                area || widgetContext.area,
                widgetContext
            ))
        });
        if (result?.success) {
            await queueRemovedLayoutNode(result, {
                layoutId,
                nodeUid: widgetContext.nodeUid,
            });
        }
        return !!result?.success;
    }

    async function placeWidgetFromProvider(widgetData, placementTarget = {}) {
        const normalizedWidget = normalizeProviderWidgetData(widgetData || {});
        if (!normalizedWidget.code) {
            showToast('生成的部件缺少 code，无法放置', 'error');
            return null;
        }

        const mode = placementTarget.insert_mode || placementTarget.mode || 'into_slot';
        const anchorInfo = placementTarget.anchor_layout_id ? resolveAnchorPlacementInfo(placementTarget.anchor_layout_id) : null;
        const selectedSlot = state.selectedSlot ? normalizePlacementSlotInfo(state.selectedSlot) : null;
        let slotId = placementTarget.slot_id || placementTarget.parent_slot_id || anchorInfo?.slotId || selectedSlot?.id || normalizedWidget.slot || '';
        let area = placementTarget.area || anchorInfo?.area || selectedSlot?.area || inferAreaFromSlotId(slotId);
        let sortOrder = placementTarget.sort_order != null ? Number(placementTarget.sort_order) : null;

        if ((mode === 'before' || mode === 'after' || mode === 'replace') && anchorInfo) {
            slotId = slotId || anchorInfo.slotId;
            area = area || anchorInfo.area;
            if (sortOrder === null || Number.isNaN(sortOrder)) {
                sortOrder = mode === 'after' ? anchorInfo.index + 1 : anchorInfo.index;
            }
        }

        if (!slotId && selectedSlot) {
            slotId = selectedSlot.id;
            area = selectedSlot.area;
        }
        if (!slotId) {
            showToast('请先选择要放置的 slot', 'warning');
            return null;
        }

        if (mode === 'replace' && anchorInfo?.widgetEl) {
            const deleted = await deleteLayoutWidgetForPlacement(placementTarget.anchor_layout_id, slotId, area);
            if (!deleted) {
                showToast('替换目标删除失败，已停止放置', 'error');
                return null;
            }
        }

        if (sortOrder === null || Number.isNaN(sortOrder)) {
            sortOrder = getNextSlotSortOrder(slotId);
        }

        const exclusive = placementTarget.exclusive !== undefined
            ? !!placementTarget.exclusive
            : (mode === 'replace' && isExclusiveSlot(slotId, normalizedWidget.code)) || normalizedWidget.exclusive || isExclusiveSlot(slotId, normalizedWidget.code);

        return saveWidget({
            area,
            slotId,
            widgetData: normalizedWidget,
            sortOrder,
            exclusive,
            switchToPreview: true,
        });
    }

    function registerWidgetLibraryItem(item) {
        if (!item || item.__themeEditorWidgetBound) {
            return;
        }
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragend', handleDragEnd);
        item.__themeEditorWidgetBound = true;
        scheduleFitWidgetPreviews();
    }

    Object.assign(EditorApi, {
        apiJson,
        buildTypedEditorContext,
        loadScopedWorkspace,
        queueScopedChanges,
        refreshPreview,
        getScopeIdentity: () => state.scopeIdentity ? { ...state.scopeIdentity } : null,
        getLegacyScope: () => legacyStorageScopeForIdentity(state.scopeIdentity),
        deselectArea,
        selectArea,
        filterWidgetsByArea,
        getWidgetPlacementContext,
        getWidgetAiContext: getThemeWidgetAiContext,
        placeWidgetFromProvider,
        registerWidgetLibraryItem,
        publishEmbeddedLayout,
    });
    window.addEventListener('message', handleDashboardSaveCloseMessage);
    registerThemeWidgetAiContextProvider();

    // ==================== 版本控制功能 ====================

    function versionIdEquals(left, right) {
        if (left === null || left === undefined || right === null || right === undefined) {
            return false;
        }
        return String(left) === String(right);
    }

    function setVersionPanelStatus(message, options = {}) {
        const versionList = document.getElementById('versionList');
        const currentVersionDisplay = document.getElementById('currentVersionDisplay');
        const text = message || '版本历史加载失败';

        if (currentVersionDisplay && options.updateCurrent !== false) {
            currentVersionDisplay.textContent = text;
        }

        if (versionList) {
            const tone = options.error ? ' data-tone="danger"' : '';
            versionList.innerHTML = `<div class="version-item" data-state="empty"${tone}>${escapeHtml(text)}</div>`;
        }
    }

    function resetVersionState() {
        state.versions = [];
        state.currentVersionId = null;
        state.publishedVersionId = null;
    }

    /**
     * 加载版本列表
     */
    async function loadVersions(options = {}) {
        const notifyOnError = options.notifyOnError === true;

        if (!state.themeId) {
            resetVersionState();
            setVersionPanelStatus('请选择主题后查看版本历史', { error: true });
            return;
        }

        setVersionPanelStatus('加载中...', { updateCurrent: state.versions.length === 0 });

        try {
            const url = new URL(config.apiVersions, window.location.origin);
            Object.entries(buildLayoutVersionIdentityPayload()).forEach(([key, value]) => {
                if (value !== undefined && value !== null && String(value) !== '') {
                    url.searchParams.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
                }
            });
            url.searchParams.set('limit', '20');

            const result = await apiJson(url.toString());

            if (!result.success || !result.data) {
                const message = result.message || '版本历史加载失败';
                resetVersionState();
                setVersionPanelStatus(message, { error: true });
                if (notifyOnError) {
                    showToast(message, 'error');
                }
                return;
            }

            state.versions = Array.isArray(result.data.versions) ? result.data.versions : [];
            state.currentVersionId = result.data.current_version_id;
            state.publishedVersionId = result.data.published_version_id;

            // 更新版本面板 UI
            renderVersionPanel();
        } catch (error) {
            console.error('[ThemeEditor] Load versions error:', error);
            const message = error && error.message
                ? `版本历史加载失败：${error.message}`
                : '版本历史加载失败';
            resetVersionState();
            setVersionPanelStatus(message, { error: true });
            if (notifyOnError) {
                showToast(message, 'error');
            }
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
            const currentVersion = state.versions.find(v => versionIdEquals(v.version_id, state.currentVersionId));
            currentVersionDisplay.textContent = currentVersion ? currentVersion.display_name : '无版本';
        }

        // 渲染版本列表
        if (state.versions.length === 0) {
            versionList.innerHTML = '<div class="version-item" data-state="empty">暂无版本记录</div>';
            return;
        }

        let html = '';
        for (const version of state.versions) {
            const safeVersionId = parseInt(version.version_id || '', 10);
            if (!Number.isFinite(safeVersionId) || safeVersionId <= 0) {
                continue;
            }
            version.version_id = safeVersionId;
            const isCurrent = versionIdEquals(version.version_id, state.currentVersionId);
            const isPublished = versionIdEquals(version.version_id, state.publishedVersionId);
            const isAutoBackup = version.is_auto_backup;

            html += `
                <div class="version-item"
                     data-state="${isCurrent ? 'current' : 'idle'}"
                     data-kind="${isAutoBackup ? 'backup' : 'manual'}"
                     data-version-id="${version.version_id}">
                    <div class="version-info">
                        <span class="version-name">
                            ${isAutoBackup ? iconSvg('history') : iconSvg('tag')}
                            ${escapeHtml(version.display_name)}
                        </span>
                        <span class="version-badges">
                            ${isCurrent ? '<span class="w-badge" data-tone="primary">当前</span>' : ''}
                            ${isPublished ? '<span class="w-badge" data-tone="success">已发布</span>' : ''}
                            ${isAutoBackup ? '<span class="w-badge" data-tone="neutral">备份</span>' : ''}
                        </span>
                    </div>
                    <div class="version-meta">
                        <span class="version-date">${formatDate(version.created_at)}</span>
                        ${version.description ? `<span class="version-desc">${escapeHtml(version.description)}</span>` : ''}
                    </div>
                    <div class="version-actions">
                        <button class="w-button" data-tone="neutral" data-variant="outline" data-size="sm" type="button" data-version-action="preview" data-version-id="${escapeHtml(version.version_id)}" title="Preview this version">
                            ${iconSvg('eye')} 预览
                        </button>
                        ${!isCurrent ? `<button class="w-button" data-tone="primary" data-variant="outline" data-size="sm" type="button" data-version-action="switch" data-version-id="${escapeHtml(version.version_id)}" title="Restore draft to this version">回撤
                            ${iconSvg('refresh')}
                        </button>` : ''}
                        ${!isCurrent && !isPublished ? `<button class="w-button" data-tone="danger" data-variant="outline" data-size="sm" type="button" data-version-action="delete" data-version-id="${escapeHtml(version.version_id)}">
                            ${iconSvg('trash')}
                        </button>` : ''}
                    </div>
                </div>
            `;
        }

        versionList.innerHTML = html;
    }

    async function previewVersion(versionId) {
        if (!state.themeId || !versionId || !elements.previewFrame) {
            return;
        }

        const version = state.versions.find(item => Number(item.version_id) === Number(versionId));
        const previewOverrides = {
            preview_mode: 'version',
            version_id: versionId,
            status: 'draft',
            editor_mode: '1',
            _t: Date.now(),
        };

        switchPreviewView('preview');
        clearSlotSelection();
        deselectArea();
        deselectWidget();
        const previewUrl = await setLayoutPreviewSource(previewOverrides);
        if (!previewUrl) {
            return;
        }
        await fetchLayoutSlots(previewOverrides);
        showToast(version ? `正在预览版本：${version.display_name}` : '正在预览版本', 'info');
    }

    /**
     * 回撤到指定版本
     */
    async function switchToVersion(versionId) {
        if (!state.themeId || !versionId) {
            return;
        }

        const confirmed = await showCustomConfirm(
            '回撤版本',
            '确定要回撤到此版本吗？当前工作区的未保存修改将被替换。',
            '确认回撤',
            '取消'
        );

        if (!confirmed) {
            return;
        }

        try {
            showToast('正在回撤版本...', 'info');

            const result = await apiJson(config.apiSwitchVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload({
                    version_id: versionId,
                })),
            });

            if (result.success) {
                navigateEditorShell({
                    page_type: state.pageType,
                    version_id: null,
                });
                return;
                showToast(result.message || '已回撤版本', 'success');

                // 刷新版本列表
                await loadVersions();

                // 刷新预览
                refreshPreview();
            } else {
                showToast(result.message || '回撤失败', 'error');
            }
        } catch (error) {
            console.error('[ThemeEditor] Switch version error:', error);
            showToast('回撤版本失败：' + error.message, 'error');
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
            const result = await apiJson(config.apiDeleteVersion, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload({
                    version_id: versionId,
                })),
            });

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
        const root = panel?.closest('[data-w-component~="popover"]');
        if (!(panel instanceof HTMLElement) || !(root instanceof HTMLElement)) return false;
        getEditorUi().mount(root);
        const popover = getEditorUi().get(root, 'popover');
        if (!popover) return false;
        return panel.hidden ? popover.open() : popover.close('editor-api');
    }

    /**
     * 显示提示输入对话框
     */
    async function showPromptDialog(title, message, defaultValue = '', confirmText = '确定', cancelText = '取消') {
        const result = await getEditorUi().dialog.prompt(String(message ?? ''), {
            title: String(title ?? ''),
            confirmLabel: String(confirmText),
            cancelLabel: String(cancelText),
            size: 'sm',
            field: {
                type: 'text',
                value: String(defaultValue ?? ''),
                placeholder: '版本名称',
                autocomplete: 'off',
            },
        });
        return result.confirmed ? String(result.value ?? '').trim() : null;
    }

    /**
     * 格式化日期
     */
    function renderEditorLockOverlay(lockInfo, mode = 'conflict') {
        if (!elements.container) {
            return;
        }

        const host = elements.container.querySelector('.editor-main')
            || elements.container.querySelector('.editor-preview-area')
            || elements.container;
        if (host instanceof HTMLElement) {
            const hostStyle = window.getComputedStyle(host);
            if (hostStyle.position === 'static') {
                host.style.position = 'relative';
            }
        }

        let overlay = document.getElementById('themeEditorLockOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'themeEditorLockOverlay';
            overlay.className = 'w-theme-editor-lock';
            host.appendChild(overlay);
        } else if (overlay.parentElement !== host) {
            host.appendChild(overlay);
        }

        const unavailable = mode === 'unavailable';
        const pending = mode === 'pending';
        const userName = lockInfo && lockInfo.user_name ? lockInfo.user_name : '其他用户';
        const title = pending
            ? translateUiText('正在确认编辑权限')
            : (unavailable ? translateUiText('无法确认编辑权限') : translateUiText('当前页面正在被编辑'));
        const message = pending
            ? translateUiText('正在获取当前页面的编辑锁，请稍候。')
            : (unavailable
                ? translateUiText('编辑锁服务暂时不可用，为避免覆盖其他管理员的修改，当前页面保持只读。工具栏仍可切换语言预览。')
                : `${escapeHtml(userName)} ${escapeHtml(translateUiText('正在编辑当前主题页面。为了避免互相覆盖，当前会话已被锁定为只读等待状态。'))}`);
        overlay.innerHTML = `
            <section class="w-card w-theme-editor-lock__card" role="status" aria-live="polite">
                <div class="w-card__body w-stack">
                <h3 class="w-card__title">${escapeHtml(title)}</h3>
                <p class="w-text">${mode === 'conflict' ? message : escapeHtml(message)}</p>
                <p class="w-text" data-tone="muted">
                    ${escapeHtml(pending
                        ? translateUiText('锁定成功后将自动进入编辑。')
                        : translateUiText('确认锁已释放或服务恢复后，刷新页面重试。'))}
                </p>
                <div class="w-cluster" data-justify="end" ${pending ? 'hidden' : ''}>
                    <button type="button" id="themeEditorLockReload" class="w-button" data-tone="primary">刷新重试</button>
                </div>
                </div>
            </section>
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
            const result = await apiJson(config.apiUpdateActivity, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildLayoutVersionIdentityPayload()),
            });
            if (!(result && result.success)) {
                state.lockHeld = false;
                stopLockHeartbeat();
                renderEditorLockOverlay(state.lockConflictInfo);
                return false;
            }

            return true;
        } catch (error) {
            console.warn('[ThemeEditor] Lock heartbeat failed:', error);
            state.lockHeld = false;
            state.lockConflictInfo = null;
            stopLockHeartbeat();
            renderEditorLockOverlay(null, 'unavailable');
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

    async function releaseEditorLockPayload(payload, options = {}) {
        if (!config.apiReleaseLock || !payload || !(parseInt(payload.theme_id || 0, 10) > 0)) {
            return false;
        }

        const keepalive = options.keepalive === true;
        const requestBody = JSON.stringify(payload);

        try {
            if (keepalive) {
                apiJson(config.apiReleaseLock, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: requestBody,
                    keepalive: true,
                });
                return true;
            }

            const result = await apiJson(config.apiReleaseLock, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: requestBody,
            });
            return !(result && result.success === false);
        } catch (error) {
            console.warn('[ThemeEditor] Release lock failed:', error);
            return false;
        }
    }

    async function releaseCurrentEditorLock(options = {}) {
        if (!state.lockHeld || !state.themeId) {
            return false;
        }
        const released = await releaseEditorLockPayload(buildLayoutVersionIdentityPayload(), options);
        if (released) {
            state.lockHeld = false;
            stopLockHeartbeat();
        }
        return released;
    }

    async function acquireEditorLockPayload(payload) {
        if (!config.apiCheckLock || !payload || !(parseInt(payload.theme_id || 0, 10) > 0)) {
            return {
                success: false,
                message: translateUiText('编辑锁服务不可用'),
            };
        }

        try {
            return await apiJson(config.apiCheckLock, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });
        } catch (error) {
            console.warn('[ThemeEditor] Failed to acquire editor lock:', error);
            return {
                success: false,
                message: error?.message || translateUiText('编辑锁服务暂时不可用'),
                unavailable: true,
            };
        }
    }

    async function initializeEditorLock() {
        if (!state.themeId) {
            return false;
        }
        renderEditorLockOverlay(null, 'pending');
        let result = await acquireEditorLockPayload(buildLayoutVersionIdentityPayload());
        if (!(result && result.success) && result?.unavailable) {
            await new Promise((resolve) => setTimeout(resolve, 400));
            if (state.scopeIdentity) {
                result = await acquireEditorLockPayload(buildLayoutVersionIdentityPayload());
            }
        }

        if (result && result.success) {
            state.lockHeld = true;
            state.lockConflictInfo = null;
            clearEditorLockOverlay();
            startLockHeartbeat();
            bindLockLifecycle();
            return true;
        }

        state.lockHeld = false;
        stopLockHeartbeat();
        state.lockConflictInfo = (result && result.data && result.data.lock_info) ? result.data.lock_info : null;
        renderEditorLockOverlay(state.lockConflictInfo, result?.unavailable ? 'unavailable' : 'conflict');
        showToast(result?.message || '当前页面正被其他用户编辑', 'warning');
        return false;
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

    Object.assign(EditorApi, {
        scrollToSlot,
        switchPreviewStatus,
        openPreview,
        previewVersion,
        openPublishedPreview,
        getPreviewStatus,
        switchToVersion,
        deleteVersion,
        toggleVersionPanel,
        loadVersions,
    });

    // 初始化
    document.addEventListener('DOMContentLoaded', init);
})();

/* Weline UI source: js/theme-disk-appearance.js */
/**
 * Theme disk appearance (整盘) — additive overlay for the classic ThemeEditor.
 * Does not touch widget drag/drop / layout editing.
 */
(function () {
    'use strict';

    function ui() {
        const current = window.Weline?.UI;
        if (!current) throw new Error('Weline.UI must be loaded before Theme Disk Appearance.');
        return current;
    }

    function toast(message, type) {
        const tone = type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info';
        return ui().toast.show(String(message ?? ''), { tone, duration: 3000 });
    }

    function looksLikeColorValue(value) {
        return /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(String(value || '').trim());
    }

    /** <input type="color"> 只接受 #rrggbb；3 位 hex 需展开，否则赋值会抛错并中断整表渲染。 */
    function toColorInputValue(value) {
        const text = String(value || '').trim();
        const m3 = text.match(/^#([0-9a-f]{3})$/i);
        if (m3) {
            const [r, g, b] = m3[1].split('');
            return `#${r}${r}${g}${g}${b}${b}`.toLowerCase();
        }
        const m6 = text.match(/^#([0-9a-f]{6})([0-9a-f]{2})?$/i);
        if (m6) {
            return `#${m6[1]}`.toLowerCase();
        }
        return '#000000';
    }

    function collectInheritTokens(panel, disk) {
        const tokens = {};
        const list = Array.isArray(disk && disk.tokens) ? disk.tokens : [];
        list.forEach((token) => {
            if (!token || typeof token !== 'object') return;
            const name = String(token.variable_name || token.name || '').trim();
            if (!name.startsWith('--')) return;
            if (panel === 'color') {
                const role = String(token.role || token.palette_role || '').toLowerCase();
                const lateSafe = role === 'brand' || role === 'functional'
                    || /primary|accent|secondary|success|warning|danger|info|link/.test(name);
                if (!lateSafe) return;
            }
            tokens[name] = String(token.default_value ?? token.value ?? '');
        });
        return tokens;
    }

    async function requestJson(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const headers = {
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            ...(options.headers || {}),
        };
        let body = options.body;
        if (body && typeof body === 'object' && !(body instanceof FormData) && !(body instanceof URLSearchParams)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }
        const editor = window.Weline?.Theme?.Editor;
        if (!editor || typeof editor.apiJson !== 'function') {
            throw new Error('Weline.Theme.Editor API is unavailable.');
        }
        const payload = await editor.apiJson(url, {
            method,
            headers,
            body: method === 'GET' || method === 'HEAD' ? undefined : body,
        });
        if (payload && payload.success === false) {
            throw new Error(String(payload.message || '请求失败'));
        }
        return payload;
    }

    function boot() {
        const root = document.getElementById('themeEditor');
        const modalEl = document.getElementById('themeDiskAppearanceModal');
        if (!(root instanceof HTMLElement) || !(modalEl instanceof HTMLElement)) {
            return;
        }

        const panelSelect = modalEl.querySelector('[data-w-appearance-panel]');
        const disksEl = modalEl.querySelector('[data-w-appearance-disks]');
        const editorEl = modalEl.querySelector('[data-w-appearance-editor]');
        const emptyEl = modalEl.querySelector('[data-w-appearance-empty]');
        const footerEl = modalEl.querySelector('[data-w-appearance-footer]');
        const editorTitleEl = modalEl.querySelector('[data-w-appearance-editor-title]');
        const nameInput = modalEl.querySelector('[data-w-appearance-name]');
        const tokensEl = modalEl.querySelector('[data-w-appearance-tokens]');
        const tokenSearchEl = modalEl.querySelector('[data-w-appearance-token-search]');
        const tokenCountEl = modalEl.querySelector('[data-w-appearance-token-count]');

        const openPanel = () => {
            return ui().drawer.open(modalEl);
        };

        const setEditorVisible = (visible, title) => {
            if (editorEl instanceof HTMLElement) editorEl.hidden = !visible;
            if (emptyEl instanceof HTMLElement) emptyEl.hidden = !!visible;
            if (footerEl instanceof HTMLElement) footerEl.hidden = !visible;
            if (visible && editorTitleEl instanceof HTMLElement && title) {
                editorTitleEl.textContent = title;
            }
        };

        let appearanceState = null;
        let scopedWorkspace = null;
        const draft = { panel: 'color', base_file: '', disk_key: '', tokens: {}, mode: '' };
        let previewTokenTimer = 0;

        const getPreviewDocument = () => {
            const frame = document.getElementById('previewFrame');
            if (!(frame instanceof HTMLIFrameElement)) return null;
            try {
                return frame.contentDocument || frame.contentWindow?.document || null;
            } catch (_error) {
                return null;
            }
        };

        /** 编辑中即时把 Token 写进预览 iframe，不等待保存/整页刷新。 */
        const applyAppearancePreviewTokens = (tokens) => {
            const doc = getPreviewDocument();
            if (!doc || !doc.documentElement) return false;
            const map = tokens && typeof tokens === 'object' ? tokens : {};
            const root = doc.documentElement;
            const lines = [];
            Object.entries(map).forEach(([name, value]) => {
                const token = String(name || '').trim();
                const cssValue = String(value ?? '').trim();
                if (!token.startsWith('--') || !cssValue) return;
                root.style.setProperty(token, cssValue);
                lines.push(`  ${token}: ${cssValue};`);
            });
            let styleEl = doc.querySelector('style[data-theme-scoped-preview-appearance]');
            if (!(styleEl instanceof HTMLStyleElement)) {
                styleEl = doc.createElement('style');
                styleEl.setAttribute('data-theme-scoped-preview-appearance', '1');
                (doc.head || doc.documentElement).appendChild(styleEl);
            }
            styleEl.textContent = lines.length
                ? `:root {\n${lines.join('\n')}\n}`
                : '';
            return true;
        };

        const scheduleAppearancePreviewTokens = () => {
            window.clearTimeout(previewTokenTimer);
            previewTokenTimer = window.setTimeout(() => {
                applyAppearancePreviewTokens(draft.tokens);
            }, 80);
        };

        const refreshLayoutPreview = () => {
            const editor = window.Weline?.Theme?.Editor;
            if (typeof editor?.refreshPreview === 'function') {
                editor.refreshPreview();
                return;
            }
            applyAppearancePreviewTokens(draft.tokens);
        };

        const identity = () => {
            const themeSelect = document.getElementById('themeSelect');
            const areaSelect = document.getElementById('editorAreaSelect');
            const editor = window.Weline?.Theme?.Editor;
            const diskScope = String(editor?.getLegacyScope?.() || root.dataset.scope || 'default.default.default').trim();
            return {
                theme_id: Number(themeSelect && themeSelect.value ? themeSelect.value : root.dataset.themeId || 0),
                editor_area: String(areaSelect && areaSelect.value ? areaSelect.value : root.dataset.editorArea || 'frontend'),
                scope: diskScope,
                editor_context: JSON.stringify(editor?.buildTypedEditorContext?.('appearance') || {}),
            };
        };

        const pointerSegment = (value) => String(value ?? '').replace(/~/g, '~0').replace(/\//g, '~1');

        const queueAppearanceChanges = async (changes, summary) => {
            const editor = window.Weline?.Theme?.Editor;
            if (!editor || typeof editor.queueScopedChanges !== 'function') {
                throw new Error('主题 Scope 工作区不可用');
            }
            scopedWorkspace = await editor.queueScopedChanges('appearance', changes, { summary });
            return scopedWorkspace;
        };

        const ownedPaths = () => new Set(Array.isArray(scopedWorkspace?.owned_paths)
            ? scopedWorkspace.owned_paths
            : []);

        const ownershipBadge = (path) => {
            const owned = ownedPaths().has(path);
            const badge = document.createElement('span');
            badge.className = 'w-badge theme-config-ownership__badge';
            badge.dataset.owned = owned ? 'true' : 'false';
            badge.textContent = owned ? '本级修改' : '继承值';
            return badge;
        };

        const apiUrl = (key, query) => {
            const base = root.dataset[key] || '';
            if (!base) return '';
            const url = new URL(base, window.location.origin);
            Object.entries(query || {}).forEach(([k, v]) => {
                if (v === undefined || v === null || v === '') return;
                url.searchParams.set(k, String(v));
            });
            return url.pathname + url.search;
        };

        const findCatalogDisk = (panel, key) => {
            const disks = appearanceState?.catalog?.panels?.[panel]?.disks;
            if (!Array.isArray(disks)) return null;
            const needle = String(key || '').replace(/^_+/, '');
            return disks.find((item) => String(item.key || '').replace(/^_+/, '') === needle) || null;
        };

        const loadDiskTokens = async (panel, disk) => {
            if (!disk || typeof disk !== 'object') {
                return {};
            }
            if (Array.isArray(disk.tokens) && disk.tokens.length > 0) {
                return collectInheritTokens(panel, disk);
            }
            const ref = String(disk.ref || '').trim();
            if (!ref) {
                return {};
            }
            const url = apiUrl('apiThemeDiskTokens', {
                ...identity(),
                panel,
                ref,
            });
            const loaded = await requestJson(url, { method: 'GET' });
            const tokensJson = loaded?.data?.tokens_json;
            if (typeof tokensJson === 'string' && tokensJson.trim() !== '') {
                try {
                    const parsed = JSON.parse(tokensJson);
                    if (Array.isArray(parsed)) {
                        disk.tokens = parsed;
                    }
                } catch (_error) {
                    // ignore malformed token payload
                }
            } else if (Array.isArray(loaded?.data?.tokens)) {
                disk.tokens = loaded.data.tokens;
            }
            return collectInheritTokens(panel, disk);
        };

        /** Meta 只存 delta：编辑时用「原生可继承基线 + 已存 delta」合成完整可编辑表。 */
        const resolveEditableTokens = async (panel, baseFile, deltaTokens) => {
            const baseDisk = findCatalogDisk(panel, baseFile);
            const base = baseDisk ? await loadDiskTokens(panel, baseDisk) : {};
            const delta = deltaTokens && typeof deltaTokens === 'object' ? deltaTokens : {};
            return { ...base, ...delta };
        };

        const makeButton = (label, tone = 'neutral', variant = 'outline') => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-button';
            btn.dataset.tone = tone;
            btn.dataset.size = 'sm';
            if (variant) btn.dataset.variant = variant;
            btn.textContent = label;
            return btn;
        };

        const tokenSearchQuery = () => {
            if (!(tokenSearchEl instanceof HTMLInputElement)) return '';
            return String(tokenSearchEl.value || '').trim().toLowerCase();
        };

        const matchesTokenSearch = (name, value, query) => {
            if (!query) return true;
            return String(name || '').toLowerCase().includes(query)
                || String(value || '').toLowerCase().includes(query);
        };

        const updateTokenCount = (shown, total, filtered) => {
            if (!(tokenCountEl instanceof HTMLElement)) return;
            if (!total) {
                tokenCountEl.hidden = true;
                tokenCountEl.textContent = '';
                return;
            }
            tokenCountEl.hidden = false;
            tokenCountEl.textContent = filtered
                ? `显示 ${shown} / ${total}`
                : `${total} 个变量`;
        };

        const renderTokenEditor = () => {
            if (!(tokensEl instanceof HTMLElement)) return;
            tokensEl.replaceChildren();
            const query = tokenSearchQuery();
            const entries = Object.entries(draft.tokens);
            if (!entries.length) {
                const empty = document.createElement('p');
                empty.className = 'w-theme-disk-empty';
                empty.textContent = draft.mode === 'custom'
                    ? '未找到可编辑 Token（请确认该盘的 base_file 仍存在于原生盘中）'
                    : (draft.panel === 'color'
                        ? '该原生盘没有可继承的品牌/功能色 Token'
                        : '该原生盘没有可继承的变量 Token');
                tokensEl.append(empty);
                updateTokenCount(0, 0, false);
                return;
            }
            const matched = query
                ? entries.filter(([name, value]) => matchesTokenSearch(name, value, query))
                : entries;
            if (!matched.length) {
                const empty = document.createElement('p');
                empty.className = 'w-theme-disk-empty';
                empty.textContent = '没有匹配的变量';
                tokensEl.append(empty);
                updateTokenCount(0, entries.length, true);
                return;
            }
            matched.forEach(([name, value]) => {
                const card = document.createElement('div');
                card.className = 'w-theme-disk-token';
                card.dataset.tokenName = name;
                const label = document.createElement('div');
                label.className = 'w-theme-disk-token__label';
                label.textContent = name;
                const controls = document.createElement('div');
                controls.className = 'w-theme-disk-token__controls';
                const text = String(value || '');
                const textInput = document.createElement('input');
                textInput.className = 'w-input w-theme-disk-token__value';
                textInput.dataset.size = 'sm';
                textInput.type = 'text';
                textInput.value = text;
                textInput.addEventListener('input', () => {
                    draft.tokens[name] = textInput.value;
                    if (colorInput && looksLikeColorValue(textInput.value)) {
                        colorInput.value = toColorInputValue(textInput.value);
                    }
                    scheduleAppearancePreviewTokens();
                });
                let colorInput = null;
                if (looksLikeColorValue(text) || draft.panel === 'color') {
                    colorInput = document.createElement('input');
                    colorInput.className = 'w-theme-disk-token__color';
                    colorInput.type = 'color';
                    colorInput.value = toColorInputValue(text);
                    colorInput.title = name;
                    colorInput.addEventListener('input', () => {
                        draft.tokens[name] = colorInput.value;
                        textInput.value = colorInput.value;
                        scheduleAppearancePreviewTokens();
                    });
                    controls.append(colorInput);
                }
                controls.append(textInput);
                card.append(label, controls);
                tokensEl.append(card);
            });
            updateTokenCount(matched.length, entries.length, !!query);
            scheduleAppearancePreviewTokens();
        };

        const startInheritEdit = async (disk) => {
            const panel = panelSelect instanceof HTMLSelectElement && panelSelect.value
                ? panelSelect.value
                : draft.panel;
            draft.panel = panel;
            draft.mode = 'inherit';
            draft.base_file = String(disk.key || '');
            draft.disk_key = '';
            draft.tokens = await loadDiskTokens(panel, disk);
            if (nameInput instanceof HTMLInputElement) {
                nameInput.value = `${disk.name || disk.key}-自定义`;
            }
            renderTokenEditor();
            setEditorVisible(true, '继承编辑 · 另存为我的盘');
            renderAppearance();
        };

        const startCustomEdit = async (disk) => {
            const panel = panelSelect instanceof HTMLSelectElement && panelSelect.value
                ? panelSelect.value
                : draft.panel;
            draft.panel = panel;
            draft.mode = 'custom';
            draft.base_file = String(disk.base_file || '');
            draft.disk_key = String(disk.disk_key || '');
            draft.tokens = await resolveEditableTokens(panel, draft.base_file, disk.tokens || {});
            if (nameInput instanceof HTMLInputElement) {
                nameInput.value = String(disk.name || disk.disk_key || '');
            }
            renderTokenEditor();
            setEditorVisible(true, '编辑我的盘');
            renderAppearance();
            if (!Object.keys(draft.tokens).length) {
                toast('该我的盘没有可编辑 Token，请检查继承来源原生盘是否仍存在', 'error');
            }
        };

        /** 当前已选用的盘直接展开右侧编辑，无需再点「编辑 / 继承编辑」。 */
        const openActiveEditor = async () => {
            if (!appearanceState) {
                setEditorVisible(false);
                return;
            }
            const panelState = (appearanceState.state?.panels || []).find((item) => item.panel === draft.panel)
                || { active: '', custom: [] };
            const active = String(panelState.active || '').trim();
            if (!active) {
                setEditorVisible(false);
                return;
            }
            if (active.startsWith('custom:')) {
                const customs = Array.isArray(panelState.custom) ? panelState.custom : [];
                const current = customs.find((item) =>
                    String(item.ref || '') === active
                    || `custom:${String(item.disk_key || '')}` === active);
                if (current) {
                    await startCustomEdit(current);
                    return;
                }
                setEditorVisible(false);
                return;
            }
            const panelCatalog = appearanceState.catalog?.panels?.[draft.panel] || { disks: [] };
            const native = (panelCatalog.disks || []).find((item) => String(item.ref || '') === active);
            if (native && native.palette_role !== 'mode') {
                await startInheritEdit(native);
                return;
            }
            setEditorVisible(false);
        };

        const applyScopedDraft = () => {
            const payload = scopedWorkspace?.draft_payload;
            if (!appearanceState || !payload || typeof payload !== 'object') return;
            const active = payload.tokens && typeof payload.tokens === 'object' ? payload.tokens : {};
            const disks = payload.disks && typeof payload.disks === 'object' ? payload.disks : {};
            const panelStates = Array.isArray(appearanceState.state?.panels)
                ? appearanceState.state.panels
                : [];
            const panelCodes = new Set([
                ...Object.keys(appearanceState.catalog?.panels || {}),
                ...Object.keys(active),
                ...Object.keys(disks),
            ]);
            appearanceState.state = appearanceState.state || {};
            appearanceState.state.panels = Array.from(panelCodes).map((panel) => {
                const previous = panelStates.find((item) => item.panel === panel) || {};
                const scopedDisks = disks[panel] && typeof disks[panel] === 'object' ? disks[panel] : {};
                const custom = Object.entries(scopedDisks)
                    .filter(([, item]) => item && typeof item === 'object')
                    .map(([diskKey, item]) => ({
                        ...item,
                        disk_key: diskKey,
                        ref: `custom:${diskKey}`,
                    }));
                return {
                    ...previous,
                    panel,
                    active: Object.prototype.hasOwnProperty.call(active, panel)
                        ? String(active[panel] ?? '')
                        : String(previous.active || ''),
                    custom,
                };
            });
        };

        const addPanelOwnership = (container, panel) => {
            const path = `/tokens/${pointerSegment(panel)}`;
            const row = document.createElement('div');
            row.className = 'theme-config-ownership w-theme-disk-scope-ownership';
            row.append(ownershipBadge(path));
            if (ownedPaths().has(path)) {
                const inherit = makeButton('恢复继承', 'neutral', 'link');
                inherit.addEventListener('click', async () => {
                    try {
                        await queueAppearanceChanges([{ op: 'inherit', path }], 'appearance_active_inherit');
                        applyScopedDraft();
                        renderAppearance();
                        toast('已恢复继承（发布后生效）', 'success');
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                row.append(inherit);
            }
            container.append(row);
        };

        const renderAppearance = () => {
            if (!appearanceState || !(panelSelect instanceof HTMLSelectElement) || !(disksEl instanceof HTMLElement)) return;
            const panels = appearanceState.catalog?.panels || {};
            const codes = Object.keys(panels);
            if (!codes.includes(draft.panel) && codes.length) {
                draft.panel = codes.includes('color') ? 'color' : codes[0];
            }
            panelSelect.replaceChildren();
            codes.forEach((code) => {
                const opt = document.createElement('option');
                opt.value = code;
                opt.textContent = code;
                if (code === draft.panel) opt.selected = true;
                panelSelect.append(opt);
            });

            disksEl.replaceChildren();
            const panel = panels[draft.panel] || { disks: [] };
            const panelState = (appearanceState.state?.panels || []).find((item) => item.panel === draft.panel)
                || { active: '', custom: [] };

            addPanelOwnership(disksEl, draft.panel);

            const nativeTitle = document.createElement('div');
            nativeTitle.className = 'w-theme-disk-section-title';
            nativeTitle.textContent = '原生盘';
            disksEl.append(nativeTitle);

            (panel.disks || []).forEach((disk) => {
                if (disk.palette_role === 'mode') {
                    const note = document.createElement('p');
                    note.className = 'w-theme-disk-mode-note';
                    note.textContent = `${disk.name || disk.key}（模式层，双载，不可单选替换）`;
                    disksEl.append(note);
                    return;
                }
                const row = document.createElement('div');
                row.className = 'w-theme-disk-row';
                if (panelState.active === disk.ref) row.classList.add('is-active');
                if (draft.mode === 'inherit' && draft.base_file === String(disk.key || '')) {
                    row.classList.add('is-editing');
                }
                const label = document.createElement('span');
                label.className = 'w-theme-disk-row__name';
                label.textContent = disk.name || disk.key;
                const actions = document.createElement('div');
                actions.className = 'w-theme-disk-row__actions';
                const selectBtn = makeButton(
                    panelState.active === disk.ref ? '已选用' : '选用',
                    'primary',
                );
                selectBtn.disabled = panelState.active === disk.ref;
                selectBtn.addEventListener('click', async () => {
                    try {
                        await requestJson(root.dataset.apiDiskSelect, {
                            method: 'POST',
                            body: { ...identity(), panel: draft.panel, ref: disk.ref },
                        });
                        await queueAppearanceChanges([{
                            op: 'set',
                            path: `/tokens/${pointerSegment(draft.panel)}`,
                            value: disk.ref,
                        }], 'appearance_disk_selected');
                        toast('色盘选择已保存', 'success');
                        await loadAppearance();
                        refreshLayoutPreview();
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                const inheritBtn = makeButton('继承编辑');
                inheritBtn.addEventListener('click', () => startInheritEdit(disk));
                actions.append(selectBtn, inheritBtn);
                row.append(label, actions);
                disksEl.append(row);
            });

            const customTitle = document.createElement('div');
            customTitle.className = 'w-theme-disk-section-title';
            customTitle.textContent = '我的盘';
            disksEl.append(customTitle);
            const customs = Array.isArray(panelState.custom) ? panelState.custom : [];
            if (!customs.length) {
                const empty = document.createElement('p');
                empty.className = 'w-theme-disk-empty';
                empty.textContent = '暂无我的盘';
                disksEl.append(empty);
                return;
            }
            customs.forEach((disk) => {
                const row = document.createElement('div');
                row.className = 'w-theme-disk-row';
                if (panelState.active === disk.ref) row.classList.add('is-active');
                if (draft.mode === 'custom' && draft.disk_key === String(disk.disk_key || '')) {
                    row.classList.add('is-editing');
                }
                const label = document.createElement('span');
                label.className = 'w-theme-disk-row__name';
                label.textContent = disk.name || disk.disk_key;
                const diskPath = `/disks/${pointerSegment(draft.panel)}/${pointerSegment(disk.disk_key)}`;
                label.append(ownershipBadge(diskPath));
                const actions = document.createElement('div');
                actions.className = 'w-theme-disk-row__actions';
                const selectBtn = makeButton(
                    panelState.active === disk.ref ? '已选用' : '选用',
                    'primary',
                );
                selectBtn.disabled = panelState.active === disk.ref;
                selectBtn.addEventListener('click', async () => {
                    try {
                        await requestJson(root.dataset.apiDiskSelect, {
                            method: 'POST',
                            body: { ...identity(), panel: draft.panel, ref: disk.ref },
                        });
                        await queueAppearanceChanges([{
                            op: 'set',
                            path: `/tokens/${pointerSegment(draft.panel)}`,
                            value: disk.ref,
                        }], 'appearance_disk_selected');
                        toast('色盘选择已保存', 'success');
                        await loadAppearance();
                        refreshLayoutPreview();
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                const editBtn = makeButton('编辑');
                editBtn.addEventListener('click', () => startCustomEdit(disk));
                const deleteBtn = makeButton('删除', 'danger');
                deleteBtn.addEventListener('click', async () => {
                    const confirmed = await ui().dialog.confirm('删除后无法恢复该自定义主题盘。', {
                        title: '删除我的盘',
                        confirmLabel: '删除',
                        cancelLabel: '取消',
                        dangerous: true,
                    });
                    if (!confirmed) return;
                    try {
                        await requestJson(root.dataset.apiDiskDelete, {
                            method: 'POST',
                            body: { ...identity(), panel: draft.panel, disk_key: disk.disk_key },
                        });
                        const changes = [{
                            op: 'set',
                            path: diskPath,
                            value: null,
                        }];
                        if (panelState.active === disk.ref) {
                            changes.push({
                                op: 'set',
                                path: `/tokens/${pointerSegment(draft.panel)}`,
                                value: '',
                            });
                        }
                        await queueAppearanceChanges(changes, 'appearance_disk_deleted');
                        toast('已删除我的盘', 'success');
                        await loadAppearance();
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                actions.append(selectBtn, editBtn, deleteBtn);
                if (ownedPaths().has(diskPath)) {
                    const inheritBtn = makeButton('恢复继承', 'neutral', 'link');
                    inheritBtn.addEventListener('click', async () => {
                        try {
                            await queueAppearanceChanges([{ op: 'inherit', path: diskPath }], 'appearance_disk_inherit');
                            applyScopedDraft();
                            renderAppearance();
                            toast('已恢复继承（发布后生效）', 'success');
                        } catch (error) {
                            toast(error instanceof Error ? error.message : String(error), 'error');
                        }
                    });
                    actions.append(inheritBtn);
                }
                row.append(label, actions);
                disksEl.append(row);
            });
        };

        const loadAppearance = async () => {
            const url = apiUrl('apiThemeTokens', identity());
            const loaded = await requestJson(url, { method: 'GET' });
            appearanceState = loaded?.data || loaded;
            const editor = window.Weline?.Theme?.Editor;
            scopedWorkspace = typeof editor?.loadScopedWorkspace === 'function'
                ? await editor.loadScopedWorkspace('appearance')
                : null;
            applyScopedDraft();
            draft.mode = '';
            draft.disk_key = '';
            draft.base_file = '';
            draft.tokens = {};
            setEditorVisible(false);
            renderAppearance();
            await openActiveEditor();
        };

        const saveAppearance = async (asNew) => {
            const name = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';
            if (!Object.keys(draft.tokens).length) {
                throw new Error('没有可保存的 Token，请先点「继承编辑」或「编辑」');
            }
            const endpoint = asNew ? root.dataset.apiDiskSaveAs : root.dataset.apiDiskSave;
            const result = await requestJson(endpoint, {
                method: 'POST',
                body: {
                    ...identity(),
                    panel: draft.panel,
                    name,
                    base_file: draft.base_file,
                    disk_key: asNew ? '' : draft.disk_key,
                    tokens: draft.tokens,
                },
            });
            draft.disk_key = String(result?.data?.disk_key || draft.disk_key || '');
            const savedKey = draft.disk_key;
            const baseDisk = draft.base_file ? findCatalogDisk(draft.panel, draft.base_file) : null;
            const baseTokens = baseDisk ? await loadDiskTokens(draft.panel, baseDisk) : {};
            const deltaTokens = Object.fromEntries(Object.entries(draft.tokens).filter(([token, value]) =>
                !Object.prototype.hasOwnProperty.call(baseTokens, token)
                    || String(baseTokens[token]) !== String(value)));
            await queueAppearanceChanges([
                {
                    op: 'set',
                    path: `/disks/${pointerSegment(draft.panel)}/${pointerSegment(savedKey)}`,
                    value: {
                        name: name || savedKey,
                        base_file: draft.base_file,
                        disk_kind: draft.panel === 'color' ? 'colors' : 'variables',
                        tokens: deltaTokens,
                    },
                },
                {
                    op: 'set',
                    path: `/tokens/${pointerSegment(draft.panel)}`,
                    value: `custom:${savedKey}`,
                },
            ], 'appearance_disk_saved');
            toast(String(result?.message || '主题盘已保存'), 'success');
            // loadAppearance → openActiveEditor：保存后继续展开当前已选用盘
            await loadAppearance();
            refreshLayoutPreview();
        };

        const openAndLoad = async () => {
            openPanel();
            try {
                await loadAppearance();
            } catch (error) {
                toast(error instanceof Error ? error.message : String(error), 'error');
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;
            const openHit = target.closest('#btnThemeDiskAppearance');
            if (openHit) {
                event.preventDefault();
                openAndLoad();
                return;
            }
            const action = target.closest('[data-w-appearance-action]');
            if (!(action instanceof HTMLButtonElement) || !modalEl.contains(action)) return;
            const kind = action.dataset.wAppearanceAction || '';
            if (kind !== 'save-as' && kind !== 'save') return;
            action.disabled = true;
            saveAppearance(kind === 'save-as')
                .catch((error) => toast(error instanceof Error ? error.message : String(error), 'error'))
                .finally(() => {
                    action.disabled = false;
                });
        });

        if (tokenSearchEl instanceof HTMLInputElement) {
            tokenSearchEl.addEventListener('input', () => {
                renderTokenEditor();
            });
        }

        if (panelSelect instanceof HTMLSelectElement) {
            panelSelect.addEventListener('change', () => {
                draft.panel = panelSelect.value || 'color';
                draft.base_file = '';
                draft.disk_key = '';
                draft.tokens = {};
                draft.mode = '';
                if (tokenSearchEl instanceof HTMLInputElement) {
                    tokenSearchEl.value = '';
                }
                setEditorVisible(false);
                renderAppearance();
                openActiveEditor();
            });
        }

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
