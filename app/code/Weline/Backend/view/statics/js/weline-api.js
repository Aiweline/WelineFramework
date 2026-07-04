/**
 * Weline backend Api module.
 *
 * Backend pages keep the public Weline.Api name, while requests are executed
 * by the backend worker module.
 */
(function (window) {
    'use strict';

    var RESERVED_METHODS = {
        then: true,
        catch: true,
        finally: true,
        __proto__: true,
        prototype: true,
        constructor: true,
        toString: true,
        valueOf: true
    };

    function isDevMode() {
        return !!(window.DEV || window.WELINE_ENV === 'DEV' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
    }

    function currentScriptUrl() {
        var script = document.currentScript;
        if (script && script.src) {
            return script.src;
        }
        var scripts = document.getElementsByTagName('script');
        for (var index = scripts.length - 1; index >= 0; index -= 1) {
            var src = scripts[index].src || '';
            if (src.indexOf('/weline-api.js') !== -1 || src.indexOf('/weline-api.js?') !== -1) {
                return src;
            }
        }
        return '';
    }

    function defaultWorkerUrl() {
        var scriptUrl = currentScriptUrl();
        if (scriptUrl) {
            return scriptUrl.replace(/weline-api\.js(\?.*)?$/, 'weline-api-worker.js$1');
        }
        return isDevMode()
            ? '/Weline/Backend/view/statics/js/weline-api-worker.js'
            : '/static/Weline/Backend/js/weline-api-worker.js';
    }

    function defaultQueryWorkerUrl() {
        if (window.Weline && window.Weline.staticResourceResolver && typeof window.Weline.staticResourceResolver.resolve === 'function') {
            try {
                return window.Weline.staticResourceResolver.resolve('Weline_Frontend::js/weline-api-worker.js');
            } catch (error) {
                /* fallback below */
            }
        }
        return isDevMode()
            ? '/Weline/Frontend/view/statics/js/weline-api-worker.js'
            : '/static/Weline/Frontend/js/weline-api-worker.js';
    }

    function withDevCacheBust(url) {
        if (!isDevMode()) {
            return url;
        }
        var workerUrl = new URL(url, window.location.origin);
        if (!workerUrl.searchParams.has('_weline_backend_worker_dev')) {
            workerUrl.searchParams.set('_weline_backend_worker_dev', String(Date.now()));
        }
        return workerUrl.href;
    }

    function sameOriginUrl(value) {
        var url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) {
            throw new Error('[Weline.Api] backend worker requests must be same-origin.');
        }
        return url.href;
    }

    function hasHeader(headers, name) {
        var target = String(name).toLowerCase();
        return Object.keys(headers || {}).some(function (key) {
            return String(key).toLowerCase() === target;
        });
    }

    function setHeader(headers, name, value) {
        if (!hasHeader(headers, name)) {
            headers[name] = value;
        }
        return headers;
    }

    function normalizeHeaders(headers) {
        var normalized = {};
        Object.keys(headers || {}).forEach(function (key) {
            if (headers[key] !== undefined && headers[key] !== null) {
                normalized[key] = String(headers[key]);
            }
        });
        setHeader(normalized, 'X-Requested-With', 'XMLHttpRequest');
        if (window.site && window.site.csrf_token) {
            setHeader(normalized, 'X-CSRF-TOKEN', String(window.site.csrf_token));
        }
        return normalized;
    }

    function isFormData(value) {
        return typeof window.FormData !== 'undefined' && value instanceof window.FormData;
    }

    function isUrlSearchParams(value) {
        return typeof window.URLSearchParams !== 'undefined' && value instanceof window.URLSearchParams;
    }

    function isBlob(value) {
        return typeof window.Blob !== 'undefined' && value instanceof window.Blob;
    }

    function isArrayBuffer(value) {
        return value instanceof ArrayBuffer || (ArrayBuffer.isView(value) && value.buffer instanceof ArrayBuffer);
    }

    function serializeFormData(formData) {
        var entries = [];
        formData.forEach(function (value, key) {
            if (isBlob(value)) {
                entries.push({
                    name: key,
                    kind: 'blob',
                    value: value,
                    filename: value.name || 'blob'
                });
                return;
            }
            entries.push({
                name: key,
                kind: 'text',
                value: String(value)
            });
        });
        return {
            type: 'formData',
            entries: entries
        };
    }

    function serializeBody(body, headers) {
        if (body === undefined || body === null) {
            return null;
        }
        if (isFormData(body)) {
            return serializeFormData(body);
        }
        if (isUrlSearchParams(body)) {
            setHeader(headers, 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            return {
                type: 'text',
                value: body.toString()
            };
        }
        if (typeof body === 'string' || isBlob(body) || isArrayBuffer(body)) {
            return {
                type: 'raw',
                value: body
            };
        }
        if (typeof body === 'object') {
            setHeader(headers, 'Content-Type', 'application/json; charset=UTF-8');
            return {
                type: 'text',
                value: JSON.stringify(body)
            };
        }
        return {
            type: 'text',
            value: String(body)
        };
    }

    function removeHeader(headers, name) {
        var target = String(name).toLowerCase();
        Object.keys(headers || {}).forEach(function (key) {
            if (String(key).toLowerCase() === target) {
                delete headers[key];
            }
        });
    }

    function deserializeBodyForFetch(body, headers) {
        if (!body) {
            return null;
        }
        if (body.type === 'formData') {
            removeHeader(headers, 'Content-Type');
            var formData = new FormData();
            (body.entries || []).forEach(function (entry) {
                if (entry.kind === 'blob') {
                    formData.append(entry.name, entry.value, entry.filename || 'blob');
                    return;
                }
                formData.append(entry.name, entry.value == null ? '' : String(entry.value));
            });
            return formData;
        }
        if (body.type === 'text' || body.type === 'raw') {
            return body.value;
        }
        return null;
    }

    function normalizeOptions(options) {
        var requestOptions = Object.assign({}, options || {});
        var method = String(requestOptions.method || 'GET').toUpperCase();
        var headers = normalizeHeaders(requestOptions.headers || {});
        var serializedBody = method === 'GET' || method === 'HEAD'
            ? null
            : serializeBody(requestOptions.body, headers);

        return {
            method: method,
            headers: headers,
            body: serializedBody,
            credentials: requestOptions.credentials || 'same-origin',
            cache: requestOptions.cache || 'no-store',
            redirect: requestOptions.redirect || 'follow'
        };
    }

    function getRuntimeConfig() {
        return (window.Weline && window.Weline.config) || window.__WelineThemeConfig || {};
    }

    function mergeApiConfig() {
        var runtimeConfig = getRuntimeConfig();
        return Object.assign({}, runtimeConfig.api || {}, window.WelineApiConfig || {});
    }

    function normalizeCallParams(params) {
        if (!params || typeof params !== 'object' || Array.isArray(params) || isFormData(params)) {
            return params || {};
        }
        var normalized = {};
        Object.keys(params).forEach(function (key) {
            if (key === 'form_key' || key === 'csrf_token' || key === '_token') {
                return;
            }
            normalized[key] = params[key];
        });
        return normalized;
    }

    function normalizeLocale(value) {
        var locale = String(value || '').trim();
        return /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/.test(locale) ? locale : '';
    }

    function normalizeCurrencyCode(value) {
        return String(value || '').trim().toUpperCase();
    }

    function normalizeCurrencyList(values) {
        var codes = [];
        var seen = {};
        if (!Array.isArray(values)) {
            values = [values];
        }
        values.forEach(function (value) {
            if (value && typeof value === 'object') {
                value = value.code || value.currency || value.currency_code || value.value || '';
            }
            var code = normalizeCurrencyCode(value);
            if (!/^[A-Z]{3}$/.test(code) || seen[code]) {
                return;
            }
            seen[code] = true;
            codes.push(code);
        });
        return codes;
    }

    function normalizeCurrency(value, config) {
        var currency = normalizeCurrencyCode(value);
        var supported = {};
        normalizeCurrencyList(config.availableCurrencies).forEach(function (code) {
            supported[code] = true;
        });
        if (config.defaultCurrency) {
            supported[normalizeCurrencyCode(config.defaultCurrency)] = true;
        }
        return supported[currency] ? currency : '';
    }

    function buildQueryBinConfig() {
        var apiConfig = mergeApiConfig();
        var runtimeConfig = getRuntimeConfig();
        var config = {
            endpoint: sameOriginUrl(apiConfig.endpoint || apiConfig.queryBinUrl || '/api/framework/query-bin'),
            workerUrl: withDevCacheBust(sameOriginUrl(apiConfig.queryWorkerUrl || apiConfig.workerUrl || defaultQueryWorkerUrl())),
            deployVersion: String(apiConfig.deployVersion || apiConfig.deploy_version || runtimeConfig.deployVersion || runtimeConfig.deploy_version || 'dev'),
            workerBuildId: String(apiConfig.workerBuildId || apiConfig.worker_build_id || runtimeConfig.workerBuildId || runtimeConfig.worker_build_id || 'dev'),
            locale: normalizeLocale(apiConfig.locale || apiConfig.currentLang || runtimeConfig.currentLang || ''),
            defaultCurrency: normalizeCurrencyCode(apiConfig.defaultCurrency || apiConfig.default_currency || runtimeConfig.defaultCurrency || runtimeConfig.default_currency || 'CNY'),
            availableCurrencies: normalizeCurrencyList(apiConfig.availableCurrencies || apiConfig.supportedCurrencies || apiConfig.currencyCodes || apiConfig.currencies || runtimeConfig.availableCurrencies || runtimeConfig.supportedCurrencies || runtimeConfig.currencyCodes || runtimeConfig.currencies || []),
            requestTimeoutMs: parseInt(apiConfig.requestTimeoutMs || runtimeConfig.requestTimeoutMs || 60000, 10)
        };
        config.currency = normalizeCurrency(apiConfig.currency || apiConfig.currentCurrency || runtimeConfig.currentCurrency || '', config);
        return config;
    }

    function buildError(message, response, requestUrl) {
        var error = new Error(message || 'Backend request failed.');
        error.status = response && response.status ? response.status : 0;
        error.requestUrl = requestUrl;
        error.response = response || {
            ok: false,
            status: 0,
            statusText: '',
            data: null,
            maintenance: false
        };
        return error;
    }

    function isObjectPayload(data) {
        return !!(data && typeof data === 'object' && !Array.isArray(data));
    }

    function hasOwn(data, key) {
        return Object.prototype.hasOwnProperty.call(data, key);
    }

    function businessCode(data) {
        if (!isObjectPayload(data) || !hasOwn(data, 'code')) {
            return null;
        }
        var code = Number(data.code);
        return isFinite(code) ? code : null;
    }

    function isHttpSuccessCode(code) {
        return code >= 200 && code < 300;
    }

    function isHttpFailureCode(code) {
        return code >= 400 && code < 600;
    }

    function normalizeBusinessResult(data) {
        if (!isObjectPayload(data) || typeof data.success === 'boolean') {
            return data;
        }
        var code = businessCode(data);
        if (code === null || (!isHttpSuccessCode(code) && !isHttpFailureCode(code))) {
            return data;
        }
        return Object.assign({}, data, {
            success: isHttpSuccessCode(code)
        });
    }

    function buildCompatibleResponseData(data) {
        if (!isObjectPayload(data)) {
            return data;
        }
        var compatible = Object.assign({}, data);
        if (isObjectPayload(data.data)) {
            Object.keys(data.data).forEach(function (key) {
                if (!hasOwn(compatible, key)) {
                    compatible[key] = data.data[key];
                }
            });
        }
        return compatible;
    }

    function buildResponseEnvelope(transportResponse) {
        var body = transportResponse.data;
        var response = isObjectPayload(body) ? Object.assign({}, body) : {};
        Object.assign(response, transportResponse, {
            data: buildCompatibleResponseData(body),
            body: body
        });
        return response;
    }

    function isBusinessFailure(data) {
        if (!isObjectPayload(data)) {
            return false;
        }
        if (data.success === false) {
            return true;
        }
        var code = businessCode(data);
        return code !== null && isHttpFailureCode(code);
    }

    function failureMessage(data, statusText) {
        if (data && typeof data === 'object') {
            return data.message || data.msg || (data.error && data.error.message) || statusText || 'Backend request failed.';
        }
        if (typeof data === 'string' && data.trim()) {
            return data;
        }
        return statusText || 'Backend request failed.';
    }

    function looksLikeJson(text) {
        var trimmed = String(text || '').trim();
        return trimmed.indexOf('{') === 0 || trimmed.indexOf('[') === 0;
    }

    function collectHeaders(responseHeaders) {
        var headers = {};
        responseHeaders.forEach(function (value, key) {
            headers[key] = value;
        });
        return headers;
    }

    function parseFetchResponseBody(response) {
        var contentType = response.headers.get('content-type') || '';
        return response.text().then(function (text) {
            if (text === '') {
                return {};
            }
            if (contentType.indexOf('application/json') !== -1 || looksLikeJson(text)) {
                try {
                    return JSON.parse(text);
                } catch (error) {
                    return {
                        success: false,
                        message: text
                    };
                }
            }
            return text;
        });
    }

    function directFetch(requestUrl, requestOptions, responseMode) {
        if (typeof window.fetch !== 'function') {
            return Promise.reject(new Error('[Weline.Api] fetch is unavailable.'));
        }

        var method = String(requestOptions.method || 'GET').toUpperCase();
        var headers = Object.assign({}, requestOptions.headers || {});
        var body = method === 'GET' || method === 'HEAD'
            ? null
            : deserializeBodyForFetch(requestOptions.body, headers);
        var fetchOptions = {
            method: method,
            credentials: requestOptions.credentials || 'same-origin',
            cache: requestOptions.cache || 'no-store',
            redirect: requestOptions.redirect || 'follow',
            headers: headers
        };

        if (method !== 'GET' && method !== 'HEAD' && body !== null && body !== undefined) {
            fetchOptions.body = body;
        }

        return window.fetch(requestUrl, fetchOptions).then(function (response) {
            return parseFetchResponseBody(response).then(function (bodyData) {
                var body = normalizeBusinessResult(bodyData);
                var transportResponse = {
                    ok: !!response.ok,
                    status: response.status || 0,
                    statusText: response.statusText || '',
                    data: body,
                    headers: collectHeaders(response.headers),
                    url: response.url || requestUrl,
                    redirected: !!response.redirected,
                    maintenance: response.status === 503
                };

                if (responseMode === 'fetch') {
                    if (transportResponse.ok) {
                        return buildFetchResponse(transportResponse, requestUrl);
                    }
                    throw buildError(failureMessage(body, response.statusText), transportResponse, requestUrl);
                }

                var businessFailed = isBusinessFailure(body);
                var envelope = buildResponseEnvelope(Object.assign({}, transportResponse, {
                    ok: !!response.ok && !businessFailed
                }));

                if (envelope.ok) {
                    return envelope;
                }

                throw buildError(failureMessage(body, response.statusText), envelope, requestUrl);
            });
        });
    }

    function makeHeaders(headers) {
        var normalized = {};
        Object.keys(headers || {}).forEach(function (key) {
            normalized[String(key).toLowerCase()] = String(headers[key]);
        });
        return {
            get: function (name) {
                return normalized[String(name).toLowerCase()] || null;
            },
            has: function (name) {
                return Object.prototype.hasOwnProperty.call(normalized, String(name).toLowerCase());
            },
            forEach: function (callback, thisArg) {
                Object.keys(normalized).forEach(function (key) {
                    callback.call(thisArg, normalized[key], key, this);
                }, this);
            },
            entries: function () {
                return Object.keys(normalized).map(function (key) {
                    return [key, normalized[key]];
                })[Symbol.iterator]();
            }
        };
    }

    function fetchBodyText(data) {
        if (typeof data === 'string') {
            return data;
        }
        if (data === undefined || data === null) {
            return '';
        }
        return JSON.stringify(data);
    }

    function buildFetchResponse(response, requestUrl) {
        var body = response.data;
        var text = fetchBodyText(body);
        var headers = makeHeaders(response.headers || {});
        return {
            ok: !!response.ok,
            status: response.status || 0,
            statusText: response.statusText || '',
            headers: headers,
            redirected: !!response.redirected,
            url: response.url || requestUrl || '',
            body: null,
            json: function () {
                if (body && typeof body === 'object') {
                    return Promise.resolve(body);
                }
                if (!text) {
                    return Promise.resolve({});
                }
                return Promise.resolve(JSON.parse(text));
            },
            text: function () {
                return Promise.resolve(text);
            },
            clone: function () {
                return buildFetchResponse(response, requestUrl);
            },
            blob: function () {
                if (typeof Blob === 'undefined') {
                    return Promise.reject(new Error('[Weline.Api] Blob is unavailable.'));
                }
                return Promise.resolve(new Blob([text], {
                    type: headers.get('content-type') || 'text/plain'
                }));
            },
            arrayBuffer: function () {
                if (typeof TextEncoder === 'undefined') {
                    return Promise.reject(new Error('[Weline.Api] TextEncoder is unavailable.'));
                }
                return Promise.resolve(new TextEncoder().encode(text).buffer);
            }
        };
    }

    function BackendApiClient(config) {
        this.config = config || {};
        this.worker = null;
        this.requestId = 0;
        this.pending = {};
        this.handleWorkerMessage = this.handleWorkerMessage.bind(this);
        this.handleWorkerError = this.handleWorkerError.bind(this);
    }

    BackendApiClient.prototype.ensureWorker = function () {
        if (this.worker) {
            return;
        }
        if (typeof window.Worker !== 'function') {
            throw new Error('[Weline.Api] Worker is unavailable; backend direct requests are disabled.');
        }
        if (!this.config.workerUrl) {
            throw new Error('[Weline.Api] backend workerUrl is not configured.');
        }
        this.worker = new Worker(this.config.workerUrl);
        this.worker.addEventListener('message', this.handleWorkerMessage);
        this.worker.addEventListener('error', this.handleWorkerError);
        this.worker.addEventListener('messageerror', this.handleWorkerError);
    };

    BackendApiClient.prototype.nextId = function () {
        this.requestId += 1;
        return 'backend_req_' + Date.now() + '_' + this.requestId;
    };

    BackendApiClient.prototype.send = function (url, options, responseMode) {
        var requestUrl = sameOriginUrl(url);
        var requestOptions = normalizeOptions(options);
        var mode = responseMode || 'body';
        try {
            this.ensureWorker();
        } catch (error) {
            return directFetch(requestUrl, requestOptions, mode);
        }
        var messageId = this.nextId();
        var timeoutMs = Math.max(1000, parseInt((options && options.timeoutMs) || this.config.requestTimeoutMs || 60000, 10));
        var worker = this.worker;
        var pending = this.pending;

        return new Promise(function (resolve, reject) {
            var timeoutId = window.setTimeout(function () {
                if (!pending[messageId]) {
                    return;
                }
                delete pending[messageId];
                directFetch(requestUrl, requestOptions, mode).then(resolve).catch(function (error) {
                    reject(error || buildError('[Weline.Api] backend worker request timed out.', {
                        ok: false,
                        status: 0,
                        statusText: '',
                        data: null,
                        maintenance: false
                    }, requestUrl));
                });
            }, timeoutMs);

            pending[messageId] = {
                resolve: resolve,
                reject: reject,
                timeoutId: timeoutId,
                requestUrl: requestUrl,
                requestOptions: requestOptions,
                responseMode: mode
            };

            try {
                worker.postMessage({
                    id: messageId,
                    type: 'request',
                    url: requestUrl,
                    options: requestOptions
                });
            } catch (error) {
                window.clearTimeout(timeoutId);
                delete pending[messageId];
                directFetch(requestUrl, requestOptions, mode).then(resolve).catch(function () {
                    reject(error);
                });
            }
        });
    };

    BackendApiClient.prototype.request = function (url, options) {
        return this.send(url, options, 'body');
    };

    BackendApiClient.prototype.fetch = function (url, options) {
        return this.send(url, options, 'fetch');
    };

    BackendApiClient.prototype.handleWorkerMessage = function (event) {
        var message = event.data || {};
        var pending = this.pending[message.id];
        if (!pending) {
            return;
        }
        delete this.pending[message.id];
        window.clearTimeout(pending.timeoutId);

        var body = normalizeBusinessResult(message.body);
        var transportResponse = {
            ok: !!message.ok,
            status: message.status || 0,
            statusText: message.statusText || '',
            data: body,
            headers: message.headers || {},
            url: message.url || pending.requestUrl,
            redirected: !!message.redirected,
            maintenance: !!message.maintenance
        };

        if (pending.responseMode === 'fetch') {
            if (transportResponse.ok) {
                pending.resolve(buildFetchResponse(transportResponse, pending.requestUrl));
                return;
            }
            pending.reject(buildError(failureMessage(body, message.statusText), transportResponse, pending.requestUrl));
            return;
        }

        var businessFailed = isBusinessFailure(body);
        var response = buildResponseEnvelope(Object.assign({}, transportResponse, {
            ok: !!message.ok && !businessFailed
        }));

        if (response.ok) {
            pending.resolve(response);
            return;
        }

        pending.reject(buildError(failureMessage(body, message.statusText), response, pending.requestUrl));
    };

    BackendApiClient.prototype.handleWorkerError = function (event) {
        var message = event && event.message ? event.message : '[Weline.Api] backend worker failed.';
        if (this.worker && typeof this.worker.terminate === 'function') {
            this.worker.terminate();
        }
        this.worker = null;
        this.config.workerUrl = '';
        Object.keys(this.pending).forEach(function (id) {
            var pending = this.pending[id];
            delete this.pending[id];
            window.clearTimeout(pending.timeoutId);
            directFetch(pending.requestUrl, pending.requestOptions, pending.responseMode)
                .then(pending.resolve)
                .catch(function (error) {
                    pending.reject(error || buildError(message, {
                        ok: false,
                        status: 0,
                        statusText: '',
                        data: null,
                        maintenance: false
                    }, pending.requestUrl));
                });
        }, this);
    };

    BackendApiClient.prototype.get = function (url, options) {
        return this.request(url, Object.assign({}, options || {}, {method: 'GET'}));
    };

    BackendApiClient.prototype.post = function (url, data, options) {
        return this.request(url, Object.assign({}, options || {}, {
            method: 'POST',
            body: data
        }));
    };

    BackendApiClient.prototype.upload = function (url, formData, options) {
        return this.request(url, Object.assign({}, options || {}, {
            method: 'POST',
            body: formData
        }));
    };

    function BackendQueryBinClient() {
        this.worker = null;
        this.requestId = 0;
        this.pending = {};
        this.handleWorkerMessage = this.handleWorkerMessage.bind(this);
        this.handleWorkerError = this.handleWorkerError.bind(this);
    }

    BackendQueryBinClient.prototype.ensureWorker = function (config) {
        if (this.worker && this.workerUrl === config.workerUrl) {
            return;
        }
        if (this.worker && typeof this.worker.terminate === 'function') {
            this.worker.terminate();
        }
        if (typeof window.Worker !== 'function') {
            throw new Error('[Weline.Api] Worker is unavailable; bin-query requests are disabled.');
        }
        this.workerUrl = config.workerUrl;
        this.worker = new Worker(config.workerUrl);
        this.worker.addEventListener('message', this.handleWorkerMessage);
        this.worker.addEventListener('error', this.handleWorkerError);
        this.worker.addEventListener('messageerror', this.handleWorkerError);
    };

    BackendQueryBinClient.prototype.nextId = function () {
        this.requestId += 1;
        return 'backend_query_' + Date.now() + '_' + this.requestId;
    };

    BackendQueryBinClient.prototype.call = function (provider, operation, params, options) {
        if (!provider || !operation) {
            return Promise.reject(new Error('[Weline.Api] provider and operation are required.'));
        }
        return this.send({
            type: 'call',
            provider: provider,
            operation: operation,
            params: normalizeCallParams(params),
            options: options || {}
        });
    };

    BackendQueryBinClient.prototype.graph = function (graph, options) {
        return this.send({
            type: 'graph',
            graph: graph || {},
            options: options || {}
        });
    };

    BackendQueryBinClient.prototype.resource = function (provider, optionalMap) {
        if (!provider || typeof provider !== 'string') {
            throw new Error('[Weline.Api] resource provider is required.');
        }
        var client = this;
        var methodMap = optionalMap && typeof optionalMap === 'object' ? optionalMap : null;
        return new Proxy(Object.create(null), {
            get: function (_target, property) {
                if (typeof property === 'symbol' || RESERVED_METHODS[property]) {
                    return undefined;
                }
                var methodName = String(property);
                if (methodMap && !hasOwn(methodMap, methodName)) {
                    return undefined;
                }
                var operation = methodMap ? String(methodMap[methodName]) : methodName;
                if (!operation) {
                    return undefined;
                }
                return function (params, options) {
                    return client.call(provider, operation, params || {}, options || {});
                };
            },
            has: function (_target, property) {
                return typeof property === 'string' && !RESERVED_METHODS[property] && (!methodMap || hasOwn(methodMap, property));
            }
        });
    };

    BackendQueryBinClient.prototype.send = function (payload) {
        var config = buildQueryBinConfig();
        this.ensureWorker(config);
        var messageId = this.nextId();
        var optionTimeout = payload && payload.options
            ? (payload.options.requestTimeoutMs || payload.options.timeoutMs || payload.options.timeout)
            : null;
        var configuredTimeout = parseInt(optionTimeout || config.requestTimeoutMs || 60000, 10);
        var timeoutMs = isFinite(configuredTimeout) ? Math.max(1000, configuredTimeout) : 60000;
        var worker = this.worker;
        var pending = this.pending;

        return new Promise(function (resolve, reject) {
            var timeoutId = window.setTimeout(function () {
                if (!pending[messageId]) {
                    return;
                }
                delete pending[messageId];
                var error = new Error('[Weline.Api] bin-query worker request timed out.');
                error.code = 'worker_timeout';
                reject(error);
            }, timeoutMs);

            pending[messageId] = {
                resolve: resolve,
                reject: reject,
                timeoutId: timeoutId,
                payload: payload
            };

            worker.postMessage(Object.assign({}, payload, {
                id: messageId,
                config: {
                    endpoint: config.endpoint,
                    deployVersion: config.deployVersion,
                    workerBuildId: config.workerBuildId,
                    locale: config.locale,
                    currency: config.currency,
                    defaultCurrency: config.defaultCurrency,
                    availableCurrencies: config.availableCurrencies
                }
            }));
        });
    };

    BackendQueryBinClient.prototype.handleWorkerMessage = function (event) {
        var data = event.data || {};
        var pending = this.pending[data.id];
        if (!pending) {
            return;
        }
        delete this.pending[data.id];
        window.clearTimeout(pending.timeoutId);

        var wrapper = data.body || {};
        if (data.ok === true && wrapper.ok === true) {
            var businessData = wrapper.data;
            var requestOptions = pending.payload && pending.payload.options;
            var keepBusinessResult = !!(requestOptions && requestOptions.keepBusinessResult);
            if (isBusinessFailure(businessData) && !keepBusinessResult) {
                var message = failureMessage(businessData, data.statusText || '请求失败');
                var businessError = buildError(message, {
                    ok: false,
                    status: data.status || 200,
                    statusText: data.statusText || '',
                    data: wrapper,
                    headers: data.headers || {},
                    maintenance: !!data.maintenance
                }, buildQueryBinConfig().endpoint);
                businessError.code = (businessData && businessData.code) || 'business_error';
                pending.reject(businessError);
                return;
            }
            pending.resolve(businessData);
            return;
        }

        var serverError = wrapper.error || {};
        var error = buildError(serverError.message || data.error || 'Weline bin-query request failed.', {
            ok: false,
            status: data.status || 0,
            statusText: data.statusText || '',
            data: wrapper,
            headers: data.headers || {},
            maintenance: !!data.maintenance
        }, buildQueryBinConfig().endpoint);
        error.code = serverError.code || 'protocol_error';
        pending.reject(error);
    };

    BackendQueryBinClient.prototype.handleWorkerError = function (event) {
        var message = event && event.message ? event.message : '[Weline.Api] bin-query worker failed.';
        if (this.worker && typeof this.worker.terminate === 'function') {
            this.worker.terminate();
        }
        this.worker = null;
        Object.keys(this.pending).forEach(function (id) {
            var pending = this.pending[id];
            delete this.pending[id];
            window.clearTimeout(pending.timeoutId);
            pending.reject(buildError(message, {
                ok: false,
                status: 0,
                statusText: '',
                data: null,
                maintenance: false
            }, buildQueryBinConfig().endpoint));
        }, this);
    };

    var client = new BackendApiClient({
        workerUrl: withDevCacheBust(sameOriginUrl(defaultWorkerUrl())),
        requestTimeoutMs: 60000
    });
    var queryBinClient = new BackendQueryBinClient();

    var ApiModule = {
        __full: true,
        __backend: true,
        request: function (url, options) {
            return client.request(url, options);
        },
        get: function (url, options) {
            return client.get(url, options);
        },
        post: function (url, data, options) {
            return client.post(url, data, options);
        },
        upload: function (url, formData, options) {
            return client.upload(url, formData, options);
        },
        fetch: function (url, options) {
            return client.fetch(url, options);
        },
        call: function (provider, operation, params, options) {
            return queryBinClient.call(provider, operation, params, options);
        },
        graph: function (graph, options) {
            return queryBinClient.graph(graph, options);
        },
        stream: function () {
            return Promise.reject(new Error('[Weline.Api] backend stream() is not available yet.'));
        },
        resource: function (provider, optionalMap) {
            return queryBinClient.resource(provider, optionalMap);
        },
        markCartActive: function () {},
        markCartEmpty: function () {},
        enableAutoRequests: function () {},
        disableAutoRequests: function () {},
        getClient: function () {
            return client;
        }
    };

    window.WelineApiModule = ApiModule;
    window.Weline = window.Weline || {};
    window.Weline.Api = ApiModule;
})(window);
