/**
 * 前台事件拾取浮层：简易拾取 + 本会话操作链录制封成事件。
 * 激活：URL ?weline_pixel_picker=1&token=... 或跨页 sessionStorage/cookie 续活。
 */
(function () {
    'use strict';

    var SESSION_KEY = 'wpp_session_v1';
    var COOKIE_ON = 'weline_pixel_picker';
    var COOKIE_TOKEN = 'weline_pixel_picker_token';
    var COOKIE_VENDOR = 'weline_pixel_picker_vendor';
    var COOKIE_MAX_AGE = 28800; // 8h，与拾取 token 同量级

    function qs(name) {
        try {
            return new URLSearchParams(window.location.search).get(name) || '';
        } catch (e) {
            return '';
        }
    }

    function readPickerSession() {
        try {
            var raw = sessionStorage.getItem(SESSION_KEY);
            var parsed = raw ? JSON.parse(raw) : null;
            if (!parsed || typeof parsed !== 'object') return null;
            if (!parsed.active || !parsed.token) return null;
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function persistPickerSession(patch) {
        var cur = readPickerSession() || {};
        var next = {
            active: patch.active !== undefined ? !!patch.active : (cur.active !== false),
            token: String(patch.token || cur.token || ''),
            vendor: String(patch.vendor || cur.vendor || ''),
            website_id: String(patch.website_id != null ? patch.website_id : (cur.website_id || '')),
            mode: String(patch.mode || cur.mode || 'simple'),
            chain_recording: patch.chain_recording !== undefined ? !!patch.chain_recording : (cur.chain_recording !== false)
        };
        if (!next.token) return null;
        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify(next));
        } catch (e) {}
        try {
            var secure = location.protocol === 'https:' ? '; Secure' : '';
            var base = '; Path=/; SameSite=Lax; Max-Age=' + COOKIE_MAX_AGE + secure;
            document.cookie = COOKIE_ON + '=' + (next.active ? '1' : '0') + base;
            document.cookie = COOKIE_TOKEN + '=' + encodeURIComponent(next.token) + base;
            document.cookie = COOKIE_VENDOR + '=' + encodeURIComponent(next.vendor || '') + base;
        } catch (e2) {}
        return next;
    }

    function clearPickerSession() {
        try { sessionStorage.removeItem(SESSION_KEY); } catch (e) {}
        try {
            var secure = location.protocol === 'https:' ? '; Secure' : '';
            var base = '; Path=/; SameSite=Lax; Max-Age=0' + secure;
            document.cookie = COOKIE_ON + '=0' + base;
            document.cookie = COOKIE_TOKEN + '=' + base;
            document.cookie = COOKIE_VENDOR + '=' + base;
        } catch (e2) {}
    }

    function resolvePickerAuth() {
        var urlOn = qs('weline_pixel_picker') === '1';
        var urlToken = qs('token');
        var stored = readPickerSession();
        if (urlOn && urlToken) {
            return persistPickerSession({
                active: true,
                token: urlToken,
                vendor: qs('vendor') || (stored && stored.vendor) || '',
                website_id: qs('website_id') || (stored && stored.website_id) || '',
                mode: (stored && stored.mode) || 'simple',
                chain_recording: stored ? stored.chain_recording !== false : true
            });
        }
        if (stored && stored.active && stored.token) {
            // 续写 cookie，保证下一跳 PHP 仍可注入
            return persistPickerSession(stored);
        }
        return null;
    }

    var auth = resolvePickerAuth();
    if (!auth || !auth.token) {
        return;
    }
    var token = auth.token;

    var observeUrl = '/visitor/analytics/event-picker/observe';
    var recordUrl = '/visitor/analytics/event-picker/record';
    var mappedUrl = '/visitor/analytics/event-picker/mapped';
    var storageKey = 'wpp_chain_' + token.slice(0, 12);
    var items = [];
    var highlightEls = [];
    var mode = auth.mode === 'chain' || auth.mode === 'mapped' ? auth.mode : 'simple';
    var chainRecording = auth.chain_recording !== false;
    var chainSteps = loadChainSteps();
    /** @type {Object.<string, string>} weline_event -> third_party */
    var knownMap = {};
    /** @type {Object.<string, boolean>} 字典内系统事件（只看不录） */
    var systemEvents = {};
    /** @type {Array.<{weline_event:string,third_party_event:string,custom:boolean,source:string}>} */
    var mappedEvents = [];
    var vendorCode = '';
    /** 选值模式：点页面元素取值，不触发其它事件记录（类 FB） */
    var valuePick = { active: false, target: 'custom', itemIdx: -1 };
    /** @type {{display:string,value:string,selector:string,tag:string}|null} */
    var pendingValue = null;
    var hoverPickEl = null;

    function rewriteSameOriginNav(url) {
        try {
            var u = new URL(url, location.href);
            if (u.origin !== location.origin) return url;
            if (/^\/visitor\/analytics\/event-picker\//.test(u.pathname)) return url;
            u.searchParams.set('weline_pixel_picker', '1');
            u.searchParams.set('token', token);
            if (vendorCode) u.searchParams.set('vendor', vendorCode);
            if (auth.website_id) u.searchParams.set('website_id', String(auth.website_id));
            return u.pathname + u.search + u.hash;
        } catch (e) {
            return url;
        }
    }

    function installCrossPageNavGuards() {
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) return;
            var rootEl = document.getElementById('wpp-root');
            if (rootEl && rootEl.contains(a)) return;
            if (a.target && a.target !== '' && a.target !== '_self') return;
            if (a.hasAttribute('download')) return;
            var href = a.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;
            var next = rewriteSameOriginNav(href);
            if (next !== href) a.setAttribute('href', next);
        }, true);
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            var method = String(form.getAttribute('method') || 'get').toLowerCase();
            if (method !== 'get') return;
            try {
                var action = form.getAttribute('action') || location.href;
                form.setAttribute('action', rewriteSameOriginNav(action));
            } catch (err) {}
        }, true);
    }

    function refreshKnownMap() {
        knownMap = {};
        systemEvents = {};
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var dict = cfg.eventDictionary || {};
        var evs = Array.isArray(dict.events) ? dict.events : [];
        evs.forEach(function (row) {
            if (!row || typeof row !== 'object') return;
            var nk = normalizeName(row.weline_event || '');
            if (nk) systemEvents[nk] = true;
        });
        var vendors = Array.isArray(cfg.vendors) ? cfg.vendors : [];
        vendors.forEach(function (v) {
            if (!v || typeof v !== 'object') return;
            var code = normalizeName(v.code || '');
            if (vendorCode && code && code !== vendorCode) return;
            var map = v.event_map || {};
            Object.keys(map).forEach(function (k) {
                var nk = normalizeName(k);
                if (!nk) return;
                knownMap[nk] = String(map[k] == null ? nk : map[k]);
            });
        });
    }

    function isInVendorMap(name) {
        var n = normalizeName(name);
        return !!(n && Object.prototype.hasOwnProperty.call(knownMap, n));
    }

    function isSystemDict(name) {
        var n = normalizeName(name);
        return !!(n && systemEvents[n]);
    }

    /** 系统字典或后台已搭接：可见，无需重新录入 */
    function isSystemOwned(name) {
        return isSystemDict(name) || isInVendorMap(name);
    }

    function isAlreadyMapped(name) {
        return isInVendorMap(name);
    }

    function markMapped(name, third) {
        var n = normalizeName(name);
        if (!n) return;
        var t = normalizeName(third || name) || n;
        knownMap[n] = t;
        var found = false;
        mappedEvents = mappedEvents.map(function (row) {
            if (normalizeName(row.weline_event) !== n) return row;
            found = true;
            return {
                weline_event: n,
                third_party_event: t,
                custom: !isSystemDict(n),
                source: isSystemDict(n) ? 'system' : 'custom'
            };
        });
        if (!found) {
            mappedEvents.unshift({
                weline_event: n,
                third_party_event: t,
                custom: !isSystemDict(n),
                source: isSystemDict(n) ? 'system' : 'custom'
            });
        }
        updateMappedCount();
    }

    function updateMappedCount() {
        var el = document.getElementById('wpp-mapped-count');
        if (el) el.textContent = String(mappedEvents.length);
    }

    function confirmDuplicateRecord(name, third) {
        var n = normalizeName(name);
        var existing = knownMap[n] || '';
        var msg = '「' + n + '」已录入（当前三方：' + (existing || n) + '）。继续将覆盖为：' + (normalizeName(third) || n) + '？';
        // 禁止原生 confirm：走主题弹窗
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
            && typeof window.Weline.UI.dialog.confirm === 'function') {
            return Promise.resolve(window.Weline.UI.dialog.confirm(msg, { tone: 'warning', dangerous: true }));
        }
        return Promise.resolve(false);
    }

    function loadMappedFromServer() {
        return postForm(mappedUrl, { token: token }).then(function (data) {
            if (!(data && data.ok && Array.isArray(data.events))) return data;
            mappedEvents = data.events.map(function (row) {
                var n = normalizeName(row && row.weline_event);
                var t = normalizeName((row && row.third_party_event) || n) || n;
                if (n) knownMap[n] = t;
                return {
                    weline_event: n,
                    third_party_event: t,
                    custom: !!(row && row.custom) || !isSystemDict(n),
                    source: (row && row.source) || (isSystemDict(n) ? 'system' : 'custom')
                };
            }).filter(function (row) { return !!row.weline_event; });
            updateMappedCount();
            if (mode === 'mapped') renderMapped();
            if (mode === 'simple') renderDiscover();
            return data;
        }).catch(function () { return null; });
    }

    function loadChainSteps() {
        try {
            var raw = sessionStorage.getItem(storageKey);
            var parsed = raw ? JSON.parse(raw) : null;
            return Array.isArray(parsed) ? parsed.slice(0, 40) : [];
        } catch (e) {
            return [];
        }
    }

    function saveChainSteps() {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(chainSteps.slice(0, 40)));
        } catch (e) {}
    }

    function postForm(url, fields) {
        var body = new URLSearchParams();
        Object.keys(fields).forEach(function (k) {
            body.set(k, String(fields[k] == null ? '' : fields[k]));
        });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            return r.text().then(function (text) {
                var data = null;
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (e) {
                    data = { ok: false, error: 'invalid_json', message: 'HTTP ' + r.status };
                }
                if (!data || typeof data !== 'object') data = {};
                // 统一 Analytics {ok} / REST {success} / 维护页 {code:maintenance}
                if (data.ok === undefined) {
                    if (data.success === true) data.ok = true;
                    else if (data.success === false || data.code === 'maintenance' || r.status >= 400) data.ok = false;
                }
                if (!data.ok && !data.error) {
                    data.error = data.code || data.message || ('http_' + r.status);
                }
                data.__http = r.status;
                return data;
            });
        });
    }

    function recordErrorText(data) {
        var err = String((data && (data.error || data.message || data.code)) || '');
        if (err === 'invalid_token') return '令牌失效，请回后台重新启动拾取';
        if (err === 'vendor_not_found') return '供应商不存在';
        if (err === 'weline_event_required') return '事件名无效';
        if (err === 'duplicate' || (data && data.duplicate)) return '已录入（重复）';
        if (err === 'maintenance' || (data && data.code === 'maintenance')) return '站点维护中，请稍后重试';
        if (err === 'invalid_json') return '接口无响应(HTTP ' + ((data && data.__http) || '?') + ')';
        if (err.length > 40) return '失败：' + err.slice(0, 40);
        return err ? ('失败：' + err) : '失败';
    }

    function normalizeName(name) {
        return String(name || '').toLowerCase().trim().replace(/-/g, '_').replace(/[^a-z0-9_]/g, '').slice(0, 64);
    }

    vendorCode = normalizeName(auth.vendor || qs('vendor') || '');
    refreshKnownMap();
    try {
        window.addEventListener('weline:visitor-tracking-config', function () {
            refreshKnownMap();
            mappedEvents = Object.keys(knownMap).map(function (n) {
                return {
                    weline_event: n,
                    third_party_event: knownMap[n] || n,
                    custom: !isSystemDict(n),
                    source: isSystemDict(n) ? 'system' : 'custom'
                };
            });
            updateMappedCount();
            renderDiscover();
            if (mode === 'mapped') renderMapped();
        });
    } catch (e) {}

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }

    function selectorHint(el) {
        if (!el || !el.tagName) return '';
        var tag = String(el.tagName).toLowerCase();
        var id = el.id ? ('#' + el.id) : '';
        var cls = '';
        if (typeof el.className === 'string' && el.className.trim()) {
            cls = '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
        }
        return (tag + id + cls).slice(0, 80);
    }

    function extractElementValue(el) {
        if (!el || el.nodeType !== 1) return null;
        var tag = String(el.tagName).toLowerCase();
        var type = String(el.getAttribute('type') || '').toLowerCase();
        if (type === 'password') return null;
        var raw = '';
        if (tag === 'input' || tag === 'select' || tag === 'textarea') {
            if (type === 'checkbox' || type === 'radio') {
                raw = el.checked ? (el.value || '1') : '';
            } else {
                raw = el.value || '';
            }
        } else {
            raw = el.getAttribute('data-value')
                || el.getAttribute('data-price')
                || el.getAttribute('content')
                || '';
            if (!raw) {
                var priceEl = null;
                try {
                    if (el.matches && el.matches('[itemprop="price"], .price, [data-price], [data-value]')) {
                        priceEl = el;
                    } else if (el.querySelector) {
                        priceEl = el.querySelector('[itemprop="price"], .price, [data-price], [data-value]');
                    }
                } catch (e) {}
                if (priceEl) {
                    raw = priceEl.getAttribute('content')
                        || priceEl.getAttribute('data-price')
                        || priceEl.getAttribute('data-value')
                        || String(priceEl.innerText || priceEl.textContent || '');
                } else {
                    raw = String(el.innerText || el.textContent || '');
                }
            }
        }
        raw = String(raw || '').replace(/\s+/g, ' ').trim().slice(0, 200);
        if (!raw) return null;
        var numMatch = raw.replace(/,/g, '').match(/-?\d+(?:\.\d+)?/);
        return {
            display: raw,
            value: numMatch ? numMatch[0] : raw,
            selector: selectorHint(el),
            tag: tag
        };
    }

    function clearValueHover() {
        if (hoverPickEl && hoverPickEl.style) {
            hoverPickEl.style.outline = '';
            hoverPickEl.style.outlineOffset = '';
        }
        hoverPickEl = null;
    }

    function setValuePickBanner(on) {
        var el = document.getElementById('wpp-value-pick-banner');
        if (!el) return;
        el.hidden = !on;
        el.setAttribute('data-active', on ? '1' : '0');
        if (on) {
            el.innerHTML = '<strong>选值模式</strong>：点击页面元素取值，不会记录其它事件。' +
                '<button type="button" class="wpp-btn" id="wpp-value-pick-cancel" data-testid="wpp-value-pick-cancel">取消</button>';
            var c = document.getElementById('wpp-value-pick-cancel');
            if (c) c.addEventListener('click', function () { exitValuePick(); });
        }
    }

    function renderValuePreview() {
        var box = document.getElementById('wpp-value-preview');
        if (!box) return;
        if (!pendingValue) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        box.hidden = false;
        box.innerHTML = '<span class="wpp-value-preview__label">已选值</span>' +
            '<code class="wpp-value-preview__val">' + esc(pendingValue.display) + '</code>' +
            '<span class="wpp-value-preview__sel">' + esc(pendingValue.selector) + '</span>' +
            '<button type="button" class="wpp-btn" id="wpp-value-clear" data-testid="wpp-value-clear">清除</button>';
        var clearBtn = document.getElementById('wpp-value-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                pendingValue = null;
                if (valuePick.target === 'item' && valuePick.itemIdx >= 0 && items[valuePick.itemIdx]) {
                    items[valuePick.itemIdx].picked_value = null;
                    items[valuePick.itemIdx].picked_selector = '';
                }
                renderValuePreview();
                if (valuePick.target === 'item') renderDiscover();
            });
        }
    }

    function exitValuePick() {
        valuePick.active = false;
        valuePick.target = 'custom';
        valuePick.itemIdx = -1;
        clearValueHover();
        setValuePickBanner(false);
        try {
            document.documentElement.classList.remove('wpp-value-picking');
            document.body && document.body.classList.remove('wpp-value-picking');
        } catch (e) {}
        document.querySelectorAll('[data-wpp-pick-value]').forEach(function (btn) {
            btn.setAttribute('data-active', '0');
            if (btn.id === 'wpp-custom-pick-value') btn.textContent = '选择值';
            else if ((btn.textContent || '').indexOf('选值中') >= 0) btn.textContent = '选择值';
        });
    }

    function enterValuePick(target, itemIdx) {
        target = target === 'item' ? 'item' : 'custom';
        valuePick.active = true;
        valuePick.target = target;
        valuePick.itemIdx = typeof itemIdx === 'number' ? itemIdx : -1;
        setValuePickBanner(true);
        try {
            document.documentElement.classList.add('wpp-value-picking');
            document.body && document.body.classList.add('wpp-value-picking');
        } catch (e) {}
        document.querySelectorAll('[data-wpp-pick-value]').forEach(function (btn) {
            var isMine = target === 'custom'
                ? btn.id === 'wpp-custom-pick-value'
                : (btn.getAttribute('data-idx') === String(itemIdx));
            btn.setAttribute('data-active', isMine ? '1' : '0');
            if (isMine) btn.textContent = '选值中…';
            else if (btn.id === 'wpp-custom-pick-value') btn.textContent = '选择值';
            else btn.textContent = '选择值';
        });
    }

    function applyPickedValue(picked) {
        if (!picked) return;
        pendingValue = picked;
        if (valuePick.target === 'item' && valuePick.itemIdx >= 0 && items[valuePick.itemIdx]) {
            items[valuePick.itemIdx].picked_value = picked;
            items[valuePick.itemIdx].picked_selector = picked.selector;
        }
        exitValuePick();
        renderValuePreview();
        renderDiscover();
    }

    function attachValueFields(fields, picked) {
        fields = fields || {};
        if (!picked) return fields;
        fields.value = picked.value;
        fields.params_json = JSON.stringify({
            value: picked.value,
            content: picked.display,
            selector: picked.selector,
            source: 'element_pick'
        });
        if (!fields.summary || fields.summary === 'named_custom') {
            fields.summary = 'value=' + String(picked.display).slice(0, 80);
        }
        return fields;
    }

    function pushItem(ev) {
        if (valuePick.active) return;
        var name = normalizeName(ev.weline_event || ev.name || '');
        if (!name) return;
        // 同名只保留一条，避免重复「录入搭接」
        items = items.filter(function (it) {
            return normalizeName(it.weline_event) !== name;
        });
        var third = normalizeName(ev.third_party_event || knownMap[name] || name) || name;
        var systemOwned = isSystemOwned(name) || !!ev.already_mapped;
        items.unshift({
            weline_event: name,
            third_party_event: third,
            summary: String(ev.summary || '').slice(0, 160),
            path: String(ev.path || (location && location.pathname) || ''),
            at: new Date().toISOString(),
            kind: ev.kind || 'single',
            chain: ev.chain || null,
            already_mapped: systemOwned,
            custom: !systemOwned,
            source: ev.source || (systemOwned ? 'system' : 'track'),
            fired: !!ev.fired
        });
        items = items.slice(0, 40);
        renderDiscover();
        if (systemOwned) {
            // 系统已有 / 已搭接：只发现累计，不催录入
            var observeFields = {
                token: token,
                weline_event: name,
                third_party_event: third,
                summary: items[0].summary || 'system_visible',
                path: items[0].path,
                kind: items[0].kind || 'single'
            };
            if (items[0].chain) {
                observeFields.chain_json = JSON.stringify(items[0].chain);
            }
            postForm(observeUrl, observeFields).catch(function () {});
            return;
        }
        var fields = {
            token: token,
            weline_event: name,
            third_party_event: items[0].third_party_event,
            summary: items[0].summary,
            path: items[0].path,
            kind: items[0].kind || 'single'
        };
        if (items[0].chain) {
            fields.chain_json = JSON.stringify(items[0].chain);
        }
        if (ev.publish_chain === '1' || ev.publish_chain === true) {
            fields.publish_chain = '1';
        }
        postForm(observeUrl, fields).catch(function () {});
    }

    function appendChainStep(step) {
        if (!chainRecording || mode !== 'chain') return;
        var entry = {
            t: new Date().toISOString(),
            type: String(step.type || 'action'),
            label: String(step.label || '').slice(0, 80),
            path: String(step.path || location.pathname || ''),
            selector: String(step.selector || '').slice(0, 80),
            value: step.value == null ? '' : String(step.value).slice(0, 60),
            event: normalizeName(step.event || '')
        };
        // 去抖：同类型同 selector 连续重复忽略
        var last = chainSteps[chainSteps.length - 1];
        if (last && last.type === entry.type && last.selector === entry.selector && last.label === entry.label && entry.type !== 'track') {
            return;
        }
        chainSteps.push(entry);
        if (chainSteps.length > 40) {
            chainSteps = chainSteps.slice(-40);
        }
        saveChainSteps();
        renderChain();
    }

    function clearHighlights() {
        highlightEls.forEach(function (el) {
            try {
                el.style.outline = el.getAttribute('data-wpp-outline') || '';
                el.removeAttribute('data-wpp-outline');
            } catch (e) {}
        });
        highlightEls = [];
    }

    function highlightMarkers() {
        clearHighlights();
        document.querySelectorAll('[class*="weline-pixel::"], [data-pixel-event], [data-cta-event], [data-visitor-event]').forEach(function (el) {
            el.setAttribute('data-wpp-outline', el.style.outline || '');
            el.style.outline = '2px solid var(--weline-color-primary, #2f6fed)';
            el.style.outlineOffset = '2px';
            highlightEls.push(el);
        });
    }

    function eventNameFromEl(el) {
        if (!el) return '';
        var attr = el.getAttribute('data-pixel-event') || el.getAttribute('data-cta-event') || el.getAttribute('data-visitor-event') || '';
        if (attr) return normalizeName(attr);
        var className = typeof el.className === 'string' ? el.className : '';
        var found = '';
        className.split(/\s+/).forEach(function (tok) {
            if (tok.indexOf('weline-pixel::') === 0) {
                found = normalizeName(tok.replace('weline-pixel::', ''));
            }
        });
        return found;
    }

    /** 未打标点击：invent 可改名的草稿事件 id（中文文案无法作 slug）。 */
    function suggestCustomName(el, label) {
        var id = el && el.id ? normalizeName(el.id) : '';
        var nameAttr = el ? normalizeName(el.getAttribute('name') || el.getAttribute('aria-label') || '') : '';
        var hrefPart = '';
        try {
            if (el && el.closest) {
                var a = el.closest('a[href]');
                if (a && a.pathname) {
                    var seg = String(a.pathname).split('/').filter(Boolean).pop() || '';
                    hrefPart = normalizeName(seg);
                }
            }
        } catch (_e) {}
        var tag = el && el.tagName ? String(el.tagName).toLowerCase() : 'el';
        var base = id || nameAttr || hrefPart || ('click_' + tag);
        var seed = String(label || '') + '|' + selectorHint(el);
        var h = 0;
        for (var i = 0; i < seed.length; i += 1) {
            h = ((h << 5) - h) + seed.charCodeAt(i);
            h |= 0;
        }
        var suffix = Math.abs(h).toString(36).slice(0, 5) || 'x';
        var name = normalizeName(base + '_' + suffix) || ('click_' + suffix);
        if (name.length < 4) name = 'click_' + suffix;
        return name.slice(0, 64);
    }

    function flashCaptureNotice(name, label) {
        var el = document.getElementById('wpp-capture-flash');
        if (!el) return;
        el.hidden = false;
        el.setAttribute('data-active', '1');
        el.innerHTML = '<strong>已捕捉</strong> <code>' + esc(name) + '</code>' +
            (label ? (' · ' + esc(String(label).slice(0, 40))) : '') +
            ' — 改名后点「录入自定义」进池';
        if (flashCaptureNotice._timer) clearTimeout(flashCaptureNotice._timer);
        flashCaptureNotice._timer = setTimeout(function () {
            el.hidden = true;
            el.setAttribute('data-active', '0');
        }, 3200);
    }

    function pulseCaptureTarget(el) {
        if (!el || !el.style) return;
        var prev = el.style.outline;
        var prevOff = el.style.outlineOffset;
        try {
            el.style.outline = '2px solid var(--weline-color-primary, var(--color-primary, #2f6fed))';
            el.style.outlineOffset = '3px';
        } catch (_e) {}
        setTimeout(function () {
            try {
                el.style.outline = prev;
                el.style.outlineOffset = prevOff;
            } catch (_e2) {}
        }, 900);
    }

    function resolveClickTarget(el) {
        if (!el || el.nodeType !== 1) return null;
        var tag = String(el.tagName || '').toLowerCase();
        if (tag === 'html' || tag === 'body' || tag === 'script' || tag === 'style' || tag === 'link') return null;
        if (el.closest) {
            var interactive = el.closest(
                'a, button, [role="button"], input, select, textarea, summary, label,' +
                ' [data-pixel-event], [data-cta-event], [data-visitor-event], [class*="weline-pixel::"],' +
                ' [onclick], [tabindex]:not([tabindex="-1"])'
            );
            if (interactive) return interactive;
        }
        return el;
    }

    function setMode(next) {
        mode = next === 'chain' ? 'chain' : (next === 'mapped' ? 'mapped' : 'simple');
        persistPickerSession({ mode: mode, chain_recording: chainRecording, token: token, vendor: vendorCode, website_id: auth.website_id, active: true });
        var root = document.getElementById('wpp-root');
        if (root) root.setAttribute('data-mode', mode);
        document.querySelectorAll('[data-wpp-mode]').forEach(function (btn) {
            btn.setAttribute('data-active', btn.getAttribute('data-wpp-mode') === mode ? '1' : '0');
        });
        var simplePane = document.getElementById('wpp-pane-simple');
        var chainPane = document.getElementById('wpp-pane-chain');
        var mappedPane = document.getElementById('wpp-pane-mapped');
        if (simplePane) simplePane.hidden = mode !== 'simple';
        if (chainPane) chainPane.hidden = mode !== 'chain';
        if (mappedPane) mappedPane.hidden = mode !== 'mapped';
        if (mode === 'chain') {
            var last = chainSteps[chainSteps.length - 1];
            if (!last || last.type !== 'page' || last.path !== location.pathname) {
                appendChainStep({
                    type: 'page',
                    label: document.title || location.pathname,
                    path: location.pathname
                });
            }
            renderChain();
        } else if (mode === 'mapped') {
            loadMappedFromServer().then(function () { renderMapped(); });
        } else {
            renderDiscover();
        }
    }

    function renderMapped() {
        var list = document.getElementById('wpp-mapped-list');
        updateMappedCount();
        if (!list) return;
        if (!mappedEvents.length) {
            list.innerHTML = '<li class="wpp-empty" data-testid="wpp-mapped-empty">暂无已录入事件。在「自动发现」输入名字点「录入自定义」，或在「高级事件链」仅录入/发布后，会出现在这里。</li>';
            return;
        }
        list.innerHTML = mappedEvents.map(function (it) {
            var custom = !!it.custom || !isSystemDict(it.weline_event);
            var badge = custom
                ? '<span class="wpp-badge wpp-badge--ok">已录入</span>'
                : '<span class="wpp-badge">系统</span>';
            return '<li class="wpp-item wpp-item--mapped" data-testid="wpp-mapped-row">' +
                '<div class="wpp-item__top"><span class="wpp-item__name">' + esc(it.weline_event) + '</span>' + badge + '</div>' +
                '<div class="wpp-item__meta">三方：' + esc(it.third_party_event || it.weline_event) + '</div>' +
                '</li>';
        }).join('');
    }

    function applyRecordRevisionAfterPersist(data) {
        if (!data || !data.ok) return;
        var rev = Number(data.configRevision || data.config_revision || 0) || 0;
        if (rev <= 0) return;
        try {
            if (window.WelinePixelSandbox && typeof window.WelinePixelSandbox.noteConfigRevision === 'function') {
                window.WelinePixelSandbox.noteConfigRevision(rev);
                return;
            }
        } catch (_e0) {}
        try {
            if (typeof window.__noteServerConfigRevision === 'function') {
                window.__noteServerConfigRevision(rev);
            }
        } catch (_e1) {}
    }

    function submitRecord(fields, btn) {
        if (btn) {
            btn.disabled = true;
            btn.textContent = '录入中…';
        }
        return postForm(recordUrl, fields).then(function (data) {
            if (data && data.ok) {
                markMapped(fields.weline_event, fields.third_party_event);
                applyRecordRevisionAfterPersist(data);
                return data;
            }
            if (data && (data.duplicate || data.error === 'duplicate')) {
                var existing = data.existing_third_party_event || knownMap[normalizeName(fields.weline_event)] || fields.weline_event;
                return confirmDuplicateRecord(fields.weline_event, fields.third_party_event || existing).then(function (ok) {
                    if (ok) {
                        fields.force = '1';
                        return postForm(recordUrl, fields).then(function (data2) {
                            if (data2 && data2.ok) {
                                markMapped(fields.weline_event, fields.third_party_event);
                                applyRecordRevisionAfterPersist(data2);
                            }
                            return data2;
                        });
                    }
                    if (btn) {
                        btn.textContent = '已取消覆盖';
                        btn.disabled = false;
                    }
                    return data;
                });
            }
            return data;
        }).finally(function () {
            if (btn && btn.disabled) {
                /* success path re-renders; leave as-is */
            }
        });
    }

    function renderDiscover() {
        var list = document.getElementById('wpp-list');
        if (!list) return;
        if (!items.length) {
            list.innerHTML = '<li class="wpp-empty" data-testid="wpp-discover-empty">点击页面任意按钮、链接或可点区域，会出现候选事件。改名后点「录入自定义」才进池；系统已有事件仅对照观察。高级事件链用于跨页步骤录制。</li>';
            return;
        }
        list.innerHTML = items.map(function (it, idx) {
            var badge = it.kind === 'chain' ? '<span class="wpp-badge">链</span>' : '';
            var mapped = isSystemOwned(it.weline_event) || it.already_mapped;
            var thirdShow = knownMap[normalizeName(it.weline_event)] || it.third_party_event || it.weline_event;
            if (mapped) {
                var isSys = isSystemDict(it.weline_event);
                var statusBadge = isSys
                    ? '<span class="wpp-badge wpp-badge--ok">系统已有</span>'
                    : '<span class="wpp-badge wpp-badge--ok">已录入</span>';
                var doneText = isSys ? '已默认无需重新录入' : '已录入，无需重复录入';
                return '<li class="wpp-item wpp-item--mapped" data-idx="' + idx + '" data-mapped="1">' +
                    '<div class="wpp-item__top"><span class="wpp-item__name">' + esc(it.weline_event) + '</span>' +
                    statusBadge + badge + '</div>' +
                    '<div class="wpp-item__meta">' + esc(it.summary || it.path) + '</div>' +
                    '<div class="wpp-item__meta">三方：' + esc(thirdShow) + '</div>' +
                    '<div class="wpp-item__done" data-testid="wpp-already-mapped">' + doneText + '</div>' +
                    '</li>';
            }
            var sourceBadge = it.fired || it.source === 'track'
                ? '已自动触发'
                : (it.source === 'click' || it.source === 'marker' ? '点击捕捉' : '自定义');
            return '<li class="wpp-item wpp-item--custom" data-idx="' + idx + '" data-source="' + esc(it.source || '') + '">' +
                '<div class="wpp-item__top"><span class="wpp-item__name">' + esc(it.weline_event) + '</span>' +
                '<span class="wpp-badge" data-testid="wpp-badge-source">' + sourceBadge + '</span>' + badge + '</div>' +
                '<div class="wpp-item__meta">' + esc(it.summary || it.path) + '</div>' +
                (it.picked_value
                    ? ('<div class="wpp-item__meta" data-testid="wpp-item-value">值：' + esc(it.picked_value.display) +
                        (it.picked_value.selector ? (' · ' + esc(it.picked_value.selector)) : '') + '</div>')
                    : '') +
                '<label class="wpp-item__third">我方 <input data-wpp-name value="' + esc(it.weline_event) + '"></label>' +
                '<label class="wpp-item__third">三方 <input data-wpp-third value="' + esc(it.third_party_event) + '"></label>' +
                '<div class="wpp-item__actions">' +
                '<button type="button" class="wpp-btn" data-wpp-pick-value data-idx="' + idx + '" data-testid="wpp-item-pick-value">选择值</button>' +
                '<button type="button" class="wpp-btn wpp-btn--primary" data-wpp-record data-idx="' + idx + '">录入自定义</button>' +
                '</div>' +
                '</li>';
        }).join('');
        list.querySelectorAll('[data-wpp-pick-value]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                if (valuePick.active && valuePick.target === 'item' && valuePick.itemIdx === idx) {
                    exitValuePick();
                    return;
                }
                enterValuePick('item', idx);
            });
        });
        list.querySelectorAll('[data-wpp-record]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                var it = items[idx];
                if (!it) return;
                var nameInput = btn.parentNode.parentNode.querySelector('[data-wpp-name]') || btn.parentNode.querySelector('[data-wpp-name]');
                var input = btn.parentNode.parentNode.querySelector('[data-wpp-third]') || btn.parentNode.querySelector('[data-wpp-third]');
                var newName = normalizeName(nameInput ? nameInput.value : it.weline_event) || it.weline_event;
                var third = input ? input.value : it.third_party_event;
                if (isSystemDict(it.weline_event) || isSystemDict(newName)) {
                    btn.textContent = '系统事件无需录入';
                    return;
                }
                var fields = {
                    token: token,
                    weline_event: newName,
                    third_party_event: third,
                    summary: it.summary,
                    path: it.path,
                    kind: it.kind || 'single'
                };
                if (it.chain) fields.chain_json = JSON.stringify(it.chain);
                attachValueFields(fields, it.picked_value || pendingValue);
                var proceed = Promise.resolve(true);
                if (isAlreadyMapped(newName)) {
                    proceed = confirmDuplicateRecord(newName, third).then(function (ok) {
                        if (!ok) {
                            btn.textContent = '已取消覆盖';
                            return false;
                        }
                        fields.force = '1';
                        return true;
                    });
                }
                proceed.then(function (ok) {
                    if (!ok) return;
                    return submitRecord(fields, btn).then(function (data) {
                        if (data && data.ok) {
                            it.weline_event = newName;
                            it.already_mapped = true;
                            it.custom = false;
                            it.third_party_event = normalizeName(third) || newName;
                            pendingValue = null;
                            renderValuePreview();
                            renderDiscover();
                        } else if (!(data && data.duplicate)) {
                            btn.textContent = recordErrorText(data);
                            btn.title = String((data && (data.error || data.message)) || '');
                            btn.disabled = false;
                        }
                    }).catch(function (e) {
                        btn.textContent = '失败：网络异常';
                        btn.title = String(e && e.message ? e.message : e);
                        btn.disabled = false;
                    });
                });
            });
        });
    }

    function renderChain() {
        var list = document.getElementById('wpp-chain-list');
        var count = document.getElementById('wpp-chain-count');
        if (count) count.textContent = String(chainSteps.length);
        if (!list) return;
        if (!chainSteps.length) {
            list.innerHTML = '<li class="wpp-empty">开始浏览与点击，步骤会按时间累积到本会话</li>';
            return;
        }
        list.innerHTML = chainSteps.map(function (st, i) {
            var title = st.event || st.label || st.type;
            var meta = [st.type, st.path, st.selector].filter(Boolean).join(' · ');
            return '<li class="wpp-step">' +
                '<span class="wpp-step__n">' + (i + 1) + '</span>' +
                '<div class="wpp-step__body">' +
                '<div class="wpp-step__title">' + esc(title) + '</div>' +
                '<div class="wpp-step__meta">' + esc(meta) + '</div>' +
                '</div></li>';
        }).join('');
    }

    function sealChain(publish) {
        var nameInput = document.getElementById('wpp-chain-name');
        var thirdInput = document.getElementById('wpp-chain-third');
        var btn = document.getElementById(publish ? 'wpp-chain-publish' : 'wpp-chain-seal');
        var name = normalizeName((nameInput && nameInput.value) || '');
        if (!name) {
            if (nameInput) nameInput.focus();
            return;
        }
        if (isSystemDict(name)) {
            if (btn) {
                btn.textContent = '系统事件名不可作闭环';
                btn.disabled = false;
            }
            return;
        }
        if (!chainSteps.length) return;
        var third = normalizeName((thirdInput && thirdInput.value) || name) || name;
        var chain = {
            version: 1,
            sealed_at: new Date().toISOString(),
            path: location.pathname,
            steps: chainSteps.slice()
        };
        var summary = '操作链 ' + chainSteps.length + ' 步：' + chainSteps.map(function (s) {
            return s.event || s.label || s.type;
        }).slice(0, 6).join(' → ');
        var fields = {
            token: token,
            weline_event: name,
            third_party_event: third,
            summary: summary,
            path: location.pathname,
            kind: 'chain',
            chain_json: JSON.stringify(chain)
        };
        if (publish) {
            fields.publish_chain = '1';
        }
        var proceed = Promise.resolve(true);
        if (isAlreadyMapped(name)) {
            proceed = confirmDuplicateRecord(name, third).then(function (ok) {
                if (!ok) {
                    if (btn) {
                        btn.textContent = publish ? '发布事件链' : '仅录入';
                        btn.disabled = false;
                    }
                    return false;
                }
                fields.force = '1';
                return true;
            });
        }
        proceed.then(function (ok) {
            if (!ok) return;
            // 自定义池门禁：封链/发布必须走 record（非 observe）
            return submitRecord(fields, btn).then(function (data) {
                if (data && data.ok) {
                    chainSteps = [];
                    saveChainSteps();
                    renderChain();
                    if (nameInput) nameInput.value = '';
                    if (thirdInput) thirdInput.value = '';
                    if (btn) {
                        btn.textContent = publish ? '已发布' : '已录入';
                        btn.disabled = false;
                    }
                    setMode('mapped');
                    loadMappedFromServer().then(function () { renderMapped(); });
                } else if (!(data && data.duplicate)) {
                    if (btn) {
                        btn.textContent = recordErrorText(data);
                        btn.title = String((data && (data.error || data.message)) || '');
                        btn.disabled = false;
                    }
                }
            }).catch(function (e) {
                if (btn) {
                    btn.textContent = '失败：网络异常';
                    btn.title = String(e && e.message ? e.message : e);
                    btn.disabled = false;
                }
            });
        });
    }

    function mount() {
        if (document.getElementById('wpp-root')) return;
        var root = document.createElement('div');
        root.id = 'wpp-root';
        root.setAttribute('data-testid', 'weline-pixel-picker');
        root.setAttribute('data-mode', 'simple');
        root.setAttribute('data-wpp-build', '1.1.12-record-bump-rev');
        root.setAttribute('data-wpp-cross-page', '1');
        root.innerHTML = '' +
            '<div class="wpp-panel">' +
            '<header class="wpp-head">' +
            '<div class="wpp-brand"><span class="wpp-dot"></span><strong>事件拾取</strong></div>' +
            '<button type="button" class="wpp-icon-btn" id="wpp-close" aria-label="关闭">×</button>' +
            '</header>' +
            '<div class="wpp-value-banner" id="wpp-value-pick-banner" hidden data-active="0" data-testid="wpp-value-pick-banner">' +
            '<strong>选值模式</strong>：点击页面元素取值，不会记录其它事件。' +
            '<button type="button" class="wpp-btn" id="wpp-value-pick-cancel" data-testid="wpp-value-pick-cancel">取消</button>' +
            '</div>' +
            '<div class="wpp-capture-flash" id="wpp-capture-flash" hidden data-active="0" data-testid="wpp-capture-flash"></div>' +
            '<div class="wpp-modes" role="tablist" aria-label="拾取模式">' +
            '<button type="button" data-wpp-mode="simple" data-active="1">自动发现</button>' +
            '<button type="button" data-wpp-mode="mapped" data-active="0" data-testid="wpp-mode-mapped">已录入 <span id="wpp-mapped-count">0</span></button>' +
            '<button type="button" data-wpp-mode="chain" data-active="0" data-testid="wpp-mode-chain">高级事件链</button>' +
            '</div>' +
            '<div id="wpp-pane-simple">' +
            '<p class="wpp-hint">点击页面任意可点元素即可捕捉候选（不限已打标）。进自定义池仍须改名并点「录入自定义」；「选择值」只取值不记其它事件。</p>' +
            '<button type="button" class="wpp-adv-cta" id="wpp-goto-chain" data-testid="wpp-goto-chain">' +
            '<span class="wpp-adv-cta__title">高级事件链</span>' +
            '<span class="wpp-adv-cta__desc">跨页按序录步骤 → 命名闭环 → 仅录入/发布写入自定义池</span>' +
            '<span class="wpp-adv-cta__go">去录制</span>' +
            '</button>' +
            '<ul id="wpp-list" class="wpp-list"></ul>' +
            '<div class="wpp-custom">' +
            '<input id="wpp-custom-name" placeholder="输入自定义事件名，如 vip_apply" data-testid="wpp-custom-name">' +
            '<button type="button" class="wpp-btn" id="wpp-custom-pick-value" data-wpp-pick-value data-testid="wpp-custom-pick-value">选择值</button>' +
            '<button type="button" class="wpp-btn wpp-btn--primary" id="wpp-custom-add">录入自定义</button>' +
            '</div>' +
            '<div class="wpp-value-preview" id="wpp-value-preview" hidden data-testid="wpp-value-preview"></div>' +
            '</div>' +
            '<div id="wpp-pane-mapped" hidden>' +
            '<p class="wpp-hint">当前供应商已搭接 / 已录入的事件映射。自定义项优先展示；系统字典项仅作对照。</p>' +
            '<div class="wpp-mapped-bar">' +
            '<button type="button" class="wpp-btn" id="wpp-mapped-refresh" data-testid="wpp-mapped-refresh">刷新列表</button>' +
            '</div>' +
            '<ul id="wpp-mapped-list" class="wpp-list" data-testid="wpp-mapped-list"></ul>' +
            '</div>' +
            '<div id="wpp-pane-chain" hidden>' +
            '<p class="wpp-hint">链内可点击/输入入步（跨页保留）。填闭环总事件名后「仅录入」或「发布事件链」才写入自定义池；未命名封链不会进池。</p>' +
            '<div class="wpp-chain-bar">' +
            '<span>已录 <strong id="wpp-chain-count">0</strong> 步</span>' +
            '<label class="wpp-toggle"><input type="checkbox" id="wpp-chain-rec" checked> 继续录制</label>' +
            '</div>' +
            '<ol id="wpp-chain-list" class="wpp-chain-list"></ol>' +
            '<div class="wpp-seal">' +
            '<input id="wpp-chain-name" placeholder="闭环总事件名，如 checkout_funnel_done">' +
            '<input id="wpp-chain-third" placeholder="三方事件名（可选）">' +
            '<div class="wpp-seal__actions">' +
            '<button type="button" class="wpp-btn" id="wpp-chain-clear">清空链</button>' +
            '<button type="button" class="wpp-btn" id="wpp-chain-seal">仅录入</button>' +
            '<button type="button" class="wpp-btn wpp-btn--primary" id="wpp-chain-publish">发布事件链</button>' +
            '</div></div></div></div>';

        var style = document.createElement('style');
        style.textContent = '' +
            '#wpp-root{position:fixed;inset:auto 14px 14px auto;z-index:2147483000;' +
            'font:13px/1.45 var(--weline-font-family-sans,ui-sans-serif,system-ui,sans-serif);' +
            'color:var(--weline-color-text,#1a1a1a)}' +
            '.wpp-panel{width:min(24rem,94vw);max-height:min(78vh,40rem);overflow:auto;' +
            'background:var(--weline-color-surface,#fff);' +
            'border:1px solid var(--weline-color-border,rgba(0,0,0,.12));' +
            'border-radius:var(--weline-radius-md,10px);' +
            'box-shadow:0 12px 40px rgba(15,23,42,.14);padding:12px 14px}' +
            '.wpp-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}' +
            '.wpp-brand{display:flex;align-items:center;gap:8px;font-size:14px}' +
            '.wpp-dot{inline-size:8px;block-size:8px;border-radius:50%;' +
            'background:var(--weline-color-primary,#2f6fed);box-shadow:0 0 0 3px color-mix(in srgb,var(--weline-color-primary,#2f6fed) 22%,transparent)}' +
            '.wpp-icon-btn{border:0;background:transparent;font-size:20px;line-height:1;cursor:pointer;' +
            'color:var(--weline-color-text-muted,#666);padding:2px 6px;border-radius:6px}' +
            '.wpp-icon-btn:hover{background:var(--weline-color-surface-muted,rgba(0,0,0,.05))}' +
            '.wpp-modes{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;padding:3px;' +
            'background:var(--weline-color-surface-muted,rgba(0,0,0,.06));border-radius:8px;margin-bottom:10px;' +
            'border:1px solid var(--weline-color-border,rgba(0,0,0,.08))}' +
            '.wpp-modes button{border:0;background:transparent;padding:8px 4px;border-radius:6px;cursor:pointer;' +
            'color:var(--weline-color-text-muted,#555);font:inherit;font-weight:500;font-size:12px}' +
            '.wpp-modes button[data-active="1"]{background:var(--weline-color-surface,#fff);' +
            'color:var(--weline-color-primary,#2f6fed);font-weight:700;box-shadow:0 1px 2px rgba(0,0,0,.08)}' +
            '.wpp-mapped-bar{display:flex;justify-content:flex-end;margin-bottom:8px}' +
            '.wpp-hint{margin:0 0 10px;color:var(--weline-color-text-muted,#5c5c5c);font-size:12px}' +
            '.wpp-adv-cta{display:grid;grid-template-columns:1fr auto;grid-template-rows:auto auto;gap:2px 10px;' +
            'width:100%;text-align:left;margin:0 0 12px;padding:10px 12px;cursor:pointer;' +
            'border:1px dashed color-mix(in srgb,var(--weline-color-primary,#2f6fed) 45%,var(--weline-color-border,rgba(0,0,0,.12)));' +
            'border-radius:8px;background:color-mix(in srgb,var(--weline-color-primary,#2f6fed) 8%,var(--weline-color-surface,#fff));' +
            'color:inherit;font:inherit}' +
            '.wpp-adv-cta__title{font-weight:700;color:var(--weline-color-primary,#2f6fed);font-size:13px}' +
            '.wpp-adv-cta__desc{grid-column:1;font-size:11px;color:var(--weline-color-text-muted,#666);line-height:1.35}' +
            '.wpp-adv-cta__go{grid-row:1 / span 2;align-self:center;font-size:12px;font-weight:600;' +
            'color:var(--weline-color-primary,#2f6fed);white-space:nowrap}' +
            '.wpp-adv-cta:hover{border-style:solid}' +
            '.wpp-list,.wpp-chain-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}' +
            '.wpp-chain-list{max-block-size:14rem;overflow:auto}' +
            '.wpp-item{border:1px solid var(--weline-color-border,rgba(0,0,0,.08));border-radius:8px;padding:10px;display:grid;gap:6px;' +
            'background:var(--weline-color-surface,#fff)}' +
            '.wpp-item__top{display:flex;align-items:center;gap:6px}' +
            '.wpp-item__name{font-weight:600}' +
            '.wpp-badge{font-size:10px;padding:1px 6px;border-radius:999px;' +
            'background:color-mix(in srgb,var(--weline-color-primary,#2f6fed) 14%,transparent);' +
            'color:var(--weline-color-primary,#2f6fed)}' +
            '.wpp-badge--ok{background:color-mix(in srgb,var(--weline-color-success,#1a7f37) 16%,transparent);' +
            'color:var(--weline-color-success,#1a7f37)}' +
            '.wpp-item--mapped{opacity:0.96;background:var(--weline-color-surface-muted,rgba(0,0,0,.02))}' +
            '.wpp-item__done{font-size:12px;font-weight:600;padding:8px 10px;border-radius:6px;text-align:center;' +
            'color:var(--weline-color-success,#1a7f37);' +
            'background:color-mix(in srgb,var(--weline-color-success,#1a7f37) 10%,var(--weline-color-surface,#fff));' +
            'border:1px solid color-mix(in srgb,var(--weline-color-success,#1a7f37) 28%,transparent)}' +
            '.wpp-item__meta{font-size:11px;color:var(--weline-color-text-muted,#777);word-break:break-all}' +
            '.wpp-item__third{display:flex;gap:6px;align-items:center;font-size:12px}' +
            '.wpp-item__third input,.wpp-custom input,.wpp-seal input{flex:1;min-width:0;padding:6px 8px;' +
            'border:1px solid var(--weline-color-border,rgba(0,0,0,.14));border-radius:6px;font:inherit;' +
            'background:var(--weline-color-surface,#fff)}' +
            '.wpp-btn{border:1px solid var(--weline-color-border,rgba(0,0,0,.14));background:var(--weline-color-surface,#fff);' +
            'border-radius:6px;padding:6px 10px;cursor:pointer;font:inherit}' +
            '.wpp-btn--primary{background:var(--weline-color-primary,#2f6fed);border-color:transparent;color:#fff}' +
            '.wpp-btn[data-active="1"]{border-color:var(--weline-color-primary,#2f6fed);color:var(--weline-color-primary,#2f6fed)}' +
            '.wpp-custom{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}' +
            '.wpp-item__actions{display:flex;gap:6px;flex-wrap:wrap}' +
            '.wpp-value-banner{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 10px;' +
            'padding:8px 10px;border-radius:8px;font-size:12px;' +
            'background:color-mix(in srgb,var(--weline-color-warning,#b54708) 12%,var(--weline-color-surface,#fff));' +
            'border:1px solid color-mix(in srgb,var(--weline-color-warning,#b54708) 35%,transparent);' +
            'color:var(--weline-color-text,#1a1a1a)}' +
            '.wpp-value-banner[hidden]{display:none!important}' +
            '.wpp-capture-flash{margin:0 0 10px;padding:8px 10px;border-radius:8px;font-size:12px;line-height:1.45;' +
            'background:color-mix(in srgb,var(--weline-color-success,#16a34a) 12%,var(--weline-color-surface,#fff));' +
            'border:1px solid color-mix(in srgb,var(--weline-color-success,#16a34a) 35%,transparent);' +
            'color:var(--weline-color-text,#1a1a1a)}' +
            '.wpp-capture-flash[hidden]{display:none!important}' +
            '.wpp-capture-flash code{font-size:11px;padding:1px 4px;border-radius:4px;' +
            'background:var(--weline-color-surface-muted,rgba(0,0,0,.04))}' +
            '.wpp-value-preview{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px;font-size:12px;' +
            'padding:8px;border-radius:8px;background:var(--weline-color-surface-muted,rgba(0,0,0,.03));' +
            'border:1px dashed var(--weline-color-border,rgba(0,0,0,.12))}' +
            '.wpp-value-preview[hidden]{display:none!important}' +
            '.wpp-value-preview__label{font-weight:600}' +
            '.wpp-value-preview__val{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}' +
            '.wpp-value-preview__sel{color:var(--weline-color-text-muted,#777);font-size:11px}' +
            'html.wpp-value-picking,html.wpp-value-picking body{cursor:crosshair!important}' +
            'html.wpp-value-picking #wpp-root,html.wpp-value-picking #wpp-root *{cursor:default!important}' +
            '.wpp-empty{color:var(--weline-color-text-muted,#777);padding:10px 0;font-size:12px}' +
            '.wpp-chain-bar{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;font-size:12px}' +
            '.wpp-toggle{display:flex;align-items:center;gap:4px;color:var(--weline-color-text-muted,#555)}' +
            '.wpp-step{display:grid;grid-template-columns:1.5rem 1fr;gap:8px;align-items:start;' +
            'padding:8px;border-radius:8px;background:var(--weline-color-surface-muted,rgba(0,0,0,.03))}' +
            '.wpp-step__n{inline-size:1.5rem;block-size:1.5rem;border-radius:50%;display:grid;place-items:center;' +
            'font-size:11px;font-weight:600;background:var(--weline-color-surface,#fff);' +
            'border:1px solid var(--weline-color-border,rgba(0,0,0,.1))}' +
            '.wpp-step__title{font-weight:600;font-size:12px}' +
            '.wpp-step__meta{font-size:11px;color:var(--weline-color-text-muted,#777);word-break:break-all}' +
            '.wpp-seal{display:grid;gap:6px;margin-top:10px}' +
            '.wpp-seal__actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:6px}' +
            '@media (prefers-reduced-motion:no-preference){#wpp-root{animation:wpp-in .22s ease-out}}' +
            '@keyframes wpp-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}';

        document.documentElement.appendChild(style);
        document.documentElement.appendChild(root);

        document.getElementById('wpp-close').addEventListener('click', function () {
            clearHighlights();
            exitValuePick();
            clearPickerSession();
            root.remove();
            style.remove();
        });
        var valuePickCancel = document.getElementById('wpp-value-pick-cancel');
        if (valuePickCancel) {
            valuePickCancel.addEventListener('click', function () { exitValuePick(); });
        }
        var customPick = document.getElementById('wpp-custom-pick-value');
        if (customPick) {
            customPick.addEventListener('click', function () {
                if (valuePick.active && valuePick.target === 'custom') {
                    exitValuePick();
                    return;
                }
                enterValuePick('custom', -1);
            });
        }
        document.querySelectorAll('[data-wpp-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                exitValuePick();
                setMode(btn.getAttribute('data-wpp-mode'));
            });
        });
        var gotoChain = document.getElementById('wpp-goto-chain');
        if (gotoChain) {
            gotoChain.addEventListener('click', function () { setMode('chain'); });
        }
        var mappedRefresh = document.getElementById('wpp-mapped-refresh');
        if (mappedRefresh) {
            mappedRefresh.addEventListener('click', function () {
                loadMappedFromServer().then(function () { renderMapped(); });
            });
        }
        document.getElementById('wpp-custom-add').addEventListener('click', function () {
            var btn = this;
            var nameInput = document.getElementById('wpp-custom-name');
            var name = normalizeName((nameInput && nameInput.value) || '');
            if (!name) {
                if (nameInput) nameInput.focus();
                return;
            }
            if (isSystemDict(name)) {
                btn.textContent = '系统事件无需录入';
                return;
            }
            var fields = {
                token: token,
                weline_event: name,
                third_party_event: name,
                summary: 'named_custom',
                path: location.pathname || '',
                kind: 'single'
            };
            attachValueFields(fields, pendingValue);
            var proceed = Promise.resolve(true);
            if (isAlreadyMapped(name)) {
                proceed = confirmDuplicateRecord(name, name).then(function (ok) {
                    if (!ok) return false;
                    fields.force = '1';
                    return true;
                });
            }
            proceed.then(function (ok) {
                if (!ok) return;
                // 自定义池门禁：必须命名后走 record
                return submitRecord(fields, btn).then(function (data) {
                    if (data && data.ok) {
                        if (nameInput) nameInput.value = '';
                        pendingValue = null;
                        renderValuePreview();
                        btn.textContent = '已录入';
                        btn.disabled = false;
                        setMode('mapped');
                        loadMappedFromServer().then(function () { renderMapped(); });
                    } else if (!(data && data.duplicate)) {
                        btn.textContent = recordErrorText(data);
                        btn.title = String((data && (data.error || data.message)) || '');
                        btn.disabled = false;
                    }
                }).catch(function (e) {
                    btn.textContent = '失败：网络异常';
                    btn.title = String(e && e.message ? e.message : e);
                    btn.disabled = false;
                });
            });
        });
        var chainRec = document.getElementById('wpp-chain-rec');
        if (chainRec) {
            chainRec.checked = !!chainRecording;
            chainRec.addEventListener('change', function (e) {
                chainRecording = !!(e.target && e.target.checked);
                persistPickerSession({
                    mode: mode,
                    chain_recording: chainRecording,
                    token: token,
                    vendor: vendorCode,
                    website_id: auth.website_id,
                    active: true
                });
            });
        }
        document.getElementById('wpp-chain-clear').addEventListener('click', function () {
            chainSteps = [];
            saveChainSteps();
            renderChain();
        });
        document.getElementById('wpp-chain-seal').addEventListener('click', function () { sealChain(false); });
        document.getElementById('wpp-chain-publish').addEventListener('click', function () { sealChain(true); });

        installCrossPageNavGuards();
        persistPickerSession({
            active: true,
            token: token,
            vendor: vendorCode,
            website_id: auth.website_id,
            mode: mode,
            chain_recording: chainRecording
        });

        highlightMarkers();
        renderDiscover();
        renderChain();
        renderMapped();
        loadMappedFromServer();
        // 跨页恢复：回到链模式时自动展示已录步骤
        if (mode === 'chain' || mode === 'mapped') {
            setMode(mode);
        }

        // 选值模式：capture 点击只取值，阻断其它事件记录（含链/自动发现）
        document.addEventListener('mousemove', function (e) {
            if (!valuePick.active) return;
            var rootEl = document.getElementById('wpp-root');
            if (rootEl && rootEl.contains(e.target)) {
                clearValueHover();
                return;
            }
            var el = e.target;
            if (!el || el.nodeType !== 1) return;
            if (hoverPickEl === el) return;
            clearValueHover();
            hoverPickEl = el;
            try {
                el.style.outline = '2px solid var(--weline-color-primary,#2f6fed)';
                el.style.outlineOffset = '2px';
            } catch (err) {}
        }, true);

        document.addEventListener('click', function (e) {
            if (!valuePick.active) return;
            var rootEl = document.getElementById('wpp-root');
            if (rootEl && rootEl.contains(e.target)) return;
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            var el = e.target;
            if (!el || el.nodeType !== 1) {
                el = el && el.parentElement ? el.parentElement : null;
            }
            if (!el) return;
            var picked = extractElementValue(el);
            if (!picked) {
                setValuePickBanner(true);
                var banner = document.getElementById('wpp-value-pick-banner');
                if (banner) {
                    banner.innerHTML = '<strong>选值模式</strong>：未取到有效值，请点有文字/价格的元素。' +
                        '<button type="button" class="wpp-btn" id="wpp-value-pick-cancel">取消</button>';
                    var c = document.getElementById('wpp-value-pick-cancel');
                    if (c) c.addEventListener('click', function () { exitValuePick(); });
                }
                return;
            }
            applyPickedValue(picked);
        }, true);

        // 自动发现：任意可点目标都出候选；未打标则 suggestCustomName 草稿，仍须「录入自定义」进池。
        document.addEventListener('click', function (e) {
            if (valuePick.active) return;
            var rootEl = document.getElementById('wpp-root');
            if (rootEl && rootEl.contains(e.target)) return;
            if (mode === 'mapped') return;

            var el = e.target;
            if (!el || el.nodeType !== 1) {
                el = el && el.parentElement ? el.parentElement : null;
            }
            if (!el) return;
            var target = resolveClickTarget(el);
            if (!target) return;

            var name = eventNameFromEl(target);
            var label = ((target.innerText || target.textContent || target.getAttribute('aria-label') || target.getAttribute('name') || target.tagName) + '').trim().slice(0, 80);
            var fromMarker = !!name;
            if (!name) {
                name = suggestCustomName(target, label);
            }
            if (!name) return;

            if (mode === 'chain') {
                if (!chainRecording) return;
                appendChainStep({
                    type: 'click',
                    label: label,
                    selector: selectorHint(target),
                    event: name,
                    path: location.pathname
                });
                pulseCaptureTarget(target);
                return;
            }

            pushItem({
                weline_event: name,
                summary: label || selectorHint(target),
                path: location.pathname,
                already_mapped: isSystemOwned(name),
                source: fromMarker ? 'marker' : 'click'
            });
            pulseCaptureTarget(target);
            flashCaptureNotice(name, label);
        }, true);

        document.addEventListener('change', function (e) {
            if (valuePick.active) return;
            if (mode !== 'chain' || !chainRecording) return;
            var el = e.target;
            if (!el || !el.tagName) return;
            var tag = String(el.tagName).toLowerCase();
            if (tag !== 'input' && tag !== 'select' && tag !== 'textarea') return;
            var rootEl = document.getElementById('wpp-root');
            if (rootEl && rootEl.contains(el)) return;
            var type = (el.getAttribute('type') || '').toLowerCase();
            if (type === 'password') return;
            appendChainStep({
                type: 'input',
                label: el.getAttribute('name') || el.getAttribute('placeholder') || tag,
                selector: selectorHint(el),
                value: type === 'checkbox' || type === 'radio' ? (el.checked ? '1' : '0') : '',
                path: location.pathname
            });
        }, true);

        document.addEventListener('submit', function (e) {
            if (valuePick.active) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            if (mode !== 'chain' || !chainRecording) return;
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            var rootEl = document.getElementById('wpp-root');
            if (rootEl && rootEl.contains(form)) return;
            appendChainStep({
                type: 'submit',
                label: form.getAttribute('action') || 'form_submit',
                selector: selectorHint(form),
                path: location.pathname
            });
        }, true);

        // 进页本身不在简易模式自动记步；切到操作链时由 setMode 记 page
        renderChain();

        function hookTrack() {
            if (!window.WelinePixel || typeof window.WelinePixel.track !== 'function' || window.WelinePixel.__wppHooked) {
                return false;
            }
            var orig = window.WelinePixel.track.bind(window.WelinePixel);
            window.WelinePixel.track = function (name, meta, options) {
                try {
                    if (valuePick.active) {
                        return orig(name, meta, options);
                    }
                    var summary = meta && (meta.link_text || meta.search_term || JSON.stringify(meta).slice(0, 80));
                    if (mode === 'chain') {
                        appendChainStep({
                            type: 'track',
                            label: summary || name,
                            event: name,
                            path: location.pathname
                        });
                    } else {
                        pushItem({
                            weline_event: name,
                            summary: summary,
                            path: location.pathname,
                            source: 'track',
                            fired: true
                        });
                    }
                } catch (err) {}
                return orig(name, meta, options);
            };
            window.WelinePixel.__wppHooked = true;
            return true;
        }
        if (!hookTrack()) {
            var tries = 0;
            var timer = setInterval(function () {
                tries += 1;
                if (hookTrack() || tries > 40) clearInterval(timer);
            }, 250);
        }
        if (window.__WelineLoadPixel) {
            try { window.__WelineLoadPixel('event-picker'); } catch (e) {}
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
