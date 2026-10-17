/**
 * Storefront inline dictionary edit shell (operator session only).
 * Discovers <w-i18n-phrase> and [[W18N:src_b64url:tr_b64url]] attribute markers.
 * Stamps same-origin <a href> with pierce query so in-site navigation keeps the session
 * without server URL builders. Operator chrome is a draggable book FAB (not a full bar).
 */
(function () {
    'use strict';

    var ATTR_RE = /\[\[W18N:([A-Za-z0-9_-]+):([A-Za-z0-9_-]+)\]\]/g;
    var BOOK = '\uD83D\uDCD6';
    var PIERCE_KEY = 'w_fpc_pierce';
    var INLINE_KEY = 'i18n_inline';
    var SS_PIERCE = 'weline_i18n_inline_pierce';
    var SS_INLINE = 'weline_i18n_inline_flag';
    var SS_FAB_POS = 'weline_i18n_inline_fab_pos';
    var SS_FAB_COLLAPSED = 'weline_i18n_inline_fab_collapsed';

    function b64urlDecode(encoded) {
        try {
            var s = String(encoded || '').replace(/-/g, '+').replace(/_/g, '/');
            while (s.length % 4) s += '=';
            return decodeURIComponent(escape(atob(s)));
        } catch (e) {
            return '';
        }
    }

    /** Peel nested/polluted [[W18N:…]] (dictionary may store markers as "translation"). */
    function unwrapW18nValue(raw) {
        var s = String(raw || '');
        var guard = 0;
        while (guard++ < 8 && s.indexOf('[[W18N:') !== -1) {
            ATTR_RE.lastIndex = 0;
            var next = s.replace(ATTR_RE, function (_, a, b) {
                return b64urlDecode(b) || b64urlDecode(a) || '';
            });
            if (next === s) break;
            s = next;
        }
        return s;
    }

    function closestShell() {
        return document.querySelector('[data-w-component="i18n-inline-edit-shell"]');
    }

    function endpoint(name) {
        var shell = closestShell();
        if (!shell) return '';
        return String(shell.getAttribute('data-' + name) || '');
    }

    function postForm(url, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) {
            body.append(k, data[k] == null ? '' : String(data[k]));
        });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
            body: body.toString(),
        }).then(function (r) { return r.json(); });
    }

    function detectPageLocale() {
        try {
            var cfg = document.getElementById('weline-frontend-runtime-config');
            if (cfg && cfg.textContent) {
                var json = JSON.parse(cfg.textContent);
                if (json && json.currentLang) return String(json.currentLang);
            }
        } catch (e) {}
        return '';
    }

    /**
     * Update-current-page SSE: dictionary export → cache invalidate → FPC purge.
     * Prefer XHR (same-origin cookies) over EventSource.
     */
    function postUpdatePageSse(url, data, onMsg) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) {
            body.append(k, data[k] == null ? '' : String(data[k]));
        });
        if (!body.has('locale_code') || !body.get('locale_code')) {
            body.set('locale_code', detectPageLocale());
        }
        body.set('sse', '1');
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            var seen = 0;
            var lastComplete = null;
            var failed = null;
            function consume() {
                var chunk = xhr.responseText.substring(seen);
                seen = xhr.responseText.length;
                var parts = chunk.split(/\n\n+/);
                parts.forEach(function (block) {
                    if (!block || block.indexOf('data:') === -1) return;
                    var eventName = 'message';
                    var dataLines = [];
                    block.split(/\n/).forEach(function (line) {
                        if (line.indexOf('event:') === 0) eventName = line.slice(6).trim();
                        else if (line.indexOf('data:') === 0) dataLines.push(line.slice(5).trim());
                    });
                    if (!dataLines.length) return;
                    var payload = {};
                    try { payload = JSON.parse(dataLines.join('\n')); } catch (e) { payload = { message: dataLines.join('\n') }; }
                    if (payload.message && typeof onMsg === 'function') {
                        onMsg(String(payload.message), eventName === 'failed');
                    }
                    if (eventName === 'complete') lastComplete = payload;
                    if (eventName === 'failed') failed = payload;
                });
            }
            xhr.open('POST', url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8');
            xhr.setRequestHeader('Accept', 'text/event-stream');
            xhr.onprogress = consume;
            xhr.onload = function () {
                consume();
                if (failed) {
                    reject(new Error((failed && failed.message) || '更新失败'));
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(lastComplete || { success: true });
                    return;
                }
                reject(new Error('HTTP ' + xhr.status));
            };
            xhr.onerror = function () { reject(new Error('网络错误')); };
            xhr.send(body.toString());
        }).then(function (res) {
            if (typeof onMsg === 'function') {
                onMsg((res && res.message) || '已更新' + (res && res.slug ? ' · ' + res.slug : ''), false);
            }
            return res;
        }).catch(function (e) {
            if (typeof onMsg === 'function') onMsg(String(e && e.message || e), true);
            throw e;
        });
    }

    function getJson(url) {
        return fetch(url, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); });
    }

    var state = {
        source: '',
        translate: '',
        locale: '',
        targetEl: null,
        attrName: null,
        portal: null,
        monitor: null,
        open: false,
    };

    function uiFloating() {
        var ui = window.Weline && window.Weline.UI;
        return ui && ui.floating ? ui.floating : null;
    }

    function ensurePopover() {
        var el = document.getElementById('w-i18n-inline-popover');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'w-i18n-inline-popover';
        el.className = 'w-popover w-surface';
        el.setAttribute('data-w-component', 'popover');
        el.setAttribute('data-w-popover-panel', '');
        el.setAttribute('data-surface', 'raised');
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', '店面点译');
        el.dataset.wPlacement = 'top-start';
        el.dataset.state = 'closed';
        el.setAttribute('aria-hidden', 'true');
        el.hidden = true;
        el.style.setProperty('--w-floating-inline-size', '20rem');
        el.style.setProperty('--w-floating-max-inline-size', 'min(24rem, calc(100dvw - 1rem))');
        el.innerHTML = ''
            + '<div class="w-menu__header" style="text-transform:none;font-size:14px;font-weight:600;color:inherit;">' + BOOK + ' 点译</div>'
            + '<div data-role="source" style="color:var(--weline-theme-text-muted,#565959);font-size:12px;margin:0 0 8px;padding:0 8px;word-break:break-word;"></div>'
            + '<label style="display:block;font-size:12px;margin:0 8px 4px;">语种'
            + '<select data-role="locale" class="w-input" style="display:block;width:100%;margin-top:2px;"></select></label>'
            + '<label style="display:block;font-size:12px;margin:8px 8px 4px;">译文'
            + '<textarea data-role="translate" class="w-input" rows="3" style="display:block;width:100%;margin-top:2px;box-sizing:border-box;"></textarea></label>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 8px 8px;">'
            + '<button type="button" class="w-button" data-role="save" data-size="sm">保存</button>'
            + '<button type="button" class="w-button" data-role="close" data-size="sm" data-tone="quiet" style="margin-left:auto;">关闭</button>'
            + '</div>'
            + '<div data-role="msg" style="margin:0 8px 8px;font-size:12px;color:var(--weline-theme-text-muted,#565959);"></div>';
        document.body.appendChild(el);
        return el;
    }

    function setMsg(text, isError) {
        var pop = ensurePopover();
        var msg = pop.querySelector('[data-role="msg"]');
        if (!msg) return;
        msg.textContent = text || '';
        msg.style.color = isError ? 'var(--weline-theme-danger,#b12704)' : 'var(--weline-theme-text-muted,#565959)';
    }

    function destroyPopoverMonitor() {
        if (state.monitor && typeof state.monitor.destroy === 'function') {
            try { state.monitor.destroy(); } catch (e) {}
        }
        state.monitor = null;
    }

    function closePopover() {
        var pop = document.getElementById('w-i18n-inline-popover');
        var floating = uiFloating();
        if (state.monitor) {
            try {
                if (pop) state.monitor.unobserve(pop);
                state.monitor.reset();
            } catch (e) {}
        }
        destroyPopoverMonitor();
        if (pop && floating && typeof floating.clear === 'function') {
            floating.clear(pop);
        }
        if (state.portal && typeof state.portal.restore === 'function') {
            try { state.portal.restore(); } catch (e2) {}
        }
        if (pop) {
            pop.hidden = true;
            pop.dataset.state = 'closed';
            pop.setAttribute('aria-hidden', 'true');
            delete pop.dataset.wFloatingPositioned;
        }
        state.open = false;
        state.targetEl = null;
        state.attrName = null;
    }

    /** Strip residual [[W18N:…]] markers so the edit field never shows raw tokens. */
    function unwrapW18nDisplay(text) {
        var s = String(text == null ? '' : text);
        if (s.indexOf('[[W18N:') === -1) return s;
        ATTR_RE.lastIndex = 0;
        return s.replace(ATTR_RE, function (_, srcB64, trB64) {
            return b64urlDecode(trB64) || b64urlDecode(srcB64) || '';
        });
    }

    function openPopover(anchor, payload) {
        if (!(anchor instanceof Element)) return;
        state.source = unwrapW18nDisplay(payload.source || '');
        state.translate = unwrapW18nDisplay(payload.translate || '');
        state.locale = payload.locale || '';
        state.targetEl = payload.targetEl || null;
        state.attrName = payload.attrName || null;
        var pop = ensurePopover();
        pop.querySelector('[data-role="source"]').textContent = state.source;
        pop.querySelector('[data-role="translate"]').value = state.translate;
        loadLocales(pop);

        var floating = uiFloating();
        var ui = window.Weline && window.Weline.UI;
        if (floating && typeof floating.portal === 'function' && typeof floating.monitor === 'function') {
            if (state.open) closePopover();
            if (!state.portal) {
                state.portal = floating.portal(pop, 'i18n-inline-edit');
            }
            state.monitor = floating.monitor(
                anchor,
                function () { return pop; },
                function () { return pop.dataset.wPlacement || 'top-start'; },
                function () { closePopover(); }
            );
            pop.dataset.wPlacement = 'top-start';
            pop.dataset.wFloatingPositioned = 'pending';
            try { state.portal.mount(); } catch (e) {}
            pop.hidden = false;
            pop.dataset.state = 'open';
            pop.setAttribute('aria-hidden', 'false');
            state.open = true;
            state.monitor.observe(pop);
            var reference = typeof floating.capture === 'function'
                ? floating.capture(anchor, null, 'element')
                : null;
            state.monitor.place(reference);
            // Portal host = body; origin = click path so Theme can walk containers.
            // Then reinforce: sticky header is often z≈overlay+1 (> menu floor).
            applyPopoverStack(pop, state.targetEl || anchor);
            return;
        }

        // Fallback if theme UI floating is not ready yet.
        var rect = anchor.getBoundingClientRect();
        var width = 320;
        var left = Math.min(window.innerWidth - width - 8, Math.max(8, rect.left));
        var top = rect.top - 12;
        pop.style.position = 'fixed';
        pop.style.left = left + 'px';
        pop.hidden = false;
        pop.dataset.state = 'open';
        var h = pop.getBoundingClientRect().height || 220;
        if (top - h < 8) top = Math.min(window.innerHeight - h - 8, rect.bottom + 8);
        else top = top - h;
        pop.style.top = Math.max(8, top) + 'px';
        applyPopoverStack(pop, state.targetEl || anchor);
        state.open = true;
    }

    /**
     * Popover must sit above the stacking context that owns the click
     * (e.g. sticky header at --weline-z-overlay+1), not the book glyph's local z.
     * Rule: max(ancestor peak of origin, Theme stack.apply, menu floor) + keep ≥ peak+1.
     */
    function readNumericZ(el) {
        if (!(el instanceof Element)) return 0;
        try {
            var raw = window.getComputedStyle(el).zIndex;
            if (!raw || raw === 'auto') return 0;
            var n = parseInt(raw, 10);
            return isFinite(n) ? n : 0;
        } catch (e) {
            return 0;
        }
    }

    function isOperatorChrome(el) {
        if (!(el instanceof Element)) return true;
        if (el.id === 'w-i18n-inline-fab' || el.id === 'w-i18n-inline-popover'
            || el.id === 'w-i18n-inline-fab-panel') {
            return true;
        }
        try {
            return !!(el.closest && (
                el.closest('#w-i18n-inline-fab')
                || el.closest('#w-i18n-inline-popover')
                || el.closest('#w-i18n-inline-fab-panel')
            ));
        } catch (e) {
            return false;
        }
    }

    function resolveClickStackBase(origin) {
        var base = 0;
        var node = origin instanceof Element ? origin : null;
        while (node && node !== document.documentElement) {
            if (!(node.classList && node.classList.contains('w-i18n-book'))
                && !isOperatorChrome(node)) {
                base = Math.max(base, readNumericZ(node));
            }
            // Sibling stacking contexts (account/cart drawers at z≈1000) must win
            // over a plain header z=100, or the popover still slides under chrome.
            if (node.parentElement) {
                Array.prototype.forEach.call(node.parentElement.children, function (sib) {
                    if (sib === node || !(sib instanceof Element) || isOperatorChrome(sib)) return;
                    base = Math.max(base, readNumericZ(sib));
                });
            }
            if (node === document.body) break;
            node = node.parentElement;
        }
        return base;
    }

    function applyPopoverStack(pop, origin) {
        if (!(pop instanceof HTMLElement)) return;
        var ui = window.Weline && window.Weline.UI;
        if (ui && typeof ui.stack === 'object' && typeof ui.stack.apply === 'function') {
            try {
                // Body-hosted portal + origin for container walk (Theme contract).
                ui.stack.apply(pop, document.body, origin instanceof Element ? origin : null);
            } catch (e) {}
        }
        var need = resolveClickStackBase(origin) + 1;
        try {
            var floor = parseInt(
                window.getComputedStyle(document.documentElement).getPropertyValue('--weline-z-menu').trim(),
                10
            );
            if (isFinite(floor)) need = Math.max(need, floor + 1);
        } catch (e2) {}
        var cur = parseInt(pop.style.zIndex || '0', 10);
        if (!isFinite(cur)) {
            try {
                cur = parseInt(window.getComputedStyle(pop).zIndex, 10) || 0;
            } catch (e3) {
                cur = 0;
            }
        }
        if (cur < need) {
            pop.style.setProperty('z-index', String(need));
        }
        pop.dataset.wFloatingPortal = 'true';
    }

    function loadLocales(pop) {
        var url = endpoint('locales-url');
        var select = pop.querySelector('[data-role="locale"]');
        if (!url || !select) return;
        getJson(url).then(function (res) {
            if (!res || !res.success) return;
            select.innerHTML = '';
            (res.locales || []).forEach(function (row) {
                var opt = document.createElement('option');
                opt.value = row.code;
                var tag = row.scope === 'channel' ? '渠道' : '网站';
                opt.textContent = row.code + ' (' + tag + ')' + (row.current ? ' · 当前' : '');
                if (row.current || row.code === state.locale) opt.selected = true;
                select.appendChild(opt);
            });
            if (state.open && state.monitor && typeof state.monitor.place === 'function') {
                try { state.monitor.place(); } catch (e) {}
            }
        }).catch(function () {});
    }

    /**
     * Books float on the host only (absolute). They inherit the host's stacking
     * context — never a body-level fixed layer with a invented mega z-index.
     */
    var bookPairs = [];
    var bookRepositionQueued = false;
    var bookListenersBound = false;

    function ensureBookStyles() {
        var style = document.getElementById('w-i18n-book-style');
        if (!style) {
            style = document.createElement('style');
            style.id = 'w-i18n-book-style';
            document.head.appendChild(style);
        }
        // Always rewrite so older mega-layer CSS does not stick after hot reload.
        style.textContent = [
            /* Host becomes positioning context; do NOT invent a global z-index. */
            '[data-i18n-book-host="1"]{position:relative;}',
            '.w-i18n-book{',
            'position:absolute!important;',
            'box-sizing:border-box!important;',
            'width:16px!important;height:16px!important;margin:0!important;padding:0!important;',
            'border:0!important;background:transparent!important;box-shadow:none!important;',
            'overflow:visible!important;line-height:16px!important;font-size:12px!important;',
            'text-align:center;cursor:pointer;pointer-events:auto;opacity:.92;',
            'z-index:auto;',
            'transform:translate(30%,-30%);',
            '}',
            '.w-i18n-book:hover,.w-i18n-book:focus-visible{opacity:1;outline:none;}',
            'w-i18n-phrase[data-i18n-book-host],.w-i18n-text[data-i18n-book-host]{',
            'outline:1px dashed rgba(0,115,187,.35);',
            '}',
            /* Legacy global layer: neutralize if left in DOM from an older session. */
            '#w-i18n-book-layer,.w-i18n-book-layer--local{',
            'display:none!important;pointer-events:none!important;',
            '}',
        ].join('');
    }

    function ensureBookListeners() {
        if (bookListenersBound) return;
        bookListenersBound = true;
        window.addEventListener('scroll', scheduleBookReposition, true);
        window.addEventListener('resize', scheduleBookReposition);
        document.addEventListener('pointerover', scheduleBookReposition, true);
        document.addEventListener('focusin', scheduleBookReposition, true);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', scheduleBookReposition);
            window.visualViewport.addEventListener('scroll', scheduleBookReposition);
        }
    }

    function purgeLegacyBookLayers() {
        try {
            var legacy = document.getElementById('w-i18n-book-layer');
            if (legacy && legacy.parentNode) legacy.parentNode.removeChild(legacy);
            document.querySelectorAll('.w-i18n-book-layer--local').forEach(function (node) {
                if (node && node.parentNode) node.parentNode.removeChild(node);
            });
        } catch (e) {}
    }

    /**
     * Host must be painted and at least partly in the viewport.
     * Hides books for off-canvas chrome (e.g. closed sidebar aria-label「关闭菜单」).
     */
    function isBookHostVisible(host) {
        if (!(host instanceof Element) || !host.isConnected) return false;
        try {
            if (host.hasAttribute('hidden')) return false;
            if (host.getAttribute('aria-hidden') === 'true') return false;
            // Closed floating shells often stay layout-visible; hide their books.
            // Do NOT match bare [hidden] ancestors here — computed style already covers that,
            // and over-matching buried visible chrome (nav LI, money row) under false flags.
            var buried = host.closest(
                '[aria-hidden="true"], [data-state="closed"], [data-state="hidden"], '
                + '.categories-sidebar[aria-hidden="true"], .mini-cart-drawer[aria-hidden="true"]'
            );
            if (buried) return false;
            var cs = window.getComputedStyle(host);
            if (!cs || cs.display === 'none' || cs.visibility === 'hidden') return false;
            if (parseFloat(cs.opacity || '1') < 0.05) return false;
            var r = host.getBoundingClientRect();
            if (!r || r.width < 0.5 || r.height < 0.5) return false;
            var vw = window.innerWidth || document.documentElement.clientWidth || 0;
            var vh = window.innerHeight || document.documentElement.clientHeight || 0;
            // Partially in viewport is enough; below-fold hosts reappear on scroll.
            if (r.bottom < 0 || r.right < 0 || r.top > vh || r.left > vw) return false;
            return true;
        } catch (e) {
            return false;
        }
    }

    function scheduleBookReposition() {
        if (bookRepositionQueued) return;
        bookRepositionQueued = true;
        var run = function () {
            bookRepositionQueued = false;
            repositionAllBooks();
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(run);
        } else {
            setTimeout(run, 16);
        }
    }

    function placeBookButton(host, btn) {
        if (!(host instanceof Element) || !(btn instanceof HTMLElement)) return;
        if (!isBookHostVisible(host)) {
            btn.style.visibility = 'hidden';
            btn.setAttribute('aria-hidden', 'true');
            return;
        }
        btn.style.visibility = 'visible';
        btn.removeAttribute('aria-hidden');
        // Anchor to host's own box (absolute child) — no viewport fixed coords.
        btn.style.left = 'auto';
        btn.style.right = '-2px';
        btn.style.top = '-2px';
        btn.style.bottom = 'auto';
    }

    function repositionAllBooks() {
        for (var i = bookPairs.length - 1; i >= 0; i--) {
            var pair = bookPairs[i];
            if (!pair || !pair.host || !pair.btn || !pair.host.isConnected || !pair.btn.isConnected) {
                if (pair && pair.btn && pair.btn.parentNode) {
                    try { pair.btn.parentNode.removeChild(pair.btn); } catch (e) {}
                }
                bookPairs.splice(i, 1);
                continue;
            }
            if (pair.btn.parentNode !== pair.host) {
                try { pair.host.appendChild(pair.btn); } catch (e2) {}
            }
            placeBookButton(pair.host, pair.btn);
        }
    }

    /** Absolute child of host — stacks with host; no invented z-index. */
    function attachFloatingBook(host, btn) {
        if (!host || !btn) return;
        ensureBookStyles();
        ensureBookListeners();
        purgeLegacyBookLayers();
        try {
            host.setAttribute('data-i18n-book-host', '1');
        } catch (e) {}
        btn.setAttribute('data-i18n-book-for', '1');
        btn.removeAttribute('data-i18n-book-in-surface');
        if (btn.parentNode !== host) {
            host.appendChild(btn);
        }
        var exists = false;
        for (var i = 0; i < bookPairs.length; i++) {
            if (bookPairs[i].btn === btn || bookPairs[i].host === host) {
                bookPairs[i] = { host: host, btn: btn };
                exists = true;
                break;
            }
        }
        if (!exists) bookPairs.push({ host: host, btn: btn });
        placeBookButton(host, btn);
        scheduleBookReposition();
    }

    function decorateBodyPhrases(root) {
        ensureBookStyles();
        (root || document).querySelectorAll('w-i18n-phrase').forEach(function (el) {
            if (el.dataset.i18nBookBound === '1') return;
            el.dataset.i18nBookBound = '1';
            var btn = makeBookButton('点译', function (book) {
                openPopover(book, {
                    source: el.getAttribute('data-source') || '',
                    // Prefer phrase text without the floating book glyph.
                    translate: phrasePlainText(el),
                    locale: el.getAttribute('data-locale') || '',
                    targetEl: el,
                });
            });
            el.addEventListener('mouseenter', function () {
                el.style.background = 'rgba(0,115,187,.08)';
            });
            el.addEventListener('mouseleave', function () {
                el.style.background = '';
            });
            attachFloatingBook(el, btn);
        });
    }

    function phrasePlainText(el) {
        if (!el) return '';
        var clone = el.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll('.w-i18n-book'), function (b) {
            b.parentNode && b.parentNode.removeChild(b);
        });
        return unwrapW18nDisplay(String(clone.textContent || '').trim());
    }

    function makeBookButton(title, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'w-i18n-book';
        btn.textContent = BOOK;
        btn.title = title || '点译';
        btn.setAttribute('aria-label', title || '点译');
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            onClick(btn);
        });
        return btn;
    }

    function isSkippedDecorateHost(el) {
        if (!el || el.nodeType !== 1) return true;
        var tag = (el.tagName || '').toUpperCase();
        return tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT'
            || tag === 'TEMPLATE' || tag === 'TEXTAREA' || tag === 'CODE' || tag === 'PRE';
    }

    /** title / aria / alt / placeholder — safe to swap + book; skip data-* JSON blobs. */
    function isEditablePhraseAttr(name) {
        var n = String(name || '').toLowerCase();
        return n === 'title' || n === 'alt' || n === 'placeholder'
            || n === 'aria-label' || n === 'aria-description' || n === 'aria-placeholder'
            || n.indexOf('aria-') === 0;
    }

    function decorateTextMarkers(root) {
        var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        var n;
        while ((n = walker.nextNode())) {
            if (!n.nodeValue || n.nodeValue.indexOf('[[W18N:') === -1) continue;
            if (isSkippedDecorateHost(n.parentElement)) continue;
            nodes.push(n);
        }
        nodes.forEach(function (textNode) {
            var raw = textNode.nodeValue || '';
            ATTR_RE.lastIndex = 0;
            if (!ATTR_RE.test(raw)) return;
            ATTR_RE.lastIndex = 0;
            var frag = document.createDocumentFragment();
            var last = 0;
            var m;
            while ((m = ATTR_RE.exec(raw))) {
                if (m.index > last) {
                    frag.appendChild(document.createTextNode(raw.slice(last, m.index)));
                }
                var src = unwrapW18nValue(b64urlDecode(m[1]));
                var tr = unwrapW18nValue(b64urlDecode(m[2]) || src);
                var span = document.createElement('span');
                span.className = 'w-i18n-text';
                span.textContent = tr;
                var textBook = makeBookButton('点译', function (btn) {
                    openPopover(btn, { source: src, translate: tr, locale: '', targetEl: span });
                });
                attachFloatingBook(span, textBook);
                frag.appendChild(span);
                last = m.index + m[0].length;
            }
            if (last < raw.length) {
                frag.appendChild(document.createTextNode(raw.slice(last)));
            }
            textNode.parentNode && textNode.parentNode.replaceChild(frag, textNode);
        });
    }

    /**
     * ANY attribute with [[W18N:]] (aria + data-i18n-* …): unwrap only — never books.
     * Mini-cart reads data-i18n-* into textContent; leaving markers there re-leaks after boot.
     */
    function decorateAttributeMarkers(root) {
        var list = (root || document.body).querySelectorAll('*');
        Array.prototype.forEach.call(list, function (node) {
            if (isSkippedDecorateHost(node) || !node.attributes) return;
            var names = [];
            Array.prototype.forEach.call(node.attributes, function (attr) {
                names.push(attr.name);
            });
            names.forEach(function (name) {
                var value = node.getAttribute(name);
                if (!value || value.indexOf('[[W18N:') === -1) return;
                node.setAttribute(name, unwrapW18nValue(value));
            });
        });
    }

    /** Fail-closed: any leftover visible [[W18N:]] text → plain translation (book if new span). */
    function unwrapResidualW18nText(root) {
        var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        var n;
        while ((n = walker.nextNode())) {
            if (!n.nodeValue || n.nodeValue.indexOf('[[W18N:') === -1) continue;
            if (isSkippedDecorateHost(n.parentElement)) continue;
            // Already handled by decorateTextMarkers into .w-i18n-text
            if (n.parentElement && n.parentElement.classList
                && n.parentElement.classList.contains('w-i18n-text')) {
                continue;
            }
            nodes.push(n);
        }
        nodes.forEach(function (textNode) {
            var raw = textNode.nodeValue || '';
            ATTR_RE.lastIndex = 0;
            if (!ATTR_RE.test(raw)) return;
            ATTR_RE.lastIndex = 0;
            var frag = document.createDocumentFragment();
            var last = 0;
            var m;
            var addedBook = false;
            while ((m = ATTR_RE.exec(raw))) {
                if (m.index > last) {
                    frag.appendChild(document.createTextNode(raw.slice(last, m.index)));
                }
                var src = unwrapW18nValue(b64urlDecode(m[1]));
                var tr = unwrapW18nValue(b64urlDecode(m[2]) || src);
                var span = document.createElement('span');
                span.className = 'w-i18n-text';
                span.textContent = tr || src;
                if (!addedBook && (tr || src)) {
                    var textBook = makeBookButton('点译', function (btn) {
                        openPopover(btn, { source: src, translate: tr || src, locale: '', targetEl: span });
                    });
                    attachFloatingBook(span, textBook);
                    addedBook = true;
                }
                frag.appendChild(span);
                last = m.index + m[0].length;
            }
            if (last < raw.length) {
                frag.appendChild(document.createTextNode(raw.slice(last)));
            }
            textNode.parentNode && textNode.parentNode.replaceChild(frag, textNode);
        });
    }

    /** JS string tables baked with marked __() (e.g. window.__welineHeaderLabels). */
    function sanitizeGlobalLabelTables() {
        var tables = [window.__welineHeaderLabels];
        tables.forEach(function (tbl) {
            if (!tbl || typeof tbl !== 'object') return;
            Object.keys(tbl).forEach(function (k) {
                if (typeof tbl[k] === 'string' && tbl[k].indexOf('[[W18N:') !== -1) {
                    tbl[k] = unwrapW18nValue(tbl[k]);
                }
            });
        });
    }

    function decorateAllMarkers(root) {
        sanitizeGlobalLabelTables();
        decorateBodyPhrases(root || document);
        decorateTextMarkers(root ? (root.body || root) : document.body);
        decorateAttributeMarkers(root ? (root.body || root) : document.body);
        unwrapResidualW18nText(root ? (root.body || root) : document.body);
        scheduleBookReposition();
    }

    function bindPopoverActions() {
        var pop = ensurePopover();
        pop.querySelector('[data-role="close"]').onclick = closePopover;
        pop.querySelector('[data-role="save"]').onclick = function () {
            var url = endpoint('phrase-url');
            if (!url) return setMsg('缺少保存地址', true);
            // Prefer DOM (shared across accidental dual boot) over closed-over state.
            var word = String(
                (pop.querySelector('[data-role="source"]') || {}).textContent
                || state.source
                || ''
            ).trim();
            var locale = String(
                (pop.querySelector('[data-role="locale"]') || {}).value
                || state.locale
                || ''
            ).trim();
            var translate = String(
                (pop.querySelector('[data-role="translate"]') || {}).value
                || ''
            );
            if (!word || !locale) {
                return setMsg('参数不完整（缺词条或语种）', true);
            }
            state.source = word;
            state.locale = locale;
            postForm(url, { word: word, translate: translate, locale_code: locale })
                .then(function (res) {
                    if (!res || !res.success) {
                        setMsg((res && res.message) || '保存失败', true);
                        return;
                    }
                    setMsg(res.message || '已保存');
                    if (state.targetEl) {
                        if (state.attrName) {
                            state.targetEl.setAttribute(state.attrName, translate);
                        } else {
                            state.targetEl.textContent = translate;
                        }
                    }
                })
                .catch(function (e) { setMsg(String(e && e.message || e), true); });
        };
    }

    function captureStampFromLocation() {
        try {
            var u = new URL(location.href);
            var pierce = u.searchParams.get(PIERCE_KEY) || '';
            var inline = u.searchParams.get(INLINE_KEY) || '';
            if (pierce) {
                sessionStorage.setItem(SS_PIERCE, pierce);
            }
            if (inline === '1' || inline.toLowerCase() === 'true') {
                sessionStorage.setItem(SS_INLINE, '1');
            }
        } catch (e) {}
    }

    function stampParams() {
        var pierce = '';
        var inline = '';
        try {
            pierce = sessionStorage.getItem(SS_PIERCE) || '';
            inline = sessionStorage.getItem(SS_INLINE) || '';
        } catch (e) {}
        if (!pierce) {
            try {
                pierce = new URL(location.href).searchParams.get(PIERCE_KEY) || '';
            } catch (e2) {}
        }
        if (!inline) {
            inline = '1';
        }
        return { pierce: pierce, inline: inline };
    }

    function withStamp(href) {
        var stamp = stampParams();
        if (!stamp.pierce) return href;
        try {
            var abs = new URL(href, location.href);
            if (abs.origin !== location.origin) return href;
            if (abs.protocol !== 'http:' && abs.protocol !== 'https:') return href;
            abs.searchParams.set(INLINE_KEY, '1');
            abs.searchParams.set(PIERCE_KEY, stamp.pierce);
            return abs.pathname + abs.search + abs.hash;
        } catch (e) {
            return href;
        }
    }

    /**
     * Mutate same-origin <a href> in place (no click intercept / 禁链).
     * Absolute http(s) same-origin hrefs become path+query+hash with stamp.
     */
    function stampAnchor(a) {
        if (!a || a.nodeType !== 1 || a.tagName !== 'A') return;
        if (a.closest && (a.closest('#w-i18n-inline-fab') || a.closest('#w-i18n-inline-popover'))) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^\s*javascript:/i.test(href) || /^\s*mailto:/i.test(href) || /^\s*tel:/i.test(href)) {
            return;
        }
        var next = withStamp(href);
        if (next === href) return;
        a.setAttribute('href', next);
        a.dataset.i18nStamp = '1';
    }

    function stampAllAnchors(root) {
        var stamp = stampParams();
        if (!stamp.pierce) return;
        (root || document).querySelectorAll('a[href]').forEach(stampAnchor);
    }

    function exitInlineSession() {
        var url = endpoint('stop-url');
        var clear = function () {
            try {
                sessionStorage.removeItem(SS_PIERCE);
                sessionStorage.removeItem(SS_INLINE);
                sessionStorage.removeItem(SS_FAB_POS);
                sessionStorage.removeItem(SS_FAB_COLLAPSED);
            } catch (e) {}
            location.assign(location.pathname);
        };
        if (!url) {
            clear();
            return;
        }
        postForm(url, {}).finally(clear);
    }

    function loadFabPos() {
        try {
            var raw = sessionStorage.getItem(SS_FAB_POS);
            if (!raw) return null;
            var pos = JSON.parse(raw);
            if (!pos || typeof pos.left !== 'number' || typeof pos.top !== 'number') return null;
            return pos;
        } catch (e) {
            return null;
        }
    }

    function saveFabPos(left, top) {
        try {
            sessionStorage.setItem(SS_FAB_POS, JSON.stringify({ left: left, top: top }));
        } catch (e) {}
    }

    function isFabCollapsed() {
        try {
            return sessionStorage.getItem(SS_FAB_COLLAPSED) === '1';
        } catch (e) {
            return false;
        }
    }

    function setFabCollapsed(collapsed) {
        try {
            if (collapsed) sessionStorage.setItem(SS_FAB_COLLAPSED, '1');
            else sessionStorage.removeItem(SS_FAB_COLLAPSED);
        } catch (e) {}
    }

    /** Default: right edge, mid-viewport height (about where cookie/checkout chrome sits). */
    function defaultFabPos(el) {
        var w = (el && el.offsetWidth) || 48;
        var h = (el && el.offsetHeight) || 48;
        return {
            left: Math.max(8, window.innerWidth - w - 16),
            top: Math.max(8, Math.round(window.innerHeight * 0.42) - Math.round(h / 2)),
        };
    }

    function clampFab(el, left, top) {
        var w = el.offsetWidth || 48;
        var h = el.offsetHeight || 48;
        var maxL = Math.max(8, window.innerWidth - w - 8);
        var maxT = Math.max(8, window.innerHeight - h - 8);
        return {
            left: Math.min(maxL, Math.max(8, left)),
            top: Math.min(maxT, Math.max(8, top)),
        };
    }

    function applyFabPos(fab, left, top) {
        var next = clampFab(fab, left, top);
        fab.style.left = next.left + 'px';
        fab.style.top = next.top + 'px';
        fab.style.right = 'auto';
        fab.style.bottom = 'auto';
        return next;
    }

    function ensureOperatorFab() {
        var existing = document.getElementById('w-i18n-inline-fab');
        if (existing) return existing;

        var fab = document.createElement('div');
        fab.id = 'w-i18n-inline-fab';
        fab.setAttribute('data-w-component', 'i18n-inline-edit-fab');
        fab.style.cssText = 'position:fixed;z-index:2147483001;width:48px;height:48px;border-radius:24px;background:#0f1111;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.28);font:13px/1.35 system-ui,sans-serif;user-select:none;touch-action:none;';

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.setAttribute('data-role', 'fab-toggle');
        toggle.title = '店面点译（拖动移动；点击展开/收起）';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.style.cssText = 'display:flex;align-items:center;justify-content:center;width:48px;height:48px;border:0;border-radius:24px;background:transparent;color:#fff;cursor:grab;font-size:22px;padding:0;';
        toggle.textContent = BOOK;
        fab.appendChild(toggle);
        document.body.appendChild(fab);

        // Operator shell panel: Theme w-popover + floating (portal/monitor) for boundary flip.
        var panel = document.createElement('div');
        panel.id = 'w-i18n-inline-fab-panel';
        panel.className = 'w-popover w-surface';
        panel.setAttribute('data-role', 'fab-panel');
        panel.setAttribute('data-w-component', 'popover');
        panel.setAttribute('data-w-popover-panel', '');
        panel.setAttribute('data-surface', 'raised');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', '店面点译');
        panel.dataset.wPlacement = 'left-end';
        panel.dataset.state = 'closed';
        panel.setAttribute('aria-hidden', 'true');
        panel.hidden = true;
        panel.style.setProperty('--w-floating-inline-size', '16rem');
        panel.style.setProperty('--w-floating-max-inline-size', 'min(20rem, calc(100dvw - 1rem))');
        panel.innerHTML = ''
            + '<div class="w-menu__header" style="text-transform:none;font-size:14px;font-weight:600;color:inherit;">' + BOOK + ' 店面点译</div>'
            + '<div style="opacity:.85;font-size:12px;margin:0 8px 10px;color:var(--weline-theme-text-muted,#565959);">点词条旁书本图标编辑。同站链接已自动带签章。</div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 8px 8px;">'
            + '<button type="button" class="w-button" data-role="fab-purge" data-size="sm" data-variant="outline">更新当前页</button>'
            + '<button type="button" class="w-button" data-role="fab-exit" data-size="sm" data-tone="danger" data-variant="outline">退出点译</button>'
            + '<button type="button" class="w-button" data-role="fab-collapse" data-size="sm" data-tone="quiet" style="margin-left:auto;">收起</button>'
            + '</div>'
            + '<div data-role="fab-msg" style="margin:0 8px 8px;font-size:12px;color:var(--weline-theme-text-muted,#565959);"></div>';
        document.body.appendChild(panel);

        var fabPortal = null;
        var fabMonitor = null;
        var fabPanelOpen = false;

        function destroyFabMonitor() {
            if (fabMonitor && typeof fabMonitor.destroy === 'function') {
                try { fabMonitor.destroy(); } catch (e) {}
            }
            fabMonitor = null;
        }

        function setFabMsg(text, isError) {
            var msg = panel.querySelector('[data-role="fab-msg"]');
            if (!msg) return;
            msg.textContent = text || '';
            msg.style.color = isError ? 'var(--weline-theme-danger,#b12704)' : 'var(--weline-theme-text-muted,#565959)';
        }

        function placeFabPanel() {
            if (!fabPanelOpen || !fabMonitor) return;
            var floating = uiFloating();
            var reference = floating && typeof floating.capture === 'function'
                ? floating.capture(toggle, null, 'element')
                : null;
            try { fabMonitor.place(reference); } catch (e) {}
        }

        function closePanel() {
            var floating = uiFloating();
            if (fabMonitor) {
                try {
                    fabMonitor.unobserve(panel);
                    fabMonitor.reset();
                } catch (e) {}
            }
            destroyFabMonitor();
            if (floating && typeof floating.clear === 'function') {
                try { floating.clear(panel); } catch (e2) {}
            }
            if (fabPortal && typeof fabPortal.restore === 'function') {
                try { fabPortal.restore(); } catch (e3) {}
            }
            panel.hidden = true;
            panel.dataset.state = 'closed';
            panel.setAttribute('aria-hidden', 'true');
            delete panel.dataset.wFloatingPositioned;
            toggle.setAttribute('aria-expanded', 'false');
            fabPanelOpen = false;
            setFabCollapsed(true);
        }

        function openPanel() {
            var floating = uiFloating();
            var ui = window.Weline && window.Weline.UI;
            if (floating && typeof floating.portal === 'function' && typeof floating.monitor === 'function') {
                if (fabPanelOpen) closePanel();
                if (!fabPortal) {
                    fabPortal = floating.portal(panel, 'i18n-inline-fab');
                }
                fabMonitor = floating.monitor(
                    toggle,
                    function () { return panel; },
                    function () { return panel.dataset.wPlacement || 'left-end'; },
                    function () { closePanel(); }
                );
                panel.dataset.wPlacement = 'left-end';
                panel.dataset.wFloatingPositioned = 'pending';
                try { fabPortal.mount(); } catch (e) {}
                panel.hidden = false;
                panel.dataset.state = 'open';
                panel.setAttribute('aria-hidden', 'false');
                toggle.setAttribute('aria-expanded', 'true');
                fabPanelOpen = true;
                setFabCollapsed(false);
                fabMonitor.observe(panel);
                placeFabPanel();
                if (ui && typeof ui.stack === 'object' && typeof ui.stack.apply === 'function') {
                    try { ui.stack.apply(panel, toggle); } catch (e3) {}
                }
                return;
            }

            // Fallback without theme floating: fixed near FAB, clamp to viewport.
            var rect = toggle.getBoundingClientRect();
            var width = 260;
            var left = Math.min(window.innerWidth - width - 8, Math.max(8, rect.left - width - 8));
            if (left < 8) left = Math.min(window.innerWidth - width - 8, rect.right + 8);
            panel.style.position = 'fixed';
            panel.style.left = left + 'px';
            panel.style.zIndex = '2147483002';
            panel.hidden = false;
            panel.dataset.state = 'open';
            panel.setAttribute('aria-hidden', 'false');
            var h = panel.getBoundingClientRect().height || 160;
            var top = Math.min(window.innerHeight - h - 8, Math.max(8, rect.top - 8));
            panel.style.top = top + 'px';
            toggle.setAttribute('aria-expanded', 'true');
            fabPanelOpen = true;
            setFabCollapsed(false);
        }

        var saved = loadFabPos();
        var initial = saved || defaultFabPos(fab);
        applyFabPos(fab, initial.left, initial.top);
        if (!saved) {
            saveFabPos(initial.left, initial.top);
        }

        var drag = { active: false, moved: false, ox: 0, oy: 0, sl: 0, st: 0 };

        function onPointerDown(ev) {
            if (ev.button != null && ev.button !== 0) return;
            drag.active = true;
            drag.moved = false;
            drag.ox = ev.clientX;
            drag.oy = ev.clientY;
            var rect = fab.getBoundingClientRect();
            drag.sl = rect.left;
            drag.st = rect.top;
            applyFabPos(fab, rect.left, rect.top);
            toggle.style.cursor = 'grabbing';
            try { toggle.setPointerCapture(ev.pointerId); } catch (e) {}
            ev.preventDefault();
        }

        function onPointerMove(ev) {
            if (!drag.active) return;
            var dx = ev.clientX - drag.ox;
            var dy = ev.clientY - drag.oy;
            if (Math.abs(dx) > 4 || Math.abs(dy) > 4) {
                drag.moved = true;
            }
            applyFabPos(fab, drag.sl + dx, drag.st + dy);
            if (fabPanelOpen) placeFabPanel();
        }

        function onPointerUp(ev) {
            if (!drag.active) return;
            drag.active = false;
            toggle.style.cursor = 'grab';
            try { toggle.releasePointerCapture(ev.pointerId); } catch (e) {}
            var rect = fab.getBoundingClientRect();
            saveFabPos(rect.left, rect.top);
            if (!drag.moved) {
                if (fabPanelOpen) closePanel();
                else openPanel();
            } else if (fabPanelOpen) {
                placeFabPanel();
            }
        }

        toggle.addEventListener('pointerdown', onPointerDown);
        toggle.addEventListener('pointermove', onPointerMove);
        toggle.addEventListener('pointerup', onPointerUp);
        toggle.addEventListener('pointercancel', onPointerUp);

        panel.querySelector('[data-role="fab-exit"]').addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            exitInlineSession();
        });
        panel.querySelector('[data-role="fab-collapse"]').addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            closePanel();
        });
        panel.querySelector('[data-role="fab-purge"]').addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var url = endpoint('purge-url');
            if (!url) return setFabMsg('缺少清缓存地址', true);
            setFabMsg('更新中…');
            postUpdatePageSse(url, {
                path: location.pathname || '/',
                locale_code: detectPageLocale(),
            }, setFabMsg);
        });

        window.addEventListener('resize', function () {
            var rect = fab.getBoundingClientRect();
            var next = applyFabPos(fab, rect.left, rect.top);
            saveFabPos(next.left, next.top);
            if (fabPanelOpen) placeFabPanel();
        });

        // Default expanded; only stay collapsed if user previously clicked 收起.
        if (isFabCollapsed()) {
            closePanel();
        } else {
            openPanel();
        }

        return fab;
    }

    function boot() {
        // Short hook name `footer` used to inject this script twice (widget + partial).
        // Keep boot idempotent even if HTML still duplicates the tag.
        if (window.__welineI18nInlineShellBooted) return;
        var shell = closestShell();
        if (!shell) return;
        window.__welineI18nInlineShellBooted = true;
        captureStampFromLocation();
        stampAllAnchors(document);
        ensureOperatorFab();
        bindPopoverActions();
        decorateAllMarkers(document);
        var mo = new MutationObserver(function (mutations) {
            stampAllAnchors(document);
            decorateAllMarkers(document);
            // Re-stamp newly inserted anchors quickly.
            mutations.forEach(function (m) {
                m.addedNodes && m.addedNodes.forEach(function (node) {
                    if (node && node.nodeType === 1) {
                        if (node.tagName === 'A') stampAnchor(node);
                        else if (node.querySelectorAll) stampAllAnchors(node);
                    }
                });
            });
        });
        mo.observe(document.documentElement, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
