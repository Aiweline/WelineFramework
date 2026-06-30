function setCookie(key, value, expiry=7, options = {}) {
    let expires = new Date();
    expires.setTime(expires.getTime() + (expiry * 24 * 60 * 60 * 1000));
    let cookie_string = key + '=' + value + ';expires=' + expires.toUTCString();
    for (let option_key in options) {
        cookie_string += ';' + option_key + '=' + options[option_key];
    }
    document.cookie = cookie_string;
}

function getCookie(key) {
    let keyValue = document.cookie.match('(^|;) ?' + key + '=([^;]*)(;|$)');
    return keyValue ? keyValue[2] : null;
}

function removeCookie(key) {
    let keyValue = getCookie(key);
    setCookie(key, keyValue, '-1');
}

(function () {
    if (window.__WelineBackendLanguageCookieSync) {
        return;
    }
    window.__WelineBackendLanguageCookieSync = true;

    function addPath(paths, path) {
        path = path || '/';
        if (path.charAt(0) !== '/') {
            path = '/' + path;
        }
        if (paths.indexOf(path) < 0) {
            paths.push(path);
        }
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
        var code = normalizeCurrencyCode(value);
        if (isCurrencyCodeShape(code)) {
            codes[code] = true;
        }
    }

    function collectSupportedCurrencyCodes(codes, source) {
        if (!source) {
            return;
        }
        if (Array.isArray(source)) {
            source.forEach(function (item) {
                addSupportedCurrencyCode(codes, item);
            });
            return;
        }
        if (typeof source === 'object') {
            Object.keys(source).forEach(function (key) {
                addSupportedCurrencyCode(codes, key);
                addSupportedCurrencyCode(codes, source[key]);
            });
            return;
        }
        String(source).split(/[,\s|]+/).forEach(function (code) {
            addSupportedCurrencyCode(codes, code);
        });
    }

    function getSupportedCurrencyCodes() {
        var config = (window.Weline && window.Weline.config) || window.__WelineThemeConfig || {};
        var site = window.site || {};
        var codes = Object.create(null);
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
        ].forEach(function (source) {
            collectSupportedCurrencyCodes(codes, source);
        });
        addSupportedCurrencyCode(codes, config.defaultCurrency || (config.site && (config.site.defaultCurrency || config.site.default_currency)) || site.defaultCurrency || site.default_currency);
        return codes;
    }

    function isSupportedCurrencyCode(value) {
        var code = normalizeCurrencyCode(value);
        if (!isCurrencyCodeShape(code)) {
            return false;
        }
        return !!getSupportedCurrencyCodes()[code];
    }

    function collectLangCookiePaths(link) {
        var paths = ['/'];
        var langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
        var parts = String(window.location.pathname || '/').split('/').filter(Boolean);
        var backendKey = String((window.site && window.site.area) || '').replace(/^\/+|\/+$/g, '');
        var first = parts[0] || '';

        if (backendKey) {
            addPath(paths, '/' + backendKey);
        }
        if (first && !langPattern.test(first) && !isSupportedCurrencyCode(first)) {
            addPath(paths, '/' + first);
        }

        var currencyIndex = -1;
        var langIndex = -1;
        for (var i = 0; i < parts.length; i++) {
            if (currencyIndex < 0 && isSupportedCurrencyCode(parts[i])) {
                currencyIndex = i;
            }
            if (langIndex < 0 && langPattern.test(parts[i])) {
                langIndex = i;
            }
        }
        if (currencyIndex > 0) {
            addPath(paths, '/' + parts.slice(0, currencyIndex + 1).join('/'));
        }
        if (langIndex > 0) {
            addPath(paths, '/' + parts.slice(0, langIndex + 1).join('/'));
        }

        try {
            if (link && link.href) {
                var targetParts = new URL(link.href, window.location.href).pathname.split('/').filter(Boolean);
                for (var j = 0; j < targetParts.length; j++) {
                    if (isSupportedCurrencyCode(targetParts[j]) || langPattern.test(targetParts[j])) {
                        addPath(paths, '/' + targetParts.slice(0, j + 1).join('/'));
                    }
                }
            }
        } catch (e) {
        }

        return paths;
    }

    function writeBackendLanguagePreference(lang, link) {
        if (!lang) {
            return;
        }
        try {
            if (window.localStorage) {
                localStorage.setItem('weline_user_lang', lang);
                localStorage.removeItem('api_doc_locale');
                localStorage.removeItem('WELINE_USER_LANG');
            }
        } catch (e) {
        }

        var value = encodeURIComponent(lang);
        var expires = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toUTCString();
        var expired = 'Thu, 01 Jan 1970 00:00:00 GMT';
        var host = window.location.hostname || '';
        var domains = [''];
        if (host.indexOf('.') > 0 && !/^\d+\.\d+\.\d+\.\d+$/.test(host)) {
            domains.push(';domain=' + host);
        }

        collectLangCookiePaths(link).forEach(function (path) {
            domains.forEach(function (domain) {
                document.cookie = 'WELINE_USER_LANG=;expires=' + expired + ';path=' + path + domain + ';SameSite=Lax';
                document.cookie = 'WELINE_USER_LANG=' + value + ';expires=' + expires + ';path=' + path + domain + ';SameSite=Lax';
            });
        });
    }

    window.WelineBackendLanguageCookieSync = writeBackendLanguagePreference;
    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest ? event.target.closest('[data-language-option][data-lang]') : null;
        if (!target) {
            return;
        }
        writeBackendLanguagePreference(target.getAttribute('data-lang') || '', target);
    }, true);
})();
