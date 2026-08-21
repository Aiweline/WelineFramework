(function (window, document) {
    'use strict';

    function merge(target, source) {
        if (!source || typeof source !== 'object' || Array.isArray(source)) return target;
        Object.keys(source).forEach(function (key) {
            var value = source[key];
            if (value && typeof value === 'object' && !Array.isArray(value)) {
                target[key] = merge(target[key] && typeof target[key] === 'object' ? target[key] : {}, value);
            } else {
                target[key] = value;
            }
        });
        return target;
    }

    function readConfigNodes() {
        var config = {};
        document.querySelectorAll('script[type="application/json"][data-weline-runtime-config]').forEach(function (node) {
            try {
                merge(config, JSON.parse(node.textContent || '{}'));
            } catch (error) {
                throw new Error('[Weline.Runtime] Invalid runtime configuration: ' + error.message);
            }
        });
        return config;
    }

    function encodeCookiePart(value) {
        return encodeURIComponent(String(value == null ? '' : value));
    }

    function getCookie(name) {
        var prefix = encodeCookiePart(name) + '=';
        var row = document.cookie.split('; ').find(function (item) { return item.indexOf(prefix) === 0; });
        return row ? decodeURIComponent(row.slice(prefix.length)) : null;
    }

    function setCookie(name, value, days, options) {
        var settings = options && typeof options === 'object' ? options : {};
        var parts = [encodeCookiePart(name) + '=' + encodeCookiePart(value)];
        if (Number.isFinite(Number(days))) {
            var expires = new Date(Date.now() + (Number(days) * 86400000));
            parts.push('Expires=' + expires.toUTCString());
        }
        parts.push('Path=' + String(settings.path || '/'));
        parts.push('SameSite=' + String(settings.sameSite || 'Lax'));
        if (settings.secure !== false && window.location.protocol === 'https:') parts.push('Secure');
        document.cookie = parts.join('; ');
    }

    function normalizePath(value, baseRouter) {
        var path = String(value || '');
        if (path.indexOf('*') !== -1) path = path.replace(/\*/g, String(baseRouter || ''));
        return path.replace(/^\/{1,2}/, '');
    }

    function joinUrl(base, path) {
        return String(base || '').replace(/\/+$/, '') + '/' + String(path || '').replace(/^\/+/, '');
    }

    function translate(dictionary, phrase, parameters) {
        var text = Object.prototype.hasOwnProperty.call(dictionary, phrase) ? dictionary[phrase] : phrase;
        if (parameters == null) return text;
        if (typeof parameters === 'string' || typeof parameters === 'number') {
            return text.replace(/%\{(?:1)?\}/g, String(parameters));
        }
        Object.keys(parameters).forEach(function (key) {
            text = text.replace(new RegExp('%\\{' + String(key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\}', 'g'), String(parameters[key] == null ? '' : parameters[key]));
        });
        return text;
    }

    var config = readConfigNodes();
    var Weline = window.Weline = window.Weline || {};
    var site = merge({}, config.site || {});
    site.computePath = site.computePath || {};
    site.i18n = site.i18n || config.i18n && config.i18n.dictionary || {};

    var Runtime = {
        config: config,
        site: site,
        refreshConfig: function () {
            config = merge(config, readConfigNodes());
            Runtime.config = config;
            Weline.config = config;
            return config;
        },
        path: function (path) { return normalizePath(path, site.base_router); },
        media: function (path, moduleName) {
            return String(site.env_model_media_base_path_template || '')
                .replace('{path}', Runtime.path(path))
                .replace('{module}', String(moduleName || site.module || '').replace('_', '/'));
        },
        backendUrl: function (path) { return joinUrl(site.url_host, Runtime.path(path)); },
        frontendUrl: function (path) { return joinUrl(site.host, Runtime.path(path)); },
        apiUrl: function (path) { return joinUrl(site.api_host, Runtime.path(path)); },
        frontendApiUrl: function (path) { return joinUrl(site.frontend_api_host, Runtime.path(path)); },
        getCookie: getCookie,
        setCookie: setCookie,
        translate: function (phrase, parameters) { return translate(site.i18n, phrase, parameters); },
    };

    Weline.Runtime = Runtime;
    Weline.config = config;
    window.WelineApiConfig = merge(window.WelineApiConfig || {}, config.api || {});
    window.site = site;
    window.WELINE_ENV = config.env && config.env.WELINE_ENV || 'PROD';
    window.DEV = !!(config.env && config.env.DEV);
    window.PROD = !window.DEV;

    window.getCookie = getCookie;
    window.setCookie = setCookie;
    window.path = Runtime.path;
    window.media = Runtime.media;
    window.url = Runtime.backendUrl;
    window.backend_url = Runtime.backendUrl;
    window.frontend_url = Runtime.frontendUrl;
    window.api = Runtime.apiUrl;
    window.backend_api = Runtime.apiUrl;
    window.frontend_api = Runtime.frontendApiUrl;
    window.phrase = Runtime.translate;
    window.__ = Runtime.translate;
    window.lang = Runtime.translate;
    window.p = function (value) { if (window.DEV) console.log(value); };
    window.d = window.p;
})(window, document);
