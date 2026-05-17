/**
 * Weline Api Module
 *
 * Browser business requests are only allowed through:
 * theme.js -> Weline.Api -> worker -> /api/framework/query-bin.
 */
(function (window) {
    'use strict';

    const RESERVED_METHODS = new Set([
        'then',
        'catch',
        'finally',
        '__proto__',
        'prototype',
        'constructor',
        'toString',
        'valueOf',
    ]);

    const getConfig = () => {
        if (window.Weline && window.Weline.config && window.Weline.config.api) {
            return window.Weline.config.api;
        }
        if (window.__WelineThemeConfig && window.__WelineThemeConfig.api) {
            return window.__WelineThemeConfig.api;
        }
        if (window.WelineApiConfig) {
            return window.WelineApiConfig;
        }
        return {};
    };

    const sameOriginUrl = (path, fallbackPath) => {
        const value = path || fallbackPath;
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) {
            throw new Error('[Weline.Api] worker and query-bin URLs must be same-origin.');
        }
        return url.href;
    };

    const getDefaultWorkerUrl = () => {
        const isDev = window.DEV || window.WELINE_ENV === 'DEV';
        if (isDev) {
            return '/Weline/Frontend/view/statics/js/weline-api-worker.js';
        }
        if (window.Weline && window.Weline.staticResourceResolver) {
            try {
                return window.Weline.staticResourceResolver.resolve('Weline_Frontend::js/weline-api-worker.js');
            } catch (error) {
                /* fall through */
            }
        }
        return '/static/Weline/Frontend/js/weline-api-worker.js';
    };

    const apiConfig = getConfig();
    const config = Object.assign({
        endpoint: '/api/framework/query-bin',
        deployVersion: 'dev',
        workerBuildId: 'dev',
        cartFlagStorageKey: 'weline_cart_has_items',
        cartProbeSessionKey: 'weline_cart_probe_done',
        cartCountCookieKey: 'weline_cart_item_count',
        autoEnableOnCartClickSelector: '[data-weline-cart-trigger]',
        onHttpError: null,
    }, apiConfig);

    config.workerUrl = sameOriginUrl(apiConfig.workerUrl || getDefaultWorkerUrl(), getDefaultWorkerUrl());
    config.endpoint = sameOriginUrl(apiConfig.endpoint || apiConfig.queryBinUrl || '/api/framework/query-bin', '/api/framework/query-bin');
    config.deployVersion = String(apiConfig.deployVersion || apiConfig.deploy_version || config.deployVersion || 'dev');
    config.workerBuildId = String(apiConfig.workerBuildId || apiConfig.worker_build_id || config.workerBuildId || 'dev');

    class WelineApiClient {
        constructor(clientConfig) {
            this.config = clientConfig;
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

        call(provider, operation, params = {}, options = {}) {
            if (!provider || !operation) {
                return Promise.reject(new Error('[Weline.Api] provider and operation are required.'));
            }
            return this.send({
                type: 'call',
                provider,
                operation,
                params: params || {},
                options,
            });
        }

        graph(graph, options = {}) {
            return this.send({
                type: 'graph',
                graph: graph || {},
                options,
            });
        }

        async stream(channel, params = {}, options = {}) {
            const ticket = await this.send({
                type: 'stream-ticket',
                channel,
                params: params || {},
                options,
            });
            if (!ticket || !ticket.url) {
                throw new Error('[Weline.Api] stream ticket did not include a stream URL.');
            }
            const url = sameOriginUrl(ticket.url, ticket.url);
            return new EventSource(url, { withCredentials: options.withCredentials !== false });
        }

        resource(provider, optionalMap) {
            if (!provider || typeof provider !== 'string') {
                throw new Error('[Weline.Api] resource provider is required.');
            }
            const methodMap = optionalMap && typeof optionalMap === 'object' ? optionalMap : null;
            const client = this;

            return new Proxy(Object.create(null), {
                get(_target, property) {
                    if (typeof property === 'symbol' || RESERVED_METHODS.has(property)) {
                        return undefined;
                    }
                    const methodName = String(property);
                    if (methodMap && !Object.prototype.hasOwnProperty.call(methodMap, methodName)) {
                        return undefined;
                    }
                    const operation = methodMap ? String(methodMap[methodName]) : methodName;
                    if (!operation) {
                        return undefined;
                    }
                    return (params = {}, options = {}) => client.call(provider, operation, params, options);
                },
                has(_target, property) {
                    if (typeof property !== 'string' || RESERVED_METHODS.has(property)) {
                        return false;
                    }
                    return !methodMap || Object.prototype.hasOwnProperty.call(methodMap, property);
                },
            });
        }

        request() {
            return Promise.reject(new Error('[Weline.Api] direct request(url) is disabled. Use Weline.Api.resource()/call()/graph()/stream().'));
        }

        send(payload) {
            this.ensureWorker();
            const messageId = this.buildMessageId();
            return new Promise((resolve, reject) => {
                this.pending.set(messageId, { resolve, reject, payload });
                this.worker.postMessage(Object.assign({}, payload, {
                    id: messageId,
                    config: {
                        endpoint: this.config.endpoint,
                        deployVersion: this.config.deployVersion,
                        workerBuildId: this.config.workerBuildId,
                    },
                }));
            });
        }

        ensureWorker() {
            if (this.worker) {
                return;
            }
            if (!window.Worker) {
                throw new Error('[Weline.Api] Worker is unavailable; direct frontend API fallback is disabled.');
            }
            if (!this.config.workerUrl) {
                throw new Error('[Weline.Api] workerUrl is not configured.');
            }

            this.worker = new Worker(this.config.workerUrl);
            this.worker.addEventListener('message', this.handleWorkerMessage);
            this.worker.addEventListener('error', this.handleWorkerError);
        }

        buildMessageId() {
            this.requestId += 1;
            return `req_${Date.now()}_${this.requestId}`;
        }

        handleWorkerMessage(event) {
            const data = event.data || {};
            const pending = this.pending.get(data.id);
            if (!pending) {
                return;
            }
            this.pending.delete(data.id);

            const wrapper = data.body || {};
            if (data.ok === true && wrapper.ok === true) {
                pending.resolve(wrapper.data);
                return;
            }

            const serverError = wrapper.error || {};
            const error = new Error(serverError.message || data.error || 'Weline worker API request failed.');
            error.code = serverError.code || 'protocol_error';
            error.status = data.status || 0;
            error.response = {
                ok: false,
                status: data.status || 0,
                statusText: data.statusText || '',
                data: wrapper,
                maintenance: !!data.maintenance,
            };
            this.handleHttpError(error.status, error, pending.payload && pending.payload.options && pending.payload.options.silent, pending.payload && pending.payload.options);
            pending.reject(error);
        }

        handleWorkerError(event) {
            console.error('[Weline.Api] worker error:', event);
        }

        handleHttpError(status, error, silent, requestOptions) {
            if (silent) return;
            const requestCb = requestOptions && (requestOptions.onError || requestOptions.onHttpError);
            if (typeof requestCb === 'function') {
                try {
                    if (requestCb.call(null, status, error) === true) {
                        return;
                    }
                } catch (callbackError) {
                    console.error('[Weline.Api] request error callback failed:', callbackError);
                }
            }
            if (typeof this.config.onHttpError === 'function') {
                try {
                    this.config.onHttpError(status, error, silent);
                    return;
                } catch (callbackError) {
                    console.error('[Weline.Api] onHttpError failed:', callbackError);
                }
            }
            const Toast = window.BackendToast || window.FrontendToast;
            if (Toast && typeof Toast.error === 'function') {
                Toast.error(error.message || 'Request failed');
            }
        }

        restoreCartState() {
            try {
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
            } catch (error) {
                /* storage may be unavailable */
            }
        }

        readCartCookie() {
            const key = this.config.cartCountCookieKey;
            if (!key || !document.cookie) {
                return null;
            }
            const match = document.cookie.split('; ').find((row) => row.startsWith(`${key}=`));
            if (!match) {
                return null;
            }
            const value = parseInt(match.split('=')[1] || '0', 10);
            if (Number.isNaN(value)) {
                return null;
            }
            return value > 0 ? true : value === 0 ? false : null;
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
                if (count === null) {
                    return;
                }
                if (count > 0) {
                    this.markCartActive();
                } else {
                    this.markCartEmpty();
                }
            });
        }

        markCartActive() {
            try {
                localStorage.setItem(this.config.cartFlagStorageKey, 'true');
            } catch (error) {
                /* storage may be unavailable */
            }
            this.enableAutoRequests();
        }

        markCartEmpty() {
            try {
                localStorage.removeItem(this.config.cartFlagStorageKey);
            } catch (error) {
                /* storage may be unavailable */
            }
            this.disableAutoRequests();
        }

        enableAutoRequests() {
            if (this.autoRequestsEnabled) {
                return;
            }
            this.autoRequestsEnabled = true;
            window.dispatchEvent(new CustomEvent('weline:api:auto-enabled'));
        }

        disableAutoRequests() {
            if (!this.autoRequestsEnabled) {
                return;
            }
            this.autoRequestsEnabled = false;
            window.dispatchEvent(new CustomEvent('weline:api:auto-disabled'));
        }
    }

    const client = new WelineApiClient(config);

    const ApiModule = {
        __full: true,
        request: () => client.request(),
        get: () => client.request(),
        post: () => client.request(),
        call: (provider, operation, params, options) => client.call(provider, operation, params, options),
        graph: (graph, options) => client.graph(graph, options),
        stream: (channel, params, options) => client.stream(channel, params, options),
        resource: (provider, optionalMap) => client.resource(provider, optionalMap),
        markCartActive: () => client.markCartActive(),
        markCartEmpty: () => client.markCartEmpty(),
        enableAutoRequests: () => client.enableAutoRequests(),
        disableAutoRequests: () => client.disableAutoRequests(),
        getClient: () => client,
    };

    window.WelineApiModule = ApiModule;
})(window);
