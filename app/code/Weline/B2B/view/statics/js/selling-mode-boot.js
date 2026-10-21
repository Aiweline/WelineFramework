/**
 * B2B selling-mode boot (Weline_B2B).
 * Moved verbatim from selling-mode-switcher.phtml inline <script>.
 * Registered as module `b2bSellingModeBoot` (eager); the widget root declares
 * data-weline-load="b2bSellingModeBoot". Keeps working even when the main
 * selling-mode.js bundle is stale.
 */
(function (w, d) {
    /* Inline boot: unhide + switch CTA even if selling-mode.js cache is stale. */
    function readChromeMode() {
        try {
            var htmlMode = String(d.documentElement.getAttribute('data-selling-mode') || '').toLowerCase();
            if (htmlMode === 'tob' || htmlMode === 'toc') {
                return htmlMode;
            }
        } catch (e0) {}
        try {
            var parts = String(d.cookie || '').split(';');
            for (var i = 0; i < parts.length; i += 1) {
                var pair = parts[i].split('=');
                var key = String(pair[0] || '').trim();
                if (key !== 'weline_selling_mode' && !/^weline_selling_mode_w\d+$/.test(key) && key !== 'selling_mode') {
                    continue;
                }
                var value = decodeURIComponent(String(pair[1] || '')).toLowerCase();
                if (value === 'tob' || value === 'toc') {
                    return value;
                }
            }
        } catch (e1) {}
        try {
            var explicit = String(w.sessionStorage.getItem('weline_cart_type_explicit') || '').toLowerCase();
            if (explicit === 'tob' || explicit === 'toc') {
                return explicit;
            }
        } catch (e2) {}
        return 'toc';
    }
    function writeTocPreference() {
        var maxAge = 30 * 24 * 60 * 60;
        var secure = w.location && String(w.location.protocol) === 'https:' ? '; Secure' : '';
        var names = ['weline_selling_mode', 'selling_mode'];
        try {
            var wid = '';
            var hint = d.querySelector('[data-b2b-retail-only-hint="1"][data-website-id]');
            if (hint) {
                wid = String(hint.getAttribute('data-website-id') || '').trim();
            }
            if (!wid && w.site) {
                wid = String(w.site.website_id || w.site.websiteId || '').trim();
            }
            if (wid && /^\d+$/.test(wid)) {
                names.unshift('weline_selling_mode_w' + wid);
            }
        } catch (eWid) {}
        names.forEach(function (name) {
            d.cookie = name + '=toc; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
        });
        try {
            w.sessionStorage.setItem('weline_selling_mode', 'toc');
            w.sessionStorage.setItem('weline_cart_type_explicit', 'toc');
        } catch (eSs) {}
        try {
            d.documentElement.setAttribute('data-selling-mode', 'toc');
        } catch (eHtml) {}
    }
    function paintRetailChrome() {
        d.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            root.setAttribute('data-cart-type', 'toc');
            root.classList.remove('is-cart-type-tob');
            root.querySelectorAll('[data-mini-cart-type-caption]').forEach(function (el) {
                el.hidden = false;
                el.removeAttribute('hidden');
                el.textContent = '零售';
            });
            root.querySelectorAll('[data-mini-cart-title]').forEach(function (el) {
                el.textContent = '零售车';
            });
            root.querySelectorAll('[data-mini-cart-trigger]').forEach(function (el) {
                el.setAttribute('aria-label', '零售车');
            });
        });
    }
    function switchToRetailCart(ev) {
        if (ev) {
            try { ev.preventDefault(); } catch (ePrev) {}
            try { ev.stopPropagation(); } catch (eStop) {}
        }
        if (w.WelineB2BSellingMode && typeof w.WelineB2BSellingMode.switchToRetailCart === 'function') {
            w.WelineB2BSellingMode.switchToRetailCart();
            return;
        }
        if (w.WelineB2BSellingMode && typeof w.WelineB2BSellingMode.setMode === 'function') {
            w.WelineB2BSellingMode.setMode('toc');
            if (typeof w.WelineB2BSellingMode.syncMiniCartChrome === 'function') {
                w.WelineB2BSellingMode.syncMiniCartChrome('toc');
            }
            if (typeof w.WelineB2BSellingMode.syncRetailOnlyHints === 'function') {
                w.WelineB2BSellingMode.syncRetailOnlyHints('toc');
            }
            return;
        }
        writeTocPreference();
        paintRetailChrome();
        d.querySelectorAll('[data-b2b-retail-only-hint="1"]').forEach(function (el) {
            el.hidden = true;
        });
        try {
            w.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
                detail: { selling_mode: 'toc', cart_type: 'toc', source: 'retail-only-inline' }
            }));
            w.dispatchEvent(new CustomEvent('weline:cart-type-changed', {
                detail: { selling_mode: 'toc', cart_type: 'toc', source: 'retail-only-inline' }
            }));
        } catch (eEv) {}
    }
    function applyRetailOnlyHints(ev) {
        var mode = '';
        if (ev && ev.detail && typeof ev.detail === 'object') {
            mode = String(ev.detail.selling_mode || ev.detail.cart_type || '').toLowerCase();
        }
        if (mode !== 'tob' && mode !== 'toc') {
            mode = readChromeMode();
        }
        var show = mode === 'tob';
        d.querySelectorAll('[data-b2b-retail-only-hint="1"]').forEach(function (el) {
            el.hidden = !show;
        });
    }
    if (!d.documentElement.getAttribute('data-b2b-retail-only-inline-bound')) {
        d.documentElement.setAttribute('data-b2b-retail-only-inline-bound', '1');
        d.addEventListener('click', function (ev) {
            var btn = ev.target && ev.target.closest
                ? ev.target.closest('[data-b2b-retail-only-switch-toc]')
                : null;
            if (!btn) {
                return;
            }
            switchToRetailCart(ev);
        }, true);
    }
    applyRetailOnlyHints();
    w.addEventListener('weline:selling-mode-changed', applyRetailOnlyHints);
    w.addEventListener('weline:cart-type-changed', applyRetailOnlyHints);
})(window, document);
