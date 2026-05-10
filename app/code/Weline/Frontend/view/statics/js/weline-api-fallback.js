/**
 * Weline.Api 同步回退实现
 * 在 weline-api.js（Worker 版本）加载前提供基础 fetch 实现，确保 Weline.Api.request 立即可用。
 * 当 weline-api.js 加载后会覆盖本实现。
 */
(function (window) {
    'use strict';
    if (window.WelineApiModule && window.WelineApiModule.__full) return;

    function fetchRequest(url, opts) {
        opts = opts || {};
        var method = opts.method || 'GET';
        var headers = opts.headers || {};
        var body = opts.body;
        var cred = opts.credentials || 'same-origin';
        return fetch(url, {
            method: method,
            headers: headers,
            body: body,
            credentials: cred
        }).then(function (r) {
            var ct = (r.headers.get('content-type') || '').toLowerCase();
            if (ct.indexOf('application/json') !== -1) {
                return r.json().then(function (d) {
                    return { ok: r.ok, status: r.status, statusText: r.statusText || '', data: d };
                });
            }
            return r.text().then(function (t) {
                var trimmed = (t || '').trim();
                if (
                    trimmed.length > 0 &&
                    ((trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}') ||
                        (trimmed.charAt(0) === '[' && trimmed.charAt(trimmed.length - 1) === ']'))
                ) {
                    try {
                        return { ok: r.ok, status: r.status, statusText: r.statusText || '', data: JSON.parse(t) };
                    } catch (e) {
                        /* fall through */
                    }
                }
                return { ok: r.ok, status: r.status, statusText: r.statusText || '', data: t };
            });
        });
    }

    window.WelineApiModule = {
        __full: false,
        request: function (url, opts) {
            opts = opts || {};
            return fetchRequest(url, opts);
        },
        get: function (url, opts) {
            return fetchRequest(url, Object.assign({}, opts, { method: 'GET' }));
        },
        post: function (url, data, opts) {
            opts = opts || {};
            var body = typeof data === 'string' ? data : JSON.stringify(data || {});
            var headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
            return fetchRequest(url, { method: 'POST', headers: headers, body: body, credentials: opts.credentials || 'same-origin' });
        },
        markCartActive: function () {},
        markCartEmpty: function () {},
        enableAutoRequests: function () {},
        disableAutoRequests: function () {},
        getClient: function () { return null; }
    };

    // 确保 window.Weline.Api 立即可用，供 head 之后的脚本直接调用
    window.Weline = window.Weline || {};
    window.Weline.Api = window.Weline.Api || window.WelineApiModule;
})(window);
