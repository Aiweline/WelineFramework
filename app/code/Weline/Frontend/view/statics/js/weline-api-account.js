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
        // Keep Session TTL sliding while the browser stays online (ms).
        keepaliveIntervalMs: 240000,
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
            this._authRefreshHandled = false;
            this._keepaliveTimer = null;
            this._keepaliveRunning = false;
            this._keepaliveBound = false;
            this._keepaliveInFlight = false;
            this._keepaliveIntervalMs = Math.max(
                60000,
                Number(this.config.keepaliveIntervalMs) || DEFAULT_KEYS.keepaliveIntervalMs
            );
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
                    this.startOnlineKeepalive();
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
                    this.startOnlineKeepalive();
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
            this.stopOnlineKeepalive();
            try {
                await this.call('logout');
            } catch (error) {
                console.warn('[WelineApi.Account] frontend logout request failed:', error);
            }
            this.frontendUser = null;
            window.dispatchEvent(new CustomEvent('weline:account:frontend:logout'));
            return { success: true, message: 'Logout successful' };
        }

        /**
         * While the browser stays online, periodically call account.current so the
         * storefront Session TTL slides (Framework Session.save/touch on activity).
         */
        startOnlineKeepalive() {
            this.bindOnlineKeepaliveEvents();
            if (this._keepaliveRunning) {
                this.scheduleOnlineKeepalive(false);
                return;
            }
            this._keepaliveRunning = true;
            this.scheduleOnlineKeepalive(false);
        }

        stopOnlineKeepalive() {
            this._keepaliveRunning = false;
            if (this._keepaliveTimer) {
                clearTimeout(this._keepaliveTimer);
                this._keepaliveTimer = null;
            }
        }

        isOnlineKeepaliveActive() {
            return !!this._keepaliveRunning;
        }

        bindOnlineKeepaliveEvents() {
            if (this._keepaliveBound) {
                return;
            }
            this._keepaliveBound = true;
            window.addEventListener('online', () => {
                if (this._keepaliveRunning) {
                    this.scheduleOnlineKeepalive(true);
                }
            });
            window.addEventListener('offline', () => {
                if (this._keepaliveTimer) {
                    clearTimeout(this._keepaliveTimer);
                    this._keepaliveTimer = null;
                }
            });
        }

        scheduleOnlineKeepalive(immediate) {
            if (this._keepaliveTimer) {
                clearTimeout(this._keepaliveTimer);
                this._keepaliveTimer = null;
            }
            if (!this._keepaliveRunning) {
                return;
            }
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                return;
            }
            const delay = immediate ? 0 : this._keepaliveIntervalMs;
            this._keepaliveTimer = setTimeout(() => {
                this._keepaliveTimer = null;
                this.runOnlineKeepaliveTick();
            }, delay);
        }

        async runOnlineKeepaliveTick() {
            if (!this._keepaliveRunning) {
                return;
            }
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                return;
            }
            if (this._keepaliveInFlight) {
                this.scheduleOnlineKeepalive(false);
                return;
            }
            this._keepaliveInFlight = true;
            try {
                const result = await this.call('current');
                const loggedIn = !!(result && (result.isLogin || result.logged_in));
                if (!loggedIn) {
                    this.frontendUser = null;
                    this.stopOnlineKeepalive();
                    return;
                }
                this.frontendUser = result.user || (result.data && result.data.user) || this.frontendUser;
            } catch (error) {
                // Transient network/API errors must not stop keepalive while the browser is online.
                console.warn('[WelineApi.Account] online keepalive failed:', error);
            } finally {
                this._keepaliveInFlight = false;
            }
            if (this._keepaliveRunning) {
                this.scheduleOnlineKeepalive(false);
            }
        }

        /**
         * One-shot storefront chrome refresh after login (w_auth=1) or logout (w_auth=0).
         * Theme HeaderManager calls Weline.Account.handleAuthRefreshSignal().
         * Also soft-reconciles guest SSR chrome when Session is already signed in
         * (FPC / cookie-name split can still render the guest shell without w_auth).
         */
        handleAuthRefreshSignal() {
            if (this._authRefreshHandled) {
                return Promise.resolve({ handled: false, reason: 'already_handled' });
            }

            const roots = document.querySelectorAll('[data-w-header-account="1"]');
            if (!roots.length) {
                if (this.hasAuthRefreshSignal()) {
                    this._authRefreshHandled = true;
                    this.stripAuthRefreshSignal();
                }
                return Promise.resolve({ handled: true, refreshed: false });
            }

            const hasSignal = this.hasAuthRefreshSignal();
            const guestRoots = [];
            roots.forEach((root) => {
                if (root.getAttribute('data-auth-state') === 'guest') {
                    guestRoots.push(root);
                }
            });

            // No auth signal and SSR already signed-in: nothing to do.
            if (!hasSignal && guestRoots.length === 0) {
                return Promise.resolve({ handled: false, reason: 'no_signal' });
            }

            this._authRefreshHandled = true;
            const targets = hasSignal ? Array.from(roots) : guestRoots;

            return this.checkFrontendUserLogin()
                .then((status) => {
                    targets.forEach((root) => {
                        if (status && status.isLogin) {
                            this.applyHeaderSignedIn(root, status.user || null);
                        } else if (hasSignal) {
                            this.applyHeaderGuest(root);
                        }
                    });
                    if (status && status.isLogin) {
                        this.startOnlineKeepalive();
                    } else {
                        this.stopOnlineKeepalive();
                    }
                    return { handled: true, refreshed: true, isLogin: !!(status && status.isLogin) };
                })
                .catch((error) => {
                    console.warn('[WelineApi.Account] auth refresh signal failed:', error);
                    return { handled: true, refreshed: false, error };
                })
                .finally(() => {
                    if (hasSignal) {
                        this.stripAuthRefreshSignal();
                    }
                });
        }

        hasAuthRefreshSignal() {
            try {
                const params = new URLSearchParams(window.location.search || '');
                const value = params.get('w_auth');
                return value === '1' || value === '0';
            } catch (_error) {
                return false;
            }
        }

        stripAuthRefreshSignal() {
            try {
                const url = new URL(window.location.href);
                if (!url.searchParams.has('w_auth')) {
                    return;
                }
                url.searchParams.delete('w_auth');
                const next = url.pathname
                    + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '')
                    + url.hash;
                window.history.replaceState(window.history.state, '', next);
            } catch (_error) {
                // ignore history errors
            }
        }

        applyHeaderSignedIn(root, user) {
            if (!(root instanceof Element)) {
                return;
            }
            const guest = root.querySelector('[data-account-shell="guest"]');
            const signedIn = root.querySelector('[data-account-shell="signed-in"]');
            if (guest) {
                guest.hidden = true;
            }
            if (signedIn) {
                signedIn.hidden = false;
            }
            root.setAttribute('data-auth-state', 'signed-in');

            const username = this.resolveHeaderDisplayName(user);
            const helloNamed = root.getAttribute('data-i18n-hello-named') || 'Hello, %USERNAME%';
            const hello = username
                ? helloNamed.replace('%USERNAME%', username).replace('%{1}', username)
                : (root.getAttribute('data-i18n-hello') || 'Hello');
            const line1 = root.querySelector('[data-account-line1]');
            if (line1) {
                line1.textContent = hello;
            }
            const fallbackAccount = root.getAttribute('data-i18n-account') || 'Account';
            const menuName = root.querySelector('[data-account-menu-name]');
            if (menuName) {
                menuName.textContent = username || fallbackAccount;
            }
            const displayName = root.querySelector('[data-account-display-name]');
            if (displayName) {
                displayName.textContent = username || fallbackAccount;
            }
            const avatar = root.querySelector('[data-account-avatar]');
            if (avatar && user && user.avatar) {
                avatar.setAttribute('src', String(user.avatar));
                avatar.setAttribute('alt', username || '');
            }
            this.applyHeaderMenuAuth(root, 'signed-in');
        }

        /**
         * Toggle dual-rendered header-account-links (guest vs signed-in) after
         * FPC / soft-reconcile flips data-auth-state without re-SSR the hook.
         */
        applyHeaderMenuAuth(root, state) {
            if (!(root instanceof Element)) {
                return;
            }
            const mode = state === 'signed-in' ? 'signed-in' : 'guest';
            root.querySelectorAll('[data-account-menu-auth]').forEach((el) => {
                const forState = el.getAttribute('data-account-menu-auth');
                el.hidden = forState !== mode;
            });
            // Mobile "我的" menu also hosts the same hook outside the widget root.
            document.querySelectorAll('.header-my-menu [data-account-menu-auth]').forEach((el) => {
                const forState = el.getAttribute('data-account-menu-auth');
                el.hidden = forState !== mode;
            });
        }

        /**
         * Short chrome label: prefer API display_name, then non-email username,
         * else email/username local-part (never full mailbox in header greetings).
         */
        resolveHeaderDisplayName(user) {
            if (!user || typeof user !== 'object') {
                return '';
            }
            const displayName = String(user.display_name || '').trim();
            if (displayName) {
                return displayName;
            }
            const username = String(user.username || '').trim();
            const email = String(user.email || '').trim();
            if (username && username.indexOf('@') === -1) {
                return username;
            }
            const source = (username.indexOf('@') !== -1 ? username : email) || username || email;
            if (source.indexOf('@') !== -1) {
                const local = String(source.split('@')[0] || '').trim();
                return local || source;
            }
            return username || email;
        }

        applyHeaderGuest(root) {
            if (!(root instanceof Element)) {
                return;
            }
            const guest = root.querySelector('[data-account-shell="guest"]');
            const signedIn = root.querySelector('[data-account-shell="signed-in"]');
            if (guest) {
                guest.hidden = false;
            }
            if (signedIn) {
                signedIn.hidden = true;
            }
            root.setAttribute('data-auth-state', 'guest');
            this.applyHeaderMenuAuth(root, 'guest');
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
        __full: true,
        _instance: accountManager,
        checkFrontendUserLogin: () => accountManager.checkFrontendUserLogin(),
        frontendUserLogin: (username, password, rememberDuration) => accountManager.frontendUserLogin(username, password, rememberDuration),
        frontendUserRegister: (payload) => accountManager.frontendUserRegister(payload),
        frontendUserLogout: () => accountManager.frontendUserLogout(),
        handleAuthRefreshSignal: () => accountManager.handleAuthRefreshSignal(),
        startOnlineKeepalive: () => accountManager.startOnlineKeepalive(),
        stopOnlineKeepalive: () => accountManager.stopOnlineKeepalive(),
        isOnlineKeepaliveActive: () => accountManager.isOnlineKeepaliveActive(),
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

    // Header account widget declares data-weline-load="api,account": when this module
    // arrives, apply w_auth chrome refresh and soft-reconcile guest SSR shells.
    (function bootstrapHeaderAuthRefresh() {
        const run = () => {
            if (!document.querySelector('[data-w-header-account="1"]')) {
                return;
            }
            Promise.resolve(accountManager.handleAuthRefreshSignal()).catch(() => {});
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    })();

    // Keep storefront Session TTL sliding while the browser stays online.
    (function bootstrapOnlineKeepalive() {
        const startFromSignedInShell = () => {
            if (document.querySelector('[data-w-header-account="1"][data-auth-state="signed-in"]')) {
                accountManager.startOnlineKeepalive();
            }
        };
        window.addEventListener('weline:account:frontend:login', () => {
            accountManager.startOnlineKeepalive();
        });
        window.addEventListener('weline:account:frontend:logout', () => {
            accountManager.stopOnlineKeepalive();
        });
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startFromSignedInShell, { once: true });
        } else {
            startFromSignedInShell();
        }
    })();
})(window);
