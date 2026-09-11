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

    const readScopeBootstrapId = () => {
        const nodes = document.querySelectorAll('meta[name="weline-worker-scope-bootstrap"]');
        if (nodes.length === 0) {
            return '';
        }
        if (nodes.length !== 1) {
            const error = new Error('[Weline.Api] expected exactly one worker Scope bootstrap marker.');
            error.code = 'scope_bootstrap_invalid';
            throw error;
        }

        const bootstrapId = String(nodes[0].getAttribute('content') || '').trim();
        if (!/^[A-Za-z0-9_-]{43}$/.test(bootstrapId)) {
            const error = new Error('[Weline.Api] worker Scope bootstrap marker is invalid.');
            error.code = 'scope_bootstrap_invalid';
            throw error;
        }
        return bootstrapId;
    };

    const normalizeLocale = (value) => {
        const locale = String(value || '').trim();
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
        return isDevMode()
            ? '/Weline/Frontend/view/statics/js/weline-dev-console.js'
            : '/static/Weline/Frontend/js/weline-dev-console.js';
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
        return [
            'wqrc1',
            String((config && config.deployVersion) || ''),
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
     * Prefer same-origin classic Worker URL (allowed by CSP worker-src/'self').
     * Fall back to Blob Worker when URL construction fails or CSP blocks it —
     * Blob path requires worker-src blob: (see SecurityHeaderDefaults::CSP).
     * Historical note: some storefronts left `new Worker(http URL)` Network-pending;
     * Blob bootstrap remains the recovery path after a short CSP/error probe.
     */
    const createDedicatedWorkerFromScriptUrl = (workerUrl, options = {}) => {
        if (!window.Worker) {
            return Promise.reject(new Error(
                '[Weline.Api] Worker is unavailable; direct frontend API fallback is disabled.'
            ));
        }
        const scriptUrl = String(workerUrl || '');
        if (!scriptUrl) {
            return Promise.reject(new Error('[Weline.Api] workerUrl is not configured.'));
        }
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
                return worker;
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
                resolve(worker);
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

        return createUrlWorker().catch(() => createBlobWorker());
    };

    const getDefaultWorkerUrl = () => {
        const isDev = isDevMode();
        if (isDev) {
            return '/Weline/Frontend/view/statics/js/weline-api-worker.js';
        }
        if (window.Weline && window.Weline.staticResourceResolver) {
            try {
                return window.Weline.staticResourceResolver.resolve('Weline_Frontend::js/weline-api-worker.js');
            } catch (error) {
                /* fall through */
            }
        }
        return '/static/Weline/Frontend/js/weline-api-worker.js';
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
            scopeBootstrapId: '',
        }, apiConfig);

        config.workerUrl = withDevCacheBust(sameOriginUrl(apiConfig.workerUrl || getDefaultWorkerUrl(), getDefaultWorkerUrl()));
        config.endpoint = sameOriginUrl(apiConfig.endpoint || apiConfig.queryBinUrl || '/api/framework/query-bin', '/api/framework/query-bin');
        config.deployVersion = String(apiConfig.deployVersion || apiConfig.deploy_version || config.deployVersion || 'dev');
        config.workerBuildId = String(apiConfig.workerBuildId || apiConfig.worker_build_id || config.workerBuildId || 'dev');
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
        // This value is intentionally sourced only from the server-injected
        // non-executable marker. Runtime/global JavaScript config is not a
        // trusted Scope authority.
        config.scopeBootstrapId = readScopeBootstrapId();
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
        if (String(client.config.deployVersion || '') !== String(freshConfig.deployVersion || '')) {
            client.responseCacheL1.clear();
        }
        client.config.deployVersion = freshConfig.deployVersion;
        client.config.workerBuildId = freshConfig.workerBuildId;
        client.config.locale = freshConfig.locale;
        client.config.pathname = freshConfig.pathname;
        client.config.currency = freshConfig.currency;
        client.config.defaultCurrency = freshConfig.defaultCurrency;
        client.config.availableCurrencies = freshConfig.availableCurrencies;
        client.config.area = freshConfig.area;
        if (client.config.scopeBootstrapId !== freshConfig.scopeBootstrapId) {
            const error = new Error('[Weline.Api] page Scope changed while the API client was active. Reload the page.');
            error.code = 'scope_context_conflict';
            throw error;
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
            const deploy = String(this.config.deployVersion || '');
            const locale = String(this.config.locale || '');
            const currency = String(this.config.currency || '');
            targets.forEach((target) => {
                const needle = '|' + String(target) + '|';
                Array.from(this.responseCacheL1.keys()).forEach((key) => {
                    if (String(key).indexOf(needle) !== -1
                        && String(key).indexOf('|' + deploy + '|') !== -1
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

        warmup() {
            if (!this.config.scopeBootstrapId) {
                return Promise.resolve(null);
            }
            if (this.scopeWarmupComplete) {
                return Promise.resolve(null);
            }
            if (!this.scopeWarmupPromise) {
                this.scopeWarmupPromise = this.sendToWorker({
                    type: 'scope-bootstrap',
                    options: { silent: true },
                }).then((result) => {
                    this.scopeWarmupComplete = true;
                    return result;
                }).finally(() => {
                    if (!this.scopeWarmupComplete) {
                        this.scopeWarmupPromise = null;
                    }
                });
            }
            return this.scopeWarmupPromise;
        }

        send(payload) {
            return this.dispatchToWorker(payload).catch((error) => {
                if (!error || error.code !== 'worker_timeout' || (payload && payload.__workerRetry)) {
                    throw error;
                }
                // Concurrent cart/account/bootstrap timeouts must share one recreate;
                // otherwise each retry fetch()s weline-api-worker.js and floods Network.
                return this.recoverWorkerAfterTimeout().then(() => {
                    const retryPayload = Object.assign({}, payload, { __workerRetry: true });
                    return this.dispatchToWorker(retryPayload);
                });
            });
        }

        dispatchToWorker(payload) {
            if (payload && payload.type !== 'scope-bootstrap' && this.config.scopeBootstrapId) {
                return this.warmup().then(() => this.sendToWorker(payload));
            }
            return this.sendToWorker(payload);
        }

        recoverWorkerAfterTimeout() {
            if (this.workerRecoverPromise) {
                return this.workerRecoverPromise;
            }
            this.workerRecoverPromise = Promise.resolve().then(() => {
                this.resetWorker();
                this.scopeWarmupComplete = false;
                this.scopeWarmupPromise = null;
                return this.ensureWorker();
            }).finally(() => {
                this.workerRecoverPromise = null;
            });
            return this.workerRecoverPromise;
        }

        resetWorker() {
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
                        locale: this.config.locale,
                        pathname: this.config.pathname || (window.location && window.location.pathname) || '/',
                        currency: this.config.currency,
                        defaultCurrency: this.config.defaultCurrency,
                        availableCurrencies: this.config.availableCurrencies,
                        scopeBootstrapId: this.config.scopeBootstrapId,
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
                    this.finishDevTrace(data.id, {
                        ok: false,
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
                    this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions, pending.payload);
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
            error.response = {
                ok: false,
                status: data.status || 0,
                statusText: data.statusText || '',
                data: wrapper,
                maintenance: !!data.maintenance,
            };
            const requestOptions = pending.payload && pending.payload.options;
            this.finishDevTrace(data.id, {
                ok: false,
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
            this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions, pending.payload);
            pending.reject(error);
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
            this.resetWorker();
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
            const message = error && error.message ? error.message : 'Request failed';
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
            try {
                const Toast = this.resolveToastComponent();
                if (Toast) {
                    if (typeof Toast.error === 'function') {
                        Toast.error(message);
                        return;
                    }
                    if (typeof Toast.show === 'function') {
                        Toast.show(message, 'error');
                        return;
                    }
                }

                this.renderFallbackToast(message);
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
    if (!window.Weline.Api || window.Weline.Api.__fallback === true) {
        window.Weline.Api = ApiModule;
    }

    try {
        if (readScopeBootstrapId() !== '') {
            ApiModule.bootstrapScope().catch((error) => {
                window.dispatchEvent(new CustomEvent('weline:scope-bootstrap-failed', {
                    detail: {
                        code: error && error.code ? error.code : 'scope_bootstrap_invalid',
                        status: error && error.status ? error.status : 0,
                    },
                }));
            });
        }
    } catch (error) {
        window.dispatchEvent(new CustomEvent('weline:scope-bootstrap-failed', {
            detail: {
                code: error && error.code ? error.code : 'scope_bootstrap_invalid',
                status: 0,
            },
        }));
    }
})(window);
