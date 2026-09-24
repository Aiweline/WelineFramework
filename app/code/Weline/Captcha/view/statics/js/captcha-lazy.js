/**
 * Weline_Captcha lazy / refresh client runtime.
 * FPC-safe: shared page HTML may keep only lazy hosts; challenges are fetched
 * from weline_captcha/frontend/challenge (private, no-store).
 * When a challenge was hidden and becomes visible again, force a fresh challenge
 * so reopening editors/modals never reuse a stale image.
 *
 * Important: do NOT prefetch challenges for hosts that are still hidden (e.g. closed
 * <details> quick-add). Parallel hidden fetches saturate the browser connection pool
 * and leave /weline_captcha/frontend/challenge stuck in Network "pending" even though
 * the same URL returns 200 when opened alone.
 *
 * MutationObserver: document-wide childList/attributes fire densely during deferred
 * widget load (region.list / header modules). Use Weline.observeMutationsCoalesced
 * (disconnect + trailing idle 100ms); pause via handle.withPaused while writing DOM.
 */
(function (w, d) {
    'use strict';
    w.Weline = w.Weline || {};
    if (w.Weline.Captcha && w.Weline.Captcha.__runtime === 'captcha-lazy') {
        return;
    }

    var DEFAULT_ROUTE = 'weline_captcha/frontend/challenge';
    var STYLESHEET_ID = 'weline-captcha-local-styles';
    var STYLESHEET_MODULE = 'Weline_Captcha::css/captcha-local.css?v=20260910-google-ready1';
    var FETCH_TIMEOUT_MS = 8000;
    var OBSERVE_OPTIONS = {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['hidden', 'open'],
    };
    var booted = false;
    var visibilityState = typeof WeakMap === 'function' ? new WeakMap() : null;
    var inflightByUrl = Object.create(null);
    var ensurePromiseByHost = typeof WeakMap === 'function' ? new WeakMap() : null;
    var mutationHandle = null;
    var mutationObserver = null;

    function pauseObserver() {
        if (mutationHandle && typeof mutationHandle.pause === 'function') {
            mutationHandle.pause();
            return;
        }
        if (mutationObserver) {
            try {
                mutationObserver.disconnect();
            } catch (_error) {
            }
        }
    }

    function resumeObserver() {
        if (mutationHandle && typeof mutationHandle.resume === 'function') {
            mutationHandle.resume();
            return;
        }
        if (mutationObserver) {
            try {
                mutationObserver.observe(d.documentElement, OBSERVE_OPTIONS);
            } catch (_error) {
            }
        }
    }

    function withObserverPaused(fn) {
        if (mutationHandle && typeof mutationHandle.withPaused === 'function') {
            return mutationHandle.withPaused(fn);
        }
        pauseObserver();
        try {
            return fn();
        } finally {
            resumeObserver();
        }
    }

    function runDomScan() {
        ensureAll(d, false).catch(function () {});
        trackNewCaptchas(d);
        refreshWhenShown(d);
    }

    function scheduleDomScan() {
        if (mutationHandle && typeof mutationHandle.kick === 'function') {
            mutationHandle.kick();
            return;
        }
        // Fallback without coalesced handle: trailing idle 100ms (never double-rAF).
        pauseObserver();
        if (scheduleDomScan._timer) {
            w.clearTimeout(scheduleDomScan._timer);
            scheduleDomScan._timer = null;
        }
        var flush = function () {
            scheduleDomScan._timer = null;
            try {
                runDomScan();
            } finally {
                resumeObserver();
            }
        };
        if (typeof w.requestIdleCallback === 'function') {
            // Quiet window first; do not use rIC({timeout:100}) alone.
            scheduleDomScan._timer = w.setTimeout(function () {
                scheduleDomScan._timer = null;
                scheduleDomScan._idle = w.requestIdleCallback(flush, { timeout: 50 });
            }, 100);
        } else {
            scheduleDomScan._timer = w.setTimeout(flush, 100);
        }
    }

    function resolveStylesheetUrl() {
        var scripts = d.querySelectorAll('script[src*="captcha-lazy"]');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].getAttribute('src') || '';
            if (src.indexOf('captcha-lazy') === -1) {
                continue;
            }
            var css = src.replace(/\/js\/captcha-lazy\.js(\?.*)?$/i, '/css/captcha-local.css$1');
            if (css !== src) {
                return css;
            }
        }
        var loader = w.Weline && w.Weline.loader;
        if (loader && typeof loader.resolveStaticPath === 'function') {
            return loader.resolveStaticPath(STYLESHEET_MODULE);
        }
        // Prefer empty over DEV-shaped /Weline/*/view/statics/ (PROD 404).
        return '';
    }

    function ensureStylesheet() {
        if (d.getElementById(STYLESHEET_ID)) {
            return;
        }
        var href = resolveStylesheetUrl();
        if (!href) {
            return;
        }
        withObserverPaused(function () {
            var link = d.createElement('link');
            link.id = STYLESHEET_ID;
            link.rel = 'stylesheet';
            link.href = href;
            d.head.appendChild(link);
        });
    }

    function hoistFragmentStyles(wrap) {
        wrap.querySelectorAll('style, link[rel="stylesheet"]').forEach(function (el) {
            if (el.tagName === 'STYLE') {
                var style = d.createElement('style');
                style.setAttribute('data-weline-captcha-style', 'inline');
                style.textContent = el.textContent || '';
                d.head.appendChild(style);
                return;
            }
            d.head.appendChild(el.cloneNode(true));
        });
    }

    function extractChallengeNode(wrap) {
        var node = wrap.querySelector('.weline-captcha, [data-weline-captcha-provider]');
        if (node instanceof HTMLElement) {
            return node;
        }
        return wrap.firstElementChild;
    }

    /**
     * innerHTML does not run <script>; providers append bind scripts as siblings.
     * Re-insert so Google/Tencent can reveal badge or degrade to local_image.
     */
    function executeFragmentScripts(fromRoot, mountParent) {
        if (!(fromRoot instanceof HTMLElement) || !(mountParent instanceof Node)) {
            return;
        }
        var scripts = Array.prototype.slice.call(fromRoot.querySelectorAll('script'));
        scripts.forEach(function (oldScript) {
            var s = d.createElement('script');
            Array.prototype.forEach.call(oldScript.attributes || [], function (attr) {
                s.setAttribute(attr.name, attr.value);
            });
            if (oldScript.src) {
                s.src = oldScript.src;
            } else {
                s.textContent = oldScript.textContent || '';
            }
            if (oldScript.parentNode) {
                oldScript.parentNode.removeChild(oldScript);
            }
            mountParent.appendChild(s);
        });
    }

    function challengeUrl(route, intent, formId, prefer) {
        var segments = String(w.location.pathname || '').split('/').filter(Boolean);
        var maybeLocale = segments[0] || '';
        var hasLocale = /^[a-z]{2}(_[A-Za-z0-9-]+)?$/i.test(maybeLocale);
        var prefix = hasLocale ? '/' + maybeLocale : '';
        var clean = String(route || DEFAULT_ROUTE).replace(/^\/+/, '');
        var params = new URLSearchParams({
            intent: String(intent || 'generic'),
            form_id: String(formId || ''),
            _: String(Date.now()),
        });
        if (prefer) {
            params.set('prefer', String(prefer));
        }
        return prefix + '/' + clean + '?' + params.toString();
    }

    function isInsideClosedDetails(el) {
        if (!(el instanceof Element) || !el.closest) {
            return false;
        }
        var details = el.closest('details');
        if (!(details instanceof HTMLDetailsElement)) {
            return false;
        }
        if (details.open) {
            return false;
        }
        // Summary stays visible while the rest of a closed details is not.
        if (el.closest('summary') && details.querySelector('summary') && details.querySelector('summary').contains(el)) {
            return false;
        }
        return true;
    }

    function isEffectivelyHidden(el) {
        if (!(el instanceof Element)) {
            return true;
        }
        if (isInsideClosedDetails(el)) {
            return true;
        }
        var node = el;
        while (node) {
            if (node.nodeType === 1) {
                if (node.hasAttribute('hidden') || node.getAttribute('aria-hidden') === 'true') {
                    return true;
                }
                var style = w.getComputedStyle ? w.getComputedStyle(node) : null;
                if (style && (style.display === 'none' || style.visibility === 'hidden')) {
                    return true;
                }
            }
            node = node.parentElement;
        }
        return false;
    }

    function captchaRoots(scope) {
        var root = scope && scope.querySelectorAll ? scope : d;
        var list = [];
        if (root.matches && root.matches('[data-weline-captcha-lazy], .weline-captcha, [data-weline-captcha-provider]')) {
            list.push(root);
        }
        if (root.querySelectorAll) {
            root.querySelectorAll('[data-weline-captcha-lazy], .weline-captcha, [data-weline-captcha-provider]')
                .forEach(function (el) {
                    list.push(el);
                });
        }
        return list;
    }

    function noteVisibility(el, hidden) {
        if (!visibilityState || !(el instanceof Element)) {
            return;
        }
        visibilityState.set(el, !!hidden);
    }

    function trackNewCaptchas(scope) {
        captchaRoots(scope).forEach(function (el) {
            if (!visibilityState || visibilityState.has(el)) {
                return;
            }
            noteVisibility(el, isEffectivelyHidden(el));
        });
    }

    function refreshWhenShown(scope) {
        captchaRoots(scope).forEach(function (el) {
            if (el.closest && el.closest('#cs-bind-modal')) {
                return;
            }
            var hidden = isEffectivelyHidden(el);
            var prev = visibilityState ? visibilityState.get(el) : undefined;
            noteVisibility(el, hidden);
            // 「曾隐藏 → 现可见」时再拉；首次可见也要拉（首屏跳过了隐藏宿主）
            if (hidden === false && prev !== false) {
                refresh(el).catch(function () {});
            }
        });
    }

    function resolveContext(anchor) {
        var node = anchor instanceof Element ? anchor : null;
        var form = node && node.closest ? node.closest('form') : null;
        if (!(form instanceof HTMLFormElement) && node && node.matches && node.matches('form')) {
            form = node;
        }
        var host = null;
        if (node) {
            host = node.matches('[data-weline-captcha-lazy]')
                ? node
                : (node.closest('[data-weline-captcha-lazy]') || null);
        }
        if (!host && form) {
            host = form.querySelector('[data-weline-captcha-lazy]');
        }
        var existing = null;
        if (node) {
            existing = node.closest('[data-weline-captcha-provider], .weline-captcha') || null;
        }
        if (!existing && form) {
            existing = form.querySelector('[data-weline-captcha-provider], .weline-captcha');
        }
        var anchorEl = host || existing || node;
        var intent = (host && host.getAttribute('data-intent'))
            || (anchorEl && anchorEl.getAttribute('data-intent'))
            || (form && (form.getAttribute('data-weline-form-intent') || form.dataset.welineFormIntent))
            || 'generic';
        var formId = (host && host.getAttribute('data-form-id'))
            || (form && form.id)
            || '';
        var route = (host && host.getAttribute('data-challenge-route'))
            || (anchorEl && anchorEl.getAttribute('data-challenge-route'))
            || DEFAULT_ROUTE;
        var prefer = (host && host.getAttribute('data-prefer'))
            || (anchorEl && anchorEl.getAttribute('data-prefer'))
            || '';
        return { form: form, host: host, existing: existing, anchor: anchorEl, intent: intent, formId: formId, route: route, prefer: prefer };
    }

    function fetchWithTimeout(url, options, timeoutMs) {
        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = 0;
        var opts = Object.assign({}, options || {});
        if (ctrl) {
            opts.signal = ctrl.signal;
            timer = w.setTimeout(function () {
                try {
                    ctrl.abort();
                } catch (_error) {
                }
            }, timeoutMs || FETCH_TIMEOUT_MS);
        }
        return fetch(url, opts).finally(function () {
            if (timer) {
                w.clearTimeout(timer);
            }
        });
    }

    async function fetchChallengeHtml(ctx) {
        var url = challengeUrl(ctx.route, ctx.intent, ctx.formId, ctx.prefer);
        // Drop cache-buster for inflight key so parallel hosts share one network call.
        var inflightKey = url.replace(/([?&])_=\d+/, '$1_={ts}');
        if (inflightByUrl[inflightKey]) {
            return inflightByUrl[inflightKey];
        }
        inflightByUrl[inflightKey] = (async function () {
            var response = await fetchWithTimeout(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            }, FETCH_TIMEOUT_MS);
            if (!response.ok) {
                throw new Error('captcha_http_' + response.status);
            }
            var payload = await response.json();
            var html = String((payload && (payload.html || (payload.data && payload.data.html))) || '');
            if (!html) {
                throw new Error('captcha_empty');
            }
            return html;
        })().finally(function () {
            delete inflightByUrl[inflightKey];
        });
        return inflightByUrl[inflightKey];
    }

    function replaceAnchor(anchor, html, form) {
        if (!(anchor instanceof HTMLElement)) {
            throw new Error('captcha_anchor');
        }
        return withObserverPaused(function () {
            var wrap = d.createElement('div');
            wrap.innerHTML = String(html).trim();
            hoistFragmentStyles(wrap);
            var node = extractChallengeNode(wrap);
            if (!(node instanceof HTMLElement)) {
                throw new Error('captcha_markup');
            }
            if (anchor.getAttribute && anchor.getAttribute('data-weline-captcha-lazy') === '1') {
                anchor.setAttribute('data-loaded', '1');
            }
            var mountParent = anchor.parentNode || d.body;
            anchor.replaceWith(node);
            executeFragmentScripts(wrap, mountParent);
            executeFragmentScripts(node, node);
            trackNewCaptchas(node);
            noteVisibility(node, isEffectivelyHidden(node));
            if (form instanceof HTMLFormElement && w.Weline && w.Weline.Form && typeof w.Weline.Form.mount === 'function') {
                w.Weline.Form.mount(form);
            }
            return node;
        });
    }

    async function ensure(target, force, prefer) {
        var ctx = resolveContext(target);
        if (!ctx.anchor) {
            return null;
        }
        if (prefer) {
            ctx.prefer = String(prefer);
        }
        if (!force && isEffectivelyHidden(ctx.anchor) && !prefer) {
            noteVisibility(ctx.anchor, true);
            return null;
        }
        if (!force && ctx.host && ctx.host.getAttribute('data-loaded') === '1' && !prefer) {
            return ctx.existing || ctx.host;
        }
        if (!force && !ctx.host && ctx.existing && !prefer) {
            return ctx.existing;
        }
        if (ensurePromiseByHost && ctx.anchor && ensurePromiseByHost.has(ctx.anchor) && !force && !prefer) {
            return ensurePromiseByHost.get(ctx.anchor);
        }
        var task = (async function () {
            var html = await fetchChallengeHtml(ctx);
            return replaceAnchor(ctx.anchor, html, ctx.form);
        })();
        if (ensurePromiseByHost && ctx.anchor) {
            ensurePromiseByHost.set(ctx.anchor, task);
            task.finally(function () {
                if (ensurePromiseByHost.get(ctx.anchor) === task) {
                    ensurePromiseByHost.delete(ctx.anchor);
                }
            });
        }
        return task;
    }

    async function refresh(target, prefer) {
        return ensure(target, true, prefer);
    }

    async function degradeToLocal(target) {
        var ctx = resolveContext(target);
        var form = ctx.form;
        if (form instanceof HTMLFormElement) {
            delete form.dataset.welineCaptchaBound;
            delete form.dataset.welineCaptchaVerified;
            delete form.dataset.welineCaptchaPending;
        }
        return refresh(target || ctx.anchor || ctx.form, 'local_image');
    }

    function ensureAll(root, force) {
        var scope = root && root.querySelectorAll ? root : d;
        var hosts = scope.querySelectorAll
            ? scope.querySelectorAll('[data-weline-captcha-lazy]:not([data-loaded="1"])')
            : [];
        var tasks = [];
        hosts.forEach(function (host) {
            if (host.closest && host.closest('#cs-bind-modal')) {
                return;
            }
            if (!force && isEffectivelyHidden(host)) {
                noteVisibility(host, true);
                return;
            }
            tasks.push(ensure(host, !!force).catch(function () {}));
        });
        return Promise.all(tasks);
    }

    function onClick(event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var button = target.closest('[data-weline-captcha-refresh]');
        if (!(button instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        refresh(button).catch(function () {});
    }

    function onRefreshEvent(event) {
        var detail = event && event.detail;
        var target = (detail && detail.target) || (detail && detail.form) || event.target;
        refresh(target).catch(function () {});
    }

    function onDegradeEvent(event) {
        var detail = event && event.detail;
        if (!detail || detail.prefer !== 'local_image') {
            return;
        }
        var target = detail.form || detail.target || event.target;
        // CustomerService bind modal owns its challenge URL + degrade_reason logging.
        if (target instanceof Element && target.closest && target.closest('#cs-bind-modal')) {
            return;
        }
        degradeToLocal(target).catch(function () {});
    }

    function onToggle(event) {
        var target = event && event.target;
        if (!(target instanceof HTMLDetailsElement)) {
            return;
        }
        refreshWhenShown(target);
    }

    function boot(root) {
        ensureStylesheet();
        ensureAll(root || d, false).then(function () {
            trackNewCaptchas(root || d);
        }).catch(function () {
            trackNewCaptchas(root || d);
        });
        if (booted) {
            return api;
        }
        booted = true;
        d.addEventListener('click', onClick);
        d.addEventListener('toggle', onToggle, true);
        d.addEventListener('weline:captcha:refresh-requested', onRefreshEvent);
        d.addEventListener('weline:captcha:degrade', onDegradeEvent);
        if (typeof MutationObserver === 'function') {
            var observeApi = (w.Weline && w.Weline.dom && typeof w.Weline.dom.observe === 'function')
                ? w.Weline.dom.observe
                : (w.Weline && typeof w.Weline.observeMutationsCoalesced === 'function'
                    ? w.Weline.observeMutationsCoalesced
                    : null);
            if (observeApi) {
                mutationHandle = observeApi({
                    target: d.documentElement,
                    options: OBSERVE_OPTIONS,
                    idleTimeoutMs: 100,
                    onFlush: function () {
                        runDomScan();
                    },
                });
                mutationObserver = mutationHandle.observer;
            } else {
                /* ARCH_MO_FALLBACK_START */
                mutationObserver = new MutationObserver(function () {
                    scheduleDomScan();
                });
                mutationObserver.observe(d.documentElement, OBSERVE_OPTIONS);
                /* ARCH_MO_FALLBACK_END */
            }
        }
        return api;
    }

    var api = {
        __runtime: 'captcha-lazy',
        challengeUrl: challengeUrl,
        ensure: ensure,
        refresh: refresh,
        degradeToLocal: degradeToLocal,
        ensureAll: ensureAll,
        refreshWhenShown: refreshWhenShown,
        scheduleDomScan: scheduleDomScan,
        withObserverPaused: withObserverPaused,
        boot: boot,
    };
    w.Weline.Captcha = api;

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { boot(d); });
    } else {
        boot(d);
    }
})(window, document);
