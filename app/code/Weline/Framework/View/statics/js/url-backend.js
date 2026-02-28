/**
 * Weline Framework - 后端 URL 处理模块
 * 
 * 参考 Backend 模块的实现，提供统一的后端 URL 生成和处理功能
 * 支持：
 * - 后端 URL 生成（注意 admin 前缀逻辑）
 * - 后端 API URL 生成（注意 api_admin 前缀逻辑）
 * - 前端 API URL 生成
 * - 语言切换 URL 生成
 * - 货币切换 URL 生成
 * 
 * 注意：
 * - 后端 URL 使用 admin 前缀（从配置获取）
 * - 后端 API URL 使用 api_admin 前缀（从配置获取）
 * - 前端 API URL 使用 api 前缀
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
            if (ignoreCase) {
                str = str.toLowerCase();
                prefix = prefix.toLowerCase();
            }
            return str.lastIndexOf(prefix, 0) === 0;
        },
        replaceStartsWith: (str, prefix, value, ignoreCase = true) => {
            if (ignoreCase) {
                str = str.toLowerCase();
                prefix = prefix.toLowerCase();
            }
            return str.replace(new RegExp('^' + prefix), value);
        }
    };

    /**
     * 获取配置
     * 优先使用新的 Theme 配置方式：
     * - window.Weline.config（推荐）
     * - window.__WelineThemeConfig（旧的注入方式）
     * 已弃用：window.WelineConfig（不再从这里读取，避免与 Theme.js 的别名产生递归）
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

    /**
     * 路径规范化：移除开头的双斜杠和单斜杠
     */
    function normalizePath(path, baseRouter = '') {
        if (!path) {
            return '';
        }

        // 替换 * 为 baseRouter
        if (baseRouter && path.includes('*')) {
            path = path.replace(/\*/g, baseRouter);
        }

        // 移除开头的双斜杠
        if (path.indexOf('//') === 0) {
            path = path.slice(2);
        }

        // 移除开头的单斜杠（如果需要）
        if (path.indexOf('/') === 0) {
            path = path.slice(1);
        }

        return path;
    }

    /**
     * 解析路径：移除 website/area/api_area/api_admin_area/currency/lang 前缀
     * 参考 Backend 模块的 app_path 函数
     */
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

        // 提取类似 http://localhost:8080/ 的 host
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

                    // 移除 website_code
                    if (getCookie('WELINE_WEBSITE_CODE') && host1s1.startsWith(getCookie('WELINE_WEBSITE_CODE'))) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }

                    // 移除 area (admin)
                    if (config.adminArea && config.adminArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    // 移除 api_area
                    else if (config.apiArea && config.apiArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    // 移除 api_admin_area（后端特有）
                    else if (config.apiAdminArea && config.apiAdminArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }

                    // 移除 currency
                    if (getCookie('WELINE_USER_CURRENCY') === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }

                    // 移除 lang
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

    /**
     * 路径注入：将 website/area/currency/lang 注入到路径中
     * 参考 Backend 模块的 inject_path 函数
     */
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

        // 网站
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

        prePath = decodeURIComponent(prePath);
        if (prePath.endsWith('/')) {
            prePath = prePath.slice(0, -1);
        }

        // 区域（后端使用 admin）
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

        // 币种
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

        // 语言
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

    /**
     * 构建带参数的 URL
     */
    function buildUrlWithParams(baseUrl, path, params = {}) {
        const normalizedPath = normalizePath(path);
        let url = baseUrl.replace(/\/+$/, '') + '/' + normalizedPath.replace(/^\/+/, '');

        // 添加查询参数
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

    /**
     * 构建语言切换 URL
     */
    function buildUrlWithLang(path, lang, options = {}) {
        const currentUrl = path || window.location.pathname + window.location.search;
        const injectedPath = inject_path(currentUrl.split('?')[0], lang, 'lang');
        const search = currentUrl.includes('?') ? currentUrl.split('?')[1] : '';
        return injectedPath + (search ? '?' + search : '');
    }

    /**
     * 构建货币切换 URL
     */
    function buildUrlWithCurrency(path, currency, options = {}) {
        const currentUrl = path || window.location.pathname + window.location.search;
        const injectedPath = inject_path(currentUrl.split('?')[0], currency, 'currency');
        const search = currentUrl.includes('?') ? currentUrl.split('?')[1] : '';
        return injectedPath + (search ? '?' + search : '');
    }

    const config = getConfig();

    /**
     * 路径规范化函数
     */
    window.path = function (path) {
        return normalizePath(path, config.baseRouter);
    };

    /**
     * 通用 URL 生成函数（后端）
     */
    window.url = function (path, params = {}) {
        if (!path) {
            return '';
        }

        // 如果已经是完整 URL，直接返回
        if (/^https?:\/\//i.test(path)) {
            return path;
        }

        const normalizedPath = normalizePath(path, config.baseRouter);
        return window.backend_url(normalizedPath, params);
    };

    /**
     * 后端 URL 生成（注意 admin 前缀）
     */
    window.backend_url = function (path, params = {}) {
        if (!path) {
            return '';
        }
        const normalizedPath = normalizePath(path, config.baseRouter);
        // 后端 URL 需要加上 admin 前缀
        const backendPath = config.adminArea ? config.adminArea + '/' + normalizedPath : normalizedPath;
        return buildUrlWithParams(config.urlHost, backendPath, params);
    };

    /**
     * 前端 URL 生成
     */
    window.frontend_url = function (path, params = {}) {
        if (!path) {
            return '';
        }
        const normalizedPath = normalizePath(path, config.baseRouter);
        return buildUrlWithParams(config.host, normalizedPath, params);
    };

    /**
     * 后端 API URL 生成（注意 api_admin 前缀）
     * 后端 API 路径格式：/{api_admin}/rest/v1/...
     */
    window.api = function (path, params = {}) {
        if (!path) {
            return '';
        }
        const normalizedPath = normalizePath(path, config.baseRouter);
        // 后端 API 路径：确保以 api_admin 开头
        const apiPath = config.apiAdminArea ? config.apiAdminArea + '/' + normalizedPath : normalizedPath;
        return buildUrlWithParams(config.apiHost, apiPath, params);
    };

    /**
     * 后端 API URL 生成（别名）
     */
    window.backend_api = function (path, params = {}) {
        return window.api(path, params);
    };

    /**
     * 前端 API URL 生成
     * 前端 API 路径格式：/api/... 或 /{api_area}/...
     */
    window.frontend_api = function (path, params = {}) {
        if (!path) {
            return '';
        }
        const normalizedPath = normalizePath(path, config.baseRouter);
        // 前端 API 路径：确保以 api_area 开头
        const apiPath = config.apiArea ? config.apiArea + '/' + normalizedPath : 'api/' + normalizedPath;
        return buildUrlWithParams(config.frontendApiHost, apiPath, params);
    };

    /**
     * 带语言的 URL 生成
     */
    window.urlWithLang = function (path, lang, options = {}) {
        return buildUrlWithLang(path, lang, options);
    };

    /**
     * 带货币的 URL 生成
     */
    window.urlWithCurrency = function (path, currency, options = {}) {
        return buildUrlWithCurrency(path, currency, options);
    };

    /**
     * 切换语言
     */
    window.select_language = function (lang) {
        setCookie('WELINE_USER_LANG', lang, 7, { path: '/' + config.adminArea, domain: window.location.host });
        window.location.href = inject_path(window.location.pathname, lang, 'lang') + window.location.search;
    };

    /**
     * 切换货币
     */
    window.select_currency = function (currencyCode) {
        setCookie('WELINE_USER_CURRENCY', currencyCode, 7, { path: '/', domain: window.location.host });
        window.location.href = inject_path(window.location.pathname, currencyCode, 'currency') + window.location.search;
    };

    /**
     * 路径解析函数（供其他模块使用）
     */
    window.app_path = app_path;

    /**
     * 路径注入函数（供其他模块使用）
     */
    window.inject_path = inject_path;

    // 导出模块对象（供模块加载器识别）
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

    // 导出到 Weline 对象（如果存在）
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
