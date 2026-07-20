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

    const STREAM_TERMINAL_EVENTS = new Set([
        'done',
        'complete',
        'completed',
        'failed',
        'cancelled',
        'expired',
        'recovery_unsafe',
        'event_backlog_limit'
    ]);
    const STREAM_RUNTIME_CHANNEL_PREFIX = 'runtime_task.';
    const STREAM_STORAGE_PREFIX = 'weline.runtime.stream.';

    const normalizeStreamCursor = (value) => {
        const cursor = value === null || typeof value === 'undefined' ? '' : String(value).trim();
        return cursor.length <= 128 && !/[\x00-\x1F\x7F]/.test(cursor) ? cursor : '';
    };

    const normalizeStreamParamName = (value) => {
        const name = String(value || '').trim();
        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(name) ? name : '';
    };

    const compareNumericStreamCursors = (left, right) => {
        const normalizedLeft = String(left).replace(/^0+(?=\d)/, '');
        const normalizedRight = String(right).replace(/^0+(?=\d)/, '');
        if (normalizedLeft.length !== normalizedRight.length) {
            return normalizedLeft.length < normalizedRight.length ? -1 : 1;
        }
        if (normalizedLeft === normalizedRight) {
            return 0;
        }
        return normalizedLeft < normalizedRight ? -1 : 1;
    };

    const createStreamIntentId = () => {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        }
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    };

    class StreamHandle extends EventTarget {
        constructor(client, channel, params = {}, options = {}) {
            super();

            this.client = client;
            this.channel = String(channel || '');
            this.options = options && typeof options === 'object' ? options : {};
            this.params = params && typeof params === 'object' && !Array.isArray(params) && !isFormData(params)
                ? Object.assign({}, normalizeCallParams(params))
                : {};
            this._source = null;
            this._sourceEventTypes = new Set();
            this._eventTypes = new Set(['message']);
            (Array.isArray(this.options.eventTypes) ? this.options.eventTypes : []).forEach(type => {
                if (typeof type === 'string' && type !== '') {
                    this._eventTypes.add(type);
                }
            });
            this._seenEventIds = new Set();
            this._retryTimer = null;
            this._leaseTimer = null;
            this._connecting = false;
            this._closed = false;
            this._terminal = false;
            this._cancelRequested = false;
            this._cancelPromise = null;
            this._retryAttempt = 0;
            this._renewingLease = false;
            this._onopen = null;
            this._onerror = null;
            this._onmessage = null;

            this.taskId = String(this.params.task_id || '');
            this._storageKey = this.resolveStorageKey();
            const stored = this.readStoredState();
            if (!this.params.lease_id && stored.lease_id) {
                this.params.lease_id = stored.lease_id;
            }
            this.leaseId = String(this.params.lease_id || '');
            this.lastEventId = normalizeStreamCursor(
                this.options.lastEventId
                ?? this.options.last_event_id
                ?? this.params.last_event_id
                ?? stored.cursor
            );
            this._lastNumericEventId = /^\d+$/.test(this.lastEventId) ? this.lastEventId : '';
            if (this.lastEventId !== '') {
                this._seenEventIds.add(this.lastEventId);
            }
            this.cancelIntentId = normalizeStreamCursor(this.options.intentId || stored.intent_id) || createStreamIntentId();
            this.cursorParam = this.resolveCursorParam();
            this.isRuntimeTaskStream = this.channel.indexOf(STREAM_RUNTIME_CHANNEL_PREFIX) === 0;
            this.leaseEnabled = this.options.lease === true
                || (this.options.lease !== false && this.isRuntimeTaskStream);
            this.leaseProvider = String(this.options.leaseProvider || 'runtime_task');
            this.touchOperation = String(this.options.touchOperation || 'touch');
            this.cancelOperation = String(this.options.cancelOperation || 'cancel');
            this.leaseIntervalMs = this.resolveInterval(this.options.leaseIntervalMs, 30000, 1000, 300000);
            this.retryMinMs = this.resolveInterval(this.options.retryMinMs, 1000, 1, 30000);
            this.retryMaxMs = this.resolveInterval(this.options.retryMaxMs, 30000, this.retryMinMs, 30000);
            this.terminalEvents = new Set(STREAM_TERMINAL_EVENTS);
            (Array.isArray(this.options.terminalEvents) ? this.options.terminalEvents : []).forEach(type => {
                if (typeof type === 'string' && type !== '') {
                    this.terminalEvents.add(type.toLowerCase());
                    this._eventTypes.add(type);
                }
            });
            // Terminal events must always be observed internally. Otherwise a
            // caller that only subscribes to e.g. `progress` would miss a
            // persisted `completed`/`failed` frame and reconnect forever.
            this.terminalEvents.forEach(type => this._eventTypes.add(type));

            this._onlineHandler = () => this.handleOnline();
            this._offlineHandler = () => this.dispatchLifecycleEvent('offline');
            window.addEventListener('online', this._onlineHandler);
            window.addEventListener('offline', this._offlineHandler);
            this.persistState();
        }

        get onopen() {
            return this._onopen;
        }

        set onopen(listener) {
            this.replacePropertyListener('_onopen', 'open', listener);
        }

        get onerror() {
            return this._onerror;
        }

        set onerror(listener) {
            this.replacePropertyListener('_onerror', 'error', listener);
        }

        get onmessage() {
            return this._onmessage;
        }

        set onmessage(listener) {
            this.replacePropertyListener('_onmessage', 'message', listener);
        }

        get readyState() {
            if (this._closed || this._terminal) {
                return 2;
            }
            return this._source ? this._source.readyState : 0;
        }

        get url() {
            return this._source ? this._source.url : '';
        }

        get withCredentials() {
            return this.options.withCredentials !== false;
        }

        addEventListener(type, listener, options) {
            super.addEventListener(type, listener, options);
            if (typeof type === 'string' && type !== '' && type !== 'open' && type !== 'error') {
                this._eventTypes.add(type);
                this.attachSourceEvent(this._source, type);
            }
        }

        async start() {
            await this.connect(true);
            return this;
        }

        close() {
            if (this._closed) {
                return;
            }
            this._closed = true;
            this.clearReconnectTimer();
            this.stopLeaseRenewal();
            this.closeSource();
            this.removeWindowListeners();
            this.persistState();
            this.dispatchLifecycleEvent('close');
        }

        async cancel(reason = '') {
            if (!this.taskId) {
                throw new Error('[Weline.Api] StreamHandle.cancel() requires a task_id.');
            }
            if (this._cancelPromise) {
                return this._cancelPromise;
            }

            this._cancelPromise = this.client.call(this.leaseProvider, this.cancelOperation, {
                task_id: this.taskId,
                intent_id: this.cancelIntentId,
                reason: String(reason || ''),
            }).then(result => {
                this._cancelRequested = true;
                this.stopLeaseRenewal();
                this.persistState();
                this.dispatchLifecycleEvent('cancel_requested', { intent_id: this.cancelIntentId });
                return result;
            }).finally(() => {
                this._cancelPromise = null;
            });

            return this._cancelPromise;
        }

        resolveStorageKey() {
            if (typeof this.options.storageKey === 'string' && this.options.storageKey !== '') {
                return STREAM_STORAGE_PREFIX + this.options.storageKey;
            }
            if (!this.taskId) {
                return '';
            }
            return STREAM_STORAGE_PREFIX + encodeURIComponent(this.channel) + ':' + encodeURIComponent(this.taskId);
        }

        readStoredState() {
            if (!this._storageKey) {
                return {};
            }
            try {
                const value = window.sessionStorage.getItem(this._storageKey);
                const parsed = value ? JSON.parse(value) : null;
                return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
            } catch (error) {
                return {};
            }
        }

        persistState() {
            if (!this._storageKey) {
                return;
            }
            try {
                window.sessionStorage.setItem(this._storageKey, JSON.stringify({
                    task_id: this.taskId,
                    lease_id: this.leaseId,
                    cursor: this.lastEventId,
                    intent_id: this.cancelIntentId,
                }));
            } catch (error) {
                /* session storage may be unavailable */
            }
        }

        resolveCursorParam() {
            if (this.options.cursorParam === false) {
                return '';
            }
            if (typeof this.options.cursorParam === 'string') {
                return normalizeStreamParamName(this.options.cursorParam);
            }
            return this.channel.indexOf(STREAM_RUNTIME_CHANNEL_PREFIX) === 0 ? 'last_event_id' : '';
        }

        resolveInterval(value, fallback, minimum, maximum) {
            const parsed = Number(value);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(minimum, Math.min(maximum, Math.floor(parsed)));
        }

        replacePropertyListener(property, type, listener) {
            const previous = this[property];
            if (typeof previous === 'function') {
                super.removeEventListener(type, previous);
            }
            this[property] = typeof listener === 'function' ? listener : null;
            if (this[property]) {
                super.addEventListener(type, this[property]);
            }
        }

        async connect(initial) {
            if (this._closed || this._terminal || this._connecting) {
                return;
            }
            this._connecting = true;
            this.clearReconnectTimer();

            try {
                const ticket = await this.client.send({
                    type: 'stream-ticket',
                    channel: this.channel,
                    params: this.buildTicketParams(),
                    options: this.options.ticketOptions || this.options,
                });
                if (!ticket || !ticket.url) {
                    throw new Error('[Weline.Api] stream ticket did not include a stream URL.');
                }
                if (this._closed || this._terminal) {
                    return;
                }

                this.openSource(sameOriginUrl(ticket.url));
                this.startLeaseRenewal();
            } catch (error) {
                this.dispatchError(error);
                this.scheduleReconnect();
                // A recoverable initial ticket failure must not strand a
                // detached stream without a handle the caller can later
                // close. Opt into the former rejecting behavior explicitly.
                if (initial && this.options.rejectOnInitialError === true) {
                    this.close();
                    throw error;
                }
            } finally {
                this._connecting = false;
            }
        }

        buildTicketParams() {
            const params = Object.assign({}, this.params);
            if (this.cursorParam && this.lastEventId) {
                params[this.cursorParam] = this.lastEventId;
            }
            return params;
        }

        openSource(url) {
            if (!window.EventSource) {
                throw new Error('[Weline.Api] EventSource is unavailable.');
            }

            this.closeSource();
            const source = new window.EventSource(url, { withCredentials: this.withCredentials });
            this._source = source;
            this._sourceEventTypes = new Set();

            source.addEventListener('open', event => {
                if (this._source !== source || this._closed || this._terminal) {
                    return;
                }
                this._retryAttempt = 0;
                this.dispatchEvent(this.createEvent('open', event));
            });
            source.addEventListener('error', event => this.handleSourceError(source, event));
            this._eventTypes.forEach(type => this.attachSourceEvent(source, type));
        }

        attachSourceEvent(source, type) {
            if (!source || type === 'open' || type === 'error' || this._sourceEventTypes.has(type)) {
                return;
            }
            this._sourceEventTypes.add(type);
            source.addEventListener(type, event => {
                if (this._source === source && !this._closed && !this._terminal) {
                    this.receiveEvent(event);
                }
            });
        }

        handleSourceError(source, event) {
            if (this._source !== source || this._closed || this._terminal) {
                return;
            }
            this.closeSource(source);
            this.dispatchEvent(this.createEvent('error', event));
            this.scheduleReconnect();
        }

        receiveEvent(event) {
            const eventId = normalizeStreamCursor(event && event.lastEventId);
            if (eventId && this.isDuplicateEvent(eventId)) {
                return;
            }
            if (eventId) {
                this.rememberEventId(eventId);
            }

            const forwarded = this.createMessageEvent(event);
            this.dispatchEvent(forwarded);
            if (this.isTerminalEvent(forwarded)) {
                this.markTerminal();
            }
        }

        isDuplicateEvent(eventId) {
            if (this._seenEventIds.has(eventId)) {
                return true;
            }
            if (/^\d+$/.test(eventId) && this._lastNumericEventId) {
                return compareNumericStreamCursors(eventId, this._lastNumericEventId) <= 0;
            }
            return false;
        }

        rememberEventId(eventId) {
            this.lastEventId = eventId;
            if (/^\d+$/.test(eventId)) {
                this._lastNumericEventId = eventId;
            }
            this._seenEventIds.add(eventId);
            while (this._seenEventIds.size > 1024) {
                this._seenEventIds.delete(this._seenEventIds.values().next().value);
            }
            this.persistState();
        }

        isTerminalEvent(event) {
            const type = String(event.type || '').toLowerCase();
            if (this.terminalEvents.has(type)) {
                return true;
            }
            let payload = event.data;
            if (typeof payload === 'string') {
                try {
                    payload = JSON.parse(payload);
                } catch (error) {
                    return false;
                }
            }
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                return false;
            }
            if (payload.terminal === true) {
                return true;
            }
            const status = String(payload.status || payload.state || '').toLowerCase();
            return this.terminalEvents.has(status);
        }

        markTerminal() {
            if (this._terminal) {
                return;
            }
            this._terminal = true;
            this.clearReconnectTimer();
            this.stopLeaseRenewal();
            this.closeSource();
            this.removeWindowListeners();
            this.persistState();
            this.dispatchLifecycleEvent('terminal');
        }

        scheduleReconnect() {
            if (this._closed || this._terminal || this._retryTimer !== null || this.isOffline()) {
                return;
            }
            this._retryAttempt += 1;
            const baseDelay = Math.min(this.retryMaxMs, this.retryMinMs * (2 ** (this._retryAttempt - 1)));
            const delay = Math.max(this.retryMinMs, Math.round(baseDelay * (0.75 + (Math.random() * 0.5))));
            this.dispatchLifecycleEvent('reconnecting', { attempt: this._retryAttempt, delay });
            this._retryTimer = window.setTimeout(() => {
                this._retryTimer = null;
                this.connect(false).catch(() => {});
            }, delay);
        }

        clearReconnectTimer() {
            if (this._retryTimer !== null) {
                window.clearTimeout(this._retryTimer);
                this._retryTimer = null;
            }
        }

        handleOnline() {
            if (this._closed || this._terminal) {
                return;
            }
            this.clearReconnectTimer();
            this.closeSource();
            this.connect(false).catch(() => {});
        }

        isOffline() {
            return typeof window.navigator !== 'undefined' && window.navigator.onLine === false;
        }

        startLeaseRenewal() {
            if (!this.leaseEnabled || !this.taskId || !this.leaseId || this._terminal || this._closed || this._cancelRequested) {
                return;
            }
            if (this._leaseTimer !== null) {
                return;
            }
            this.renewLease();
            this._leaseTimer = window.setInterval(() => this.renewLease(), this.leaseIntervalMs);
        }

        stopLeaseRenewal() {
            if (this._leaseTimer !== null) {
                window.clearInterval(this._leaseTimer);
                this._leaseTimer = null;
            }
        }

        renewLease() {
            if (this._renewingLease || this._closed || this._terminal || this._cancelRequested || this.isOffline()) {
                return;
            }
            this._renewingLease = true;
            this.client.call(this.leaseProvider, this.touchOperation, {
                task_id: this.taskId,
                lease_id: this.leaseId,
            }).then(result => {
                this.dispatchLifecycleEvent('lease', { result });
            }).catch(error => {
                // A missing touch is intentionally not a cancel. The server-side
                // lease timeout decides whether the detached task eventually expires.
                this.dispatchLifecycleEvent('leaseerror', { error });
            }).finally(() => {
                this._renewingLease = false;
            });
        }

        closeSource(source = this._source) {
            if (!source) {
                return;
            }
            if (this._source === source) {
                this._source = null;
                this._sourceEventTypes = new Set();
            }
            try {
                source.close();
            } catch (error) {
                /* EventSource close is best-effort */
            }
        }

        removeWindowListeners() {
            window.removeEventListener('online', this._onlineHandler);
            window.removeEventListener('offline', this._offlineHandler);
        }

        dispatchError(error) {
            const event = this.createEvent('error');
            Object.defineProperty(event, 'error', { value: error, enumerable: true });
            this.dispatchEvent(event);
        }

        dispatchLifecycleEvent(type, detail = null) {
            const event = typeof window.CustomEvent === 'function'
                ? new window.CustomEvent(type, { detail })
                : this.createEvent(type);
            this.dispatchEvent(event);
        }

        createEvent(type, sourceEvent = null) {
            const event = new window.Event(type);
            if (sourceEvent && sourceEvent.lastEventId) {
                Object.defineProperty(event, 'lastEventId', { value: sourceEvent.lastEventId, enumerable: true });
            }
            return event;
        }

        createMessageEvent(sourceEvent) {
            const type = String(sourceEvent.type || 'message');
            if (typeof window.MessageEvent === 'function') {
                return new window.MessageEvent(type, {
                    data: sourceEvent.data,
                    lastEventId: sourceEvent.lastEventId || '',
                    origin: sourceEvent.origin || window.location.origin,
                });
            }
            const event = this.createEvent(type, sourceEvent);
            Object.defineProperty(event, 'data', { value: sourceEvent.data, enumerable: true });
            return event;
        }
    }


    BackendQueryBinClient.prototype.createStream = function (channel, params, options) {
        return new StreamHandle(this, channel, params || {}, options || {});
    };

    BackendQueryBinClient.prototype.stream = function (channel, params, options) {
        return this.createStream(channel, params, options).start();
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
        createStream: function (channel, params, options) {
            return queryBinClient.createStream(channel, params, options);
        },
        stream: function (channel, params, options) {
            return queryBinClient.stream(channel, params, options);
        },
        resource: function (provider, optionalMap) {
            return queryBinClient.resource(provider, optionalMap);
        },
        StreamHandle: StreamHandle,
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
