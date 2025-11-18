/**
 * Weline Api Module
 * 可被 theme.js 按需加载的 API 模块
 */
(function (window) {
    'use strict';

    // 获取配置（从 Weline 主对象或全局配置）
    const getConfig = () => {
        if (window.Weline && window.Weline.config && window.Weline.config.api) {
            return window.Weline.config.api;
        }
        if (window.__WelineThemeConfig && window.__WelineThemeConfig.api) {
            return window.__WelineThemeConfig.api;
        }
        if (window.WelineApiConfig) {
            console.warn('[Weline.Api] window.WelineApiConfig 已废弃，请通过 Theme.js 注入配置。');
            return window.WelineApiConfig;
        }
        return {};
    };
    
    // URL 解析辅助函数
    const resolveWorkerUrl = (path) => {
        if (!path) return null;
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        
        // 尝试使用 Weline.Url.resolve
        if (window.Weline && window.Weline.Url && typeof window.Weline.Url.resolve === 'function') {
            try {
                return window.Weline.Url.resolve(path, { type: 'frontend' });
            } catch (e) {
                // 如果解析失败，使用原始路径
            }
        }
        
        // Fallback: 直接返回绝对路径
        const normalizedOrigin = window.location.origin.replace(/\/+$/, '');
        const cleanPath = path.startsWith('/') ? path.slice(1) : path;
        return normalizedOrigin + '/' + cleanPath;
    };
    
    // 检测环境并生成默认 workerUrl
    const getDefaultWorkerUrl = () => {
        const isDev = window.DEV || (window.WELINE_ENV === 'DEV');
        if (isDev) {
            return '/Weline/Frontend/view/statics/js/weline-api-worker.js';
        }
        // 尝试使用静态资源解析器
        if (window.Weline && window.Weline.staticResourceResolver) {
            try {
                return window.Weline.staticResourceResolver.resolve('Weline_Frontend::js/weline-api-worker.js');
            } catch (e) {
                // 如果解析失败，使用默认路径
            }
        }
        return '/static/Weline/Frontend/js/weline-api-worker.js';
    };

    const apiConfig = getConfig();
    const config = Object.assign({
        baseUrl: window.location.origin,
        useCredentials: 'same-origin',
        cartFlagStorageKey: 'weline_cart_has_items',
        cartProbeSessionKey: 'weline_cart_probe_done',
        cartCountCookieKey: 'weline_cart_item_count',
        cartStatusUrl: null,
        cartStatusResolver: null,
        autoRequests: [],
        autoEnableOnCartClickSelector: '[data-weline-cart-trigger]',
        maintenanceHandler: null,
        // workerUrl 自动处理：优先使用配置中的，否则使用默认值
        workerUrl: apiConfig.workerUrl ? resolveWorkerUrl(apiConfig.workerUrl) : getDefaultWorkerUrl(),
    }, apiConfig);
    
    // 如果配置中提供了 workerUrl，需要解析
    if (apiConfig.workerUrl && !config.workerUrl) {
        config.workerUrl = resolveWorkerUrl(apiConfig.workerUrl);
    }

    class WelineApiClient {
        constructor(config) {
            this.config = config;
            this.worker = null;
            this.requestId = 0;
            this.pending = new Map();
            this.autoRequestsEnabled = false;

            this.handleWorkerMessage = this.handleWorkerMessage.bind(this);
            this.handleWorkerError = this.handleWorkerError.bind(this);

            this.restoreCartState();
            this.listenCartTriggers();
            this.listenCartUpdateEvent();
        }

        request(url, options = {}) {
            if (!url) {
                return Promise.reject(new Error('请求地址不能为空'));
            }

            this.ensureWorker();

            const resolvedUrl = this.resolveUrl(url);
            const messageId = this.buildMessageId();

            const payload = {
                id: messageId,
                url: resolvedUrl,
                options: this.prepareOptions(options),
            };

            return new Promise((resolve, reject) => {
                this.pending.set(messageId, { resolve, reject });
                this.worker?.postMessage(payload);
            });
        }

        ensureWorker() {
            if (this.worker) {
                return;
            }

            if (!this.config.workerUrl) {
                throw new Error('[Weline.Api] workerUrl 未配置，无法创建 Worker。');
            }

            this.worker = new Worker(this.config.workerUrl);
            this.worker.addEventListener('message', this.handleWorkerMessage);
            this.worker.addEventListener('error', this.handleWorkerError);
        }

        buildMessageId() {
            this.requestId += 1;
            return `req_${Date.now()}_${this.requestId}`;
        }

        prepareOptions(options) {
            const prepared = Object.assign({}, options);

            if (!prepared.method) {
                prepared.method = 'GET';
            }

            if (prepared.json === true && typeof prepared.body === 'object' && prepared.body !== null) {
                prepared.body = JSON.stringify(prepared.body);
                prepared.headers = Object.assign(
                    { 'Content-Type': 'application/json' },
                    prepared.headers || {}
                );
            }

            if (prepared.headers) {
                prepared.headers = this.normalizeHeaders(prepared.headers);
            }

            if (!prepared.credentials) {
                prepared.credentials = this.config.useCredentials;
            }

            if (prepared.body === undefined) {
                delete prepared.body;
            }

            delete prepared.json;
            delete prepared.signal;

            return prepared;
        }

        normalizeHeaders(headers) {
            const normalized = {};

            if (headers instanceof Headers) {
                headers.forEach((value, key) => {
                    normalized[key] = value ?? '';
                });
                return normalized;
            }

            Object.keys(headers).forEach((key) => {
                const value = headers[key];
                if (value !== undefined && value !== null) {
                    normalized[key] = String(value);
                }
            });

            return normalized;
        }

        resolveUrl(url) {
            if (/^https?:\/\//i.test(url)) {
                return url;
            }

            if (url.startsWith('/')) {
                return window.location.origin + url;
            }

            return `${(this.config.baseUrl || window.location.origin).replace(/\/$/, '')}/${url}`;
        }

        handleWorkerMessage(event) {
            const data = event.data || {};
            const pending = this.pending.get(data.id);
            if (!pending) {
                return;
            }

            this.pending.delete(data.id);

            if (data.maintenance) {
                this.handleMaintenance(data);
            }

            if (data.ok !== false && !data.error) {
                pending.resolve(this.buildResponse(data));
            } else {
                const error = new Error(data.error || data.statusText || '请求失败');
                error.response = this.buildResponse(data);
                pending.reject(error);
            }
        }

        handleWorkerError(event) {
            console.error('[Weline.Api] Worker 错误:', event);
        }

        buildResponse(data) {
            return {
                ok: data.ok !== false,
                status: data.status ?? 0,
                statusText: data.statusText ?? '',
                headers: this.collectHeaders(data.headers),
                data: data.body ?? null,
                maintenance: !!data.maintenance,
            };
        }

        collectHeaders(headers) {
            if (!headers) {
                return {};
            }

            if (Array.isArray(headers)) {
                return headers.reduce((accumulator, header) => {
                    if (Array.isArray(header) && header.length === 2) {
                        accumulator[header[0]] = header[1];
                    }
                    return accumulator;
                }, {});
            }

            return headers;
        }

        handleMaintenance(payload) {
            this.disableAutoRequests();
            const detail = {
                retryAfter: payload.body && payload.body.data ? payload.body.data.retry_after ?? null : null,
                message: payload.body && typeof payload.body === 'object' ? payload.body.message ?? '' : '',
                raw: payload,
            };

            window.dispatchEvent(new CustomEvent('weline:maintenance', { detail }));

            if (typeof this.config.maintenanceHandler === 'function') {
                try {
                    this.config.maintenanceHandler(detail);
                } catch (error) {
                    console.error('[Weline.Api] maintenanceHandler 执行失败:', error);
                }
            }
        }

        restoreCartState() {
            const storedFlag = localStorage.getItem(this.config.cartFlagStorageKey);
            if (storedFlag === 'true') {
                this.enableAutoRequests();
            }

            const cookieFlag = this.readCartCookie();
            if (cookieFlag === true) {
                this.markCartActive();
            } else if (cookieFlag === false && storedFlag !== 'true') {
                this.markCartEmpty();
            }

            if (sessionStorage.getItem(this.config.cartProbeSessionKey) !== 'true') {
                this.probeCartStatus();
                sessionStorage.setItem(this.config.cartProbeSessionKey, 'true');
            }
        }

        readCartCookie() {
            const key = this.config.cartCountCookieKey;
            if (!key) {
                return null;
            }

            const match = document.cookie.split('; ').find((row) => row.startsWith(`${key}=`));
            if (!match) {
                return null;
            }

            const value = parseInt(match.split('=')[1] ?? '0', 10);
            if (Number.isNaN(value)) {
                return null;
            }

            if (value > 0) {
                return true;
            }

            if (value === 0) {
                return false;
            }

            return null;
        }

        probeCartStatus() {
            const statusUrl = this.config.cartStatusUrl;
            if (!statusUrl) {
                return;
            }

            this.request(statusUrl, { method: 'GET' })
                .then((response) => {
                    const resolver = typeof this.config.cartStatusResolver === 'function'
                        ? this.config.cartStatusResolver
                        : defaultCartStatusResolver;
                    if (resolver(response)) {
                        this.markCartActive();
                    } else {
                        this.markCartEmpty();
                    }
                })
                .catch((error) => {
                    console.warn('[Weline.Api] 购物车状态检查失败:', error);
                });
        }

        listenCartTriggers() {
            document.addEventListener('click', (event) => {
                const selector = this.config.autoEnableOnCartClickSelector;
                if (!selector || !(event.target instanceof Element)) {
                    return;
                }

                if (event.target.closest(selector)) {
                    this.markCartActive();
                }
            }, { passive: true });
        }

        listenCartUpdateEvent() {
            window.addEventListener('weline:cart:update', (event) => {
                const detail = event.detail || {};
                const count = typeof detail.count === 'number' ? detail.count : null;
                if (count !== null) {
                    if (count > 0) {
                        this.markCartActive();
                    } else {
                        this.markCartEmpty();
                    }
                }
            });
        }

        markCartActive() {
            localStorage.setItem(this.config.cartFlagStorageKey, 'true');
            this.enableAutoRequests();
        }

        markCartEmpty() {
            localStorage.removeItem(this.config.cartFlagStorageKey);
            this.disableAutoRequests();
        }

        enableAutoRequests() {
            if (this.autoRequestsEnabled) {
                return;
            }

            this.autoRequestsEnabled = true;
            this.ensureWorker();
            this.scheduleAutoRequests();
            window.dispatchEvent(new CustomEvent('weline:api:auto-enabled'));
        }

        disableAutoRequests() {
            if (!this.autoRequestsEnabled) {
                return;
            }

            this.autoRequestsEnabled = false;
            window.dispatchEvent(new CustomEvent('weline:api:auto-disabled'));
        }

        scheduleAutoRequests() {
            if (!this.autoRequestsEnabled) {
                return;
            }

            const tasks = Array.isArray(this.config.autoRequests) ? this.config.autoRequests : [];
            tasks.forEach((task) => {
                if (!task || !task.url) {
                    return;
                }

                const options = task.options || { method: 'GET' };
                this.request(task.url, options).catch((error) => {
                    console.warn('[Weline.Api] 自动请求失败:', error);
                });
            });
        }
    }

    function defaultCartStatusResolver(response) {
        if (!response || typeof response !== 'object') {
            return false;
        }

        const data = response.data;
        if (!data || typeof data !== 'object') {
            return false;
        }

        if (typeof data.has_items !== 'undefined') {
            return !!data.has_items;
        }

        if (typeof data.hasItems !== 'undefined') {
            return !!data.hasItems;
        }

        if (typeof data.count !== 'undefined') {
            return Number(data.count) > 0;
        }

        if (typeof data.total !== 'undefined') {
            return Number(data.total) > 0;
        }

        return false;
    }

    // 创建客户端实例
    const client = new WelineApiClient(config);

    // 导出模块接口
    const ApiModule = {
        request: (url, options) => client.request(url, options),
        markCartActive: () => client.markCartActive(),
        markCartEmpty: () => client.markCartEmpty(),
        enableAutoRequests: () => client.enableAutoRequests(),
        disableAutoRequests: () => client.disableAutoRequests(),
        getClient: () => client,
    };

    // 挂载到全局，供 theme.js 加载
    window.WelineApiModule = ApiModule;
})(window);
