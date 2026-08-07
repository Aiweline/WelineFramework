/**
 * Weline 国际化（i18n）模块
 * 
 * 功能：
 * 1. 自动初始化语言切换器
 * 2. 更新语言切换链接
 * 3. 监听语言切换事件
 * 4. 更新当前语言显示
 */
(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.WelineI18n && window.WelineI18n.__initialized) {
        return;
    }

    function readCookieValue(key) {
        if (!key) {
            return '';
        }
        if (typeof window.getCookie === 'function') {
            const value = window.getCookie(key);
            if (value) {
                return value;
            }
        }
        const match = document.cookie.match('(?:^|; )' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)');
        return match ? decodeURIComponent(match[1]) : '';
    }

    function writeCookieValue(key, value, expiry = 365, options = {}) {
        if (!key) {
            return;
        }
        const normalizedOptions = Object.assign({ path: '/' }, options || {});
        if (typeof window.setCookie === 'function') {
            window.setCookie(key, value, expiry, normalizedOptions);
            return;
        }
        const expires = new Date();
        expires.setTime(expires.getTime() + (expiry * 24 * 60 * 60 * 1000));
        let cookieString = key + '=' + encodeURIComponent(value) + ';expires=' + expires.toUTCString();
        Object.keys(normalizedOptions).forEach((optionKey) => {
            cookieString += ';' + optionKey + '=' + normalizedOptions[optionKey];
        });
        document.cookie = cookieString;
    }

    function writeLanguagePreference(lang) {
        try {
            if (window.localStorage) {
                localStorage.setItem('weline_user_lang', lang);
                localStorage.removeItem('api_doc_locale');
                localStorage.removeItem('WELINE_USER_LANG');
            }
        } catch (error) {
            // localStorage can be unavailable in privacy modes.
        }
        writeCookieValue('WELINE_USER_LANG', lang, 365);
    }

    /**
     * 获取当前语言代码
     */
    function getCurrentLang() {
        const cookieLang = readCookieValue('WELINE_USER_LANG');
        const pathLang = detectPathLanguage();
        const config = window.__WelineThemeConfig || {};
        const configLang = config.currentLang || config.i18n?.currentLang || (window.site && window.site.lang) || '';

        // URL 路径段优先（与服务端 State::getLang 一致）
        if (pathLang) {
            return pathLang;
        }

        // Cookie 仅在属于页面上真实可选语言时生效，避免残留 ar_* 把头部钉死在 AR
        if (cookieLang && isLanguageOfferedOnPage(cookieLang)) {
            return cookieLang;
        }

        if (configLang) {
            return configLang;
        }

        const firstOffered = getFirstOfferedLanguage();
        if (firstOffered) {
            return firstOffered;
        }

        // 从 URL 参数获取（兼容旧链接）
        const urlParams = new URLSearchParams(window.location.search);
        const urlLang = urlParams.get('lang');
        if (urlLang) {
            return urlLang;
        }

        return 'zh_Hans_CN';
    }

    function detectPathLanguage() {
        const pathParts = window.location.pathname.split('/').filter(Boolean);
        const langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
        for (const part of pathParts) {
            if (langPattern.test(part)) {
                return part;
            }
        }
        return '';
    }

    function isLanguageOfferedOnPage(langCode) {
        const needle = String(langCode || '').trim().replace(/-/g, '_').toLowerCase();
        if (!needle) {
            return false;
        }
        const options = document.querySelectorAll(
            '[data-i18n-switcher] [data-lang], [data-weline-choice-switcher="language"] [data-lang]'
        );
        if (!options.length) {
            // 切换器尚未渲染时不据此否决 cookie
            return true;
        }
        for (const option of options) {
            const code = String(option.getAttribute('data-lang') || '').trim().replace(/-/g, '_').toLowerCase();
            if (code && code === needle) {
                return true;
            }
        }
        return false;
    }

    function getFirstOfferedLanguage() {
        const option = document.querySelector(
            '[data-i18n-switcher] [data-lang], [data-weline-choice-switcher="language"] [data-lang]'
        );
        return option ? String(option.getAttribute('data-lang') || '').trim() : '';
    }

    /**
     * 将语言代码转换为显示标识
     * zh_Hans_CN -> ZH, en_US -> EN, zh_Hant_TW -> TW
     */
    function getLangDisplay(langCode) {
        if (!langCode) {
            return 'ZH';
        }

        // 提取语言代码的主要部分
        const parts = langCode.split('_');
        if (parts.length >= 2) {
            // 取前两个部分，如 zh_Hans -> ZH, en_US -> EN
            const lang = parts[0].toUpperCase();
            const region = parts[1].toUpperCase();

            // 如果是中文，显示 ZH
            if (lang === 'ZH') {
                if (region === 'HANT') {
                    return 'TW'; // 繁体中文显示 TW
                }
                return 'ZH';
            }

            // 其他语言显示前两个字母
            return lang.substring(0, 2);
        }

        // 如果格式不对，返回前两个大写字母
        return langCode.substring(0, 2).toUpperCase();
    }

    function isIgnorableLanguageQueryParam(key) {
        const normalized = String(key || '').trim().toLowerCase();
        if (!normalized) {
            return false;
        }
        if (['_', 'ai_perf', 'fbclid', 'gbraid', 'gclid', 'igshid', 'mc_cid', 'mc_eid', 'msclkid', 'wbraid', 'yclid'].includes(normalized)) {
            return true;
        }
        return normalized.startsWith('utm_') || normalized.startsWith('mtm_') || normalized.startsWith('pk_');
    }

    function sanitizeLanguageSearch(search) {
        const raw = typeof search === 'string' ? search : '';
        if (!raw) {
            return '';
        }

        const params = new URLSearchParams(raw.charAt(0) === '?' ? raw.slice(1) : raw);
        Array.from(params.keys()).forEach(key => {
            if (isIgnorableLanguageQueryParam(key)) {
                params.delete(key);
            }
        });

        const query = params.toString();
        return query ? '?' + query : '';
    }

    function getHeaderLanguageCompactLabel(option, langCode) {
        if (!option) {
            return getLangDisplay(langCode);
        }

        const native = String(option.getAttribute('data-native') || '').trim();
        if (native && native.length <= 12) {
            return native;
        }

        const short = String(option.getAttribute('data-short') || '').trim();
        if (short) {
            return short;
        }

        return getLangDisplay(langCode);
    }

    /**
     * 更新当前语言显示
     */
    function updateCurrentLanguageDisplay() {
        const currentLang = getCurrentLang();
        const langDisplay = getLangDisplay(currentLang);

        const languageSwitchers = document.querySelectorAll('[data-i18n-switcher]');
        languageSwitchers.forEach(languageSwitcher => {
            let displayName = langDisplay;
            let activeOption = null;

            // 通过属性标记查找语言选项（优先使用 data-language-option）
            const languageOptions = languageSwitcher.querySelectorAll('[data-language-option], .language-option, a[data-lang]');
            languageOptions.forEach(option => {
                const langCode = option.getAttribute('data-lang') || option.dataset.lang;
                if (sameLang(langCode, currentLang)) {
                    activeOption = option;
                    option.classList.add('active');
                    displayName = getHeaderLanguageCompactLabel(option, langCode);
                } else {
                    option.classList.remove('active');
                }
            });

            const currentLangElements = languageSwitcher.querySelectorAll('.current-language');
            currentLangElements.forEach(el => {
                el.textContent = displayName;
            });

            if (activeOption) {
                const optionFlag = activeOption.querySelector('.weline-choice-flag');
                const currentFlag = languageSwitcher.querySelector('.weline-choice-current-flag');
                if (optionFlag && currentFlag) {
                    currentFlag.innerHTML = optionFlag.innerHTML;
                }
            }
        });
    }

    /**
     * 基于当前 URL 安全构建语言切换链接，避免重复注入 backend/currency/lang 段
     */
    function isBackendLocalizedPath(pathParts, backendKey) {
        if (backendKey) {
            return true;
        }
        const config = (window.Weline && window.Weline.config) || window.__WelineThemeConfig || {};
        if (config.area === 'backend' || (config.theme && config.theme.area === 'backend')) {
            return true;
        }
        return document.documentElement
            && document.documentElement.getAttribute('data-theme') === 'backend'
            && pathParts.length > 0;
    }

    function normalizeCurrencyCode(value) {
        return String(value || '').trim().toUpperCase();
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
        const codes = Object.create(null);
        const site = window.site || {};
        [
            config.availableCurrencies,
            config.supportedCurrencies,
            config.currencyCodes,
            config.currencies,
            config.site && config.site.availableCurrencies,
            config.site && config.site.supportedCurrencies,
            config.site && config.site.currencyCodes,
            config.site && config.site.currencies,
            site.availableCurrencies,
            site.supportedCurrencies,
            site.currencyCodes,
            site.currencies
        ].forEach(source => collectSupportedCurrencyCodes(codes, source));

        document.querySelectorAll('[data-currency-switcher] [data-currency], [data-currency-switcher] [data-currency-option], [data-currency-switcher] .currency-option').forEach(option => {
            addSupportedCurrencyCode(codes, option.getAttribute('data-currency') || option.getAttribute('data-currency-option') || option.dataset.currency);
        });

        addSupportedCurrencyCode(codes, config.defaultCurrency || (config.site && (config.site.defaultCurrency || config.site.default_currency)) || site.defaultCurrency || site.default_currency);
        return codes;
    }

    function isSupportedCurrencyCode(value, config) {
        const code = normalizeCurrencyCode(value);
        if (!isCurrencyCodeShape(code)) {
            return false;
        }
        return !!getSupportedCurrencyCodes(config || {})[code];
    }

    function normalizeLangCode(value) {
        return String(value || '').trim().replace(/-/g, '_');
    }

    function sameLang(a, b) {
        const left = normalizeLangCode(a).toLowerCase();
        const right = normalizeLangCode(b).toLowerCase();
        return left !== '' && right !== '' && left === right;
    }

    function shouldOutputCurrency(currency, config) {
        currency = normalizeCurrencyCode(currency);
        const defaultCurrency = normalizeCurrencyCode(config.defaultCurrency || 'CNY');
        return isSupportedCurrencyCode(currency, config) && currency !== defaultCurrency;
    }

    function shouldOutputLang(lang, config) {
        lang = normalizeLangCode(lang);
        const defaultLang = normalizeLangCode(config.defaultLang || config.defaultLanguage || config.i18n?.defaultLang || config.i18n?.defaultLanguage || 'zh_Hans_CN');
        return lang !== '' && !sameLang(lang, defaultLang);
    }

    function resolveWebsiteMountPath() {
        const fromDom = (function () {
            const node = document.querySelector('[data-i18n-switcher][data-website-mount]');
            return node ? String(node.getAttribute('data-website-mount') || '').trim() : '';
        })();
        const raw = String(fromDom || readCookieValue('WELINE_WEBSITE_URL') || '').trim();
        if (!raw) {
            return '';
        }
        let path = '';
        try {
            if (/^https?:\/\//i.test(raw)) {
                path = new URL(raw).pathname || '';
            } else if (raw.charAt(0) === '/') {
                path = raw.split('?')[0];
            } else {
                // bare mount segment from data-website-mount
                path = '/' + raw.replace(/^\/+|\/+$/g, '');
            }
        } catch (error) {
            path = '';
        }
        path = '/' + String(path || '').split('/').filter(Boolean).join('/');
        return path === '/' ? '' : path.replace(/\/+$/, '');
    }

    function peelWebsiteMountPrefix(pathname, mountPath) {
        const path = String(pathname || '/').split('?')[0] || '/';
        const mount = String(mountPath || '').replace(/\/+$/, '');
        if (!mount || mount === '/') {
            return path.charAt(0) === '/' ? path : ('/' + path);
        }
        const lower = path.toLowerCase();
        const mountLower = mount.toLowerCase();
        if (lower === mountLower) {
            return '/';
        }
        if (lower.indexOf(mountLower + '/') === 0) {
            const peeled = path.slice(mount.length) || '/';
            return peeled.charAt(0) === '/' ? peeled : ('/' + peeled);
        }
        return path.charAt(0) === '/' ? path : ('/' + path);
    }

    function buildLanguageUrl(targetLang, pathname, search, fallbackCurrency) {
        if (!targetLang) {
            return '';
        }

        const mountPath = resolveWebsiteMountPath();
        const safePathname = peelWebsiteMountPrefix(
            (pathname || window.location.pathname || '/').split('?')[0],
            mountPath
        );
        const safeSearch = sanitizeLanguageSearch(typeof search === 'string' ? search : (window.location.search || ''));
        const pathParts = safePathname.split('/').filter(Boolean);
        const langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
        const config = (window.Weline && window.Weline.config) || window.__WelineThemeConfig || {};
        const mountSegment = mountPath ? mountPath.replace(/^\/+|\/+$/g, '') : '';
        const mountLower = mountSegment ? mountSegment.toLowerCase() : '';
        const backendKey = String(
            (window.site && window.site.area)
            || (window.Weline && window.Weline.config && window.Weline.config.url && window.Weline.config.url.adminArea)
            || ''
        );
        let currency = '';
        for (const part of pathParts) {
            if (isSupportedCurrencyCode(part, config)) {
                currency = part.toUpperCase();
                break;
            }
        }
        if (!currency) {
            currency = normalizeCurrencyCode(fallbackCurrency || config.currentCurrency || 'CNY');
        }

        // Relative route only: website mount is already peeled as a fixed base.
        const isInternalPageBuilderPath = (() => {
            const joined = pathParts
                .filter((part) => !langPattern.test(part) && !isSupportedCurrencyCode(part, config)
                    && !(mountLower && String(part).toLowerCase() === mountLower))
                .join('/')
                .toLowerCase();
            return joined === 'pagebuilder/frontend/page'
                || joined.startsWith('pagebuilder/frontend/page/');
        })();
        const filteredParts = [];

        let prefixIndex = -1;
        if (backendKey) {
            prefixIndex = pathParts.findIndex(part => !langPattern.test(part)
                && !isSupportedCurrencyCode(part, config)
                && String(part).toLowerCase() === backendKey.toLowerCase());
        }
        const prefixSegment = prefixIndex >= 0 ? pathParts[prefixIndex] : '';

        if (!isInternalPageBuilderPath) {
            pathParts.forEach((part, index) => {
                if (langPattern.test(part) || isSupportedCurrencyCode(part, config)) {
                    return;
                }
                if (index === prefixIndex) {
                    return;
                }
                if (mountLower && String(part).toLowerCase() === mountLower) {
                    return;
                }
                filteredParts.push(part);
            });
        }

        const outputParts = [];
        if (prefixSegment) {
            outputParts.push(prefixSegment);
        }

        if (!prefixSegment && !mountPath && isBackendLocalizedPath(pathParts, backendKey) && filteredParts.length > 0) {
            const inferredPrefix = filteredParts.shift();
            outputParts.push(inferredPrefix);
        }

        if (shouldOutputCurrency(currency, config)) {
            outputParts.push(normalizeCurrencyCode(currency));
        }
        const onBackendPath = prefixSegment !== '';
        if (onBackendPath) {
            const backendLang = normalizeLangCode(targetLang);
            if (backendLang) {
                outputParts.push(backendLang);
            }
        } else if (shouldOutputLang(targetLang, config)) {
            outputParts.push(normalizeLangCode(targetLang));
        }
        if (filteredParts.length > 0) {
            outputParts.push(...filteredParts);
        }

        const relativePath = outputParts.length ? ('/' + outputParts.join('/')) : '/';
        if (prefixSegment || !mountPath) {
            return relativePath + (safeSearch || '');
        }
        // Re-attach fixed website base outside segment splitting.
        const withMount = relativePath === '/'
            ? (mountPath + '/')
            : (mountPath + relativePath);
        return withMount + (safeSearch || '');
    }

    /**
     * 更新语言切换器链接
     * 通过 data-i18n-switcher 属性标记来查找语言切换器元素
     */
    function updateLanguageSwitcherLinks() {
        // 通过属性标记查找语言切换器（支持多个）
        const languageSwitchers = document.querySelectorAll('[data-i18n-switcher]');
        if (languageSwitchers.length === 0) {
            // 找不到属性标记，静默返回（不报错）
            if (window.DEV) {
                console.log('[WelineI18n] 未找到语言切换器元素 [data-i18n-switcher]');
            }
            return;
        }

        // 遍历所有找到的语言切换器
        languageSwitchers.forEach(languageSwitcher => {
            // 通过属性标记查找语言选项（优先使用 data-language-option）
            const languageOptions = languageSwitcher.querySelectorAll('[data-language-option], .language-option, a[data-lang]');
            if (languageOptions.length === 0) {
                if (window.DEV) {
                    console.log('[WelineI18n] 在语言切换器中未找到语言选项');
                }
                return;
            }

            // 获取当前 URL 和语言配置
            const pathname = window.location.pathname || '/';
            const search = window.location.search || '';
            const currentPath = pathname + search;
            const config = window.__WelineThemeConfig || {};

            // 获取当前货币（用于保持货币）
            let currentCurrency = '';
            const pathParts = currentPath.split('?')[0].split('/').filter(Boolean);
            for (const part of pathParts) {
                if (isSupportedCurrencyCode(part, config)) {
                    currentCurrency = part;
                    break;
                }
            }
            if (!currentCurrency) {
                currentCurrency = (config.currentCurrency || 'CNY').toUpperCase();
            }

            languageOptions.forEach(option => {
                const langCode = option.getAttribute('data-lang') || option.dataset.lang;
                if (!langCode) {
                    return;
                }
                if (option.getAttribute('data-i18n-authoritative-href') === '1') {
                    return;
                }

                const langUrl = buildLanguageUrl(langCode, pathname, search, currentCurrency);

                if (langUrl) {
                    option.setAttribute('href', langUrl);
                    if (window.DEV) {
                        console.log(`[WelineI18n] 更新语言选项链接: ${langCode} -> ${langUrl}`);
                    }
                }
            });
        });

        // 更新当前语言显示
        updateCurrentLanguageDisplay();
    }

    /**
     * 切换语言（会自动保持当前货币）
     * @param {string} lang 语言代码
     * @returns {Promise<void>}
     */
    async function switchLang(lang, authoritativeHref = '') {
        if (!lang) {
            console.warn('[WelineI18n] switchLang: 语言代码不能为空');
            return;
        }
        // Guard against double-fired handlers (header+footer switchers / raced
        // click rewriters) cancelling the in-flight navigation into a blank doc.
        if (window.__WelineI18nNavigating) {
            return;
        }

        // 服务端渲染的 href 是权威结果；无 href 的旧编程调用才使用兼容重建。
        const configuredHref = typeof authoritativeHref === 'string' ? authoritativeHref.trim() : '';
        const config = window.__WelineThemeConfig || {};
        const langUrl = configuredHref || buildLanguageUrl(
            lang,
            window.location.pathname || '/',
            window.location.search || '',
            (config.currentCurrency || 'CNY').toUpperCase()
        );

        // 保存语言偏好
        writeLanguagePreference(lang);
        window.__WelineI18nNavigating = true;
        try {
            sessionStorage.setItem('__weline_i18n_recover', '1');
        } catch (error) {
            // sessionStorage can be unavailable in privacy modes.
        }

        // Defer navigation out of the click stack. Synchronous location changes
        // during <a data-lang> activation can race the cancelled default action
        // and leave Chromium with an empty document (200 + decodedBodySize 0)
        // until a manual reload. setTimeout(0) keeps a single completed navigate.
        const navigate = () => {
            // 前台切到默认语言时 URL 往往不含语言段，与当前 pathname+search 相同；
            // 仅赋值 location.href 不会触发导航，必须强制 reload。
            // 后台 buildLanguageUrl 始终带语言段，走下方 href（路由语言段最高优先）。
            try {
                const target = new URL(langUrl, window.location.origin);
                const samePath = target.pathname === (window.location.pathname || '/')
                    && target.search === (window.location.search || '')
                    && target.hash === (window.location.hash || '');
                if (samePath) {
                    window.location.reload();
                    return;
                }
                // Absolute replace avoids relative-assign races in Electron webviews.
                window.location.replace(target.href);
            } catch (e) {
                window.location.assign(langUrl);
            }
        };
        setTimeout(navigate, 0);
    }

    /**
     * Recover from Chromium cancelled navigations that leave an empty document
     * after language switching (responseStatus 200 + decodedBodySize 0).
     */
    function installBlankDocumentRecovery() {
        if (window.__WelineI18nBlankRecovery) {
            return;
        }
        window.__WelineI18nBlankRecovery = true;
        window.addEventListener('pageshow', function () {
            try {
                if (sessionStorage.getItem('__weline_i18n_recover') !== '1') {
                    return;
                }
                sessionStorage.removeItem('__weline_i18n_recover');
                const htmlLen = document.documentElement
                    ? document.documentElement.outerHTML.length
                    : 0;
                const bodyLen = document.body ? document.body.innerHTML.length : 0;
                if (htmlLen < 200 || bodyLen < 20) {
                    window.location.reload();
                }
            } catch (error) {
                // ignore recovery failures
            }
        });
    }

    /**
     * 初始化语言切换器
     */
    function initLanguageSwitcher() {
        // 确保 DOM 完全加载后再执行
        function initAfterDOMReady() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    // DOM 加载完成后，再等待一小段时间确保所有元素都已渲染
                    setTimeout(updateLanguageSwitcherLinks, 100);
                });
            } else {
                // DOM 已加载，等待一小段时间确保所有元素都已渲染
                setTimeout(updateLanguageSwitcherLinks, 100);
            }
        }

        initAfterDOMReady();

        // 监听 URL 变化（用于浏览器前进/后退）
        let lastUrl = window.location.href;
        setInterval(() => {
            if (window.location.href !== lastUrl) {
                lastUrl = window.location.href;
                updateCurrentLanguageDisplay();
            }
        }, 500);
    }

    /**
     * URL 解析辅助函数
     */
    function resolveApiUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) {
            return path;
        }

        // 尝试使用 Weline.Url.resolve
        if (window.Weline && window.Weline.Url && typeof window.Weline.Url.resolve === 'function') {
            try {
                return window.Weline.Url.resolve(path, { type: 'frontend' });
            } catch (e) {
                // 如果解析失败，使用原始路径
            }
        }

        // Fallback: 直接返回绝对路径
        const normalizedOrigin = window.location.origin.replace(/\/+$/, '');
        const cleanPath = path.startsWith('/') ? path.slice(1) : path;
        return normalizedOrigin + '/' + cleanPath;
    }

    /**
     * 检测当前区域（frontend 或 backend）
     */
    function detectArea() {
        const path = window.location.pathname || '';
        if (path.indexOf('/admin') === 0 || path.indexOf('/backend') === 0) {
            return 'backend';
        }
        return 'frontend';
    }

    /**
     * 初始化 i18n 对象（包含翻译字典、API URL 等）
     */
    function initI18nObject() {
        // 从全局配置读取 i18n 配置
        const config = window.__WelineThemeConfig || {};
        const i18nConfig = config.i18n || {};
        const currentLang = config.currentLang || i18nConfig.currentLang || getCurrentLang() || 'zh_Hans_CN';

        // 默认 API URL（根据区域自动选择）
        const area = detectArea();
        const defaultApiUrl = `/i18n/${area}/word/get-translations`;
        const apiUrl = resolveApiUrl(i18nConfig.apiUrl || defaultApiUrl);

        // 初始化字典
        let dictionary = i18nConfig.dictionary || {};

        // 如果字典为空，尝试从多个来源读取
        if (Object.keys(dictionary).length === 0) {
            // 1. 从 window.site.i18n 读取（footer hook 设置的）
            if (window.site && window.site.i18n && typeof window.site.i18n === 'object' && Object.keys(window.site.i18n).length > 0) {
                dictionary = window.site.i18n;
            }
            // 2. 从 window.__WelineI18nDictionary 读取（如果存在）
            else if (window.__WelineI18nDictionary && typeof window.__WelineI18nDictionary === 'object' && Object.keys(window.__WelineI18nDictionary).length > 0) {
                dictionary = window.__WelineI18nDictionary;
                delete window.__WelineI18nDictionary; // 清理临时变量
            }
        }

        const i18nObj = {
            currentLang: currentLang,
            dictionary: dictionary,
            apiUrl: apiUrl,

            setDictionary: (dict) => {
                i18nObj.dictionary = dict || {};
            },

            translate: (key, params = {}) => {
                let text = i18nObj.dictionary[key] || key;
                Object.keys(params).forEach(paramKey => {
                    text = text.replace(new RegExp(`%{${paramKey}}`, 'g'), params[paramKey]);
                });
                return text;
            },

            // 按需加载翻译词（如果字典为空）
            loadDictionary: async function () {
                if (Object.keys(this.dictionary).length > 0) {
                    return this.dictionary;
                }

                try {
                    if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                        console.warn('[WelineI18n] Weline.Api is not ready; skip frontend worker dictionary load.');
                        return this.dictionary;
                    }

                    const wordKeys = Object.keys(window.site && window.site.i18n ? window.site.i18n : {});
                    const I18nApi = await window.Weline.Api.resource('i18n');
                    const result = await I18nApi.getTranslations({words: wordKeys}, {silent: true});
                    const data = result && result.data ? result.data : result;
                    if (data && data.dictionary) {
                        this.dictionary = data.dictionary || {};
                        return this.dictionary;
                    }
                } catch (error) {
                    console.warn('[WelineI18n] 加载翻译字典失败:', error);
                }

                return this.dictionary;
            },
        };

        return i18nObj;
    }

    // 初始化 i18n 对象
    const i18nObj = initI18nObject();

    // 导出模块 API
    window.WelineI18n = {
        __initialized: true,
        getCurrentLang: getCurrentLang,
        getLangDisplay: getLangDisplay,
        updateCurrentLanguageDisplay: updateCurrentLanguageDisplay,
        updateLanguageSwitcherLinks: updateLanguageSwitcherLinks,
        switchLang: switchLang,
        writeLanguagePreference: writeLanguagePreference,
        buildLanguageUrl: buildLanguageUrl,
        // 翻译相关 API
        currentLang: i18nObj.currentLang,
        dictionary: i18nObj.dictionary,
        apiUrl: i18nObj.apiUrl,
        setDictionary: i18nObj.setDictionary,
        translate: i18nObj.translate,
        loadDictionary: i18nObj.loadDictionary,
        init: initLanguageSwitcher
    };

    // 自动初始化
    installBlankDocumentRecovery();
    initLanguageSwitcher();

})(window, document);
