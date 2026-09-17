/**
 * Weline Customer — 前台账户会话 / 顶栏 chrome（account 模组）
 *
 * 归属：Weline_Customer（禁止放在 Frontend weline-api* 下）。
 * Storefront account 请求经 Weline.Api -> worker -> query-bin。
 * Browser External API token login 不在此实现。
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
        // Storefront session chrome snapshot (localStorage); avoids account.current on every page.
        frontendSessionUserKey: 'weline_frontend_session_user',
        // Cross-navigation stamp (sessionStorage): login just succeeded; force one reconcile.
        frontendAuthPendingKey: 'weline_frontend_auth_pending',
        // Align with Framework session lifetime default (86400s).
        sessionTtlMs: 86400000,
        // Refetch when this close to session TTL end (also default keepalive interval).
        renewSkewMs: 240000,
        // Guest negative-cache recheck window (shorter than signed-in TTL).
        guestRecheckMs: 300000,
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
            this._authRefreshInFlight = null;
            this._keepaliveTimer = null;
            this._keepaliveRunning = false;
            this._keepaliveBound = false;
            this._keepaliveInFlight = false;
            this._keepaliveIntervalMs = Math.max(
                60000,
                Number(this.config.keepaliveIntervalMs) || DEFAULT_KEYS.keepaliveIntervalMs
            );
            this._sessionTtlMs = Math.max(
                this._keepaliveIntervalMs,
                Number(this.config.sessionTtlMs) || DEFAULT_KEYS.sessionTtlMs
            );
            this._renewSkewMs = Math.max(
                30000,
                Number(this.config.renewSkewMs) || this._keepaliveIntervalMs || DEFAULT_KEYS.renewSkewMs
            );
            this._guestRecheckMs = Math.max(
                60000,
                Number(this.config.guestRecheckMs) || DEFAULT_KEYS.guestRecheckMs
            );
            this._storageBound = false;
            this.bindFrontendSessionStorageSync();
            const cached = this.readFrontendSessionCache();
            if (cached && this.isFrontendSessionCacheFresh(cached) && cached.isLogin) {
                this.frontendUser = cached.user || null;
            }
        }

        computeFrontendSessionRenewAt(isLogin, verifiedAt) {
            const at = Number(verifiedAt) || Date.now();
            if (isLogin) {
                const ttl = Math.max(this._sessionTtlMs - this._renewSkewMs, this._renewSkewMs);
                return at + ttl;
            }
            return at + this._guestRecheckMs;
        }

        readFrontendSessionCache() {
            const key = this.config.frontendSessionUserKey || DEFAULT_KEYS.frontendSessionUserKey;
            const raw = localStorage.getItem(key);
            if (!raw) {
                return null;
            }
            try {
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object' || Number(parsed.v) !== 1) {
                    localStorage.removeItem(key);
                    return null;
                }
                return {
                    v: 1,
                    isLogin: !!parsed.isLogin,
                    user: parsed.user && typeof parsed.user === 'object' ? parsed.user : null,
                    verifiedAt: Number(parsed.verifiedAt) || 0,
                    renewAt: Number(parsed.renewAt) || 0,
                    signals: parsed.signals && typeof parsed.signals === 'object' ? parsed.signals : null,
                };
            } catch (_error) {
                localStorage.removeItem(key);
                return null;
            }
        }

        isFrontendSessionCacheFresh(cache) {
            if (!cache || typeof cache !== 'object') {
                return false;
            }
            const renewAt = Number(cache.renewAt) || 0;
            const verifiedAt = Number(cache.verifiedAt) || 0;
            if (!renewAt || !verifiedAt || renewAt < verifiedAt) {
                return false;
            }
            return Date.now() < renewAt;
        }

        resolveUserIdentity(user) {
            if (!user || typeof user !== 'object') {
                return '';
            }
            const id = user.customer_id ?? user.id ?? user.user_id ?? '';
            if (id !== '' && id !== null && id !== undefined) {
                return String(id);
            }
            const email = String(user.email || '').trim().toLowerCase();
            if (email) {
                return 'email:' + email;
            }
            const username = String(user.username || '').trim().toLowerCase();
            return username ? ('user:' + username) : '';
        }

        writeFrontendSessionCache(status, signals) {
            const key = this.config.frontendSessionUserKey || DEFAULT_KEYS.frontendSessionUserKey;
            const isLogin = !!(status && status.isLogin);
            const verifiedAt = Date.now();
            const payload = {
                v: 1,
                isLogin,
                user: isLogin ? ((status && status.user) || null) : null,
                verifiedAt,
                renewAt: this.computeFrontendSessionRenewAt(isLogin, verifiedAt),
            };
            if (signals && typeof signals === 'object') {
                payload.signals = {
                    total: Number(signals.total) || 0,
                    by_code: signals.by_code && typeof signals.by_code === 'object' ? signals.by_code : {},
                };
            } else if (isLogin) {
                // Only keep badge counts when the identity is unchanged — never
                // inherit previous account signals after switching users.
                const prev = this.readFrontendSessionCache();
                const prevId = this.resolveUserIdentity(prev && prev.user);
                const nextId = this.resolveUserIdentity(payload.user);
                if (prev && prev.isLogin && prev.signals && prevId && nextId && prevId === nextId) {
                    payload.signals = prev.signals;
                }
            }
            try {
                localStorage.setItem(key, JSON.stringify(payload));
            } catch (_error) {
                // Quota / private mode — ignore; network path still works.
            }
            return payload;
        }

        /**
         * Drop cached chrome + in-memory user before a login/logout boundary so
         * switching accounts cannot flash the previous profile.
         */
        beginAuthBoundary(kind) {
            this.clearFrontendSessionCache();
            this.clearAccountMenuSignals();
            this.frontendUser = null;
            if (kind === 'logout' || kind === 'login' || kind === 'switch') {
                document.querySelectorAll('[data-w-header-account="1"]').forEach((root) => {
                    this.applyHeaderGuest(root);
                });
            }
            if (kind === 'logout') {
                this.stopOnlineKeepalive();
            }
        }

        clearFrontendSessionCache() {
            const key = this.config.frontendSessionUserKey || DEFAULT_KEYS.frontendSessionUserKey;
            try {
                localStorage.removeItem(key);
            } catch (_error) {
                // ignore
            }
        }

        /**
         * sessionStorage stamp so the post-login navigation (often ?w_auth=1) still
         * forces account.current even if localStorage was briefly cleared.
         */
        markAuthPending() {
            const key = this.config.frontendAuthPendingKey || DEFAULT_KEYS.frontendAuthPendingKey;
            try {
                sessionStorage.setItem(key, '1');
            } catch (_error) {
                // ignore
            }
        }

        clearAuthPending() {
            const key = this.config.frontendAuthPendingKey || DEFAULT_KEYS.frontendAuthPendingKey;
            try {
                sessionStorage.removeItem(key);
            } catch (_error) {
                // ignore
            }
        }

        hasAuthPending() {
            const key = this.config.frontendAuthPendingKey || DEFAULT_KEYS.frontendAuthPendingKey;
            try {
                return sessionStorage.getItem(key) === '1';
            } catch (_error) {
                return false;
            }
        }

        /**
         * Paint header + keepalive from a session cache snapshot (same-tab helper
         * and cross-tab storage sync).
         */
        applyFrontendSessionSnapshot(cache) {
            const roots = document.querySelectorAll('[data-w-header-account="1"]');
            if (!cache || !cache.isLogin) {
                this.frontendUser = null;
                this.stopOnlineKeepalive();
                this.clearAccountMenuSignals();
                roots.forEach((root) => this.applyHeaderGuest(root));
                this.maybeStartSocialQuickPrompt();
                return { applied: true, isLogin: false };
            }
            this.frontendUser = cache.user || null;
            roots.forEach((root) => this.applyHeaderSignedIn(root, this.frontendUser));
            this.startOnlineKeepalive();
            if (cache.signals) {
                this.paintAccountMenuSignals(cache.signals);
            }
            try { window.__welineSocialQuickBooted = true; } catch (_e) { /* ignore */ }
            // Cross-tab storage sync must not leave auth pages painted signed-in.
            if (this.leaveAuthPageIfSignedIn(true)) {
                return { applied: true, isLogin: true, redirected: true };
            }
            return { applied: true, isLogin: true };
        }

        /**
         * After profile save: merge user into session cache and refresh header
         * without forcing a new account.current (preserves renewAt when still valid).
         */
        applyFrontendProfileUpdate(user) {
            if (!user || typeof user !== 'object') {
                return { updated: false, reason: 'no_user' };
            }
            const prev = this.readFrontendSessionCache();
            const baseUser = (prev && prev.isLogin && prev.user) ? prev.user : (this.frontendUser || {});
            const merged = Object.assign({}, baseUser, user);
            this.frontendUser = merged;
            const verifiedAt = (prev && prev.isLogin && Number(prev.verifiedAt) > 0)
                ? Number(prev.verifiedAt)
                : Date.now();
            let renewAt = (prev && prev.isLogin && Number(prev.renewAt) > Date.now())
                ? Number(prev.renewAt)
                : this.computeFrontendSessionRenewAt(true, Date.now());
            const key = this.config.frontendSessionUserKey || DEFAULT_KEYS.frontendSessionUserKey;
            const payload = {
                v: 1,
                isLogin: true,
                user: merged,
                verifiedAt,
                renewAt,
            };
            if (prev && prev.signals) {
                payload.signals = prev.signals;
            }
            try {
                localStorage.setItem(key, JSON.stringify(payload));
            } catch (_error) {
                // ignore
            }
            document.querySelectorAll('[data-w-header-account="1"]').forEach((root) => {
                this.applyHeaderSignedIn(root, merged);
            });
            window.dispatchEvent(new CustomEvent('weline:account:frontend:profile', {
                detail: { user: merged }
            }));
            return { updated: true, user: merged };
        }

        bindFrontendSessionStorageSync() {
            if (this._storageBound) {
                return;
            }
            this._storageBound = true;
            const key = this.config.frontendSessionUserKey || DEFAULT_KEYS.frontendSessionUserKey;
            window.addEventListener('storage', (event) => {
                if (!event || event.key !== key) {
                    return;
                }
                if (event.newValue === null || event.newValue === '') {
                    this.applyFrontendSessionSnapshot(null);
                    return;
                }
                try {
                    const parsed = JSON.parse(event.newValue);
                    if (!parsed || typeof parsed !== 'object' || Number(parsed.v) !== 1) {
                        this.applyFrontendSessionSnapshot(null);
                        return;
                    }
                    this.applyFrontendSessionSnapshot({
                        v: 1,
                        isLogin: !!parsed.isLogin,
                        user: parsed.user && typeof parsed.user === 'object' ? parsed.user : null,
                        verifiedAt: Number(parsed.verifiedAt) || 0,
                        renewAt: Number(parsed.renewAt) || 0,
                        signals: parsed.signals && typeof parsed.signals === 'object' ? parsed.signals : null,
                    });
                } catch (_error) {
                    this.applyFrontendSessionSnapshot(null);
                }
            });
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

        async checkFrontendUserLogin(options = {}) {
            const force = !!(options && options.force);
            // After login (?w_auth=1 / auth-pending): never poison a just-written signed-in
            // snapshot with a guest negative-cache when account.current is briefly wrong.
            const skipGuestNegativeCache = !!(options && options.skipGuestNegativeCache);
            if (!force) {
                const cached = this.readFrontendSessionCache();
                if (this.isFrontendSessionCacheFresh(cached)) {
                    this.frontendUser = cached.isLogin ? (cached.user || null) : null;
                    return {
                        isLogin: !!cached.isLogin,
                        user: this.frontendUser,
                        fromCache: true,
                        renewAt: cached.renewAt,
                    };
                }
            }
            try {
                const result = await this.call('current');
                // Never treat transport success as signed-in — guest current uses success:false,
                // but other account ops may return success:true with isLogin:false.
                const loggedIn = !!(result && (result.isLogin || result.logged_in));
                this.frontendUser = loggedIn ? (result.user || (result.data && result.data.user) || null) : null;
                const status = { isLogin: loggedIn, user: this.frontendUser, fromCache: false };
                this.writeFrontendSessionCache(status);
                return status;
            } catch (error) {
                console.warn('[WelineApi.Account] frontend session check failed:', error);
                this.frontendUser = null;
                const status = { isLogin: false, user: null, fromCache: false };
                // Worker/API treats guest current (success:false) as a thrown error.
                // Still write a short-lived negative cache so every page does not re-hit the network.
                const message = String((error && error.message) || error || '');
                if (!skipGuestNegativeCache
                    && (/not signed in|not logged in|unauthori[sz]ed|未登录|未登入/i.test(message)
                        || (error && (error.code === 401 || error.status === 401)))) {
                    this.writeFrontendSessionCache(status);
                }
                return status;
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
                if (ok) {
                    // Account switch / re-login: wipe previous profile before applying the new one.
                    this.beginAuthBoundary('login');
                    this.frontendUser = (result.user || (result.data && result.data.user) || null);
                    this.writeFrontendSessionCache({ isLogin: true, user: this.frontendUser });
                    this.markAuthPending();
                    this.startOnlineKeepalive();
                    window.dispatchEvent(new CustomEvent('weline:account:frontend:login', {
                        detail: { user: this.frontendUser }
                    }));
                } else {
                    this.frontendUser = null;
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
                if (ok) {
                    // Account switch / re-login: wipe previous profile before applying the new one.
                    this.beginAuthBoundary('login');
                    this.frontendUser = (result.user || (result.data && result.data.user) || null);
                    this.writeFrontendSessionCache({ isLogin: true, user: this.frontendUser });
                    this.markAuthPending();
                    this.startOnlineKeepalive();
                    window.dispatchEvent(new CustomEvent('weline:account:frontend:login', {
                        detail: { user: this.frontendUser }
                    }));
                } else {
                    this.frontendUser = null;
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
            this.beginAuthBoundary('logout');
            try {
                await this.call('logout');
            } catch (error) {
                console.warn('[WelineApi.Account] frontend logout request failed:', error);
            }
            window.dispatchEvent(new CustomEvent('weline:account:frontend:logout'));
            return { success: true, message: 'Logout successful' };
        }

        /**
         * While signed-in, renew only when the cached renewAt is due — not on a
         * fixed short poll. Login writes renewAt (= verifiedAt + sessionTtl - skew).
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

        msUntilSessionRenew() {
            const cached = this.readFrontendSessionCache();
            if (cached && cached.isLogin && Number(cached.renewAt) > 0) {
                return Math.max(0, Number(cached.renewAt) - Date.now());
            }
            // No snapshot yet: wait a full renew window, not a short poll.
            return Math.max(this._renewSkewMs, this._sessionTtlMs - this._renewSkewMs);
        }

        bindOnlineKeepaliveEvents() {
            if (this._keepaliveBound) {
                return;
            }
            this._keepaliveBound = true;
            window.addEventListener('online', () => {
                if (this._keepaliveRunning) {
                    // Back online: only renew immediately if already past renewAt.
                    this.scheduleOnlineKeepalive(this.msUntilSessionRenew() <= 0);
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
            const delay = immediate ? 0 : Math.max(1000, this.msUntilSessionRenew());
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
                    this.writeFrontendSessionCache({ isLogin: false, user: null });
                    this.stopOnlineKeepalive();
                    return;
                }
                this.frontendUser = result.user || (result.data && result.data.user) || this.frontendUser;
                this.writeFrontendSessionCache({ isLogin: true, user: this.frontendUser });
            } catch (error) {
                const message = String((error && error.message) || error || '');
                if (/not signed in|not logged in|unauthori[sz]ed|未登录|未登入/i.test(message)
                    || (error && (error.code === 401 || error.status === 401))) {
                    this.frontendUser = null;
                    this.writeFrontendSessionCache({ isLogin: false, user: null });
                    this.stopOnlineKeepalive();
                } else {
                    // Transient network/API errors must not stop keepalive while the browser is online.
                    console.warn('[WelineApi.Account] online keepalive failed:', error);
                }
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
            return this.syncHeaderAccountChrome({ fromAuthSignal: true });
        }

        /**
         * Align header shell + dual-rendered header-account-links with account session.
         * Prefer localStorage session cache; network account.current only when cache is
         * missing/stale (past renewAt), w_auth signal, or options.force.
         * Auth pages (login/register/forgot) always revalidate — never trust chrome cache alone
         * (avoids “header signed-in + login form” mismatch).
         * Covers FPC guest SSR, signed-in SSR with guest-cached hook fragments, and w_auth redirects.
         */
        syncHeaderAccountChrome(options = {}) {
            const force = !!options.force;
            const onAuthPage = this.isStorefrontAuthPage();
            const loginSignal = this.isLoginAuthSignal();
            const logoutSignal = this.isLogoutAuthSignal();
            const hasSignal = loginSignal || logoutSignal;
            const authPending = this.hasAuthPending();

            if (this._authRefreshInFlight) {
                return this._authRefreshInFlight;
            }

            const roots = document.querySelectorAll('[data-w-header-account="1"]');
            if (!roots.length) {
                if (hasSignal) {
                    this.stripAuthRefreshSignal();
                }
                return Promise.resolve({ synced: false, reason: 'no_roots' });
            }

            // Logout always drops chrome. Login / auth-pending must NOT wipe an
            // optimistic signed-in snapshot written by the login page — otherwise
            // a failed account.current re-poisons guest negative-cache for guestRecheckMs
            // and every subsequent FPC guest-SSR page stays "未登录".
            if (logoutSignal) {
                this.beginAuthBoundary('logout');
                this.clearAuthPending();
            } else if (loginSignal || authPending) {
                const pre = this.readFrontendSessionCache();
                if (!pre || !pre.isLogin) {
                    this.beginAuthBoundary('login');
                } else {
                    this.frontendUser = pre.user || null;
                }
            }
            const guestRoots = [];
            let needsMenuReconcile = false;
            roots.forEach((root) => {
                if (root.getAttribute('data-auth-state') === 'guest') {
                    guestRoots.push(root);
                }
                const desired = root.getAttribute('data-auth-state') === 'signed-in' ? 'signed-in' : 'guest';
                if (this.headerMenuAuthMismatch(root, desired)) {
                    needsMenuReconcile = true;
                }
            });

            const cached = this.readFrontendSessionCache();
            const cacheFresh = this.isFrontendSessionCacheFresh(cached);
            // Guest SSR alone must not force a network round-trip when cache is still fresh.
            // Auth pages / w_auth / auth-pending always force network so server session is SoT.
            const needsNetwork = force || hasSignal || authPending || !cacheFresh || onAuthPage;

            if (!needsNetwork && cached) {
                const isLogin = !!cached.isLogin;
                const user = isLogin ? (cached.user || null) : null;
                this.frontendUser = user;
                Array.from(roots).forEach((root) => {
                    if (isLogin) {
                        this.applyHeaderSignedIn(root, user);
                    } else {
                        this.applyHeaderGuest(root);
                    }
                });
                if (isLogin) {
                    this.startOnlineKeepalive();
                    this._authRefreshHandled = true;
                    if (cached.signals) {
                        this.paintAccountMenuSignals(cached.signals);
                    }
                    try { window.__welineSocialQuickBooted = true; } catch (_e) { /* ignore */ }
                    if (this.leaveAuthPageIfSignedIn(true)) {
                        return Promise.resolve({
                            synced: true,
                            refreshed: false,
                            fromCache: true,
                            isLogin: true,
                            redirected: true,
                            reason: 'auth_page_leave_cache',
                        });
                    }
                } else {
                    // Guest cache hit: skip account.current network only; still start
                    // the visible quick-login chooser (idempotent via __welineSocialQuickBooted).
                    this.clearAccountMenuSignals();
                    this.maybeStartSocialQuickPrompt();
                }
                if (hasSignal) {
                    this.stripAuthRefreshSignal();
                }
                return Promise.resolve({
                    synced: true,
                    refreshed: false,
                    fromCache: true,
                    isLogin,
                    reason: needsMenuReconcile ? 'cache_menu_reconcile' : 'cache_hit',
                });
            }

            if (!force && !hasSignal && !authPending && !onAuthPage && guestRoots.length === 0 && !needsMenuReconcile && cacheFresh && cached && !cached.isLogin) {
                this.maybeStartSocialQuickPrompt();
                return Promise.resolve({ synced: false, reason: 'already_aligned' });
            }

            const targets = (hasSignal || authPending || onAuthPage)
                ? Array.from(roots)
                : (guestRoots.length ? guestRoots : Array.from(roots));
            const skipGuestNegativeCache = !!(loginSignal || authPending);

            this._authRefreshInFlight = this.checkFrontendUserLogin({
                force: true,
                skipGuestNegativeCache,
            })
                .then((status) => {
                    const isLogin = !!(status && status.isLogin);
                    if (!isLogin && loginSignal) {
                        // Landing from login redirect: keep optimistic signed-in chrome if
                        // the login page already wrote a snapshot (cookie/query race).
                        const optimistic = this.readFrontendSessionCache();
                        if (optimistic && optimistic.isLogin) {
                            this.frontendUser = optimistic.user || null;
                            targets.forEach((root) => {
                                this.applyHeaderSignedIn(root, this.frontendUser);
                            });
                            this.startOnlineKeepalive();
                            this._authRefreshHandled = true;
                            this.clearAuthPending();
                            try { window.__welineSocialQuickBooted = true; } catch (_e) { /* ignore */ }
                            this.refreshAccountMenuSignals().catch(() => {});
                            if (this.leaveAuthPageIfSignedIn(true)) {
                                return {
                                    synced: true,
                                    refreshed: true,
                                    fromCache: true,
                                    isLogin: true,
                                    redirected: true,
                                    reason: 'optimistic_keep_login_signal',
                                };
                            }
                            return {
                                synced: true,
                                refreshed: true,
                                fromCache: true,
                                isLogin: true,
                                reason: 'optimistic_keep_login_signal',
                            };
                        }
                    }
                    targets.forEach((root) => {
                        if (isLogin) {
                            this.applyHeaderSignedIn(root, status.user || null);
                        } else {
                            this.applyHeaderGuest(root);
                        }
                    });
                    if (isLogin) {
                        this.clearAuthPending();
                        this.startOnlineKeepalive();
                        this._authRefreshHandled = true;
                        this.refreshAccountMenuSignals();
                        try { window.__welineSocialQuickBooted = true; } catch (_e) { /* ignore */ }
                        if (this.leaveAuthPageIfSignedIn(true)) {
                            return { synced: true, refreshed: true, fromCache: false, isLogin: true, redirected: true };
                        }
                    } else if (hasSignal || authPending) {
                        this.clearAuthPending();
                        this.stopOnlineKeepalive();
                        this._authRefreshHandled = true;
                        this.clearAccountMenuSignals();
                        this.maybeStartSocialQuickPrompt();
                    } else {
                        // Auth page + server says guest: drop any stale signed-in chrome cache.
                        if (onAuthPage) {
                            this.clearFrontendSessionCache();
                            this.frontendUser = null;
                            this.stopOnlineKeepalive();
                        }
                        this.clearAccountMenuSignals();
                        this.maybeStartSocialQuickPrompt();
                    }
                    return { synced: true, refreshed: true, fromCache: false, isLogin };
                })
                .catch((error) => {
                    console.warn('[WelineApi.Account] header chrome sync failed:', error);
                    this._authRefreshHandled = false;
                    const optimistic = this.readFrontendSessionCache();
                    if ((loginSignal || authPending) && optimistic && optimistic.isLogin) {
                        this.frontendUser = optimistic.user || null;
                        targets.forEach((root) => {
                            this.applyHeaderSignedIn(root, this.frontendUser);
                        });
                        this.startOnlineKeepalive();
                        this.clearAuthPending();
                        return {
                            synced: true,
                            refreshed: false,
                            fromCache: true,
                            isLogin: true,
                            reason: 'optimistic_after_error',
                            error,
                        };
                    }
                    return { synced: false, refreshed: false, error };
                })
                .finally(() => {
                    this._authRefreshInFlight = null;
                    if (hasSignal) {
                        this.stripAuthRefreshSignal();
                    }
                });

            return this._authRefreshInFlight;
        }

        headerMenuAuthMismatch(root, desiredState) {
            if (!(root instanceof Element)) {
                return false;
            }
            const mode = desiredState === 'signed-in' ? 'signed-in' : 'guest';
            const items = root.querySelectorAll('[data-account-menu-auth]');
            if (!items.length) {
                return false;
            }
            for (let i = 0; i < items.length; i += 1) {
                const el = items[i];
                const forState = el.getAttribute('data-account-menu-auth');
                const shouldHide = forState !== mode;
                if (el.hidden !== shouldHide) {
                    return true;
                }
            }
            return false;
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

        isLogoutAuthSignal() {
            try {
                return new URLSearchParams(window.location.search || '').get('w_auth') === '0';
            } catch (_error) {
                return false;
            }
        }

        isLoginAuthSignal() {
            try {
                return new URLSearchParams(window.location.search || '').get('w_auth') === '1';
            } catch (_error) {
                return false;
            }
        }

        /**
         * Login / register / forgot-password routes: chrome must match server session.
         */
        isStorefrontAuthPage() {
            try {
                const path = String(window.location.pathname || '').toLowerCase();
                // Keep these literals for contracts / grep: customer/account/login, customer/account/register
                return path.includes('/customer/account/login')
                    || path.includes('/customer/account/register')
                    || path.includes('/customer/account/forgot-password')
                    || path.includes('/customer/account/forgot_password');
            } catch (_error) {
                return false;
            }
        }

        resolveSignedInLeaveUrl() {
            const fallback = '/customer/account';
            try {
                const params = new URLSearchParams(window.location.search || '');
                const keys = ['redirect_url', 'redirect', 'return_url', 'referer'];
                for (let i = 0; i < keys.length; i += 1) {
                    const raw = String(params.get(keys[i]) || '').trim();
                    if (!raw) {
                        continue;
                    }
                    let candidate = raw;
                    try {
                        candidate = decodeURIComponent(raw);
                    } catch (_e) { /* keep raw */ }
                    candidate = String(candidate || '').trim();
                    if (!candidate) {
                        continue;
                    }
                    // Absolute off-site URLs are rejected; keep same-origin absolute paths.
                    if (/^https?:\/\//i.test(candidate)) {
                        try {
                            const abs = new URL(candidate, window.location.origin);
                            if (abs.origin !== window.location.origin) {
                                continue;
                            }
                            candidate = abs.pathname + abs.search + abs.hash;
                        } catch (_e2) {
                            continue;
                        }
                    }
                    if (!candidate.startsWith('/')) {
                        candidate = '/' + candidate.replace(/^\/+/, '');
                    }
                    const lower = candidate.toLowerCase();
                    if (/\/customer\/account\/(login|register|forgot-password|forgot_password)(\/|\?|$)/.test(lower)) {
                        continue;
                    }
                    return candidate;
                }
            } catch (_error) {
                // fall through
            }
            return fallback;
        }

        /**
         * Already signed in on an auth page → leave (replace, so Back does not re-open the form).
         */
        leaveAuthPageIfSignedIn(isLogin) {
            if (!isLogin || !this.isStorefrontAuthPage()) {
                return false;
            }
            if (this._authPageLeaveScheduled) {
                return true;
            }
            this._authPageLeaveScheduled = true;
            try {
                window.location.replace(this.resolveSignedInLeaveUrl());
            } catch (_error) {
                this._authPageLeaveScheduled = false;
                return false;
            }
            return true;
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
            const avatarFallback = root.querySelector('[data-account-avatar-fallback]');
            const avatarUrl = this.normalizeAccountAvatarUrl(
                user && user.avatar ? String(user.avatar).trim() : '',
            );
            if (avatar instanceof HTMLImageElement) {
                if (avatarUrl !== '') {
                    this.applyAccountAvatar(avatar, avatarFallback, avatarUrl, username || '');
                } else {
                    this.clearAccountAvatar(avatar, avatarFallback);
                }
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
            const toggle = (el) => {
                const forState = el.getAttribute('data-account-menu-auth');
                const hide = forState !== mode;
                el.hidden = hide;
                el.classList.toggle('is-account-menu-hidden', hide);
                el.setAttribute('aria-hidden', hide ? 'true' : 'false');
            };
            root.querySelectorAll('[data-account-menu-auth]').forEach(toggle);
            // Mobile "我的" menu also hosts the same hook outside the widget root.
            document.querySelectorAll('.header-my-menu [data-account-menu-auth]').forEach(toggle);
        }

        /**
         * Only accept absolute http(s) avatar URLs. Relative Google photo tokens
         * (e.g. "ACg8…=s96-c") resolve against the storefront origin and make WLS
         * return a multi-MB HTML 404 that stalls the browser connection pool.
         */
        normalizeAccountAvatarUrl(raw) {
            const value = String(raw || '').trim();
            if (value === '') {
                return '';
            }
            try {
                const parsed = new URL(value, window.location.origin);
                if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                    return '';
                }
                if (parsed.origin === window.location.origin) {
                    const path = parsed.pathname || '';
                    // Google avatar id /a/ACg… without host must never hit this site.
                    if (/^\/(?:a\/)?ACg/i.test(path) || /^\/ACg/i.test(path)) {
                        return '';
                    }
                    // Same-origin avatars must look like real media paths.
                    if (!/\.(png|jpe?g|gif|webp|svg|avif)(?:$|\?)/i.test(path)
                        && !path.includes('/media/')
                        && !path.includes('/upload')
                        && !path.includes('/avatar')) {
                        return '';
                    }
                }
                return parsed.href;
            } catch (_error) {
                return '';
            }
        }

        clearAccountAvatar(avatar, avatarFallback) {
            if (avatar instanceof HTMLImageElement) {
                avatar.onload = null;
                avatar.onerror = null;
                avatar.removeAttribute('src');
                avatar.hidden = true;
            }
            if (avatarFallback instanceof HTMLElement) {
                avatarFallback.hidden = false;
            }
        }

        applyAccountAvatar(avatar, avatarFallback, avatarUrl, altText) {
            if (!(avatar instanceof HTMLImageElement)) {
                return;
            }
            const url = this.normalizeAccountAvatarUrl(avatarUrl);
            if (url === '') {
                this.clearAccountAvatar(avatar, avatarFallback);
                return;
            }
            let failSafe = 0;
            avatar.onload = () => {
                window.clearTimeout(failSafe);
                avatar.hidden = false;
                if (avatarFallback instanceof HTMLElement) {
                    avatarFallback.hidden = true;
                }
            };
            avatar.onerror = () => {
                window.clearTimeout(failSafe);
                this.clearAccountAvatar(avatar, avatarFallback);
            };
            avatar.setAttribute('alt', altText || '');
            avatar.setAttribute('referrerpolicy', 'no-referrer');
            avatar.setAttribute('decoding', 'async');
            avatar.setAttribute('loading', 'lazy');
            // Hide until load succeeds so a hanging remote host does not flash broken chrome.
            avatar.hidden = true;
            if (avatarFallback instanceof HTMLElement) {
                avatarFallback.hidden = false;
            }
            // Remote avatar hosts (e.g. googleusercontent) can stall forever in some networks;
            // fail soft so same-origin module scripts are not left pending behind a dead connection.
            failSafe = window.setTimeout(() => {
                if (avatar.hidden) {
                    this.clearAccountAvatar(avatar, avatarFallback);
                }
            }, 2500);
            avatar.setAttribute('src', url);
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
            const avatar = root.querySelector('[data-account-avatar]');
            const avatarFallback = root.querySelector('[data-account-avatar-fallback]');
            this.clearAccountAvatar(avatar, avatarFallback);
            this.applyHeaderMenuAuth(root, 'guest');
        }

        /**
         * Guest-only social One Tap / FedCM. Config comes from account.socialQuickPrompt
         * (dynamic); Theme layouts must not SSR the bootstrap.
         * options.host: HTMLElement with data-weline-mount="customer/social-quick"
         */
        maybeStartSocialQuickPrompt(options = {}) {
            const force = !!(options && options.force);
            const host = options && options.host ? options.host : null;
            if (force && !host) {
                try { window.__welineSocialQuickBooted = false; } catch (_forceBoot) { /* ignore */ }
                this._socialQuickInFlight = null;
            }
            if (this._socialQuickInFlight && !host) {
                return this._socialQuickInFlight;
            }
            // A stale cached social-quick boot may set the flag without visible fallback UI.
            if (window.__welineSocialQuickBooted
                && !(window.WelineSocialQuick && window.WelineSocialQuick.__supportsVisibleFallback)
                && !document.querySelector('[data-w-social-quick-fallback-ui]')) {
                try { window.__welineSocialQuickBooted = false; } catch (_e) { /* ignore */ }
            }
            if (window.__welineSocialQuickBooted && !force && !host) {
                if (window.WelineSocialQuick && typeof window.WelineSocialQuick.reveal === 'function') {
                    try { window.WelineSocialQuick.reveal(); } catch (_reveal) { /* ignore */ }
                }
                return Promise.resolve({ started: false, reason: 'already_booted' });
            }
            // Login/register pages already render Logo OAuth — skip global chooser.
            if (document.querySelector('[data-w-component="account-social-login"]') && !host) {
                try { window.__welineSocialQuickBooted = true; } catch (_s) { /* ignore */ }
                return Promise.resolve({ started: false, reason: 'suppressed_login_widget' });
            }
            if (host
                && !force
                && host.getAttribute('data-weline-mount-state') === 'ready'
                && host.querySelector('[data-provider]')) {
                if (window.WelineSocialQuick && typeof window.WelineSocialQuick.syncFloatingVisibility === 'function') {
                    window.WelineSocialQuick.syncFloatingVisibility();
                }
                return Promise.resolve({ started: true, host: true, reason: 'already_mounted' });
            }
            const run = Promise.resolve()
                .then(async () => {
                    const returnUrl = (window.location.pathname || '/')
                        + (window.location.search || '')
                        + (window.location.hash || '');
                    const result = await this.call('socialQuickPrompt', { return_url: returnUrl });
                    const enabled = !!(result && (result.enabled === true
                        || (result.data && result.data.enabled === true)));
                    const loggedIn = !!(result && (result.logged_in || result.isLogin
                        || (result.data && (result.data.logged_in || result.data.isLogin))));
                    if (!enabled || loggedIn) {
                        if (loggedIn) {
                            window.__welineSocialQuickBooted = true;
                        }
                        if (host) {
                            host.setAttribute('data-weline-mount-state', 'empty');
                            host.innerHTML = '';
                        }
                        return { started: false, enabled: false };
                    }
                    const bootstrap = (result && (result.bootstrap || (result.data && result.data.bootstrap))) || null;
                    if (!bootstrap || typeof bootstrap !== 'object') {
                        if (host) {
                            host.setAttribute('data-weline-mount-state', 'empty');
                        }
                        return { started: false, reason: 'no_bootstrap' };
                    }
                    if (window.Weline && typeof window.Weline.load === 'function') {
                        try {
                            await window.Weline.load('customerSocialQuick');
                        } catch (loadError) {
                            console.debug('[WelineApi.Account] customerSocialQuick load skipped:', loadError);
                        }
                    }
                    if (!window.WelineSocialQuick
                        || typeof window.WelineSocialQuick.start !== 'function'
                        || !window.WelineSocialQuick.__supportsVisibleFallback) {
                        await this.loadSocialQuickScriptFallback(true);
                    }
                    const starter = window.WelineSocialQuick;
                    if (!starter) {
                        return { started: false, reason: 'module_missing' };
                    }
                    if (host && typeof starter.mount === 'function') {
                        const ok = starter.mount(host, bootstrap, { force: true });
                        return { started: !!ok, host: true };
                    }
                    if (typeof starter.start === 'function') {
                        const ok = starter.start(bootstrap, force ? { force: true } : undefined);
                        return { started: !!ok };
                    }
                    return { started: false, reason: 'module_missing' };
                })
                .catch((error) => {
                    console.debug('[WelineApi.Account] social quick prompt skipped:', error);
                    if (host) {
                        try { host.setAttribute('data-weline-mount-state', 'error'); } catch (_st) { /* ignore */ }
                    }
                    return { started: false, error };
                });
            if (!host) {
                this._socialQuickInFlight = run.finally(() => {
                    this._socialQuickInFlight = null;
                });
                return this._socialQuickInFlight;
            }
            return run;
        }

        /**
         * Prefer framework Weline.mount.scan; legacy self-scan only if mount API missing.
         * Provider registration is registerCustomerMountProviders() — Account does not own orchestration.
         */
        scanMounts(options = {}) {
            if (window.Weline && window.Weline.mount && typeof window.Weline.mount.scan === 'function') {
                return window.Weline.mount.scan(options || {});
            }
            const force = !!(options && options.force);
            const tasks = [];
            let hosts = [];
            try {
                if (window.WelineSocialQuick && typeof window.WelineSocialQuick.scanMountHosts === 'function') {
                    hosts = window.WelineSocialQuick.scanMountHosts({ force }) || [];
                } else {
                    hosts = Array.from(document.querySelectorAll('[data-weline-mount="customer/social-quick"]'));
                }
            } catch (_scan) {
                hosts = [];
            }
            hosts.forEach((host) => {
                tasks.push(this.maybeStartSocialQuickPrompt({ force: true, host }));
            });
            if (window.WelineSocialQuick && typeof window.WelineSocialQuick.syncFloatingVisibility === 'function') {
                try { window.WelineSocialQuick.syncFloatingVisibility(); } catch (_sync) { /* ignore */ }
            }
            return Promise.all(tasks);
        }

        loadSocialQuickScriptFallback(forceReload = false) {
            return new Promise((resolve) => {
                if (!forceReload
                    && window.WelineSocialQuick
                    && typeof window.WelineSocialQuick.start === 'function'
                    && window.WelineSocialQuick.__supportsVisibleFallback) {
                    resolve(true);
                    return;
                }
                const existing = document.querySelector('script[data-w-social-quick-fallback="1"]');
                if (existing && !forceReload) {
                    existing.addEventListener('load', () => resolve(!!(window.WelineSocialQuick && window.WelineSocialQuick.__supportsVisibleFallback)));
                    existing.addEventListener('error', () => resolve(false));
                    return;
                }
                if (existing && forceReload) {
                    try { existing.remove(); } catch (_e) { /* ignore */ }
                }
                // Allow re-exec of the IIFE even if a prior stale boot set the flag.
                try { window.__welineSocialQuickBooted = false; } catch (_b) { /* ignore */ }
                try { window.WelineSocialQuick = null; } catch (_w) { /* ignore */ }
                const base = (window.WelineConfig && window.WelineConfig.baseUrl)
                    || (document.querySelector('[data-weline-runtime-config]') && (() => {
                        try {
                            return JSON.parse(document.querySelector('[data-weline-runtime-config]').textContent).baseUrl;
                        } catch (_e) {
                            return '';
                        }
                    })())
                    || (window.location.origin + '/');
                const s = document.createElement('script');
                s.async = true;
                s.setAttribute('data-w-social-quick-fallback', '1');
                s.src = String(base).replace(/\/?$/, '/')
                    + 'Weline/Customer/view/statics/js/account-social-quick.js?v='
                    + String(Date.now());
                s.onload = () => resolve(!!(window.WelineSocialQuick
                    && window.WelineSocialQuick.start
                    && window.WelineSocialQuick.__supportsVisibleFallback));
                s.onerror = () => resolve(false);
                document.head.appendChild(s);
            });
        }

        /**
         * Paint unread badges from account.menuSignals. Counts are never SSR'd.
         */
        paintAccountMenuSignals(signals) {
            const payload = signals && typeof signals === 'object' ? signals : {};
            const byCode = payload.by_code && typeof payload.by_code === 'object' ? payload.by_code : {};
            const total = Math.max(0, Number(payload.total) || 0);
            const formatCount = (n) => {
                const count = Math.max(0, Number(n) || 0);
                if (count <= 0) {
                    return '';
                }
                return count > 99 ? '99+' : String(count);
            };

            document.querySelectorAll('[data-account-menu-signal-total]').forEach((node) => {
                const label = formatCount(total);
                node.textContent = label;
                node.hidden = label === '';
                node.setAttribute('aria-hidden', label === '' ? 'true' : 'false');
            });

            document.querySelectorAll('[data-account-menu-signal]').forEach((node) => {
                const code = String(node.getAttribute('data-account-menu-signal') || '').trim().toLowerCase();
                let count = 0;
                if (code !== '') {
                    count = Number(byCode[code] || 0);
                    if (!count && code.indexOf('-') !== -1) {
                        count = Number(byCode[code.replace(/-/g, '.')] || 0);
                    }
                }
                let badge = node.matches('.account-menu-signal-badge')
                    ? node
                    : node.querySelector('.account-menu-signal-badge,[data-account-menu-signal-badge]');
                if (!badge && !node.matches('.account-menu-signal-badge')) {
                    badge = document.createElement('span');
                    badge.className = 'account-menu-signal-badge';
                    badge.setAttribute('data-account-menu-signal-badge', '1');
                    badge.hidden = true;
                    const labelHost = node.querySelector('.account-hook-nav-link__text strong, strong') || node;
                    labelHost.appendChild(badge);
                }
                if (!badge) {
                    return;
                }
                const label = formatCount(count);
                badge.textContent = label;
                badge.hidden = label === '';
                badge.setAttribute('aria-hidden', label === '' ? 'true' : 'false');
            });
        }

        clearAccountMenuSignals() {
            this.paintAccountMenuSignals({ total: 0, by_code: {} });
        }

        refreshAccountMenuSignals() {
            return this.call('menuSignals')
                .then((result) => {
                    const data = (result && result.data && typeof result.data === 'object')
                        ? result.data
                        : (result || {});
                    const byCode = data.by_code && typeof data.by_code === 'object' ? data.by_code : {};
                    const signals = {
                        total: data.total,
                        by_code: byCode,
                    };
                    this.paintAccountMenuSignals(signals);
                    const cached = this.readFrontendSessionCache();
                    if (cached && cached.isLogin) {
                        this.writeFrontendSessionCache(
                            { isLogin: true, user: cached.user || this.frontendUser },
                            signals
                        );
                    }
                    return { total: Number(data.total) || 0, by_code: byCode };
                })
                .catch((error) => {
                    console.warn('[WelineApi.Account] menuSignals failed:', error);
                    this.clearAccountMenuSignals();
                    return { total: 0, by_code: {} };
                });
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
        checkFrontendUserLogin: (options) => accountManager.checkFrontendUserLogin(options || {}),
        frontendUserLogin: (username, password, rememberDuration) => accountManager.frontendUserLogin(username, password, rememberDuration),
        frontendUserRegister: (payload) => accountManager.frontendUserRegister(payload),
        frontendUserLogout: () => accountManager.frontendUserLogout(),
        handleAuthRefreshSignal: () => accountManager.handleAuthRefreshSignal(),
        syncHeaderAccountChrome: (options) => accountManager.syncHeaderAccountChrome(options || {}),
        maybeStartSocialQuickPrompt: (...args) => accountManager.maybeStartSocialQuickPrompt(...args),
        scanMounts: (...args) => accountManager.scanMounts(...args),
        refreshAccountMenuSignals: () => accountManager.refreshAccountMenuSignals(),
        paintAccountMenuSignals: (signals) => accountManager.paintAccountMenuSignals(signals || {}),
        clearAccountMenuSignals: () => accountManager.clearAccountMenuSignals(),
        startOnlineKeepalive: () => accountManager.startOnlineKeepalive(),
        stopOnlineKeepalive: () => accountManager.stopOnlineKeepalive(),
        isOnlineKeepaliveActive: () => accountManager.isOnlineKeepaliveActive(),
        getFrontendUser: () => accountManager.getFrontendUser(),
        readFrontendSessionCache: () => accountManager.readFrontendSessionCache(),
        clearFrontendSessionCache: () => accountManager.clearFrontendSessionCache(),
        beginAuthBoundary: (kind) => accountManager.beginAuthBoundary(kind || 'switch'),
        markAuthPending: () => accountManager.markAuthPending(),
        clearAuthPending: () => accountManager.clearAuthPending(),
        hasAuthPending: () => accountManager.hasAuthPending(),
        applyFrontendProfileUpdate: (user) => accountManager.applyFrontendProfileUpdate(user || null),
        applyFrontendSessionSnapshot: (cache) => accountManager.applyFrontendSessionSnapshot(cache || null),
        isFrontendSessionCacheFresh: (cache) => accountManager.isFrontendSessionCacheFresh(cache),
        resolveUserIdentity: (user) => accountManager.resolveUserIdentity(user),
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

    // Always provide when Weline.mount exists. provide() only scheduleScan if hosts exist.
    // Do not gate on hasCustomerHosts — early account load before SSR hosts caused no_provider races.
    (function registerCustomerMountProviders() {
        const register = () => {
            if (!window.Weline || !window.Weline.mount || typeof window.Weline.mount.provide !== 'function') {
                return false;
            }
            window.Weline.mount.provide('customer/social-quick', (host, ctx) => {
                return accountManager.maybeStartSocialQuickPrompt({
                    force: !!(ctx && ctx.force),
                    host: host,
                });
            });
            window.Weline.mount.provide('customer/login-panel', (host, ctx) => {
                const force = !!(ctx && ctx.force);
                const run = async () => {
                    if (window.Weline && typeof window.Weline.load === 'function') {
                        try {
                            await window.Weline.load('customerLoginPanel');
                        } catch (_load) { /* ignore */ }
                    }
                    const panel = window.WelineLoginPanel;
                    if (!panel || typeof panel.mount !== 'function') {
                        try { host.setAttribute('data-weline-mount-state', 'error'); } catch (_st) { /* ignore */ }
                        return { started: false, reason: 'panel_module_missing' };
                    }
                    return panel.mount(host, { force: force });
                };
                return run();
            });
            return true;
        };
        const boot = () => {
            if (register()) {
                return;
            }
            document.addEventListener('weline:mount:scan', () => {
                register();
            });
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => { register(); }, { once: true });
            } else {
                setTimeout(() => { register(); }, 0);
            }
        };
        boot();
    })();

    // Header account widget declares data-weline-load="api,account": when this module
    // arrives, reconcile header shell from session cache (network only near renew / miss).
    (function bootstrapHeaderAuthRefresh() {
        const run = () => {
            if (!document.querySelector('[data-w-header-account="1"]')) {
                return;
            }
            // Theme visual editor / preview canvas: force network reconcile so header
            // account chrome matches current storefront session (guest or signed-in).
            const editorPreview = /(?:[?&]editor_mode=1|[?&]shell=theme-editor\b)/.test(
                String(location.search || '')
            ) || document.documentElement.dataset.wEditorPreview === 'true'
                || document.documentElement.dataset.wEditorInteraction === 'edit'
                || document.documentElement.dataset.wEditorInteraction === 'preview';
            Promise.resolve(accountManager.syncHeaderAccountChrome({
                force: editorPreview,
                fromAuthSignal: true,
            })).catch(() => {});
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            run();
        }
    })();

    // Immediate chrome refresh after in-page login/register (no full reload).
    window.addEventListener('weline:account:frontend:login', (event) => {
        const user = event && event.detail ? event.detail.user : null;
        try { window.__welineSocialQuickBooted = true; } catch (_e) { /* ignore */ }
        // Replace any previous account chrome — do not keep old name/avatar/signals.
        accountManager.clearAccountMenuSignals();
        document.querySelectorAll('[data-w-header-account="1"]').forEach((root) => {
            accountManager.applyHeaderSignedIn(root, user || accountManager.frontendUser);
        });
        accountManager.writeFrontendSessionCache({
            isLogin: true,
            user: user || accountManager.frontendUser,
        });
        accountManager.markAuthPending();
        accountManager.startOnlineKeepalive();
        accountManager.refreshAccountMenuSignals().catch(() => {});
    });
    window.addEventListener('weline:account:frontend:logout', () => {
        try { window.__welineSocialQuickBooted = false; } catch (_e) { /* ignore */ }
        accountManager.clearAuthPending();
        accountManager.beginAuthBoundary('logout');
        accountManager.maybeStartSocialQuickPrompt();
    });

    // Keep storefront Session TTL sliding while the browser stays online.
    (function bootstrapOnlineKeepalive() {
        const startFromSignedInShell = () => {
            document.querySelectorAll('[data-w-header-account="1"]').forEach((root) => {
                if (root.getAttribute('data-auth-state') !== 'signed-in') {
                    return;
                }
                if (accountManager.headerMenuAuthMismatch(root, 'signed-in')) {
                    accountManager.applyHeaderMenuAuth(root, 'signed-in');
                }
            });
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
