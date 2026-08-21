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

    function readConfig() {
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

    function getCookie(name) {
        var prefix = encodeURIComponent(String(name)) + '=';
        var row = document.cookie.split('; ').find(function (item) { return item.indexOf(prefix) === 0; });
        return row ? decodeURIComponent(row.slice(prefix.length)) : null;
    }

    function setCookie(name, value, days, options) {
        var settings = options && typeof options === 'object' ? options : {};
        var parts = [encodeURIComponent(String(name)) + '=' + encodeURIComponent(String(value == null ? '' : value))];
        if (Number.isFinite(Number(days))) {
            parts.push('Expires=' + new Date(Date.now() + Number(days) * 86400000).toUTCString());
        }
        parts.push('Path=' + String(settings.path || '/'));
        parts.push('SameSite=' + String(settings.sameSite || 'Lax'));
        if (settings.secure !== false && window.location.protocol === 'https:') parts.push('Secure');
        document.cookie = parts.join('; ');
    }

    function path(value, baseRouter) {
        return String(value || '').replace(/\*/g, String(baseRouter || '')).replace(/^\/{1,2}/, '');
    }

    function join(base, value) {
        return String(base || '').replace(/\/+$/, '') + '/' + String(value || '').replace(/^\/+/, '');
    }

    function translate(dictionary, phrase, parameters) {
        var text = Object.prototype.hasOwnProperty.call(dictionary, phrase) ? dictionary[phrase] : phrase;
        if (parameters == null) return text;
        if (typeof parameters === 'string' || typeof parameters === 'number') {
            return text.replace(/%\{(?:1)?\}/g, String(parameters));
        }
        Object.keys(parameters).forEach(function (key) {
            var escaped = String(key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            text = text.replace(new RegExp('%\\{' + escaped + '\\}', 'g'), String(parameters[key] == null ? '' : parameters[key]));
        });
        return text;
    }

    var config = readConfig();
    var Weline = window.Weline = window.Weline || {};
    var site = merge({}, config.site || {});
    site.computePath = site.computePath || {};
    site.i18n = site.i18n || config.i18n && config.i18n.dictionary || {};
    var urlConfig = config.url || {};
    var Runtime = {
        config: config,
        site: site,
        path: function (value) { return path(value, site.base_router); },
        media: function (value, moduleName) {
            return String(site.env_model_media_base_path_template || '')
                .replace('{path}', Runtime.path(value))
                .replace('{module}', String(moduleName || site.module || '').replace('_', '/'));
        },
        frontendUrl: function (value) { return join(urlConfig.frontendHost || site.host, Runtime.path(value)); },
        apiUrl: function (value) { return join(urlConfig.apiHost || site.api_host, Runtime.path(value)); },
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
    window.url = Runtime.frontendUrl;
    window.frontend_url = Runtime.frontendUrl;
    window.api = Runtime.apiUrl;
    window.frontend_api = Runtime.apiUrl;
    window.phrase = Runtime.translate;
    window.__ = Runtime.translate;
    window.lang = Runtime.translate;
})(window, document);
