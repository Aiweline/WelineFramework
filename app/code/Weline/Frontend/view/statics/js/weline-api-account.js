/**
 * Weline Account Module
 *
 * Storefront account requests must go through Weline.Api -> worker -> query-bin.
 * Browser External API token login is intentionally not implemented here.
 */
(function (window) {
    'use strict';

    const DEFAULT_KEYS = {
        apiTokenKey: 'weline_api_access_token',
        apiRefreshTokenKey: 'weline_api_refresh_token',
        backendApiTokenKey: 'weline_backend_api_token',
        backendApiRefreshTokenKey: 'weline_backend_api_refresh_token',
        apiUserKey: 'weline_api_user',
        backendApiUserKey: 'weline_backend_api_user',
    };

    function getGlobalConfig() {
        if (window.Weline && window.Weline.config) {
            return window.Weline.config;
        }
        if (window.__WelineThemeConfig) {
            return window.__WelineThemeConfig;
        }
        return {};
    }

    function getAccountConfig() {
        const config = getGlobalConfig();
        return Object.assign({}, DEFAULT_KEYS, config.account || {});
    }

    class AccountManager {
        constructor(config) {
            this.config = Object.assign({}, DEFAULT_KEYS, config || {});
            this.frontendUser = null;
            this.apiUser = this.readStoredUser(this.config.apiUserKey);
            this.backendApiUser = this.readStoredUser(this.config.backendApiUserKey);
            this.apiPromise = null;
        }

        async getAccountApi() {
            if (!this.apiPromise) {
                this.apiPromise = Promise.resolve().then(async () => {
                    if ((!window.Weline || !window.Weline.Api) && window.Weline && typeof window.Weline.load === 'function') {
                        await window.Weline.load('api');
                    }
                    if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                        throw new Error('Weline.Api is not ready.');
                    }
                    return window.Weline.Api.resource('account');
                });
            }
            return this.apiPromise;
        }

        async call(method, params) {
            const api = await this.getAccountApi();
            if (!api || typeof api[method] !== 'function') {
                throw new Error('Account API method is unavailable: ' + method);
            }
            return api[method](params || {}, { silent: true });
        }

        async checkFrontendUserLogin() {
            try {
                const result = await this.call('current');
                const loggedIn = !!(result && (result.isLogin || result.logged_in || result.success));
                this.frontendUser = loggedIn ? (result.user || (result.data && result.data.user) || null) : null;
                return { isLogin: loggedIn, user: this.frontendUser };
            } catch (error) {
                console.warn('[WelineApi.Account] frontend session check failed:', error);
                this.frontendUser = null;
                return { isLogin: false, user: null };
            }
        }

        async frontendUserLogin(username, password, rememberDuration = 0) {
            try {
                const result = await this.call('login', {
                    username,
                    password,
                    remember_duration: Number(rememberDuration || 0),
                });
                const ok = !!(result && result.success !== false);
                this.frontendUser = ok ? (result.user || (result.data && result.data.user) || null) : null;
                if (ok) {
                    window.dispatchEvent(new CustomEvent('weline:account:frontend:login', {
                        detail: { user: this.frontendUser }
                    }));
                }
                return {
                    success: ok,
                    message: (result && (result.message || result.msg)) || (ok ? 'Login successful' : 'Login failed'),
                    user: this.frontendUser,
                    redirect: result && (result.redirect || (result.data && result.data.redirect)) || null
                };
            } catch (error) {
                return {
                    success: false,
                    message: error && error.message ? error.message : 'Login request failed',
                    user: null,
                    redirect: null
                };
            }
        }

        async frontendUserRegister(payload = {}) {
            try {
                const result = await this.call('register', payload);
                const ok = !!(result && result.success !== false);
                this.frontendUser = ok ? (result.user || (result.data && result.data.user) || null) : null;
                if (ok) {
                    window.dispatchEvent(new CustomEvent('weline:account:frontend:login', {
                        detail: { user: this.frontendUser }
                    }));
                }
                return {
                    success: ok,
                    message: (result && (result.message || result.msg)) || (ok ? 'Registration successful' : 'Registration failed'),
                    user: this.frontendUser,
                    redirect: result && (result.redirect || (result.data && result.data.redirect)) || null
                };
            } catch (error) {
                return {
                    success: false,
                    message: error && error.message ? error.message : 'Registration request failed',
                    user: null,
                    redirect: null
                };
            }
        }

        async frontendUserLogout() {
            try {
                await this.call('logout');
            } catch (error) {
                console.warn('[WelineApi.Account] frontend logout request failed:', error);
            }
            this.frontendUser = null;
            window.dispatchEvent(new CustomEvent('weline:account:frontend:logout'));
            return { success: true, message: 'Logout successful' };
        }

        checkFrontendApiLogin() {
            return Promise.resolve(this.checkStoredToken('frontendApi'));
        }

        checkBackendApiLogin() {
            return Promise.resolve(this.checkStoredToken('backendApi'));
        }

        frontendApiLogin() {
            return Promise.resolve(this.externalApiUnavailable('frontendApiLogin'));
        }

        backendApiLogin() {
            return Promise.resolve(this.externalApiUnavailable('backendApiLogin'));
        }

        async frontendApiLogout() {
            await this.clearApiToken('frontendApi');
            window.dispatchEvent(new CustomEvent('weline:account:api:logout'));
            return { success: true, message: 'Token cleared locally' };
        }

        async backendApiLogout() {
            await this.clearApiToken('backendApi');
            window.dispatchEvent(new CustomEvent('weline:account:backend-api:logout'));
            return { success: true, message: 'Token cleared locally' };
        }

        getFrontendUser() {
            return this.frontendUser;
        }

        getFrontendApiUser() {
            return this.apiUser;
        }

        getBackendApiUser() {
            return this.backendApiUser;
        }

        getFrontendApiToken() {
            return localStorage.getItem(this.config.apiTokenKey);
        }

        getBackendApiToken() {
            return localStorage.getItem(this.config.backendApiTokenKey);
        }

        checkStoredToken(type) {
            const isFrontend = type === 'frontendApi';
            const tokenKey = isFrontend ? this.config.apiTokenKey : this.config.backendApiTokenKey;
            const userKey = isFrontend ? this.config.apiUserKey : this.config.backendApiUserKey;
            const token = localStorage.getItem(tokenKey);
            if (!token) {
                if (isFrontend) {
                    this.apiUser = null;
                } else {
                    this.backendApiUser = null;
                }
                return { isLogin: false, user: null, expiresAt: null };
            }
            const expiresAt = Number(localStorage.getItem(tokenKey + '_expires_at') || 0);
            if (expiresAt && expiresAt < Date.now()) {
                this.clearApiToken(type);
                return { isLogin: false, user: null, expiresAt: null };
            }
            const user = this.readStoredUser(userKey);
            if (isFrontend) {
                this.apiUser = user;
            } else {
                this.backendApiUser = user;
            }
            return { isLogin: true, user, expiresAt: expiresAt || null };
        }

        async clearApiToken(type) {
            const isFrontend = type === 'frontendApi';
            const tokenKey = isFrontend ? this.config.apiTokenKey : this.config.backendApiTokenKey;
            const refreshTokenKey = isFrontend ? this.config.apiRefreshTokenKey : this.config.backendApiRefreshTokenKey;
            const userKey = isFrontend ? this.config.apiUserKey : this.config.backendApiUserKey;
            localStorage.removeItem(tokenKey);
            localStorage.removeItem(refreshTokenKey);
            localStorage.removeItem(userKey);
            localStorage.removeItem(tokenKey + '_expires_at');
            if (isFrontend) {
                this.apiUser = null;
            } else {
                this.backendApiUser = null;
            }
        }

        readStoredUser(key) {
            const value = localStorage.getItem(key);
            if (!value) {
                return null;
            }
            try {
                return JSON.parse(value);
            } catch (error) {
                localStorage.removeItem(key);
                return null;
            }
        }

        externalApiUnavailable(method) {
            const message = method + ' is disabled in the storefront browser. Use External API/OAuth/External Frontend Bridge.';
            console.warn('[WelineApi.Account] ' + message);
            return { success: false, message, user: null, token: null };
        }
    }

    const accountManager = new AccountManager(getAccountConfig());
    const AccountModule = {
        _instance: accountManager,
        checkFrontendUserLogin: () => accountManager.checkFrontendUserLogin(),
        frontendUserLogin: (username, password, rememberDuration) => accountManager.frontendUserLogin(username, password, rememberDuration),
        frontendUserRegister: (payload) => accountManager.frontendUserRegister(payload),
        frontendUserLogout: () => accountManager.frontendUserLogout(),
        getFrontendUser: () => accountManager.getFrontendUser(),
        checkFrontendApiLogin: () => accountManager.checkFrontendApiLogin(),
        frontendApiLogin: (...args) => accountManager.frontendApiLogin(...args),
        frontendApiLogout: () => accountManager.frontendApiLogout(),
        getFrontendApiUser: () => accountManager.getFrontendApiUser(),
        getFrontendApiToken: () => accountManager.getFrontendApiToken(),
        checkBackendApiLogin: () => accountManager.checkBackendApiLogin(),
        backendApiLogin: (...args) => accountManager.backendApiLogin(...args),
        backendApiLogout: () => accountManager.backendApiLogout(),
        getBackendApiUser: () => accountManager.getBackendApiUser(),
        getBackendApiToken: () => accountManager.getBackendApiToken(),
    };

    AccountModule.checkFrontendLogin = (...args) => AccountModule.checkFrontendUserLogin(...args);
    AccountModule.frontendLogin = (...args) => AccountModule.frontendUserLogin(...args);
    AccountModule.frontendRegister = (...args) => AccountModule.frontendUserRegister(...args);
    AccountModule.frontendLogout = (...args) => AccountModule.frontendUserLogout(...args);
    AccountModule.checkApiLogin = (...args) => AccountModule.checkFrontendApiLogin(...args);
    AccountModule.apiLogin = (...args) => AccountModule.frontendApiLogin(...args);
    AccountModule.apiLogout = (...args) => AccountModule.frontendApiLogout(...args);
    AccountModule.getApiUser = (...args) => AccountModule.getFrontendApiUser(...args);
    AccountModule.getApiToken = (...args) => AccountModule.getFrontendApiToken(...args);

    window.WelineAccountModule = AccountModule;
})(window);
