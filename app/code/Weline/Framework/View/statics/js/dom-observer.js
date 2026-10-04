/** Shared DOM mutation subscriptions for frontend and backend widgets. */
(function (window, document) {
    'use strict';
    const Weline = window.Weline = window.Weline || {};
    if (typeof Weline.dom?.observe === 'function') return;
    function observeMutationsCoalesced(spec) {
        const options = (spec && spec.options) || { childList: true, subtree: true };
        const idleTimeoutMs = (function () {
            const n = Number(spec && spec.idleTimeoutMs);
            return Number.isFinite(n) && n >= 0 ? n : 100;
        })();
        /** Vue-style max update depth：同调用栈内嵌套 flush 过深即判定反馈环。 */
        const MAX_FLUSH_DEPTH = 50;
        /** 短窗内连续 flush 次数（含安静窗链式），对齐 DEV delivery_storm 量级。 */
        const MAX_FLUSHES_PER_WINDOW = 40;
        const FLUSH_WINDOW_MS = 250;
        const loopLabel = String((spec && spec.label) || 'observeMutationsCoalesced');
        let target = spec && spec.target ? spec.target : null;
        let paused = 0;
        let flushScheduled = false;
        let idleHandle = null;
        let timeoutHandle = null;
        let observing = false;
        let disposed = false;
        let flushDepth = 0;
        let flushWindowStart = 0;
        let flushesInWindow = 0;

        function clearIdleTimers() {
            if (idleHandle != null && typeof window.cancelIdleCallback === 'function') {
                try {
                    window.cancelIdleCallback(idleHandle);
                } catch (_e) { /* ignore */ }
                idleHandle = null;
            }
            if (timeoutHandle != null) {
                window.clearTimeout(timeoutHandle);
                timeoutHandle = null;
            }
        }

        function disconnectQuiet() {
            if (!observer) {
                return;
            }
            try {
                observer.disconnect();
            } catch (_e) { /* ignore */ }
            observing = false;
        }

        function observeNow() {
            if (disposed || !observer || !target || paused > 0 || flushScheduled) {
                return;
            }
            try {
                observer.observe(target, options);
                observing = true;
            } catch (_e) { /* ignore */ }
        }

        function takePendingRecords() {
            if (!observer || typeof observer.takeRecords !== 'function') {
                return [];
            }
            try {
                return observer.takeRecords() || [];
            } catch (_e) {
                return [];
            }
        }

        function deliverRecords(records) {
            if (!records || !records.length || typeof spec.onRecords !== 'function') {
                return;
            }
            try {
                spec.onRecords(records);
            } catch (_e) { /* ignore */ }
        }

        function reportFeedbackLoop(reason, detail) {
            if (disposed) {
                return;
            }
            disposed = true;
            flushScheduled = false;
            clearIdleTimers();
            disconnectQuiet();
            const payload = Object.assign({
                reason: reason,
                label: loopLabel,
                flushDepth: flushDepth,
                flushesInWindow: flushesInWindow,
            }, detail || {});
            try {
                console.error(
                    '[Weline] Mutation flush loop stopped（参考 Vue maxUpdateDepth）。'
                    + ' 回调改 DOM 形成反馈环；请检查 onFlush/onRecords，document 级须用 Weline.dom.observe。',
                    payload
                );
            } catch (_c) { /* ignore */ }
            try {
                window.dispatchEvent(new CustomEvent('weline:dom:mutation-loop', { detail: payload }));
            } catch (_e) { /* ignore */ }
            try {
                window.__WelineDomMutationLoopLast = payload;
            } catch (_w) { /* ignore */ }
            if (typeof spec.onLoop === 'function') {
                try {
                    spec.onLoop(payload);
                } catch (_o) { /* ignore */ }
            }
            observer = null;
        }

        function noteFlushInWindow() {
            const now = Date.now();
            if (!flushWindowStart || (now - flushWindowStart) > FLUSH_WINDOW_MS) {
                flushWindowStart = now;
                flushesInWindow = 0;
            }
            flushesInWindow += 1;
            return flushesInWindow > MAX_FLUSHES_PER_WINDOW;
        }

        function runFlush() {
            if (disposed) {
                return;
            }
            if (flushDepth >= MAX_FLUSH_DEPTH) {
                reportFeedbackLoop('max_flush_depth', { maxFlushDepth: MAX_FLUSH_DEPTH });
                return;
            }
            if (noteFlushInWindow()) {
                reportFeedbackLoop('flush_storm', {
                    maxFlushesPerWindow: MAX_FLUSHES_PER_WINDOW,
                    windowMs: FLUSH_WINDOW_MS,
                });
                return;
            }
            flushDepth += 1;
            flushScheduled = false;
            clearIdleTimers();
            disconnectQuiet();
            try {
                deliverRecords(takePendingRecords());
                if (typeof spec.onFlush === 'function') {
                    spec.onFlush();
                }
                deliverRecords(takePendingRecords());
            } catch (error) {
                try {
                    console.warn('[Weline] observeMutationsCoalesced onFlush failed:', error);
                } catch (_c) { /* ignore */ }
            } finally {
                flushDepth -= 1;
                if (!disposed && paused === 0) {
                    observeNow();
                    const leftover = takePendingRecords();
                    if (leftover.length) {
                        deliverRecords(leftover);
                        scheduleFlushTrailing();
                    }
                }
            }
        }

        function scheduleFlushTrailing() {
            if (disposed) {
                return;
            }
            clearIdleTimers();
            flushScheduled = true;
            disconnectQuiet();
            // Quiet window FIRST (setTimeout). rIC timeout alone is not a quiet window —
            // browsers may run it on the next idle slice within a few ms.
            timeoutHandle = window.setTimeout(function () {
                timeoutHandle = null;
                const run = function () {
                    idleHandle = null;
                    runFlush();
                };
                if (typeof window.requestIdleCallback === 'function') {
                    idleHandle = window.requestIdleCallback(run, { timeout: 50 });
                } else {
                    run();
                }
            }, idleTimeoutMs);
        }

        let observer = null;
        if (typeof MutationObserver === 'function') {
            observer = new MutationObserver(function (records) {
                if (disposed || paused > 0) {
                    return;
                }
                disconnectQuiet();
                deliverRecords(records);
                scheduleFlushTrailing();
            });
        }

        const api = {
            get observer() {
                return observer;
            },
            kick() {
                scheduleFlushTrailing();
            },
            pause() {
                paused += 1;
                disconnectQuiet();
            },
            resume() {
                if (paused > 0) {
                    paused -= 1;
                }
                if (paused === 0 && !flushScheduled && !disposed) {
                    observeNow();
                }
            },
            withPaused(fn) {
                api.pause();
                try {
                    return fn();
                } finally {
                    api.resume();
                }
            },
            disconnect() {
                disposed = true;
                flushScheduled = false;
                clearIdleTimers();
                disconnectQuiet();
                observer = null;
            },
            start() {
                if (disposed) {
                    return;
                }
                observeNow();
            },
            setTarget(next) {
                target = next || null;
                if (observing) {
                    disconnectQuiet();
                    observeNow();
                }
            },
        };

        if (!spec || spec.autoStart !== false) {
            api.start();
        }
        return api;
    }

    /**
     * 架构级 DOM Mutation 观察总线（同 target+options 仅一个物理 MutationObserver）。
     * 业务/UI 必须经 Weline.dom.observe 订阅；禁止对 document/body/html 再 new MutationObserver。
     * 扇出 onRecords/onFlush；订阅级 pause 不拆掉共享观察者（写 DOM 时跳过本订阅回调即可）。
     */
    const domMutationChannels = new Map();

    function fingerprintObserveOptions(options) {
        const o = options && typeof options === 'object' ? options : {};
        const keys = Object.keys(o).sort();
        return keys.map(function (key) {
            const value = o[key];
            if (Array.isArray(value)) {
                return key + '=' + value.slice().map(String).sort().join(',');
            }
            return key + '=' + String(value);
        }).join('|');
    }

    function documentWideTargetToken(target) {
        if (!target) {
            return null;
        }
        if (target === document) {
            return 'document';
        }
        if (target === document.documentElement) {
            return 'html';
        }
        if (target === document.body) {
            return 'body';
        }
        return null;
    }

    /**
     * @param {{
     *   target: Node,
     *   options?: MutationObserverInit,
     *   onRecords?: (records: MutationRecord[]) => void,
     *   onFlush: () => void,
     *   idleTimeoutMs?: number,
     *   share?: boolean,
     *   autoStart?: boolean,
     * }} spec
     */
    function observeDomMutations(spec) {
        const target = spec && spec.target ? spec.target : null;
        const options = (spec && spec.options) || { childList: true, subtree: true };
        const idleTimeoutMs = (function () {
            const n = Number(spec && spec.idleTimeoutMs);
            return Number.isFinite(n) && n >= 0 ? n : 100;
        })();
        const token = documentWideTargetToken(target);
        const wantShare = spec && spec.share === false ? false : true;
        if (!wantShare || !token) {
            return observeMutationsCoalesced(spec);
        }

        const channelKey = token + '::' + fingerprintObserveOptions(options) + '::' + String(idleTimeoutMs);
        let channel = domMutationChannels.get(channelKey);
        if (!channel) {
            const subscribers = new Map();
            const handle = observeMutationsCoalesced({
                target: target,
                options: options,
                idleTimeoutMs: idleTimeoutMs,
                autoStart: spec && spec.autoStart !== false,
                label: 'Weline.dom:' + channelKey,
                onLoop() {
                    domMutationChannels.delete(channelKey);
                },
                onRecords(records) {
                    subscribers.forEach(function (sub) {
                        if (sub.paused > 0 || typeof sub.onRecords !== 'function') {
                            return;
                        }
                        try {
                            sub.onRecords(records);
                        } catch (_e) { /* ignore */ }
                    });
                },
                onFlush() {
                    subscribers.forEach(function (sub) {
                        if (sub.paused > 0 || typeof sub.onFlush !== 'function') {
                            return;
                        }
                        try {
                            sub.onFlush();
                        } catch (_e) { /* ignore */ }
                    });
                },
            });
            channel = {
                handle: handle,
                subscribers: subscribers,
                nextId: 1,
            };
            domMutationChannels.set(channelKey, channel);
        }

        const subId = channel.nextId++;
        const sub = {
            paused: 0,
            name: String((spec && spec.label) || ('sub-' + subId)),
            onRecords: typeof spec.onRecords === 'function' ? spec.onRecords : null,
            onFlush: typeof spec.onFlush === 'function' ? spec.onFlush : null,
        };
        channel.subscribers.set(subId, sub);

        return {
            get observer() {
                return channel.handle.observer;
            },
            kick() {
                channel.handle.kick();
            },
            pause() {
                sub.paused += 1;
            },
            resume() {
                if (sub.paused > 0) {
                    sub.paused -= 1;
                }
            },
            withPaused(fn) {
                sub.paused += 1;
                try {
                    return fn();
                } finally {
                    if (sub.paused > 0) {
                        sub.paused -= 1;
                    }
                }
            },
            disconnect() {
                channel.subscribers.delete(subId);
                if (channel.subscribers.size === 0) {
                    try {
                        channel.handle.disconnect();
                    } catch (_e) { /* ignore */ }
                    domMutationChannels.delete(channelKey);
                }
            },
            start() {
                channel.handle.start();
            },
            __channelKey: channelKey,
            __shared: true,
        };
    }

    Weline.observeMutationsCoalesced = observeMutationsCoalesced;
    Weline.dom = Object.assign({}, Weline.dom || {}, { observe: observeDomMutations, observeMutations: observeDomMutations });
})(window, document);
