(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.Weline = root.Weline || {};
        root.Weline.Theme = root.Weline.Theme || {};
        root.Weline.Theme.CmsPreviewBridge = api;
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    const MESSAGE_TYPE = 'weline:cms-theme-context';
    const PROTOCOL = 'weline.cms-theme-editor.v2';
    const VERSION = 2;
    let requestSequence = 0;

    function normalizeLocale(value) {
        const source = String(value || '').trim().replace(/-/g, '_');
        const parts = source ? source.split('_') : [];
        if (parts.length < 1 || parts.length > 3 || !/^[A-Za-z]{2,3}$/.test(parts[0])) {
            return '';
        }
        const normalized = [parts[0].toLowerCase()];
        if (parts[1]) {
            if (/^[A-Za-z]{4}$/.test(parts[1])) {
                normalized.push(parts[1][0].toUpperCase() + parts[1].slice(1).toLowerCase());
            } else if (/^(?:[A-Za-z]{2}|[0-9]{3})$/.test(parts[1])) {
                normalized.push(parts[1].toUpperCase());
            } else {
                return '';
            }
        }
        if (parts[2]) {
            if (!/^(?:[A-Za-z]{2}|[0-9]{3})$/.test(parts[2]) || parts[1].length !== 4) {
                return '';
            }
            normalized.push(parts[2].toUpperCase());
        }
        return normalized.join('_');
    }

    function normalizeLayoutOption(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^[._/-]+|[._/-]+$/g, '')
            .slice(0, 100);
    }

    function normalizeContext(context) {
        const value = context && typeof context === 'object' ? context : {};
        const claim = function (camelCase, snakeCase, fallback = null) {
            if (Object.prototype.hasOwnProperty.call(value, camelCase)
                && value[camelCase] !== null
                && value[camelCase] !== undefined
            ) {
                return value[camelCase];
            }
            if (Object.prototype.hasOwnProperty.call(value, snakeCase)
                && value[snakeCase] !== null
                && value[snakeCase] !== undefined
            ) {
                return value[snakeCase];
            }
            return fallback;
        };
        const boundedId = function (candidate) {
            if (typeof candidate === 'number') {
                return Number.isSafeInteger(candidate) && candidate >= 0 ? candidate : 0;
            }
            const text = String(candidate ?? '').trim();
            if (!/^(?:0|[1-9][0-9]{0,15})$/.test(text)) {
                return 0;
            }
            const number = Number(text);
            return Number.isSafeInteger(number) && number >= 0 ? number : 0;
        };
        const boundedText = function (candidate, maxLength) {
            return String(candidate ?? '').trim().slice(0, maxLength);
        };
        const storeMode = String(claim('storeMode', 'store_mode', 'normal')).trim().toLowerCase();
        return {
            pageId: boundedId(claim('pageId', 'page_id', 0)),
            websiteId: boundedId(claim('websiteId', 'website_id', 0)),
            storeId: boundedId(claim('storeId', 'store_id', 0)),
            websiteCode: boundedText(claim('websiteCode', 'website_code', ''), 255),
            storeCode: boundedText(claim('storeCode', 'store_code', ''), 64),
            scope: boundedText(value.scope, 768),
            storeMode: ['normal', 'dev', 'test'].includes(storeMode) ? storeMode : 'normal',
            locale: normalizeLocale(value.locale),
            layoutOption: normalizeLayoutOption(claim('layoutOption', 'layout_option', '')),
        };
    }

    function isProtocolMessage(data, action) {
        return Boolean(data)
            && typeof data === 'object'
            && data.type === MESSAGE_TYPE
            && data.protocol === PROTOCOL
            && data.version === VERSION
            && data.action === action;
    }

    function createParentBridge(options) {
        const hostWindow = options.hostWindow;
        const targetWindow = options.targetWindow;
        const origin = options.origin || hostWindow.location.origin;
        const timeoutMs = Math.max(1, Number(options.timeoutMs) || 2000);
        let ready = false;
        let started = false;
        let pending = null;

        function clearPendingTimer() {
            if (pending && pending.timer) {
                hostWindow.clearTimeout ? hostWindow.clearTimeout(pending.timer) : clearTimeout(pending.timer);
                pending.timer = null;
            }
        }

        function finishPending(result) {
            if (!pending) {
                return;
            }
            const current = pending;
            clearPendingTimer();
            pending = null;
            current.resolve(result);
        }

        function failPending(reason) {
            if (!pending) {
                return;
            }
            finishPending({
                ok: false,
                fallback: false,
                reason,
                context: pending.context,
            });
        }

        function scheduleTimeout(reason) {
            clearPendingTimer();
            const schedule = hostWindow.setTimeout ? hostWindow.setTimeout.bind(hostWindow) : setTimeout;
            pending.timer = schedule(function () {
                // Without a v2 handshake/ACK the parent cannot prove that the
                // Theme iframe is clean. Fail closed for both readiness and ACK
                // timeouts; a URL reload could otherwise discard editor state.
                failPending(reason);
            }, timeoutMs);
        }

        function sendPending() {
            if (!pending || !ready) {
                return;
            }
            if (!post({
                type: MESSAGE_TYPE,
                protocol: PROTOCOL,
                version: VERSION,
                action: 'set-context',
                requestId: pending.requestId,
                context: pending.context,
            })) {
                failPending('post-failed');
                return;
            }
            scheduleTimeout('ack-timeout');
        }

        function requestReady() {
            return post({
                type: MESSAGE_TYPE,
                protocol: PROTOCOL,
                version: VERSION,
                action: 'probe',
            });
        }

        function post(payload) {
            try {
                targetWindow.postMessage(payload, origin);
                return true;
            } catch (error) {
                return false;
            }
        }

        function onMessage(event) {
            if (!event || event.origin !== origin || event.source !== targetWindow) {
                return;
            }
            if (isProtocolMessage(event.data, 'ready')) {
                ready = true;
                sendPending();
                return;
            }
            if (!pending || !isProtocolMessage(event.data, 'ack') || event.data.requestId !== pending.requestId) {
                return;
            }
            finishPending({
                ok: event.data.ok === true,
                dirty: event.data.dirty === true,
                reason: String(event.data.reason || ''),
                context: normalizeContext(event.data.context || pending.context),
            });
        }

        return {
            start() {
                if (started) {
                    return;
                }
                hostWindow.addEventListener('message', onMessage);
                started = true;
                // The child may have emitted its one-shot ready message before
                // the parent listener was attached (for example from a warm
                // browser cache). Probe explicitly so a dirty editor can never
                // be mistaken for an unavailable bridge and reloaded.
                requestReady();
            },
            isReady() {
                return ready;
            },
            setContext(context) {
                if (pending) {
                    finishPending({ ok: false, reason: 'superseded', context: pending.context });
                }
                return new Promise(function (resolve) {
                    pending = {
                        requestId: `cms-context-${Date.now()}-${++requestSequence}`,
                        context: normalizeContext(context),
                        resolve,
                        timer: null,
                    };
                    if (ready) {
                        sendPending();
                    } else {
                        if (!requestReady()) {
                            failPending('post-failed');
                        } else {
                            scheduleTimeout('not-ready');
                        }
                    }
                });
            },
            destroy() {
                clearPendingTimer();
                if (pending) {
                    finishPending({ ok: false, reason: 'destroyed', context: pending.context });
                }
                if (started) {
                    hostWindow.removeEventListener('message', onMessage);
                }
                started = false;
                ready = false;
            },
        };
    }

    function createChildBridge(options) {
        const hostWindow = options.hostWindow;
        const parentWindow = options.parentWindow;
        const origin = options.origin || hostWindow.location.origin;
        const applyContext = typeof options.applyContext === 'function' ? options.applyContext : async function () {};
        const isDirty = typeof options.isDirty === 'function' ? options.isDirty : function () { return false; };
        let started = false;
        let applyQueue = Promise.resolve();

        function post(payload) {
            try {
                parentWindow.postMessage(
                    Object.assign({ type: MESSAGE_TYPE, protocol: PROTOCOL, version: VERSION }, payload),
                    origin,
                );
                return true;
            } catch (error) {
                return false;
            }
        }

        function onMessage(event) {
            if (!event || event.origin !== origin || event.source !== parentWindow) {
                return;
            }
            if (isProtocolMessage(event.data, 'probe')) {
                post({ action: 'ready' });
                return;
            }
            if (!isProtocolMessage(event.data, 'set-context')) {
                return;
            }
            const requestId = String(event.data.requestId || '');
            if (!requestId) {
                return;
            }
            const context = normalizeContext(event.data.context);
            // Context switches mutate a shared editor state. Serialize them so
            // rapid Store/Locale changes cannot finish out of order.
            applyQueue = applyQueue.catch(function () {}).then(async function () {
                if (isDirty()) {
                    post({ action: 'ack', requestId, ok: false, dirty: true, reason: 'dirty', context });
                    return;
                }
                try {
                    await applyContext(context);
                    post({ action: 'ack', requestId, ok: true, context });
                } catch (error) {
                    post({
                        action: 'ack',
                        requestId,
                        ok: false,
                        dirty: isDirty(),
                        reason: error && error.message ? String(error.message) : 'apply-failed',
                        context,
                    });
                }
            });
        }

        return {
            start() {
                if (started) {
                    return;
                }
                hostWindow.addEventListener('message', onMessage);
                started = true;
                post({ action: 'ready' });
            },
            destroy() {
                if (started) {
                    hostWindow.removeEventListener('message', onMessage);
                }
                started = false;
            },
        };
    }

    return {
        MESSAGE_TYPE,
        PROTOCOL,
        VERSION,
        normalizeLocale,
        normalizeLayoutOption,
        normalizeContext,
        createParentBridge,
        createChildBridge,
    };
});
