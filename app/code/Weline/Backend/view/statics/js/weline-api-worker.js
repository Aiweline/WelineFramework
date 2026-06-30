/* eslint-disable no-restricted-globals */
(function () {
    'use strict';

    self.addEventListener('message', function (event) {
        var message = event.data || {};
        if (!message.id || message.type !== 'request') {
            return;
        }
        handleRequest(message).then(function (result) {
            self.postMessage(Object.assign({id: message.id}, result));
        }).catch(function (error) {
            self.postMessage({
                id: message.id,
                ok: false,
                status: error && error.status ? error.status : 0,
                statusText: '',
                headers: {},
                body: {
                    success: false,
                    message: error instanceof Error ? error.message : String(error)
                },
                maintenance: false
            });
        });
    });

    async function handleRequest(message) {
        var url = sameOriginUrl(message.url);
        var options = normalizeOptions(message.options || {});
        var response = await fetch(url, options);
        var body = await parseResponseBody(response);

        return {
            ok: response.ok,
            status: response.status,
            statusText: response.statusText || '',
            headers: collectHeaders(response.headers),
            url: response.url || url.href,
            redirected: !!response.redirected,
            body: body,
            maintenance: response.status === 503
        };
    }

    function sameOriginUrl(value) {
        var url = new URL(value, self.location.origin);
        if (url.origin !== self.location.origin) {
            var error = new Error('[Weline.Api] backend worker requests must be same-origin.');
            error.status = 0;
            throw error;
        }
        return url.href;
    }

    function normalizeOptions(options) {
        var headers = normalizeHeaders(options.headers || {});
        var body = deserializeBody(options.body, headers);
        var method = String(options.method || 'GET').toUpperCase();
        var requestOptions = {
            method: method,
            credentials: options.credentials || 'same-origin',
            cache: options.cache || 'no-store',
            redirect: options.redirect || 'follow',
            headers: headers
        };

        if (method !== 'GET' && method !== 'HEAD' && body !== null && body !== undefined) {
            requestOptions.body = body;
        }

        return requestOptions;
    }

    function normalizeHeaders(headers) {
        var normalized = {};
        Object.keys(headers || {}).forEach(function (key) {
            if (headers[key] !== undefined && headers[key] !== null) {
                normalized[key] = String(headers[key]);
            }
        });
        return normalized;
    }

    function removeHeader(headers, name) {
        var target = String(name).toLowerCase();
        Object.keys(headers || {}).forEach(function (key) {
            if (String(key).toLowerCase() === target) {
                delete headers[key];
            }
        });
    }

    function deserializeBody(body, headers) {
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

    async function parseResponseBody(response) {
        var contentType = response.headers.get('content-type') || '';
        var text = await response.text();
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
})();
