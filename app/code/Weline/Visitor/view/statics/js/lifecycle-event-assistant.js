/**
 * Lifecycle Event Assistant — floating DEV probe for checkout/payment surfaces.
 * Loaded by Weline panel「访问事件」; persists in-browser until panel collapses/closes
 * or the panel toggle turns it off. Event log keeps only current + previous page.
 */
(function (global, document) {
    'use strict';

    var STORAGE_KEY = 'weline_lifecycle_assistant_v1';
    var HISTORY_KEY = 'weline_lifecycle_assistant_history_v1';
    var POSITION_KEY = 'weline_lifecycle_assistant_pos_v1';
    var COOKIE_ON = 'weline_lifecycle_assistant';
    var ROOT_ID = 'weline-lifecycle-assistant';
    var STYLE_ATTR = 'data-weline-lifecycle-assistant-style';
    var PANEL_COLLAPSE_EVENT = 'weline:dev-tool-panel:collapsed';
    var WATCH_EVENTS = [
        'weline:checkout:order-created',
        'weline:checkout:success',
        'weline:checkout:cancelled',
        'weline:checkout:anomaly',
        'weline:payment:outcome',
        'weline:payment:paid',
        'weline:payment:pending',
        'weline:payment:failed',
        'weline:payment:cancelled',
        'weline:payment:anomaly',
        'weline:cart:update',
    ];
    var CHAIN = [
        { id: 'order-created', label: '订单已创建', match: ['weline:checkout:order-created'] },
        { id: 'payment', label: '支付结果', match: ['weline:payment:paid', 'weline:payment:pending', 'weline:payment:failed', 'weline:payment:cancelled', 'weline:payment:outcome'] },
        { id: 'checkout-success', label: '结账成功', match: ['weline:checkout:success'] },
        { id: 'cart-cleared', label: '购物车清理', match: ['weline:cart:update'] },
    ];

    var state = {
        enabled: false,
        mounted: false,
        pageKey: '',
        fired: [],
        anomalies: [],
        pageIssues: [],
        previous: null,
        listenersBound: false,
        panelWatchBound: false,
        panelObserver: null,
        clearingFromPanel: false,
        pageHideBound: false,
    };

    function text(value) {
        return value == null ? '' : String(value);
    }

    function nowTs() {
        try {
            return new Date().toLocaleTimeString();
        } catch (e) {
            return String(Date.now());
        }
    }

    function setCookie(on) {
        try {
            var secure = global.location && global.location.protocol === 'https:' ? '; Secure' : '';
            var maxAge = on ? 28800 : 0;
            document.cookie = COOKIE_ON + '=' + (on ? '1' : '0')
                + '; Path=/; SameSite=Lax; Max-Age=' + maxAge + secure;
        } catch (eCookie) {}
    }

    function readCookieOn() {
        try {
            return /(^|;\s*)weline_lifecycle_assistant=1(;|$)/.test(String(document.cookie || ''));
        } catch (e) {
            return false;
        }
    }

    function readEnabled() {
        try {
            if (global.sessionStorage.getItem(STORAGE_KEY) === '1') {
                return true;
            }
        } catch (e) {}
        return readCookieOn();
    }

    function writeEnabled(on) {
        try {
            if (on) {
                global.sessionStorage.setItem(STORAGE_KEY, '1');
            } else {
                global.sessionStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {}
        setCookie(!!on);
    }

    function currentPageKey() {
        try {
            var loc = global.location;
            return text(loc && (loc.pathname + (loc.search || '')));
        } catch (e) {
            return '';
        }
    }

    function cloneRows(rows) {
        return Array.isArray(rows) ? rows.slice() : [];
    }

    function normalizeBucket(bucket, fallbackPath) {
        if (!bucket || typeof bucket !== 'object') {
            return null;
        }
        return {
            path: text(bucket.path || fallbackPath || ''),
            surface: text(bucket.surface || 'other'),
            fired: cloneRows(bucket.fired),
            anomalies: cloneRows(bucket.anomalies),
            pageIssues: cloneRows(bucket.pageIssues),
            updatedAt: Number(bucket.updatedAt) || Date.now(),
        };
    }

    function readHistory() {
        try {
            var raw = global.sessionStorage.getItem(HISTORY_KEY);
            if (!raw) {
                return { current: null, previous: null };
            }
            var parsed = JSON.parse(raw);
            return {
                current: normalizeBucket(parsed && parsed.current),
                previous: normalizeBucket(parsed && parsed.previous),
            };
        } catch (e) {
            return { current: null, previous: null };
        }
    }

    function snapshotCurrentBucket() {
        return {
            path: state.pageKey || currentPageKey(),
            surface: detectSurface(),
            fired: cloneRows(state.fired),
            anomalies: cloneRows(state.anomalies),
            pageIssues: cloneRows(state.pageIssues),
            updatedAt: Date.now(),
        };
    }

    function writeHistory() {
        try {
            global.sessionStorage.setItem(HISTORY_KEY, JSON.stringify({
                current: snapshotCurrentBucket(),
                previous: state.previous ? normalizeBucket(state.previous) : null,
            }));
        } catch (eWrite) {}
    }

    function clearHistory() {
        try {
            global.sessionStorage.removeItem(HISTORY_KEY);
        } catch (eClear) {}
        state.previous = null;
        state.fired = [];
        state.anomalies = [];
        state.pageIssues = [];
        state.pageKey = currentPageKey();
    }

    function readPosition() {
        try {
            var raw = global.sessionStorage.getItem(POSITION_KEY);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.left !== 'number' || typeof parsed.top !== 'number') {
                return null;
            }
            return { left: parsed.left, top: parsed.top };
        } catch (ePos) {
            return null;
        }
    }

    function writePosition(left, top) {
        try {
            global.sessionStorage.setItem(POSITION_KEY, JSON.stringify({
                left: left,
                top: top,
            }));
        } catch (eWritePos) {}
    }

    function clearPosition() {
        try {
            global.sessionStorage.removeItem(POSITION_KEY);
        } catch (eClearPos) {}
    }

    function clampPosition(left, top, root) {
        var width = (root && root.offsetWidth) || 360;
        var height = (root && root.offsetHeight) || 200;
        var maxLeft = Math.max(0, (global.innerWidth || width) - width);
        var maxTop = Math.max(0, (global.innerHeight || height) - height);
        return {
            left: Math.min(Math.max(0, left), maxLeft),
            top: Math.min(Math.max(0, top), maxTop),
        };
    }

    function applyPosition(root) {
        if (!root) {
            return;
        }
        var pos = readPosition();
        if (!pos) {
            root.style.left = '';
            root.style.top = '';
            root.style.right = '16px';
            root.style.bottom = '16px';
            return;
        }
        var next = clampPosition(pos.left, pos.top, root);
        root.style.right = 'auto';
        root.style.bottom = 'auto';
        root.style.left = next.left + 'px';
        root.style.top = next.top + 'px';
    }

    function bindChrome(root) {
        if (!root || root.__wlaChromeBound) {
            return;
        }
        root.__wlaChromeBound = true;
        var drag = { active: false, originX: 0, originY: 0, startLeft: 0, startTop: 0 };

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var closeBtn = target.closest('[data-wla-action="close"]');
            if (!closeBtn || !root.contains(closeBtn)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            disable();
        });

        root.addEventListener('pointerdown', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            if (target.closest('[data-wla-action="close"]')) {
                return;
            }
            var head = target.closest('.wla-head');
            if (!head || !root.contains(head)) {
                return;
            }
            var rect = root.getBoundingClientRect();
            drag.active = true;
            drag.originX = event.clientX;
            drag.originY = event.clientY;
            drag.startLeft = rect.left;
            drag.startTop = rect.top;
            root.style.right = 'auto';
            root.style.bottom = 'auto';
            root.style.left = drag.startLeft + 'px';
            root.style.top = drag.startTop + 'px';
            try {
                root.setPointerCapture(event.pointerId);
            } catch (eCapture) {}
            event.preventDefault();
        });

        root.addEventListener('pointermove', function (event) {
            if (!drag.active) {
                return;
            }
            var next = clampPosition(
                drag.startLeft + (event.clientX - drag.originX),
                drag.startTop + (event.clientY - drag.originY),
                root
            );
            root.style.left = next.left + 'px';
            root.style.top = next.top + 'px';
        });

        function endDrag(event) {
            if (!drag.active) {
                return;
            }
            drag.active = false;
            var rect = root.getBoundingClientRect();
            var next = clampPosition(rect.left, rect.top, root);
            root.style.left = next.left + 'px';
            root.style.top = next.top + 'px';
            writePosition(next.left, next.top);
            try {
                root.releasePointerCapture(event.pointerId);
            } catch (eRelease) {}
        }

        root.addEventListener('pointerup', endDrag);
        root.addEventListener('pointercancel', endDrag);
    }

    function hydratePageWindow() {
        var key = currentPageKey();
        var hist = readHistory();
        if (hist.current && hist.current.path === key) {
            state.pageKey = key;
            state.fired = hist.current.fired;
            state.anomalies = hist.current.anomalies;
            state.pageIssues = hist.current.pageIssues;
            state.previous = hist.previous;
            return;
        }
        // New page / tab navigation: keep only previous = last current; drop older.
        state.previous = hist.current;
        state.pageKey = key;
        state.fired = [];
        state.anomalies = [];
        state.pageIssues = [];
        writeHistory();
    }

    function detectSurface() {
        var path = '';
        try {
            path = text(global.location && global.location.pathname);
        } catch (e) {}
        if (/\/checkout(?:\/index)?(?:\.html)?\/?$/i.test(path)) {
            return 'checkout';
        }
        if (/\/checkout\/success/i.test(path)) {
            return 'checkout-success';
        }
        if (/\/payment\/success/i.test(path)) {
            return 'payment-success';
        }
        if (/\/payment\/handoff/i.test(path)) {
            return 'payment-handoff';
        }
        return 'other';
    }

    function pageFieldSnapshot() {
        var root = document.querySelector(
            '[data-payment-lifecycle], [data-checkout-lifecycle], [data-testid="payment-return"], [data-testid="payment-handoff"], [data-weline-checkout], main.amz-order-confirm'
        );
        var snap = {
            surface: detectSurface(),
            order_uuid: '',
            order_number: '',
            transaction_id: '',
            checkout_session_code: '',
            amount: '',
            currency: '',
        };
        if (root) {
            snap.order_uuid = text(root.getAttribute('data-order-uuid')).trim();
            snap.transaction_id = text(root.getAttribute('data-transaction-id')).trim();
            snap.checkout_session_code = text(root.getAttribute('data-checkout-session-code')).trim();
            snap.amount = text(root.getAttribute('data-amount') || root.getAttribute('data-pixel-value') || root.getAttribute('data-order-amount')).trim();
            snap.currency = text(root.getAttribute('data-currency') || root.getAttribute('data-pixel-currency')).trim();
            var strong = root.querySelector('.amz-order-confirm__order-no strong, [data-order-number], [data-payment-order-reference]');
            if (strong) {
                snap.order_number = text(strong.textContent).trim();
            }
        }
        var hash = '';
        try {
            hash = text(global.location && global.location.hash);
        } catch (eHash) {}
        if (!snap.order_uuid && hash.indexOf('#payment-recovery') === 0) {
            try {
                var params = new URLSearchParams(hash.replace(/^#payment-recovery\?/, ''));
                snap.order_uuid = text(params.get('order_uuid')).trim();
            } catch (eParams) {}
        }
        return snap;
    }

    function auditPage() {
        var snap = pageFieldSnapshot();
        var issues = [];
        if (snap.surface === 'other') {
            state.pageIssues = [];
            writeHistory();
            return snap;
        }
        var hasIdentity = !!(snap.order_uuid || snap.order_number || snap.transaction_id || snap.checkout_session_code);
        if (!hasIdentity) {
            issues.push('缺少订单编号 / order_uuid / transaction_id / session');
        }
        if (!snap.amount) {
            issues.push('缺少订单金额 amount');
        }
        if (!snap.currency) {
            issues.push('缺少货币 currency');
        }
        state.pageIssues = issues.map(function (msg) {
            return { at: nowTs(), message: msg, surface: snap.surface, snap: snap };
        });
        writeHistory();
        return snap;
    }

    function ensureStyle() {
        if (document.querySelector('style[' + STYLE_ATTR + ']')) {
            return;
        }
        var style = document.createElement('style');
        style.setAttribute(STYLE_ATTR, '1');
        style.textContent = [
            '#' + ROOT_ID + '{position:fixed;right:16px;bottom:16px;z-index:2147483000;width:min(360px,calc(100vw - 24px));',
            'font:12px/1.45 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;',
            'color:#e8eef7;background:#121826;border:1px solid #2a364d;border-radius:12px;box-shadow:0 18px 40px rgba(0,0,0,.35);overflow:hidden}',
            '#' + ROOT_ID + ' .wla-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;background:#172033;border-bottom:1px solid #2a364d;cursor:move;user-select:none;touch-action:none}',
            '#' + ROOT_ID + ' .wla-title{font-weight:800;letter-spacing:.02em}',
            '#' + ROOT_ID + ' .wla-meta{color:#93a4bd;font-size:11px}',
            '#' + ROOT_ID + ' .wla-close{flex:0 0 auto;width:28px;height:28px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e8eef7;font-size:18px;line-height:1;cursor:pointer}',
            '#' + ROOT_ID + ' .wla-close:hover{border-color:#64748b;background:#1e293b}',
            '#' + ROOT_ID + ' .wla-body{padding:10px 12px;max-height:42vh;overflow:auto}',
            '#' + ROOT_ID + ' .wla-sec{margin:0 0 10px}',
            '#' + ROOT_ID + ' .wla-sec h4{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#93a4bd}',
            '#' + ROOT_ID + ' .wla-chip{display:inline-flex;align-items:center;gap:6px;margin:0 6px 6px 0;padding:3px 8px;border-radius:999px;border:1px solid #334155;background:#0f172a}',
            '#' + ROOT_ID + ' .wla-chip.is-done{border-color:#166534;background:#052e16;color:#bbf7d0}',
            '#' + ROOT_ID + ' .wla-chip.is-wait{opacity:.7}',
            '#' + ROOT_ID + ' .wla-list{margin:0;padding:0;list-style:none}',
            '#' + ROOT_ID + ' .wla-list li{padding:6px 0;border-bottom:1px solid #1f2a3d}',
            '#' + ROOT_ID + ' .wla-list li:last-child{border-bottom:0}',
            '#' + ROOT_ID + ' .wla-bad{color:#fecaca}',
            '#' + ROOT_ID + ' .wla-ok{color:#bbf7d0}',
            '#' + ROOT_ID + ' .wla-foot{padding:8px 12px;border-top:1px solid #2a364d;color:#93a4bd;font-size:11px}',
        ].join('');
        document.head.appendChild(style);
    }

    function chainProgressHtml(firedRows) {
        var firedNames = (firedRows || []).map(function (row) { return row.name; });
        return CHAIN.map(function (step) {
            var done = step.match.some(function (name) { return firedNames.indexOf(name) !== -1; });
            return '<span class="wla-chip ' + (done ? 'is-done' : 'is-wait') + '">'
                + (done ? '✓' : '·') + ' ' + step.label + '</span>';
        }).join('');
    }

    function listHtml(rows, emptyText, bad) {
        if (!rows.length) {
            return '<div class="wla-meta">' + emptyText + '</div>';
        }
        return '<ul class="wla-list">' + rows.slice(-12).reverse().map(function (row) {
            var cls = bad ? 'wla-bad' : '';
            return '<li class="' + cls + '"><strong>' + text(row.at) + '</strong> '
                + text(row.message || row.name)
                + (row.detail ? '<div class="wla-meta">' + text(typeof row.detail === 'string' ? row.detail : JSON.stringify(row.detail)).slice(0, 180) + '</div>' : '')
                + '</li>';
        }).join('') + '</ul>';
    }

    function previousSectionHtml() {
        if (!state.previous) {
            return '<div class="wla-sec"><h4>上一页事件</h4><div class="wla-meta">尚无上一页记录</div></div>';
        }
        var prev = state.previous;
        var count = (prev.fired || []).length + (prev.anomalies || []).length + (prev.pageIssues || []).length;
        return [
            '<div class="wla-sec"><h4>上一页事件</h4>',
            '<div class="wla-meta">' + text(prev.path || '—') + ' · ' + text(prev.surface || '—') + ' · 共 ' + count + ' 条</div>',
            '<div class="wla-meta" style="margin:6px 0 2px">事件链</div>',
            chainProgressHtml(prev.fired || []),
            '<div class="wla-meta" style="margin:8px 0 2px">已触发</div>',
            listHtml(prev.fired || [], '上一页无生命周期事件', false),
            '<div class="wla-meta" style="margin:8px 0 2px">异常</div>',
            listHtml([].concat(prev.anomalies || [], prev.pageIssues || []), '上一页无异常', true),
            '</div>',
        ].join('');
    }

    function render() {
        var root = document.getElementById(ROOT_ID);
        if (!root) {
            return;
        }
        var snap = auditPage();
        var anomalyCount = state.anomalies.length + state.pageIssues.length;
        root.innerHTML = [
            '<div class="wla-head">',
            '<div><div class="wla-title">生命周期小助手</div>',
            '<div class="wla-meta">surface: ' + text(snap.surface) + ' · 本页触发 ' + state.fired.length + ' · 异常 ' + anomalyCount + '</div></div>',
            '<button type="button" class="wla-close" data-wla-action="close" aria-label="关闭生命周期小助手" title="关闭并清理">×</button>',
            '</div>',
            '<div class="wla-body">',
            '<div class="wla-sec"><h4>本页事件链</h4>' + chainProgressHtml(state.fired) + '</div>',
            '<div class="wla-sec"><h4>本页字段</h4>',
            '<div class="wla-meta">order=' + text(snap.order_uuid || snap.order_number || '—')
                + ' · txn=' + text(snap.transaction_id || snap.checkout_session_code || '—')
                + ' · ' + text(snap.currency || '—') + ' ' + text(snap.amount || '—') + '</div>',
            listHtml(state.pageIssues, state.pageIssues.length ? '' : '<span class="wla-ok">字段齐全或非结账/支付面</span>', true),
            '</div>',
            '<div class="wla-sec"><h4>本页已触发事件</h4>' + listHtml(state.fired, '尚无生命周期事件', false) + '</div>',
            '<div class="wla-sec"><h4>本页派发异常</h4>' + listHtml(state.anomalies, '无派发异常', true) + '</div>',
            previousSectionHtml(),
            '</div>',
            '<div class="wla-foot">可拖动标题栏记住位置；点 × 关闭并清理。仅保留本页与上一页</div>',
        ].join('');
        applyPosition(root);
        bindChrome(root);
    }

    function onLifecycleEvent(event) {
        if (!state.enabled) {
            return;
        }
        var name = text(event && event.type);
        var detail = (event && event.detail) || {};
        var row = {
            at: nowTs(),
            name: name,
            message: name,
            detail: detail,
        };
        if (/anomaly$/i.test(name)) {
            state.anomalies.push({
                at: row.at,
                message: text(detail.reason || 'anomaly') + (detail.missing ? (' · missing: ' + [].concat(detail.missing).join(',')) : ''),
                detail: detail,
            });
        } else {
            state.fired.push(row);
        }
        writeHistory();
        render();
    }

    function bindListeners() {
        if (state.listenersBound) {
            return;
        }
        WATCH_EVENTS.forEach(function (name) {
            global.addEventListener(name, onLifecycleEvent);
        });
        state.listenersBound = true;
    }

    function unbindListeners() {
        if (!state.listenersBound) {
            return;
        }
        WATCH_EVENTS.forEach(function (name) {
            global.removeEventListener(name, onLifecycleEvent);
        });
        state.listenersBound = false;
    }

    function clearBecausePanelClosed(source) {
        if (!state.enabled || state.clearingFromPanel) {
            return;
        }
        state.clearingFromPanel = true;
        try {
            disable();
            try {
                console.info('[WelineLifecycleAssistant] 面板关闭，已清除小助手', source || '');
            } catch (eLog) {}
        } finally {
            state.clearingFromPanel = false;
        }
    }

    function wrapDevToolPanel() {
        var panelApi = global.DevToolPanel;
        if (!panelApi || panelApi.__lifecycleAssistantWrapped) {
            return;
        }
        panelApi.__lifecycleAssistantWrapped = true;

        function afterMaybeCollapsed(source, wasCollapsed) {
            try {
                var el = document.getElementById('dev-tool-panel');
                var nowCollapsed = !!(el && el.classList.contains('collapsed'));
                if (!wasCollapsed && nowCollapsed) {
                    global.dispatchEvent(new CustomEvent(PANEL_COLLAPSE_EVENT, {
                        detail: { source: source || 'dev-tool-panel' },
                    }));
                }
            } catch (e) {}
        }

        ['toggle', 'closePanel'].forEach(function (method) {
            var original = panelApi[method];
            if (typeof original !== 'function') {
                return;
            }
            panelApi[method] = function () {
                var el = document.getElementById('dev-tool-panel');
                var wasCollapsed = !!(el && el.classList.contains('collapsed'));
                var result = original.apply(this, arguments);
                afterMaybeCollapsed(method, wasCollapsed);
                return result;
            };
        });
    }

    function bindPanelWatch() {
        if (state.panelWatchBound) {
            return;
        }
        state.panelWatchBound = true;
        global.addEventListener(PANEL_COLLAPSE_EVENT, function (event) {
            clearBecausePanelClosed((event && event.detail && event.detail.source) || 'event');
        });
        wrapDevToolPanel();
        var tries = 0;
        var timer = global.setInterval(function () {
            tries += 1;
            wrapDevToolPanel();
            if ((global.DevToolPanel && global.DevToolPanel.__lifecycleAssistantWrapped) || tries > 40) {
                global.clearInterval(timer);
            }
        }, 250);
        // Do NOT clear merely because #dev-tool-panel starts collapsed on a new page.
        // Only user toggle/closePanel (open → collapsed) clears via PANEL_COLLAPSE_EVENT.
    }

    function unbindPanelWatch() {
        if (state.panelObserver) {
            try {
                state.panelObserver.disconnect();
            } catch (e) {}
            state.panelObserver = null;
        }
    }

    function onPageHide() {
        if (!state.enabled) {
            return;
        }
        writeHistory();
    }

    function bindPageHide() {
        if (state.pageHideBound) {
            return;
        }
        state.pageHideBound = true;
        global.addEventListener('pagehide', onPageHide);
        global.addEventListener('beforeunload', onPageHide);
    }

    function unbindPageHide() {
        if (!state.pageHideBound) {
            return;
        }
        global.removeEventListener('pagehide', onPageHide);
        global.removeEventListener('beforeunload', onPageHide);
        state.pageHideBound = false;
    }

    function mount() {
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', function () {
                mount();
            }, { once: true });
            return;
        }
        ensureStyle();
        var root = document.getElementById(ROOT_ID);
        if (!root) {
            root = document.createElement('aside');
            root.id = ROOT_ID;
            root.setAttribute('data-testid', 'lifecycle-event-assistant');
            root.setAttribute('aria-live', 'polite');
            document.body.appendChild(root);
        }
        state.mounted = true;
        bindListeners();
        bindPanelWatch();
        bindPageHide();
        auditPage();
        render();
    }

    function unmount() {
        unbindListeners();
        unbindPanelWatch();
        unbindPageHide();
        var root = document.getElementById(ROOT_ID);
        if (root && root.parentNode) {
            root.parentNode.removeChild(root);
        }
        state.mounted = false;
        state.fired = [];
        state.anomalies = [];
        state.pageIssues = [];
        state.previous = null;
    }

    function enable() {
        state.enabled = true;
        writeEnabled(true);
        hydratePageWindow();
        mount();
        return true;
    }

    function disable() {
        state.enabled = false;
        writeEnabled(false);
        clearHistory();
        clearPosition();
        unmount();
        return true;
    }

    function isEnabled() {
        return !!state.enabled;
    }

    function snapshot() {
        return {
            enabled: state.enabled,
            surface: detectSurface(),
            pageKey: state.pageKey || currentPageKey(),
            fired: state.fired.slice(),
            anomalies: state.anomalies.slice(),
            pageIssues: state.pageIssues.slice(),
            previous: state.previous ? normalizeBucket(state.previous) : null,
            position: readPosition(),
            page: pageFieldSnapshot(),
        };
    }

    var api = {
        enable: enable,
        disable: disable,
        isEnabled: isEnabled,
        mount: mount,
        unmount: unmount,
        refresh: function () { auditPage(); render(); return snapshot(); },
        snapshot: snapshot,
        STORAGE_KEY: STORAGE_KEY,
        HISTORY_KEY: HISTORY_KEY,
        POSITION_KEY: POSITION_KEY,
        PANEL_COLLAPSE_EVENT: PANEL_COLLAPSE_EVENT,
    };

    global.WelineLifecycleAssistant = api;
    global.__WELINE_LIFECYCLE_ASSISTANT__ = api;

    if (readEnabled()) {
        enable();
    }
})(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null);
