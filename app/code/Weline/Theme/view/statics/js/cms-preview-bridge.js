(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.WelineCmsPreviewBridge = api;
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    const MESSAGE_TYPE = 'weline:cms-theme-context';
    const VERSION = 1;
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
            .slice(0, 128);
    }

    function normalizeContext(context) {
        const value = context && typeof context === 'object' ? context : {};
        return {
            locale: normalizeLocale(value.locale),
            layoutOption: normalizeLayoutOption(value.layoutOption || value.layout_option),
        };
    }

    function isProtocolMessage(data, action) {
        return Boolean(data)
            && typeof data === 'object'
            && data.type === MESSAGE_TYPE
            && data.version === VERSION
            && data.action === action;
    }

    function createParentBridge(options) {
        const hostWindow = options.hostWindow;
        const targetWindow = options.targetWindow;
        const origin = options.origin || hostWindow.location.origin;
        const timeoutMs = Math.max(1, Number(options.timeoutMs) || 2000);
        const onFallback = typeof options.onFallback === 'function' ? options.onFallback : function () {};
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

        function fallback(reason) {
            if (!pending) {
                return;
            }
            const context = pending.context;
            onFallback(context, reason);
            finishPending({ ok: false, fallback: true, reason, context });
        }

        function scheduleTimeout(reason) {
            clearPendingTimer();
            const schedule = hostWindow.setTimeout ? hostWindow.setTimeout.bind(hostWindow) : setTimeout;
            pending.timer = schedule(function () {
                fallback(reason);
            }, timeoutMs);
        }

        function sendPending() {
            if (!pending || !ready) {
                return;
            }
            targetWindow.postMessage({
                type: MESSAGE_TYPE,
                version: VERSION,
                action: 'set-context',
                requestId: pending.requestId,
                context: pending.context,
            }, origin);
            scheduleTimeout('ack-timeout');
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
                        scheduleTimeout('not-ready');
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

        function post(payload) {
            parentWindow.postMessage(Object.assign({ type: MESSAGE_TYPE, version: VERSION }, payload), origin);
        }

        async function onMessage(event) {
            if (!event || event.origin !== origin || event.source !== parentWindow || !isProtocolMessage(event.data, 'set-context')) {
                return;
            }
            const requestId = String(event.data.requestId || '');
            if (!requestId) {
                return;
            }
            const context = normalizeContext(event.data.context);
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
                    reason: error && error.message ? String(error.message) : 'apply-failed',
                    context,
                });
            }
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
        VERSION,
        normalizeLocale,
        normalizeLayoutOption,
        normalizeContext,
        createParentBridge,
        createChildBridge,
    };
});
