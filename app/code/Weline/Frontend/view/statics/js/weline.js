/**
 * Weline Framework — core ModuleLoader only.
 *
 * Allowed: load/preLoad/declare, attribute scan (data-weline-load|declare),
 * declarative UI mount orchestration (data-weline-mount + Weline.mount),
 * concurrency/defer policy, and lazy-trigger of Maintenance module on 503.
 * Forbidden: path-heuristic URL preloads; business UI/logic / business mount surfaces.
 * Core i18n is Framework module `i18n` (Weline_Framework::js/i18n.js); Theme declares load.
 * Weline_I18n is enhancement only.
 *
 * Usage:
 *   await Weline.load('api')
 *   await Weline.preLoad(['api'])
 *   // modules: data-weline-load / Weline.declare + owning weline.modules.js
 */
(function (window, document) {
    'use strict';

    /**
     * TEMP 诊断开关：true 时本文件后续逻辑全部不执行（ModuleLoader / declare / 属性扫描等）。
     * 用于排查「页面异常是否由 weline.js 引起」。验完务必改回 false。
     */
    const WELINE_JS_KILL_SWITCH = false;
    if (WELINE_JS_KILL_SWITCH) {
        window.Weline = Object.assign({}, window.Weline || {}, {
            __initialized: true,
            __killSwitch: true,
            config: (window.Weline && window.Weline.config) || {},
        });
        try {
            console.warn('[Weline] TEMP kill-switch ON：weline.js 后续代码已短路，仅用于前端诊断。');
        } catch (_) { /* ignore */ }
        return;
    }

    // 防止重复初始化
    if (window.Weline && window.Weline.__initialized) {
        return;
    }

    /**
     * 默认配置
     */
    const defaultConfig = {
        baseUrl: window.location.origin,
        modulesBaseUrl: '/static/Weline_Frontend/js/weline-api',
        assetVersion: 'dev',
        // 模块配置
        api: {
            workerUrl: null,
            useCredentials: 'same-origin',
            autoRequests: [],
            // Set only as lazy bridge; UI lives in Maintenance module.
            maintenanceHandler: null,
        },
        /**
         * Attribute / declare module load policy (framework only).
         * NEVER put business module names (cart/compare/wishlist/…) in defaults here.
         * Business modules declare via data-weline-load / declare + weline.modules.js.
         * deferByDefault=true → attribute modules idle-defer unless listed in eagerModules
         * (site/runtime override only; keep defaults empty of business names).
         */
        modulesLoad: {
            deferByDefault: true,
            /** Opt-in immediate modules (runtime/site config). Defaults empty. */
            eagerModules: [],
            /** Opt-in forced idle-defer when deferByDefault is false. Defaults empty. */
            deferredModules: [],
            /** data-weline-declare also schedules idle load. */
            loadDeclaredDeferred: true,
            idleTimeoutMs: 2000,
        },
    };

    // 合并用户配置
    const userConfig = (window.Weline && window.Weline.config) || {};
    const runtimeConfig = Object.assign(
        {},
        defaultConfig,
        userConfig
    );
    runtimeConfig.modulesLoad = Object.assign(
        {},
        defaultConfig.modulesLoad || {},
        userConfig.modulesLoad && typeof userConfig.modulesLoad === 'object'
            ? userConfig.modulesLoad
            : {}
    );

    /**
     * DEV-only：检测 MutationObserver「回调改 DOM → 再次投递」反馈环（曾导致迷你购物车卡死整页）。
     * 生产不包装。触发后 disconnect 并 console.error，避免浏览器假死。
     */
    function isWelineDevRuntime() {
        return !!(
            runtimeConfig.debug
            || window.DEV
            || window.WELINE_ENV === 'DEV'
            || (typeof DEV !== 'undefined' && DEV)
            || (window.location && String(window.location.search || '').indexOf('debug=1') !== -1)
        );
    }

    function installDevMutationObserverGuard() {
        if (!isWelineDevRuntime() || typeof window.MutationObserver !== 'function') {
            return;
        }
        if (window.__WelineDevMutationObserverGuardInstalled) {
            return;
        }
        window.__WelineDevMutationObserverGuardInstalled = true;

        const NativeMO = window.MutationObserver;
        /** 同一观察者：同步重入深度 */
        const MAX_SYNC_DEPTH = 1;
        /** 同一观察者：短窗内投递次数（含微任务连发） */
        const MAX_DELIVERIES_PER_WINDOW = 40;
        const WINDOW_MS = 250;

        function captureStack(skip) {
            try {
                const err = new Error('[Weline:DEV] MutationObserver site');
                const lines = String(err.stack || '').split('\n');
                return lines.slice(Math.max(1, skip || 2), 12).join('\n');
            } catch (_e) {
                return '';
            }
        }

        function reportAndStop(state, reason, observer) {
            if (state.tripped) {
                return;
            }
            state.tripped = true;
            try {
                observer.disconnect();
            } catch (_d) { /* ignore */ }
            const detail = {
                reason: reason,
                deliveries: state.deliveries,
                syncDepth: state.depth,
                observeStack: state.observeStack || '',
                createStack: state.createStack || '',
            };
            try {
                console.error(
                    '[Weline:DEV] MutationObserver 反馈环已截断（开发环境防护）。'
                    + ' 回调内改 childList/subtree 又触发同一观察者会导致页面卡死。'
                    + ' 请在回调里加重入守卫，或只发现新节点、禁止每次全量重绘。',
                    detail
                );
            } catch (_c) { /* ignore */ }
            try {
                window.dispatchEvent(new CustomEvent('weline:dev:mutation-loop', { detail: detail }));
            } catch (_e) { /* ignore */ }
            try {
                window.__WelineDevMutationLoopLast = detail;
            } catch (_w) { /* ignore */ }
        }

        function WrappedMutationObserver(callback) {
            if (typeof callback !== 'function') {
                return new NativeMO(callback);
            }
            const state = {
                depth: 0,
                deliveries: 0,
                windowStart: 0,
                tripped: false,
                createStack: captureStack(3),
                observeStack: '',
            };
            const wrapped = function (records, observer) {
                if (state.tripped) {
                    return;
                }
                const now = Date.now();
                if (!state.windowStart || (now - state.windowStart) > WINDOW_MS) {
                    state.windowStart = now;
                    state.deliveries = 0;
                }
                state.deliveries += 1;
                state.depth += 1;
                if (state.depth > MAX_SYNC_DEPTH) {
                    reportAndStop(state, 'sync_reentry', observer);
                    state.depth -= 1;
                    return;
                }
                if (state.deliveries > MAX_DELIVERIES_PER_WINDOW) {
                    reportAndStop(state, 'delivery_storm', observer);
                    state.depth -= 1;
                    return;
                }
                try {
                    return callback.call(this, records, observer);
                } finally {
                    state.depth -= 1;
                }
            };
            const observer = new NativeMO(wrapped);
            const nativeObserve = observer.observe.bind(observer);
            observer.observe = function (target, options) {
                if (!state.observeStack) {
                    state.observeStack = captureStack(2);
                }
                return nativeObserve(target, options);
            };
            return observer;
        }

        WrappedMutationObserver.prototype = NativeMO.prototype;
        try {
            Object.defineProperty(WrappedMutationObserver, 'name', { value: 'MutationObserver' });
        } catch (_n) { /* ignore */ }
        window.MutationObserver = WrappedMutationObserver;
    }

    installDevMutationObserverGuard();

    /**
     * Document-wide MutationObserver 正确合并原语（防 DEV delivery_storm）。
     * 首次投递即 disconnect → 安静窗 setTimeout(idleTimeoutMs) → 可选 requestIdleCallback → flush → observe。
     * 禁止仅用 requestIdleCallback({timeout})（timeout 会在主线程空档几乎立刻跑，密级 hydrate 仍会打爆投递）。
     * 禁止双 rAF / queueMicrotask 立刻 re-observe。
     *
     * @param {{
     *   target: Node,
     *   options?: MutationObserverInit,
     *   onRecords?: (records: MutationRecord[]) => void,
     *   onFlush: () => void,
     *   idleTimeoutMs?: number,
     *   autoStart?: boolean,
     * }} spec
     * @returns {{
     *   kick: () => void,
     *   pause: () => void,
     *   resume: () => void,
     *   withPaused: (fn: Function) => *,
     *   disconnect: () => void,
     *   start: () => void,
     *   observer: MutationObserver|null,
     * }}
     */
    function observeMutationsCoalesced(spec) {
        const options = (spec && spec.options) || { childList: true, subtree: true };
        const idleTimeoutMs = (function () {
            const n = Number(spec && spec.idleTimeoutMs);
            return Number.isFinite(n) && n >= 0 ? n : 100;
        })();
        /** Vue-style max update depth：同调用栈内嵌套 flush 过深即判定反馈环。 */
        const MAX_FLUSH_DEPTH = 50;
        /** 短窗内连续 flush 次数（含安静窗链式），对齐 DEV delivery_storm 量级。 */
        const MAX_FLUSHES_PER_WINDOW = 40;
        const FLUSH_WINDOW_MS = 250;
        const loopLabel = String((spec && spec.label) || 'observeMutationsCoalesced');
        let target = spec && spec.target ? spec.target : null;
        let paused = 0;
        let flushScheduled = false;
        let idleHandle = null;
        let timeoutHandle = null;
        let observing = false;
        let disposed = false;
        let flushDepth = 0;
        let flushWindowStart = 0;
        let flushesInWindow = 0;

        function clearIdleTimers() {
            if (idleHandle != null && typeof window.cancelIdleCallback === 'function') {
                try {
                    window.cancelIdleCallback(idleHandle);
                } catch (_e) { /* ignore */ }
                idleHandle = null;
            }
            if (timeoutHandle != null) {
                window.clearTimeout(timeoutHandle);
                timeoutHandle = null;
            }
        }

        function disconnectQuiet() {
            if (!observer) {
                return;
            }
            try {
                observer.disconnect();
            } catch (_e) { /* ignore */ }
            observing = false;
        }

        function observeNow() {
            if (disposed || !observer || !target || paused > 0 || flushScheduled) {
                return;
            }
            try {
                observer.observe(target, options);
                observing = true;
            } catch (_e) { /* ignore */ }
        }

        function takePendingRecords() {
            if (!observer || typeof observer.takeRecords !== 'function') {
                return [];
            }
            try {
                return observer.takeRecords() || [];
            } catch (_e) {
                return [];
            }
        }

        function deliverRecords(records) {
            if (!records || !records.length || typeof spec.onRecords !== 'function') {
                return;
            }
            try {
                spec.onRecords(records);
            } catch (_e) { /* ignore */ }
        }

        function reportFeedbackLoop(reason, detail) {
            if (disposed) {
                return;
            }
            disposed = true;
            flushScheduled = false;
            clearIdleTimers();
            disconnectQuiet();
            const payload = Object.assign({
                reason: reason,
                label: loopLabel,
                flushDepth: flushDepth,
                flushesInWindow: flushesInWindow,
            }, detail || {});
            try {
                console.error(
                    '[Weline] Mutation flush loop stopped（参考 Vue maxUpdateDepth）。'
                    + ' 回调改 DOM 形成反馈环；请检查 onFlush/onRecords，document 级须用 Weline.dom.observe。',
                    payload
                );
            } catch (_c) { /* ignore */ }
            try {
                window.dispatchEvent(new CustomEvent('weline:dom:mutation-loop', { detail: payload }));
            } catch (_e) { /* ignore */ }
            try {
                window.__WelineDomMutationLoopLast = payload;
            } catch (_w) { /* ignore */ }
            if (typeof spec.onLoop === 'function') {
                try {
                    spec.onLoop(payload);
                } catch (_o) { /* ignore */ }
            }
            observer = null;
        }

        function noteFlushInWindow() {
            const now = Date.now();
            if (!flushWindowStart || (now - flushWindowStart) > FLUSH_WINDOW_MS) {
                flushWindowStart = now;
                flushesInWindow = 0;
            }
            flushesInWindow += 1;
            return flushesInWindow > MAX_FLUSHES_PER_WINDOW;
        }

        function runFlush() {
            if (disposed) {
                return;
            }
            if (flushDepth >= MAX_FLUSH_DEPTH) {
                reportFeedbackLoop('max_flush_depth', { maxFlushDepth: MAX_FLUSH_DEPTH });
                return;
            }
            if (noteFlushInWindow()) {
                reportFeedbackLoop('flush_storm', {
                    maxFlushesPerWindow: MAX_FLUSHES_PER_WINDOW,
                    windowMs: FLUSH_WINDOW_MS,
                });
                return;
            }
            flushDepth += 1;
            flushScheduled = false;
            clearIdleTimers();
            disconnectQuiet();
            try {
                deliverRecords(takePendingRecords());
                if (typeof spec.onFlush === 'function') {
                    spec.onFlush();
                }
                deliverRecords(takePendingRecords());
            } catch (error) {
                try {
                    console.warn('[Weline] observeMutationsCoalesced onFlush failed:', error);
                } catch (_c) { /* ignore */ }
            } finally {
                flushDepth -= 1;
                if (!disposed && paused === 0) {
                    observeNow();
                    const leftover = takePendingRecords();
                    if (leftover.length) {
                        deliverRecords(leftover);
                        scheduleFlushTrailing();
                    }
                }
            }
        }

        function scheduleFlushTrailing() {
            if (disposed) {
                return;
            }
            clearIdleTimers();
            flushScheduled = true;
            disconnectQuiet();
            // Quiet window FIRST (setTimeout). rIC timeout alone is not a quiet window —
            // browsers may run it on the next idle slice within a few ms.
            timeoutHandle = window.setTimeout(function () {
                timeoutHandle = null;
                const run = function () {
                    idleHandle = null;
                    runFlush();
                };
                if (typeof window.requestIdleCallback === 'function') {
                    idleHandle = window.requestIdleCallback(run, { timeout: 50 });
                } else {
                    run();
                }
            }, idleTimeoutMs);
        }

        let observer = null;
        if (typeof MutationObserver === 'function') {
            observer = new MutationObserver(function (records) {
                if (disposed || paused > 0) {
                    return;
                }
                disconnectQuiet();
                deliverRecords(records);
                scheduleFlushTrailing();
            });
        }

        const api = {
            get observer() {
                return observer;
            },
            kick() {
                scheduleFlushTrailing();
            },
            pause() {
                paused += 1;
                disconnectQuiet();
            },
            resume() {
                if (paused > 0) {
                    paused -= 1;
                }
                if (paused === 0 && !flushScheduled && !disposed) {
                    observeNow();
                }
            },
            withPaused(fn) {
                api.pause();
                try {
                    return fn();
                } finally {
                    api.resume();
                }
            },
            disconnect() {
                disposed = true;
                flushScheduled = false;
                clearIdleTimers();
                disconnectQuiet();
                observer = null;
            },
            start() {
                if (disposed) {
                    return;
                }
                observeNow();
            },
            setTarget(next) {
                target = next || null;
                if (observing) {
                    disconnectQuiet();
                    observeNow();
                }
            },
        };

        if (!spec || spec.autoStart !== false) {
            api.start();
        }
        return api;
    }

    /**
     * 架构级 DOM Mutation 观察总线（同 target+options 仅一个物理 MutationObserver）。
     * 业务/UI 必须经 Weline.dom.observe 订阅；禁止对 document/body/html 再 new MutationObserver。
     * 扇出 onRecords/onFlush；订阅级 pause 不拆掉共享观察者（写 DOM 时跳过本订阅回调即可）。
     */
    const domMutationChannels = new Map();

    function fingerprintObserveOptions(options) {
        const o = options && typeof options === 'object' ? options : {};
        const keys = Object.keys(o).sort();
        return keys.map(function (key) {
            const value = o[key];
            if (Array.isArray(value)) {
                return key + '=' + value.slice().map(String).sort().join(',');
            }
            return key + '=' + String(value);
        }).join('|');
    }

    function documentWideTargetToken(target) {
        if (!target) {
            return null;
        }
        if (target === document) {
            return 'document';
        }
        if (target === document.documentElement) {
            return 'html';
        }
        if (target === document.body) {
            return 'body';
        }
        return null;
    }

    /**
     * @param {{
     *   target: Node,
     *   options?: MutationObserverInit,
     *   onRecords?: (records: MutationRecord[]) => void,
     *   onFlush: () => void,
     *   idleTimeoutMs?: number,
     *   share?: boolean,
     *   autoStart?: boolean,
     * }} spec
     */
    function observeDomMutations(spec) {
        const target = spec && spec.target ? spec.target : null;
        const options = (spec && spec.options) || { childList: true, subtree: true };
        const idleTimeoutMs = (function () {
            const n = Number(spec && spec.idleTimeoutMs);
            return Number.isFinite(n) && n >= 0 ? n : 100;
        })();
        const token = documentWideTargetToken(target);
        const wantShare = spec && spec.share === false ? false : true;
        if (!wantShare || !token) {
            return observeMutationsCoalesced(spec);
        }

        const channelKey = token + '::' + fingerprintObserveOptions(options) + '::' + String(idleTimeoutMs);
        let channel = domMutationChannels.get(channelKey);
        if (!channel) {
            const subscribers = new Map();
            const handle = observeMutationsCoalesced({
                target: target,
                options: options,
                idleTimeoutMs: idleTimeoutMs,
                autoStart: spec && spec.autoStart !== false,
                label: 'Weline.dom:' + channelKey,
                onLoop() {
                    domMutationChannels.delete(channelKey);
                },
                onRecords(records) {
                    subscribers.forEach(function (sub) {
                        if (sub.paused > 0 || typeof sub.onRecords !== 'function') {
                            return;
                        }
                        try {
                            sub.onRecords(records);
                        } catch (_e) { /* ignore */ }
                    });
                },
                onFlush() {
                    subscribers.forEach(function (sub) {
                        if (sub.paused > 0 || typeof sub.onFlush !== 'function') {
                            return;
                        }
                        try {
                            sub.onFlush();
                        } catch (_e) { /* ignore */ }
                    });
                },
            });
            channel = {
                handle: handle,
                subscribers: subscribers,
                nextId: 1,
            };
            domMutationChannels.set(channelKey, channel);
        }

        const subId = channel.nextId++;
        const sub = {
            paused: 0,
            name: String((spec && spec.label) || ('sub-' + subId)),
            onRecords: typeof spec.onRecords === 'function' ? spec.onRecords : null,
            onFlush: typeof spec.onFlush === 'function' ? spec.onFlush : null,
        };
        channel.subscribers.set(subId, sub);

        return {
            get observer() {
                return channel.handle.observer;
            },
            kick() {
                channel.handle.kick();
            },
            pause() {
                sub.paused += 1;
            },
            resume() {
                if (sub.paused > 0) {
                    sub.paused -= 1;
                }
            },
            withPaused(fn) {
                sub.paused += 1;
                try {
                    return fn();
                } finally {
                    if (sub.paused > 0) {
                        sub.paused -= 1;
                    }
                }
            },
            disconnect() {
                channel.subscribers.delete(subId);
                if (channel.subscribers.size === 0) {
                    try {
                        channel.handle.disconnect();
                    } catch (_e) { /* ignore */ }
                    domMutationChannels.delete(channelKey);
                }
            },
            start() {
                channel.handle.start();
            },
            __channelKey: channelKey,
            __shared: true,
        };
    }

    /**
     * 模块加载器
     */
    class ModuleLoader {
        constructor() {
            this.loadedModules = new Map();
            this.loadingModules = new Map();
            /** Cap parallel <script> inserts so one page cannot flood H2 streams. */
            this.maxConcurrentScripts = 4;
            this.scriptInflight = 0;
            /** @type {Array<() => void>} */
            this.scriptWaitQueue = [];
        }

        /**
         * 模块基础 URL（由 Frontend head 注入 runtimeConfig.modulesBaseUrl）
         */
        getModulesBaseUrl() {
            if (runtimeConfig.modulesBaseUrl) {
                return runtimeConfig.modulesBaseUrl;
            }
            throw new Error('[Weline.ModuleLoader] modulesBaseUrl 未配置，请在 Frontend 模块的 head 模板中配置 modulesBaseUrl');
        }

        getScriptUrl(url) {
            const isDev = runtimeConfig.debug ||
                window.DEV ||
                window.location.hostname === 'localhost' ||
                window.location.hostname === '127.0.0.1';
            if (!isDev) {
                return url;
            }
            if (url.indexOf('_weline_dev=') !== -1) {
                return url;
            }
            const assetVersion = encodeURIComponent(String(
                runtimeConfig.assetVersion ||
                runtimeConfig.deployVersion ||
                runtimeConfig.deploy_version ||
                'dev'
            ));
            const separator = url.indexOf('?') === -1 ? '?' : '&';
            return `${url}${separator}_weline_dev=${assetVersion}`;
        }

        /**
         * Resolve Weline_Module::path or absolute/http paths (Theme StaticResourceResolver 对齐).
         */
        resolveStaticPath(modulePath) {
            if (!modulePath || typeof modulePath !== 'string') {
                return modulePath;
            }
            if (modulePath.indexOf('http://') === 0 || modulePath.indexOf('https://') === 0 || modulePath.charAt(0) === '/') {
                return modulePath;
            }
            if (modulePath.indexOf('::') === -1) {
                return modulePath;
            }
            const parts = modulePath.split('::');
            if (parts.length !== 2) {
                return modulePath;
            }
            const moduleName = parts[0].trim();
            let filePath = parts[1].trim().replace(/^\/+/, '');
            // Keep ?query out of the filesystem path (e.g. Module::js/foo.js?v=mtime).
            let querySuffix = '';
            const queryPos = filePath.indexOf('?');
            if (queryPos !== -1) {
                querySuffix = filePath.slice(queryPos);
                filePath = filePath.slice(0, queryPos);
            }
            const modulePathParts = moduleName.split('_');
            const vendorName = modulePathParts[0];
            const moduleNamePart = modulePathParts.slice(1).join('_');
            const normalizedModuleName = `${vendorName}/${moduleNamePart}`;
            const isDev = runtimeConfig.debug ||
                window.DEV ||
                window.location.hostname === 'localhost' ||
                window.location.hostname === '127.0.0.1';
            if (isDev) {
                return `/${normalizedModuleName}/view/statics/${filePath}${querySuffix}`;
            }
            return `/static/${normalizedModuleName}/${filePath}${querySuffix}`;
        }

        ensureModulesConfig() {
            if (this._modulesConfigPromise) {
                return this._modulesConfigPromise;
            }
            const url = runtimeConfig.modulesConfigUrl;
            if (!url) {
                this._modulesConfigPromise = Promise.resolve();
                return this._modulesConfigPromise;
            }
            if (window.WelineModulesConfig && window.WelineModulesConfig.__welineMerged === true) {
                this._modulesConfigPromise = Promise.resolve();
                return this._modulesConfigPromise;
            }
            this._modulesConfigPromise = new Promise((resolve) => {
                const script = document.createElement('script');
                script.src = this.getScriptUrl(url);
                script.async = true;
                script.onload = () => {
                    if (window.WelineModulesConfig) {
                        window.WelineModulesConfig.__welineMerged = true;
                    }
                    resolve();
                };
                script.onerror = () => resolve();
                document.head.appendChild(script);
            });
            return this._modulesConfigPromise;
        }

        lookupModuleConfig(moduleName) {
            const cfg = window.WelineModulesConfig || {};
            const aliases = cfg.moduleAliases || {};
            const modules = cfg.modules || {};
            const resolvedName = aliases[moduleName] || moduleName;
            return {
                resolvedName: resolvedName,
                moduleConfig: modules[resolvedName] || modules[moduleName] || null,
            };
        }

        isGlobalModuleReady(globalVarName, requireFullGlobal = false) {
            if (!globalVarName || !window[globalVarName]) {
                return false;
            }
            if (!requireFullGlobal) {
                return true;
            }
            if (window[globalVarName].__full !== true) {
                return false;
            }
            if (globalVarName === 'WelineApiModule') {
                const requiredMethods = ['request', 'get', 'post', 'call', 'graph', 'stream', 'resource'];
                return requiredMethods.every(method => typeof window[globalVarName][method] === 'function');
            }
            return true;
        }

        /**
         * 加载模块
         * @param {string} moduleName 模块名称
         * @param {string} modulePath 模块路径（可选，默认从 modules 配置或 modulesBaseUrl）
         * @returns {Promise<any>}
         */
        async loadModule(moduleName, modulePath = null) {
            if (this.loadedModules.has(moduleName)) {
                return this.loadedModules.get(moduleName);
            }
            if (this.loadingModules.has(moduleName)) {
                return this.loadingModules.get(moduleName);
            }

            // 先同步登记 loading，再 await 配置，避免 declare 与属性扫描并发双插 script
            const loadPromise = (async () => {
                await this.ensureModulesConfig();

                if (this.loadedModules.has(moduleName)) {
                    return this.loadedModules.get(moduleName);
                }

                const lookup = this.lookupModuleConfig(moduleName);
                const moduleConfig = lookup.moduleConfig;
                let path = modulePath;
                let globalVarName = this.getGlobalVarName(moduleName);

                if (moduleConfig) {
                    // 显式 null = 无全局变量校验（Worker / 纯 IIFE 部件脚本）
                    if (Object.prototype.hasOwnProperty.call(moduleConfig, 'globalVar')) {
                        globalVarName = moduleConfig.globalVar;
                    }
                }

                let paths = [];
                if (path) {
                    paths = [this.resolveStaticPath(path)];
                } else if (moduleConfig && Array.isArray(moduleConfig.paths) && moduleConfig.paths.length > 0) {
                    paths = moduleConfig.paths.map((entry) => this.resolveStaticPath(entry));
                } else if (moduleConfig && typeof moduleConfig.paths === 'string') {
                    paths = [this.resolveStaticPath(moduleConfig.paths)];
                } else {
                    const modulesBaseUrl = this.getModulesBaseUrl();
                    // 回退路径按调用名（api/account）拼，不按别名后的注册名
                    if (moduleName === 'api') {
                        paths = [modulesBaseUrl + '.js'];
                    } else if (moduleName === 'account') {
                        paths = [modulesBaseUrl + '-account.js'];
                    } else {
                        paths = [modulesBaseUrl + '-' + moduleName + '.js'];
                    }
                }

                const requiresFullGlobal = globalVarName === 'WelineApiModule'
                    || globalVarName === 'WelineTokenStorage';
                if (this.isGlobalModuleReady(globalVarName, requiresFullGlobal)) {
                    const module = window[globalVarName];
                    this.loadedModules.set(moduleName, module);
                    return module;
                }

                const appendScript = (scriptPath, validateGlobal) => new Promise((resolve, reject) => {
                    const run = () => {
                        this.scriptInflight += 1;
                        const script = document.createElement('script');
                        script.src = this.getScriptUrl(scriptPath);
                        script.async = true;
                        // 同源不强制 CORS；跨域再设 anonymous
                        try {
                            const resolved = new URL(script.src, window.location.href);
                            if (resolved.origin !== window.location.origin) {
                                script.crossOrigin = 'anonymous';
                            }
                        } catch (_error) {
                        }

                        const loadTimeoutMs = 10000;
                        let settled = false;
                        const releaseSlot = () => {
                            this.scriptInflight = Math.max(0, this.scriptInflight - 1);
                            const next = this.scriptWaitQueue.shift();
                            if (typeof next === 'function') {
                                next();
                            }
                        };
                        const settle = (fn) => {
                            if (settled) {
                                return;
                            }
                            settled = true;
                            window.clearTimeout(timeoutId);
                            releaseSlot();
                            fn();
                        };
                        const timeoutId = window.setTimeout(() => {
                            try {
                                script.onload = null;
                                script.onerror = null;
                                if (script.parentNode) {
                                    script.parentNode.removeChild(script);
                                }
                            } catch (_cleanupError) {
                            }
                            settle(() => reject(new Error(
                                '[Weline] ' + __('模块 %{1} 加载超时：%{2}', { 1: moduleName, 2: scriptPath })
                            )));
                        }, loadTimeoutMs);

                        script.onload = () => {
                            settle(() => {
                                if (validateGlobal && globalVarName && !this.isGlobalModuleReady(globalVarName, requiresFullGlobal)) {
                                    reject(new Error('[Weline] ' + __('模块 %{1} 加载失败：未找到 %{2}', { 1: moduleName, 2: globalVarName })));
                                    return;
                                }
                                resolve();
                            });
                        };
                        script.onerror = () => {
                            settle(() => reject(new Error(
                                '[Weline] ' + __('模块 %{1} 加载失败：无法加载 %{2}', { 1: moduleName, 2: scriptPath })
                            )));
                        };
                        document.head.appendChild(script);
                    };

                    if (this.scriptInflight >= this.maxConcurrentScripts) {
                        this.scriptWaitQueue.push(run);
                        return;
                    }
                    run();
                });

                for (let index = 0; index < paths.length; index += 1) {
                    await appendScript(paths[index], index === paths.length - 1);
                }

                const module = globalVarName ? window[globalVarName] : true;
                this.loadedModules.set(moduleName, module);
                return module;
            })().finally(() => {
                this.loadingModules.delete(moduleName);
            });

            this.loadingModules.set(moduleName, loadPromise);
            return loadPromise;
        }

        /**
         * 获取模块的全局变量名
         * @param {string} moduleName 模块名称
         * @returns {string}
         */
        getGlobalVarName(moduleName) {
            // Transport/microkernel aliases only. Business modules MUST set globalVar in weline.modules.js.
            const nameMap = {
                'api': 'WelineApiModule',
                'welineApi': 'WelineApiModule',
                'welineApiTokenStorage': 'WelineTokenStorage',
                'dom': 'WelineDomModule',
                'welineDom': 'WelineDomModule',
            };
            return nameMap[moduleName] || `Weline${moduleName.charAt(0).toUpperCase() + moduleName.slice(1)}Module`;
        }

        /**
         * 检查模块是否已加载
         * @param {string} moduleName 模块名称
         * @returns {boolean}
         */
        isModuleLoaded(moduleName) {
            return this.loadedModules.has(moduleName);
        }

        /**
         * 模块是否正在加载
         * @param {string} moduleName
         * @returns {boolean}
         */
        isModuleLoading(moduleName) {
            return this.loadingModules.has(moduleName);
        }

        /**
         * 属性 / declare 加载是否应跳过（已加载 / 加载中）
         * @param {string} moduleName
         * @returns {boolean}
         */
        shouldSkipAttributeLoad(moduleName) {
            return this.isModuleLoaded(moduleName)
                || this.isModuleLoading(moduleName);
        }
    }

    const moduleLoader = new ModuleLoader();

    /**
     * 翻译函数辅助方法（简化调用）
     * @param {string} key 翻译键
     * @param {Object} params 参数对象
     * @returns {string} 翻译后的文本
     */
    const __ = (key, params = {}) => {
        // 核心 i18n：Framework 模组；Theme declare / data-weline-load 加载。
        if (window.WelineI18n && typeof window.WelineI18n.translate === 'function') {
            return window.WelineI18n.translate(key, params);
        }
        if (window.Weline && window.Weline.i18n && typeof window.Weline.i18n.translate === 'function') {
            return window.Weline.i18n.translate(key, params);
        }
        return key;
    };

    /**
     * Maintenance / 503 UI lives in Weline_Maintenance (maintenanceAsyncWait).
     * Framework only wires a lazy loader — forbid business/HTML here.
     */
    (function installMaintenanceHandlerLazyBridge() {
        const MODULE_NAME = 'maintenanceAsyncWait';
        const WAIT_STORAGE_KEY = 'weline_mw_wait_gift';

        function loadMaintenanceModule() {
            if (window.WelineMaintenanceAsyncWait
                && typeof window.WelineMaintenanceAsyncWait.handle === 'function') {
                return Promise.resolve(window.WelineMaintenanceAsyncWait);
            }
            // Prefer ModuleLoader directly so this works before window.Weline is assigned.
            return moduleLoader.loadModule(MODULE_NAME).then(() => window.WelineMaintenanceAsyncWait || null);
        }

        runtimeConfig.api = runtimeConfig.api || {};
        if (typeof runtimeConfig.api.maintenanceHandler !== 'function') {
            runtimeConfig.api.maintenanceHandler = function maintenanceHandler(ctx) {
                loadMaintenanceModule().then((mod) => {
                    if (mod && typeof mod.handle === 'function') {
                        mod.handle(ctx && typeof ctx === 'object' ? ctx : {});
                    }
                }).catch((error) => {
                    console.warn('[Weline] maintenanceAsyncWait load failed:', error && error.message ? error.message : error);
                });
            };
        }

        function maybeRedeemWaitGiftAfterReload() {
            let hasToken = false;
            try {
                hasToken = !!sessionStorage.getItem(WAIT_STORAGE_KEY);
            } catch (e) {
                hasToken = false;
            }
            if (!hasToken) {
                return;
            }
            loadMaintenanceModule().then((mod) => {
                if (mod && typeof mod.tryRedeemSameUrlToken === 'function') {
                    mod.tryRedeemSameUrlToken();
                }
            }).catch(() => undefined);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', maybeRedeemWaitGiftAfterReload);
        } else {
            maybeRedeemWaitGiftAfterReload();
        }
    })();

    /**
     * Weline 主对象
     */
    const Weline = {
        __initialized: true,
        __version: '1.0.0',
        config: runtimeConfig,
        loader: moduleLoader,

        /**
         * 预加载模块
         * @param {string|string[]} modules 模块名称或模块名称数组
         * @returns {Promise<void|void[]>} 返回Promise，如果传入数组则返回Promise数组
         * 
         * @example
         * // 预加载单个模块
         * await Weline.preLoad('account');
         * 
         * @example
         * // 预加载多个模块
         * await Weline.preLoad(['api', 'account']);
         * 
         * @example
         * // 预加载多个模块（不等待）
         * Weline.preLoad(['api', 'account']).catch(() => {});
         */
        preLoad: async (modules) => {
            if (!modules) {
                return Promise.resolve();
            }

            // 如果是字符串，转换为数组
            const moduleList = Array.isArray(modules) ? modules : [modules];

            // 加载所有模块
            const loadPromises = moduleList.map(moduleName => {
                return moduleLoader.loadModule(moduleName).catch((error) => {
                    console.warn(`[Weline] ${__('预加载模块 %{1} 失败', { 1: moduleName })}:`, error.message);
                    // 不抛出错误，允许其他模块继续加载
                    return null;
                });
            });

            // 如果只有一个模块，返回单个Promise结果
            if (moduleList.length === 1) {
                return loadPromises[0];
            }

            // 多个模块，返回Promise.all
            return Promise.all(loadPromises);
        },

        /**
         * Api 模块代理（stub；weline-api.js 加载后会替换为同步 ApiModule）
         */
        Api: {
            __fallback: true,
            call: async (provider, operation, params, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.call(provider, operation, params, options);
            },
            graph: async (graph, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.graph(graph, options);
            },
            stream: async (channel, params, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.stream(channel, params, options);
            },
            resource: async (provider, optionalMap) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.resource(provider, optionalMap);
            },
            /**
             * 发送请求
             */
            request: async (url, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.request(url, options);
            },
            enableAutoRequests: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.enableAutoRequests();
            },
            disableAutoRequests: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.disableAutoRequests();
            },
            getClient: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.getClient();
            },
        },

        /**
         * 按需加载模块（返回真实模块，如 WelineApiModule）。
         * 用法：Weline.load('api').then(api => api.resource('consent').status({}))
         */
        load: (moduleName, modulePath = null) => moduleLoader.loadModule(moduleName, modulePath),

        /**
         * 声明模块（主题规范：部件/布局必须 declare 或 data-weline-load，禁止直引 script）。
         * @param {string|string[]} moduleNames
         * @param {boolean|string|string[]|{load?: 'eager'|'defer'|'none', path?: string|string[]}} loadImmediatelyOrCustomPath
         * @param {string|string[]|null} customPath
         */
        declare: async (moduleNames, loadImmediatelyOrCustomPath = false, customPath = null) => {
            if (!moduleNames) {
                return;
            }
            let mode = 'none';
            let path = customPath;
            if (loadImmediatelyOrCustomPath && typeof loadImmediatelyOrCustomPath === 'object'
                && !Array.isArray(loadImmediatelyOrCustomPath)
            ) {
                const opts = loadImmediatelyOrCustomPath;
                if (opts.path !== undefined) {
                    path = opts.path;
                }
                if (opts.load === 'eager' || opts.load === 'defer' || opts.load === 'none') {
                    mode = opts.load;
                } else if (opts.loadImmediately === true) {
                    mode = 'eager';
                } else if (opts.loadImmediately === false) {
                    mode = (runtimeConfig.modulesLoad && runtimeConfig.modulesLoad.loadDeclaredDeferred)
                        ? 'defer'
                        : 'none';
                }
            } else if (typeof loadImmediatelyOrCustomPath === 'boolean') {
                if (loadImmediatelyOrCustomPath) {
                    mode = 'eager';
                } else if (runtimeConfig.modulesLoad && runtimeConfig.modulesLoad.loadDeclaredDeferred) {
                    // 默认：声明即延后加载（不再是“只登记不加载”）
                    mode = 'defer';
                } else {
                    mode = 'none';
                }
            } else if (
                typeof loadImmediatelyOrCustomPath === 'string'
                || Array.isArray(loadImmediatelyOrCustomPath)
            ) {
                path = loadImmediatelyOrCustomPath;
                mode = (runtimeConfig.modulesLoad && runtimeConfig.modulesLoad.loadDeclaredDeferred)
                    ? 'defer'
                    : 'none';
            }

            if (mode === 'none') {
                return;
            }
            const list = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
            const runLoad = () => Promise.all(
                list.map((name) => moduleLoader.loadModule(name, path).catch(() => null))
            );
            if (mode === 'eager') {
                await runLoad();
                return;
            }
            const idleTimeout = Math.max(
                0,
                Number((runtimeConfig.modulesLoad && runtimeConfig.modulesLoad.idleTimeoutMs) || 2000) || 2000
            );
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(() => { runLoad(); }, { timeout: idleTimeout });
            } else {
                window.setTimeout(() => { runLoad(); }, 0);
            }
        },

        /**
         * Declarative UI mount (framework). Business modules only provide(surface, handler).
         * Hosts declare data-weline-mount / -load / -after; never hard-code surfaces here.
         */
        mount: null,

    };

    /**
     * Framework UI mount orchestrator (lazy).
     * - Hosts declare data-weline-mount="{providerKey}/{surface}"
     * - Optional data-weline-mount-load / data-weline-mount-after
     * - Providers call Weline.mount.provide(surface, handler)
     * - Performance: no core boot / auto-scan unless page declares [data-weline-mount]
     */
    Weline.mount = (function createLazyMountFacade() {
        function pageDeclaresMount(root) {
            try {
                const scope = root && root.querySelector ? root : document;
                return !!scope.querySelector('[data-weline-mount]');
            } catch (_e) {
                return false;
            }
        }

        let core = null;

        function buildMountCore() {
            /** @type {Map<string, Function>} */
            const providers = new Map();
            /** @type {Map<string, Array<{resolve: Function, reject: Function, timeoutId: *}> >} */
            const waiters = new Map();
            let scanTimer = null;
            let scanChain = Promise.resolve();

            function splitCsv(raw) {
                return String(raw || '')
                    .split(',')
                    .map((part) => part.trim())
                    .filter((part) => part);
            }

            function normalizeSurface(raw) {
                return String(raw || '').trim();
            }

            function hostSurface(host) {
                if (!host || typeof host.getAttribute !== 'function') {
                    return '';
                }
                return normalizeSurface(host.getAttribute('data-weline-mount'));
            }

            function hostState(host) {
                return String((host && host.getAttribute && host.getAttribute('data-weline-mount-state')) || '')
                    .trim()
                    .toLowerCase();
            }

            function isSettledState(state) {
                return state === 'ready' || state === 'empty' || state === 'error';
            }

            function surfaceSettled(surface, root) {
                const scope = root && root.querySelectorAll ? root : document;
                const nodes = scope.querySelectorAll('[data-weline-mount="' + surface + '"]');
                if (!nodes.length) {
                    return false;
                }
                let settled = false;
                nodes.forEach((node) => {
                    if (isSettledState(hostState(node))) {
                        settled = true;
                    }
                });
                return settled;
            }

            function depsReady(host, root) {
                const deps = splitCsv(host.getAttribute('data-weline-mount-after'));
                if (!deps.length) {
                    return true;
                }
                for (let i = 0; i < deps.length; i += 1) {
                    if (!surfaceSettled(deps[i], root || document)) {
                        return false;
                    }
                }
                return true;
            }

            function flushWaiters(surface) {
                const key = normalizeSurface(surface);
                const list = waiters.get(key);
                if (!list || !list.length) {
                    return;
                }
                waiters.delete(key);
                list.forEach((entry) => {
                    try {
                        if (entry.timeoutId) {
                            clearTimeout(entry.timeoutId);
                        }
                    } catch (_t) { /* ignore */ }
                    try {
                        entry.resolve({ surface: key, state: 'ready' });
                    } catch (_r) { /* ignore */ }
                });
            }

            function emitReady(host, surface, result) {
                try {
                    host.dispatchEvent(new CustomEvent('weline:mount:ready', {
                        bubbles: true,
                        detail: { surface: surface, host: host, result: result || null },
                    }));
                } catch (_e) { /* ignore */ }
                try {
                    document.dispatchEvent(new CustomEvent('weline:mount:ready', {
                        detail: { surface: surface, host: host, result: result || null },
                    }));
                } catch (_d) { /* ignore */ }
                if (isSettledState(hostState(host))) {
                    flushWaiters(surface);
                }
            }

            function loadHostModules(host) {
                const names = splitCsv(host.getAttribute('data-weline-mount-load'));
                if (!names.length) {
                    return Promise.resolve([]);
                }
                return Promise.all(names.map((name) => Weline.load(name).catch(() => null)));
            }

            function processHost(host, options) {
                const opts = options || {};
                const force = !!opts.force;
                const root = opts.root || document;
                const surface = hostSurface(host);
                if (!surface) {
                    return Promise.resolve({ skipped: true, reason: 'no_surface' });
                }
                if (!force && isSettledState(hostState(host))) {
                    return Promise.resolve({ skipped: true, reason: 'already_settled', surface: surface });
                }
                if (!depsReady(host, root)) {
                    try {
                        host.setAttribute('data-weline-mount-state', 'waiting');
                    } catch (_w) { /* ignore */ }
                    return Promise.resolve({ skipped: true, reason: 'waiting_deps', surface: surface });
                }
                return loadHostModules(host).then(() => {
                    const runHandler = (handler) => Promise.resolve()
                        .then(() => handler(host, { force: force, surface: surface, root: root }))
                        .then((result) => {
                            emitReady(host, surface, result);
                            scheduleScan({ force: false, delayMs: 0 });
                            return { ok: true, surface: surface, result: result };
                        })
                        .catch((error) => {
                            try {
                                host.setAttribute('data-weline-mount-state', 'error');
                            } catch (_e) { /* ignore */ }
                            emitReady(host, surface, { error: error });
                            scheduleScan({ force: false, delayMs: 0 });
                            return { ok: false, surface: surface, error: error };
                        });
                    let handler = providers.get(surface);
                    if (typeof handler === 'function') {
                        return runHandler(handler);
                    }
                    // mount-load scripts may defer provide via setTimeout(0); one macrotask retry.
                    return new Promise((resolve) => {
                        setTimeout(() => {
                            handler = providers.get(surface);
                            if (typeof handler !== 'function') {
                                try {
                                    if (hostState(host) !== 'waiting') {
                                        host.setAttribute('data-weline-mount-state', 'pending');
                                    }
                                } catch (_p) { /* ignore */ }
                                resolve({ skipped: true, reason: 'no_provider', surface: surface });
                                return;
                            }
                            resolve(runHandler(handler));
                        }, 0);
                    });
                });
            }

            function collectHosts(root) {
                const scope = root && root.querySelectorAll ? root : document;
                return Array.from(scope.querySelectorAll('[data-weline-mount]'));
            }

            function scan(options) {
                const opts = options || {};
                const root = opts.root || document;
                if (!pageDeclaresMount(root)) {
                    return Promise.resolve({ skipped: true, reason: 'no_hosts' });
                }
                const force = !!opts.force;
                const hosts = collectHosts(root);
                // Second pass must not force-remount settled hosts — that wiped nested
                // social-quick slots created during the first pass (login-panel).
                const runPass = (passForce) => Promise.all(
                    hosts.map((host) => processHost(host, { force: !!passForce, root: root }))
                );
                scanChain = scanChain
                    .catch(() => null)
                    .then(() => runPass(force))
                    .then((first) => runPass(false).then((second) => ({ first: first, second: second })));
                return scanChain;
            }

            function scheduleScan(options) {
                const opts = options || {};
                if (!pageDeclaresMount(opts.root || document)) {
                    return;
                }
                if (scanTimer) {
                    clearTimeout(scanTimer);
                }
                scanTimer = setTimeout(() => {
                    scanTimer = null;
                    scan(opts);
                }, opts.delayMs != null ? Number(opts.delayMs) || 0 : 0);
            }

            function provide(surface, handler) {
                const key = normalizeSurface(surface);
                if (!key || typeof handler !== 'function') {
                    return false;
                }
                providers.set(key, handler);
                // Only wake scan when this surface is declared on the page.
                if (document.querySelector('[data-weline-mount="' + key + '"]')) {
                    scheduleScan({ force: false, reason: 'provide' });
                }
                return true;
            }

            function waitFor(surface, options) {
                const key = normalizeSurface(surface);
                const opts = options || {};
                const root = opts.root || document;
                const timeoutMs = Math.max(0, Number(opts.timeoutMs) || 15000);
                if (!key) {
                    return Promise.reject(new Error('mount_surface_required'));
                }
                if (surfaceSettled(key, root)) {
                    return Promise.resolve({ surface: key, state: 'ready' });
                }
                return new Promise((resolve, reject) => {
                    const entry = { resolve: resolve, reject: reject, timeoutId: null };
                    if (!waiters.has(key)) {
                        waiters.set(key, []);
                    }
                    waiters.get(key).push(entry);
                    if (timeoutMs > 0) {
                        entry.timeoutId = setTimeout(() => {
                            const list = waiters.get(key) || [];
                            const next = list.filter((item) => item !== entry);
                            if (next.length) {
                                waiters.set(key, next);
                            } else {
                                waiters.delete(key);
                            }
                            reject(new Error('mount_wait_timeout:' + key));
                        }, timeoutMs);
                    }
                    scheduleScan({ force: !!opts.force, root: root });
                });
            }

            function hasProvider(surface) {
                return providers.has(normalizeSurface(surface));
            }

            return {
                provide: provide,
                scan: scan,
                waitFor: waitFor,
                hasProvider: hasProvider,
                scheduleScan: scheduleScan,
            };
        }

        function ensureMountCore() {
            if (!core) {
                core = buildMountCore();
            }
            return core;
        }

        whenDomReady(() => {
            if (!pageDeclaresMount(document)) {
                return;
            }
            ensureMountCore().scheduleScan({ force: false, delayMs: 0 });
        });

        document.addEventListener('weline:mount:scan', (event) => {
            const detail = (event && event.detail) || {};
            const root = detail.root || document;
            if (!pageDeclaresMount(root) && !pageDeclaresMount(document)) {
                return;
            }
            ensureMountCore().scan({
                force: !!detail.force,
                root: root,
            });
        });

        return {
            provide: function provide(surface, handler) {
                return ensureMountCore().provide(surface, handler);
            },
            scan: function scan(options) {
                const opts = options || {};
                const root = opts.root || document;
                if (!pageDeclaresMount(root) && !pageDeclaresMount(document)) {
                    return Promise.resolve({ skipped: true, reason: 'no_hosts' });
                }
                return ensureMountCore().scan(opts);
            },
            waitFor: function waitFor(surface, options) {
                return ensureMountCore().waitFor(surface, options);
            },
            hasProvider: function hasProvider(surface) {
                return !!(core && core.hasProvider(surface));
            },
            scheduleScan: function scheduleScan(options) {
                const opts = options || {};
                if (!pageDeclaresMount(opts.root || document) && !pageDeclaresMount(document)) {
                    return;
                }
                return ensureMountCore().scheduleScan(opts);
            },
        };
    })();

    /**
     * Run after DOM is interactive (DOMContentLoaded), or immediately if already past that.
     * @param {() => void} fn
     */
    function whenDomReady(fn) {
        if (typeof fn !== 'function') {
            return;
        }
        const run = () => {
            try {
                fn();
            } catch (error) {
                console.warn('[Weline] whenDomReady callback failed:', error);
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run, { once: true });
            return;
        }
        run();
    }

    /**
     * @deprecated Use whenDomReady. Kept as alias so older callers keep DOM timing.
     * @param {() => void} fn
     */
    function whenHtmlDocumentLoaded(fn) {
        whenDomReady(fn);
    }

    /**
     * Whether attribute/declare module should idle-defer.
     * @param {string} name
     * @returns {boolean}
     */
    function shouldDeferAttributeModule(name) {
        const cfg = runtimeConfig.modulesLoad || {};
        const eager = Array.isArray(cfg.eagerModules) ? cfg.eagerModules : [];
        const deferred = Array.isArray(cfg.deferredModules) ? cfg.deferredModules : [];
        if (eager.indexOf(name) !== -1) {
            return false;
        }
        if (deferred.indexOf(name) !== -1) {
            return true;
        }
        // Per-module policy from owning module's weline.modules.js (load: 'defer'|'eager').
        try {
            const lookup = moduleLoader.lookupModuleConfig(name);
            const modLoad = lookup && lookup.moduleConfig
                ? String(lookup.moduleConfig.load || '').toLowerCase()
                : '';
            if (modLoad === 'eager') {
                return false;
            }
            if (modLoad === 'defer') {
                return true;
            }
        } catch (e) {
            // ignore lookup errors; fall through to deferByDefault
        }
        return cfg.deferByDefault !== false;
    }

    /**
     * @returns {number}
     */
    function modulesLoadIdleTimeoutMs() {
        const n = Number((runtimeConfig.modulesLoad && runtimeConfig.modulesLoad.idleTimeoutMs) || 2000);
        return Number.isFinite(n) && n >= 0 ? n : 2000;
    }

    // 挂载到全局
    window.Weline = Weline;
    Weline.whenDomReady = whenDomReady;
    Weline.whenHtmlDocumentLoaded = whenHtmlDocumentLoaded;
    Weline.shouldDeferAttributeModule = shouldDeferAttributeModule;
    Weline.observeMutationsCoalesced = observeMutationsCoalesced;
    Weline.dom = Object.assign({}, Weline.dom || {}, {
        /**
         * 架构入口：document/body/html 级 Mutation 观察必须走此 API（共享总线）。
         * 元素级默认私有通道；share:false 可强制私有。
         */
        observe: observeDomMutations,
        observeMutations: observeDomMutations,
    });

    // 将翻译函数也挂载到 Weline 对象上，方便后续更新
    Weline.__ = __;

    /**
     * Dom 微核按需探测：仅当出现动态标记属性时 load('dom')。
     * 标记：data-weline-when / data-weline-on；显式 data-weline-load="dom" 仍走属性加载通道。
     * 探测层只负责加载，不实现 Observer 业务逻辑。
     */
    (function autoLoadDomCore() {
        const MARKER_SELECTOR = '[data-weline-when],[data-weline-on]';
        let ensurePromise = null;
        let discoveryObserver = null;
        let discoveryHandle = null;

        function elementDeclaresDomLoad(el) {
            if (!el || el.nodeType !== 1 || typeof el.getAttribute !== 'function') {
                return false;
            }
            const raw = el.getAttribute('data-weline-load') || '';
            return /(^|,)\s*dom\s*(,|$)/i.test(raw);
        }

        function subtreeNeedsDom(root) {
            if (!root) {
                return false;
            }
            if (root.nodeType === 1) {
                if (root.matches && root.matches(MARKER_SELECTOR)) {
                    return true;
                }
                if (elementDeclaresDomLoad(root)) {
                    return true;
                }
            }
            if (typeof root.querySelector === 'function' && root.querySelector(MARKER_SELECTOR)) {
                return true;
            }
            if (typeof root.querySelectorAll === 'function') {
                const loads = root.querySelectorAll('[data-weline-load]');
                for (let i = 0; i < loads.length; i++) {
                    if (elementDeclaresDomLoad(loads[i])) {
                        return true;
                    }
                }
            }
            return false;
        }

        function stopDiscovery() {
            if (discoveryHandle && typeof discoveryHandle.disconnect === 'function') {
                discoveryHandle.disconnect();
                discoveryHandle = null;
            }
            discoveryObserver = null;
        }

        function ensureDom() {
            if (window.Weline && window.Weline.Dom && window.Weline.Dom.__full) {
                stopDiscovery();
                return Promise.resolve(window.Weline.Dom);
            }
            if (ensurePromise) {
                return ensurePromise;
            }
            ensurePromise = Promise.resolve()
                .then(() => (Weline.load ? Weline.load('dom') : moduleLoader.loadModule('dom')))
                .then((mod) => {
                    stopDiscovery();
                    return mod;
                })
                .catch((error) => {
                    ensurePromise = null;
                    console.warn(`[Weline] ${__('按需加载 Dom 微核失败')}:`, error && error.message ? error.message : error);
                    return null;
                });
            return ensurePromise;
        }

        document.addEventListener('weline:dom:ready', () => {
            stopDiscovery();
        }, { once: true });

        function scan(root) {
            if (subtreeNeedsDom(root || document)) {
                ensureDom();
                return true;
            }
            return false;
        }

        function startMarkerDiscovery() {
            if (typeof MutationObserver !== 'function') {
                return;
            }
            const observeOptions = {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['data-weline-when', 'data-weline-on', 'data-weline-load'],
            };
            const rootEl = document.documentElement || document.body;
            if (!rootEl) {
                return;
            }
            // Dense widget childList: disconnect + trailing idle (not double-rAF reobserve).
            discoveryHandle = observeDomMutations({
                target: rootEl,
                options: observeOptions,
                idleTimeoutMs: 100,
                label: 'startMarkerDiscovery',
                onFlush() {
                    if (ensurePromise || (window.Weline && window.Weline.Dom && window.Weline.Dom.__full)) {
                        stopDiscovery();
                        return;
                    }
                    scan(document);
                },
            });
            discoveryObserver = discoveryHandle.observer;
        }

        const boot = () => {
            if (window.Weline && window.Weline.Dom && window.Weline.Dom.__full) {
                return;
            }
            if (scan(document)) {
                return;
            }
            startMarkerDiscovery();
        };

        whenDomReady(boot);
    })();

    /**
     * 部件/布局 data-weline-load / data-weline-declare（主题前端 JS 模块硬约束）
     */
    (function autoLoadDataAttributes() {
        const isDev = runtimeConfig.debug ||
            (typeof DEV !== 'undefined' && DEV) ||
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1' ||
            window.location.search.includes('debug=1');

        function splitModules(raw) {
            return String(raw || '')
                .split(',')
                .map((name) => name.trim())
                .filter((name) => name);
        }

        function loadModuleNames(names) {
            const unique = [];
            const seen = new Set();
            names.forEach((name) => {
                if (!name || seen.has(name) || moduleLoader.shouldSkipAttributeLoad(name)) {
                    return;
                }
                seen.add(name);
                unique.push(name);
            });
            return Promise.all(unique.map((name) => Weline.load(name).catch((error) => {
                if (isDev) {
                    console.warn('[Weline] attribute module failed:', name, error && error.message);
                }
                return null;
            })));
        }

        function process() {
            const declareNames = new Set();
            const immediateNames = [];
            const deferredNames = [];
            const skippedNames = new Set();
            /** @type {Array<{el: Element, modules: string[]}>} */
            const elementLoads = [];

            document.querySelectorAll('[data-weline-declare]').forEach((el) => {
                const modules = splitModules(el.getAttribute('data-weline-declare'));
                modules.forEach((name) => declareNames.add(name));
                if (modules.length === 0) {
                    return;
                }
                // declare 默认延后加载（modulesLoad.loadDeclaredDeferred）
                Weline.declare(modules, false);
            });

            document.querySelectorAll('[data-weline-load]').forEach((el) => {
                const modules = splitModules(el.getAttribute('data-weline-load'));
                if (modules.length === 0) {
                    return;
                }
                const requested = [];
                modules.forEach((name) => {
                    if (moduleLoader.shouldSkipAttributeLoad(name)) {
                        skippedNames.add(name);
                        return;
                    }
                    requested.push(name);
                    if (shouldDeferAttributeModule(name)) {
                        deferredNames.push(name);
                        return;
                    }
                    immediateNames.push(name);
                });
                if (requested.length) {
                    elementLoads.push({ el: el, modules: requested });
                }
            });

            const notifyElements = (loadedNames) => {
                const loaded = new Set(loadedNames);
                elementLoads.forEach((entry) => {
                    if (!entry.modules.some((name) => loaded.has(name))) {
                        return;
                    }
                    entry.el.dispatchEvent(new CustomEvent('weline-modules-loaded', {
                        detail: { modules: entry.modules, element: entry.el },
                    }));
                });
            };

            const runDeferred = () => {
                const deferredUnique = Array.from(new Set(deferredNames));
                loadModuleNames(deferredUnique).then(() => {
                    notifyElements(deferredUnique);
                    if (!isDev || deferredUnique.length === 0) {
                        return;
                    }
                    console.log(
                        `%c[Weline] ${__('延迟部件模块加载完成')}`,
                        'color: #2196F3; font-weight: bold; font-size: 12px;',
                        `\n${__('模块')}: ${deferredUnique.join(', ')}`
                    );
                });
            };

            const immediateUnique = Array.from(new Set(immediateNames));
            const scheduleDeferred = () => {
                if (typeof window.requestIdleCallback === 'function') {
                    window.requestIdleCallback(runDeferred, { timeout: modulesLoadIdleTimeoutMs() });
                    return;
                }
                window.setTimeout(runDeferred, 0);
            };

            if (immediateUnique.length === 0) {
                scheduleDeferred();
            } else {
                loadModuleNames(immediateUnique).then(() => {
                    notifyElements(immediateUnique);
                }).finally(scheduleDeferred);
            }

            if (isDev) {
                const loadList = immediateUnique;
                const deferredList = Array.from(new Set(deferredNames));
                const declareList = Array.from(declareNames);
                const skippedList = Array.from(skippedNames);
                if (loadList.length || deferredList.length || declareList.length || skippedList.length) {
                    console.log(
                        `%c[Weline] ${__('部件属性模块加载')}`,
                        'color: #4CAF50; font-weight: bold; font-size: 12px;',
                        '\n',
                        `${__('页面')}: ${window.location.pathname}`,
                        loadList.length ? `\n${__('立即加载')}: ${loadList.join(', ')}` : '',
                        deferredList.length ? `\n${__('空闲延迟')}: ${deferredList.join(', ')}` : '',
                        skippedList.length ? `\n${__('已跳过（已加载/加载中）')}: ${skippedList.join(', ')}` : '',
                        declareList.length ? `\n${__('声明延后')}: ${declareList.join(', ')}` : '',
                        `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                    );
                } else {
                    console.log(
                        `%c[Weline] ${__('本页 DOM 无 data-weline-load / data-weline-declare')}`,
                        'color: #9E9E9E; font-size: 11px;',
                        `\n${__('页面')}: ${window.location.pathname}`
                    );
                }
            }
        }

        const run = () => {
            moduleLoader.ensureModulesConfig().then(process);
        };

        // data-weline-load 在 DOMContentLoaded 后扫描（默认延后插 script）
        whenDomReady(run);
    })();

    /**
     * A server-issued Scope bootstrap is short-lived and one-time. Load the
     * official API module after DOM is ready so the response-injected meta
     * marker is present without racing Document parse.
     */
    (function preLoadScopeBootstrapApi() {
        const preload = () => {
            if (!document.querySelector('meta[name="weline-worker-scope-bootstrap"]')) {
                return;
            }
            Weline.preLoad('api').then((apiModule) => {
                if (apiModule && typeof apiModule.bootstrapScope === 'function') {
                    return apiModule.bootstrapScope();
                }
                return null;
            }).catch(() => {
                // The API module emits a structured scope-bootstrap failure
                // event; the loader must not replace the page with a fallback.
            });
        };

        whenDomReady(preload);
    })();

})(window, document);
