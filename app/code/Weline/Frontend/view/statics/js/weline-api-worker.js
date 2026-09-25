/* eslint-disable no-restricted-globals */
(function () {
    'use strict';

    const MAGIC = [0x57, 0x51, 0x42, 0x31]; // WQB1
    const VERSION = 1;
    const MAX_DEPTH = 32;
    const MAX_LIST_ITEMS = 2000;
    // Keep in sync with Weline\\Framework\\Binary\\Limits::MAP_KEYS (i18n/widget bags).
    const MAX_MAP_KEYS = 2000;
    const MAX_STRING_BYTES = 2097152;
    const CONTENT_TYPE = 'application/x-weline-query-bin';
    const PROTOCOL = 'worker-query-bin-v1';
    const WORKER_PROTOCOL = 'weline-worker-request-v1';
    const SIGNED_PATH = '/api/framework/query-bin';
    const MAX_SAFE_INTEGER = Number.MAX_SAFE_INTEGER;
    const encoder = new TextEncoder();
    const decoder = new TextDecoder('utf-8', { fatal: true });

    let workerSession = null;
    let workerScopeBootstrapId = '';
    let workerBackendBootstrapId = '';
    let handshakePromise = null;
    let handshakeCooldownUntil = 0;
    let handshakeBackoffMs = 0;
    // Interactive commerce/UI calls (cart, purchase panel, …) must not wait behind
    // fire-and-forget telemetry. Nonces are unique per request; handshake stays shared.
    let signedRequestChain = Promise.resolve();
    let signedTelemetryChain = Promise.resolve();
    const TELEMETRY_CAPABILITIES = Object.freeze({
        'visitor.trackPixel': true,
    });

    // Browser-side QueryBin response cache (TTL + in-flight dedupe).
    // HTTP fetch stays cache:'no-store'; this layer skips the signed POST when fresh.
    const responseCacheMemory = new Map();
    const responseCacheInflight = new Map();
    let responseCacheDbPromise = null;
    /** Active deploy stamp for response cache; mismatch → wipe local storage. */
    let activeResponseCacheDeployVersion = '';
    const RESPONSE_CACHE_DB = 'weline-querybin-response-cache';
    const RESPONSE_CACHE_STORE = 'entries';
    const RESPONSE_CACHE_DB_VERSION = 1;
    const RESPONSE_CACHE_MAX_BYTES = 750000;
    const RESPONSE_CACHE_TTL_MS = Object.freeze({
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
    const RESPONSE_CACHE_INVALIDATE = Object.freeze({
        'consent.accept': ['consent.status'],
        'consent.withdraw': ['consent.status'],
        'order.saveCheckoutRemark': ['order.getCheckoutRemark'],
        'order.clearCheckoutRemark': ['order.getCheckoutRemark'],
        'compare.add': ['compare.list', 'compare.pageView'],
        'compare.remove': ['compare.list', 'compare.pageView'],
        'compare.clear': ['compare.list', 'compare.pageView'],
    });

    self.addEventListener('message', async (event) => {
        const message = event.data || {};
        const id = message.id;
        if (!id) return;
        const workerStartedAt = (typeof performance !== 'undefined' && performance.now)
            ? performance.now()
            : Date.now();

        const workerElapsedMs = () => Math.max(0, Math.round(
            ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now()) - workerStartedAt
        ));

        try {
            const config = normalizeConfig(message.config || {});
            await ensureResponseCacheDeployVersion(config);
            if (message.type === 'scope-bootstrap' || message.type === 'backend-bootstrap') {
                const session = await ensureSession(config);
                self.postMessage({
                    id,
                    ok: true,
                    status: 200,
                    statusText: 'OK',
                    headers: {},
                    workerElapsedMs: workerElapsedMs(),
                    body: {
                        ok: true,
                        data: {
                            scope_bound: session.scope_bound === true,
                            attested_area: String(session.attested_area || 'frontend'),
                            expires_at: Number(session.expires_at || 0),
                        },
                        error: null,
                        request_id: '',
                    },
                    maintenance: false,
                });
                return;
            }
            const packetPayload = buildPayload(message, config);
            const capability = resolveCapability(packetPayload);
            let result = await dispatchCachedOrNetwork(message, config, packetPayload, capability);
            self.postMessage({
                id,
                ok: result.responseOk && result.body && result.body.ok === true,
                status: result.status,
                statusText: result.statusText,
                headers: result.headers,
                workerElapsedMs: workerElapsedMs(),
                body: result.body,
                maintenance: detectMaintenance(result.status, result.body, result.headers),
            });
        } catch (error) {
            self.postMessage({
                id,
                ok: false,
                status: error && error.status ? error.status : 0,
                statusText: '',
                headers: {},
                workerElapsedMs: workerElapsedMs(),
                body: {
                    ok: false,
                    data: null,
                    error: {
                        code: error && error.code ? error.code : 'protocol_error',
                        message: error instanceof Error ? error.message : String(error),
                    },
                    request_id: '',
                },
                maintenance: false,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    });

    function normalizeConfig(config) {
        const deployVersion = String(config.deployVersion || config.deploy_version || 'dev').trim();
        const workerBuildId = String(config.workerBuildId || config.worker_build_id || 'dev').trim();
        if (!/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/.test(deployVersion)
            || !/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/.test(workerBuildId)) {
            throw Object.assign(new Error('Invalid Weline worker deploy/build identifier.'), {
                code: 'protocol_error',
            });
        }
        const rawScopeBootstrapId = String(
            config.scopeBootstrapId || config.scope_bootstrap_id || ''
        ).trim();
        if (rawScopeBootstrapId !== '' && !/^[A-Za-z0-9_-]{43}$/.test(rawScopeBootstrapId)) {
            throw Object.assign(new Error('Invalid Weline worker Scope bootstrap ID.'), {
                code: 'scope_bootstrap_invalid',
            });
        }
        const rawBackendBootstrapId = String(
            config.backendBootstrapId || config.backend_bootstrap_id || ''
        ).trim();
        if (rawBackendBootstrapId !== '' && !/^[A-Za-z0-9_-]{43}$/.test(rawBackendBootstrapId)) {
            throw Object.assign(new Error('Invalid Weline worker backend bootstrap ID.'), {
                code: 'backend_attestation_invalid',
            });
        }
        if (rawScopeBootstrapId !== '' && rawBackendBootstrapId !== '') {
            throw Object.assign(new Error('Worker storefront and backend bootstraps are mutually exclusive.'), {
                code: 'protocol_error',
            });
        }
        const normalized = {
            endpoint: config.endpoint || '/api/framework/query-bin',
            deployVersion,
            workerBuildId,
            locale: normalizeLocale(
                config.locale
                || config.currentLang
                || config.current_lang
                || detectPathLanguage(config.pathname || config.path || '')
                || ''
            ),
            pathname: String(config.pathname || config.path || ''),
            defaultCurrency: normalizeCurrencyCode(config.defaultCurrency || config.default_currency || 'CNY'),
            availableCurrencies: normalizeCurrencyList(
                config.availableCurrencies || config.supportedCurrencies || config.currencyCodes || config.currencies || []
            ),
            scopeBootstrapId: rawScopeBootstrapId,
            backendBootstrapId: rawBackendBootstrapId,
        };
        normalized.currency = normalizeCurrency(config.currency || config.currentCurrency || config.current_currency || '', normalized);

        return normalized;
    }

    function normalizeLocale(value) {
        const locale = String(value || '').trim();
        return /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/.test(locale) ? locale : '';
    }

    const LOCALE_PATH_PATTERN = /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/i;

    function detectPathLanguage(pathname) {
        const parts = String(pathname || '/').split('/').filter(Boolean);
        for (let i = 0; i < parts.length; i += 1) {
            if (LOCALE_PATH_PATTERN.test(parts[i])) {
                return String(parts[i]).trim().replace(/-/g, '_');
            }
        }
        return '';
    }

    function normalizeCurrencyCode(value) {
        return String(value || '').trim().toUpperCase();
    }

    function isCurrencyCodeShape(value) {
        return /^[A-Z]{3}$/.test(normalizeCurrencyCode(value));
    }

    function normalizeCurrencyList(values) {
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
    }

    function isSupportedCurrencyCode(value, config) {
        const code = normalizeCurrencyCode(value);
        if (!isCurrencyCodeShape(code)) {
            return false;
        }
        // Explicit allow-list only — defaultCurrency alone must not reject path/SSR USD.
        const explicit = normalizeCurrencyList(
            config && (config.availableCurrencies || config.supportedCurrencies || config.currencyCodes || config.currencies) || []
        );
        if (explicit.length === 0) {
            return true;
        }
        const supported = {};
        explicit.forEach((entry) => {
            supported[entry] = true;
        });
        if (config && config.defaultCurrency) {
            supported[normalizeCurrencyCode(config.defaultCurrency)] = true;
        }
        return supported[code] === true;
    }

    function normalizeCurrency(value, config) {
        const currency = normalizeCurrencyCode(value);
        return isSupportedCurrencyCode(currency, config || {}) ? currency : '';
    }

    function withContext(payload, config) {
        const context = {};
        if (config.locale) {
            context.locale = config.locale;
        }
        if (config.currency) {
            context.currency = config.currency;
        }
        // Document path (not Worker script Referer). QueryBin uses this when
        // Scope-kernel is off so path-mounted sites like /daocharms resolve.
        const pathname = String(config.pathname || config.path || '').trim();
        if (pathname.charAt(0) === '/' && pathname.indexOf('://') === -1) {
            context.pathname = pathname.length > 1024 ? pathname.slice(0, 1024) : pathname;
        }
        if (Object.keys(context).length > 0) {
            payload.context = context;
        }
        return payload;
    }

    function buildPayload(message, config) {
        if (message.type === 'call') {
            return withContext({
                type: 'call',
                provider: String(message.provider || ''),
                operation: String(message.operation || ''),
                params: normalizeMap(message.params),
            }, config);
        }
        if (message.type === 'graph') {
            return withContext({
                type: 'graph',
                graph: message.graph || message.operations || {},
            }, config);
        }
        if (message.type === 'stream-ticket') {
            return withContext({
                type: 'stream-ticket',
                channel: String(message.channel || ''),
                params: normalizeMap(message.params),
            }, config);
        }
        throw Object.assign(new Error('Unsupported Weline worker request type.'), { code: 'protocol_error' });
    }

    function resolveCapability(payload) {
        if (payload.type === 'call') {
            return `${payload.provider}.${payload.operation}`;
        }
        if (payload.type === 'graph') {
            return 'graph';
        }
        if (payload.type === 'stream-ticket') {
            return 'stream-ticket';
        }
        throw Object.assign(new Error('Unsupported Weline worker capability.'), { code: 'protocol_error' });
    }

    function wantsBypassResponseCache(message) {
        const options = message && message.options && typeof message.options === 'object'
            ? message.options
            : {};
        return options.bypassCache === true
            || options.noCache === true
            || options.cache === false
            || options.refresh === true;
    }

    function responseCacheTtlMs(capability) {
        const ttl = RESPONSE_CACHE_TTL_MS[String(capability || '')];
        return typeof ttl === 'number' && ttl > 0 ? ttl : 0;
    }

    function stableStringify(value) {
        if (value === null || typeof value !== 'object') {
            return JSON.stringify(value);
        }
        if (Array.isArray(value)) {
            return '[' + value.map(stableStringify).join(',') + ']';
        }
        const keys = Object.keys(value).sort();
        return '{' + keys.map((key) => JSON.stringify(key) + ':' + stableStringify(value[key])).join(',') + '}';
    }

    function canonicalizeResponseCacheParams(capability, params) {
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
    }

    function buildResponseCacheKey(config, payload, capability) {
        return [
            'wqrc1',
            String(config.deployVersion || ''),
            String(config.locale || ''),
            String(config.currency || ''),
            String(capability || ''),
            stableStringify(canonicalizeResponseCacheParams(capability, payload && payload.params)),
        ].join('|');
    }

    function withCacheHeader(result, state) {
        const headers = Object.assign({}, (result && result.headers) || {});
        headers['X-Weline-Worker-Response-Cache'] = String(state || 'miss');
        return Object.assign({}, result, { headers });
    }

    function cloneCacheResult(entry) {
        return {
            responseOk: entry.responseOk === true,
            status: Number(entry.status) || 200,
            statusText: String(entry.statusText || 'OK'),
            headers: Object.assign({}, entry.headers || {}),
            body: entry.body,
        };
    }

    function openResponseCacheDb() {
        if (responseCacheDbPromise) {
            return responseCacheDbPromise;
        }
        if (typeof indexedDB === 'undefined') {
            responseCacheDbPromise = Promise.resolve(null);
            return responseCacheDbPromise;
        }
        responseCacheDbPromise = new Promise((resolve) => {
            let request;
            try {
                request = indexedDB.open(RESPONSE_CACHE_DB, RESPONSE_CACHE_DB_VERSION);
            } catch (_error) {
                resolve(null);
                return;
            }
            request.onerror = () => resolve(null);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(RESPONSE_CACHE_STORE)) {
                    db.createObjectStore(RESPONSE_CACHE_STORE, { keyPath: 'key' });
                }
            };
            request.onsuccess = () => resolve(request.result || null);
        });
        return responseCacheDbPromise;
    }

    async function readPersistedResponseCache(key) {
        const db = await openResponseCacheDb();
        if (!db) {
            return null;
        }
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(RESPONSE_CACHE_STORE, 'readonly');
                const req = tx.objectStore(RESPONSE_CACHE_STORE).get(key);
                req.onsuccess = () => resolve(req.result || null);
                req.onerror = () => resolve(null);
            } catch (_error) {
                resolve(null);
            }
        });
    }

    async function writePersistedResponseCache(entry) {
        const db = await openResponseCacheDb();
        if (!db || !entry || !entry.key) {
            return;
        }
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(RESPONSE_CACHE_STORE, 'readwrite');
                tx.objectStore(RESPONSE_CACHE_STORE).put(entry);
                tx.oncomplete = () => resolve();
                tx.onerror = () => resolve();
                tx.onabort = () => resolve();
            } catch (_error) {
                resolve();
            }
        });
    }

    async function deletePersistedResponseCacheByPrefix(prefix) {
        const db = await openResponseCacheDb();
        if (!db) {
            return;
        }
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(RESPONSE_CACHE_STORE, 'readwrite');
                const store = tx.objectStore(RESPONSE_CACHE_STORE);
                const req = store.openCursor();
                req.onsuccess = () => {
                    const cursor = req.result;
                    if (!cursor) {
                        return;
                    }
                    const value = cursor.value;
                    if (value && typeof value.key === 'string' && value.key.indexOf(prefix) !== -1) {
                        cursor.delete();
                    }
                    cursor.continue();
                };
                tx.oncomplete = () => resolve();
                tx.onerror = () => resolve();
                tx.onabort = () => resolve();
            } catch (_error) {
                resolve();
            }
        });
    }

    /**
     * Drop memory + IndexedDB entries that do not belong to the page deployVersion
     * (Weline.config.deployVersion). Keys are prefixed with wqrc1|{deploy}|…
     */
    async function clearResponseCacheNotMatchingDeploy(deployVersion) {
        const keep = String(deployVersion || '');
        const keepPrefix = 'wqrc1|' + keep + '|';
        Array.from(responseCacheMemory.keys()).forEach((key) => {
            if (String(key).indexOf(keepPrefix) !== 0) {
                responseCacheMemory.delete(key);
            }
        });
        Array.from(responseCacheInflight.keys()).forEach((key) => {
            if (String(key).indexOf(keepPrefix) !== 0) {
                responseCacheInflight.delete(key);
            }
        });
        const db = await openResponseCacheDb();
        if (!db) {
            return;
        }
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(RESPONSE_CACHE_STORE, 'readwrite');
                const store = tx.objectStore(RESPONSE_CACHE_STORE);
                const req = store.openCursor();
                req.onsuccess = () => {
                    const cursor = req.result;
                    if (!cursor) {
                        return;
                    }
                    const value = cursor.value || {};
                    const key = typeof value.key === 'string' ? value.key : '';
                    const entryDeploy = String(value.deployVersion || '');
                    if (key.indexOf(keepPrefix) !== 0 || (entryDeploy !== '' && entryDeploy !== keep)) {
                        cursor.delete();
                    }
                    cursor.continue();
                };
                tx.oncomplete = () => resolve();
                tx.onerror = () => resolve();
                tx.onabort = () => resolve();
            } catch (_error) {
                resolve();
            }
        });
    }

    async function ensureResponseCacheDeployVersion(config) {
        const next = String((config && config.deployVersion) || '');
        if (activeResponseCacheDeployVersion === next) {
            return;
        }
        const previous = activeResponseCacheDeployVersion;
        activeResponseCacheDeployVersion = next;
        if (previous === '') {
            // First message in this worker process: still purge foreign deploy rows
            // left from an earlier page generation in the same browser profile.
            await clearResponseCacheNotMatchingDeploy(next);
            return;
        }
        if (workerSession && workerSession.deploy_version !== next) {
            workerSession = null;
        }
        await clearResponseCacheNotMatchingDeploy(next);
    }

    function memoryGetFresh(key) {
        const entry = responseCacheMemory.get(key);
        if (!entry) {
            return null;
        }
        if (Number(entry.expiresAt) <= Date.now()) {
            responseCacheMemory.delete(key);
            return null;
        }
        return entry;
    }

    async function loadFreshResponseCache(key) {
        const memory = memoryGetFresh(key);
        if (memory) {
            return memory;
        }
        const persisted = await readPersistedResponseCache(key);
        if (!persisted || Number(persisted.expiresAt) <= Date.now()) {
            return null;
        }
        responseCacheMemory.set(key, persisted);
        return persisted;
    }

    async function storeResponseCache(key, ttlMs, config, result) {
        if (!key || !(ttlMs > 0) || !result || result.responseOk !== true || !result.body || result.body.ok !== true) {
            return;
        }
        let encodedBytes = 0;
        try {
            encodedBytes = encoder.encode(JSON.stringify(result.body)).length;
        } catch (_error) {
            return;
        }
        if (encodedBytes <= 0 || encodedBytes > RESPONSE_CACHE_MAX_BYTES) {
            return;
        }
        const entry = {
            key,
            expiresAt: Date.now() + ttlMs,
            deployVersion: String(config.deployVersion || ''),
            responseOk: true,
            status: Number(result.status) || 200,
            statusText: String(result.statusText || 'OK'),
            headers: Object.assign({}, result.headers || {}),
            body: result.body,
        };
        responseCacheMemory.set(key, entry);
        await writePersistedResponseCache(entry);
    }

    async function invalidateResponseCacheCapabilities(capabilities, config) {
        const list = Array.isArray(capabilities) ? capabilities : [];
        if (list.length === 0) {
            return;
        }
        const deploy = String(config.deployVersion || '');
        const locale = String(config.locale || '');
        const currency = String(config.currency || '');
        for (const capability of list) {
            const needle = '|' + String(capability) + '|';
            for (const key of Array.from(responseCacheMemory.keys())) {
                if (String(key).indexOf(needle) !== -1
                    && String(key).indexOf('|' + deploy + '|') !== -1
                    && String(key).indexOf('|' + locale + '|') !== -1
                    && String(key).indexOf('|' + currency + '|') !== -1) {
                    responseCacheMemory.delete(key);
                }
            }
            await deletePersistedResponseCacheByPrefix(needle);
        }
    }

    async function maybeInvalidateAfterWrite(payload, capability, config, result) {
        if (!result || result.responseOk !== true || !result.body || result.body.ok !== true) {
            return;
        }
        const targets = RESPONSE_CACHE_INVALIDATE[String(capability || '')];
        if (!targets || targets.length === 0) {
            return;
        }
        await invalidateResponseCacheCapabilities(targets, config);
    }

    async function dispatchCachedOrNetwork(message, config, packetPayload, capability) {
        const ttlMs = packetPayload && packetPayload.type === 'call' ? responseCacheTtlMs(capability) : 0;
        if (!(ttlMs > 0) || wantsBypassResponseCache(message)) {
            const result = await executeSignedRequest(config, packetPayload, capability);
            await maybeInvalidateAfterWrite(packetPayload, capability, config, result);
            return withCacheHeader(result, ttlMs > 0 ? 'bypass' : 'live');
        }

        const key = buildResponseCacheKey(config, packetPayload, capability);
        const cached = await loadFreshResponseCache(key);
        if (cached) {
            return withCacheHeader(cloneCacheResult(cached), 'hit');
        }

        const inflight = responseCacheInflight.get(key);
        if (inflight) {
            const shared = await inflight;
            return withCacheHeader(shared, 'inflight');
        }

        const promise = (async () => {
            const networkResult = await executeSignedRequest(config, packetPayload, capability);
            if (networkResult.responseOk && networkResult.body && networkResult.body.ok === true) {
                await storeResponseCache(key, ttlMs, config, networkResult);
            }
            return networkResult;
        })();
        responseCacheInflight.set(key, promise);
        try {
            const networkResult = await promise;
            return withCacheHeader(networkResult, 'miss');
        } finally {
            if (responseCacheInflight.get(key) === promise) {
                responseCacheInflight.delete(key);
            }
        }
    }

    function normalizeMap(value) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return {};
        }
        return value;
    }

    async function ensureSession(config) {
        const now = Math.floor(Date.now() / 1000);
        if (
            workerSession &&
            workerSession.expires_at > now + 5 &&
            workerSession.deploy_version === config.deployVersion &&
            workerSession.worker_build_id === config.workerBuildId &&
            workerScopeBootstrapId === config.scopeBootstrapId &&
            workerBackendBootstrapId === config.backendBootstrapId
        ) {
            return workerSession;
        }

        if (workerSession && workerSession.scope_bound === true && workerSession.attested_area !== 'backend') {
            throw Object.assign(new Error('The page Scope has expired or changed. Reload the page to continue.'), {
                code: 'scope_reload_required',
                status: 401,
            });
        }
        if (workerSession && workerSession.attested_area === 'backend') {
            // Backend PHP Session may still be valid while the Worker token or
            // page bootstrap bridge is stale (WLS reload, deploy rotation, etc.).
            workerSession = null;
        }
        if (workerSession && (
            workerScopeBootstrapId !== config.scopeBootstrapId ||
            workerBackendBootstrapId !== config.backendBootstrapId
        )) {
            throw Object.assign(new Error('The page Worker bootstrap changed while this worker was active.'), {
                code: 'scope_context_conflict',
                status: 409,
            });
        }

        if (!handshakePromise) {
            handshakePromise = (async () => {
                const session = await handshake(config);
                workerSession = session;
                workerScopeBootstrapId = config.scopeBootstrapId;
                workerBackendBootstrapId = config.backendBootstrapId;
                return session;
            })().finally(() => {
                handshakePromise = null;
            });
        }

        return handshakePromise;
    }

    function sleepMs(ms) {
        const wait = Math.max(0, Number(ms) || 0);
        if (wait < 1) {
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            setTimeout(resolve, wait);
        });
    }

    async function handshake(config) {
        // Capacity/auth pressure sets a cooldown. Wait (capped) then retry once —
        // never fail-fast with a synthetic "cooling down" error that spam-toasts
        // every concurrent QueryBin caller on the same page (e.g. checkout success).
        const nowMs = Date.now();
        if (handshakeCooldownUntil > nowMs) {
            const waitMs = handshakeCooldownUntil - nowMs;
            // Cap so outer Weline.Api worker_timeout (~15s) can still recover.
            await sleepMs(Math.min(waitMs, 4000));
        }
        const handshakePayload = {
            type: 'handshake',
            deploy_version: config.deployVersion,
            worker_build_id: config.workerBuildId,
        };
        if (config.scopeBootstrapId) {
            handshakePayload.scope_bootstrap_id = config.scopeBootstrapId;
        }
        if (config.backendBootstrapId) {
            handshakePayload.backend_bootstrap_id = config.backendBootstrapId;
        }
        const rawBody = encodePacket(handshakePayload);

        let response;
        try {
            response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'manual',
                cache: 'no-store',
                headers: {
                    'Content-Type': CONTENT_TYPE,
                    'X-Weline-Protocol': PROTOCOL,
                    'X-Weline-Worker-Protocol': WORKER_PROTOCOL,
                    'X-Weline-Deploy-Version': config.deployVersion,
                    'X-Weline-Worker-Build-Id': config.workerBuildId,
                },
                body: rawBody,
            });
        } catch (error) {
            // Transient network: short soft backoff only (not capacity semantics).
            noteHandshakeSoftBackoff();
            throw error;
        }

        assertBinaryFetchResponse(response);
        const body = decodeResponsePacket(response, new Uint8Array(await response.arrayBuffer()));
        if (!response.ok || !body || body.ok !== true || !body.data) {
            const message = body && body.error ? body.error.message : 'Weline worker handshake failed.';
            const code = body && body.error && body.error.code
                ? String(body.error.code)
                : 'auth_error';
            if (
                response.status === 503
                || code === 'worker_capacity_exhausted'
                || /capacity|上限|exhausted/i.test(String(message || ''))
            ) {
                noteHandshakePressure();
            } else if (response.status === 401 || code === 'auth_error') {
                noteHandshakeSoftBackoff();
            }
            throw Object.assign(new Error(message), { code, status: response.status });
        }

        const attestedArea = String(body.data.attested_area || 'frontend');
        if ((attestedArea !== 'frontend' && attestedArea !== 'backend')
            || (config.backendBootstrapId && attestedArea !== 'backend')
            || (!config.backendBootstrapId && attestedArea === 'backend')) {
            throw Object.assign(new Error('Weline worker handshake returned an invalid authority area.'), {
                code: 'backend_attestation_invalid',
                status: 401,
            });
        }
        body.data.attested_area = attestedArea;
        handshakeBackoffMs = 0;
        handshakeCooldownUntil = 0;

        return body.data;
    }

    function noteHandshakeSoftBackoff() {
        handshakeBackoffMs = Math.min(4000, Math.max(400, (handshakeBackoffMs || 200) * 2));
        handshakeCooldownUntil = Date.now() + handshakeBackoffMs;
    }

    function noteHandshakePressure() {
        handshakeBackoffMs = Math.min(30000, Math.max(1000, (handshakeBackoffMs || 500) * 2));
        handshakeCooldownUntil = Date.now() + handshakeBackoffMs;
    }

    function isTelemetryCapability(capability) {
        return TELEMETRY_CAPABILITIES[String(capability || '')] === true;
    }

    function enqueueSignedRequest(task, lane) {
        const useTelemetry = lane === 'telemetry';
        if (useTelemetry) {
            const run = signedTelemetryChain.then(task, task);
            signedTelemetryChain = run.catch(() => {});
            return run;
        }
        const run = signedRequestChain.then(task, task);
        signedRequestChain = run.catch(() => {});
        return run;
    }

    async function executeSignedRequest(config, payload, capability) {
        const lane = isTelemetryCapability(capability) ? 'telemetry' : 'interactive';
        let result = await postSigned(config, payload, capability, lane);
        if (!result.responseOk && isNonceReuse(result.status, result.body)) {
            // Nonce replay means this exact signed request was already committed.
            // The session is still valid — only mint a fresh nonce and retry once.
            result = await postSigned(config, payload, capability, lane);
        } else if (!result.responseOk && shouldInvalidateWorkerSession(result.status, result.body)) {
            workerSession = null;
            result = await postSigned(config, payload, capability, lane);
        }
        return result;
    }

    function isNonceReuse(status, body) {
        if (status !== 401) {
            return false;
        }
        const message = body && body.error && body.error.message
            ? String(body.error.message)
            : '';
        return /nonce has already been used/i.test(message);
    }

    function shouldInvalidateWorkerSession(status, body) {
        if (status !== 401) {
            return false;
        }
        if (isNonceReuse(status, body)) {
            return false;
        }
        const code = body && body.error && typeof body.error.code === 'string'
            ? body.error.code.toLowerCase()
            : '';
        return code === 'auth_error' || code === 'backend_attestation_invalid';
    }

    async function postSigned(config, payload, capability, lane) {
        return enqueueSignedRequest(async () => {
            await ensureSession(config);
            // Snapshot for this request: dual lanes may refresh/clear workerSession concurrently.
            const sessionSnapshot = workerSession;
            if (!sessionSnapshot || typeof sessionSnapshot.signing_secret !== 'string' || sessionSnapshot.signing_secret === '') {
                throw Object.assign(new Error('Weline worker session is unavailable.'), {
                    code: 'auth_error',
                    status: 401,
                });
            }
            const rawBody = encodePacket(payload);
            const timestamp = String(Math.floor(Date.now() / 1000));
            const nonce = randomHex(16);
            const bodyHash = await sha256Hex(rawBody);
            const signatureBase = [
                'POST',
                SIGNED_PATH,
                config.deployVersion,
                config.workerBuildId,
                capability,
                nonce,
                timestamp,
                bodyHash,
            ].join('\n');
            const signature = await hmacSha256Hex(sessionSnapshot.signing_secret, signatureBase);

            const response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'manual',
                cache: 'no-store',
                headers: {
                    'Content-Type': CONTENT_TYPE,
                    'X-Weline-Protocol': PROTOCOL,
                    'X-Weline-Worker-Protocol': WORKER_PROTOCOL,
                    'X-Weline-Deploy-Version': config.deployVersion,
                    'X-Weline-Worker-Build-Id': config.workerBuildId,
                    'X-Weline-Worker-Session': sessionSnapshot.worker_session_token,
                    'X-Weline-Worker-Capability': capability,
                    'X-Weline-Worker-Nonce': nonce,
                    'X-Weline-Worker-Timestamp': timestamp,
                    'X-Weline-Worker-Body-Hash': bodyHash,
                    'X-Weline-Worker-Signature': signature,
                },
                body: rawBody,
            });

            assertBinaryFetchResponse(response);
            const responseBytes = new Uint8Array(await response.arrayBuffer());
            const headers = collectHeaders(response.headers);
            let body = null;
            if (responseBytes.length > 0) {
                try {
                    body = decodeResponsePacket(response, responseBytes);
                } catch (error) {
                    // Maintenance/startup gates return JSON/HTML, not WQB1 — keep headers for detection.
                    if (response.status === 503) {
                        body = tryParseJsonBytes(responseBytes);
                        if (!body) {
                            throw error;
                        }
                    } else {
                        // Prefer a structured JSON error over opaque magic failure when
                        // a middleware incorrectly returned application/json with HTTP 200.
                        const jsonBody = tryParseJsonBytes(responseBytes);
                        if (jsonBody && (jsonBody.error || jsonBody.message || jsonBody.ok === false)) {
                            body = {
                                ok: false,
                                data: jsonBody.data ?? null,
                                error: jsonBody.error && typeof jsonBody.error === 'object'
                                    ? jsonBody.error
                                    : {
                                        code: String(jsonBody.code || jsonBody.error || 'protocol_error'),
                                        message: String(
                                            (jsonBody.error && jsonBody.error.message)
                                            || jsonBody.message
                                            || 'Non-binary query-bin response.'
                                        ),
                                    },
                                request_id: String(jsonBody.request_id || ''),
                            };
                        } else {
                            throw error;
                        }
                    }
                }
            }
            if (shouldInvalidateWorkerSession(response.status, body)) {
                // Only clear if we still own the live session (another lane may have refreshed).
                if (workerSession === sessionSnapshot) {
                    workerSession = null;
                }
            }
            return {
                responseOk: response.ok,
                status: response.status,
                statusText: response.statusText || '',
                headers,
                body,
            };
        }, lane);
    }

    function headerValue(headers, name) {
        if (!headers || typeof headers !== 'object') {
            return '';
        }
        const needle = String(name || '').toLowerCase();
        for (const key of Object.keys(headers)) {
            if (String(key).toLowerCase() === needle) {
                return String(headers[key] || '');
            }
        }
        return '';
    }

    function tryParseJsonBytes(responseBytes) {
        try {
            const text = decoder.decode(responseBytes);
            const parsed = JSON.parse(text);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * True only for explicit site-maintenance signals.
     * Bare HTTP 503 / startup (wls_starting) must NOT open the wait-gift modal.
     */
    function detectMaintenance(status, body, headers) {
        const mw = headerValue(headers, 'x-weline-maintenance').toLowerCase();
        if (mw === '1' || mw === 'true') {
            return true;
        }
        const code = String(
            (body && body.code)
            || (body && body.error && typeof body.error === 'object' ? body.error.code : '')
            || (body && typeof body.error === 'string' ? body.error : '')
            || ''
        ).toLowerCase();
        if (code === 'maintenance') {
            return true;
        }
        const variant = body && body.data && typeof body.data === 'object'
            ? String(body.data.variant || '').toLowerCase()
            : '';
        return variant === 'maintenance';
    }

    function describeResponseBytes(responseBytes) {
        const bytes = responseBytes instanceof Uint8Array ? responseBytes : new Uint8Array();
        const head = bytes.subarray(0, 48);
        const hex = Array.from(head).map((b) => b.toString(16).padStart(2, '0')).join(' ');
        const ascii = Array.from(head)
            .map((b) => (b >= 0x20 && b < 0x7f ? String.fromCharCode(b) : '.'))
            .join('');
        let kind = 'unknown';
        if (bytes.length === 0) {
            kind = 'empty';
        } else if (bytes.length >= 4
            && bytes[0] === 0x57 && bytes[1] === 0x51 && bytes[2] === 0x42 && bytes[3] === 0x31) {
            kind = 'wqb1';
        } else if (bytes[0] === 0x1f && bytes[1] === 0x8b) {
            kind = 'gzip';
        } else if (bytes[0] === 0x7b || bytes[0] === 0x5b) {
            kind = 'json';
        } else if (bytes[0] === 0x3c) {
            kind = 'html';
        }
        return { kind, hex, ascii, length: bytes.length };
    }

    function decodeResponsePacket(response, responseBytes) {
        try {
            return decodePacket(responseBytes);
        } catch (error) {
            const status = response && response.status ? response.status : 0;
            const contentType = response && response.headers && typeof response.headers.get === 'function'
                ? (response.headers.get('content-type') || '')
                : '';
            const desc = describeResponseBytes(responseBytes);
            const hint = desc.kind === 'html'
                ? 'got HTML instead of WQB1 (login redirect or error page?)'
                : (desc.kind === 'json'
                    ? 'got JSON instead of WQB1'
                    : (desc.kind === 'gzip'
                        ? 'got gzip bytes without Content-Encoding decode'
                        : (desc.kind === 'empty' ? 'empty body' : '')));
            const message = [
                error instanceof Error ? error.message : String(error),
                `(HTTP ${status}${contentType ? ', ' + contentType : ''})`,
                `response_bytes=${desc.length}`,
                hint,
                desc.hex ? `head_hex=${desc.hex}` : '',
                desc.ascii ? `head_ascii=${desc.ascii}` : '',
            ].filter(Boolean).join(' ');
            throw Object.assign(new Error(message), {
                code: 'protocol_error',
                status,
            });
        }
    }

    function assertBinaryFetchResponse(response) {
        // Never follow redirects into HTML login/error pages — that yields
        // HTTP 200 + "<!DOCTYPE..." and surfaces as "Invalid Weline binary magic".
        const status = response && response.status ? response.status : 0;
        if (status === 301 || status === 302 || status === 303 || status === 307 || status === 308) {
            const location = response.headers && typeof response.headers.get === 'function'
                ? (response.headers.get('location') || '')
                : '';
            throw Object.assign(new Error(
                `Query-bin redirected instead of returning WQB1 (HTTP ${status}${location ? ', ' + location : ''}).`
            ), {
                code: 'auth_error',
                status,
            });
        }
        if (response && response.type === 'opaqueredirect') {
            throw Object.assign(new Error('Query-bin opaque redirect; expected WQB1 binary response.'), {
                code: 'auth_error',
                status: 0,
            });
        }
        return response;
    }

    function collectHeaders(responseHeaders) {
        const headers = {};
        responseHeaders.forEach((value, key) => {
            headers[key] = value;
        });
        return headers;
    }

    function randomHex(length) {
        const bytes = new Uint8Array(length);
        if (typeof crypto !== 'undefined' && crypto && typeof crypto.getRandomValues === 'function') {
            crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i += 1) {
                bytes[i] = Math.floor(Math.random() * 256) & 0xff;
            }
        }
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function hasSubtleCrypto() {
        return typeof crypto !== 'undefined'
            && crypto
            && crypto.subtle
            && typeof crypto.subtle.digest === 'function'
            && typeof crypto.subtle.importKey === 'function'
            && typeof crypto.subtle.sign === 'function';
    }

    async function sha256Hex(bytes) {
        if (!hasSubtleCrypto()) {
            return bytesToHex(sha256Bytes(bytes));
        }
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        return bytesToHex(new Uint8Array(digest));
    }

    async function hmacSha256Hex(secret, message) {
        if (!hasSubtleCrypto()) {
            return bytesToHex(hmacSha256Bytes(encoder.encode(secret), encoder.encode(message)));
        }
        const key = await crypto.subtle.importKey(
            'raw',
            encoder.encode(secret),
            { name: 'HMAC', hash: 'SHA-256' },
            false,
            ['sign']
        );
        const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(message));
        return bytesToHex(new Uint8Array(signature));
    }

    function bytesToHex(bytes) {
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    const SHA256_K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5,
        0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
        0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc,
        0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
        0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
        0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3,
        0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5,
        0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
        0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];

    function rotr32(value, bits) {
        return (value >>> bits) | (value << (32 - bits));
    }

    function add32() {
        let result = 0;
        for (let i = 0; i < arguments.length; i += 1) {
            result = (result + arguments[i]) >>> 0;
        }
        return result;
    }

    function sha256Bytes(inputBytes) {
        const source = inputBytes instanceof Uint8Array ? inputBytes : new Uint8Array(inputBytes);
        const paddedLength = (((source.length + 9 + 63) >> 6) << 6);
        const bytes = new Uint8Array(paddedLength);
        bytes.set(source);
        bytes[source.length] = 0x80;

        const bitLength = source.length * 8;
        const bitLengthHigh = Math.floor(bitLength / 0x100000000);
        const bitLengthLow = bitLength >>> 0;
        bytes[paddedLength - 8] = (bitLengthHigh >>> 24) & 0xff;
        bytes[paddedLength - 7] = (bitLengthHigh >>> 16) & 0xff;
        bytes[paddedLength - 6] = (bitLengthHigh >>> 8) & 0xff;
        bytes[paddedLength - 5] = bitLengthHigh & 0xff;
        bytes[paddedLength - 4] = (bitLengthLow >>> 24) & 0xff;
        bytes[paddedLength - 3] = (bitLengthLow >>> 16) & 0xff;
        bytes[paddedLength - 2] = (bitLengthLow >>> 8) & 0xff;
        bytes[paddedLength - 1] = bitLengthLow & 0xff;

        const hash = [
            0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
            0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
        ];
        const words = new Uint32Array(64);

        for (let offset = 0; offset < bytes.length; offset += 64) {
            for (let i = 0; i < 16; i += 1) {
                const j = offset + (i * 4);
                words[i] = (
                    (bytes[j] << 24)
                    | (bytes[j + 1] << 16)
                    | (bytes[j + 2] << 8)
                    | bytes[j + 3]
                ) >>> 0;
            }
            for (let i = 16; i < 64; i += 1) {
                const s0 = rotr32(words[i - 15], 7) ^ rotr32(words[i - 15], 18) ^ (words[i - 15] >>> 3);
                const s1 = rotr32(words[i - 2], 17) ^ rotr32(words[i - 2], 19) ^ (words[i - 2] >>> 10);
                words[i] = add32(words[i - 16], s0, words[i - 7], s1);
            }

            let a = hash[0];
            let b = hash[1];
            let c = hash[2];
            let d = hash[3];
            let e = hash[4];
            let f = hash[5];
            let g = hash[6];
            let h = hash[7];

            for (let i = 0; i < 64; i += 1) {
                const s1 = rotr32(e, 6) ^ rotr32(e, 11) ^ rotr32(e, 25);
                const ch = (e & f) ^ ((~e) & g);
                const temp1 = add32(h, s1, ch, SHA256_K[i], words[i]);
                const s0 = rotr32(a, 2) ^ rotr32(a, 13) ^ rotr32(a, 22);
                const maj = (a & b) ^ (a & c) ^ (b & c);
                const temp2 = add32(s0, maj);

                h = g;
                g = f;
                f = e;
                e = add32(d, temp1);
                d = c;
                c = b;
                b = a;
                a = add32(temp1, temp2);
            }

            hash[0] = add32(hash[0], a);
            hash[1] = add32(hash[1], b);
            hash[2] = add32(hash[2], c);
            hash[3] = add32(hash[3], d);
            hash[4] = add32(hash[4], e);
            hash[5] = add32(hash[5], f);
            hash[6] = add32(hash[6], g);
            hash[7] = add32(hash[7], h);
        }

        const digest = new Uint8Array(32);
        for (let i = 0; i < hash.length; i += 1) {
            digest[i * 4] = (hash[i] >>> 24) & 0xff;
            digest[i * 4 + 1] = (hash[i] >>> 16) & 0xff;
            digest[i * 4 + 2] = (hash[i] >>> 8) & 0xff;
            digest[i * 4 + 3] = hash[i] & 0xff;
        }
        return digest;
    }

    function concatBytes(first, second) {
        const out = new Uint8Array(first.length + second.length);
        out.set(first, 0);
        out.set(second, first.length);
        return out;
    }

    function hmacSha256Bytes(secretBytes, messageBytes) {
        let key = secretBytes instanceof Uint8Array ? secretBytes : new Uint8Array(secretBytes);
        if (key.length > 64) {
            key = sha256Bytes(key);
        }
        const inner = new Uint8Array(64);
        const outer = new Uint8Array(64);
        inner.fill(0x36);
        outer.fill(0x5c);
        for (let i = 0; i < key.length; i += 1) {
            inner[i] ^= key[i];
            outer[i] ^= key[i];
        }
        return sha256Bytes(concatBytes(outer, sha256Bytes(concatBytes(inner, messageBytes))));
    }

    class Writer {
        constructor() {
            this.bytes = [];
        }

        byte(value) {
            this.bytes.push(value & 0xff);
        }

        bytesValue(bytes) {
            for (const byte of bytes) {
                this.byte(byte);
            }
        }

        varuint(value) {
            if (!Number.isSafeInteger(value) || value < 0) {
                throw new Error('Invalid Weline varuint.');
            }
            do {
                let byte = value & 0x7f;
                value = Math.floor(value / 128);
                if (value > 0) byte |= 0x80;
                this.byte(byte);
            } while (value > 0);
        }

        finish() {
            return new Uint8Array(this.bytes);
        }
    }

    class Reader {
        constructor(bytes) {
            this.bytes = bytes;
            this.offset = 0;
        }

        byte() {
            if (this.offset >= this.bytes.length) {
                throw new Error('Unexpected end of Weline binary packet.');
            }
            return this.bytes[this.offset++];
        }

        bytesValue(length) {
            if (this.offset + length > this.bytes.length) {
                throw new Error('Unexpected end of Weline binary packet.');
            }
            const value = this.bytes.slice(this.offset, this.offset + length);
            this.offset += length;
            return value;
        }

        varuint() {
            let result = 0;
            let shift = 0;
            while (true) {
                if (shift > 56) {
                    throw new Error('Weline varuint is too large.');
                }
                const byte = this.byte();
                result += (byte & 0x7f) * Math.pow(2, shift);
                if ((byte & 0x80) === 0) {
                    return result;
                }
                shift += 7;
            }
        }
    }

    function encodePacket(value) {
        const writer = new Writer();
        MAGIC.forEach((byte) => writer.byte(byte));
        writer.byte(VERSION);
        encodeValue(writer, value, 0);
        return writer.finish();
    }

    function encodeValue(writer, value, depth) {
        if (depth > MAX_DEPTH) throw new Error('Weline binary value exceeds max depth.');
        if (value === null || typeof value === 'undefined') {
            writer.byte(0x00);
            return;
        }
        if (value === false) {
            writer.byte(0x01);
            return;
        }
        if (value === true) {
            writer.byte(0x02);
            return;
        }
        if (typeof value === 'number') {
            if (!Number.isFinite(value)) throw new Error('Non-finite number is not allowed.');
            if (Number.isInteger(value)) {
                if (!Number.isSafeInteger(value)) throw new Error('Integer exceeds safe range.');
                writer.byte(0x03);
                writer.byte(value < 0 ? 1 : 0);
                writer.varuint(Math.abs(value));
            } else {
                writer.byte(0x04);
                const buffer = new ArrayBuffer(8);
                new DataView(buffer).setFloat64(0, value, false);
                writer.bytesValue(new Uint8Array(buffer));
            }
            return;
        }
        if (typeof value === 'string') {
            const bytes = encoder.encode(value);
            if (bytes.length > MAX_STRING_BYTES) throw new Error('String exceeds 2MB limit.');
            writer.byte(0x05);
            writer.varuint(bytes.length);
            writer.bytesValue(bytes);
            return;
        }
        if (value instanceof Uint8Array || value instanceof ArrayBuffer) {
            const bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
            if (bytes.length > MAX_STRING_BYTES) throw new Error('Bytes exceed 2MB limit.');
            writer.byte(0x06);
            writer.varuint(bytes.length);
            writer.bytesValue(bytes);
            return;
        }
        if (Array.isArray(value)) {
            if (value.length > MAX_LIST_ITEMS) throw new Error('List exceeds 2000 item limit.');
            writer.byte(0x07);
            writer.varuint(value.length);
            value.forEach((item) => encodeValue(writer, item, depth + 1));
            return;
        }
        if (typeof value === 'object') {
            const keys = Object.keys(value);
            if (keys.length > MAX_MAP_KEYS) throw new Error('Map exceeds 2000 key limit.');
            writer.byte(0x08);
            writer.varuint(keys.length);
            keys.forEach((key) => {
                const keyBytes = encoder.encode(key);
                if (keyBytes.length === 0 || keyBytes.length > MAX_STRING_BYTES) {
                    throw new Error('Invalid Weline map key.');
                }
                writer.varuint(keyBytes.length);
                writer.bytesValue(keyBytes);
                encodeValue(writer, value[key], depth + 1);
            });
            return;
        }
        throw new Error('Unsupported Weline binary value type.');
    }

    function decodePacket(bytes) {
        const reader = new Reader(bytes);
        for (const byte of MAGIC) {
            if (reader.byte() !== byte) {
                throw new Error('Invalid Weline binary magic.');
            }
        }
        if (reader.byte() !== VERSION) {
            throw new Error('Unsupported Weline binary version.');
        }
        const value = decodeValue(reader, 0);
        if (reader.offset !== reader.bytes.length) {
            throw new Error('Trailing bytes in Weline binary packet.');
        }
        return value;
    }

    function decodeValue(reader, depth) {
        if (depth > MAX_DEPTH) throw new Error('Weline binary value exceeds max depth.');
        const type = reader.byte();
        if (type === 0x00) return null;
        if (type === 0x01) return false;
        if (type === 0x02) return true;
        if (type === 0x03) {
            const sign = reader.byte();
            const magnitude = reader.varuint();
            if (magnitude > MAX_SAFE_INTEGER) throw new Error('Integer exceeds safe range.');
            return sign === 1 ? -magnitude : magnitude;
        }
        if (type === 0x04) {
            const bytes = reader.bytesValue(8);
            const value = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getFloat64(0, false);
            if (!Number.isFinite(value)) throw new Error('Non-finite float is not allowed.');
            return value;
        }
        if (type === 0x05) {
            const length = reader.varuint();
            if (length > MAX_STRING_BYTES) throw new Error('String exceeds 2MB limit.');
            return decoder.decode(reader.bytesValue(length));
        }
        if (type === 0x06) {
            const length = reader.varuint();
            if (length > MAX_STRING_BYTES) throw new Error('Bytes exceed 2MB limit.');
            return reader.bytesValue(length);
        }
        if (type === 0x07) {
            const count = reader.varuint();
            if (count > MAX_LIST_ITEMS) throw new Error('List exceeds 2000 item limit.');
            const list = [];
            for (let i = 0; i < count; i += 1) {
                list.push(decodeValue(reader, depth + 1));
            }
            return list;
        }
        if (type === 0x08) {
            const count = reader.varuint();
            if (count > MAX_MAP_KEYS) throw new Error('Map exceeds 2000 key limit.');
            const map = {};
            for (let i = 0; i < count; i += 1) {
                const length = reader.varuint();
                if (length === 0 || length > MAX_STRING_BYTES) throw new Error('Invalid Weline map key.');
                const key = decoder.decode(reader.bytesValue(length));
                if (Object.prototype.hasOwnProperty.call(map, key)) {
                    throw new Error('Duplicate Weline map key.');
                }
                map[key] = decodeValue(reader, depth + 1);
            }
            return map;
        }
        throw new Error('Unknown Weline binary type tag.');
    }
})();
