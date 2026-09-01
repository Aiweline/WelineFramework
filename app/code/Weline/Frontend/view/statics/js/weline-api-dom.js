/**
 * Weline Dom micro-core (on-demand)
 *
 * Load only when pages declare dynamic DOM needs:
 *   data-weline-when / data-weline-on  → auto via weline.js detector
 *   data-weline-load="dom"            → existing attribute loader
 *
 * API:
 *   Weline.Dom.on(root, type, selector, handler)
 *   Weline.Dom.when(selector, handler, { root, timeout, once })
 *   Weline.Dom.off(handle)
 *   Weline.Dom.emit(target, name, detail)
 *   Weline.Dom.bind(root)  // declarative attributes
 *
 * Declarative:
 *   data-weline-when=".card"           → fires weline:dom:when on host when match appears
 *   data-weline-on="click:.item"       → delegated; fires weline:dom:on on host
 *   data-weline-timeout="8000"         → optional ms for when (default 15000)
 */
(function (window, document) {
    'use strict';

    if (window.WelineDomModule && window.WelineDomModule.__full) {
        return;
    }

    const ATTR_WHEN = 'data-weline-when';
    const ATTR_ON = 'data-weline-on';
    const ATTR_TIMEOUT = 'data-weline-timeout';
    const ATTR_BOUND = 'data-weline-dom-bound';
    const DEFAULT_TIMEOUT_MS = 15000;
    const DISCOVER_DEBOUNCE_MS = 48;

    /** @type {WeakMap<Element, Set<object>>} */
    const hostHandles = new WeakMap();

    function normalizeRoot(root) {
        if (!root || root === window) {
            return document;
        }
        return root;
    }

    function toElementList(nodeList) {
        return Array.prototype.slice.call(nodeList || []);
    }

    function parseOnSpec(raw) {
        const text = String(raw || '').trim();
        if (!text) {
            return null;
        }
        const colon = text.indexOf(':');
        if (colon <= 0) {
            return { type: text, selector: null };
        }
        return {
            type: text.slice(0, colon).trim(),
            selector: text.slice(colon + 1).trim() || null,
        };
    }

    function readTimeoutMs(el, fallback) {
        const raw = el && el.getAttribute ? el.getAttribute(ATTR_TIMEOUT) : null;
        if (raw == null || raw === '') {
            return fallback;
        }
        const n = Number(raw);
        return Number.isFinite(n) && n >= 0 ? n : fallback;
    }

    /**
     * Event delegation / direct listen.
     * @returns {{ abort: Function }}
     */
    function on(root, type, selectorOrHandler, handlerMaybe, options) {
        const rootNode = normalizeRoot(root);
        let selector = selectorOrHandler;
        let handler = handlerMaybe;
        let opts = options || {};
        if (typeof selectorOrHandler === 'function') {
            handler = selectorOrHandler;
            selector = null;
            opts = handlerMaybe || {};
        }
        if (typeof handler !== 'function' || !type) {
            throw new Error('[Weline.Dom] on(root, type, selector?, handler) requires type and handler');
        }

        const listener = function (event) {
            if (!selector) {
                handler.call(event.currentTarget, event, event.currentTarget);
                return;
            }
            const matched = event.target && event.target.closest ? event.target.closest(selector) : null;
            if (!matched || (rootNode !== document && rootNode.nodeType === 1 && !rootNode.contains(matched))) {
                return;
            }
            handler.call(matched, event, matched);
        };

        const capture = !!(opts && opts.capture);
        rootNode.addEventListener(type, listener, capture);

        const handle = {
            kind: 'on',
            abort: function () {
                rootNode.removeEventListener(type, listener, capture);
            },
        };
        return handle;
    }

    /**
     * Resolve when selector appears under root (MutationObserver, once by default).
     * @returns {Promise<Element[]> & { abort: Function }}
     */
    function when(selector, handlerOrOptions, maybeOptions) {
        let handler = null;
        let options = maybeOptions || {};
        if (typeof handlerOrOptions === 'function') {
            handler = handlerOrOptions;
        } else if (handlerOrOptions && typeof handlerOrOptions === 'object') {
            options = handlerOrOptions;
        }

        const sel = String(selector || '').trim();
        if (!sel) {
            return Promise.reject(new Error('[Weline.Dom] when() requires a selector'));
        }

        const rootNode = normalizeRoot(options.root);
        const once = options.once !== false;
        const timeoutMs = options.timeout == null ? DEFAULT_TIMEOUT_MS : Number(options.timeout);
        let settled = false;
        let observer = null;
        let timer = 0;
        let abortFn = function () {};

        const find = function () {
            if (rootNode.nodeType === 1 && rootNode.matches && rootNode.matches(sel)) {
                return [rootNode];
            }
            if (typeof rootNode.querySelectorAll === 'function') {
                return toElementList(rootNode.querySelectorAll(sel));
            }
            return [];
        };

        const promise = new Promise(function (resolve, reject) {
            const finishOk = function (nodes) {
                if (settled) {
                    return;
                }
                settled = true;
                abortFn();
                if (handler) {
                    try {
                        handler(nodes);
                    } catch (err) {
                        // Consumer errors must not break Dom core.
                        if (window.console && console.warn) {
                            console.warn('[Weline.Dom] when handler error:', err);
                        }
                    }
                }
                resolve(nodes);
            };

            const finishErr = function (err) {
                if (settled) {
                    return;
                }
                settled = true;
                abortFn();
                reject(err);
            };

            const immediate = find();
            if (immediate.length) {
                finishOk(immediate);
                return;
            }

            if (typeof MutationObserver !== 'function') {
                finishErr(new Error('[Weline.Dom] MutationObserver unavailable'));
                return;
            }

            const observeTarget = rootNode.nodeType === 9 ? rootNode.documentElement || rootNode.body : rootNode;
            if (!observeTarget) {
                finishErr(new Error('[Weline.Dom] when() root is not observable'));
                return;
            }

            observer = new MutationObserver(function () {
                const nodes = find();
                if (nodes.length) {
                    finishOk(nodes);
                    if (!once) {
                        // once=false is not a continuous stream in v1; treat as resolve-on-first.
                    }
                }
            });
            observer.observe(observeTarget, { childList: true, subtree: true });

            if (timeoutMs > 0) {
                timer = window.setTimeout(function () {
                    finishErr(new Error('[Weline.Dom] when() timeout: ' + sel));
                }, timeoutMs);
            }

            abortFn = function () {
                if (observer) {
                    observer.disconnect();
                    observer = null;
                }
                if (timer) {
                    window.clearTimeout(timer);
                    timer = 0;
                }
            };
        });

        promise.abort = function () {
            abortFn();
        };
        return promise;
    }

    function off(handle) {
        if (handle && typeof handle.abort === 'function') {
            handle.abort();
        }
    }

    function emit(target, name, detail) {
        const el = typeof target === 'string'
            ? document.querySelector(target)
            : target;
        if (!el || typeof el.dispatchEvent !== 'function') {
            return false;
        }
        const eventName = String(name || '').indexOf('weline:') === 0
            ? String(name)
            : ('weline:dom:' + String(name || 'event'));
        return el.dispatchEvent(new CustomEvent(eventName, {
            bubbles: true,
            cancelable: true,
            detail: detail == null ? {} : detail,
        }));
    }

    function rememberHandle(host, handle) {
        if (!host || !handle) {
            return;
        }
        let set = hostHandles.get(host);
        if (!set) {
            set = new Set();
            hostHandles.set(host, set);
        }
        set.add(handle);
    }

    function bindWhenHost(host) {
        if (!host || host.getAttribute(ATTR_BOUND) === 'when') {
            return;
        }
        const selector = String(host.getAttribute(ATTR_WHEN) || '').trim();
        if (!selector) {
            return;
        }
        host.setAttribute(ATTR_BOUND, 'when');
        const timeout = readTimeoutMs(host, DEFAULT_TIMEOUT_MS);
        const handle = when(selector, function (nodes) {
            emit(host, 'when', { selector: selector, nodes: nodes, host: host });
        }, { root: document, timeout: timeout, once: true });
        rememberHandle(host, handle);
    }

    function bindOnHost(host) {
        if (!host || host.getAttribute(ATTR_BOUND) === 'on' || host.getAttribute(ATTR_BOUND) === 'when,on') {
            // allow both attrs: use composite marker
        }
        const raw = host.getAttribute(ATTR_ON);
        if (!raw || host.getAttribute('data-weline-dom-on-bound') === '1') {
            return;
        }
        const spec = parseOnSpec(raw);
        if (!spec || !spec.type) {
            return;
        }
        host.setAttribute('data-weline-dom-on-bound', '1');
        const bound = host.getAttribute(ATTR_BOUND);
        host.setAttribute(ATTR_BOUND, bound ? (bound + ',on') : 'on');

        const handle = on(host, spec.type, spec.selector, function (event, matched) {
            emit(host, 'on', {
                type: spec.type,
                selector: spec.selector,
                event: event,
                matched: matched,
                host: host,
            });
        });
        rememberHandle(host, handle);
    }

    function bind(root) {
        const scope = normalizeRoot(root);
        const hostsWhen = [];
        const hostsOn = [];

        if (scope.nodeType === 1) {
            if (scope.hasAttribute && scope.hasAttribute(ATTR_WHEN)) {
                hostsWhen.push(scope);
            }
            if (scope.hasAttribute && scope.hasAttribute(ATTR_ON)) {
                hostsOn.push(scope);
            }
        }

        if (typeof scope.querySelectorAll === 'function') {
            toElementList(scope.querySelectorAll('[' + ATTR_WHEN + ']')).forEach(function (el) {
                hostsWhen.push(el);
            });
            toElementList(scope.querySelectorAll('[' + ATTR_ON + ']')).forEach(function (el) {
                hostsOn.push(el);
            });
        }

        hostsWhen.forEach(bindWhenHost);
        hostsOn.forEach(bindOnHost);
    }

    function startLateDiscovery() {
        if (typeof MutationObserver !== 'function') {
            return;
        }
        let timer = 0;
        const schedule = function (rootHint) {
            if (timer) {
                return;
            }
            timer = window.setTimeout(function () {
                timer = 0;
                bind(rootHint || document);
            }, DISCOVER_DEBOUNCE_MS);
        };

        const observer = new MutationObserver(function (records) {
            for (let i = 0; i < records.length; i++) {
                const record = records[i];
                if (record.type === 'attributes') {
                    const name = record.attributeName;
                    if (name === ATTR_WHEN || name === ATTR_ON) {
                        schedule(record.target && record.target.ownerDocument ? record.target.ownerDocument : document);
                        return;
                    }
                }
                const nodes = record.addedNodes;
                for (let j = 0; j < nodes.length; j++) {
                    const node = nodes[j];
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }
                    if (
                        (node.matches && (node.matches('[' + ATTR_WHEN + ']') || node.matches('[' + ATTR_ON + ']')))
                        || (node.querySelector && (node.querySelector('[' + ATTR_WHEN + ']') || node.querySelector('[' + ATTR_ON + ']')))
                    ) {
                        schedule(document);
                        return;
                    }
                }
            }
        });

        const rootEl = document.documentElement || document.body;
        if (rootEl) {
            observer.observe(rootEl, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: [ATTR_WHEN, ATTR_ON],
            });
        }
    }

    function attachToWeline(api) {
        if (window.Weline) {
            window.Weline.Dom = api;
        }
    }

    const api = {
        __full: true,
        on: on,
        when: when,
        off: off,
        emit: emit,
        bind: bind,
    };

    window.WelineDomModule = api;
    attachToWeline(api);

    try {
        document.dispatchEvent(new CustomEvent('weline:dom:ready', {
            bubbles: true,
            detail: { api: api },
        }));
    } catch (_error) {
    }

    const boot = function () {
        bind(document);
        startLateDiscovery();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})(window, document);
