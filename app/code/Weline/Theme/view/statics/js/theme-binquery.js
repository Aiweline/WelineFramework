/**
 * Theme backend business bridge: Controller URL -> theme.editorRequest (bin-query).
 */
(function (global) {
    'use strict';

    function bodyToString(body) {
        if (body == null) return '';
        if (typeof body === 'string') return body;
        if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
            return body.toString();
        }
        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            var params = new URLSearchParams();
            body.forEach(function (value, key) {
                if (typeof File !== 'undefined' && value instanceof File) {
                    // Files must use dedicated upload ops; skip binary here.
                    return;
                }
                params.append(key, String(value));
            });
            return params.toString();
        }
        if (typeof body === 'object') {
            try { return JSON.stringify(body); } catch (e) { return ''; }
        }
        return String(body);
    }

    function themeRequest(url, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var headers = options.headers || {};
        var body = bodyToString(options.body);
        if (!global.Weline) {
            return Promise.reject(new Error('Weline.Api unavailable'));
        }
        var run = function (api) {
            return api.resource('theme').editorRequest({
                url: url,
                method: method,
                headers: headers,
                body: body
            });
        };
        if (typeof global.Weline.load === 'function') {
            return global.Weline.load('api').then(run);
        }
        return Promise.resolve(run(global.Weline.Api));
    }

    global.WelineThemeBinQuery = { request: themeRequest };
})(typeof window !== 'undefined' ? window : globalThis);
