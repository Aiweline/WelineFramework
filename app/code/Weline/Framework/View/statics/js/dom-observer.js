/** Shared DOM mutation subscriptions for frontend and backend widgets. */
(function (window, document) {
    'use strict';
    const Weline = window.Weline = window.Weline || {};
    // 启动桩会先装一个占位 observe（把早期调用排队）；只有**真实**实现才允许提前返回，
    // 否则桩在场时本文件会误判「已安装」而跳过，真实观察总线就永远不生效。
    if (typeof Weline.dom?.observe === 'function' && !Weline.dom.observe.__welineBootStub) return;

    const NativeMutationObserver = window.MutationObserver;
    if (typeof NativeMutationObserver !== 'function') {
        return;
    }
    /** DEV 护栏可替换此引用以包一层风暴计数，而不拆掉 Gate 构造器。 */
    if (!window.__WelineNativeMutationObserver) {
        window.__WelineNativeMutationObserver = NativeMutationObserver;
    }

    function resolveNativeMutationObserver() {
        return window.__WelineNativeMutationObserver || NativeMutationObserver;
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

    function tagPhysicalObserver(observer, label) {
        if (!observer) {
            return observer;
        }
        try {
            observer.__welineBusOwned = true;
            observer.__welineMoLabel = String(label || 'Weline.dom');
        } catch (_e) { /* ignore */ }
        return observer;
    }

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
                    // Still disconnected here: may deliver leftover, but never observeThenDeliver
                    // (observeNow + sync deliverRecords caused DEV sync_reentry).
                    const leftover = takePendingRecords();
                    if (leftover.length) {
                        deliverRecords(leftover);
                        scheduleFlushTrailing();
                    } else {
                        observeNow();
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
        const NativeMO = resolveNativeMutationObserver();
        if (typeof NativeMO === 'function') {
            observer = tagPhysicalObserver(new NativeMO(function (records) {
                if (disposed || paused > 0) {
                    return;
                }
                disconnectQuiet();
                deliverRecords(records);
                scheduleFlushTrailing();
            }), loopLabel);
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
     * 架构级 DOM Mutation 观察总线（同 target+options+idleMs 仅一个物理 MutationObserver）。
     * 业务/UI 必须经 Weline.dom.observe 订阅；Document Observe Gate 强制裸 document 观察入线。
     * 扇出 onRecords/onFlush；订阅级 pause 不拆掉共享观察者（写 DOM 时跳过本订阅回调即可）。
     */
    const domMutationChannels = new Map();

    function listChannelSubscriberNames(channelKey) {
        const channel = domMutationChannels.get(channelKey);
        if (!channel) {
            return [];
        }
        const names = [];
        channel.subscribers.forEach(function (sub) {
            names.push(sub.name);
        });
        return names;
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
     *   label?: string,
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
            if (handle && handle.observer) {
                try {
                    handle.observer.__welineChannelKey = channelKey;
                } catch (_t) { /* ignore */ }
            }
            channel = {
                handle: handle,
                subscribers: subscribers,
                nextId: 1,
                channelKey: channelKey,
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

    /**
     * Document Observe Gate：包构造函数捕获 callback；observe(document|html|body) 强制入共享总线。
     */
    function installDocumentObserveGate() {
        if (window.__WelineDomObserveGateInstalled) {
            return;
        }
        if (typeof NativeMutationObserver !== 'function') {
            return;
        }
        window.__WelineDomObserveGateInstalled = true;

        const adopted = typeof WeakMap === 'function' ? new WeakMap() : null;

        function shortLabelFromStack() {
            try {
                const err = new Error('[Weline] gate adopt');
                const lines = String(err.stack || '').split('\n');
                for (let i = 0; i < lines.length; i += 1) {
                    const line = String(lines[i] || '').trim();
                    if (!line || line.indexOf('dom-observer') !== -1 || line.indexOf('gate adopt') !== -1) {
                        continue;
                    }
                    return line.slice(0, 160);
                }
            } catch (_e) { /* ignore */ }
            return 'gate-adopted';
        }

        function clearAdoption(instance) {
            const state = adopted ? adopted.get(instance) : instance.__welineGateState;
            if (!state) {
                return;
            }
            if (state.sub && typeof state.sub.disconnect === 'function') {
                try {
                    state.sub.disconnect();
                } catch (_d) { /* ignore */ }
            }
            state.sub = null;
            instance.__welineDomGateAdopted = false;
        }

        function adoptIntoBus(instance, target, options) {
            clearAdoption(instance);
            const callback = instance.__welineMoCallback;
            const label = String(instance.__welineMoLabel || shortLabelFromStack());
            const sub = observeDomMutations({
                target: target,
                options: options || { childList: true, subtree: true },
                idleTimeoutMs: 100,
                label: label,
                onRecords: function (records) {
                    if (typeof callback !== 'function') {
                        return;
                    }
                    try {
                        callback.call(instance, records, instance);
                    } catch (_e) { /* ignore */ }
                },
                onFlush: function () { /* records already delivered via onRecords */ },
            });
            const state = { sub: sub };
            if (adopted) {
                adopted.set(instance, state);
            } else {
                instance.__welineGateState = state;
            }
            instance.__welineDomGateAdopted = true;
            instance.__welineMoLabel = label;
            try {
                if (window.DEV || (window.Weline && window.Weline.runtimeConfig && window.Weline.runtimeConfig.debug)) {
                    console.info('[Weline] Document Observe Gate adopted raw MutationObserver → bus', {
                        label: label,
                        channelKey: sub && sub.__channelKey,
                    });
                }
            } catch (_i) { /* ignore */ }
            return sub;
        }

        function ensureNative(instance) {
            if (instance.__native) {
                return instance.__native;
            }
            const NativeMO = resolveNativeMutationObserver();
            const cb = typeof instance.__welineMoCallback === 'function'
                ? instance.__welineMoCallback.bind(instance)
                : function () { /* noop */ };
            const native = new NativeMO(cb);
            if (instance.__welineBusOwned) {
                tagPhysicalObserver(native, instance.__welineMoLabel || 'Weline.dom');
            }
            instance.__native = native;
            return native;
        }

        function GateMutationObserver(callback) {
            if (!(this instanceof GateMutationObserver)) {
                return new GateMutationObserver(callback);
            }
            this.__welineMoCallback = typeof callback === 'function' ? callback : null;
            this.__welineBusOwned = false;
            this.__welineDomGateAdopted = false;
            this.__welineMoAllowRawDocument = false;
            this.__welineMoLabel = '';
            this.__native = null;
        }

        GateMutationObserver.prototype.observe = function (target, options) {
            if (this.__welineMoAllowRawDocument || this.__welineBusOwned) {
                clearAdoption(this);
                const native = ensureNative(this);
                return native.observe(target, options || {});
            }
            if (documentWideTargetToken(target)) {
                adoptIntoBus(this, target, options || {});
                return;
            }
            clearAdoption(this);
            const native = ensureNative(this);
            return native.observe(target, options || {});
        };

        GateMutationObserver.prototype.disconnect = function () {
            clearAdoption(this);
            if (this.__native) {
                try {
                    this.__native.disconnect();
                } catch (_e) { /* ignore */ }
            }
        };

        GateMutationObserver.prototype.takeRecords = function () {
            if (this.__native && typeof this.__native.takeRecords === 'function') {
                try {
                    return this.__native.takeRecords() || [];
                } catch (_e) {
                    return [];
                }
            }
            return [];
        };

        try {
            Object.defineProperty(GateMutationObserver, 'name', { value: 'MutationObserver' });
        } catch (_n) { /* ignore */ }

        GateMutationObserver.__welineDocumentObserveGate = true;
        GateMutationObserver.__welineNative = NativeMutationObserver;
        window.MutationObserver = GateMutationObserver;
        window.__WelineListDomMutationSubscribers = listChannelSubscriberNames;
    }

    installDocumentObserveGate();

    Weline.observeMutationsCoalesced = observeMutationsCoalesced;
    Weline.dom = Object.assign({}, Weline.dom || {}, {
        observe: observeDomMutations,
        observeMutations: observeDomMutations,
    });
    Weline.dom.__welineDocumentObserveGate = true;
})(window, document);
