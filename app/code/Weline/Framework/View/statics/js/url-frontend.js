/**
 * Weline Framework - 前端 URL 处理模块
 * 
 * 参考 Frontend 模块的实现，提供统一的 URL 生成和处理功能
 * 支持：
 * - 前端 URL 生成（支持 website/area/currency/lang 路径注入）
 * - 前端 API URL 生成
 * - 语言切换 URL 生成
 * - 货币切换 URL 生成
 * 
 * URL 结构：[website_url]/[area]/[currency]/[lang]/[path]
 * 
 * 使用方式：
 *   window.url(path) - 通用前端 URL
 *   window.frontend_url(path) - 前端 URL
 *   window.api(path) - 前端 API URL
 *   window.urlWithLang(path, lang) - 带语言的 URL
 *   window.urlWithCurrency(path, currency) - 带货币的 URL
 *   window.select_language(lang) - 切换语言
 *   window.select_currency(currency) - 切换货币
 */
(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.__WelineUrlFrontendInitialized) {
        return;
    }
    window.__WelineUrlFrontendInitialized = true;

    function isIgnorableSwitchQueryParam(key) {
        key = String(key || '').toLowerCase().trim();
        if (!key) {
            return false;
        }
        if (['_', 'ai_perf', 'fbclid', 'gbraid', 'gclid', 'igshid', 'mc_cid', 'mc_eid', 'msclkid', 'wbraid', 'yclid'].includes(key)) {
            return true;
        }
        return key.startsWith('utm_') || key.startsWith('mtm_') || key.startsWith('pk_');
    }

    function sanitizeSwitchSearch(search) {
        const raw = String(search || '');
        if (!raw || raw === '?') {
            return '';
        }

        const params = new URLSearchParams(raw.charAt(0) === '?' ? raw.slice(1) : raw);
        Array.from(params.keys()).forEach(key => {
            if (isIgnorableSwitchQueryParam(key)) {
                params.delete(key);
            }
        });

        const query = params.toString();
        return query ? '?' + query : '';
    }

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

    function writeCanonicalLocalStorage(canonicalKey, legacyKeys, value) {
        try {
            if (!window.localStorage) {
                return;
            }
            localStorage.setItem(canonicalKey, value);
            legacyKeys.forEach((key) => {
                if (key && key !== canonicalKey) {
                    localStorage.removeItem(key);
                }
            });
        } catch (error) {
            // localStorage can be unavailable in privacy modes.
        }
    }

    function persistLangPreference(lang) {
        writeCanonicalLocalStorage('weline_user_lang', ['weline_user_lang', 'api_doc_locale', 'WELINE_USER_LANG'], lang);
        setCookie('WELINE_USER_LANG', lang, 7, { path: '/' });
    }

    function persistCurrencyPreference(currencyCode) {
        writeCanonicalLocalStorage('weline_user_currency', ['weline_user_currency', 'api_doc_currency', 'WELINE_USER_CURRENCY'], currencyCode);
        setCookie('WELINE_USER_CURRENCY', currencyCode, 7, { path: '/' });
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
            baseUrl: config.baseUrl || site.host || window.location.origin,
            host: site.host || config.baseUrl || window.location.origin,
            apiHost: site.api_host || config.url?.apiHost || window.location.origin,
            baseRouter: site.base_router || config.url?.baseRouter || '',
            area: site.area || config.url?.area || '',
            apiArea: site.api_area || config.url?.apiArea || 'api',
            currentLang: site.lang || config.currentLang || config.i18n?.currentLang || 'zh_Hans_CN',
            currentCurrency: site.currency || config.currentCurrency || 'CNY',
            defaultLang: site.default_lang || site.defaultLanguage || config.defaultLang || config.defaultLanguage || config.i18n?.defaultLang || config.i18n?.defaultLanguage || 'zh_Hans_CN',
            defaultCurrency: site.default_currency || site.defaultCurrency || config.defaultCurrency || 'CNY',
            availableCurrencies: config.availableCurrencies || config.supportedCurrencies || config.currencyCodes || config.currencies
                || site.availableCurrencies || site.supportedCurrencies || site.currencyCodes || site.currencies || [],
        };
    }

    function normalizeCurrencyCode(value) {
        return String(value || '').trim().toUpperCase();
    }

    function normalizeLangCode(value) {
        return String(value || '').trim().replace(/-/g, '_');
    }

    function sameLang(a, b) {
        const left = normalizeLangCode(a).toLowerCase();
        const right = normalizeLangCode(b).toLowerCase();
        return left !== '' && right !== '' && left === right;
    }

    function isCurrencyCodeShape(value) {
        return /^[A-Z]{3}$/.test(normalizeCurrencyCode(value));
    }

    function addSupportedCurrencyCode(codes, value) {
        if (value && typeof value === 'object') {
            value = value.code || value.currency || value.currency_code || value.value || '';
        }
        const code = normalizeCurrencyCode(value);
        if (isCurrencyCodeShape(code)) {
            codes[code] = true;
        }
    }

    function collectSupportedCurrencyCodes(codes, source) {
        if (!source) {
            return;
        }
        if (Array.isArray(source)) {
            source.forEach(item => addSupportedCurrencyCode(codes, item));
            return;
        }
        if (typeof source === 'object') {
            Object.keys(source).forEach(key => {
                addSupportedCurrencyCode(codes, key);
                addSupportedCurrencyCode(codes, source[key]);
            });
            return;
        }
        String(source).split(/[,\s|]+/).forEach(code => addSupportedCurrencyCode(codes, code));
    }

    function getSupportedCurrencyCodes(config) {
        const rawConfig = (window.Weline && window.Weline.config) || window.__WelineThemeConfig || {};
        const site = window.site || {};
        const codes = Object.create(null);
        [
            config.availableCurrencies,
            rawConfig.availableCurrencies,
            rawConfig.supportedCurrencies,
            rawConfig.currencyCodes,
            rawConfig.currencies,
            rawConfig.site && rawConfig.site.availableCurrencies,
            rawConfig.site && rawConfig.site.supportedCurrencies,
            rawConfig.site && rawConfig.site.currencyCodes,
            rawConfig.site && rawConfig.site.currencies,
            site.availableCurrencies,
            site.supportedCurrencies,
            site.currencyCodes,
            site.currencies
        ].forEach(source => collectSupportedCurrencyCodes(codes, source));

        document.querySelectorAll('[data-currency-switcher] [data-currency], [data-currency-switcher] [data-currency-option], [data-currency-switcher] .currency-option').forEach(option => {
            addSupportedCurrencyCode(codes, option.getAttribute('data-currency') || option.getAttribute('data-currency-option') || option.dataset.currency);
        });

        addSupportedCurrencyCode(codes, config.defaultCurrency || rawConfig.defaultCurrency || (rawConfig.site && (rawConfig.site.defaultCurrency || rawConfig.site.default_currency)) || site.defaultCurrency || site.default_currency);
        return codes;
    }

    function isCurrencySegment(value, config = getConfig()) {
        const code = normalizeCurrencyCode(value);
        if (!isCurrencyCodeShape(code)) {
            return false;
        }
        return !!getSupportedCurrencyCodes(config)[code];
    }

    function isLangSegment(value) {
        return /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i.test(normalizeLangCode(value));
    }

    function shouldOutputCurrency(currency, config) {
        currency = normalizeCurrencyCode(currency);
        const defaultCurrency = normalizeCurrencyCode(config.defaultCurrency || 'CNY');
        return isCurrencySegment(currency, config) && currency !== defaultCurrency;
    }

    function shouldOutputLang(lang, config) {
        lang = normalizeLangCode(lang);
        const defaultLang = normalizeLangCode(config.defaultLang || 'zh_Hans_CN');
        return lang !== '' && !sameLang(lang, defaultLang);
    }

    function stripLocaleSegments(path, config = getConfig()) {
        const parts = String(path || '/').split('/').filter(Boolean);
        if (parts.length > 0 && isCurrencySegment(parts[0], config)) {
            parts.shift();
        }
        if (parts.length > 0 && isLangSegment(parts[0])) {
            parts.shift();
        }
        return parts.length ? '/' + parts.join('/') : '/';
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
     * 解析路径：移除 website/area/api_area/currency/lang 前缀
     * 参考 Frontend 模块的 app_path 函数
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
                    // 移除 website_code
                    if (getCookie('WELINE_WEBSITE_CODE') && host1s1.startsWith(getCookie('WELINE_WEBSITE_CODE'))) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }

                    const config = getConfig();

                    // 移除 area
                    if (config.area && config.area === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    // 移除 api_area
                    else if (config.apiArea && config.apiArea === host1s1) {
                        host1s.shift();
                        host1s1 = host1s[0];
                    }
                    // 移除 api_admin_area（前端通常不需要，但保留兼容性）
                    const apiAdminArea = getCookie('WELINE_API_ADMIN_AREA') || '';
                    if (apiAdminArea && apiAdminArea === host1s1) {
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

    function normalizeWebsiteBaseUrl(url, config) {
        const rawUrl = String(url || '').trim();
        if (!rawUrl) {
            return '';
        }

        try {
            const parsed = new URL(rawUrl, window.location.origin);
            const firstSegment = parsed.pathname.split('/').filter(Boolean)[0] || '';
            const apiArea = String(config.apiArea || 'api').toLowerCase();
            if (firstSegment && (firstSegment.toLowerCase() === apiArea || firstSegment.toLowerCase() === 'api')) {
                return parsed.origin;
            }
            return parsed.href.replace(/\/$/, '');
        } catch (e) {
            return rawUrl;
        }
    }

    /**
     * 路径注入：将 website/area/currency/lang 注入到路径中
     * 参考 Frontend 模块的 inject_path 函数
     * URL 结构：[website_url]/[area]/[currency]/[lang]/[path]
     */
    function inject_path(path, code = '', type = '') {
        if (!path) {
            throw new Error('inject_path函数path路径不允许为空！');
        }

        if (!path.startsWith('/')) {
            path = '/' + path;
        }

        const config = getConfig();
        let prePath = normalizeWebsiteBaseUrl(getCookie('WELINE_WEBSITE_URL') || '', config);
        const currentLang = normalizeLangCode(getCookie('WELINE_USER_LANG') || config.currentLang || '');
        const rawCurrentCurrency = getCookie('WELINE_USER_CURRENCY') || config.currentCurrency || '';
        const currentCurrency = isCurrencySegment(rawCurrentCurrency, config) ? normalizeCurrencyCode(rawCurrentCurrency) : '';

        // 网站
        if (prePath && WelineString.startsWith(path, prePath)) {
            path = WelineString.replaceStartsWith(path, prePath, '');
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

        // 区域
        if (config.area && WelineString.startsWith(path, '/' + config.area)) {
            if ('area' === type && code) {
                path = WelineString.replaceStartsWith(path, '/' + config.area, '');
                prePath += '/' + code;
            } else {
                path = WelineString.replaceStartsWith(path, '/' + config.area, '');
                prePath += '/' + config.area;
            }
        } else {
            if ('area' === type && code) {
                prePath += '/' + code;
            } else if (config.area) {
                prePath += '/' + config.area;
            }
        }

        path = stripLocaleSegments(path, config);
        const targetCurrency = normalizeCurrencyCode('currency' === type && code ? code : currentCurrency);
        const targetLang = normalizeLangCode('lang' === type && code ? code : currentLang);

        if (shouldOutputCurrency(targetCurrency, config)) {
            prePath += '/' + targetCurrency;
        }

        if (shouldOutputLang(targetLang, config)) {
            prePath += '/' + targetLang;
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
        const currentUrl = path || window.location.pathname + sanitizeSwitchSearch(window.location.search || '');
        const injectedPath = inject_path(currentUrl.split('?')[0], lang, 'lang');
        const search = currentUrl.includes('?') ? sanitizeSwitchSearch(currentUrl.split('?')[1] || '') : '';
        return injectedPath + search;
    }

    /**
     * 构建货币切换 URL
     */
    function buildUrlWithCurrency(path, currency, options = {}) {
        const currentUrl = path || window.location.pathname + sanitizeSwitchSearch(window.location.search || '');
        const injectedPath = inject_path(currentUrl.split('?')[0], currency, 'currency');
        const search = currentUrl.includes('?') ? sanitizeSwitchSearch(currentUrl.split('?')[1] || '') : '';
        return injectedPath + search;
    }

    const config = getConfig();

    /**
     * 通用 URL 生成函数（前端）
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
        return buildUrlWithParams(config.host, normalizedPath, params);
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
     * 前端 API URL 生成
     * 注意：前端 API 路径格式：/api/... 或 /{api_area}/...
     */
    window.api = function (path, params = {}) {
        if (!path) {
            return '';
        }
        const normalizedPath = normalizePath(path, config.baseRouter);
        // 前端 API 路径：确保以 api_area 开头
        const apiPath = config.apiArea ? config.apiArea + '/' + normalizedPath : 'api/' + normalizedPath;
        return buildUrlWithParams(config.apiHost, apiPath, params);
    };

    /**
     * 前端 API URL 生成（别名）
     */
    window.frontend_api = function (path, params = {}) {
        return window.api(path, params);
    };

    /**
     * 路径规范化函数
     */
    window.path = function (path) {
        return normalizePath(path, config.baseRouter);
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
     * 切换语言（参考 Frontend 模块的 select_language）
     */
    window.select_language = function (lang) {
        // URL结构 [website_url]/[area]/[currency]/[lang]/[path]
        persistLangPreference(lang);
        window.location.href = inject_path(window.location.pathname, lang, 'lang') + sanitizeSwitchSearch(window.location.search || '');
    };

    /**
     * 切换货币（参考 Frontend 模块的 select_currency）
     */
    window.select_currency = function (currencyCode) {
        // URL结构 [website_url]/[area]/[currency]/[lang]/[path]
        persistCurrencyPreference(currencyCode);
        window.location.href = inject_path(window.location.pathname, currencyCode, 'currency') + sanitizeSwitchSearch(window.location.search || '');
    };

    /**
     * 路径解析函数（供其他模块使用）
     */
    window.app_path = app_path;

    /**
     * 路径注入函数（供其他模块使用）
     */
    window.inject_path = inject_path;

    // 导出到 Weline 对象（如果存在）
    if (window.Weline) {
        window.Weline.Url = window.Weline.Url || {};
        Object.assign(window.Weline.Url, {
            url: window.url,
            frontend: window.frontend_url,
            api: window.api,
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
