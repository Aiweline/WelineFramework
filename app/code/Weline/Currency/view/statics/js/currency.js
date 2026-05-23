/**
 * Weline 货币切换器模块
 * 
 * 功能：
 * 1. 自动初始化货币切换器
 * 2. 更新货币切换链接
 * 3. 监听货币切换事件
 * 4. 更新当前货币显示
 */
(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.WelineCurrency && window.WelineCurrency.__initialized) {
        return;
    }

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

    /**
     * 获取当前货币代码
     */
    function getCurrentCurrency() {
        const cookieCurrency = readCookieValue('WELINE_USER_CURRENCY');
        if (cookieCurrency) {
            return cookieCurrency.toUpperCase();
        }

        // 从 URL 参数获取
        const urlParams = new URLSearchParams(window.location.search);
        const urlCurrency = urlParams.get('currency');
        if (urlCurrency) {
            return urlCurrency.toUpperCase();
        }

        // 从 URL 路径获取（如 /CNY/...）
        const pathParts = window.location.pathname.split('/').filter(Boolean);
        for (const part of pathParts) {
            if (/^[A-Z]{3}$/.test(part)) {
                return part.toUpperCase();
            }
        }

        // 从配置获取
        const config = window.__WelineThemeConfig || {};
        return (config.currentCurrency || (window.site && window.site.currency) || 'CNY').toUpperCase();
    }

    /**
     * 更新当前货币显示
     */
    function updateCurrentCurrencyDisplay() {
        const currentCurrency = getCurrentCurrency();

        // 更新 current-currency 元素
        const currentCurrencyElements = document.querySelectorAll('.current-currency');
        currentCurrencyElements.forEach(el => {
            el.textContent = currentCurrency;
        });

        // 更新 active 状态（通过属性标记查找）
        const currencySwitchers = document.querySelectorAll('[data-currency-switcher]');
        currencySwitchers.forEach(currencySwitcher => {
            // 通过属性标记查找货币选项（优先使用 data-currency-option）
            const currencyOptions = currencySwitcher.querySelectorAll('[data-currency-option], .currency-option, a[data-currency]');
            currencyOptions.forEach(option => {
                const currencyCode = (option.getAttribute('data-currency') || option.dataset.currency || '').toUpperCase();
                if (currencyCode === currentCurrency) {
                    option.classList.add('active');
                } else {
                    option.classList.remove('active');
                }
            });
        });
    }

    /**
     * 更新货币切换器链接
     * 通过 data-currency-switcher 属性标记来查找货币切换器元素
     */
    function updateCurrencySwitcherLinks() {
        // 通过属性标记查找货币切换器（支持多个）
        const currencySwitchers = document.querySelectorAll('[data-currency-switcher]');
        if (currencySwitchers.length === 0) {
            // 找不到属性标记，静默返回（不报错）
            return;
        }

        // 遍历所有找到的货币切换器
        currencySwitchers.forEach(currencySwitcher => {
            // 通过属性标记查找货币选项（优先使用 data-currency-option）
            const currencyOptions = currencySwitcher.querySelectorAll('[data-currency-option], .currency-option, a[data-currency]');
            if (currencyOptions.length === 0) {
                return;
            }

            // 获取当前 URL 和货币配置
            const currentPath = window.location.pathname + sanitizeSwitchSearch(window.location.search || '');
            const config = window.__WelineThemeConfig || {};
            const availableCurrencies = config.availableCurrencies || [
                { code: 'CNY', name: 'CNY (¥)' },
                { code: 'USD', name: 'USD ($)' },
                { code: 'EUR', name: 'EUR (€)' },
                { code: 'GBP', name: 'GBP (£)' }
            ];

            currencyOptions.forEach(option => {
                const currencyCode = (option.getAttribute('data-currency') || option.dataset.currency || '').toUpperCase();
                if (!currencyCode) {
                    return;
                }

                // 使用 window.urlWithCurrency 生成带货币的 URL（框架推荐的路径格式，会自动保持语言）
                if (typeof window.urlWithCurrency === 'function') {
                    const currencyUrl = window.urlWithCurrency(currentPath, currencyCode);
                    option.setAttribute('href', currencyUrl);
                } else {
                    // 降级方案：手动构建路径格式的 URL（需要手动保持语言）
                    const pathOnly = currentPath.split('?')[0];
                    const search = currentPath.includes('?') ? currentPath.split('?')[1] : '';

                    // 获取当前语言（从 URL 或配置）
                    let currentLang = '';
                    const pathParts = pathOnly.split('/').filter(Boolean);
                    for (const part of pathParts) {
                        if (/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(part)) {
                            currentLang = part;
                            break;
                        }
                    }
                    if (!currentLang) {
                        currentLang = config.currentLang || config.i18n?.currentLang || 'zh_Hans_CN';
                    }

                    // 移除路径中的货币代码和语言代码
                    const currencyPattern = /^[A-Z]{3}$/;
                    const langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
                    const filteredParts = pathParts.filter(part => !currencyPattern.test(part) && !langPattern.test(part));
                    const cleanPath = '/' + filteredParts.join('/');

                    // 构建新 URL：/[currency]/[lang]/path（保持语言）
                    const currencyUrl = '/' + currencyCode + '/' + currentLang + cleanPath + (search ? '?' + search : '');
                    option.setAttribute('href', currencyUrl);
                }
            });
        });

        // 更新当前货币显示
        updateCurrentCurrencyDisplay();
    }

    /**
     * 切换货币（会自动保持当前语言）
     * @param {string} currency 货币代码
     * @returns {Promise<void>}
     */
    async function switchCurrency(currency) {
        if (!currency) {
            console.warn('[WelineCurrency] switchCurrency: 货币代码不能为空');
            return;
        }

        currency = currency.toUpperCase();

        // 优先使用 select_currency 函数（来自 url-frontend 模块，会自动保持语言）
        if (typeof window.select_currency === 'function') {
            window.select_currency(currency);
            return;
        }

        // 次优先使用 urlWithCurrency 函数（来自 url-frontend 模块，会自动保持语言）
        if (typeof window.urlWithCurrency === 'function') {
            const currentPath = window.location.pathname + sanitizeSwitchSearch(window.location.search || '');
            const currencyUrl = window.urlWithCurrency(currentPath, currency);

            // 保存货币偏好到 localStorage
            localStorage.setItem('weline_user_currency', currency);

            // 保存货币偏好到 Cookie（如果 getCookie/setCookie 函数存在）
            writeCookieValue('WELINE_USER_CURRENCY', currency, 365); // 保存365天

            // 立即跳转到新 URL
            window.location.href = currencyUrl;
            return;
        }

        // 降级方案：使用 inject_path（会自动保持语言）
        if (typeof window.inject_path === 'function') {
            const currencyUrl = window.inject_path(window.location.pathname, currency, 'currency') + sanitizeSwitchSearch(window.location.search || '');

            // 保存货币偏好
            localStorage.setItem('weline_user_currency', currency);
            writeCookieValue('WELINE_USER_CURRENCY', currency, 365);

            // 立即跳转到新 URL
            window.location.href = currencyUrl;
            return;
        }

        // 最后的降级方案：手动构建路径格式的 URL（需要手动保持语言）
        let currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(Boolean);

        // 获取当前语言（从 URL 或配置）
        let currentLang = '';
        for (const part of pathParts) {
            if (/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(part)) {
                currentLang = part;
                break;
            }
        }
        if (!currentLang) {
            const config = window.__WelineThemeConfig || {};
            currentLang = config.currentLang || config.i18n?.currentLang || 'zh_Hans_CN';
        }

        // 移除路径中的货币代码和语言代码
        const currencyPattern = /^[A-Z]{3}$/;
        const langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
        const filteredParts = pathParts.filter(part => !currencyPattern.test(part) && !langPattern.test(part));
        const cleanPath = '/' + filteredParts.join('/');

        // 构建新 URL：/[currency]/[lang]/path（保持语言）
        const currencyUrl = '/' + currency + '/' + currentLang + cleanPath + sanitizeSwitchSearch(window.location.search || '');

        // 保存货币偏好
        localStorage.setItem('weline_user_currency', currency);
        writeCookieValue('WELINE_USER_CURRENCY', currency, 365);

        // 立即跳转到新 URL
        window.location.href = currencyUrl;
    }

    /**
     * 初始化货币切换器
     */
    function initCurrencySwitcher() {
        // 等待 URL 模块加载（由 weline-modules 配置控制，不主动加载）
        let urlModuleRetryCount = 0;
        const MAX_URL_MODULE_RETRIES = 10; // 最多等待10次（给模块加载器时间）

        function waitForUrlModule() {
            // 如果 URL 函数已存在，直接使用
            if (typeof window.urlWithCurrency === 'function' || typeof window.url === 'function') {
                updateCurrencySwitcherLinks();
                return;
            }

            // 重试次数限制
            if (urlModuleRetryCount >= MAX_URL_MODULE_RETRIES) {
                // 使用降级方案
                setTimeout(updateCurrencySwitcherLinks, 100);
                return;
            }

            // 延迟重试（等待模块加载器从配置中加载模块）
            urlModuleRetryCount++;
            setTimeout(waitForUrlModule, 200);
        }

        // 等待 DOM 加载
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', waitForUrlModule);
        } else {
            setTimeout(waitForUrlModule, 0);
        }

        // 监听货币切换事件，立即切换URL并访问
        // 只处理带有 data-currency-switcher 标记的容器内的货币选项
        document.addEventListener('click', function (e) {
            // 检查点击的元素是否在带有 data-currency-switcher 标记的容器内
            const currencySwitcher = e.target.closest('[data-currency-switcher]');
            if (!currencySwitcher) {
                return;
            }

            // 通过属性标记查找货币选项（优先使用 data-currency-option）
            const currencyOption = e.target.closest('[data-currency-option], .currency-option, a[data-currency]');
            if (currencyOption) {
                const currencyCode = (currencyOption.getAttribute('data-currency') || currencyOption.dataset.currency || '').toUpperCase();
                if (currencyCode) {
                    // 阻止默认跳转行为
                    e.preventDefault();
                    e.stopPropagation();

                    // 立即切换货币（会自动保持当前语言）
                    // 优先使用 select_currency（来自 url-frontend 模块，会自动保持语言）
                    if (typeof window.select_currency === 'function') {
                        window.select_currency(currencyCode);
                        return;
                    }

                    // 次优先使用 switchCurrency（currency 模块的方法）
                    switchCurrency(currencyCode).catch(err => {
                        console.error('[WelineCurrency] 切换货币失败:', err);
                        // 如果切换失败，尝试使用 href 作为降级方案
                        const href = currencyOption.getAttribute('href');
                        if (href && href !== '#' && href !== '#!') {
                            window.location.href = href;
                        }
                    });
                }
            }
        });

        // 监听 URL 变化（用于浏览器前进/后退）
        let lastUrl = window.location.href;
        setInterval(() => {
            if (window.location.href !== lastUrl) {
                lastUrl = window.location.href;
                updateCurrentCurrencyDisplay();
            }
        }, 500);
    }

    // 导出模块 API
    window.WelineCurrency = {
        __initialized: true,
        getCurrentCurrency: getCurrentCurrency,
        updateCurrentCurrencyDisplay: updateCurrentCurrencyDisplay,
        updateCurrencySwitcherLinks: updateCurrencySwitcherLinks,
        switchCurrency: switchCurrency,
        init: initCurrencySwitcher
    };

    // 自动初始化
    initCurrencySwitcher();

})(window, document);
