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
        this.ensureWorker();
        var requestUrl = sameOriginUrl(url);
        var requestOptions = normalizeOptions(options);
        var messageId = this.nextId();
        var timeoutMs = Math.max(1000, parseInt((options && options.timeoutMs) || this.config.requestTimeoutMs || 15000, 10));
        var worker = this.worker;
        var pending = this.pending;

        return new Promise(function (resolve, reject) {
            var timeoutId = window.setTimeout(function () {
                if (!pending[messageId]) {
                    return;
                }
                delete pending[messageId];
                reject(buildError('[Weline.Api] backend worker request timed out.', {
                    ok: false,
                    status: 0,
                    statusText: '',
                    data: null,
                    maintenance: false
                }, requestUrl));
            }, timeoutMs);

            pending[messageId] = {
                resolve: resolve,
                reject: reject,
                timeoutId: timeoutId,
                requestUrl: requestUrl,
                responseMode: responseMode || 'body'
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
                reject(error);
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
            }, pending.requestUrl));
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

    var client = new BackendApiClient({
        workerUrl: withDevCacheBust(sameOriginUrl(defaultWorkerUrl())),
        requestTimeoutMs: 15000
    });

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
        call: function () {
            return Promise.reject(new Error('[Weline.Api] backend call() is not available for direct controller routes.'));
        },
        graph: function () {
            return Promise.reject(new Error('[Weline.Api] backend graph() is not available for direct controller routes.'));
        },
        stream: function () {
            return Promise.reject(new Error('[Weline.Api] backend stream() is not available for direct controller routes.'));
        },
        resource: function () {
            return new Proxy(Object.create(null), {
                get: function (_target, property) {
                    if (typeof property === 'symbol' || RESERVED_METHODS[property]) {
                        return undefined;
                    }
                    return function () {
                        return Promise.reject(new Error('[Weline.Api] backend resource() is not available for direct controller routes.'));
                    };
                },
                has: function (_target, property) {
                    return typeof property === 'string' && !RESERVED_METHODS[property];
                }
            });
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
