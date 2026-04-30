/**
 * Weline Backend - 后端 URL 处理模块
 *
 * 提供统一的后端 URL 生成和处理功能，支持：
 * - 后端 URL 生成（注意 admin 前缀逻辑）
 * - 后端 API URL 生成（注意 api_admin 前缀逻辑）
 * - 前端 API URL 生成
 * - 语言切换 URL 生成
 * - 货币切换 URL 生成
 *
 * 使用方式：
 *   window.url(path) - 通用后端 URL
 *   window.backend_url(path) - 后端 URL
 *   window.api(path) - 后端 API URL
 *   window.backend_api(path) - 后端 API URL（别名）
 *   window.frontend_api(path) - 前端 API URL
 *   window.urlWithLang(path, lang) - 带语言的 URL
 *   window.urlWithCurrency(path, currency) - 带货币的 URL
 */
(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.__WelineUrlBackendInitialized) {
        return;
    }
    window.__WelineUrlBackendInitialized = true;

    /**
     * Cookie 操作函数（如果不存在则定义）
     */
    function getCookie(key) {
        if (typeof window.getCookie === 'function') {
            return window.getCookie(key);
        }
        const keyValue = document.cookie.match('(^|;) ?' + key + '=([^;]*)(;|$)');
        return keyValue ? keyValue[2] : null;
    }

    function setCookie(key, value, expiry = 7, options = {}) {
        if (typeof window.setCookie === 'function') {
            return window.setCookie(key, value, expiry, options);
        }
        const expires = new Date();
        expires.setTime(expires.getTime() + (expiry * 24 * 60 * 60 * 1000));
        let cookieString = key + '=' + value + ';expires=' + expires.toUTCString();
        for (const optionKey in options) {
            cookieString += ';' + optionKey + '=' + options[optionKey];
        }
        document.cookie = cookieString;
    }

    /**
     * 字符串工具函数（如果不存在则定义）
     */
    const WelineString = window.WelineString || {
        startsWith: (str, prefix, ignoreCase = true) => {
            str = String(str ?? '');
            prefix = String(prefix ?? '');
            if (ignoreCase) {
                str = str.toLowerCase();
                prefix = prefix.toLowerCase();
            }
            return str.lastIndexOf(prefix, 0) === 0;
        },
        replaceStartsWith: (str, prefix, value, ignoreCase = true) => {
            str = String(str ?? '');
            prefix = String(prefix ?? '');
            value = String(value ?? '');
            if (ignoreCase) {
                str = str.toLowerCase();
                prefix = prefix.toLowerCase();
            }
            return str.replace(new RegExp('^' + prefix), value);
        }
    };

    /**
     * 获取配置
     */
    function getConfig() {
        const config = (window.Weline && window.Weline.config) || window.__WelineThemeConfig || {};
        const site = window.site || {};
        return {
            baseUrl: config.baseUrl || site.url_host || window.location.origin,
            urlHost: site.url_host || config.url?.urlHost || window.location.origin,
            host: site.host || config.baseUrl || window.location.origin,
            apiHost: site.api_host || config.url?.apiHost || window.location.origin,
            frontendApiHost: site.frontend_api_host || config.url?.frontendApiHost || window.location.origin,
            baseRouter: site.base_router || config.url?.baseRouter || '',
            adminArea: site.area || config.url?.adminArea || 'admin',
            apiArea: site.api_area || config.url?.apiArea || 'api',
            apiAdminArea: site.api_admin_area || config.url?.apiAdminArea || getCookie('WELINE_API_ADMIN_AREA') || '',
            currentLang: site.lang || config.currentLang || config.i18n?.currentLang || 'zh_Hans_CN',
            currentCurrency: site.currency || config.currentCurrency || 'CNY',
        };
    }

    function normalizePath(path, baseRouter = '') {
        if (!path) {
            return '';
        }
        if (baseRouter && path.includes('*')) {
            path = path.replace(/\*/g, baseRouter);
        }
        if (path.indexOf('//') === 0) {
            path = path.slice(2);
        }
        if (path.indexOf('/') === 0) {
            path = path.slice(1);
        }
        return path;
    }

    function app_path(path) {
        const originPath = path;
        if (!window.site) {
            window.site = {};
        }
        if (!window.site.computePath) {
            window.site.computePath = {};
        }
        if (window.site.computePath[originPath]) {
            return window.site.computePath[originPath];
        }
        if (path.indexOf('://') > 0) {
            const hosts = path.split('://');
            const host0 = hosts[0] + '://';
            let host1 = hosts[1];
            if (host1.indexOf('/') > 0) {
                const host1s = host1.split('/');
                const hostName = host1s.shift();
                const host0Full = host0 + hostName;
                let host1s1 = host1s[0];
                if (host1s1) {
                    const config = getConfig();
                    if (getCookie('WELINE_WEBSITE_CODE') && host1s1.startsWith(getCookie('WELINE_WEBSITE_CODE'))) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    if (config.adminArea && config.adminArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    } else if (config.apiArea && config.apiArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    } else if (config.apiAdminArea && config.apiAdminArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    if (getCookie('WELINE_USER_CURRENCY') === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    if (getCookie('WELINE_USER_LANG') === host1s1) {
                        host1s.shift();
                    }
                }
                path = host0Full + '/' + host1s.join('/');
            } else {
                path = host1;
            }
        }
        window.site.computePath[originPath] = path;
        return window.site.computePath[originPath];
    }

    function inject_path(path, code = '', type = '') {
        if (!path) {
            throw new Error('inject_path函数path路径不允许为空！');
        }
        if (!path.startsWith('/')) {
            path = '/' + path;
        }
        let prePath = getCookie('WELINE_WEBSITE_URL') || '';
        const config = getConfig();
        const currentLang = getCookie('WELINE_USER_LANG') || config.currentLang || '';
        const currentCurrency = getCookie('WELINE_USER_CURRENCY') || config.currentCurrency || '';

        if (WelineString.startsWith(path, getCookie('WELINE_WEBSITE_URL') || '')) {
            path = WelineString.replaceStartsWith(path, getCookie('WELINE_WEBSITE_URL') || '', '');
        } else {
            path = WelineString.replaceStartsWith(path, config.baseRouter, '');
        }
        if (WelineString.startsWith(path, '/' + (getCookie('WELINE_WEBSITE_CODE') || ''))) {
            path = WelineString.replaceStartsWith(path, '/' + (getCookie('WELINE_WEBSITE_CODE') || ''), '/');
        }
        if ('website' === type && code) {
            prePath = code;
        }
        prePath = decodeURIComponent(String(prePath || ''));
        if (prePath.endsWith('/')) {
            prePath = prePath.slice(0, -1);
        }
        if (config.adminArea && WelineString.startsWith(path, '/' + config.adminArea)) {
            if ('area' === type && code) {
                path = WelineString.replaceStartsWith(path, '/' + config.adminArea, '');
                prePath += '/' + code;
            } else {
                path = WelineString.replaceStartsWith(path, '/' + config.adminArea, '');
                prePath += '/' + config.adminArea;
            }
        } else {
            if ('area' === type && code) {
                prePath += '/' + code;
            } else if (config.adminArea) {
                prePath += '/' + config.adminArea;
            }
        }
        if (currentCurrency && WelineString.startsWith(path, '/' + currentCurrency)) {
            if ('currency' === type && code) {
                path = WelineString.replaceStartsWith(path, '/' + currentCurrency, '');
                prePath += '/' + code;
            } else {
                path = WelineString.replaceStartsWith(path, '/' + currentCurrency, '');
                prePath += '/' + currentCurrency;
            }
        } else {
            if ('currency' === type && code) {
                prePath += '/' + code;
            } else if (currentCurrency) {
                prePath += '/' + currentCurrency;
            }
        }
        if (currentLang && WelineString.startsWith(path, '/' + currentLang)) {
            if ('lang' === type && code) {
                path = WelineString.replaceStartsWith(path, '/' + currentLang, '');
                prePath += '/' + code;
            } else {
                path = WelineString.replaceStartsWith(path, '/' + currentLang, '');
                prePath += '/' + currentLang;
            }
        } else {
            if ('lang' === type && code) {
                prePath += '/' + code;
            } else if (currentLang) {
                prePath += '/' + currentLang;
            }
        }
        return prePath + path;
    }

    function buildUrlWithParams(baseUrl, path, params = {}) {
        const normalizedPath = normalizePath(path);
        let url = baseUrl.replace(/\/+$/, '') + '/' + normalizedPath.replace(/^\/+/, '');
        const queryParams = [];
        for (const key in params) {
            if (params.hasOwnProperty(key) && params[key] !== null && params[key] !== undefined) {
                queryParams.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
            }
        }
        if (queryParams.length > 0) {
            url += (url.includes('?') ? '&' : '?') + queryParams.join('&');
        }
        return url;
    }

    function splitPathAndSearch(inputPath) {
        const raw = inputPath || '';
        const qIndex = raw.indexOf('?');
        if (qIndex >= 0) {
            return {
                pathname: raw.slice(0, qIndex) || '/',
                search: raw.slice(qIndex)
            };
        }
        return {
            pathname: raw || '/',
            search: ''
        };
    }

    // 按当前路径结构重建 lang/currency，避免 inject_path 在后台路径中重复拼接 admin 段
    function rebuildLocalizedPath(pathname, options = {}) {
        const config = getConfig();
        const langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
        const currencyPattern = /^[A-Z]{3}$/;
        const parts = (pathname || '/').split('/').filter(Boolean);
        const nonLocalizedParts = parts.filter(part => !currencyPattern.test(part) && !langPattern.test(part));
        const prefix = nonLocalizedParts.length ? nonLocalizedParts[0] : '';
        const remain = prefix ? nonLocalizedParts.slice(1) : nonLocalizedParts;

        let currentCurrency = '';
        for (const part of parts) {
            if (currencyPattern.test(part)) {
                currentCurrency = part.toUpperCase();
                break;
            }
        }

        let currentLang = '';
        for (const part of parts) {
            if (langPattern.test(part)) {
                currentLang = part;
                break;
            }
        }

        const targetCurrency = (options.currency || currentCurrency || getCookie('WELINE_USER_CURRENCY') || config.currentCurrency || 'CNY').toUpperCase();
        const targetLang = options.lang || currentLang || getCookie('WELINE_USER_LANG') || config.currentLang || 'zh_Hans_CN';

        const out = [];
        if (prefix) {
            out.push(prefix);
        }
        if (targetCurrency) {
            out.push(targetCurrency);
        }
        if (targetLang) {
            out.push(targetLang);
        }
        if (remain.length) {
            out.push(...remain);
        }
        return '/' + out.join('/');
    }

    function buildUrlWithLang(path, lang) {
        const currentUrl = path || window.location.pathname + window.location.search;
        const parsed = splitPathAndSearch(currentUrl);
        const localizedPath = rebuildLocalizedPath(parsed.pathname, { lang: lang });
        return localizedPath + parsed.search;
    }

    function buildUrlWithCurrency(path, currency) {
        const currentUrl = path || window.location.pathname + window.location.search;
        const parsed = splitPathAndSearch(currentUrl);
        const localizedPath = rebuildLocalizedPath(parsed.pathname, { currency: currency });
        return localizedPath + parsed.search;
    }

    const config = getConfig();

    window.path = function (path) {
        return normalizePath(path, config.baseRouter);
    };

    window.url = function (path, params = {}) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        return window.backend_url(normalizePath(path, config.baseRouter), params);
    };

    window.backend_url = function (path, params = {}) {
        if (!path) return '';
        const normalizedPath = normalizePath(path, config.baseRouter);
        const backendPath = config.adminArea ? config.adminArea + '/' + normalizedPath : normalizedPath;
        return buildUrlWithParams(config.urlHost, backendPath, params);
    };

    window.frontend_url = function (path, params = {}) {
        if (!path) return '';
        return buildUrlWithParams(config.host, normalizePath(path, config.baseRouter), params);
    };

    window.api = function (path, params = {}) {
        if (!path) return '';
        const normalizedPath = normalizePath(path, config.baseRouter);
        const apiPath = config.apiAdminArea ? config.apiAdminArea + '/' + normalizedPath : normalizedPath;
        return buildUrlWithParams(config.apiHost, apiPath, params);
    };

    window.backend_api = window.api;

    window.frontend_api = function (path, params = {}) {
        if (!path) return '';
        const normalizedPath = normalizePath(path, config.baseRouter);
        const apiPath = config.apiArea ? config.apiArea + '/' + normalizedPath : 'api/' + normalizedPath;
        return buildUrlWithParams(config.frontendApiHost, apiPath, params);
    };

    window.urlWithLang = buildUrlWithLang;
    window.urlWithCurrency = buildUrlWithCurrency;

    window.select_language = function (lang) {
        setCookie('WELINE_USER_LANG', lang, 7, { path: '/' + config.adminArea, domain: window.location.host });
        window.location.href = buildUrlWithLang(window.location.pathname + window.location.search, lang);
    };

    window.select_currency = function (currencyCode) {
        setCookie('WELINE_USER_CURRENCY', currencyCode, 7, { path: '/', domain: window.location.host });
        window.location.href = buildUrlWithCurrency(window.location.pathname + window.location.search, currencyCode);
    };

    window.app_path = app_path;
    window.inject_path = inject_path;

    const UrlBackendModule = {
        url: window.url,
        backend_url: window.backend_url,
        frontend_url: window.frontend_url,
        api: window.api,
        backend_api: window.backend_api,
        frontend_api: window.frontend_api,
        urlWithLang: window.urlWithLang,
        urlWithCurrency: window.urlWithCurrency,
        select_language: window.select_language,
        select_currency: window.select_currency,
        path: window.path,
        app_path: window.app_path,
        inject_path: window.inject_path,
    };
    window.WelineUrlBackendModule = UrlBackendModule;

    if (window.Weline) {
        window.Weline.Url = window.Weline.Url || {};
        Object.assign(window.Weline.Url, {
            url: window.url,
            backend: window.backend_url,
            frontend: window.frontend_url,
            api: window.api,
            backendApi: window.backend_api,
            frontendApi: window.frontend_api,
            withLang: window.urlWithLang,
            withCurrency: window.urlWithCurrency,
            selectLanguage: window.select_language,
            selectCurrency: window.select_currency,
            path: window.path,
            appPath: window.app_path,
            injectPath: window.inject_path,
        });
    }

})(window, document);
