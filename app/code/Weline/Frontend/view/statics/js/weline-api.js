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

    const isDevMode = () => !!(window.DEV || window.WELINE_ENV === 'DEV');

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
        return String(payload.type || 'request');
    };

    const cloneDevLogValue = (value) => {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return value;
        }
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

    const withDevCacheBust = (url) => {
        const isDev = isDevMode() || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        if (!isDev) {
            return url;
        }
        const workerUrl = new URL(url, window.location.origin);
        if (!workerUrl.searchParams.has('_weline_worker_dev')) {
            workerUrl.searchParams.set('_weline_worker_dev', String(Date.now()));
        }
        return workerUrl.href;
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
            cartFlagStorageKey: 'weline_cart_has_items',
            cartProbeSessionKey: 'weline_cart_probe_done',
            cartCountCookieKey: 'weline_cart_item_count',
            autoEnableOnCartClickSelector: '[data-weline-cart-trigger]',
            area: '',
            locale: '',
            currency: '',
            onHttpError: null,
            requestTimeoutMs: 15000,
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
        config.locale = normalizeLocale(apiConfig.locale || apiConfig.currentLang || runtimeConfig.currentLang || config.locale);
        config.currency = normalizeCurrency(apiConfig.currency || apiConfig.currentCurrency || runtimeConfig.currentCurrency || config.currency, config);
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
        client.config.deployVersion = freshConfig.deployVersion;
        client.config.workerBuildId = freshConfig.workerBuildId;
        client.config.locale = freshConfig.locale;
        client.config.currency = freshConfig.currency;
        client.config.defaultCurrency = freshConfig.defaultCurrency;
        client.config.availableCurrencies = freshConfig.availableCurrencies;
        client.config.area = freshConfig.area;
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

        isTerminalEvent(event) {
            const type = String(event.type || '').toLowerCase();
            if (this.terminalEvents.has(type)) {
                return true;
            }
            let payload = event.data;
            if (typeof payload === 'string') {
                try {
                    payload = JSON.parse(payload);
                } catch (error) {
                    return false;
                }
            }
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
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
            this.requestId = 0;
            this.pending = new Map();
            this.devTraces = new Map();
            this.autoRequestsEnabled = false;

            this.handleWorkerMessage = this.handleWorkerMessage.bind(this);
            this.handleWorkerError = this.handleWorkerError.bind(this);

            this.restoreCartState();
            this.listenCartTriggers();
            this.listenCartUpdateEvent();
        }

        call(provider, operation, params = {}, options = {}) {
            if (!provider || !operation) {
                return Promise.reject(new Error('[Weline.Api] provider and operation are required.'));
            }
            return this.send({
                type: 'call',
                provider,
                operation,
                params: normalizeCallParams(params),
                options,
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
                error.code = body?.error?.code || 'business_error';
                error.status = response.status || 0;
                error.response = {
                    ok: false,
                    status: response.status || 0,
                    statusText: response.statusText || '',
                    data: body,
                    maintenance: false,
                };
                this.reportDevError(error, { type: 'upload', provider, operation, options, skipConsole: true });
                this.handleHttpError(error.status, error, options && options.silent, options);
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

        send(payload) {
            this.ensureWorker();
            const messageId = this.buildMessageId();
            return new Promise((resolve, reject) => {
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
                    this.finishDevTrace(messageId, {
                        ok: false,
                        error: { code: error.code, message: error.message },
                    });
                    this.reportDevError(error, { type: 'timeout', request: pending && pending.payload, skipConsole: true });
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
                        currency: this.config.currency,
                        defaultCurrency: this.config.defaultCurrency,
                        availableCurrencies: this.config.availableCurrencies,
                    },
                }));
            });
        }

        ensureWorker() {
            if (this.worker) {
                return;
            }
            if (!window.Worker) {
                throw new Error('[Weline.Api] Worker is unavailable; direct frontend API fallback is disabled.');
            }
            if (!this.config.workerUrl) {
                throw new Error('[Weline.Api] workerUrl is not configured.');
            }

            this.worker = new Worker(this.config.workerUrl);
            this.worker.addEventListener('message', this.handleWorkerMessage);
            this.worker.addEventListener('error', this.handleWorkerError);
            this.worker.addEventListener('messageerror', this.handleWorkerError);
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
                    this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions);
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
            this.handleHttpError(error.status, error, requestOptions && requestOptions.silent, requestOptions);
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
                pending.reject(error);
            }
        }

        handleHttpError(status, error, silent, requestOptions) {
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

        reportDevError(error, detail) {
            if (!isDevMode() || !error) {
                return;
            }
            if (error._welineApiDevLogged) {
                return;
            }
            error._welineApiDevLogged = true;
            if (detail && detail.skipConsole) {
                return;
            }
            this.logDevError('request failed', error, detail);
        }

        beginDevTrace(messageId, payload) {
            if (!isDevMode() || !messageId) {
                return;
            }
            this.devTraces.set(messageId, {
                startedAt: performance.now(),
                payload: payload || {},
            });
        }

        finishDevTrace(messageId, responseMeta, requestPayloadOverride, startedAtOverride) {
            if (!isDevMode()) {
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
            const status = responseMeta && responseMeta.status ? ` ${responseMeta.status}` : '';
            const label = `[Weline.Api] ${ok ? '✓' : '✗'} ${summary}${status} · ${durationMs}ms`;

            const requestLog = cloneDevLogValue(sanitizePayloadForWorker(requestPayload));
            const responseLog = cloneDevLogValue(responseMeta || {});

            if (typeof console.groupCollapsed === 'function') {
                console.groupCollapsed(label);
                console.log('endpoint:', this.config.endpoint);
                console.log('request:', requestLog);
                console.log('response:', responseLog);
                console.groupEnd();
                return;
            }
            console.log(label, { endpoint: this.config.endpoint, request: requestLog, response: responseLog });
        }

        logDevError(context, error, detail) {
            const label = `[Weline.Api] ${context}`;
            const extra = detail && typeof detail === 'object' ? detail : {};
            if (typeof console.groupCollapsed === 'function') {
                console.groupCollapsed(label);
                console.error(error);
                if (error && error.response) {
                    console.log('response:', error.response);
                }
                if (Object.keys(extra).length > 0) {
                    console.log('detail:', extra);
                }
                console.groupEnd();
                return;
            }
            console.error(label, error, error && error.response ? { response: error.response } : null, extra);
        }

        showDefaultError(error) {
            const message = error && error.message ? error.message : 'Request failed';
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

            if (typeof window.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('weline:api:error', {
                    detail: { message, error },
                }));
            }

            this.renderFallbackToast(message);
        }

        resolveToastComponent() {
            const area = this.resolveArea();
            const backendCandidates = [
                window.BackendToast,
                window.AdminToast,
                window.WelineBackendToast,
                window.Weline?.BackendToast,
                window.Weline?.Toast,
                window.Toast,
            ];
            const frontendCandidates = [
                window.FrontendToast,
                window.WelineFrontendToast,
                window.Weline?.FrontendToast,
                window.WeShop && typeof window.WeShop.showNotification === 'function'
                    ? { show: window.WeShop.showNotification.bind(window.WeShop) }
                    : null,
                window.Weline?.Toast,
                window.Toast,
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

        restoreCartState() {
            try {
                const cookieFlag = this.readCartCookie();
                if (cookieFlag === true) {
                    this.markCartActive();
                } else if (cookieFlag === false) {
                    this.markCartEmpty();
                } else {
                    const storedFlag = localStorage.getItem(this.config.cartFlagStorageKey);
                    if (storedFlag === 'true' && this.hasCartCookieToken()) {
                        this.enableAutoRequests();
                    }
                }
            } catch (error) {
                /* storage may be unavailable */
            }
        }

        hasCartCookieToken() {
            const key = this.config.cartCountCookieKey;
            if (!key || !document.cookie) {
                return false;
            }
            return document.cookie.split('; ').some((row) => row.startsWith(`${key}=`));
        }

        readCartCookie() {
            const key = this.config.cartCountCookieKey;
            if (!key || !document.cookie) {
                return null;
            }
            const match = document.cookie.split('; ').find((row) => row.startsWith(`${key}=`));
            if (!match) {
                return null;
            }
            const value = parseInt(match.split('=')[1] || '0', 10);
            if (Number.isNaN(value)) {
                return null;
            }
            return value > 0 ? true : value === 0 ? false : null;
        }

        listenCartTriggers() {
            document.addEventListener('click', (event) => {
                const selector = this.config.autoEnableOnCartClickSelector;
                if (!selector || !(event.target instanceof Element)) {
                    return;
                }
                if (event.target.closest(selector)) {
                    this.markCartActive();
                }
            }, { passive: true });
        }

        listenCartUpdateEvent() {
            const handleCartCountUpdate = (event) => {
                const detail = event.detail || {};
                const count = typeof detail.count === 'number'
                    ? detail.count
                    : typeof detail.cart_count === 'number'
                        ? detail.cart_count
                        : null;
                if (count === null) {
                    return;
                }
                if (count > 0) {
                    this.markCartActive();
                } else {
                    this.markCartEmpty();
                }
            };

            window.addEventListener('weline:cart:update', handleCartCountUpdate);
            window.addEventListener('weshop:cart:updated', handleCartCountUpdate);
        }

        markCartActive() {
            try {
                localStorage.setItem(this.config.cartFlagStorageKey, 'true');
            } catch (error) {
                /* storage may be unavailable */
            }
            this.enableAutoRequests();
        }

        markCartEmpty() {
            try {
                localStorage.removeItem(this.config.cartFlagStorageKey);
            } catch (error) {
                /* storage may be unavailable */
            }
            this.disableAutoRequests();
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
        markCartActive: () => getOrCreateClient().markCartActive(),
        markCartEmpty: () => getOrCreateClient().markCartEmpty(),
        enableAutoRequests: () => getOrCreateClient().enableAutoRequests(),
        disableAutoRequests: () => getOrCreateClient().disableAutoRequests(),
        getClient: () => getOrCreateClient(),
        StreamHandle,
    };

    window.WelineApiModule = ApiModule;
    window.Weline = window.Weline || {};
    if (!window.Weline.Api || window.Weline.Api.__fallback === true) {
        window.Weline.Api = ApiModule;
    }
})(window);
