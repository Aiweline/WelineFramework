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

    const isDevMode = () => !!(window.DEV || window.WELINE_ENV === 'DEV');

    const summarizeApiPayload = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return 'unknown';
        }
        if (payload.type === 'call') {
            return `call ${payload.provider}.${payload.operation}`;
        }
        if (payload.type === 'graph') {
            return 'graph';
        }
        if (payload.type === 'stream-ticket') {
            return `stream-ticket ${payload.channel || ''}`.trim();
        }
        if (payload.type === 'upload') {
            return `upload ${payload.provider}.${payload.operation}`;
        }
        return String(payload.type || 'request');
    };

    const cloneDevLogValue = (value) => {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return value;
        }
    };

    const isBusinessFailure = (data) => {
        return !!(data && typeof data === 'object' && !Array.isArray(data) && data.success === false);
    };

    const extractBusinessMessage = (data, fallback) => {
        if (!isBusinessFailure(data)) {
            return '';
        }
        const message = String(
            data.message
            || data.msg
            || (data.error && data.error.message)
            || fallback
            || ''
        ).trim();
        return message || String(fallback || '请求失败');
    };

    const withDevCacheBust = (url) => {
        const isDev = isDevMode() || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        if (!isDev) {
            return url;
        }
        const workerUrl = new URL(url, window.location.origin);
        if (!workerUrl.searchParams.has('_weline_worker_dev')) {
            workerUrl.searchParams.set('_weline_worker_dev', String(Date.now()));
        }
        return workerUrl.href;
    };

    const getDefaultWorkerUrl = () => {
        const isDev = isDevMode();
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

    const cloneWorkerValue = (value, depth = 0) => {
        if (depth > 8 || value === undefined || typeof value === 'function' || typeof value === 'symbol') {
            return undefined;
        }
        if (value === null || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            return value;
        }
        if (Array.isArray(value)) {
            return value
                .map(item => cloneWorkerValue(item, depth + 1))
                .filter(item => item !== undefined);
        }
        if (typeof value === 'object') {
            if (value instanceof Date) {
                return value.toISOString();
            }
            if (typeof Element !== 'undefined' && value instanceof Element) {
                return undefined;
            }
            const cloned = {};
            Object.keys(value).forEach(key => {
                const clonedValue = cloneWorkerValue(value[key], depth + 1);
                if (clonedValue !== undefined) {
                    cloned[key] = clonedValue;
                }
            });
            return cloned;
        }
        return undefined;
    };

    const sanitizeOptionsForWorker = (options) => {
        if (!options || typeof options !== 'object') {
            return {};
        }
        const sanitized = cloneWorkerValue(options);
        return sanitized && typeof sanitized === 'object' && !Array.isArray(sanitized) ? sanitized : {};
    };

    const sanitizePayloadForWorker = (payload) => {
        const sanitized = {};
        Object.keys(payload || {}).forEach(key => {
            if (key === 'options') {
                const workerOptions = sanitizeOptionsForWorker(payload[key]);
                if (Object.keys(workerOptions).length > 0) {
                    sanitized.options = workerOptions;
                }
                return;
            }
            const clonedValue = cloneWorkerValue(payload[key]);
            if (clonedValue !== undefined) {
                sanitized[key] = clonedValue;
            }
        });
        return sanitized;
    };

    const isFormData = (value) => {
        return typeof FormData !== 'undefined' && value instanceof FormData;
    };

    const normalizeCallParams = (params) => {
        if (!params || typeof params !== 'object' || Array.isArray(params) || isFormData(params)) {
            return params || {};
        }

        const normalized = {};
        Object.keys(params).forEach(key => {
            if (key === 'form_key' || key === 'csrf_token' || key === '_token') {
                return;
            }
            normalized[key] = params[key];
        });

        return normalized;
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
        area: '',
        onHttpError: null,
        requestTimeoutMs: 15000,
    }, apiConfig);

    config.workerUrl = withDevCacheBust(sameOriginUrl(apiConfig.workerUrl || getDefaultWorkerUrl(), getDefaultWorkerUrl()));
    config.endpoint = sameOriginUrl(apiConfig.endpoint || apiConfig.queryBinUrl || '/api/framework/query-bin', '/api/framework/query-bin');
    config.deployVersion = String(apiConfig.deployVersion || apiConfig.deploy_version || config.deployVersion || 'dev');
    config.workerBuildId = String(apiConfig.workerBuildId || apiConfig.worker_build_id || config.workerBuildId || 'dev');
    config.area = String(apiConfig.area || apiConfig.context || config.area || '');

    class WelineApiClient {
        constructor(clientConfig) {
            this.config = clientConfig;
            this.worker = null;
            this.requestId = 0;
            this.pending = new Map();
            this.devTraces = new Map();
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
                params: normalizeCallParams(params),
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
                params: normalizeCallParams(params),
                options,
            });
            if (!ticket || !ticket.url) {
                throw new Error('[Weline.Api] stream ticket did not include a stream URL.');
            }
            const url = sameOriginUrl(ticket.url, ticket.url);
            return new EventSource(url, { withCredentials: options.withCredentials !== false });
        }

        async upload(provider, operation, formData, options = {}) {
            if (!provider || !operation) {
                throw new Error('[Weline.Api] upload provider and operation are required.');
            }
            if (!isFormData(formData)) {
                throw new Error('[Weline.Api] upload expects FormData.');
            }

            const ticket = await this.call(provider, `${operation}Ticket`, {}, options);
            if (!ticket || !ticket.url || !ticket.ticket) {
                throw new Error('[Weline.Api] upload ticket response is invalid.');
            }

            const uploadTraceStartedAt = isDevMode() ? performance.now() : 0;
            const uploadRequestPayload = {
                type: 'upload',
                provider,
                operation,
                ticketUrl: ticket.url,
            };

            const headers = Object.assign({}, ticket.headers || {}, {
                'X-Weline-Upload-Ticket': String(ticket.ticket),
                'X-Weline-Upload-Provider': String(provider),
                'X-Weline-Upload-Operation': String(operation),
            });
            const response = await fetch(sameOriginUrl(ticket.url, ticket.url), {
                method: ticket.method || 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers,
                body: formData,
            });
            const responseContentType = response.headers.get('content-type') || '';
            const body = responseContentType.includes('application/json')
                ? await response.json()
                : { code: response.ok ? 200 : response.status, msg: await response.text(), data: null };

            const failed = !response.ok || (body && typeof body.code !== 'undefined' && Number(body.code) >= 400) || body?.success === false;
            this.finishDevTrace(null, {
                ok: !failed,
                status: response.status,
                statusText: response.statusText || '',
                data: body,
            }, uploadRequestPayload, uploadTraceStartedAt);
            if (failed) {
                const error = new Error((body && (body.message || body.msg)) || response.statusText || 'Weline upload failed.');
                error.code = body?.error?.code || 'business_error';
                error.status = response.status || 0;
                error.response = {
                    ok: false,
                    status: response.status || 0,
                    statusText: response.statusText || '',
                    data: body,
                    maintenance: false,
                };
                this.reportDevError(error, { type: 'upload', provider, operation, options, skipConsole: true });
                this.handleHttpError(error.status, error, options && options.silent, options);
                throw error;
            }

            return body;
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
                    return (params = {}, options = {}) => {
                        if (isFormData(params)) {
                            return client.upload(provider, operation, params, options);
                        }
                        return client.call(provider, operation, params, options);
                    };
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
                const configuredTimeout = parseInt(this.config.requestTimeoutMs || 15000, 10);
                const timeoutMs = Number.isFinite(configuredTimeout) ? Math.max(1000, configuredTimeout) : 15000;
                const timeoutId = window.setTimeout(() => {
                    if (!this.pending.has(messageId)) {
                        return;
                    }
                    const pending = this.pending.get(messageId);
                    this.pending.delete(messageId);
                    const error = new Error('[Weline.Api] worker request timed out.');
                    error.code = 'worker_timeout';
                    this.finishDevTrace(messageId, {
                        ok: false,
                        error: { code: error.code, message: error.message },
                    });
                    this.reportDevError(error, { type: 'timeout', request: pending && pending.payload, skipConsole: true });
                    reject(error);
                }, timeoutMs);
                this.pending.set(messageId, { resolve, reject, payload, timeoutId });
                const workerPayload = sanitizePayloadForWorker(payload);
                this.beginDevTrace(messageId, payload);
                this.worker.postMessage(Object.assign({}, workerPayload, {
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
            this.worker.addEventListener('messageerror', this.handleWorkerError);
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
            if (pending.timeoutId) {
                window.clearTimeout(pending.timeoutId);
            }

            const wrapper = data.body || {};
            if (data.ok === true && wrapper.ok === true) {
                const businessData = wrapper.data;
                const businessFailed = isBusinessFailure(businessData);
                const requestOptions = pending.payload && pending.payload.options;
                const keepBusinessResult = !!(requestOptions && requestOptions.keepBusinessResult);

                if (businessFailed && !keepBusinessResult) {
                    const bizMessage = extractBusinessMessage(businessData, '请求失败');
                    const error = new Error(bizMessage);
                    error.code = (businessData.error && businessData.error.code) || businessData.code || 'business_error';
                    error.status = data.status || 200;
                    error.response = {
                        ok: false,
                        status: data.status || 200,
                        statusText: data.statusText || '',
                        data: wrapper,
                        maintenance: !!data.maintenance,
                    };
                    this.finishDevTrace(data.id, {
                        ok: false,
                        status: error.status,
                        statusText: data.statusText || '',
                        error: { code: error.code, message: bizMessage },
                        data: businessData,
                        headers: data.headers || {},
                    });
                    this.reportDevError(error, {
                        status: error.status,
                        silent: !!(requestOptions && requestOptions.silent),
                        request: pending.payload,
                        workerMessage: data,
                        skipConsole: true,
                    });
                    this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions);
                    pending.reject(error);
                    return;
                }

                this.finishDevTrace(data.id, {
                    ok: !businessFailed,
                    status: data.status || 200,
                    statusText: data.statusText || '',
                    data: wrapper.data,
                    request_id: wrapper.request_id || '',
                    headers: data.headers || {},
                    ...(businessFailed ? { error: { message: extractBusinessMessage(businessData, '请求失败') } } : {}),
                });
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
            const requestOptions = pending.payload && pending.payload.options;
            this.finishDevTrace(data.id, {
                ok: false,
                status: error.status,
                statusText: data.statusText || '',
                error: serverError,
                body: wrapper,
                headers: data.headers || {},
            });
            this.reportDevError(error, {
                status: error.status,
                silent: !!(requestOptions && requestOptions.silent),
                request: pending.payload,
                workerMessage: data,
                skipConsole: true,
            });
            this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions);
            pending.reject(error);
        }

        handleWorkerError(event) {
            const message = event && event.message ? event.message : '[Weline.Api] worker failed.';
            const workerDetail = {
                message: event && event.message,
                filename: event && event.filename,
                lineno: event && event.lineno,
                colno: event && event.colno,
                error: event && event.error,
            };
            for (const [id, pending] of this.pending.entries()) {
                this.pending.delete(id);
                if (pending.timeoutId) {
                    window.clearTimeout(pending.timeoutId);
                }
                const error = new Error(message);
                error.code = 'worker_error';
                this.finishDevTrace(id, {
                    ok: false,
                    error: { code: error.code, message: error.message },
                    worker: workerDetail,
                });
                this.reportDevError(error, Object.assign({
                    type: 'worker_crash',
                    requestId: id,
                    request: pending.payload,
                    skipConsole: true,
                }, workerDetail));
                pending.reject(error);
            }
        }

        handleHttpError(status, error, silent, requestOptions) {
            if (silent) return;
            const requestCb = requestOptions && (requestOptions.onError || requestOptions.onHttpError);
            if (typeof requestCb === 'function') {
                try {
                    requestCb.call(null, status, error);
                } catch (callbackError) {
                    console.error('[Weline.Api] request error callback failed:', callbackError);
                }
                return;
            }
            if (typeof this.config.onHttpError === 'function') {
                try {
                    this.config.onHttpError(status, error, silent);
                    return;
                } catch (callbackError) {
                    console.error('[Weline.Api] onHttpError failed:', callbackError);
                }
            }
            this.showDefaultError(error);
        }

        reportDevError(error, detail) {
            if (!isDevMode() || !error) {
                return;
            }
            if (error._welineApiDevLogged) {
                return;
            }
            error._welineApiDevLogged = true;
            if (detail && detail.skipConsole) {
                return;
            }
            this.logDevError('request failed', error, detail);
        }

        beginDevTrace(messageId, payload) {
            if (!isDevMode() || !messageId) {
                return;
            }
            this.devTraces.set(messageId, {
                startedAt: performance.now(),
                payload: payload || {},
            });
        }

        finishDevTrace(messageId, responseMeta, requestPayloadOverride, startedAtOverride) {
            if (!isDevMode()) {
                return;
            }

            let trace = null;
            if (messageId) {
                trace = this.devTraces.get(messageId);
                this.devTraces.delete(messageId);
            }

            const requestPayload = requestPayloadOverride || (trace && trace.payload) || {};
            const startedAt = startedAtOverride || (trace && trace.startedAt) || performance.now();
            const durationMs = Math.max(0, Math.round(performance.now() - startedAt));
            const summary = summarizeApiPayload(requestPayload);
            const ok = !!(responseMeta && responseMeta.ok === true);
            const status = responseMeta && responseMeta.status ? ` ${responseMeta.status}` : '';
            const label = `[Weline.Api] ${ok ? '✓' : '✗'} ${summary}${status} · ${durationMs}ms`;

            const requestLog = cloneDevLogValue(sanitizePayloadForWorker(requestPayload));
            const responseLog = cloneDevLogValue(responseMeta || {});

            if (typeof console.groupCollapsed === 'function') {
                console.groupCollapsed(label);
                console.log('endpoint:', this.config.endpoint);
                console.log('request:', requestLog);
                console.log('response:', responseLog);
                console.groupEnd();
                return;
            }
            console.log(label, { endpoint: this.config.endpoint, request: requestLog, response: responseLog });
        }

        logDevError(context, error, detail) {
            const label = `[Weline.Api] ${context}`;
            const extra = detail && typeof detail === 'object' ? detail : {};
            if (typeof console.groupCollapsed === 'function') {
                console.groupCollapsed(label);
                console.error(error);
                if (error && error.response) {
                    console.log('response:', error.response);
                }
                if (Object.keys(extra).length > 0) {
                    console.log('detail:', extra);
                }
                console.groupEnd();
                return;
            }
            console.error(label, error, error && error.response ? { response: error.response } : null, extra);
        }

        showDefaultError(error) {
            const message = error && error.message ? error.message : 'Request failed';
            const Toast = this.resolveToastComponent();
            if (Toast) {
                if (typeof Toast.error === 'function') {
                    Toast.error(message);
                    return;
                }
                if (typeof Toast.show === 'function') {
                    Toast.show(message, 'error');
                    return;
                }
            }

            if (typeof window.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('weline:api:error', {
                    detail: { message, error },
                }));
            }

            this.renderFallbackToast(message);
        }

        resolveToastComponent() {
            const area = this.resolveArea();
            const backendCandidates = [
                window.BackendToast,
                window.AdminToast,
                window.WelineBackendToast,
                window.Weline?.BackendToast,
                window.Weline?.Toast,
                window.Toast,
            ];
            const frontendCandidates = [
                window.FrontendToast,
                window.WelineFrontendToast,
                window.Weline?.FrontendToast,
                window.WeShop && typeof window.WeShop.showNotification === 'function'
                    ? { show: window.WeShop.showNotification.bind(window.WeShop) }
                    : null,
                window.Weline?.Toast,
                window.Toast,
            ];
            const candidates = area === 'backend'
                ? backendCandidates.concat(frontendCandidates)
                : frontendCandidates.concat(backendCandidates);

            return candidates.find((candidate) => {
                return candidate && (typeof candidate.error === 'function' || typeof candidate.show === 'function');
            }) || null;
        }

        resolveArea() {
            const explicitArea = String(
                this.config.area
                || window.WELINE_AREA
                || window.welineArea
                || (document.body && (document.body.dataset.welineArea || document.body.dataset.area))
                || ''
            ).toLowerCase();
            if (explicitArea.includes('backend') || explicitArea.includes('admin')) {
                return 'backend';
            }
            if (explicitArea.includes('frontend')) {
                return 'frontend';
            }
            const path = window.location && window.location.pathname ? window.location.pathname.toLowerCase() : '';
            return path.includes('/admin') || path.includes('/backend') ? 'backend' : 'frontend';
        }

        renderFallbackToast(message) {
            if (!document || !document.body) {
                console.error('[Weline.Api]', message);
                return;
            }

            const containerId = 'weline-api-toast-container';
            let container = document.getElementById(containerId);
            if (!container) {
                container = document.createElement('div');
                container.id = containerId;
                container.style.cssText = [
                    'position:fixed',
                    'right:1rem',
                    'bottom:1rem',
                    'z-index:2147483647',
                    'display:grid',
                    'gap:.5rem',
                    'max-width:min(28rem,calc(100vw - 2rem))',
                ].join(';');
                document.body.appendChild(container);
            }

            const item = document.createElement('div');
            item.textContent = message;
            item.style.cssText = [
                'border-radius:.75rem',
                'background:#b91c1c',
                'color:#fff',
                'box-shadow:0 16px 40px rgba(15,23,42,.22)',
                'font:600 14px/1.45 sans-serif',
                'padding:.85rem 1rem',
            ].join(';');
            container.appendChild(item);
            window.setTimeout(() => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(8px)';
                item.style.transition = 'opacity .18s ease, transform .18s ease';
                window.setTimeout(() => item.remove(), 220);
            }, 3500);
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
        upload: (provider, operation, formData, options) => client.upload(provider, operation, formData, options),
        resource: (provider, optionalMap) => client.resource(provider, optionalMap),
        markCartActive: () => client.markCartActive(),
        markCartEmpty: () => client.markCartEmpty(),
        enableAutoRequests: () => client.enableAutoRequests(),
        disableAutoRequests: () => client.disableAutoRequests(),
        getClient: () => client,
    };

    window.WelineApiModule = ApiModule;
    window.Weline = window.Weline || {};
    if (!window.Weline.Api || window.Weline.Api.__fallback === true) {
        window.Weline.Api = ApiModule;
    }
})(window);
