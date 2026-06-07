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

    function collectLangCookiePaths(link) {
        var paths = ['/'];
        var langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
        var currencyPattern = /^[A-Z]{3}$/;
        var parts = String(window.location.pathname || '/').split('/').filter(Boolean);
        var backendKey = String((window.site && window.site.area) || '').replace(/^\/+|\/+$/g, '');
        var first = parts[0] || '';

        if (backendKey) {
            addPath(paths, '/' + backendKey);
        }
        if (first && !langPattern.test(first) && !currencyPattern.test(first)) {
            addPath(paths, '/' + first);
        }

        var currencyIndex = -1;
        var langIndex = -1;
        for (var i = 0; i < parts.length; i++) {
            if (currencyIndex < 0 && currencyPattern.test(parts[i])) {
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
                    if (currencyPattern.test(targetParts[j]) || langPattern.test(targetParts[j])) {
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
