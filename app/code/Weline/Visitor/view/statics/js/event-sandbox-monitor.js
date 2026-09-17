/**
 * Event Sandbox Monitor — parent-page observer for the sandbox stream.
 * Full action stream (incl. clicks); system hit → green; custom hit → blue; anomaly → theme accent.
 * Owned by WelineEventSandbox.monitor; does not change passthrough architecture.
 */
(function (global, document) {
    'use strict';

    var STORAGE_KEY = 'weline_event_sandbox_monitor_v1';
    var LEGACY_STORAGE_KEY = 'weline_lifecycle_assistant_v1';
    var HISTORY_KEY = 'weline_event_sandbox_monitor_history_v1';
    var LEGACY_HISTORY_KEY = 'weline_lifecycle_assistant_history_v1';
    var CHAIN_KEY = 'weline_event_sandbox_monitor_chains_v1';
    var POSITION_KEY = 'weline_event_sandbox_monitor_pos_v1';
    var LEGACY_POSITION_KEY = 'weline_lifecycle_assistant_pos_v1';
    var COOKIE_ON = 'weline_event_sandbox_monitor';
    var LEGACY_COOKIE_ON = 'weline_lifecycle_assistant';
    var ROOT_ID = 'weline-event-sandbox-monitor';
    var STYLE_ATTR = 'data-weline-event-sandbox-monitor-style';
    var STYLE_VERSION = '20260916-event-sandbox-monitor10';
    var PANEL_COLLAPSE_EVENT = 'weline:dev-tool-panel:collapsed';
    var MAX_ROWS = 120;
    var MAX_CHAIN_ROWS = 200;
    var TAB_IDS = { system: 1, custom: 1, dedupe: 1, previous: 1, chain: 1, stream: 1 };

    var LIFECYCLE_CHAIN = [
        { id: 'order-created', label: '订单已创建', match: ['weline:checkout:order-created'] },
        { id: 'payment', label: '支付结果', match: ['weline:payment:paid', 'weline:payment:pending', 'weline:payment:failed', 'weline:payment:cancelled', 'weline:payment:outcome'] },
        { id: 'checkout-success', label: '结账成功', match: ['weline:checkout:success'] },
        { id: 'cart-cleared', label: '购物车清理', match: ['weline:cart:update'] }
    ];

    var state = {
        enabled: false,
        mounted: false,
        pageKey: '',
        rows: [],
        previous: null,
        chainRows: [],
        activeTab: 'stream',
        unsub: null,
        panelWatchBound: false,
        clearingFromPanel: false,
        seenIds: {},
        chainSeenIds: {},
        expanded: {},
        configRevision: 0,
        configRevWatchBound: false
    };

    function readConfigRevision() {
        try {
            var cfg = global.__WelineVisitorTrackingConfig || {};
            return Number(cfg.configRevision || cfg.config_revision || state.configRevision || 0) || 0;
        } catch (e) {
            return Number(state.configRevision || 0) || 0;
        }
    }

    function setConfigRevision(rev) {
        rev = Number(rev) || 0;
        if (rev > 0) {
            state.configRevision = rev;
        }
        return state.configRevision || readConfigRevision();
    }

    function formatScopeVersionLabel(rev) {
        rev = Number(rev != null ? rev : readConfigRevision()) || 0;
        var cfg = {};
        var env = {};
        try { cfg = global.__WelineVisitorTrackingConfig || {}; } catch (e0) {}
        try { env = global.__WelinePixelEnv || {}; } catch (e1) {}
        var scope = (cfg.configScope && typeof cfg.configScope === 'object') ? cfg.configScope : {};
        var site = String(scope.website_code || env.website_code || '').trim();
        if (!site) {
            var wid = String(scope.website_id || env.website_id || '').trim();
            site = wid ? ('website.' + wid) : 'default';
        }
        var store = String(scope.store_code || env.store_code || '').trim() || 'default';
        var channel = String(scope.channel_code || env.channel_code || '').trim();
        if (!channel) {
            try {
                var sticky = global.__WelineStickyUtm || null;
                if (sticky && sticky.wch) {
                    channel = String(sticky.wch).trim();
                }
            } catch (e2) {}
        }
        if (!channel) {
            try {
                channel = String(new URLSearchParams(global.location && global.location.search || '').get('channel_code')
                    || new URLSearchParams(global.location && global.location.search || '').get('wch')
                    || '').trim();
            } catch (e3) {}
        }
        if (!channel) {
            channel = 'default';
        }
        return '站点 ' + site + ' · 店铺 ' + store + ' · 渠道 ' + channel + ' · 版本 ' + rev;
    }

    function text(value) {
        return value == null ? '' : String(value);
    }

    function esc(value) {
        return text(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function nowTs() {
        try {
            return new Date().toLocaleTimeString();
        } catch (e) {
            return String(Date.now());
        }
    }

    function currentPageKey() {
        try {
            var loc = global.location;
            return text(loc && (loc.pathname + (loc.search || '')));
        } catch (e) {
            return '';
        }
    }

    function setCookie(on, name) {
        try {
            var secure = global.location && global.location.protocol === 'https:' ? '; Secure' : '';
            var maxAge = on ? 28800 : 0;
            document.cookie = name + '=' + (on ? '1' : '0')
                + '; Path=/; SameSite=Lax; Max-Age=' + maxAge + secure;
        } catch (eCookie) {}
    }

    function readCookieOn(name) {
        try {
            return new RegExp('(^|;\\s*)' + name + '=1(;|$)').test(String(document.cookie || ''));
        } catch (e) {
            return false;
        }
    }

    function migrateLegacyKeys() {
        try {
            if (global.sessionStorage.getItem(STORAGE_KEY) == null
                && global.sessionStorage.getItem(LEGACY_STORAGE_KEY) === '1') {
                global.sessionStorage.setItem(STORAGE_KEY, '1');
                global.sessionStorage.removeItem(LEGACY_STORAGE_KEY);
            }
            if (!global.sessionStorage.getItem(HISTORY_KEY) && global.sessionStorage.getItem(LEGACY_HISTORY_KEY)) {
                global.sessionStorage.setItem(HISTORY_KEY, global.sessionStorage.getItem(LEGACY_HISTORY_KEY));
                global.sessionStorage.removeItem(LEGACY_HISTORY_KEY);
            }
            if (!global.sessionStorage.getItem(POSITION_KEY) && global.sessionStorage.getItem(LEGACY_POSITION_KEY)) {
                global.sessionStorage.setItem(POSITION_KEY, global.sessionStorage.getItem(LEGACY_POSITION_KEY));
                global.sessionStorage.removeItem(LEGACY_POSITION_KEY);
            }
            if (!readCookieOn(COOKIE_ON) && readCookieOn(LEGACY_COOKIE_ON)) {
                setCookie(true, COOKIE_ON);
                setCookie(false, LEGACY_COOKIE_ON);
            }
        } catch (eMig) {}
    }

    function readEnabled() {
        migrateLegacyKeys();
        try {
            if (global.sessionStorage.getItem(STORAGE_KEY) === '1') {
                return true;
            }
        } catch (e) {}
        return readCookieOn(COOKIE_ON) || readCookieOn(LEGACY_COOKIE_ON);
    }

    function writeEnabled(on) {
        try {
            if (on) {
                global.sessionStorage.setItem(STORAGE_KEY, '1');
            } else {
                global.sessionStorage.removeItem(STORAGE_KEY);
                global.sessionStorage.removeItem(LEGACY_STORAGE_KEY);
            }
        } catch (e) {}
        setCookie(!!on, COOKIE_ON);
        if (!on) {
            setCookie(false, LEGACY_COOKIE_ON);
        }
    }

    function readPosition() {
        try {
            var raw = global.sessionStorage.getItem(POSITION_KEY)
                || global.sessionStorage.getItem(LEGACY_POSITION_KEY);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.left !== 'number' || typeof parsed.top !== 'number') {
                return null;
            }
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function writePosition(left, top) {
        try {
            global.sessionStorage.setItem(POSITION_KEY, JSON.stringify({ left: left, top: top }));
        } catch (e) {}
    }

    function clearPosition() {
        try {
            global.sessionStorage.removeItem(POSITION_KEY);
            global.sessionStorage.removeItem(LEGACY_POSITION_KEY);
        } catch (e) {}
    }

    function clampPosition(left, top, root) {
        var width = (root && root.offsetWidth) || 380;
        var height = (root && root.offsetHeight) || 240;
        var maxLeft = Math.max(0, (global.innerWidth || width) - width);
        var maxTop = Math.max(0, (global.innerHeight || height) - height);
        return {
            left: Math.min(Math.max(0, left), maxLeft),
            top: Math.min(Math.max(0, top), maxTop)
        };
    }

    function applyPosition(root) {
        if (!root) return;
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

    function normalizeBucket(bucket, fallbackPath) {
        if (!bucket || typeof bucket !== 'object') return null;
        return {
            path: text(bucket.path || fallbackPath || ''),
            rows: Array.isArray(bucket.rows) ? bucket.rows.slice() : [],
            updatedAt: Number(bucket.updatedAt) || Date.now()
        };
    }

    function readHistory() {
        try {
            var raw = global.sessionStorage.getItem(HISTORY_KEY)
                || global.sessionStorage.getItem(LEGACY_HISTORY_KEY);
            if (!raw) return { current: null, previous: null };
            var parsed = JSON.parse(raw);
            return {
                current: normalizeBucket(parsed && parsed.current),
                previous: normalizeBucket(parsed && parsed.previous)
            };
        } catch (e) {
            return { current: null, previous: null };
        }
    }

    function writeHistory(current, previous) {
        try {
            global.sessionStorage.setItem(HISTORY_KEY, JSON.stringify({
                current: normalizeBucket(current),
                previous: normalizeBucket(previous)
            }));
        } catch (e) {}
    }

    function clearHistoryStorage() {
        try {
            global.sessionStorage.removeItem(HISTORY_KEY);
            global.sessionStorage.removeItem(LEGACY_HISTORY_KEY);
        } catch (e) {}
    }

    function readChainLog() {
        try {
            var raw = global.sessionStorage.getItem(CHAIN_KEY);
            if (!raw) return [];
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function writeChainLog(rows) {
        try {
            global.sessionStorage.setItem(CHAIN_KEY, JSON.stringify(Array.isArray(rows) ? rows.slice(0, MAX_CHAIN_ROWS) : []));
        } catch (e) {}
    }

    function clearChainStorage() {
        try {
            global.sessionStorage.removeItem(CHAIN_KEY);
        } catch (e) {}
    }

    function hydrateChainLog() {
        state.chainRows = readChainLog();
        state.chainSeenIds = {};
        state.chainRows.forEach(function (row) {
            if (row && row.id) state.chainSeenIds[row.id] = 1;
        });
    }

    function isChainRow(row) {
        if (!row) return false;
        if (row.chain) return true;
        var source = text(row.source).toLowerCase();
        if (source === 'chain') return true;
        var name = text(row.name || row.event_name);
        return name.indexOf('weline:event-chain') === 0;
    }

    function normalizeTab(tab) {
        tab = text(tab);
        return TAB_IDS[tab] ? tab : 'stream';
    }

    function persistCurrent() {
        writeHistory({
            path: state.pageKey || currentPageKey(),
            rows: state.rows,
            updatedAt: Date.now()
        }, state.previous);
        writeChainLog(state.chainRows);
    }

    function hydratePageWindow() {
        var key = currentPageKey();
        var hist = readHistory();
        if (hist.current && hist.current.path && hist.current.path !== key) {
            state.previous = hist.current;
            state.rows = [];
            state.seenIds = {};
            state.expanded = {};
        } else if (hist.current && hist.current.path === key) {
            state.rows = Array.isArray(hist.current.rows) ? hist.current.rows.slice() : [];
            state.previous = hist.previous;
            state.seenIds = {};
            state.rows.forEach(function (row) {
                if (row && row.id) state.seenIds[row.id] = 1;
            });
        } else {
            state.previous = hist.previous;
            state.rows = [];
            state.seenIds = {};
        }
        state.pageKey = key;
        hydrateChainLog();
        persistCurrent();
    }

    function ensureStyle() {
        var existing = document.querySelector('style[' + STYLE_ATTR + ']');
        if (existing && existing.getAttribute(STYLE_ATTR) === STYLE_VERSION) {
            return;
        }
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
        var style = document.createElement('style');
        style.setAttribute(STYLE_ATTR, STYLE_VERSION);
        style.textContent = [
            '#' + ROOT_ID + '{position:fixed;z-index:2147483000;width:min(420px,calc(100vw - 24px));max-height:min(70vh,640px);display:flex;flex-direction:column;',
            'background:var(--weline-color-surface,rgba(15,23,42,.96));color:var(--weline-color-on-surface,#e2e8f0);',
            'border:1px solid var(--weline-color-border,rgba(148,163,184,.35));border-radius:12px;',
            'box-shadow:0 18px 48px rgba(0,0,0,.35);font:13px/1.45 system-ui,-apple-system,sans-serif}',
            '#' + ROOT_ID + ' .wesm-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:10px 12px;cursor:grab;',
            'border-bottom:1px solid var(--weline-color-border,rgba(148,163,184,.25));user-select:none}',
            '#' + ROOT_ID + ' .wesm-title{font-weight:700;font-size:14px}',
            '#' + ROOT_ID + ' .wesm-rev{display:inline-block;max-width:min(70vw,360px);overflow:hidden;text-overflow:ellipsis;vertical-align:bottom;margin-inline-start:6px;font-weight:600;font-size:11px;opacity:.9;color:var(--weline-color-info,#38bdf8)}',
            '#' + ROOT_ID + ' .wesm-meta{opacity:.75;font-size:12px;margin-top:2px}',
            '#' + ROOT_ID + ' .wesm-close{border:0;background:transparent;color:inherit;font-size:20px;line-height:1;cursor:pointer;padding:0 4px}',
            '#' + ROOT_ID + ' .wesm-tabs{display:flex;flex-wrap:wrap;gap:4px;padding:8px 10px 0}',
            '#' + ROOT_ID + ' .wesm-tab{border:1px solid var(--weline-color-border,rgba(148,163,184,.3));background:transparent;color:inherit;',
            'border-radius:999px;padding:4px 8px;font-size:11px;cursor:pointer;line-height:1.3}',
            '#' + ROOT_ID + ' .wesm-tab[data-active="1"]{background:var(--weline-color-primary,#2563eb);border-color:transparent;color:#fff}',
            '#' + ROOT_ID + ' .wesm-body{overflow:auto;padding:8px 10px 12px;flex:1}',
            '#' + ROOT_ID + ' .wesm-sec{margin:10px 0 6px;font-size:12px;opacity:.8;font-weight:600}',
            '#' + ROOT_ID + ' .wesm-empty{opacity:.6;padding:12px 4px;font-size:12px}',
            '#' + ROOT_ID + ' .wesm-row{border:1px solid var(--weline-color-border,rgba(148,163,184,.2));border-radius:8px;padding:8px;margin:0 0 6px}',
            '#' + ROOT_ID + ' .wesm-row__bar{cursor:pointer;border-radius:6px;margin:-2px;padding:2px}',
            '#' + ROOT_ID + ' .wesm-row__bar:hover{background:rgba(255,255,255,.04)}',
            '#' + ROOT_ID + ' .wesm-row__params{cursor:text;user-select:text;-webkit-user-select:text}',
            '#' + ROOT_ID + ' .wesm-row[data-tone="hit-system"]{border-color:var(--weline-color-success,#22c55e);background:color-mix(in srgb,var(--weline-color-success,#22c55e) 16%,transparent)}',
            '#' + ROOT_ID + ' .wesm-row[data-tone="hit-custom"]{border-color:var(--weline-color-info,#3b82f6);background:color-mix(in srgb,var(--weline-color-info,#3b82f6) 16%,transparent)}',
            '#' + ROOT_ID + ' .wesm-row[data-tone="hit-dedupe"]{border-color:var(--weline-color-warning,#eab308);background:color-mix(in srgb,var(--weline-color-warning,#eab308) 18%,transparent)}',
            '#' + ROOT_ID + ' .wesm-row[data-tone="hit"]{border-color:var(--weline-color-success,#22c55e);background:color-mix(in srgb,var(--weline-color-success,#22c55e) 16%,transparent)}',
            '#' + ROOT_ID + ' .wesm-row[data-tone="anomaly"]{border-color:var(--weline-color-primary,#f59e0b);background:color-mix(in srgb,var(--weline-color-primary,#f59e0b) 18%,transparent)}',
            '#' + ROOT_ID + ' .wesm-row__top{display:flex;justify-content:space-between;gap:8px;align-items:baseline}',
            '#' + ROOT_ID + ' .wesm-row__name{font-weight:600;word-break:break-all}',
            '#' + ROOT_ID + ' .wesm-row__badge{font-size:11px;opacity:.85;white-space:nowrap}',
            '#' + ROOT_ID + ' .wesm-row__meta{font-size:11px;opacity:.7;margin-top:2px}',
            '#' + ROOT_ID + ' .wesm-row__params{margin-top:6px;padding:6px;border-radius:6px;background:rgba(0,0,0,.25);',
            'font-size:11px;max-height:180px;overflow:auto;display:flex;flex-direction:column;gap:4px}',
            '#' + ROOT_ID + ' .wesm-row__params[data-collapsed="1"]{max-height:4.8em}',
            '#' + ROOT_ID + ' .wesm-kv{display:grid;grid-template-columns:minmax(72px,34%) 1fr;gap:4px 6px;align-items:start}',
            '#' + ROOT_ID + ' .wesm-kv__key,'
            + '#' + ROOT_ID + ' .wesm-kv__val{border:0;background:transparent;color:inherit;text-align:left;padding:2px 4px;border-radius:4px;',
            'font:inherit;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;cursor:copy;word-break:break-all;position:relative}',
            '#' + ROOT_ID + ' .wesm-kv__key{font-weight:600;opacity:.9}',
            '#' + ROOT_ID + ' .wesm-kv__val{opacity:.8}',
            '#' + ROOT_ID + ' .wesm-kv__key:hover,'
            + '#' + ROOT_ID + ' .wesm-kv__val:hover{background:rgba(255,255,255,.08)}',
            '#' + ROOT_ID + ' .wesm-kv__key[data-copied="1"],'
            + '#' + ROOT_ID + ' .wesm-kv__val[data-copied="1"]{outline:1px solid var(--weline-color-success,#22c55e)}',
            '#' + ROOT_ID + ' .wesm-copy-tip{position:absolute;z-index:2;left:50%;bottom:calc(100% + 4px);transform:translateX(-50%);',
            'padding:2px 8px;border-radius:999px;white-space:nowrap;pointer-events:none;font:11px/1.3 system-ui,-apple-system,sans-serif;',
            'background:var(--weline-color-success,#22c55e);color:var(--weline-color-on-success,#052e16);',
            'box-shadow:0 4px 12px rgba(0,0,0,.25);opacity:1;transition:opacity .2s ease}',
            '#' + ROOT_ID + ' .wesm-copy-tip[data-fade="1"]{opacity:0}',
            '#' + ROOT_ID + ' .wesm-params-hint{opacity:.55;font-size:10px;margin:0 0 2px}',
            '#' + ROOT_ID + ' .wesm-chain{display:flex;flex-direction:column;gap:4px}',
            '#' + ROOT_ID + ' .wesm-step{display:flex;gap:8px;align-items:center;font-size:12px;padding:4px 0}',
            '#' + ROOT_ID + ' .wesm-step-mark{width:18px;height:18px;border-radius:50%;border:1px solid rgba(148,163,184,.5);display:inline-flex;align-items:center;justify-content:center;font-size:10px}',
            '#' + ROOT_ID + ' .wesm-step.is-done .wesm-step-mark{background:var(--weline-color-success,#22c55e);border-color:transparent;color:#052e16}',
            '#' + ROOT_ID + ' .wesm-foot{padding:6px 12px 10px;font-size:11px;opacity:.55;border-top:1px solid var(--weline-color-border,rgba(148,163,184,.2))}'
        ].join('');
        document.head.appendChild(style);
    }

    function rowTone(row) {
        if (row.anomaly || (row.missing && row.missing.length)) return 'anomaly';
        if (!row.event_hit) return 'plain';
        var kind = String(row.hit_kind || '').toLowerCase();
        if (kind === 'custom') return 'hit-custom';
        if (kind === 'system') return 'hit-system';
        if (kind === 'dedupe') return 'hit-dedupe';
        // 兼容旧 envelope：无 hit_kind 时按自定义蓝（历史文案曾一律「自定义」）
        return 'hit-custom';
    }

    function hitBadgeLabel(row, tone) {
        var label = text(row.event_name || row.name);
        if (tone === 'hit-system') return '系统事件 · ' + label;
        if (tone === 'hit-dedupe') return '去重丢弃 · ' + label;
        if (tone === 'hit-custom' || tone === 'hit') return '自定义事件 · ' + label;
        return '流过';
    }

    function paramEntries(params) {
        var out = [];
        if (!params || typeof params !== 'object' || Array.isArray(params)) {
            return out;
        }
        Object.keys(params).forEach(function (key) {
            var raw = params[key];
            var value = '';
            if (raw === null || raw === undefined) {
                value = '';
            } else if (typeof raw === 'object') {
                try {
                    value = JSON.stringify(raw);
                } catch (eJson) {
                    value = String(raw);
                }
            } else {
                value = String(raw);
            }
            out.push({ key: String(key), value: value });
        });
        return out;
    }

    function copyText(value) {
        var str = String(value == null ? '' : value);
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(str).catch(function () {
                return fallbackCopy(str);
            });
        }
        return Promise.resolve(fallbackCopy(str));
    }

    function fallbackCopy(str) {
        try {
            var ta = document.createElement('textarea');
            ta.value = str;
            ta.setAttribute('readonly', 'readonly');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            return false;
        }
    }

    function flashCopied(el) {
        if (!el) return;
        el.setAttribute('data-copied', '1');
        var prev = el.getAttribute('title') || '';
        el.setAttribute('title', '已复制');
        // 清掉同按钮上一次未散尽的 tip
        var oldTips = el.querySelectorAll('.wesm-copy-tip');
        for (var i = 0; i < oldTips.length; i++) {
            if (oldTips[i].parentNode) oldTips[i].parentNode.removeChild(oldTips[i]);
        }
        var tip = document.createElement('span');
        tip.className = 'wesm-copy-tip';
        tip.setAttribute('data-testid', 'wesm-copy-tip');
        tip.setAttribute('role', 'status');
        tip.textContent = '已复制';
        // 靠近按钮：默认在上方；若贴顶则翻到下方
        try {
            var root = document.getElementById(ROOT_ID);
            var tipRectApproxTop = el.getBoundingClientRect().top;
            var rootTop = root ? root.getBoundingClientRect().top : 0;
            if (tipRectApproxTop - rootTop < 28) {
                tip.style.bottom = 'auto';
                tip.style.top = 'calc(100% + 4px)';
            }
        } catch (ePos) {}
        el.appendChild(tip);
        if (el.__wesmCopyTipTimer) {
            clearTimeout(el.__wesmCopyTipTimer);
        }
        el.__wesmCopyTipTimer = setTimeout(function () {
            tip.setAttribute('data-fade', '1');
            setTimeout(function () {
                if (tip.parentNode) tip.parentNode.removeChild(tip);
                el.removeAttribute('data-copied');
                if (prev) el.setAttribute('title', prev);
                else el.removeAttribute('title');
            }, 200);
        }, 1000);
    }

    function paramsHtmlFor(row, open) {
        var entries = paramEntries(row.params);
        if (!entries.length) {
            return '';
        }
        var limit = open ? entries.length : Math.min(entries.length, 3);
        var rows = entries.slice(0, limit).map(function (item) {
            return '<div class="wesm-kv">'
                + '<button type="button" class="wesm-kv__key" data-wesm-copy="' + esc(item.key) + '" data-wesm-copy-kind="name" title="复制参数名">'
                + esc(item.key) + '</button>'
                + '<button type="button" class="wesm-kv__val" data-wesm-copy="' + esc(item.value) + '" data-wesm-copy-kind="value" title="复制参数值">'
                + esc(item.value === '' ? '∅' : item.value) + '</button>'
                + '</div>';
        }).join('');
        var more = (!open && entries.length > 3)
            ? ('<div class="wesm-params-hint">还有 ' + (entries.length - 3) + ' 个参数，点事件栏展开</div>')
            : '<div class="wesm-params-hint">点参数名/值复制 · 可双击选中 · 再点事件栏收起</div>';
        return '<div class="wesm-row__params"' + (open ? '' : ' data-collapsed="1"') + ' data-testid="wesm-params">'
            + more + rows + '</div>';
    }

    function streamRowsHtml(rows) {
        if (!rows || !rows.length) {
            return '<div class="wesm-empty">尚无动作流入沙盒。点击页面或触发 Pixel 事件后会出现在此。</div>';
        }
        return rows.map(function (row, idx) {
            var tone = rowTone(row);
            var open = !!state.expanded[row.id || idx];
            var badge = tone === 'anomaly'
                ? ('异常' + (row.missing && row.missing.length ? (' · ' + row.missing.join(',')) : ''))
                : hitBadgeLabel(row, tone);
            var hitNote = '';
            if (tone === 'anomaly' && row.event_hit) {
                var kind = String(row.hit_kind || '').toLowerCase() === 'system' ? '系统' : '自定义';
                hitNote = ' · 已命中' + kind + ' ' + text(row.event_name || row.name);
            }
            return [
                '<div class="wesm-row" data-tone="' + tone + '">',
                '<div class="wesm-row__bar" data-wesm-expand="' + esc(row.id || String(idx)) + '" title="' + (open ? '再点事件栏收起' : '点事件栏展开参数') + '">',
                '<div class="wesm-row__top"><span class="wesm-row__name">' + esc(row.name) + '</span>',
                '<span class="wesm-row__badge">' + esc(badge + hitNote) + '</span></div>',
                '<div class="wesm-row__meta">' + esc(row.at) + ' · ' + esc(row.source || '') + '</div>',
                '</div>',
                paramsHtmlFor(row, open),
                '</div>'
            ].join('');
        }).join('');
    }

    function lifecycleChainHtml(rows) {
        var names = {};
        (rows || []).forEach(function (row) {
            if (row && row.name) names[row.name] = true;
        });
        var any = LIFECYCLE_CHAIN.some(function (step) {
            return step.match.some(function (m) { return names[m]; });
        });
        if (!any) {
            return '<div class="wesm-empty">本页尚未出现结账/支付生命周期事件。</div>';
        }
        return '<div class="wesm-chain">' + LIFECYCLE_CHAIN.map(function (step) {
            var done = step.match.some(function (m) { return names[m]; });
            return '<div class="wesm-step' + (done ? ' is-done' : '') + '">'
                + '<span class="wesm-step-mark">' + (done ? '✓' : '') + '</span>'
                + '<span>' + esc(step.label) + '</span></div>';
        }).join('') + '</div>';
    }

    function advancedChainHtml() {
        var api = global.WelineEventChains;
        if (!api || typeof api.defs !== 'function') {
            return '<div class="wesm-empty">高级事件链运行时未加载。</div>';
        }
        var defs = api.defs() || [];
        var prog = typeof api.progress === 'function' ? (api.progress() || {}) : {};
        if (!defs.length) {
            return '<div class="wesm-empty">未配置高级事件链。</div>';
        }
        return '<div class="wesm-chain">' + defs.map(function (chain) {
            var st = prog[chain.id] || { i: 0 };
            var i = parseInt(st.i, 10) || 0;
            var total = (chain.steps && chain.steps.length) || 0;
            return '<div class="wesm-step' + (i >= total && total ? ' is-done' : '') + '">'
                + '<span class="wesm-step-mark">' + (i >= total && total ? '✓' : i) + '</span>'
                + '<span>' + esc(chain.name || chain.id) + ' · ' + i + '/' + total + '</span></div>';
        }).join('') + '</div>';
    }

    function hitAggregate(rows, kind) {
        var counts = {};
        (rows || []).forEach(function (row) {
            if (!row || !row.event_hit) return;
            if (row.anomaly || (row.missing && row.missing.length)) return;
            var hk = String(row.hit_kind || '').toLowerCase();
            var isSystem = hk === 'system';
            var isDedupe = hk === 'dedupe';
            var isCustom = !isSystem && !isDedupe; // 空 hit_kind 的历史命中按自定义
            if (kind === 'system' && !isSystem) return;
            if (kind === 'custom' && !isCustom) return;
            if (kind === 'dedupe' && !isDedupe) return;
            var name = text(row.event_name || row.name);
            if (!name) return;
            counts[name] = (counts[name] || 0) + 1;
        });
        return Object.keys(counts).sort().map(function (name) {
            return { name: name, count: counts[name] };
        });
    }

    function hitListHtml(hits, emptyText, tone) {
        if (!hits || !hits.length) {
            return '<div class="wesm-empty">' + esc(emptyText) + '</div>';
        }
        return hits.map(function (h) {
            return '<div class="wesm-row" data-tone="' + tone + '">'
                + '<div class="wesm-row__top"><span class="wesm-row__name">' + esc(h.name) + '</span>'
                + '<span class="wesm-row__badge">×' + h.count + '</span></div></div>';
        }).join('');
    }

    function render() {
        var root = document.getElementById(ROOT_ID);
        if (!root || !state.enabled) return;
        var hitSys = 0;
        var hitCustom = 0;
        var hitDedupe = 0;
        var bad = 0;
        state.rows.forEach(function (row) {
            if (row.anomaly || (row.missing && row.missing.length)) bad += 1;
            else if (row.event_hit) {
                var hk = String(row.hit_kind || '').toLowerCase();
                if (hk === 'system') hitSys += 1;
                else if (hk === 'dedupe') hitDedupe += 1;
                else hitCustom += 1;
            }
        });
        var prevCount = (state.previous && Array.isArray(state.previous.rows)) ? state.previous.rows.length : 0;
        var chainCount = Array.isArray(state.chainRows) ? state.chainRows.length : 0;
        var tab = normalizeTab(state.activeTab);
        state.activeTab = tab;
        var bodyHtml = '';
        if (tab === 'system') {
            bodyHtml = '<div class="wesm-sec">系统命中（字典/生命周期）</div>'
                + hitListHtml(hitAggregate(state.rows, 'system'), '本会话尚无系统事件命中。', 'hit-system');
        } else if (tab === 'custom') {
            bodyHtml = '<div class="wesm-sec">自定义命中</div>'
                + hitListHtml(hitAggregate(state.rows, 'custom'), '本会话尚无自定义事件命中。', 'hit-custom');
        } else if (tab === 'dedupe') {
            bodyHtml = '<div class="wesm-sec">去重丢弃（本地/约定参数拦截，未上报）</div>'
                + hitListHtml(hitAggregate(state.rows, 'dedupe'), '本会话尚无去重丢弃。', 'hit-dedupe');
        } else if (tab === 'previous') {
            bodyHtml = '<div class="wesm-sec">上一页事件'
                + (state.previous && state.previous.path ? (' · ' + esc(state.previous.path)) : '')
                + '</div>'
                + (prevCount
                    ? streamRowsHtml(state.previous.rows)
                    : '<div class="wesm-empty">尚无上一页记录。换页后会自动保存上一页事件。</div>');
        } else if (tab === 'chain') {
            bodyHtml = '<div class="wesm-sec">累积链监听（跨页累积，不改数据流内进度条）</div>'
                + '<div class="wesm-sec">当前链进度</div>' + advancedChainHtml()
                + '<div class="wesm-sec">链事件流水</div>'
                + (chainCount
                    ? streamRowsHtml(state.chainRows)
                    : '<div class="wesm-empty">尚无链步骤/完成事件。触发高级事件链后会出现在此并跨页保留。</div>');
        } else {
            bodyHtml = '<div class="wesm-sec">全量数据流（含参数）</div>'
                + streamRowsHtml(state.rows)
                + '<div class="wesm-sec">生命周期链</div>' + lifecycleChainHtml(state.rows)
                + '<div class="wesm-sec">高级事件链</div>' + advancedChainHtml();
        }
        root.innerHTML = [
            '<div class="wesm-head" data-wesm-drag="1">',
            '<div><div class="wesm-title">事件监视 <span class="wesm-rev" data-testid="wesm-config-revision" title="站点·店铺·渠道·配置版本（与后台对齐）">' + esc(formatScopeVersionLabel()) + '</span></div>',
            '<div class="wesm-meta">沙盒流 · ' + esc(formatScopeVersionLabel()) + ' · ' + state.rows.length + ' 条 · 系统 ' + hitSys + ' · 自定义 ' + hitCustom
                + ' · 去重 ' + hitDedupe + ' · 上一页 ' + prevCount + ' · 链 ' + chainCount + ' · 异常 ' + bad + '</div></div>',
            '<button type="button" class="wesm-close" data-wesm-action="close" aria-label="关闭事件监视" title="关闭并清理">×</button>',
            '</div>',
            '<div class="wesm-tabs">',
            '<button type="button" class="wesm-tab" data-wesm-action="tab" data-wesm-tab="system" data-active="' + (tab === 'system' ? '1' : '0') + '">系统命中 ' + hitSys + '</button>',
            '<button type="button" class="wesm-tab" data-wesm-action="tab" data-wesm-tab="custom" data-active="' + (tab === 'custom' ? '1' : '0') + '">自定义命中 ' + hitCustom + '</button>',
            '<button type="button" class="wesm-tab" data-wesm-action="tab" data-wesm-tab="dedupe" data-active="' + (tab === 'dedupe' ? '1' : '0') + '">去重丢弃 ' + hitDedupe + '</button>',
            '<button type="button" class="wesm-tab" data-wesm-action="tab" data-wesm-tab="previous" data-active="' + (tab === 'previous' ? '1' : '0') + '" title="保存的上一页事件">上一页 ' + prevCount + '</button>',

            '<button type="button" class="wesm-tab" data-wesm-action="tab" data-wesm-tab="chain" data-active="' + (tab === 'chain' ? '1' : '0') + '" title="累积链监听">累积链 ' + chainCount + '</button>',
            '<button type="button" class="wesm-tab" data-wesm-action="tab" data-wesm-tab="stream" data-active="' + (tab === 'stream' ? '1' : '0') + '">数据流 ' + state.rows.length + '</button>',
            '</div>',
            '<div class="wesm-body">',
            bodyHtml,
            '</div>',
            '<div class="wesm-foot">可拖动标题栏；点 × 关闭并清理。上一页/累积链跨页保存于本会话。数据流内高级事件链进度条保留。绿=系统命中，蓝=自定义命中，黄=去重丢弃（记流不发）。</div>'
        ].join('');
        applyPosition(root);
        bindChrome(root);
    }

    function bindChrome(root) {
        if (!root || root.__wesmChromeBound) return;
        root.__wesmChromeBound = true;
        var drag = { active: false, originX: 0, originY: 0, startLeft: 0, startTop: 0 };

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) return;
            var closeBtn = target.closest('[data-wesm-action="close"]');
            if (closeBtn && root.contains(closeBtn)) {
                event.preventDefault();
                disable();
                return;
            }
            var tabBtn = target.closest('[data-wesm-action="tab"]');
            if (tabBtn && root.contains(tabBtn)) {
                event.preventDefault();
                state.activeTab = normalizeTab(tabBtn.getAttribute('data-wesm-tab'));
                render();
                return;
            }
            var copyBtn = target.closest('[data-wesm-copy]');
            if (copyBtn && root.contains(copyBtn)) {
                event.preventDefault();
                event.stopPropagation();
                var payload = copyBtn.getAttribute('data-wesm-copy');
                if (payload == null) payload = '';
                copyText(payload).then(function () {
                    flashCopied(copyBtn);
                });
                return;
            }
            // 参数区仅供选中/复制，不触发展开收起
            if (target.closest('.wesm-row__params')) {
                return;
            }
            var expand = target.closest('[data-wesm-expand]');
            if (expand && root.contains(expand)) {
                event.preventDefault();
                var id = text(expand.getAttribute('data-wesm-expand'));
                state.expanded[id] = !state.expanded[id];
                render();
            }
        });

        root.addEventListener('pointerdown', function (event) {
            var target = event.target;
            if (!target || !target.closest) return;
            if (!target.closest('[data-wesm-drag]')) return;
            if (target.closest('[data-wesm-action]')) return;
            drag.active = true;
            drag.originX = event.clientX;
            drag.originY = event.clientY;
            var rect = root.getBoundingClientRect();
            drag.startLeft = rect.left;
            drag.startTop = rect.top;
            root.style.right = 'auto';
            root.style.bottom = 'auto';
            root.style.left = drag.startLeft + 'px';
            root.style.top = drag.startTop + 'px';
            try { root.setPointerCapture(event.pointerId); } catch (eCap) {}
        });
        root.addEventListener('pointermove', function (event) {
            if (!drag.active) return;
            var next = clampPosition(
                drag.startLeft + (event.clientX - drag.originX),
                drag.startTop + (event.clientY - drag.originY),
                root
            );
            root.style.left = next.left + 'px';
            root.style.top = next.top + 'px';
        });
        root.addEventListener('pointerup', function (event) {
            if (!drag.active) return;
            drag.active = false;
            var left = parseFloat(root.style.left) || 0;
            var top = parseFloat(root.style.top) || 0;
            writePosition(left, top);
            try { root.releasePointerCapture(event.pointerId); } catch (eRel) {}
        });
    }

    function onEnvelope(envelope) {
        if (!state.enabled || !envelope) return;
        var id = text(envelope.id);
        if (id && state.seenIds[id]) return;
        if (id) state.seenIds[id] = 1;
        var page = currentPageKey();
        if (page !== state.pageKey) {
            hydratePageWindow();
        }
        var row = {
            id: id || ('row-' + Date.now()),
            at: nowTs(),
            name: text(envelope.name || 'event'),
            event_name: text(envelope.event_name || ''),
            source: text(envelope.source || ''),
            params: envelope.params || {},
            missing: Array.isArray(envelope.missing) ? envelope.missing : [],
            anomaly: !!envelope.anomaly,
            event_hit: !!envelope.event_hit,
            hit_kind: text(envelope.hit_kind || ''),
            chain: envelope.chain || null,
            page: page
        };
        state.rows.unshift(row);
        if (state.rows.length > MAX_ROWS) {
            state.rows = state.rows.slice(0, MAX_ROWS);
        }
        if (isChainRow(row)) {
            var cid = row.id;
            if (!(cid && state.chainSeenIds[cid])) {
                if (cid) state.chainSeenIds[cid] = 1;
                state.chainRows.unshift(row);
                if (state.chainRows.length > MAX_CHAIN_ROWS) {
                    state.chainRows = state.chainRows.slice(0, MAX_CHAIN_ROWS);
                }
            }
        }
        persistCurrent();
        render();
    }

    function waitSandbox(cb) {
        var n = 0;
        function tick() {
            var sb = global.WelineEventSandbox || global.WelinePixelSandbox;
            if (sb && typeof sb.subscribe === 'function') {
                cb(sb);
                return;
            }
            n += 1;
            if (n > 80) {
                console.warn('[WelineEventSandboxMonitor] sandbox subscribe unavailable');
                return;
            }
            setTimeout(tick, 100);
        }
        tick();
    }

    function onSandboxCustomEvent(ev) {
        try {
            onEnvelope(ev && ev.detail);
        } catch (eCust) {}
    }

    function bindStream() {
        if (state.unsub) {
            try { state.unsub(); } catch (e) {}
            state.unsub = null;
        }
        try {
            global.removeEventListener('weline:pixel-sandbox:event', onSandboxCustomEvent);
        } catch (eRm) {}
        // CustomEvent 兜底：subscribe 竞态或沙盒对象被替换时仍能进监视流
        try {
            global.addEventListener('weline:pixel-sandbox:event', onSandboxCustomEvent);
        } catch (eAdd) {}
        waitSandbox(function (sb) {
            state.unsub = sb.subscribe(onEnvelope);
            // 订阅后再扫一次环形缓冲（兼容旧 subscribe 无回放）
            try {
                var buf = Array.isArray(sb._earlyBuffer) ? sb._earlyBuffer.slice() : [];
                for (var i = 0; i < buf.length; i++) {
                    onEnvelope(buf[i]);
                }
            } catch (eBuf) {}
        });
    }

    function bindPanelWatch() {
        if (state.panelWatchBound) return;
        state.panelWatchBound = true;
        global.addEventListener(PANEL_COLLAPSE_EVENT, function () {
            if (!state.enabled || state.clearingFromPanel) return;
            state.clearingFromPanel = true;
            try { disable(); } finally { state.clearingFromPanel = false; }
        });
    }

    function mount() {
        ensureStyle();
        setConfigRevision(readConfigRevision());
        var root = document.getElementById(ROOT_ID);
        if (!root) {
            root = document.createElement('div');
            root.id = ROOT_ID;
            root.setAttribute('data-testid', 'event-sandbox-monitor');
            (document.body || document.documentElement).appendChild(root);
        }
        state.mounted = true;
        hydratePageWindow();
        bindStream();
        bindPanelWatch();
        bindConfigRevisionWatch();
        render();
    }

    function bindConfigRevisionWatch() {
        if (state.configRevWatchBound) {
            return;
        }
        state.configRevWatchBound = true;
        global.addEventListener('weline:visitor-tracking-config', function (ev) {
            try {
                var detail = ev && ev.detail ? ev.detail : {};
                setConfigRevision(detail.configRevision || detail.config_revision);
                if (state.mounted) {
                    render();
                }
            } catch (e) {}
        });
        global.addEventListener('weline:visitor-tracking-config-applied', function (ev) {
            try {
                var detail = ev && ev.detail ? ev.detail : {};
                setConfigRevision(detail.configRevision || detail.config_revision);
                if (state.mounted) {
                    render();
                }
            } catch (e2) {}
        });
    }

    function enable() {
        state.enabled = true;
        writeEnabled(true);
        if (document.body) {
            mount();
        } else {
            document.addEventListener('DOMContentLoaded', mount, { once: true });
        }
        return api;
    }

    function disable() {
        state.enabled = false;
        writeEnabled(false);
        if (state.unsub) {
            try { state.unsub(); } catch (e) {}
            state.unsub = null;
        }
        state.rows = [];
        state.previous = null;
        state.chainRows = [];
        state.seenIds = {};
        state.chainSeenIds = {};
        state.expanded = {};
        clearHistoryStorage();
        clearChainStorage();
        clearPosition();
        var root = document.getElementById(ROOT_ID);
        if (root && root.parentNode) {
            root.parentNode.removeChild(root);
        }
        state.mounted = false;
        return api;
    }

    function isEnabled() {
        return !!state.enabled || readEnabled();
    }

    var api = {
        enable: enable,
        disable: disable,
        isEnabled: isEnabled,
        render: render,
        getConfigRevision: readConfigRevision,
        setConfigRevision: setConfigRevision,
        version: '20260916-event-sandbox-monitor10'
    };

    global.WelineEventSandboxMonitor = api;

    // One-cycle thin alias for prior lifecycle assistant entry.
    global.WelineLifecycleAssistant = {
        enable: function () { return api.enable(); },
        disable: function () { return api.disable(); },
        isEnabled: function () { return api.isEnabled(); }
    };

    if (readEnabled()) {
        if (document.body) {
            enable();
        } else {
            document.addEventListener('DOMContentLoaded', function () { enable(); }, { once: true });
        }
    }
})(window, document);
