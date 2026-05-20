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

    /**
     * 获取当前语言代码
     */
    function getCurrentLang() {
        // 从 Cookie 获取
        if (typeof window.getCookie === 'function') {
            const cookieLang = window.getCookie('WELINE_USER_LANG');
            if (cookieLang) {
                return cookieLang;
            }
        }

        // 从 URL 参数获取
        const urlParams = new URLSearchParams(window.location.search);
        const urlLang = urlParams.get('lang');
        if (urlLang) {
            return urlLang;
        }

        // 从 URL 路径获取（如 /zh_Hans_CN/...）
        const pathParts = window.location.pathname.split('/').filter(Boolean);
        for (const part of pathParts) {
            if (/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(part)) {
                return part;
            }
        }

        // 从配置获取
        const config = window.__WelineThemeConfig || {};
        return config.currentLang || config.i18n?.currentLang || (window.site && window.site.lang) || 'zh_Hans_CN';
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

    /**
     * 更新当前语言显示
     */
    function updateCurrentLanguageDisplay() {
        const currentLang = getCurrentLang();
        const langDisplay = getLangDisplay(currentLang);

        // 更新 current-language 元素
        const currentLangElements = document.querySelectorAll('.current-language');
        currentLangElements.forEach(el => {
            el.textContent = langDisplay;
        });

        // 更新 active 状态（通过属性标记查找）
        const languageSwitchers = document.querySelectorAll('[data-i18n-switcher]');
        languageSwitchers.forEach(languageSwitcher => {
            // 通过属性标记查找语言选项（优先使用 data-language-option）
            const languageOptions = languageSwitcher.querySelectorAll('[data-language-option], .language-option, a[data-lang]');
            languageOptions.forEach(option => {
                const langCode = option.getAttribute('data-lang') || option.dataset.lang;
                if (langCode === currentLang) {
                    option.classList.add('active');
                } else {
                    option.classList.remove('active');
                }
            });
        });
    }

    /**
     * 基于当前 URL 安全构建语言切换链接，避免重复注入 backend/currency/lang 段
     */
    function buildLanguageUrl(targetLang, pathname, search, fallbackCurrency) {
        if (!targetLang) {
            return '';
        }

        const safePathname = (pathname || window.location.pathname || '/').split('?')[0];
        const safeSearch = sanitizeLanguageSearch(typeof search === 'string' ? search : (window.location.search || ''));
        const pathParts = safePathname.split('/').filter(Boolean);
        if (pathParts.length === 0) {
            const currency = (fallbackCurrency || 'CNY').toUpperCase();
            return '/' + currency + '/' + targetLang + (safeSearch || '');
        }

        const langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
        const currencyPattern = /^[A-Z]{3}$/;
        const backendKey = String(
            (window.site && window.site.area)
            || (window.Weline && window.Weline.config && window.Weline.config.url && window.Weline.config.url.adminArea)
            || ''
        );
        let currency = '';
        for (const part of pathParts) {
            if (currencyPattern.test(part)) {
                currency = part.toUpperCase();
                break;
            }
        }
        if (!currency) {
            currency = (fallbackCurrency || 'CNY').toUpperCase();
        }

        // 仅保留非货币/语言段，原有大小写保持不变
        const filteredParts = [];

        // 前缀优先使用 backend key（即使它不在首段），否则退回首段非货币/语言段
        let prefixIndex = -1;
        if (backendKey) {
            prefixIndex = pathParts.findIndex(part => !langPattern.test(part)
                && !currencyPattern.test(part)
                && String(part).toLowerCase() === backendKey.toLowerCase());
        }
        if (prefixIndex < 0) {
            prefixIndex = pathParts.findIndex(part => !langPattern.test(part) && !currencyPattern.test(part));
        }
        const prefixSegment = prefixIndex >= 0 ? pathParts[prefixIndex] : '';

        pathParts.forEach((part, index) => {
            if (langPattern.test(part) || currencyPattern.test(part)) {
                return;
            }
            if (index === prefixIndex) {
                return;
            }
            filteredParts.push(part);
        });

        if (prefixSegment) {
            return '/' + prefixSegment + '/' + currency + '/' + targetLang +
                (filteredParts.length ? '/' + filteredParts.join('/') : '') +
                (safeSearch || '');
        }

        const cleanPath = filteredParts.length ? '/' + filteredParts.join('/') : '';
        return '/' + currency + '/' + targetLang + cleanPath + (safeSearch || '');
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
                if (/^[A-Z]{3}$/.test(part)) {
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
    async function switchLang(lang) {
        if (!lang) {
            console.warn('[WelineI18n] switchLang: 语言代码不能为空');
            return;
        }

        // 统一走 i18n 自身的路径重建，避免多处 URL 语义互相覆盖
        const config = window.__WelineThemeConfig || {};
        const langUrl = buildLanguageUrl(
            lang,
            window.location.pathname || '/',
            window.location.search || '',
            (config.currentCurrency || 'CNY').toUpperCase()
        );

        // 保存语言偏好
        localStorage.setItem('weline_user_lang', lang);
        if (typeof window.setCookie === 'function') {
            window.setCookie('WELINE_USER_LANG', lang, 365);
        }

        // 立即跳转到新 URL
        window.location.href = langUrl;
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
    initLanguageSwitcher();

})(window, document);
