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