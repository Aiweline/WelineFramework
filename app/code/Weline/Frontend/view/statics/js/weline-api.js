/**
 * Weline Api Module
 *
 * Browser business requests are only allowed through:
 * theme.js -> Weline.Api -> worker -> /api/framework/query-bin.
 */
(function (window) {
    'use strict';

    const RESERVED_METHODS = new Set([
        'then',
        'catch',
        'finally',
        '__proto__',
        'prototype',
        'constructor',
        'toString',
        'valueOf',
    ]);

    const mergeApiConfig = () => {
        const merged = {};
        [
            window.WelineApiConfig,
            window.__WelineThemeConfig && window.__WelineThemeConfig.api,
            window.Weline && window.Weline.config && window.Weline.config.api,
        ].forEach((source) => {
            if (source && typeof source === 'object') {
                Object.assign(merged, source);
            }
        });
        return merged;
    };

    const getRuntimeConfig = () => {
        if (window.Weline && window.Weline.config) {
            return window.Weline.config;
        }
        if (window.__WelineThemeConfig) {
            return window.__WelineThemeConfig;
        }
        return {};
    };

    const LOCALE_PATH_PATTERN = /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/i;

    const normalizeLangCode = (value) => String(value || '').trim().replace(/-/g, '_');

    const detectPathLanguage = (pathname) => {
        const parts = String(pathname || '/').split('/').filter(Boolean);
        for (let i = 0; i < parts.length; i += 1) {
            if (LOCALE_PATH_PATTERN.test(parts[i])) {
                return normalizeLangCode(parts[i]);
            }
        }
        return '';
    };

    const readDocumentLanguage = () => {
        try {
            const el = document.documentElement;
            if (!el) {
                return '';
            }
            const raw = (typeof el.getAttribute === 'function')
                ? (el.getAttribute('data-lang') || el.getAttribute('lang') || '')
                : '';
            return normalizeLangCode(raw || el.lang || '');
        } catch (_error) {
            return '';
        }
    };

    const readQueryLanguage = () => {
        try {
            const urlParams = new URLSearchParams((window.location && window.location.search) || '');
            const candidates = [urlParams.get('locale'), urlParams.get('locale_code'), urlParams.get('lang')];
            for (let i = 0; i < candidates.length; i += 1) {
                const raw = String(candidates[i] || '').trim();
                if (!raw || raw.toLowerCase() === 'default') {
                    continue;
                }
                return normalizeLangCode(raw);
            }
        } catch (_error) {
        }
        return '';
    };

    // Path/query language: never read WELINE_USER_LANG cookie for QueryBin workers.
    const resolveCurrentLanguage = (fallback) => detectPathLanguage(window.location && window.location.pathname)
        || readQueryLanguage()
        || readDocumentLanguage()
        || normalizeLangCode(fallback || '')
        || '';

    /**
     * Ensure long-lived HttpOnly Scope stamp (Cookie). Not a one-shot opaque bridge.
     * Timeout name kept for tip-contract compatibility; tip waits for stamp/handshake,
     * not for an opaque bootstrap_id.
     */
    // Cold DetectWebsite punch can exceed 12s; keep headroom so Abort ≠ perpetual tip loop.
    const SCOPE_HOLE_PUNCH_TIMEOUT_MS = 20000;
    /** Soft tip cooldown while callers wait for Scope stamp (avoid spam on filter clicks). */
    const SCOPE_WAIT_TIP_COOLDOWN_MS = 4500;
    /** Delay before tip so fast stamp ensure / Cookie re-verify does not flash. */
    const SCOPE_WAIT_TIP_DELAY_MS = 280;
    /** Tab memory: long Scope Cookie / no-stamp decision for this host+canonical (HttpOnly invisible to JS). */
    const SCOPE_STAMP_MEMORY_KEY = 'weline.api.scopeStampKey';
    /** Refresh / re-punch this many ms before Cookie expires_at (unix seconds → ms). */
    const SCOPE_STAMP_SKEW_MS = 60000;
    /**
     * Same-page memory: Scope already ensured (HttpOnly Cookie cannot be read from JS).
     * Real visa must pair with expiresAt. noStamp decision is tab-scoped (not a Cookie TTL).
     */
    let scopeStampEnsuredKey = '';
    let scopeStampEnsuredExpiresAt = 0;
    let scopeStampNoStampEnsured = false;
    let scopeStampInFlight = null;
    let scopeStampRenewTimer = null;
    let scopeStampVisibilityBound = false;
    let scopeWaitTipAt = 0;
    let scopeWaitTipTimer = null;

    /**
     * @returns {{key:string,expiresAt:number,noStamp?:boolean}|null}
     *   expiresAt = unix seconds; 0 = unknown/legacy/noStamp
     */
    const readRememberedScopeStamp = () => {
        try {
            const raw = String(sessionStorage.getItem(SCOPE_STAMP_MEMORY_KEY) || '');
            if (raw === '') {
                return null;
            }
            // Legacy plain key (no expiry) — force re-ensure so Cookie + TTL stay honest.
            if (raw.charAt(0) !== '{') {
                return { key: raw, expiresAt: 0 };
            }
            const parsed = JSON.parse(raw);
            const key = String((parsed && parsed.key) || '').trim();
            if (key === '') {
                return null;
            }
            const noStamp = !!(parsed && parsed.noStamp);
            return {
                key,
                expiresAt: Math.max(0, Number((parsed && parsed.expiresAt) || 0) || 0),
                noStamp: noStamp,
            };
        } catch (_storageError) {
            return null;
        }
    };

    const clearScopeStampRenewSchedule = () => {
        if (scopeStampRenewTimer) {
            window.clearTimeout(scopeStampRenewTimer);
            scopeStampRenewTimer = null;
        }
    };

    /**
     * Proactive renew before Cookie expires_at (skew). HttpOnly invisible — timer uses
     * server-returned expires_at only. Failure path still recovers via recoverScopeStamp.
     */
    const scheduleScopeStampRenewal = (stampKey, expiresAtUnix) => {
        clearScopeStampRenewSchedule();
        const expiresAt = Math.max(0, Number(expiresAtUnix) || 0);
        if (expiresAt <= 0 || stampKey === '') {
            return;
        }
        const renewAtMs = (expiresAt * 1000) - SCOPE_STAMP_SKEW_MS;
        const delayMs = Math.max(1000, renewAtMs - Date.now());
        // Cap so a tab left open for days still re-arms after the first wake (visibility).
        const cappedDelayMs = Math.min(delayMs, 6 * 60 * 60 * 1000);
        scopeStampRenewTimer = window.setTimeout(() => {
            scopeStampRenewTimer = null;
            const remembered = readRememberedScopeStamp();
            if (!remembered || remembered.key !== stampKey) {
                return;
            }
            const ids = (window.Weline && window.Weline.Api && typeof window.Weline.Api.getClient === 'function')
                ? (window.Weline.Api.getClient().config || {})
                : {};
            if (pageScopeSessionKey(ids) !== stampKey) {
                return;
            }
            // Drop optimistic memory so ensureScopeStamp POSTs again before Cookie dies.
            forgetScopeStampKey();
            ensureScopeStamp(ids).catch(() => {
                /* renew is best-effort; QueryBin recover still covers miss */
            });
        }, cappedDelayMs);
    };

    const rememberScopeStampKey = (stampKey, expiresAtUnix) => {
        const key = String(stampKey || '');
        if (key === '') {
            return;
        }
        // Require honest server expires_at — never invent a long soft TTL that outlives Cookie.
        const expiresAt = Math.max(0, Number(expiresAtUnix) || 0);
        if (expiresAt <= 0) {
            return;
        }
        scopeStampEnsuredKey = key;
        scopeStampEnsuredExpiresAt = expiresAt;
        scopeStampNoStampEnsured = false;
        try {
            sessionStorage.setItem(SCOPE_STAMP_MEMORY_KEY, JSON.stringify({ key, expiresAt }));
        } catch (_storageError) {
            /* private mode — page memory still works */
        }
        scheduleScopeStampRenewal(key, expiresAt);
    };

    /**
     * 204 / rollout-off: server said no long Cookie visa is required.
     * Remember the decision for this tab+Scope so navigations do not re-POST.
     * This is NOT a forged Cookie soft-TTL visa.
     */
    const rememberNoStampDecision = (stampKey) => {
        const key = String(stampKey || '');
        if (key === '') {
            return;
        }
        scopeStampEnsuredKey = key;
        scopeStampEnsuredExpiresAt = 0;
        scopeStampNoStampEnsured = true;
        clearScopeStampRenewSchedule();
        try {
            sessionStorage.setItem(SCOPE_STAMP_MEMORY_KEY, JSON.stringify({ key, noStamp: true, expiresAt: 0 }));
        } catch (_storageError) {
            /* private mode — page memory still works */
        }
    };

    const forgetScopeStampKey = () => {
        scopeStampEnsuredKey = '';
        scopeStampEnsuredExpiresAt = 0;
        scopeStampNoStampEnsured = false;
        clearScopeStampRenewSchedule();
        try {
            sessionStorage.removeItem(SCOPE_STAMP_MEMORY_KEY);
        } catch (_storageError) {
            /* ignore */
        }
    };

    const isScopeStampFresh = (expiresAtUnix) => {
        const expiresAt = Math.max(0, Number(expiresAtUnix) || 0);
        if (!(expiresAt > 0)) {
            return false;
        }
        return (expiresAt * 1000) > (Date.now() + SCOPE_STAMP_SKEW_MS);
    };

    const isScopeStampAlreadyEnsured = (ids) => {
        const stampKey = pageScopeSessionKey(ids || {});
        // Tab decision: rollout-off / no visa required — skip POST until forget/recover.
        if (scopeStampEnsuredKey === stampKey && scopeStampNoStampEnsured) {
            return true;
        }
        // In-page key alone is not enough — must still honor expiresAt + skew.
        if (scopeStampEnsuredKey === stampKey && isScopeStampFresh(scopeStampEnsuredExpiresAt)) {
            return true;
        }
        if (scopeStampEnsuredKey === stampKey && !scopeStampNoStampEnsured && !isScopeStampFresh(scopeStampEnsuredExpiresAt)) {
            scopeStampEnsuredKey = '';
            scopeStampEnsuredExpiresAt = 0;
        }
        const remembered = readRememberedScopeStamp();
        if (!remembered || remembered.key !== stampKey) {
            return false;
        }
        if (remembered.noStamp === true) {
            scopeStampEnsuredKey = stampKey;
            scopeStampEnsuredExpiresAt = 0;
            scopeStampNoStampEnsured = true;
            clearScopeStampRenewSchedule();
            return true;
        }
        // Unknown/legacy expiry → re-punch (Cookie may already be gone).
        if (!isScopeStampFresh(remembered.expiresAt)) {
            return false;
        }
        scopeStampEnsuredKey = stampKey;
        scopeStampEnsuredExpiresAt = remembered.expiresAt;
        scopeStampNoStampEnsured = false;
        scheduleScopeStampRenewal(stampKey, remembered.expiresAt);
        return true;
    };

    /**
     * Tab awake / BFCache restore: re-arm Cookie renew timer only when a visa is
     * already remembered. Never hole-punch here — punch is Worker-session demand.
     */
    const bindScopeStampVisibilityRenew = () => {
        if (scopeStampVisibilityBound || typeof document === 'undefined') {
            return;
        }
        scopeStampVisibilityBound = true;
        const onVisible = () => {
            if (document.visibilityState && document.visibilityState !== 'visible') {
                return;
            }
            const ids = (window.Weline && window.Weline.Api && typeof window.Weline.Api.getClient === 'function')
                ? (window.Weline.Api.getClient().config || {})
                : {};
            // Re-arms scheduleScopeStampRenewal when memory is fresh; no POST if missing.
            isScopeStampAlreadyEnsured(ids);
        };
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('pageshow', (event) => {
            if (event && event.persisted) {
                onVisible();
            }
        });
    };

    /**
     * Stamp / binding expired or missing — re-punch Cookie + re-handshake, then retry.
     * NOT for scope identity conflicts or theme generation drift (those still autoReload).
     */
    const isScopeStampRecoverableError = (error) => {
        const code = String((error && error.code) || '').toLowerCase();
        if (
            code === 'scope_binding_required'
            || code === 'scope_reload_required'
            || code === 'scope_bootstrap_invalid'
            || code === 'worker_scope_binding_expired'
        ) {
            return true;
        }
        const message = String((error && error.message) || '');
        return code === 'auth_error' && /Scope Cookie is required|Scope Cookie is invalid or expired|Worker Scope Cookie/i.test(message);
    };

    /** @deprecated alias — warmup + recover share the recoverable stamp set */
    const isScopeBindingMissingError = isScopeStampRecoverableError;

    /**
     * Mirror ScopeIdentity::fromLayerIds placeholder codes for SharedWorker slot keys.
     * @param {number} websiteId
     * @param {number} storeId
     * @param {number} channelId
     * @returns {string}
     */
    const buildScopeCanonicalFromIds = (websiteId, storeId, channelId) => {
        websiteId = Math.max(0, Number(websiteId) || 0);
        storeId = Math.max(0, Number(storeId) || 0);
        channelId = Math.max(0, Number(channelId) || 0);
        const websiteCode = websiteId === 0 ? 'default' : ('id-' + websiteId);
        if (channelId > 0) {
            const storeCode = storeId === 0 ? 'default' : ('id-' + storeId);
            return ['channel', String(websiteId), websiteCode, storeCode, 'id-' + channelId, 'normal', 'v1'].join('|');
        }
        if (storeId > 0) {
            return ['store', String(websiteId), websiteCode, 'id-' + storeId, '', 'normal', 'v1'].join('|');
        }
        return ['website', String(websiteId), websiteCode, '', '', '', 'v1'].join('|');
    };

    const pageScopeSessionKey = (ids) => {
        const host = String((window.location && window.location.host) || '').trim().toLowerCase();
        const websiteId = Number((ids && ids.website_id) || 0) || 0;
        const storeId = Number((ids && ids.store_id) || 0) || 0;
        const channelId = Number((ids && ids.channel_id) || 0) || 0;
        return host + '\0' + buildScopeCanonicalFromIds(websiteId, storeId, channelId);
    };

    const clearScopeWaitTipSchedule = () => {
        if (scopeWaitTipTimer) {
            window.clearTimeout(scopeWaitTipTimer);
            scopeWaitTipTimer = null;
        }
    };

    /**
     * Anonymous FPC / generated/html: POST scope-bootstrap only when this tab has
     * not yet ensured a long Scope stamp for the current host+canonical.
     * Tab memory (sessionStorage) survives full navigations; in-page memory is hot.
     * Long Token stays HttpOnly Cookie only — memory stores the Scope key, never the Token.
     */
    const ensureScopeStamp = (ids) => {
        const stampKey = pageScopeSessionKey(ids || {});
        // Design: long visa already ensured this tab+Scope → skip POST (not once-per-document only).
        if (isScopeStampAlreadyEnsured(ids)) {
            scopeStampEnsuredKey = stampKey;
            return Promise.resolve(true);
        }
        if (scopeStampInFlight) {
            return scopeStampInFlight;
        }
        const path = String((window.location && window.location.pathname) || '/');
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        let timeoutId = 0;
        if (controller) {
            timeoutId = window.setTimeout(() => {
                try {
                    controller.abort();
                } catch (_abortError) {
                    /* ignore */
                }
            }, SCOPE_HOLE_PUNCH_TIMEOUT_MS);
        }
        const pageLocale = resolveCurrentLanguage(
            (window.Weline && window.Weline.config && (
                window.Weline.config.locale
                || window.Weline.config.currentLang
                || (window.Weline.config.api && window.Weline.config.api.locale)
            )) || ''
        );
        const fetchOptions = {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            // locale: API path has no /en_US segment; server arms tip language from this.
            body: JSON.stringify({ path: path, locale: pageLocale || undefined }),
        };
        if (controller) {
            fetchOptions.signal = controller.signal;
        }
        scopeStampInFlight = fetch('/api/frontend/scope-bootstrap', fetchOptions).then((res) => {
            if (res.status === 204) {
                // Rollout off / no stamp required — not a long Cookie visa.
                // Remember tab decision (not a forged Cookie TTL) so navigations skip re-POST.
                return { ok: true, skipped: true, no_stamp: true };
            }
            if (res.status === 429) {
                const error = new Error('[Weline.Api] Scope stamp ensure rate limited.');
                error.code = 'scope_bootstrap_rate_limited';
                error.status = 429;
                throw error;
            }
            if (!res.ok) {
                const error = new Error('[Weline.Api] Scope stamp ensure failed.');
                error.code = 'scope_bootstrap_hole_punch_failed';
                error.status = res.status;
                throw error;
            }
            return res.json();
        }).then((payload) => {
            // Warm locale tip cache from ScopeBootstrap (mismatch path may lack QueryBin tip).
            try {
                rememberScopeTipsFromPayload(payload);
            } catch (_tipWarmError) {
                // Cache is best-effort.
            }
            // Storefront generation = Scope-published theme version (not system deploy).
            const serverThemeId = payload && payload.theme_published_version_id != null
                ? String(payload.theme_published_version_id).trim()
                : '';
            const pageThemeId = String(
                (window.Weline && window.Weline.config && (
                    window.Weline.config.themePublishedVersionId
                    || window.Weline.config.theme_published_version_id
                )) || ''
            ).trim();
            if (serverThemeId !== '' && pageThemeId !== '' && serverThemeId !== pageThemeId) {
                const error = new Error('[Weline.Api] Theme published generation changed. Reload required.');
                error.code = 'deploy_reload_required';
                error.status = 409;
                error.tip = readScopeTipCache(currentStorefrontLocale(), 'deploy_reload_required');
                try {
                    maybeAutoReloadForScopeConflict(resolveErrorPresentation(error));
                } catch (_reloadError) {
                    // Presentation helpers may not be ready on extreme early boot.
                }
                throw error;
            }
            // 204 / rollout-off: success without a long visa — remember decision, skip re-POST.
            if (payload && payload.no_stamp === true) {
                rememberNoStampDecision(stampKey);
                return true;
            }
            // Success does not require bootstrap_id (opaque bridge retired for storefront).
            if (payload && payload.ok === true) {
                const expiresAt = Number(payload && payload.expires_at) || 0;
                if (!(expiresAt > 0)) {
                    const error = new Error('[Weline.Api] Scope stamp ensure missing expires_at.');
                    error.code = 'scope_bootstrap_hole_punch_failed';
                    error.status = 0;
                    throw error;
                }
                rememberScopeStampKey(stampKey, expiresAt);
                return true;
            }
            const error = new Error('[Weline.Api] Scope stamp ensure returned an unexpected payload.');
            error.code = 'scope_bootstrap_hole_punch_failed';
            error.status = 0;
            throw error;
        }).catch((error) => {
            forgetScopeStampKey();
            if (error && (error.name === 'AbortError' || error.code === 'ABORT_ERR')) {
                const timedOut = new Error('[Weline.Api] Scope stamp ensure timed out.');
                timedOut.code = 'scope_bootstrap_timeout';
                timedOut.status = 0;
                throw timedOut;
            }
            throw error;
        }).finally(() => {
            if (timeoutId) {
                window.clearTimeout(timeoutId);
            }
            scopeStampInFlight = null;
        });
        return scopeStampInFlight;
    };

    // Alias: historical name used by tip/warmup call sites; not an opaque-id path.
    const ensureScopeBootstrapHolePunch = ensureScopeStamp;

    const normalizeLocale = (value) => {
        let locale = String(value || '').trim().replace(/-/g, '_');
        if (!locale) {
            return '';
        }
        if (/^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/.test(locale)) {
            return locale;
        }
        const parts = locale.split('_');
        if (parts.length < 2 || parts.length > 3 || parts[0].length !== 2) {
            return '';
        }
        parts[0] = parts[0].toLowerCase();
        if (parts.length === 2) {
            if (parts[1].length === 2) {
                parts[1] = parts[1].toUpperCase();
            } else if (parts[1].length >= 4) {
                parts[1] = parts[1].charAt(0).toUpperCase() + parts[1].slice(1).toLowerCase();
            }
        } else {
            parts[1] = parts[1].charAt(0).toUpperCase() + parts[1].slice(1).toLowerCase();
            parts[2] = parts[2].toUpperCase();
        }
        locale = parts.join('_');
        return /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/.test(locale) ? locale : '';
    };

    const normalizeCurrencyCode = (value) => {
        return String(value || '').trim().toUpperCase();
    };

    const isCurrencyCodeShape = (value) => {
        return /^[A-Z]{3}$/.test(normalizeCurrencyCode(value));
    };

    const normalizeCurrencyList = (values) => {
        const codes = [];
        const seen = {};
        if (!Array.isArray(values)) {
            values = [values];
        }
        values.forEach((value) => {
            if (value && typeof value === 'object') {
                value = value.code || value.currency || value.currency_code || value.value || '';
            }
            const code = normalizeCurrencyCode(value);
            if (!isCurrencyCodeShape(code) || seen[code]) {
                return;
            }
            seen[code] = true;
            codes.push(code);
        });
        return codes;
    };

    const detectPathCurrency = (pathname, available) => {
        const parts = String(pathname || '/').split('/').filter(Boolean);
        const supported = {};
        (available || []).forEach((code) => {
            const normalized = normalizeCurrencyCode(code);
            if (isCurrencyCodeShape(normalized)) {
                supported[normalized] = true;
            }
        });
        for (let i = 0; i < parts.length; i += 1) {
            const code = normalizeCurrencyCode(parts[i]);
            if (isCurrencyCodeShape(code) && (Object.keys(supported).length === 0 || supported[code])) {
                return code;
            }
        }
        return '';
    };

    const readQueryCurrency = (available) => {
        try {
            const code = normalizeCurrencyCode(new URLSearchParams((window.location && window.location.search) || '').get('currency'));
            if (!isCurrencyCodeShape(code)) {
                return '';
            }
            const supported = {};
            (available || []).forEach((entry) => {
                const normalized = normalizeCurrencyCode(entry);
                if (isCurrencyCodeShape(normalized)) {
                    supported[normalized] = true;
                }
            });
            if (Object.keys(supported).length === 0 || supported[code]) {
                return code;
            }
        } catch (_error) {
        }
        return '';
    };

    const addSupportedCurrencyCode = (codes, value) => {
        if (value && typeof value === 'object') {
            value = value.code || value.currency || value.currency_code || value.value || '';
        }
        const code = normalizeCurrencyCode(value);
        if (isCurrencyCodeShape(code)) {
            codes[code] = true;
        }
    };

    const collectSupportedCurrencyCodes = (config = {}) => {
        const codes = {};
        [
            config.availableCurrencies,
            config.supportedCurrencies,
            config.currencyCodes,
            config.currencies,
            config.defaultCurrency,
            config.default_currency,
        ].forEach((source) => {
            if (Array.isArray(source)) {
                source.forEach((entry) => addSupportedCurrencyCode(codes, entry));
                return;
            }
            addSupportedCurrencyCode(codes, source);
        });
        return codes;
    };

    const isSupportedCurrencyCode = (value, config = {}) => {
        const code = normalizeCurrencyCode(value);
        if (!isCurrencyCodeShape(code)) {
            return false;
        }
        // Explicit allow-list only — ignore defaultCurrency-only maps so path/SSR
        // currentCurrency (e.g. USD) is not stripped when availableCurrencies is unset.
        const explicit = normalizeCurrencyList(
            config.availableCurrencies
            || config.supportedCurrencies
            || config.currencyCodes
            || config.currencies
            || []
        );
        if (explicit.length === 0) {
            return true;
        }
        return collectSupportedCurrencyCodes(config)[code] === true;
    };

    const normalizeCurrency = (value, currencyConfig = {}) => {
        const currency = normalizeCurrencyCode(value);
        return isSupportedCurrencyCode(currency, currencyConfig) ? currency : '';
    };

    const sameOriginUrl = (path, fallbackPath) => {
        const value = path || fallbackPath;
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) {
            throw new Error('[Weline.Api] worker and query-bin URLs must be same-origin.');
        }
        return url.href;
    };

    // DEV / localhost / explicit flag：友好控制台打印（懒加载 weline-dev-console.js）。
    const isDevMode = () => !!(
        window.DEV
        || window.WELINE_ENV === 'DEV'
        || window.location.hostname === 'localhost'
        || window.location.hostname === '127.0.0.1'
    );

    const isDevConsoleEnabled = () => {
        if (window.WELINE_DEV_CONSOLE === false) {
            return false;
        }
        if (window.WELINE_DEV_CONSOLE === true) {
            return true;
        }
        try {
            if (window.localStorage?.getItem('weline_dev_console') === '1') {
                return true;
            }
        } catch (error) {
            /* ignore */
        }
        try {
            if (new URLSearchParams(window.location.search || '').get('weline_dev_console') === '1') {
                return true;
            }
        } catch (error) {
            /* ignore */
        }
        return isDevMode();
    };

    let devConsoleLoadPromise = null;

    const defaultDevConsoleUrl = () => {
        if (window.Weline?.staticResourceResolver?.resolve) {
            try {
                return window.Weline.staticResourceResolver.resolve('Weline_Frontend::js/weline-dev-console.js');
            } catch (error) {
                /* fallback below */
            }
        }
        return (function(d){try{var c=JSON.parse((document.getElementById('weline-frontend-runtime-config')||document.getElementById('weline-backend-runtime-config')||{}).textContent||'{}');if(c&&c.env&&c.env.useProductionPublicUrls){var m=String(d).match(/^\/([^/]+)\/([^/]+)\/view\/statics\/(.*)$/);if(m)return'/static/'+m[1]+'/'+m[2]+'/'+m[3];}}catch(e){}return d;}('/Weline/Frontend/view/statics/js/weline-dev-console.js'));
    };

    const loadDevConsole = () => {
        if (!isDevConsoleEnabled()) {
            return Promise.resolve(null);
        }
        if (window.Weline?.DevConsole?.__welineDevConsole) {
            return Promise.resolve(window.Weline.DevConsole);
        }
        if (devConsoleLoadPromise) {
            return devConsoleLoadPromise;
        }
        devConsoleLoadPromise = new Promise((resolve) => {
            let url = defaultDevConsoleUrl();
            if (isDevMode()) {
                url += (url.includes('?') ? '&' : '?') + 'v=' + Date.now();
            }
            const script = document.createElement('script');
            script.async = true;
            script.src = url;
            script.onload = () => resolve(window.Weline?.DevConsole || null);
            script.onerror = () => resolve(null);
            (document.head || document.documentElement).appendChild(script);
        });
        return devConsoleLoadPromise;
    };

    const withDevConsole = (callback) => {
        if (!isDevConsoleEnabled() || typeof callback !== 'function') {
            return;
        }
        loadDevConsole().then((devConsole) => {
            if (devConsole) {
                callback(devConsole);
            }
        });
    };

    window.Weline = window.Weline || {};
    window.Weline.enableDevConsole = () => {
        window.WELINE_DEV_CONSOLE = true;
        try {
            window.localStorage?.setItem('weline_dev_console', '1');
        } catch (error) {
            /* ignore */
        }
        return loadDevConsole();
    };
    window.Weline.disableDevConsole = () => {
        window.WELINE_DEV_CONSOLE = false;
        try {
            window.localStorage?.removeItem('weline_dev_console');
        } catch (error) {
            /* ignore */
        }
    };

    const summarizeApiPayload = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return 'unknown';
        }
        if (payload.type === 'call') {
            return `call ${payload.provider}.${payload.operation}`;
        }
        if (payload.type === 'graph') {
            return 'graph';
        }
        if (payload.type === 'stream-ticket') {
            return `stream-ticket ${payload.channel || ''}`.trim();
        }
        if (payload.type === 'upload') {
            return `upload ${payload.provider}.${payload.operation}`;
        }
        if (payload.type === 'scope-bootstrap') {
            return 'scope-bootstrap';
        }
        return String(payload.type || 'request');
    };

    const cloneDevLogValue = (value) => {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return value;
        }
    };

    const readHeaderValue = (headers, name) => {
        if (!headers || typeof headers !== 'object') {
            return '';
        }
        const needle = String(name || '').toLowerCase();
        for (const key of Object.keys(headers)) {
            if (String(key).toLowerCase() === needle) {
                return String(headers[key] || '').trim();
            }
        }
        return '';
    };

    const resolveWorkerResponseCacheState = (responseMeta) => {
        if (!responseMeta || typeof responseMeta !== 'object') {
            return 'unknown';
        }
        const direct = String(responseMeta.localCache || responseMeta.workerResponseCache || '').trim();
        if (direct) {
            return direct.toLowerCase() === 'skip' ? 'live' : direct.toLowerCase();
        }
        const fromHeaders = readHeaderValue(responseMeta.headers, 'X-Weline-Worker-Response-Cache');
        if (!fromHeaders) {
            return 'unknown';
        }
        return fromHeaders.toLowerCase() === 'skip' ? 'live' : fromHeaders.toLowerCase();
    };

    // Main-thread L1 mirrors Worker TTL whitelist — avoids postMessage wall-clock under page load.
    const CLIENT_RESPONSE_CACHE_TTL_MS = Object.freeze({
        'region.list': 4 * 60 * 60 * 1000,
        'region.country_profile': 12 * 60 * 60 * 1000,
        'region.children': 4 * 60 * 60 * 1000,
        'region.format_suggestion': 4 * 60 * 60 * 1000,
        'region.has_streets': 4 * 60 * 60 * 1000,
        'region.streets': 4 * 60 * 60 * 1000,
        'consent.status': 60 * 60 * 1000,
        'order.getCheckoutRemark': 30 * 60 * 1000,
        'compare.list': 15 * 60 * 1000,
        'compare.pageView': 15 * 60 * 1000,
    });
    const CLIENT_RESPONSE_CACHE_INVALIDATE = Object.freeze({
        'consent.accept': ['consent.status'],
        'consent.withdraw': ['consent.status'],
        'order.saveCheckoutRemark': ['order.getCheckoutRemark'],
        'order.clearCheckoutRemark': ['order.getCheckoutRemark'],
        'compare.add': ['compare.list', 'compare.pageView'],
        'compare.remove': ['compare.list', 'compare.pageView'],
        'compare.clear': ['compare.list', 'compare.pageView'],
    });

    const clientResponseCacheTtlMs = (capability) => {
        const ttl = CLIENT_RESPONSE_CACHE_TTL_MS[String(capability || '')];
        return typeof ttl === 'number' && ttl > 0 ? ttl : 0;
    };

    const stableClientCacheStringify = (value) => {
        if (value === null || typeof value !== 'object') {
            return JSON.stringify(value);
        }
        if (Array.isArray(value)) {
            return '[' + value.map(stableClientCacheStringify).join(',') + ']';
        }
        const keys = Object.keys(value).sort();
        return '{' + keys.map((key) => JSON.stringify(key) + ':' + stableClientCacheStringify(value[key])).join(',') + '}';
    };

    const canonicalizeClientCacheParams = (capability, params) => {
        const source = params && typeof params === 'object' && !Array.isArray(params) ? params : {};
        const out = {};
        Object.keys(source).forEach((key) => {
            const value = source[key];
            if (value === undefined || value === null || value === '') {
                return;
            }
            out[key] = value;
        });
        if (String(capability || '') === 'region.list') {
            const catalog = String(out.catalog || 'installed').toLowerCase();
            out.catalog = catalog === 'global' ? 'global' : 'installed';
            if (typeof out.country_code === 'string') {
                out.country_code = out.country_code.trim().toUpperCase();
                if (out.country_code === '') {
                    delete out.country_code;
                }
            }
        }
        return out;
    };

    const buildClientResponseCacheKey = (config, provider, operation, params) => {
        const capability = String(provider || '') + '.' + String(operation || '');
        // Segment 2 = Scope-published theme generation (not system deployVersion).
        return [
            'wqrc1',
            String((config && config.themePublishedVersionId) || ''),
            String((config && config.locale) || ''),
            String((config && config.currency) || ''),
            capability,
            stableClientCacheStringify(canonicalizeClientCacheParams(capability, params)),
        ].join('|');
    };

    const wantsClientCacheBypass = (options) => {
        const opts = options && typeof options === 'object' ? options : {};
        return opts.bypassCache === true
            || opts.noCache === true
            || opts.cache === false
            || opts.refresh === true;
    };

    const isBusinessFailure = (data) => {
        return !!(data && typeof data === 'object' && !Array.isArray(data) && data.success === false);
    };

    const extractBusinessMessage = (data, fallback) => {
        if (!isBusinessFailure(data)) {
            return '';
        }
        const message = String(
            data.message
            || data.msg
            || (data.error && data.error.message)
            || fallback
            || ''
        ).trim();
        return message || String(fallback || '请求失败');
    };

    const BACKEND_ACL_DENIED_CODE = 'backend_acl_denied';
    const BACKEND_ACL_EXCEPTION_MARK = 'FrontendWorkerBackendAuthorizationException';
    const BACKEND_ACL_DEFAULT_MESSAGE = '当前后台账号无权执行该操作。';
    const DEFAULT_ERROR_TOAST_DEDUPE_MS = 3000;
    let lastDefaultErrorToastKey = '';
    let lastDefaultErrorToastAt = 0;

    const isWorkerHandshakeCooldownNoise = (error) => {
        if (!error) {
            return false;
        }
        const code = String(error.code || '').trim().toLowerCase();
        const message = String(error.message || '');
        if (/cooling down after capacity\/auth pressure/i.test(message)) {
            return true;
        }
        return code === 'worker_capacity_exhausted' && /cooling down/i.test(message);
    };

    const shouldDedupeDefaultErrorToast = (code, message) => {
        const key = `${String(code || '').trim()}|${String(message || '').trim()}`;
        if (!key || key === '|') {
            return false;
        }
        const now = Date.now();
        if (key === lastDefaultErrorToastKey && (now - lastDefaultErrorToastAt) < DEFAULT_ERROR_TOAST_DEDUPE_MS) {
            return true;
        }
        lastDefaultErrorToastKey = key;
        lastDefaultErrorToastAt = now;
        return false;
    };

    // Module CSV source = 简中 phrase; lookup via runtime translator / i18n bag.
    const i18nText = (source) => {
        const phrase = String(source || '').trim();
        if (!phrase) {
            return '';
        }
        try {
            if (window.WelineI18n && typeof window.WelineI18n.translate === 'function') {
                const translated = window.WelineI18n.translate(phrase);
                if (translated != null && String(translated).trim() !== '') {
                    return String(translated);
                }
            }
            if (window.Weline && window.Weline.i18n && typeof window.Weline.i18n.translate === 'function') {
                const translated = window.Weline.i18n.translate(phrase);
                if (translated != null && String(translated).trim() !== '') {
                    return String(translated);
                }
            }
            if (typeof window.__ === 'function') {
                const translated = window.__(phrase);
                if (translated != null && String(translated).trim() !== '') {
                    return String(translated);
                }
            }
        } catch (_error) {
            // Fall through to bag / source.
        }
        const bag = (window.Weline && window.Weline.config && window.Weline.config.i18n)
            || window.WELINE_I18N
            || {};
        const value = bag && bag[phrase] != null ? String(bag[phrase]).trim() : '';
        return value || phrase;
    };

    const SCOPE_TIP_CACHE_KEY = 'weline.api.scope_tips_by_locale';
    const SCOPE_TIP_CODES = {
        scope_reload_required: true,
        scope_binding_required: true,
        scope_context_conflict: true,
        request_scope_already_conflicts: true,
        deploy_reload_required: true,
        scope_bootstrap_waiting: true,
    };

    const SCOPE_WAIT_TIP_SOURCE = '请稍等片刻...';
    const SCOPE_WAIT_TIP_EN_FALLBACK = 'Please wait a moment...';

    const isChineseFamilyLocale = (locale) => {
        const loc = normalizeLocale(locale) || '';
        return loc === 'zh_Hans_CN'
            || loc === 'zh_Hant_TW'
            || loc === 'zh_Hant_HK'
            || loc.startsWith('zh_Hans')
            || loc.startsWith('zh_Hant')
            || loc === 'zh'
            || loc.startsWith('zh-');
    };

    /**
     * Soft wait tip: config.api.softTips → locale tip cache → i18nText → EN hard fallback
     * when active locale is not Chinese-family but result still equals Chinese source.
     */
    const resolveScopeBootstrapWaitingMessage = () => {
        const locale = currentStorefrontLocale();
        try {
            const cfg = (window.Weline && window.Weline.config) || {};
            const api = cfg.api && typeof cfg.api === 'object' ? cfg.api : {};
            const softTips = api.softTips && typeof api.softTips === 'object' ? api.softTips : null;
            const fromConfig = normalizeScopeTip(softTips && softTips.scope_bootstrap_waiting);
            if (fromConfig) {
                const msg = fromConfig.message || fromConfig.title;
                if (msg) {
                    return msg;
                }
            }
        } catch (_error) {
            // Fall through.
        }
        const cached = readScopeTipCache(locale, 'scope_bootstrap_waiting');
        if (cached) {
            const msg = cached.message || cached.title;
            if (msg) {
                return msg;
            }
        }
        let message = i18nText(SCOPE_WAIT_TIP_SOURCE);
        if (
            !isChineseFamilyLocale(locale)
            && String(message || '').trim() === SCOPE_WAIT_TIP_SOURCE
        ) {
            message = SCOPE_WAIT_TIP_EN_FALLBACK;
        }
        return message || SCOPE_WAIT_TIP_EN_FALLBACK;
    };

    const currentStorefrontLocale = () => {
        try {
            const cfg = (window.Weline && window.Weline.config) || {};
            const api = cfg.api && typeof cfg.api === 'object' ? cfg.api : {};
            return normalizeLocale(
                resolveCurrentLanguage(
                    api.locale || api.currentLang || cfg.locale || cfg.currentLang || ''
                )
            ) || 'zh_Hans_CN';
        } catch (_error) {
            return 'zh_Hans_CN';
        }
    };

    const normalizeScopeTip = (tip) => {
        if (!tip || typeof tip !== 'object') {
            return null;
        }
        const title = String(tip.title || '').trim();
        const message = String(tip.message || '').trim();
        if (title === '' && message === '') {
            return null;
        }
        const locale = normalizeLocale(tip.locale) || '';
        return {
            title: title || message,
            message: message || title,
            locale,
        };
    };

    const readScopeTipCacheBag = () => {
        try {
            const raw = sessionStorage.getItem(SCOPE_TIP_CACHE_KEY);
            if (!raw) {
                return {};
            }
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_error) {
            return {};
        }
    };

    const writeScopeTipCacheBag = (bag) => {
        try {
            sessionStorage.setItem(SCOPE_TIP_CACHE_KEY, JSON.stringify(bag || {}));
        } catch (_error) {
            // sessionStorage may be blocked.
        }
    };

    const writeScopeTipCache = (locale, code, tip) => {
        const loc = normalizeLocale(locale) || currentStorefrontLocale();
        const tipCode = String(code || '').trim();
        const normalized = normalizeScopeTip(tip);
        if (!loc || !tipCode || !normalized || !SCOPE_TIP_CODES[tipCode]) {
            return;
        }
        const bag = readScopeTipCacheBag();
        if (!bag[loc] || typeof bag[loc] !== 'object') {
            bag[loc] = {};
        }
        bag[loc][tipCode] = {
            title: normalized.title,
            message: normalized.message,
        };
        writeScopeTipCacheBag(bag);
    };

    const readScopeTipCache = (locale, code) => {
        const loc = normalizeLocale(locale) || currentStorefrontLocale();
        const tipCode = String(code || '').trim();
        if (!loc || !tipCode) {
            return null;
        }
        const bag = readScopeTipCacheBag();
        const byCode = bag[loc] && typeof bag[loc] === 'object' ? bag[loc] : null;
        return normalizeScopeTip(byCode && byCode[tipCode] ? byCode[tipCode] : null);
    };

    const rememberScopeTipsFromPayload = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return;
        }
        const locale = normalizeLocale(payload.tips_locale)
            || currentStorefrontLocale();
        const tips = payload.tips && typeof payload.tips === 'object' ? payload.tips : null;
        if (tips) {
            Object.keys(tips).forEach((code) => {
                writeScopeTipCache(locale, code, tips[code]);
            });
        }
        const err = payload.error && typeof payload.error === 'object' ? payload.error : null;
        if (err && err.tip) {
            writeScopeTipCache(locale || (err.tip && err.tip.locale), err.code || '', err.tip);
        }
    };

    const resolveErrorPresentation = (error) => {
        const status = Number(error && error.status ? error.status : 0) || 0;
        const code = String((error && error.code) || '').trim() || 'request_failed';
        const rawMessage = String((error && error.message) || '').trim();
        const details = String(
            (error && error.details)
            || rawMessage
            || ''
        ).trim();
        const locale = currentStorefrontLocale();
        // Prefer server tip (envelope) → browser locale cache → Chinese-source i18nText fallback.
        let serverTip = normalizeScopeTip(error && error.tip);
        if (!serverTip) {
            const envelope = readErrorEnvelope(error);
            const nested = envelope && envelope.error && typeof envelope.error === 'object'
                ? envelope.error
                : null;
            serverTip = normalizeScopeTip(nested && nested.tip);
            if (serverTip) {
                writeScopeTipCache(serverTip.locale || locale, code, serverTip);
            }
        } else {
            writeScopeTipCache(serverTip.locale || locale, code, serverTip);
        }
        const cachedTip = (!serverTip && SCOPE_TIP_CODES[code])
            ? readScopeTipCache(locale, code)
            : null;
        const localeTip = serverTip || cachedTip;
        const byCode = {
            origin_unreachable: {
                title: i18nText('源站暂时不可用'),
                message: i18nText('CDN 连不上源站（常见 HTTP 521）。请稍后重试；若持续失败请检查源站与边缘配置。'),
            },
            service_unavailable: {
                title: i18nText('服务暂时不可用'),
                message: i18nText('服务器繁忙或维护中，请稍后重试。'),
            },
            request_forbidden: {
                title: i18nText('请求被拒绝'),
                message: i18nText('当前请求未被允许，可能被安全策略拦截。'),
            },
            auth_error: {
                title: i18nText('登录状态异常'),
                message: i18nText('请刷新页面后重新登录再试。'),
            },
            unexpected_html: {
                title: i18nText('接口返回了网页'),
                message: i18nText('期望二进制接口响应，却收到了 HTML（登录页、错误页或网关页）。'),
            },
            wqb_invalid_magic: {
                title: i18nText('接口响应格式异常'),
                message: i18nText('未能识别 Query Bin 数据包。可点开详情查看 HTTP 状态与响应头。'),
            },
            protocol_error: {
                title: i18nText('协议错误'),
                message: i18nText('与服务器的数据协议不匹配，请刷新后重试。'),
            },
            network_error: {
                title: i18nText('网络异常'),
                message: i18nText('无法连接服务器，请检查网络后重试。'),
            },
            // Stamp expired / Cookie missing: recover via re-punch + retry (send()), not reload.
            scope_reload_required: {
                title: i18nText('商城范围已变更'),
                message: i18nText('当前页面商城范围已过期，正在重新签章以继续。'),
            },
            scope_binding_required: {
                title: i18nText('商城范围未就绪'),
                message: i18nText('页面绑定信息缺失或已失效，正在重新签章以继续。'),
            },
            // True page-identity drift — HTML is for the wrong Scope / theme generation.
            scope_context_conflict: {
                title: i18nText('商城范围已变更'),
                message: i18nText('检测到渠道或店铺切换，正在刷新页面。'),
                autoReload: true,
            },
            request_scope_already_conflicts: {
                title: i18nText('商城范围冲突'),
                message: i18nText('当前请求与页面商城范围不一致，正在刷新页面。'),
                autoReload: true,
            },
            deploy_reload_required: {
                title: i18nText('站点已更新'),
                message: i18nText('主题已发布新版本。请重新加载页面以继续。'),
                autoReload: true,
            },
            scope_bootstrap_timeout: {
                title: i18nText('商城范围未就绪'),
                message: i18nText('服务器繁忙或维护中，请稍后重试。'),
            },
            scope_bootstrap_hole_punch_failed: {
                title: i18nText('商城范围未就绪'),
                message: i18nText('请求失败，请稍后重试。'),
            },
            scope_bootstrap_rate_limited: {
                title: i18nText('商城范围未就绪'),
                message: i18nText('请稍等片刻...'),
            },
        };
        const byStatus = {
            521: byCode.origin_unreachable,
            502: byCode.service_unavailable,
            503: byCode.service_unavailable,
            504: byCode.service_unavailable,
            403: byCode.request_forbidden,
            401: byCode.auth_error,
        };
        const preset = byCode[code] || byStatus[status] || null;
        // protocol_error 若误携带业务可读文案（如地点售卖「请修改收货地址…」），优先展示服务端原文，勿盖成「协议不匹配」
        const preferServerMessage = code === 'protocol_error'
            && rawMessage !== ''
            && rawMessage !== (byCode.protocol_error && byCode.protocol_error.message)
            && !/^(Empty binary|Worker request|Missing Weline|Invalid query|Handshake|Request scheme|Request authority|Unsupported)/i.test(rawMessage);
        let title;
        let message;
        if (localeTip && SCOPE_TIP_CODES[code] && !preferServerMessage) {
            title = localeTip.title;
            message = localeTip.message;
        } else if (preset && !preferServerMessage) {
            title = preset.title;
            message = preset.message;
        } else {
            title = i18nText('请求失败');
            message = rawMessage || i18nText('请求失败，请稍后重试。');
        }
        // QueryBin business failures often travel as HTTP 200 + success:false.
        // Stamping "(HTTP 200)" / "business_error:200" reads as a transport success
        // contradiction — only annotate real transport failures (4xx/5xx).
        const transportFailed = status >= 400;
        if (!preset && !preferServerMessage && !localeTip && transportFailed && !/\bHTTP\s+\d+/i.test(message)) {
            message = `${message} (HTTP ${status})`;
        }
        const displayCode = transportFailed ? `${code}:${status}` : code;
        const showDetails = details !== '' && details !== message;
        return {
            title,
            message,
            code: displayCode,
            details: showDetails ? details : '',
            detailsLabel: i18nText('查看详情'),
            autoReload: !!(preset && preset.autoReload),
        };
    };

    const SCOPE_RELOAD_GUARD_KEY = 'weline.api.scope_reload_at';
    const maybeAutoReloadForScopeConflict = (presentation) => {
        if (!presentation || !presentation.autoReload) {
            return false;
        }
        // Drop optimistic stamp memory before reload — otherwise skip-POST + missing Cookie
        // loops on "Page binding is missing or expired" forever.
        forgetScopeStampKey();
        // Prefer framework toast+auto-reload helper when present (never native confirm).
        try {
            const prompt = window.Weline && typeof window.Weline.promptStorefrontGenerationRefresh === 'function'
                ? window.Weline.promptStorefrontGenerationRefresh
                : null;
            if (prompt) {
                const tip = presentation.message || presentation.tip || '';
                return !!prompt(tip);
            }
        } catch (_promptError) {
            // Fall through to guarded location.reload.
        }
        try {
            const now = Date.now();
            const last = Number(sessionStorage.getItem(SCOPE_RELOAD_GUARD_KEY) || 0) || 0;
            // Prevent reload loops if the conflict persists after refresh.
            if (last > 0 && (now - last) < 8000) {
                return false;
            }
            sessionStorage.setItem(SCOPE_RELOAD_GUARD_KEY, String(now));
        } catch (_error) {
            // sessionStorage may be blocked; still attempt one reload.
        }
        try {
            window.location.reload();
            return true;
        } catch (_error) {
            return false;
        }
    };

    const readErrorEnvelope = (error) => {
        const responseData = error && error.response && error.response.data
            ? error.response.data
            : null;
        if (responseData && typeof responseData === 'object' && responseData.error) {
            return responseData;
        }
        if (responseData && typeof responseData === 'object' && responseData.data && responseData.data.error) {
            return responseData.data;
        }
        return responseData && typeof responseData === 'object' ? responseData : null;
    };

    const isBackendAclDeniedError = (error) => {
        if (!error) {
            return false;
        }
        const code = String(error.code || '').trim();
        if (code === BACKEND_ACL_DENIED_CODE) {
            return true;
        }
        const envelope = readErrorEnvelope(error);
        const nestedError = envelope && envelope.error && typeof envelope.error === 'object'
            ? envelope.error
            : null;
        if (nestedError && String(nestedError.code || '').trim() === BACKEND_ACL_DENIED_CODE) {
            return true;
        }
        const exceptionClass = nestedError && nestedError.debug
            ? String(nestedError.debug.exception_class || '')
            : '';
        if (exceptionClass.indexOf(BACKEND_ACL_EXCEPTION_MARK) !== -1) {
            return true;
        }
        const message = String(error.message || (nestedError && nestedError.message) || '');
        return message.indexOf(BACKEND_ACL_EXCEPTION_MARK) !== -1;
    };

    const cleanBackendAclMessage = (raw) => {
        let text = String(raw || '').trim();
        if (!text) {
            return BACKEND_ACL_DEFAULT_MESSAGE;
        }
        text = text.replace(
            /^Weline\\Framework\\Runtime\\FrontendWorkerBackendAuthorizationException:\s*/i,
            ''
        );
        text = text.replace(/\s+in\s+\/.+:\d+\s*$/i, '');
        text = text.trim();
        return text || BACKEND_ACL_DEFAULT_MESSAGE;
    };

    const resolveBackendAclMessage = (error) => {
        const envelope = readErrorEnvelope(error);
        const nestedError = envelope && envelope.error && typeof envelope.error === 'object'
            ? envelope.error
            : null;
        const debugMessage = nestedError && nestedError.debug
            ? String(nestedError.debug.exception_message || '').trim()
            : '';
        if (debugMessage) {
            return cleanBackendAclMessage(debugMessage);
        }
        if (nestedError && nestedError.message) {
            return cleanBackendAclMessage(nestedError.message);
        }
        if (error && error.message) {
            return cleanBackendAclMessage(error.message);
        }
        return BACKEND_ACL_DEFAULT_MESSAGE;
    };

    const showBackendAclDeniedUi = (error) => {
        if (!error || error._welineBackendAclUiShown) {
            return true;
        }
        error._welineBackendAclUiShown = true;
        const message = resolveBackendAclMessage(error);
        try {
            if (isDevMode()) {
                const dialog = window.Weline && window.Weline.UI && window.Weline.UI.dialog;
                if (dialog && typeof dialog.alert === 'function') {
                    Promise.resolve(dialog.alert(message)).catch(() => {});
                    return true;
                }
            }
            const toast = window.Weline && window.Weline.UI && window.Weline.UI.toast;
            if (toast && typeof toast.error === 'function') {
                toast.error(message);
                return true;
            }
            if (toast && typeof toast.show === 'function') {
                toast.show(message, { tone: 'danger' });
                return true;
            }
        } catch (uiError) {
            console.error('[Weline.Api] backend ACL UI failed:', uiError);
        }
        console.error('[Weline.Api]', message);
        return true;
    };

    // Cache-bust once per page load so rebuildConfig does not churn the Worker URL.
    const cachedDevWorkerUrls = {};

    const withDevCacheBust = (url) => {
        const isDev = isDevMode() || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        if (!isDev) {
            return url;
        }
        const cacheKey = String(url || '');
        if (cachedDevWorkerUrls[cacheKey]) {
            return cachedDevWorkerUrls[cacheKey];
        }
        const workerUrl = new URL(url, window.location.origin);
        if (!workerUrl.searchParams.has('_weline_worker_dev')) {
            workerUrl.searchParams.set('_weline_worker_dev', String(Date.now()));
        }
        cachedDevWorkerUrls[cacheKey] = workerUrl.href;
        return cachedDevWorkerUrls[cacheKey];
    };

    /**
     * Hard single-flight: one in-flight boot per worker script URL.
     * Previous fetch/`new Worker(url)` still pending ⇒ later callers await the same Promise
     * (never open a second Network request for the same script).
     */
    const inflightWorkerScriptBoots = new Map();

    /**
     * Unify SharedWorker.port and Dedicated Worker behind postMessage/addEventListener/terminate.
     * SharedWorker reset closes only this tab's port (process stays for other tabs).
     */
    const wrapQueryBinWorkerTransport = (raw, kind) => {
        if (kind === 'shared') {
            const port = raw && raw.port ? raw.port : null;
            if (!port || typeof port.postMessage !== 'function') {
                throw new Error('[Weline.Api] SharedWorker port is unavailable.');
            }
            if (typeof port.start === 'function') {
                port.start();
            }
            return {
                kind: 'shared',
                raw,
                postMessage: (message) => port.postMessage(message),
                addEventListener: (type, listener, options) => {
                    if (type === 'error' || type === 'messageerror') {
                        if (raw && typeof raw.addEventListener === 'function') {
                            raw.addEventListener(type, listener, options);
                        }
                        return;
                    }
                    port.addEventListener(type, listener, options);
                },
                removeEventListener: (type, listener, options) => {
                    if (type === 'error' || type === 'messageerror') {
                        if (raw && typeof raw.removeEventListener === 'function') {
                            raw.removeEventListener(type, listener, options);
                        }
                        return;
                    }
                    port.removeEventListener(type, listener, options);
                },
                terminate: () => {
                    try {
                        if (typeof port.close === 'function') {
                            port.close();
                        }
                    } catch (_error) {
                        /* ignore */
                    }
                },
            };
        }
        return {
            kind: 'dedicated',
            raw,
            postMessage: (message) => raw.postMessage(message),
            addEventListener: (type, listener, options) => raw.addEventListener(type, listener, options),
            removeEventListener: (type, listener, options) => raw.removeEventListener(type, listener, options),
            terminate: () => {
                try {
                    raw.terminate();
                } catch (_error) {
                    /* ignore */
                }
            },
        };
    };

    /**
     * Prefer true SharedWorker (stable same-origin URL — blob URLs cannot share across tabs).
     * Fall back to Dedicated: Blob-first then same-origin URL Worker
     * (Blob path requires worker-src blob: — see SecurityHeaderDefaults::CSP).
     */
    const createDedicatedWorkerFromScriptUrl = (workerUrl, options = {}) => {
        const scriptUrl = String(workerUrl || '');
        if (!scriptUrl) {
            return Promise.reject(new Error('[Weline.Api] workerUrl is not configured.'));
        }
        if (typeof window.SharedWorker !== 'function' && typeof window.Worker !== 'function') {
            return Promise.reject(new Error(
                '[Weline.Api] Worker is unavailable; direct frontend API fallback is disabled.'
            ));
        }

        const existingBoot = inflightWorkerScriptBoots.get(scriptUrl);
        if (existingBoot) {
            return existingBoot;
        }

        const boot = (() => {
            const fetchInit = {
                credentials: 'same-origin',
                cache: isDevMode() ? 'no-store' : 'force-cache',
            };
            const timeoutMs = typeof options.timeoutMs === 'number' ? options.timeoutMs : 8000;
            let timeoutId = 0;
            let localAbort = null;
            if (options && options.signal) {
                fetchInit.signal = options.signal;
            } else if (typeof AbortController === 'function' && timeoutMs > 0) {
                localAbort = new AbortController();
                fetchInit.signal = localAbort.signal;
                timeoutId = window.setTimeout(() => {
                    try {
                        localAbort.abort();
                    } catch (_error) {
                        /* ignore */
                    }
                }, timeoutMs);
            }

            const clearFetchTimeout = () => {
                if (timeoutId) {
                    window.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
            };

            const createSharedWorker = () => new Promise((resolve, reject) => {
                if (typeof window.SharedWorker !== 'function') {
                    reject(new Error('[Weline.Api] SharedWorker unavailable.'));
                    return;
                }
                let settled = false;
                let shared = null;
                try {
                    // Stable name + URL ⇒ one process across tabs (not blob:).
                    shared = new SharedWorker(scriptUrl, { name: 'weline-query-bin' });
                } catch (error) {
                    reject(error);
                    return;
                }
                const finishOk = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    try {
                        shared.removeEventListener('error', onError);
                    } catch (_e) { /* ignore */ }
                    clearFetchTimeout();
                    try {
                        resolve(wrapQueryBinWorkerTransport(shared, 'shared'));
                    } catch (error) {
                        reject(error);
                    }
                };
                const onError = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    try {
                        shared.removeEventListener('error', onError);
                    } catch (_e) { /* ignore */ }
                    reject(new Error('[Weline.Api] SharedWorker blocked by CSP or failed to boot.'));
                };
                if (typeof shared.addEventListener === 'function') {
                    shared.addEventListener('error', onError);
                }
                window.setTimeout(finishOk, 80);
            });

            const createBlobWorker = () => fetch(scriptUrl, fetchInit).then((response) => {
                clearFetchTimeout();
                if (!response.ok) {
                    throw new Error('[Weline.Api] worker script HTTP ' + response.status);
                }
                return response.text();
            }).then((code) => {
                if (!code || !String(code).trim()) {
                    throw new Error('[Weline.Api] worker script body is empty.');
                }
                if (typeof window.Worker !== 'function') {
                    throw new Error('[Weline.Api] Worker is unavailable.');
                }
                const blob = new Blob([code], { type: 'text/javascript' });
                const blobUrl = URL.createObjectURL(blob);
                try {
                    const worker = new Worker(blobUrl);
                    // Keep blob URL until the worker has had time to parse (revoke@0 races CSP/load).
                    window.setTimeout(() => {
                        try {
                            URL.revokeObjectURL(blobUrl);
                        } catch (_error) {
                            /* ignore */
                        }
                    }, 5000);
                    return wrapQueryBinWorkerTransport(worker, 'dedicated');
                } catch (error) {
                    try {
                        URL.revokeObjectURL(blobUrl);
                    } catch (_error) {
                        /* ignore */
                    }
                    throw error;
                }
            }).catch((error) => {
                clearFetchTimeout();
                throw error;
            });

            const createUrlWorker = () => new Promise((resolve, reject) => {
                if (typeof window.Worker !== 'function') {
                    reject(new Error('[Weline.Api] Worker is unavailable.'));
                    return;
                }
                let settled = false;
                let worker = null;
                try {
                    worker = new Worker(scriptUrl);
                } catch (error) {
                    reject(error);
                    return;
                }
                const finishOk = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    try {
                        worker.removeEventListener('error', onError);
                    } catch (_e) { /* ignore */ }
                    clearFetchTimeout();
                    resolve(wrapQueryBinWorkerTransport(worker, 'dedicated'));
                };
                const onError = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    try {
                        worker.removeEventListener('error', onError);
                    } catch (_e) { /* ignore */ }
                    try {
                        worker.terminate();
                    } catch (_t) { /* ignore */ }
                    reject(new Error('[Weline.Api] same-origin Worker blocked by CSP or failed to boot.'));
                };
                worker.addEventListener('error', onError);
                // CSP violations surface asynchronously; accept URL worker if no error arrives quickly.
                window.setTimeout(finishOk, 80);
            });

            // SharedWorker first (true cross-tab); Dedicated Blob then URL as fallback.
            return createSharedWorker().catch(() => createBlobWorker().catch(() => createUrlWorker()));
        })();

        inflightWorkerScriptBoots.set(scriptUrl, boot);
        boot.finally(() => {
            if (inflightWorkerScriptBoots.get(scriptUrl) === boot) {
                inflightWorkerScriptBoots.delete(scriptUrl);
            }
        });
        return boot;
    };

    const getDefaultWorkerUrl = () => {
        if (window.Weline && window.Weline.staticResourceResolver) {
            try {
                return window.Weline.staticResourceResolver.resolve('Weline_Frontend::js/weline-api-worker.js');
            } catch (error) {
                /* fall through */
            }
        }
        // Align with PublicStaticUrl / DeployPublicUrlMode (staging may force public under DEV).
        return (function (d) {
            try {
                var c = JSON.parse((document.getElementById('weline-frontend-runtime-config') || document.getElementById('weline-backend-runtime-config') || {}).textContent || '{}');
                if (c && c.api && c.api.workerUrl) {
                    return String(c.api.workerUrl);
                }
                if (c && c.env && c.env.useProductionPublicUrls) {
                    var m = String(d).match(/^\/([^/]+)\/([^/]+)\/view\/statics\/(.*)$/);
                    if (m) {
                        return '/static/' + m[1] + '/' + m[2] + '/' + m[3];
                    }
                }
            } catch (e) { /* keep DEV path */ }
            return d;
        })('/Weline/Frontend/view/statics/js/weline-api-worker.js');
    };

    const cloneWorkerValue = (value, depth = 0) => {
        if (depth > 8 || value === undefined || typeof value === 'function' || typeof value === 'symbol') {
            return undefined;
        }
        if (value === null || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            return value;
        }
        if (Array.isArray(value)) {
            return value
                .map(item => cloneWorkerValue(item, depth + 1))
                .filter(item => item !== undefined);
        }
        if (typeof value === 'object') {
            if (value instanceof Date) {
                return value.toISOString();
            }
            if (typeof Element !== 'undefined' && value instanceof Element) {
                return undefined;
            }
            const cloned = {};
            Object.keys(value).forEach(key => {
                const clonedValue = cloneWorkerValue(value[key], depth + 1);
                if (clonedValue !== undefined) {
                    cloned[key] = clonedValue;
                }
            });
            return cloned;
        }
        return undefined;
    };

    const sanitizeOptionsForWorker = (options) => {
        if (!options || typeof options !== 'object') {
            return {};
        }
        const sanitized = cloneWorkerValue(options);
        return sanitized && typeof sanitized === 'object' && !Array.isArray(sanitized) ? sanitized : {};
    };

    const sanitizePayloadForWorker = (payload) => {
        const sanitized = {};
        Object.keys(payload || {}).forEach(key => {
            if (key === 'options') {
                const workerOptions = sanitizeOptionsForWorker(payload[key]);
                if (Object.keys(workerOptions).length > 0) {
                    sanitized.options = workerOptions;
                }
                return;
            }
            const clonedValue = cloneWorkerValue(payload[key]);
            if (clonedValue !== undefined) {
                sanitized[key] = clonedValue;
            }
        });
        return sanitized;
    };

    const isFormData = (value) => {
        return typeof FormData !== 'undefined' && value instanceof FormData;
    };

    const normalizeCallParams = (params) => {
        if (!params || typeof params !== 'object' || Array.isArray(params) || isFormData(params)) {
            return params || {};
        }

        const normalized = {};
        Object.keys(params).forEach(key => {
            if (key === 'form_key' || key === 'csrf_token' || key === '_token') {
                return;
            }
            normalized[key] = params[key];
        });

        return normalized;
    };

    const buildClientConfig = () => {
        const apiConfig = mergeApiConfig();
        const runtimeConfig = getRuntimeConfig();
        const config = Object.assign({
            endpoint: '/api/framework/query-bin',
            deployVersion: 'dev',
            workerBuildId: 'dev',
            area: '',
            locale: '',
            currency: '',
            onHttpError: null,
            requestTimeoutMs: 15000,
        }, apiConfig);

        config.workerUrl = withDevCacheBust(sameOriginUrl(apiConfig.workerUrl || getDefaultWorkerUrl(), getDefaultWorkerUrl()));
        config.endpoint = sameOriginUrl(apiConfig.endpoint || apiConfig.queryBinUrl || '/api/framework/query-bin', '/api/framework/query-bin');
        // deployVersion / workerBuildId live on the runtime root (see header/base.phtml),
        // not only under config.api — reading api-only left clients stuck on literal "dev"
        // and QueryBin 409'd every filteredListingPage when Env had a real stamp.
        config.deployVersion = String(
            apiConfig.deployVersion
            || apiConfig.deploy_version
            || runtimeConfig.deployVersion
            || runtimeConfig.deploy_version
            || config.deployVersion
            || 'dev'
        );
        config.workerBuildId = String(
            apiConfig.workerBuildId
            || apiConfig.worker_build_id
            || runtimeConfig.workerBuildId
            || runtimeConfig.worker_build_id
            || config.workerBuildId
            || 'dev'
        );
        config.themePublishedVersionId = String(
            apiConfig.themePublishedVersionId
            || apiConfig.theme_published_version_id
            || runtimeConfig.themePublishedVersionId
            || runtimeConfig.theme_published_version_id
            || ''
        );
        config.area = String(apiConfig.area || apiConfig.context || config.area || '');
        config.defaultCurrency = normalizeCurrencyCode(
            apiConfig.defaultCurrency || apiConfig.default_currency || runtimeConfig.defaultCurrency || runtimeConfig.default_currency || 'CNY'
        );
        config.availableCurrencies = apiConfig.availableCurrencies || apiConfig.supportedCurrencies || apiConfig.currencyCodes || apiConfig.currencies
            || runtimeConfig.availableCurrencies || runtimeConfig.supportedCurrencies || runtimeConfig.currencyCodes || runtimeConfig.currencies || [];
        config.locale = normalizeLocale(resolveCurrentLanguage(
            apiConfig.locale || apiConfig.currentLang || runtimeConfig.currentLang || config.locale
        ));
        config.pathname = String((window.location && window.location.pathname) || '/');
        const available = normalizeCurrencyList(config.availableCurrencies);
        config.availableCurrencies = available;
        config.currency = normalizeCurrency(
            detectPathCurrency(config.pathname, available)
            || readQueryCurrency(available)
            || apiConfig.currency
            || apiConfig.currentCurrency
            || runtimeConfig.currentCurrency
            || config.currency
            || config.defaultCurrency,
            config
        );
        // Scope stamp is HttpOnly Cookie only — never read Token / opaque id into page JS.
        config.cacheCatalog = (apiConfig.cacheCatalog && typeof apiConfig.cacheCatalog === 'object')
            ? apiConfig.cacheCatalog
            : {};
        config.website_id = Number(apiConfig.website_id || runtimeConfig.website_id || 0) || 0;
        config.store_id = Number(apiConfig.store_id || runtimeConfig.store_id || 0) || 0;
        config.channel_id = Number(apiConfig.channel_id || runtimeConfig.channel_id || 0) || 0;
        return config;
    };

    let client = null;
    const getOrCreateClient = () => {
        const freshConfig = buildClientConfig();
        if (!client) {
            client = new WelineApiClient(freshConfig);
            return client;
        }
        client.config.endpoint = freshConfig.endpoint;
        client.config.workerUrl = freshConfig.workerUrl;
        // Storefront generation = Scope-published theme id only (not system deployVersion).
        if (String(client.config.themePublishedVersionId || '') !== String(freshConfig.themePublishedVersionId || '')) {
            client.responseCacheL1.clear();
        }
        client.config.deployVersion = freshConfig.deployVersion;
        client.config.workerBuildId = freshConfig.workerBuildId;
        client.config.themePublishedVersionId = freshConfig.themePublishedVersionId;
        client.config.locale = freshConfig.locale;
        client.config.pathname = freshConfig.pathname;
        client.config.currency = freshConfig.currency;
        client.config.defaultCurrency = freshConfig.defaultCurrency;
        client.config.availableCurrencies = freshConfig.availableCurrencies;
        client.config.area = freshConfig.area;
        // Channel/store navigations change Scope identity; adopt ids and clear
        // page stamp memory so ensure may POST again for the new Scope.
        const prevScopeKey = pageScopeSessionKey(client.config);
        client.config.website_id = freshConfig.website_id;
        client.config.store_id = freshConfig.store_id;
        client.config.channel_id = freshConfig.channel_id;
        const nextScopeKey = pageScopeSessionKey(client.config);
        if (prevScopeKey !== nextScopeKey) {
            if (scopeStampEnsuredKey === prevScopeKey) {
                scopeStampEnsuredKey = '';
            }
            client.scopeWarmupComplete = false;
            client.scopeWarmupPromise = null;
        }
        return client;
    };

    const STREAM_TERMINAL_EVENTS = new Set([
        'done',
        'complete',
        'completed',
        'failed',
        'cancelled',
        'expired',
        'recovery_unsafe',
        'event_backlog_limit'
    ]);
    const STREAM_RUNTIME_CHANNEL_PREFIX = 'runtime_task.';
    const STREAM_STORAGE_PREFIX = 'weline.runtime.stream.';

    const normalizeStreamCursor = (value) => {
        const cursor = value === null || typeof value === 'undefined' ? '' : String(value).trim();
        return cursor.length <= 128 && !/[\x00-\x1F\x7F]/.test(cursor) ? cursor : '';
    };

    const normalizeStreamParamName = (value) => {
        const name = String(value || '').trim();
        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(name) ? name : '';
    };

    const compareNumericStreamCursors = (left, right) => {
        const normalizedLeft = String(left).replace(/^0+(?=\d)/, '');
        const normalizedRight = String(right).replace(/^0+(?=\d)/, '');
        if (normalizedLeft.length !== normalizedRight.length) {
            return normalizedLeft.length < normalizedRight.length ? -1 : 1;
        }
        if (normalizedLeft === normalizedRight) {
            return 0;
        }
        return normalizedLeft < normalizedRight ? -1 : 1;
    };

    const createStreamIntentId = () => {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        }
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    };

    class StreamHandle extends EventTarget {
        constructor(client, channel, params = {}, options = {}) {
            super();

            this.client = client;
            this.channel = String(channel || '');
            this.options = options && typeof options === 'object' ? options : {};
            this.params = params && typeof params === 'object' && !Array.isArray(params) && !isFormData(params)
                ? Object.assign({}, normalizeCallParams(params))
                : {};
            this._source = null;
            this._sourceEventTypes = new Set();
            this._eventTypes = new Set(['message']);
            (Array.isArray(this.options.eventTypes) ? this.options.eventTypes : []).forEach(type => {
                if (typeof type === 'string' && type !== '') {
                    this._eventTypes.add(type);
                }
            });
            this._seenEventIds = new Set();
            this._retryTimer = null;
            this._leaseTimer = null;
            this._connecting = false;
            this._closed = false;
            this._terminal = false;
            this._cancelRequested = false;
            this._cancelPromise = null;
            this._retryAttempt = 0;
            this._renewingLease = false;
            this._onopen = null;
            this._onerror = null;
            this._onmessage = null;

            this.taskId = String(this.params.task_id || '');
            this._storageKey = this.resolveStorageKey();
            const stored = this.readStoredState();
            if (!this.params.lease_id && stored.lease_id) {
                this.params.lease_id = stored.lease_id;
            }
            this.leaseId = String(this.params.lease_id || '');
            this.lastEventId = normalizeStreamCursor(
                this.options.lastEventId
                ?? this.options.last_event_id
                ?? this.params.last_event_id
                ?? stored.cursor
            );
            this._lastNumericEventId = /^\d+$/.test(this.lastEventId) ? this.lastEventId : '';
            if (this.lastEventId !== '') {
                this._seenEventIds.add(this.lastEventId);
            }
            this.cancelIntentId = normalizeStreamCursor(this.options.intentId || stored.intent_id) || createStreamIntentId();
            this.cursorParam = this.resolveCursorParam();
            this.isRuntimeTaskStream = this.channel.indexOf(STREAM_RUNTIME_CHANNEL_PREFIX) === 0;
            this.leaseEnabled = this.options.lease === true
                || (this.options.lease !== false && this.isRuntimeTaskStream);
            this.leaseProvider = String(this.options.leaseProvider || 'runtime_task');
            this.touchOperation = String(this.options.touchOperation || 'touch');
            this.cancelOperation = String(this.options.cancelOperation || 'cancel');
            this.leaseIntervalMs = this.resolveInterval(this.options.leaseIntervalMs, 30000, 1000, 300000);
            this.retryMinMs = this.resolveInterval(this.options.retryMinMs, 1000, 1, 30000);
            this.retryMaxMs = this.resolveInterval(this.options.retryMaxMs, 30000, this.retryMinMs, 30000);
            this.terminalEvents = new Set(STREAM_TERMINAL_EVENTS);
            (Array.isArray(this.options.terminalEvents) ? this.options.terminalEvents : []).forEach(type => {
                if (typeof type === 'string' && type !== '') {
                    this.terminalEvents.add(type.toLowerCase());
                    this._eventTypes.add(type);
                }
            });
            // Terminal events must always be observed internally. Otherwise a
            // caller that only subscribes to e.g. `progress` would miss a
            // persisted `completed`/`failed` frame and reconnect forever.
            this.terminalEvents.forEach(type => this._eventTypes.add(type));
            // Transport auth failures are reconnectable, never task-terminal.
            this._eventTypes.add('transport_error');

            this._onlineHandler = () => this.handleOnline();
            this._offlineHandler = () => this.dispatchLifecycleEvent('offline');
            window.addEventListener('online', this._onlineHandler);
            window.addEventListener('offline', this._offlineHandler);
            this.persistState();
        }

        get onopen() {
            return this._onopen;
        }

        set onopen(listener) {
            this.replacePropertyListener('_onopen', 'open', listener);
        }

        get onerror() {
            return this._onerror;
        }

        set onerror(listener) {
            this.replacePropertyListener('_onerror', 'error', listener);
        }

        get onmessage() {
            return this._onmessage;
        }

        set onmessage(listener) {
            this.replacePropertyListener('_onmessage', 'message', listener);
        }

        get readyState() {
            if (this._closed || this._terminal) {
                return 2;
            }
            return this._source ? this._source.readyState : 0;
        }

        get url() {
            return this._source ? this._source.url : '';
        }

        get withCredentials() {
            return this.options.withCredentials !== false;
        }

        addEventListener(type, listener, options) {
            super.addEventListener(type, listener, options);
            if (typeof type === 'string' && type !== '' && type !== 'open' && type !== 'error') {
                this._eventTypes.add(type);
                this.attachSourceEvent(this._source, type);
            }
        }

        async start() {
            await this.connect(true);
            return this;
        }

        close() {
            if (this._closed) {
                return;
            }
            this._closed = true;
            this.clearReconnectTimer();
            this.stopLeaseRenewal();
            this.closeSource();
            this.removeWindowListeners();
            this.persistState();
            this.dispatchLifecycleEvent('close');
        }

        async cancel(reason = '') {
            if (!this.taskId) {
                throw new Error('[Weline.Api] StreamHandle.cancel() requires a task_id.');
            }
            if (this._cancelPromise) {
                return this._cancelPromise;
            }

            this._cancelPromise = this.client.call(this.leaseProvider, this.cancelOperation, {
                task_id: this.taskId,
                intent_id: this.cancelIntentId,
                reason: String(reason || ''),
            }).then(result => {
                this._cancelRequested = true;
                this.stopLeaseRenewal();
                this.persistState();
                this.dispatchLifecycleEvent('cancel_requested', { intent_id: this.cancelIntentId });
                return result;
            }).finally(() => {
                this._cancelPromise = null;
            });

            return this._cancelPromise;
        }

        resolveStorageKey() {
            if (typeof this.options.storageKey === 'string' && this.options.storageKey !== '') {
                return STREAM_STORAGE_PREFIX + this.options.storageKey;
            }
            if (!this.taskId) {
                return '';
            }
            return STREAM_STORAGE_PREFIX + encodeURIComponent(this.channel) + ':' + encodeURIComponent(this.taskId);
        }

        readStoredState() {
            if (!this._storageKey) {
                return {};
            }
            try {
                const value = window.sessionStorage.getItem(this._storageKey);
                const parsed = value ? JSON.parse(value) : null;
                return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
            } catch (error) {
                return {};
            }
        }

        persistState() {
            if (!this._storageKey) {
                return;
            }
            try {
                window.sessionStorage.setItem(this._storageKey, JSON.stringify({
                    task_id: this.taskId,
                    lease_id: this.leaseId,
                    cursor: this.lastEventId,
                    intent_id: this.cancelIntentId,
                }));
            } catch (error) {
                /* session storage may be unavailable */
            }
        }

        resolveCursorParam() {
            if (this.options.cursorParam === false) {
                return '';
            }
            if (typeof this.options.cursorParam === 'string') {
                return normalizeStreamParamName(this.options.cursorParam);
            }
            return this.channel.indexOf(STREAM_RUNTIME_CHANNEL_PREFIX) === 0 ? 'last_event_id' : '';
        }

        resolveInterval(value, fallback, minimum, maximum) {
            const parsed = Number(value);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(minimum, Math.min(maximum, Math.floor(parsed)));
        }

        replacePropertyListener(property, type, listener) {
            const previous = this[property];
            if (typeof previous === 'function') {
                super.removeEventListener(type, previous);
            }
            this[property] = typeof listener === 'function' ? listener : null;
            if (this[property]) {
                super.addEventListener(type, this[property]);
            }
        }

        async connect(initial) {
            if (this._closed || this._terminal || this._connecting) {
                return;
            }
            this._connecting = true;
            this.clearReconnectTimer();

            try {
                const ticket = await this.client.send({
                    type: 'stream-ticket',
                    channel: this.channel,
                    params: this.buildTicketParams(),
                    options: this.options.ticketOptions || this.options,
                });
                if (!ticket || !ticket.url) {
                    throw new Error('[Weline.Api] stream ticket did not include a stream URL.');
                }
                if (this._closed || this._terminal) {
                    return;
                }

                this.openSource(sameOriginUrl(ticket.url, ticket.url));
                this.startLeaseRenewal();
            } catch (error) {
                this.dispatchError(error);
                this.scheduleReconnect();
                // A recoverable initial ticket failure must not strand a
                // detached stream without a handle the caller can later
                // close. Opt into the former rejecting behavior explicitly.
                if (initial && this.options.rejectOnInitialError === true) {
                    this.close();
                    throw error;
                }
            } finally {
                this._connecting = false;
            }
        }

        buildTicketParams() {
            const params = Object.assign({}, this.params);
            if (this.cursorParam && this.lastEventId) {
                params[this.cursorParam] = this.lastEventId;
            }
            return params;
        }

        openSource(url) {
            if (!window.EventSource) {
                throw new Error('[Weline.Api] EventSource is unavailable.');
            }

            this.closeSource();
            const source = new window.EventSource(url, { withCredentials: this.withCredentials });
            this._source = source;
            this._sourceEventTypes = new Set();

            source.addEventListener('open', event => {
                if (this._source !== source || this._closed || this._terminal) {
                    return;
                }
                this._retryAttempt = 0;
                this.dispatchEvent(this.createEvent('open', event));
            });
            source.addEventListener('error', event => this.handleSourceError(source, event));
            this._eventTypes.forEach(type => this.attachSourceEvent(source, type));
        }

        attachSourceEvent(source, type) {
            if (!source || type === 'open' || type === 'error' || this._sourceEventTypes.has(type)) {
                return;
            }
            this._sourceEventTypes.add(type);
            source.addEventListener(type, event => {
                if (this._source === source && !this._closed && !this._terminal) {
                    this.receiveEvent(event);
                }
            });
        }

        handleSourceError(source, event) {
            if (this._source !== source || this._closed || this._terminal) {
                return;
            }
            this.closeSource(source);
            this.dispatchEvent(this.createEvent('error', event));
            this.scheduleReconnect();
        }

        receiveEvent(event) {
            const eventId = normalizeStreamCursor(event && event.lastEventId);
            if (eventId && this.isDuplicateEvent(eventId)) {
                return;
            }
            if (eventId) {
                this.rememberEventId(eventId);
            }

            const forwarded = this.createMessageEvent(event);
            if (this.isReconnectableTransportEvent(forwarded)) {
                // Do not emit business `failed`/`transport_error` to callers —
                // they historically treat `failed` as a durable task terminal.
                this.closeSource();
                this.dispatchEvent(this.createEvent('error', event));
                this.scheduleReconnect();
                return;
            }
            this.dispatchEvent(forwarded);
            if (this.isTerminalEvent(forwarded)) {
                this.markTerminal();
            }
        }

        isDuplicateEvent(eventId) {
            if (this._seenEventIds.has(eventId)) {
                return true;
            }
            if (/^\d+$/.test(eventId) && this._lastNumericEventId) {
                return compareNumericStreamCursors(eventId, this._lastNumericEventId) <= 0;
            }
            return false;
        }

        rememberEventId(eventId) {
            this.lastEventId = eventId;
            if (/^\d+$/.test(eventId)) {
                this._lastNumericEventId = eventId;
            }
            this._seenEventIds.add(eventId);
            while (this._seenEventIds.size > 1024) {
                this._seenEventIds.delete(this._seenEventIds.values().next().value);
            }
            this.persistState();
        }

        parseEventPayload(event) {
            let payload = event && event.data;
            if (typeof payload === 'string') {
                try {
                    payload = JSON.parse(payload);
                } catch (error) {
                    return null;
                }
            }
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                return null;
            }
            return payload;
        }

        isTransportAuthFailurePayload(payload) {
            if (!payload) {
                return false;
            }
            const code = String(payload.code || payload.error_code || '').trim().toLowerCase();
            const status = Number(payload.http_status || 0);
            return code === 'auth_error'
                || code === 'worker_store_unavailable'
                || status === 401;
        }

        isReconnectableTransportEvent(event) {
            const type = String(event && event.type || '').toLowerCase();
            if (type === 'transport_error') {
                return true;
            }
            if (type !== 'failed') {
                return false;
            }
            return this.isTransportAuthFailurePayload(this.parseEventPayload(event));
        }

        isTerminalEvent(event) {
            if (this.isReconnectableTransportEvent(event)) {
                return false;
            }
            const type = String(event.type || '').toLowerCase();
            if (this.terminalEvents.has(type)) {
                return true;
            }
            const payload = this.parseEventPayload(event);
            if (!payload) {
                return false;
            }
            if (payload.terminal === true) {
                return true;
            }
            const status = String(payload.status || payload.state || '').toLowerCase();
            return this.terminalEvents.has(status);
        }

        markTerminal() {
            if (this._terminal) {
                return;
            }
            this._terminal = true;
            this.clearReconnectTimer();
            this.stopLeaseRenewal();
            this.closeSource();
            this.removeWindowListeners();
            this.persistState();
            this.dispatchLifecycleEvent('terminal');
        }

        scheduleReconnect() {
            if (this._closed || this._terminal || this._retryTimer !== null || this.isOffline()) {
                return;
            }
            this._retryAttempt += 1;
            const baseDelay = Math.min(this.retryMaxMs, this.retryMinMs * (2 ** (this._retryAttempt - 1)));
            const delay = Math.max(this.retryMinMs, Math.round(baseDelay * (0.75 + (Math.random() * 0.5))));
            this.dispatchLifecycleEvent('reconnecting', { attempt: this._retryAttempt, delay });
            this._retryTimer = window.setTimeout(() => {
                this._retryTimer = null;
                this.connect(false).catch(() => {});
            }, delay);
        }

        clearReconnectTimer() {
            if (this._retryTimer !== null) {
                window.clearTimeout(this._retryTimer);
                this._retryTimer = null;
            }
        }

        handleOnline() {
            if (this._closed || this._terminal) {
                return;
            }
            this.clearReconnectTimer();
            this.closeSource();
            this.connect(false).catch(() => {});
        }

        isOffline() {
            return typeof window.navigator !== 'undefined' && window.navigator.onLine === false;
        }

        startLeaseRenewal() {
            if (!this.leaseEnabled || !this.taskId || !this.leaseId || this._terminal || this._closed || this._cancelRequested) {
                return;
            }
            if (this._leaseTimer !== null) {
                return;
            }
            this.renewLease();
            this._leaseTimer = window.setInterval(() => this.renewLease(), this.leaseIntervalMs);
        }

        stopLeaseRenewal() {
            if (this._leaseTimer !== null) {
                window.clearInterval(this._leaseTimer);
                this._leaseTimer = null;
            }
        }

        renewLease() {
            if (this._renewingLease || this._closed || this._terminal || this._cancelRequested || this.isOffline()) {
                return;
            }
            this._renewingLease = true;
            this.client.call(this.leaseProvider, this.touchOperation, {
                task_id: this.taskId,
                lease_id: this.leaseId,
            }).then(result => {
                this.dispatchLifecycleEvent('lease', { result });
            }).catch(error => {
                // A missing touch is intentionally not a cancel. The server-side
                // lease timeout decides whether the detached task eventually expires.
                this.dispatchLifecycleEvent('leaseerror', { error });
            }).finally(() => {
                this._renewingLease = false;
            });
        }

        closeSource(source = this._source) {
            if (!source) {
                return;
            }
            if (this._source === source) {
                this._source = null;
                this._sourceEventTypes = new Set();
            }
            try {
                source.close();
            } catch (error) {
                /* EventSource close is best-effort */
            }
        }

        removeWindowListeners() {
            window.removeEventListener('online', this._onlineHandler);
            window.removeEventListener('offline', this._offlineHandler);
        }

        dispatchError(error) {
            const event = this.createEvent('error');
            Object.defineProperty(event, 'error', { value: error, enumerable: true });
            this.dispatchEvent(event);
        }

        dispatchLifecycleEvent(type, detail = null) {
            const event = typeof window.CustomEvent === 'function'
                ? new window.CustomEvent(type, { detail })
                : this.createEvent(type);
            this.dispatchEvent(event);
        }

        createEvent(type, sourceEvent = null) {
            const event = new window.Event(type);
            if (sourceEvent && sourceEvent.lastEventId) {
                Object.defineProperty(event, 'lastEventId', { value: sourceEvent.lastEventId, enumerable: true });
            }
            return event;
        }

        createMessageEvent(sourceEvent) {
            const type = String(sourceEvent.type || 'message');
            if (typeof window.MessageEvent === 'function') {
                return new window.MessageEvent(type, {
                    data: sourceEvent.data,
                    lastEventId: sourceEvent.lastEventId || '',
                    origin: sourceEvent.origin || window.location.origin,
                });
            }
            const event = this.createEvent(type, sourceEvent);
            Object.defineProperty(event, 'data', { value: sourceEvent.data, enumerable: true });
            return event;
        }
    }

    class WelineApiClient {
        constructor(clientConfig) {
            this.config = clientConfig;
            this.worker = null;
            this.workerStartPromise = null;
            this.workerRecoverPromise = null;
            this.scopeStampRecoverPromise = null;
            this.workerFetchAbort = null;
            this.requestId = 0;
            this.pending = new Map();
            this.devTraces = new Map();
            this.responseCacheL1 = new Map();
            this.autoRequestsEnabled = false;
            this.scopeWarmupPromise = null;
            this.scopeWarmupComplete = false;

            this.handleWorkerMessage = this.handleWorkerMessage.bind(this);
            this.handleWorkerError = this.handleWorkerError.bind(this);
        }

        call(provider, operation, params = {}, options = {}) {
            if (!provider || !operation) {
                return Promise.reject(new Error('[Weline.Api] provider and operation are required.'));
            }
            const normalizedParams = normalizeCallParams(params);
            const capability = String(provider) + '.' + String(operation);
            const ttlMs = clientResponseCacheTtlMs(capability);
            if (ttlMs > 0 && !wantsClientCacheBypass(options)) {
                const cacheKey = buildClientResponseCacheKey(this.config, provider, operation, normalizedParams);
                const cached = this.responseCacheL1.get(cacheKey);
                if (cached && Number(cached.expiresAt) > Date.now()) {
                    const startedAt = performance.now();
                    const requestPayload = {
                        type: 'call',
                        provider,
                        operation,
                        params: normalizedParams,
                        options,
                    };
                    this.finishDevTrace(null, {
                        ok: true,
                        status: 200,
                        statusText: 'OK',
                        data: cached.data,
                        localCache: 'l1',
                        workerElapsedMs: 0,
                        headers: { 'X-Weline-Worker-Response-Cache': 'l1' },
                    }, requestPayload, startedAt);
                    return Promise.resolve(cached.data);
                }
            }
            return this.send({
                type: 'call',
                provider,
                operation,
                params: normalizedParams,
                options,
            }).then((data) => {
                this.rememberClientResponseCache(provider, operation, normalizedParams, options, data);
                this.invalidateClientResponseCacheAfterWrite(provider, operation, data);
                return data;
            });
        }

        rememberClientResponseCache(provider, operation, params, options, data) {
            if (wantsClientCacheBypass(options) || isBusinessFailure(data)) {
                return;
            }
            const capability = String(provider || '') + '.' + String(operation || '');
            const ttlMs = clientResponseCacheTtlMs(capability);
            if (!(ttlMs > 0)) {
                return;
            }
            const cacheKey = buildClientResponseCacheKey(this.config, provider, operation, params);
            this.responseCacheL1.set(cacheKey, {
                expiresAt: Date.now() + ttlMs,
                data,
            });
        }

        invalidateClientResponseCacheAfterWrite(provider, operation, data) {
            if (isBusinessFailure(data)) {
                return;
            }
            const capability = String(provider || '') + '.' + String(operation || '');
            const targets = CLIENT_RESPONSE_CACHE_INVALIDATE[capability];
            if (!targets || targets.length === 0) {
                return;
            }
            const themeGen = String(this.config.themePublishedVersionId || '');
            const locale = String(this.config.locale || '');
            const currency = String(this.config.currency || '');
            targets.forEach((target) => {
                const needle = '|' + String(target) + '|';
                Array.from(this.responseCacheL1.keys()).forEach((key) => {
                    if (String(key).indexOf(needle) !== -1
                        && (themeGen === '' || String(key).indexOf('|' + themeGen + '|') !== -1)
                        && String(key).indexOf('|' + locale + '|') !== -1
                        && String(key).indexOf('|' + currency + '|') !== -1) {
                        this.responseCacheL1.delete(key);
                    }
                });
            });
        }

        graph(graph, options = {}) {
            return this.send({
                type: 'graph',
                graph: graph || {},
                options,
            });
        }

        async stream(channel, params = {}, options = {}) {
            const handle = new StreamHandle(this, channel, params, options);
            return handle.start();
        }

        async upload(provider, operation, formData, options = {}) {
            if (!provider || !operation) {
                throw new Error('[Weline.Api] upload provider and operation are required.');
            }
            if (!isFormData(formData)) {
                throw new Error('[Weline.Api] upload expects FormData.');
            }

            const ticket = await this.call(provider, `${operation}Ticket`, {}, options);
            if (!ticket || !ticket.url || !ticket.ticket) {
                throw new Error('[Weline.Api] upload ticket response is invalid.');
            }

            const uploadTraceStartedAt = isDevMode() ? performance.now() : 0;
            const uploadRequestPayload = {
                type: 'upload',
                provider,
                operation,
                ticketUrl: ticket.url,
            };

            const headers = Object.assign({}, ticket.headers || {}, {
                'X-Weline-Upload-Ticket': String(ticket.ticket),
                'X-Weline-Upload-Provider': String(provider),
                'X-Weline-Upload-Operation': String(operation),
            });
            const response = await fetch(sameOriginUrl(ticket.url, ticket.url), {
                method: ticket.method || 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers,
                body: formData,
            });
            const responseContentType = response.headers.get('content-type') || '';
            const body = responseContentType.includes('application/json')
                ? await response.json()
                : { code: response.ok ? 200 : response.status, msg: await response.text(), data: null };

            const failed = !response.ok || (body && typeof body.code !== 'undefined' && Number(body.code) >= 400) || body?.success === false;
            this.finishDevTrace(null, {
                ok: !failed,
                status: response.status,
                statusText: response.statusText || '',
                data: body,
            }, uploadRequestPayload, uploadTraceStartedAt);
            if (failed) {
                const error = new Error((body && (body.message || body.msg)) || response.statusText || 'Weline upload failed.');
                const uploadCode = String(
                    (body && body.code)
                    || (body && body.error && typeof body.error === 'object' ? body.error.code : '')
                    || ''
                ).toLowerCase();
                const uploadMwHeader = String(response.headers.get('x-weline-maintenance') || '').toLowerCase();
                const uploadMaintenance = uploadMwHeader === '1'
                    || uploadMwHeader === 'true'
                    || uploadCode === 'maintenance'
                    || !!(body && body.data && String(body.data.variant || '').toLowerCase() === 'maintenance');
                error.code = uploadMaintenance
                    ? 'maintenance'
                    : (body?.error?.code || body?.code || 'business_error');
                error.status = response.status || 0;
                error.response = {
                    ok: false,
                    status: response.status || 0,
                    statusText: response.statusText || '',
                    data: body,
                    maintenance: uploadMaintenance,
                };
                this.reportDevError(error, { type: 'upload', provider, operation, options, skipConsole: true });
                this.handleHttpError(error.status, error, options && options.silent, options, {
                    type: 'upload',
                    provider,
                    operation,
                });
                throw error;
            }

            return body;
        }

        resource(provider, optionalMap) {
            if (!provider || typeof provider !== 'string') {
                throw new Error('[Weline.Api] resource provider is required.');
            }
            const methodMap = optionalMap && typeof optionalMap === 'object' ? optionalMap : null;
            const client = this;

            return new Proxy(Object.create(null), {
                get(_target, property) {
                    if (typeof property === 'symbol' || RESERVED_METHODS.has(property)) {
                        return undefined;
                    }
                    const methodName = String(property);
                    if (methodMap && !Object.prototype.hasOwnProperty.call(methodMap, methodName)) {
                        return undefined;
                    }
                    const operation = methodMap ? String(methodMap[methodName]) : methodName;
                    if (!operation) {
                        return undefined;
                    }
                    return (params = {}, options = {}) => {
                        if (isFormData(params)) {
                            return client.upload(provider, operation, params, options);
                        }
                        return client.call(provider, operation, params, options);
                    };
                },
                has(_target, property) {
                    if (typeof property !== 'string' || RESERVED_METHODS.has(property)) {
                        return false;
                    }
                    return !methodMap || Object.prototype.hasOwnProperty.call(methodMap, property);
                },
            });
        }

        request() {
            return Promise.reject(new Error('[Weline.Api] direct request(url) is disabled. Use Weline.Api.resource()/call()/graph()/stream().'));
        }

        warmup(options = {}) {
            const forceHandshake = !!(options && options.forceHandshake);
            if (this.scopeWarmupComplete && !forceHandshake) {
                return Promise.resolve(null);
            }
            if (forceHandshake) {
                // Do not share an in-flight non-force warmup; stamp recover must re-punch + re-handshake.
                this.scopeWarmupPromise = null;
                this.scopeWarmupComplete = false;
            }
            if (!this.scopeWarmupPromise) {
                // Ordinary path: Worker handshake only (SharedWorker may already hold a session).
                // Hole-punch POST only when forcing a new session after Cookie re-issue, or when
                // handshake fails with a stamp-recoverable error (Worker demanded a visa).
                const runWarmup = (allowRepunch) => {
                    const beforeHandshake = forceHandshake
                        ? ensureScopeStamp(this.config)
                        : Promise.resolve(true);
                    return beforeHandshake.then(() => {
                        // Handshake uses credentials Cookie only — no scope_bootstrap_id.
                        // forceHandshake drops SharedWorker badge so a new Cookie is not ignored.
                        return this.sendToWorker({
                            type: 'scope-bootstrap',
                            options: { silent: true, forceHandshake: forceHandshake },
                        });
                    }).catch((error) => {
                        // Worker needs a Scope visa → one silent punch + forced re-handshake.
                        if (allowRepunch && isScopeStampRecoverableError(error)) {
                            forgetScopeStampKey();
                            this.notifyScopeBootstrapWaiting();
                            return ensureScopeStamp(this.config).then(() => this.sendToWorker({
                                type: 'scope-bootstrap',
                                options: { silent: true, forceHandshake: true },
                            }));
                        }
                        throw error;
                    });
                };
                this.scopeWarmupPromise = runWarmup(true).then((result) => {
                    this.scopeWarmupComplete = true;
                    clearScopeWaitTipSchedule();
                    return result;
                }).finally(() => {
                    if (!this.scopeWarmupComplete) {
                        this.scopeWarmupPromise = null;
                    }
                });
            }
            return this.scopeWarmupPromise;
        }

        /**
         * Worker demanded a new session (Cookie / binding missing or expired): forget
         * optimistic stamp, POST scope-bootstrap, force SharedWorker re-handshake, retry.
         */
        recoverScopeStamp() {
            if (this.scopeStampRecoverPromise) {
                return this.scopeStampRecoverPromise;
            }
            this.scopeStampRecoverPromise = Promise.resolve().then(() => {
                forgetScopeStampKey();
                this.scopeWarmupComplete = false;
                this.scopeWarmupPromise = null;
                this.notifyScopeBootstrapWaiting();
                return this.warmup({ forceHandshake: true });
            }).finally(() => {
                this.scopeStampRecoverPromise = null;
            });
            return this.scopeStampRecoverPromise;
        }

        /**
         * Soft tip while QueryBin callers wait for first Scope stamp ensure — not an error toast.
         * Long HttpOnly visa already ensured (page/tab memory) ⇒ never tip again.
         * Not "still punching opaque bootstrap". Coalesced + delayed so filter spam stays quiet.
         */
        notifyScopeBootstrapWaiting() {
            if (this.scopeWarmupComplete) {
                return;
            }
            // Authorized long Scope Cookie path: tip is only for the first ensure.
            if (isScopeStampAlreadyEnsured(this.config)) {
                return;
            }
            if (scopeWaitTipTimer) {
                return;
            }
            scopeWaitTipTimer = window.setTimeout(() => {
                scopeWaitTipTimer = null;
                if (this.scopeWarmupComplete || isScopeStampAlreadyEnsured(this.config)) {
                    return;
                }
                const now = Date.now();
                if (now - scopeWaitTipAt < SCOPE_WAIT_TIP_COOLDOWN_MS) {
                    return;
                }
                scopeWaitTipAt = now;
                const showTip = () => {
                    if (this.scopeWarmupComplete || isScopeStampAlreadyEnsured(this.config)) {
                        return;
                    }
                    const message = resolveScopeBootstrapWaitingMessage();
                    let shown = false;
                    try {
                        const Toast = this.resolveToastComponent();
                        if (Toast && typeof Toast.info === 'function') {
                            Toast.info(message, { duration: 2800 });
                            shown = true;
                        } else if (Toast && typeof Toast.show === 'function') {
                            Toast.show(message, { type: 'info', tone: 'info', duration: 2800 });
                            shown = true;
                        }
                    } catch (_toastError) {
                        /* tip is best-effort */
                    }
                    // Only fallback when no Toast API succeeded — do not re-probe Theme Notice
                    // DOM (Weline.UI.toast uses .w-toast; dual probe caused duplicate tips).
                    if (!shown) {
                        this.renderScopeWaitTip(message);
                    }
                };
                // Wait briefly for footer i18n-client bag → Weline.i18n.setDictionary.
                const ready = window.Weline && window.Weline.i18n && window.Weline.i18n.ready;
                if (ready && typeof ready.then === 'function') {
                    Promise.race([
                        ready,
                        new Promise((resolve) => { window.setTimeout(resolve, 600); }),
                    ]).then(showTip).catch(showTip);
                } else {
                    showTip();
                }
            }, SCOPE_WAIT_TIP_DELAY_MS);
        }

        renderScopeWaitTip(message) {
            if (!document || !document.body) {
                return;
            }
            let el = document.getElementById('weline-api-wait-tip');
            if (!el) {
                el = document.createElement('div');
                el.id = 'weline-api-wait-tip';
                el.setAttribute('role', 'status');
                el.setAttribute('aria-live', 'polite');
                el.style.cssText = [
                    'position:fixed',
                    'right:1rem',
                    'bottom:1rem',
                    'z-index:2147483646',
                    'max-width:min(22rem,calc(100vw - 2rem))',
                    'border-radius:.75rem',
                    'background:#0f1111',
                    'color:#fafafa',
                    'box-shadow:0 12px 32px rgba(15,23,42,.18)',
                    'font:600 14px/1.45 sans-serif',
                    'padding:.85rem 1rem',
                ].join(';');
                document.body.appendChild(el);
            }
            el.textContent = message;
            el.style.opacity = '1';
            window.setTimeout(() => {
                if (!el || !el.parentNode) {
                    return;
                }
                el.style.transition = 'opacity .18s ease';
                el.style.opacity = '0';
                window.setTimeout(() => {
                    if (el && el.parentNode) {
                        el.remove();
                    }
                }, 220);
            }, 2800);
        }

        isTransientServiceError(error) {
            if (!error) {
                return false;
            }
            const status = Number(error.status || error.statusCode || 0) || 0;
            if (status === 502 || status === 503 || status === 504) {
                return true;
            }
            const code = String(error.code || '').toLowerCase();
            if (code === 'service_unavailable' || code === 'network_error' || code === 'worker_timeout') {
                return true;
            }
            const message = String(error.message || '');
            return /service_unavailable|502|503|504|Failed to fetch|NetworkError|Bad Gateway|temporarily unavailable/i.test(message);
        }

        send(payload) {
            return this.dispatchToWorker(payload).catch((error) => {
                if (payload && (payload.__workerRetry || payload.__scopeStampRetry)) {
                    throw error;
                }
                // Stamp / Cookie expired: re-punch + re-handshake, then continue the same request.
                // Do not location.reload() — SharedWorker would keep the stale badge across reload.
                if (isScopeStampRecoverableError(error)) {
                    return this.recoverScopeStamp().then(() => {
                        const retryPayload = Object.assign({}, payload, { __scopeStampRetry: true });
                        return this.dispatchToWorker(retryPayload);
                    }).catch((recoverError) => {
                        const requestOptions = payload && payload.options;
                        this.handleHttpError(
                            Number((recoverError && recoverError.status) || (error && error.status) || 0) || 0,
                            recoverError || error,
                            !!(requestOptions && requestOptions.silent),
                            requestOptions,
                            payload
                        );
                        throw recoverError || error;
                    });
                }
                if (error && error.code === 'worker_timeout') {
                    // Concurrent cart/account/bootstrap timeouts must share one recreate;
                    // otherwise each retry fetch()s weline-api-worker.js and floods Network.
                    return this.recoverWorkerAfterTimeout().then(() => {
                        const retryPayload = Object.assign({}, payload, { __workerRetry: true });
                        return this.dispatchToWorker(retryPayload);
                    });
                }
                // Transient gateway/network: one soft retry without resetting the Worker
                // (reset would cancel sibling pending QueryBin calls).
                if (this.isTransientServiceError(error)) {
                    return new Promise((resolve) => {
                        window.setTimeout(resolve, 350);
                    }).then(() => {
                        const retryPayload = Object.assign({}, payload, { __workerRetry: true });
                        return this.dispatchToWorker(retryPayload);
                    });
                }
                throw error;
            });
        }

        dispatchToWorker(payload) {
            if (payload && payload.type !== 'scope-bootstrap') {
                // Ensure Worker session before QueryBin (handshake-only). Stamp POST happens
                // only when Worker rejects for missing/expired Cookie (recoverScopeStamp).
                return this.warmup().then(() => this.sendToWorker(payload));
            }
            return this.sendToWorker(payload);
        }

        recoverWorkerAfterTimeout() {
            if (this.workerRecoverPromise) {
                return this.workerRecoverPromise;
            }
            this.workerRecoverPromise = Promise.resolve().then(() => {
                // Script still downloading ⇒ await that Promise; do not abort+refetch.
                if (this.workerStartPromise && !this.worker) {
                    return this.workerStartPromise;
                }
                this.resetWorker({ abortBoot: true });
                this.scopeWarmupComplete = false;
                this.scopeWarmupPromise = null;
                return this.ensureWorker();
            }).finally(() => {
                this.workerRecoverPromise = null;
            });
            return this.workerRecoverPromise;
        }

        resetWorker(options = {}) {
            const abortBoot = !!(options && options.abortBoot);
            // Boot still in flight + not aborting ⇒ keep workerStartPromise so callers share it.
            if (!abortBoot && this.workerStartPromise && !this.worker) {
                return;
            }
            if (this.workerFetchAbort && typeof this.workerFetchAbort.abort === 'function') {
                try {
                    this.workerFetchAbort.abort();
                } catch (_error) {
                    /* ignore */
                }
            }
            this.workerFetchAbort = null;
            if (this.worker && typeof this.worker.terminate === 'function') {
                try {
                    this.worker.terminate();
                } catch (error) {
                    /* worker may already be gone */
                }
            }
            this.worker = null;
            this.workerStartPromise = null;
        }

        sendToWorker(payload) {
            const messageId = this.buildMessageId();
            return this.ensureWorker().then(() => new Promise((resolve, reject) => {
                const optionTimeout = payload && payload.options
                    ? (payload.options.requestTimeoutMs || payload.options.timeoutMs || payload.options.timeout)
                    : null;
                const configuredTimeout = parseInt(optionTimeout || this.config.requestTimeoutMs || 15000, 10);
                const timeoutMs = Number.isFinite(configuredTimeout) ? Math.max(1000, configuredTimeout) : 15000;
                const timeoutId = window.setTimeout(() => {
                    if (!this.pending.has(messageId)) {
                        return;
                    }
                    const pending = this.pending.get(messageId);
                    this.pending.delete(messageId);
                    const error = new Error('[Weline.Api] worker request timed out.');
                    error.code = 'worker_timeout';
                    // Do not resetWorker() here: send() coalesces a single recover+retry.
                    // Resetting per concurrent timeout was re-fetching the worker script in a storm.
                    this.finishDevTrace(messageId, {
                        ok: false,
                        error: { code: error.code, message: error.message },
                    });
                    this.reportDevError(error, { type: 'timeout', request: pending && pending.payload, skipConsole: true });
                    this.notifyApiFailure(error, {
                        status: 0,
                        silent: !!(pending && pending.payload && pending.payload.options && pending.payload.options.silent),
                        request: (pending && pending.payload) || {},
                    });
                    reject(error);
                }, timeoutMs);
                this.pending.set(messageId, { resolve, reject, payload, timeoutId });
                const workerPayload = sanitizePayloadForWorker(payload);
                this.beginDevTrace(messageId, payload);
                this.worker.postMessage(Object.assign({}, workerPayload, {
                    id: messageId,
                    config: {
                        endpoint: this.config.endpoint,
                        deployVersion: this.config.deployVersion,
                        workerBuildId: this.config.workerBuildId,
                        themePublishedVersionId: this.config.themePublishedVersionId || '',
                        locale: this.config.locale,
                        host: (window.location && window.location.host) || '',
                        pathname: this.config.pathname || (window.location && window.location.pathname) || '/',
                        currency: this.config.currency,
                        defaultCurrency: this.config.defaultCurrency,
                        availableCurrencies: this.config.availableCurrencies,
                        cacheCatalog: this.config.cacheCatalog || {},
                        website_id: this.config.website_id,
                        store_id: this.config.store_id,
                        channel_id: this.config.channel_id,
                    },
                }));
            }));
        }

        ensureWorker() {
            if (this.worker) {
                return Promise.resolve(this.worker);
            }
            if (this.workerStartPromise) {
                return this.workerStartPromise;
            }
            const abortController = typeof AbortController === 'function' ? new AbortController() : null;
            this.workerFetchAbort = abortController;
            this.workerStartPromise = createDedicatedWorkerFromScriptUrl(this.config.workerUrl, {
                signal: abortController ? abortController.signal : undefined,
            })
                .then((worker) => {
                    this.worker = worker;
                    this.worker.addEventListener('message', this.handleWorkerMessage);
                    this.worker.addEventListener('error', this.handleWorkerError);
                    this.worker.addEventListener('messageerror', this.handleWorkerError);
                    return worker;
                })
                .catch((error) => {
                    this.workerStartPromise = null;
                    throw error;
                })
                .finally(() => {
                    if (this.workerFetchAbort === abortController) {
                        this.workerFetchAbort = null;
                    }
                });
            return this.workerStartPromise;
        }

        buildMessageId() {
            this.requestId += 1;
            return `req_${Date.now()}_${this.requestId}`;
        }

        handleWorkerMessage(event) {
            const data = event.data || {};
            const pending = this.pending.get(data.id);
            if (!pending) {
                return;
            }
            this.pending.delete(data.id);
            if (pending.timeoutId) {
                window.clearTimeout(pending.timeoutId);
            }

            const wrapper = data.body || {};
            if (data.ok === true && wrapper.ok === true) {
                const businessData = wrapper.data;
                const businessFailed = isBusinessFailure(businessData);
                const requestOptions = pending.payload && pending.payload.options;
                const keepBusinessResult = !!(requestOptions && requestOptions.keepBusinessResult);

                if (businessFailed && !keepBusinessResult) {
                    const bizMessage = extractBusinessMessage(businessData, '请求失败');
                    const error = new Error(bizMessage);
                    error.code = (businessData.error && businessData.error.code) || businessData.code || 'business_error';
                    error.status = data.status || 200;
                    error.response = {
                        ok: false,
                        status: data.status || 200,
                        statusText: data.statusText || '',
                        data: wrapper,
                        maintenance: !!data.maintenance,
                    };
                    // Defer toast/reload: send() may recover stamp + retry the same call.
                    const deferStampUi = this.shouldDeferScopeStampErrorUi(error, pending.payload);
                    this.finishDevTrace(data.id, {
                        ok: false,
                        recovering: deferStampUi,
                        status: error.status,
                        statusText: data.statusText || '',
                        error: { code: error.code, message: bizMessage },
                        data: businessData,
                        headers: data.headers || {},
                    });
                    this.reportDevError(error, {
                        status: error.status,
                        silent: !!(requestOptions && requestOptions.silent),
                        request: pending.payload,
                        workerMessage: data,
                        skipConsole: true,
                    });
                    if (!deferStampUi) {
                        this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions, pending.payload);
                    }
                    pending.reject(error);
                    return;
                }

                this.finishDevTrace(data.id, {
                    ok: !businessFailed,
                    status: data.status || 200,
                    statusText: data.statusText || '',
                    data: wrapper.data,
                    request_id: wrapper.request_id || '',
                    headers: data.headers || {},
                    workerElapsedMs: data.workerElapsedMs,
                    ...(businessFailed ? { error: { message: extractBusinessMessage(businessData, '请求失败') } } : {}),
                });
                pending.resolve(wrapper.data);
                return;
            }

            const serverError = wrapper.error || {};
            const error = new Error(serverError.message || data.error || 'Weline worker API request failed.');
            error.code = serverError.code || 'protocol_error';
            error.status = data.status || 0;
            error.details = serverError.details || '';
            error.responseKind = serverError.responseKind || '';
            error.tip = normalizeScopeTip(serverError.tip);
            if (error.tip) {
                writeScopeTipCache(
                    error.tip.locale || currentStorefrontLocale(),
                    error.code,
                    error.tip,
                );
            }
            error.response = {
                ok: false,
                status: data.status || 0,
                statusText: data.statusText || '',
                data: wrapper,
                maintenance: !!data.maintenance,
            };
            const requestOptions = pending.payload && pending.payload.options;
            // Defer toast/reload: send() may recover stamp + retry the same call.
            // DevConsole must not paint BinQuery ERR for this expected intermediate 401.
            const deferStampUi = this.shouldDeferScopeStampErrorUi(error, pending.payload);
            this.finishDevTrace(data.id, {
                ok: false,
                recovering: deferStampUi,
                status: error.status,
                statusText: data.statusText || '',
                error: serverError,
                body: wrapper,
                headers: data.headers || {},
            });
            this.reportDevError(error, {
                status: error.status,
                silent: !!(requestOptions && requestOptions.silent),
                request: pending.payload,
                workerMessage: data,
                skipConsole: true,
            });
            if (!deferStampUi) {
                this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions, pending.payload);
            }
            pending.reject(error);
        }

        shouldDeferScopeStampErrorUi(error, payload) {
            if (!isScopeStampRecoverableError(error)) {
                return false;
            }
            if (payload && (payload.__scopeStampRetry || payload.__workerRetry)) {
                return false;
            }
            return true;
        }

        handleWorkerError(event) {
            const message = event && event.message ? event.message : '[Weline.Api] worker failed.';
            const workerDetail = {
                message: event && event.message,
                filename: event && event.filename,
                lineno: event && event.lineno,
                colno: event && event.colno,
                error: event && event.error,
            };
            this.resetWorker({ abortBoot: true });
            for (const [id, pending] of this.pending.entries()) {
                this.pending.delete(id);
                if (pending.timeoutId) {
                    window.clearTimeout(pending.timeoutId);
                }
                const error = new Error(message);
                error.code = 'worker_error';
                this.finishDevTrace(id, {
                    ok: false,
                    error: { code: error.code, message: error.message },
                    worker: workerDetail,
                });
                this.reportDevError(error, Object.assign({
                    type: 'worker_crash',
                    requestId: id,
                    request: pending.payload,
                    skipConsole: true,
                }, workerDetail));
                this.notifyApiFailure(error, {
                    status: 0,
                    silent: !!(pending.payload && pending.payload.options && pending.payload.options.silent),
                    request: pending.payload || {},
                });
                pending.reject(error);
            }
        }

        handleHttpError(status, error, silent, requestOptions, requestPayload) {
            this.notifyApiFailure(error, {
                status,
                silent: !!silent,
                request: requestPayload && typeof requestPayload === 'object' ? requestPayload : {},
            });
            // Explicit maintenance only — bare HTTP 503 (overload/startup/misc) must not open wait modal.
            const isMaintenance = !!(error && error.response && error.response.maintenance)
                || String((error && error.code) || '').toLowerCase() === 'maintenance';
            if (isMaintenance) {
                const handler = (this.config && this.config.maintenanceHandler)
                    || (window.Weline && window.Weline.config && window.Weline.config.api && window.Weline.config.api.maintenanceHandler);
                if (typeof handler === 'function') {
                    try {
                        handler({
                            status,
                            error,
                            silent: !!silent,
                            request: requestPayload,
                            payload: (error && error.response && error.response.data) || null,
                        });
                    } catch (handlerError) {
                        console.error('[Weline.Api] maintenanceHandler failed:', handlerError);
                    }
                    return;
                }
            }
            // ACL / 环境授权异常：DEV 居中 dialog，现场右上角 toast；silent 也强制提示。
            if (isBackendAclDeniedError(error)) {
                showBackendAclDeniedUi(error);
                return;
            }
            // Deploy / Scope generation drift must auto-reload even when the page
            // installs onHttpError or marks the call silent — otherwise stale HTML
            // after release never recovers.
            const presentation = resolveErrorPresentation(error || {});
            if (presentation.autoReload && maybeAutoReloadForScopeConflict(presentation)) {
                return;
            }
            if (silent) return;
            const requestCb = requestOptions && (requestOptions.onError || requestOptions.onHttpError);
            if (typeof requestCb === 'function') {
                try {
                    requestCb.call(null, status, error);
                } catch (callbackError) {
                    console.error('[Weline.Api] request error callback failed:', callbackError);
                }
                return;
            }
            if (typeof this.config.onHttpError === 'function') {
                try {
                    this.config.onHttpError(status, error, silent);
                    return;
                } catch (callbackError) {
                    console.error('[Weline.Api] onHttpError failed:', callbackError);
                }
            }
            this.showDefaultError(error);
        }

        /**
         * Always bridge QueryBin / worker failures to pixel (and other listeners).
         * Independent of toast UI; skips visitor.trackPixel to avoid recursion.
         */
        notifyApiFailure(error, meta = {}) {
            try {
                if (!error) {
                    return;
                }
                const request = (meta && meta.request) || {};
                const provider = String(request.provider || '').trim();
                const operation = String(request.operation || '').trim();
                if (provider === 'visitor' && /^trackPixel$/i.test(operation)) {
                    return;
                }
                const message = error && error.message ? String(error.message) : 'Request failed';
                const code = error && error.code ? String(error.code) : '';
                if (code === 'auth_error' && /nonce has already been used/i.test(message)) {
                    return;
                }
                if (/signing_secret/i.test(message) && /null/i.test(message)) {
                    return;
                }
                if (code === 'auth_error' && /worker session is unavailable/i.test(message)) {
                    return;
                }
                // Silent background hydrate must not fan out into pixel site_error → more QueryBin.
                const silentStatus = parseInt((meta && meta.status) || (error && error.status) || 0, 10) || 0;
                if (
                    meta && meta.silent
                    && (
                        silentStatus === 502
                        || silentStatus === 503
                        || silentStatus === 504
                        || code === 'service_unavailable'
                        || code === 'network_error'
                        || code === 'worker_timeout'
                    )
                ) {
                    return;
                }
                // Client-side handshake backoff is not a site incident worth GA4 site_error.
                if (isWorkerHandshakeCooldownNoise(error)) {
                    return;
                }
                if (typeof window.dispatchEvent !== 'function' || typeof window.CustomEvent !== 'function') {
                    return;
                }
                const status = parseInt(
                    (meta && meta.status) || (error && error.status) || 0,
                    10
                ) || 0;
                const responseData = error && error.response && error.response.data
                    ? error.response.data
                    : null;
                const nested = responseData && responseData.data && typeof responseData.data === 'object'
                    ? responseData.data
                    : (responseData || {});
                const errorCode = String(
                    (nested && (nested.error_code || (nested.error && nested.error.code)))
                    || code
                    || ''
                ).trim();
                window.dispatchEvent(new CustomEvent('weline:api:error', {
                    detail: {
                        message,
                        msg: message,
                        code,
                        error_code: errorCode,
                        status,
                        http_status: status,
                        provider,
                        operation,
                        silent: !!(meta && meta.silent),
                        error,
                    },
                }));
            } catch (notifyError) {
                console.debug('[Weline.Api] notifyApiFailure skipped:', notifyError);
            }
        }

        reportDevError(error, detail) {
            if (!isDevConsoleEnabled() || !error) {
                return;
            }
            if (error._welineApiDevLogged) {
                return;
            }
            error._welineApiDevLogged = true;
            if (detail && detail.skipConsole) {
                return;
            }
            withDevConsole((devConsole) => {
                devConsole.failed('request failed', error, detail);
            });
        }

        beginDevTrace(messageId, payload) {
            if (!isDevConsoleEnabled() || !messageId) {
                return;
            }
            this.devTraces.set(messageId, {
                startedAt: performance.now(),
                payload: payload || {},
            });
        }

        finishDevTrace(messageId, responseMeta, requestPayloadOverride, startedAtOverride) {
            if (!isDevConsoleEnabled()) {
                return;
            }

            let trace = null;
            if (messageId) {
                trace = this.devTraces.get(messageId);
                this.devTraces.delete(messageId);
            }

            const requestPayload = requestPayloadOverride || (trace && trace.payload) || {};
            const startedAt = startedAtOverride || (trace && trace.startedAt) || performance.now();
            const durationMs = Math.max(0, Math.round(performance.now() - startedAt));
            const summary = summarizeApiPayload(requestPayload);
            const ok = !!(responseMeta && responseMeta.ok === true);
            const recovering = !ok && !!(responseMeta && responseMeta.recovering);
            const status = responseMeta && responseMeta.status ? responseMeta.status : '';
            const requestLog = cloneDevLogValue(sanitizePayloadForWorker(requestPayload));
            const responseLog = cloneDevLogValue(responseMeta || {});
            const localCache = resolveWorkerResponseCacheState(responseMeta);
            const workerElapsedMs = responseMeta && responseMeta.workerElapsedMs != null
                ? Math.max(0, Number(responseMeta.workerElapsedMs) || 0)
                : null;

            withDevConsole((devConsole) => {
                devConsole.binQuery({
                    ok,
                    recovering,
                    summary,
                    status,
                    durationMs,
                    workerElapsedMs,
                    localCache,
                    endpoint: this.config.endpoint,
                    request: requestLog,
                    response: responseLog,
                });
            });
        }

        showDefaultError(error) {
            const presentation = resolveErrorPresentation(error || {});
            const message = presentation.message;
            const code = String((error && error.code) || '').trim();
            if (code === 'auth_error' && /nonce has already been used/i.test(String(error && error.message || ''))) {
                return;
            }
            if (/signing_secret/i.test(String(error && error.message || '')) && /null/i.test(String(error && error.message || ''))) {
                return;
            }
            if (code === 'auth_error' && /worker session is unavailable/i.test(String(error && error.message || ''))) {
                return;
            }
            if (isWorkerHandshakeCooldownNoise(error)) {
                return;
            }
            // autoReload already attempted in handleHttpError; keep as toast-path fallback.
            if (maybeAutoReloadForScopeConflict(presentation)) {
                return;
            }
            if (shouldDedupeDefaultErrorToast(presentation.code, message)) {
                return;
            }
            const toastOptions = {
                title: presentation.title,
                code: presentation.code,
                details: presentation.details,
                detailsLabel: presentation.detailsLabel,
                duration: presentation.details ? 12000 : 5200,
                tone: 'danger',
            };
            try {
                const Toast = this.resolveToastComponent();
                if (Toast) {
                    if (typeof Toast.error === 'function') {
                        Toast.error(message, toastOptions);
                        return;
                    }
                    if (typeof Toast.show === 'function') {
                        Toast.show(message, { ...toastOptions, tone: 'danger' });
                        return;
                    }
                }

                this.renderFallbackToast(
                    presentation.details
                        ? `${presentation.title}\n${message}\n${presentation.details}`
                        : `${presentation.title}\n${message}`
                );
            } catch (toastError) {
                console.error('[Weline.Api] showDefaultError failed:', toastError);
                try {
                    this.renderFallbackToast(message);
                } catch (fallbackError) {
                    console.error('[Weline.Api]', message, fallbackError);
                }
            }
        }

        resolveToastComponent() {
            const area = this.resolveArea();
            const themeNotice = window.Weline?.Theme?.Notice || window.Weline?.Notice || null;
            const backendCandidates = [
                window.Weline?.UI?.toast,
                window.Weline?.UI?.Toast,
                window.WelineBackendToast,
                window.Weline?.Toast,
                themeNotice,
                window.Toast,
            ];
            const frontendCandidates = [
                window.Weline?.UI?.toast,
                window.FrontendToast,
                window.WelineFrontendToast,
                window.Weline?.FrontendToast,
                window.Weline?.Toast,
                themeNotice,
                window.WeShop && typeof window.WeShop.showNotification === 'function'
                    ? { show: window.WeShop.showNotification.bind(window.WeShop) }
                    : null,
                // Bootstrap Toast 只有实例方法，不能当全局 Toast.error/show 用。
                window.Toast && (typeof window.Toast.error === 'function' || (
                    typeof window.Toast.show === 'function' && typeof window.Toast.getOrCreateInstance !== 'function'
                )) ? window.Toast : null,
            ];
            const candidates = area === 'backend'
                ? backendCandidates.concat(frontendCandidates)
                : frontendCandidates.concat(backendCandidates);

            return candidates.find((candidate) => {
                return candidate && (typeof candidate.error === 'function' || typeof candidate.show === 'function');
            }) || null;
        }

        resolveArea() {
            const explicitArea = String(
                this.config.area
                || window.WELINE_AREA
                || window.welineArea
                || (document.body && (document.body.dataset.welineArea || document.body.dataset.area))
                || ''
            ).toLowerCase();
            if (explicitArea.includes('backend') || explicitArea.includes('admin')) {
                return 'backend';
            }
            if (explicitArea.includes('frontend')) {
                return 'frontend';
            }
            const path = window.location && window.location.pathname ? window.location.pathname.toLowerCase() : '';
            return path.includes('/admin') || path.includes('/backend') ? 'backend' : 'frontend';
        }

        renderFallbackToast(message) {
            if (!document || !document.body) {
                console.error('[Weline.Api]', message);
                return;
            }

            const containerId = 'weline-api-toast-container';
            let container = document.getElementById(containerId);
            if (!container) {
                container = document.createElement('div');
                container.id = containerId;
                container.style.cssText = [
                    'position:fixed',
                    'right:1rem',
                    'bottom:1rem',
                    'z-index:2147483647',
                    'display:grid',
                    'gap:.5rem',
                    'max-width:min(28rem,calc(100vw - 2rem))',
                ].join(';');
                document.body.appendChild(container);
            }

            const item = document.createElement('div');
            item.textContent = message;
            item.style.cssText = [
                'border-radius:.75rem',
                'background:#b91c1c',
                'color:#fff',
                'box-shadow:0 16px 40px rgba(15,23,42,.22)',
                'font:600 14px/1.45 sans-serif',
                'padding:.85rem 1rem',
            ].join(';');
            container.appendChild(item);
            window.setTimeout(() => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(8px)';
                item.style.transition = 'opacity .18s ease, transform .18s ease';
                window.setTimeout(() => item.remove(), 220);
            }, 3500);
        }

        enableAutoRequests() {
            if (this.autoRequestsEnabled) {
                return;
            }
            this.autoRequestsEnabled = true;
            window.dispatchEvent(new CustomEvent('weline:api:auto-enabled'));
        }

        disableAutoRequests() {
            if (!this.autoRequestsEnabled) {
                return;
            }
            this.autoRequestsEnabled = false;
            window.dispatchEvent(new CustomEvent('weline:api:auto-disabled'));
        }
    }

    const ApiModule = {
        __full: true,
        request: () => getOrCreateClient().request(),
        get: () => getOrCreateClient().request(),
        post: () => getOrCreateClient().request(),
        call: (provider, operation, params, options) => getOrCreateClient().call(provider, operation, params, options),
        graph: (graph, options) => getOrCreateClient().graph(graph, options),
        stream: (channel, params, options) => getOrCreateClient().stream(channel, params, options),
        upload: (provider, operation, formData, options) => getOrCreateClient().upload(provider, operation, formData, options),
        resource: (provider, optionalMap) => getOrCreateClient().resource(provider, optionalMap),
        enableAutoRequests: () => getOrCreateClient().enableAutoRequests(),
        disableAutoRequests: () => getOrCreateClient().disableAutoRequests(),
        bootstrapScope: () => getOrCreateClient().warmup(),
        getClient: () => getOrCreateClient(),
        StreamHandle,
    };

    window.WelineApiModule = ApiModule;
    window.Weline = window.Weline || {};
    // Always promote — stub must not remain if full module loaded (order races with weline.js).
    window.Weline.Api = ApiModule;

    // Bind visibility renew only. Do NOT eager bootstrapScope / hole-punch on script load —
    // Worker ensureSession demand (or recoverScopeStamp) owns when a new visa is required.
    try {
        bindScopeStampVisibilityRenew();
    } catch (_bindError) {
        /* ignore — first QueryBin still drives session via warmup/recover */
    }
})(window);
