/**
 * Weline_Captcha lazy / refresh client runtime.
 * FPC-safe: shared page HTML may keep only lazy hosts; challenges are fetched
 * from weline_captcha/frontend/challenge (private, no-store).
 */
(function (w, d) {
    'use strict';
    w.Weline = w.Weline || {};
    if (w.Weline.Captcha && w.Weline.Captcha.__runtime === 'captcha-lazy') {
        return;
    }

    var DEFAULT_ROUTE = 'weline_captcha/frontend/challenge';
    var STYLESHEET_ID = 'weline-captcha-local-styles';
    var STYLESHEET_FALLBACK = '/Weline/Captcha/view/statics/css/captcha-local.css?v=20260831-layout-fix1';
    var booted = false;

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
        return STYLESHEET_FALLBACK;
    }

    function ensureStylesheet() {
        if (d.getElementById(STYLESHEET_ID)) {
            return;
        }
        var link = d.createElement('link');
        link.id = STYLESHEET_ID;
        link.rel = 'stylesheet';
        link.href = resolveStylesheetUrl();
        d.head.appendChild(link);
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

    function challengeUrl(route, intent, formId) {
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
        return prefix + '/' + clean + '?' + params.toString();
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
        return { form: form, host: host, existing: existing, anchor: anchorEl, intent: intent, formId: formId, route: route };
    }

    async function fetchChallengeHtml(ctx) {
        var response = await fetch(challengeUrl(ctx.route, ctx.intent, ctx.formId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error('captcha_http_' + response.status);
        }
        var payload = await response.json();
        var html = String((payload && (payload.html || (payload.data && payload.data.html))) || '');
        if (!html) {
            throw new Error('captcha_empty');
        }
        return html;
    }

    function replaceAnchor(anchor, html, form) {
        if (!(anchor instanceof HTMLElement)) {
            throw new Error('captcha_anchor');
        }
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
        anchor.replaceWith(node);
        if (form instanceof HTMLFormElement && w.Weline && w.Weline.Form && typeof w.Weline.Form.mount === 'function') {
            w.Weline.Form.mount(form);
        }
        return node;
    }

    async function ensure(target, force) {
        var ctx = resolveContext(target);
        if (!ctx.anchor) {
            return null;
        }
        if (!force && ctx.host && ctx.host.getAttribute('data-loaded') === '1') {
            return ctx.existing || ctx.host;
        }
        if (!force && !ctx.host && ctx.existing) {
            return ctx.existing;
        }
        var html = await fetchChallengeHtml(ctx);
        return replaceAnchor(ctx.anchor, html, ctx.form);
    }

    async function refresh(target) {
        return ensure(target, true);
    }

    function ensureAll(root, force) {
        var scope = root && root.querySelectorAll ? root : d;
        var hosts = scope.querySelectorAll
            ? scope.querySelectorAll('[data-weline-captcha-lazy]:not([data-loaded="1"])')
            : [];
        var tasks = [];
        hosts.forEach(function (host) {
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

    function boot(root) {
        ensureStylesheet();
        ensureAll(root || d, false);
        if (booted) {
            return api;
        }
        booted = true;
        d.addEventListener('click', onClick);
        d.addEventListener('weline:captcha:refresh-requested', onRefreshEvent);
        if (typeof MutationObserver === 'function') {
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) {
                            ensureAll(node, false);
                        }
                    });
                });
            }).observe(d.documentElement, { childList: true, subtree: true });
        }
        return api;
    }

    var api = {
        __runtime: 'captcha-lazy',
        challengeUrl: challengeUrl,
        ensure: ensure,
        refresh: refresh,
        ensureAll: ensureAll,
        boot: boot,
    };
    w.Weline.Captcha = api;

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { boot(d); });
    } else {
        boot(d);
    }
})(window, document);
