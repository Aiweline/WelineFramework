/**
 * Weline DevConsole — friendly BinQuery / API console printer.
 *
 * Loaded only when:
 *   - PHP DEV / window.DEV / WELINE_ENV=DEV, or
 *   - explicit: window.WELINE_DEV_CONSOLE = true
 *   - explicit: localStorage.weline_dev_console = '1'
 *   - explicit: ?weline_dev_console=1
 *   - Weline.enableDevConsole()
 *
 * Production stays silent unless explicitly enabled.
 */
(function (global) {
    'use strict';

    var Weline = (global.Weline = global.Weline || {});
    if (Weline.DevConsole && Weline.DevConsole.__welineDevConsole) {
        return;
    }

    var STYLE = {
        okBadge: 'background:#14532d;color:#bbf7d0;padding:1px 7px;border-radius:999px;font-weight:700;font-size:11px',
        errBadge: 'background:#7f1d1d;color:#fecaca;padding:1px 7px;border-radius:999px;font-weight:700;font-size:11px',
        warnBadge: 'background:#713f12;color:#fde68a;padding:1px 7px;border-radius:999px;font-weight:700;font-size:11px',
        infoBadge: 'background:#1e3a5f;color:#bfdbfe;padding:1px 7px;border-radius:999px;font-weight:700;font-size:11px',
        cacheHitBadge: 'background:#0f766e;color:#ccfbf1;padding:1px 7px;border-radius:999px;font-weight:700;font-size:11px',
        cacheMissBadge: 'background:#334155;color:#e2e8f0;padding:1px 7px;border-radius:999px;font-weight:700;font-size:11px',
        title: 'color:#e2e8f0;font-weight:600',
        titleErr: 'color:#fca5a5;font-weight:700',
        meta: 'color:#94a3b8;font-weight:500',
        label: 'color:#64748b;font-weight:600',
    };

    function cloneValue(value) {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return value;
        }
    }

    function normalizeLocalCache(value) {
        var cache = String(value || '').trim().toLowerCase();
        if (!cache) {
            return '';
        }
        if (cache === 'hit' || cache === 'l1' || cache === 'inflight' || cache === 'miss'
            || cache === 'bypass' || cache === 'live' || cache === 'skip' || cache === 'unknown') {
            return cache === 'skip' ? 'live' : cache;
        }
        return cache.slice(0, 32);
    }

    function localCacheBadge(cache) {
        if (cache === 'hit' || cache === 'l1' || cache === 'inflight') {
            return { text: 'LOCAL ' + cache, style: STYLE.cacheHitBadge };
        }
        if (cache === 'miss' || cache === 'bypass') {
            return { text: 'NET ' + cache, style: STYLE.cacheMissBadge };
        }
        if (cache === 'live') {
            // Not cacheable — always hits the network (cart/coupon/pixel, etc.).
            return { text: 'NET live', style: STYLE.cacheMissBadge };
        }
        if (cache) {
            return { text: 'CACHE ' + cache, style: STYLE.infoBadge };
        }
        return null;
    }

    function printLine(level, badge, badgeStyle, title, titleStyle, meta, payload, extraBadge) {
        var prefix = '%c' + badge;
        var styles = [badgeStyle];
        if (extraBadge && extraBadge.text) {
            prefix += ' %c' + extraBadge.text;
            styles.push(extraBadge.style || STYLE.infoBadge);
        }
        prefix += '%c ' + String(title || '');
        styles.push(titleStyle || STYLE.title);
        if (meta) {
            prefix += ' %c' + meta;
            styles.push(STYLE.meta);
        }
        var args = [prefix].concat(styles);
        if (payload !== undefined) {
            args.push(payload);
        }
        var writer = level === 'error'
            ? console.error
            : (level === 'warn' ? console.warn : console.log);
        writer.apply(console, args);
    }

    function binQuery(meta) {
        meta = meta && typeof meta === 'object' ? meta : {};
        var ok = meta.ok === true;
        var summary = String(meta.summary || 'request');
        var status = meta.status != null && meta.status !== '' ? String(meta.status) : '';
        var wallMs = meta.durationMs != null ? Math.max(0, Number(meta.durationMs) || 0) : null;
        var workMs = meta.workerElapsedMs != null ? Math.max(0, Number(meta.workerElapsedMs) || 0) : null;
        var localCache = normalizeLocalCache(meta.localCache);
        var isLocal = localCache === 'hit' || localCache === 'l1' || localCache === 'inflight';
        var ms = '';
        if (wallMs != null && workMs != null && isLocal && wallMs > workMs + 5) {
            // Wall clock includes main-thread queue; cache work was much cheaper.
            ms = wallMs + 'ms wall · ' + workMs + 'ms cache';
        } else if (wallMs != null) {
            ms = wallMs + 'ms';
        }
        var metaText = [status, ms, localCache ? ('localCache:' + localCache) : ''].filter(Boolean).join(' · ');
        var payload = {
            endpoint: meta.endpoint || '',
            localCache: localCache || undefined,
            workerElapsedMs: workMs != null ? workMs : undefined,
            wallElapsedMs: wallMs != null ? wallMs : undefined,
            request: cloneValue(meta.request || {}),
            response: cloneValue(meta.response || {}),
        };

        printLine(
            ok ? 'log' : 'error',
            ok ? 'BinQuery OK' : 'BinQuery ERR',
            ok ? STYLE.okBadge : STYLE.errBadge,
            summary,
            ok ? STYLE.title : STYLE.titleErr,
            metaText,
            payload,
            localCacheBadge(localCache)
        );
    }

    function failed(label, error, extra) {
        printLine(
            'error',
            'BinQuery ERR',
            STYLE.errBadge,
            label || 'request failed',
            STYLE.titleErr,
            '',
            {
                error: error,
                response: error && error.response ? cloneValue(error.response) : undefined,
                detail: extra && typeof extra === 'object' ? cloneValue(extra) : extra,
            }
        );
    }

    function info(label, payload) {
        printLine('log', 'BinQuery', STYLE.infoBadge, label, STYLE.title, '', payload);
    }

    function warn(label, payload) {
        printLine('warn', 'BinQuery', STYLE.warnBadge, label, STYLE.title, '', payload);
    }

    var api = {
        __welineDevConsole: true,
        binQuery: binQuery,
        failed: failed,
        info: info,
        warn: warn,
        enabled: true,
    };

    Weline.DevConsole = api;
    global.WelineDevConsole = api;
})(typeof window !== 'undefined' ? window : this);
