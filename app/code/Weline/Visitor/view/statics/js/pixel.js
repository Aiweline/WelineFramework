(function() {
    // 防止重复加载像素代码
    if (window.__WelinePixelLoaded) {
        console.warn('Weline Pixel: 像素已加载，跳过重复加载。如需添加自定义事件，请使用像素hook方式（监听 Weline_Visitor::taglib_pixel 事件）');
        return;
    }
    window.__WelinePixelLoaded = true;

    var __visitorTrackingConfig = window.__WelineVisitorTrackingConfig || {};
    var __trackingRuntimeFetchInFlight = false;
    var __trackingRuntimeLastRevision = Number(
        (__visitorTrackingConfig && (__visitorTrackingConfig.configRevision || __visitorTrackingConfig.config_revision)) || 0
    ) || 0;

    function __applyVisitorTrackingConfig(next, opts) {
        opts = opts || {};
        if (!next || typeof next !== 'object') {
            return;
        }
        __visitorTrackingConfig = next;
        window.__WelineVisitorTrackingConfig = __visitorTrackingConfig;
        var rev = Number(next.configRevision || next.config_revision || 0) || 0;
        if (rev > 0) {
            __trackingRuntimeLastRevision = rev;
        }
        try {
            __syncCustomEventsLocalCacheFromRuntime(next, rev);
        } catch (eLs) {}
        __loadVisitorGa4();
        __loadVisitorGtm();
        __loadCustomVisitorForwarder();
        if (window.WelinePixelSandbox && typeof window.WelinePixelSandbox.rebuildVendorFrames === 'function') {
            window.WelinePixelSandbox.rebuildVendorFrames();
        }
        if (!opts.silent) {
            try {
                window.dispatchEvent(new CustomEvent('weline:visitor-tracking-config-applied', {
                    detail: { configRevision: __trackingRuntimeLastRevision }
                }));
            } catch (e) {}
        }
        try {
            __scheduleAutoDiscoverScan();
        } catch (eScan) {}
    }

    /**
     * 本范围自定义事件本地缓存（revision + events + pending_register）。
     * 全量覆盖只动 events，保留未完成 pending。
     */
    function __resolvePixelStorageScope() {
        try {
            var cfg0 = window.__WelineVisitorTrackingConfig || __visitorTrackingConfig || {};
            var storageScope = String(cfg0.storageScope || cfg0.storage_scope || '');
            if (!storageScope && window.__WelinePixelEnv) {
                var wc = String(window.__WelinePixelEnv.website_code || 'default');
                var sc = String(window.__WelinePixelEnv.store_code || 'default');
                var cc = String(window.__WelinePixelEnv.channel_code || 'default');
                storageScope = [wc, sc, cc].join('.');
            }
            return storageScope || 'default.default.default';
        } catch (e) {
            return 'default.default.default';
        }
    }

    function __customEventsLocalStorageKey(scope) {
        return 'weline-visitor-custom-events:' + String(scope || 'default');
    }

    function __readCustomEventsLocalCache(scope) {
        scope = scope || __resolvePixelStorageScope();
        try {
            var raw = window.localStorage.getItem(__customEventsLocalStorageKey(scope));
            if (!raw) {
                return { revision: 0, events: [], pending_register: [], updated_at: '' };
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') {
                return { revision: 0, events: [], pending_register: [], updated_at: '' };
            }
            return {
                revision: Number(parsed.revision || 0) || 0,
                events: Array.isArray(parsed.events) ? parsed.events : [],
                pending_register: Array.isArray(parsed.pending_register) ? parsed.pending_register : [],
                updated_at: String(parsed.updated_at || '')
            };
        } catch (e) {
            return { revision: 0, events: [], pending_register: [], updated_at: '' };
        }
    }

    function __writeCustomEventsLocalCache(scope, data) {
        scope = scope || __resolvePixelStorageScope();
        try {
            var payload = {
                revision: Number((data && data.revision) || 0) || 0,
                events: Array.isArray(data && data.events) ? data.events : [],
                pending_register: Array.isArray(data && data.pending_register) ? data.pending_register : [],
                updated_at: String((data && data.updated_at) || new Date().toISOString())
            };
            window.localStorage.setItem(__customEventsLocalStorageKey(scope), JSON.stringify(payload));
            return payload;
        } catch (e) {
            return data || { revision: 0, events: [], pending_register: [], updated_at: '' };
        }
    }

    function __normalizeAutoEventName(name) {
        return String(name || '').trim().toLowerCase().replace(/-/g, '_').replace(/[^a-z0-9_]/g, '').slice(0, 64);
    }

    function __isGenericAutoDiscoverName(name) {
        var n = __normalizeAutoEventName(name);
        return !n || [
            'click', 'page_view', 'page_enter', 'page_leave', 'page_exit', 'page_hide',
            'scroll', 'mousemove', 'mouseover', 'mouseout', 'hover',
            'focus', 'blur', 'input', 'change', 'submit', 'load', 'unload',
            'resize', 'keydown', 'keyup', 'keypress', 'touchstart', 'touchend',
            'visibilitychange', 'popstate', 'hashchange'
        ].indexOf(n) !== -1;
    }

    function __syncCustomEventsLocalCacheFromRuntime(config, rev) {
        var scope = String((config && (config.storageScope || config.storage_scope)) || __resolvePixelStorageScope());
        rev = Number(rev || (config && (config.configRevision || config.config_revision)) || 0) || 0;
        var prev = __readCustomEventsLocalCache(scope);
        var pools = [(config && config.customEvents) || null, (config && config.custom_events) || null];
        var events = [];
        var seen = {};
        for (var p = 0; p < pools.length; p++) {
            var custom = pools[p];
            if (!Array.isArray(custom)) {
                continue;
            }
            for (var i = 0; i < custom.length; i++) {
                var row = custom[i];
                var nm = '';
                var origin = 'manual';
                var deletable = true;
                var match_conditions = [];
                if (typeof row === 'string') {
                    nm = __normalizeAutoEventName(row);
                } else if (row && typeof row === 'object') {
                    nm = __normalizeAutoEventName(row.name || row.weline_event || row.event_name || '');
                    origin = String(row.origin || 'manual');
                    deletable = row.deletable !== false && origin !== 'auto_discovered';
                    match_conditions = Array.isArray(row.match_conditions) ? row.match_conditions : [];
                }
                if (!nm || seen[nm]) {
                    continue;
                }
                seen[nm] = true;
                events.push({
                    name: nm,
                    origin: origin === 'auto_discovered' ? 'auto_discovered' : 'manual',
                    deletable: !!deletable && origin !== 'auto_discovered',
                    match_conditions: match_conditions
                });
            }
        }
        if (rev > 0 && prev.revision === rev && events.length === 0 && prev.events.length) {
            // revision 未变且本次 payload 无事件列表时保留本地 events
            return prev;
        }
        var pending = (prev.pending_register || []).filter(function (n) {
            n = __normalizeAutoEventName(n);
            return n && !seen[n];
        });
        return __writeCustomEventsLocalCache(scope, {
            revision: rev > 0 ? rev : prev.revision,
            events: events,
            pending_register: pending,
            updated_at: new Date().toISOString()
        });
    }

    function __isSandboxMonitorActiveForAutoRegister() {
        try {
            if (window.WelinePixelSandbox && window.WelinePixelSandbox.monitor
                && typeof window.WelinePixelSandbox.monitor.isEnabled === 'function'
                && window.WelinePixelSandbox.monitor.isEnabled()) {
                return true;
            }
        } catch (e0) {}
        try {
            if (window.WelineEventSandboxMonitor
                && typeof window.WelineEventSandboxMonitor.isEnabled === 'function'
                && window.WelineEventSandboxMonitor.isEnabled()) {
                return true;
            }
        } catch (e1) {}
        try {
            return window.sessionStorage.getItem('weline_event_sandbox_monitor_v1') === '1'
                || window.sessionStorage.getItem('weline_lifecycle_assistant_v1') === '1';
        } catch (e2) {
            return false;
        }
    }

    var __autoDiscoverScanTimer = null;
    var __autoRegisterInFlight = {};

    function __scheduleAutoDiscoverScan() {
        if (!__isSandboxMonitorActiveForAutoRegister()) {
            return;
        }
        if (__autoDiscoverScanTimer) {
            return;
        }
        __autoDiscoverScanTimer = setTimeout(function () {
            __autoDiscoverScanTimer = null;
            try {
                __scanAndRegisterDeclaredPixelEvents();
            } catch (e) {}
        }, 80);
    }

    function __collectDeclaredPixelEventNames(root) {
        var out = {};
        var scopeRoot = root || document;
        if (!scopeRoot || !scopeRoot.querySelectorAll) {
            return out;
        }
        try {
            var nodes = scopeRoot.querySelectorAll('[class*="weline-pixel::"], [data-visitor-event], [data-pixel-event]');
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                var name = '';
                if (typeof __findPixelEventNameFromElement === 'function') {
                    name = __findPixelEventNameFromElement(el);
                }
                if (!name && el.getAttribute) {
                    name = el.getAttribute('data-visitor-event') || el.getAttribute('data-pixel-event') || '';
                }
                name = __normalizeAutoEventName(name);
                if (name && !__isGenericAutoDiscoverName(name)) {
                    out[name] = true;
                }
            }
        } catch (e) {}
        return out;
    }

    function __localKnownAutoEventNames(scope) {
        var cache = __readCustomEventsLocalCache(scope);
        var known = {};
        (cache.events || []).forEach(function (row) {
            var n = __normalizeAutoEventName(typeof row === 'string' ? row : (row && row.name));
            if (n) known[n] = true;
        });
        (cache.pending_register || []).forEach(function (n) {
            n = __normalizeAutoEventName(n);
            if (n) known[n] = true;
        });
        return { cache: cache, known: known };
    }

    function __maybeRegisterAutoDiscoveredEvent(name) {
        name = __normalizeAutoEventName(name);
        if (!name || __isGenericAutoDiscoverName(name)) {
            return;
        }
        if (!__isSandboxMonitorActiveForAutoRegister()) {
            return;
        }
        if (typeof __sandboxIsSystemDictEvent === 'function' && __sandboxIsSystemDictEvent(name)) {
            return;
        }
        var scope = __resolvePixelStorageScope();
        var pack = __localKnownAutoEventNames(scope);
        if (pack.known[name]) {
            return;
        }
        if (__autoRegisterInFlight[scope + ':' + name]) {
            return;
        }
        var pending = (pack.cache.pending_register || []).slice();
        if (pending.indexOf(name) === -1) {
            pending.push(name);
        }
        __writeCustomEventsLocalCache(scope, {
            revision: pack.cache.revision,
            events: pack.cache.events,
            pending_register: pending,
            updated_at: new Date().toISOString()
        });
        __autoRegisterInFlight[scope + ':' + name] = true;
        var websiteId = 0;
        try {
            websiteId = Number((window.__WelinePixelEnv && window.__WelinePixelEnv.website_id) || 0) || 0;
        } catch (eWid) {}
        var body = new URLSearchParams();
        body.set('sandbox', '1');
        body.set('register_auto', '1');
        body.set('website_id', String(websiteId));
        body.set('storage_scope', scope);
        body.set('weline_event', name);
        fetch('/visitor/analytics/event-picker/mapped', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            cache: 'no-store'
        }).then(function (r) { return r.json(); }).then(function (data) {
            delete __autoRegisterInFlight[scope + ':' + name];
            var cur = __readCustomEventsLocalCache(scope);
            var nextPending = (cur.pending_register || []).filter(function (n) {
                return __normalizeAutoEventName(n) !== name;
            });
            var events = Array.isArray(cur.events) ? cur.events.slice() : [];
            if (data && data.ok && !data.skipped) {
                var found = false;
                for (var i = 0; i < events.length; i++) {
                    if (__normalizeAutoEventName(events[i] && events[i].name) === name) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    events.push({
                        name: name,
                        origin: String((data && data.origin) || 'auto_discovered'),
                        deletable: false,
                        match_conditions: (data && data.match_conditions) || [
                            { param: 'event_name', op: 'equals', value: name }
                        ]
                    });
                }
                var rev = Number((data && (data.configRevision || data.config_revision)) || cur.revision) || cur.revision;
                __writeCustomEventsLocalCache(scope, {
                    revision: rev,
                    events: events,
                    pending_register: nextPending,
                    updated_at: new Date().toISOString()
                });
                if (rev > __trackingRuntimeLastRevision) {
                    __noteServerConfigRevision(rev);
                }
            } else {
                __writeCustomEventsLocalCache(scope, {
                    revision: cur.revision,
                    events: events,
                    pending_register: nextPending,
                    updated_at: new Date().toISOString()
                });
            }
        }).catch(function () {
            delete __autoRegisterInFlight[scope + ':' + name];
        });
    }

    function __scanAndRegisterDeclaredPixelEvents(root) {
        if (!__isSandboxMonitorActiveForAutoRegister()) {
            return;
        }
        var names = __collectDeclaredPixelEventNames(root);
        Object.keys(names).forEach(function (n) {
            __maybeRegisterAutoDiscoveredEvent(n);
        });
    }

    // 沙盒开启后扫描；同页点击声明名兜底注册
    try {
        document.addEventListener('click', function (ev) {
            if (!__isSandboxMonitorActiveForAutoRegister()) {
                return;
            }
            try {
                var t = ev && ev.target;
                var n = typeof __findPixelEventNameFromElement === 'function'
                    ? __findPixelEventNameFromElement(t)
                    : '';
                if (n) {
                    __maybeRegisterAutoDiscoveredEvent(n);
                }
            } catch (eClick) {}
        }, true);
        // 监视开启时触发扫描（不用定时探活，避免与 runtime 热更契约冲突）
        var __wrapMonitorEnable = function () {
            var m = window.WelineEventSandboxMonitor;
            if (!m || typeof m.enable !== 'function' || m.__welineAutoDiscoverWrapped) {
                return false;
            }
            m.__welineAutoDiscoverWrapped = true;
            var origEnable = m.enable;
            m.enable = function () {
                var ret = origEnable.apply(m, arguments);
                try {
                    __scheduleAutoDiscoverScan();
                } catch (eEn) {}
                return ret;
            };
            return true;
        };
        if (!__wrapMonitorEnable()) {
            document.addEventListener('DOMContentLoaded', function () {
                __wrapMonitorEnable();
                __scheduleAutoDiscoverScan();
            }, { once: true });
            setTimeout(__wrapMonitorEnable, 0);
            setTimeout(__wrapMonitorEnable, 500);
        }
    } catch (eBindAuto) {}

    try {
        if (__visitorTrackingConfig && typeof __visitorTrackingConfig === 'object') {
            __syncCustomEventsLocalCacheFromRuntime(__visitorTrackingConfig, __trackingRuntimeLastRevision);
            __scheduleAutoDiscoverScan();
        }
    } catch (eInitLs) {}

    window.addEventListener('weline:visitor-tracking-config', function (event) {
        if (event && event.detail && typeof event.detail === 'object') {
            __applyVisitorTrackingConfig(event.detail, { silent: true });
        }
    });

    function __fetchVisitorTrackingRuntime(force) {
        if (__trackingRuntimeFetchInFlight) {
            return;
        }
        var websiteId = 0;
        var storageScope = '';
        try {
            websiteId = Number((window.__WelinePixelEnv && window.__WelinePixelEnv.website_id) || 0) || 0;
        } catch (e0) {}
        try {
            storageScope = __resolvePixelStorageScope();
        } catch (eScope) {}
        // revision 未变：不重拉全量，仅用本地 LS
        if (!force) {
            try {
                var ls = __readCustomEventsLocalCache(storageScope);
                if (ls.revision > 0 && ls.revision === __trackingRuntimeLastRevision && Array.isArray(ls.events)) {
                    return;
                }
            } catch (eSkip) {}
        }
        var url = '/visitor/analytics/event-picker/mapped?runtime_config=1&since=' + encodeURIComponent(String(__trackingRuntimeLastRevision || 0));
        if (websiteId >= 0) {
            url += '&website_id=' + encodeURIComponent(String(websiteId));
        }
        if (storageScope) {
            url += '&storage_scope=' + encodeURIComponent(storageScope);
        }
        if (force) {
            url += '&_=' + Date.now();
        }
        __trackingRuntimeFetchInFlight = true;
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                __trackingRuntimeFetchInFlight = false;
                if (!data || !data.ok || !data.config || typeof data.config !== 'object') {
                    return;
                }
                var rev = Number(data.configRevision || data.config_revision || data.config.configRevision || 0) || 0;
                if (!force && rev > 0 && rev <= __trackingRuntimeLastRevision && data.changed === false) {
                    return;
                }
                __applyVisitorTrackingConfig(data.config, {});
            })
            .catch(function () {
                __trackingRuntimeFetchInFlight = false;
            });
    }

    /**
     * 实时改事件：提交响应里的 revision；仅当更大时才拉全量（无定时/长连接/可见探活）。
     */
    function __noteServerConfigRevision(rev) {
        rev = Number(rev) || 0;
        if (rev <= 0 || rev <= __trackingRuntimeLastRevision) {
            return;
        }
        __fetchVisitorTrackingRuntime(true);
    }

    try {
        if (typeof BroadcastChannel !== 'undefined') {
            var __trackingReloadBc = new BroadcastChannel('weline-visitor-tracking-config');
            __trackingReloadBc.onmessage = function () {
                // 仅本机后台↔店面同浏览器预览；线上用户靠提交响应里的 revision
                __fetchVisitorTrackingRuntime(true);
            };
        }
    } catch (eBc) {}
    window.addEventListener('storage', function (ev) {
        if (!ev || ev.key !== 'weline-visitor-tracking-config:ping') {
            return;
        }
        __fetchVisitorTrackingRuntime(true);
    });
    try {
        window.__WelineNoteTrackingConfigRevision = __noteServerConfigRevision;
    } catch (eExport) {}

    function __visitorConfigSection(section) {
        var config = window.__WelineVisitorTrackingConfig || __visitorTrackingConfig || {};
        return config && typeof config[section] === 'object' && config[section] ? config[section] : {};
    }

    function __visitorConfigBool(value, defaultValue) {
        if (typeof value === 'boolean') {
            return value;
        }
        if (typeof value === 'number') {
            return value === 1;
        }
        if (typeof value === 'string') {
            var normalized = value.trim().toLowerCase();
            if (['1', 'true', 'yes', 'on', 'enabled'].indexOf(normalized) > -1) {
                return true;
            }
            if (['0', 'false', 'no', 'off', 'disabled', ''].indexOf(normalized) > -1) {
                return false;
            }
        }
        return !!defaultValue;
    }

    function __visitorPixelEnabled() {
        var pixel = __visitorConfigSection('pixel');
        return __visitorConfigBool(pixel.enabled, true);
    }

    function __visitorConsentState() {
        var consentConfig = __visitorConfigSection('consent');
        var enabled = __visitorConfigBool(consentConfig.enabled, false);
        var state = window.__WelineConsentState || window.WelineConsent || {};
        function granted(key) {
            if (!enabled) {
                return true;
            }
            var value = state[key];
            if (value === undefined && key === 'analytics') {
                value = state.analytics_storage;
            }
            return value === true || value === 'granted' || value === 'yes' || value === 1;
        }
        return {
            enabled: enabled,
            analytics: granted('analytics'),
            ad_storage: granted('ad_storage'),
            ad_user_data: granted('ad_user_data'),
            ad_personalization: granted('ad_personalization')
        };
    }

    function __visitorConsentAllows(key) {
        var consent = __visitorConsentState();
        return consent.enabled ? consent[key] === true : true;
    }

    function __isPageBuilderPreview() {
        if (window.__PAGEBUILDER_PREVIEW__ === true) {
            return true;
        }
        try {
            return new URLSearchParams(String(window.location && window.location.search || '')).get('preview') === '1';
        } catch (error) {
            return false;
        }
    }

    function __visitorOptimizationEvidenceWriteAllowed() {
        return __visitorConsentAllows('analytics') && !__isPageBuilderPreview();
    }

    function __visitorMarketingStorageKey() {
        var consentConfig = __visitorConfigSection('consent') || {};
        var key = String(consentConfig.marketingStorageKey || 'ad_storage').trim();
        return key || 'ad_storage';
    }

    function __visitorMarketingConsentAllowsStorage() {
        return __visitorConsentAllows(__visitorMarketingStorageKey());
    }

    /** A08/A09：pixel + sticky.linkerEnabled + 营销同意 才允许改写 a */
    function __stickyLinkerEnabled() {
        if (!__visitorPixelEnabled()) {
            return false;
        }
        var stickyCfg = __visitorConfigSection('sticky') || {};
        var linkerFlag = stickyCfg.linkerEnabled != null ? stickyCfg.linkerEnabled : stickyCfg.linker_enabled;
        if (!__visitorConfigBool(linkerFlag, true)) {
            return false;
        }
        return __visitorMarketingConsentAllowsStorage();
    }

    function __visitorConfigList(section, key) {
        var value = (__visitorConfigSection(section) || {})[key];
        if (Array.isArray(value)) {
            return value.map(function (item) {
                return String(item || '').trim();
            }).filter(Boolean);
        }
        return String(value || '').split(/[\n\r,]+/).map(function (item) {
            return item.trim();
        }).filter(Boolean);
    }

    function __visitorHostMatches(host, patterns) {
        host = String(host || '').toLowerCase();
        return (patterns || []).some(function (pattern) {
            pattern = String(pattern || '').trim().toLowerCase();
            if (!pattern) {
                return false;
            }
            if (pattern.indexOf('*.') === 0) {
                var suffix = pattern.slice(1);
                return host.slice(-suffix.length) === suffix;
            }
            return host === pattern;
        });
    }

    function __visitorPathMatches(path, prefixes) {
        path = String(path || '/');
        return (prefixes || []).some(function (prefix) {
            prefix = String(prefix || '').trim();
            return prefix && path.indexOf(prefix) === 0;
        });
    }

    function __visitorQueryMatches(keys) {
        if (!keys || !keys.length) {
            return [];
        }
        var params = new URLSearchParams(window.location.search || '');
        var lowered = keys.map(function (key) {
            return String(key || '').trim().toLowerCase();
        }).filter(Boolean);
        var matched = [];
        params.forEach(function (_value, key) {
            if (lowered.indexOf(String(key || '').toLowerCase()) > -1) {
                matched.push(key);
            }
        });
        return matched;
    }

    function __visitorReferrerHost() {
        if (!document.referrer) {
            return '';
        }
        try {
            return new URL(document.referrer).hostname || '';
        } catch (error) {
            return '';
        }
    }

    function __visitorTrafficDecision() {
        var rules = __visitorConfigSection('trafficRules');
        var host = String(window.location.hostname || '').toLowerCase();
        var path = window.location.pathname || '/';
        var userAgent = String(navigator.userAgent || '').toLowerCase();
        var reasons = [];
        var matchedRules = [];

        function add(reason, label, value) {
            reasons.push(reason);
            matchedRules.push({ reason: reason, label: label, value: value || '' });
        }

        if (__visitorConfigBool(rules.excludeLocalForwarding, true) && __isVisitorLocalHost()) {
            add('local_access', '本地/私网访问', host);
        }

        var excludedHosts = __visitorConfigList('trafficRules', 'excludedHosts');
        if (__visitorHostMatches(host, excludedHosts)) {
            add('excluded_host', '站点排除 Host', host);
        }

        var excludedPathPrefixes = __visitorConfigList('trafficRules', 'excludedPathPrefixes');
        if (__visitorPathMatches(path, excludedPathPrefixes)) {
            add('excluded_path', '站点排除路径', path);
        }

        var matchedQueryKeys = __visitorQueryMatches(__visitorConfigList('trafficRules', 'excludedQueryKeys'));
        if (matchedQueryKeys.length) {
            add('excluded_query', '站点排除 Query', matchedQueryKeys.join(','));
        }

        var referrerHost = __visitorReferrerHost();
        if (referrerHost && __visitorHostMatches(referrerHost, __visitorConfigList('trafficRules', 'excludedReferrerHosts'))) {
            add('excluded_referrer', '站点排除来源', referrerHost);
        }

        (__visitorConfigList('trafficRules', 'excludedUserAgentKeywords') || []).some(function (keyword) {
            keyword = String(keyword || '').toLowerCase();
            if (keyword && userAgent.indexOf(keyword) > -1) {
                add('excluded_user_agent', '站点排除 User-Agent', keyword);
                return true;
            }
            return false;
        });

        return {
            contractVersion: 'weline-visitor-traffic/v1',
            source: rules.source || 'Weline_Visitor SystemConfig',
            filtered: reasons.length > 0,
            forwardable: reasons.length === 0,
            localAccess: __isVisitorLocalHost(),
            reasons: reasons,
            matchedRules: matchedRules,
            evaluatedAt: new Date().toISOString()
        };
    }

    var __visitorForwarders = window.__WelineVisitorForwarders || {};
    var __visitorForwarderMeta = window.__WelineVisitorForwarderMeta || {};
    window.__WelineVisitorForwarders = __visitorForwarders;
    window.__WelineVisitorForwarderMeta = __visitorForwarderMeta;
    window.WelineVisitorForwarders = Object.assign({}, window.WelineVisitorForwarders || {}, {
        register: function (name, handler, meta) {
            name = String(name || '').trim();
            if (!name || typeof handler !== 'function') {
                return false;
            }
            __visitorForwarders[name] = handler;
            if (meta && typeof meta === 'object') {
                __visitorForwarderMeta[name] = Object.assign({}, __visitorForwarderMeta[name] || {}, meta, { id: name });
            } else if (!__visitorForwarderMeta[name]) {
                __visitorForwarderMeta[name] = { id: name, title: name, kind: 'module' };
            }
            return true;
        },
        unregister: function (name) {
            name = String(name || '').trim();
            delete __visitorForwarders[name];
            delete __visitorForwarderMeta[name];
        },
        emit: function (event) {
            var blocked = !!(event && event.forwarding && event.forwarding.allowed === false);
            if (!blocked) {
                Object.keys(__visitorForwarders).forEach(function (name) {
                    try {
                        __visitorForwarders[name](event);
                    } catch (error) {
                        if (window.DEV) {
                            console.debug('Weline Visitor forwarder failed:', name, error && error.message ? error.message : error);
                        }
                    }
                });
            }
            // 沙盒厂商 fanout / 转化去重黄标：即使 forwarding 被拦仍执行（监视流靠外层 publish）
            try {
                __fanoutPixelVendors(event);
            } catch (vendorError) {
                if (window.DEV) {
                    console.debug('Weline Visitor vendor fanout failed:', vendorError && vendorError.message ? vendorError.message : vendorError);
                }
            }
        },
        getHandlers: function () {
            return Object.keys(__visitorForwarders);
        },
        getMeta: function () {
            return Object.assign({}, __visitorForwarderMeta);
        },
        getChannels: function () {
            return Object.keys(__visitorForwarderMeta).map(function (id) {
                return Object.assign({ id: id, integrated: typeof __visitorForwarders[id] === 'function' }, __visitorForwarderMeta[id]);
            });
        }
    });

    var __customVisitorForwarderLoaded = false;

    function __loadCustomVisitorForwarder() {
        var forwarders = __visitorConfigSection('forwarders');
        var custom = forwarders && forwarders.custom ? forwarders.custom : {};
        if (__customVisitorForwarderLoaded || !__visitorConfigBool(custom.enabled, false)) {
            return false;
        }
        var script = String(custom.script || '').trim();
        if (!script) {
            return false;
        }
        try {
            __customVisitorForwarderLoaded = true;
            (new Function('WelineVisitorForwarders', 'window', 'document', script))(window.WelineVisitorForwarders, window, document);
            return true;
        } catch (error) {
            __customVisitorForwarderLoaded = false;
            if (window.DEV) {
                console.debug('Weline Visitor custom forwarder failed:', error && error.message ? error.message : error);
            }
            return false;
        }
    }

    function __visitorEventId(payload) {
        payload = payload || {};
        var eventId = String(payload.event_id || payload.eventId || '');
        if (!eventId) {
            eventId = 'wv-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        }
        payload.event_id = eventId;
        return eventId;
    }

    function __resolveSectionSource(element, eventName) {
        var normalizedEvent = __normalizePixelEventName(eventName || 'behavior_event');
        if (!element || !element.closest) {
            return { section_code: '', section_event_key: '', section_source_status: 'n/a' };
        }
        var section = element.closest('section');
        if (!section) {
            return { section_code: '', section_event_key: '', section_source_status: 'missing_section' };
        }
        if (!section.hasAttribute || !section.hasAttribute('weline-code')) {
            return { section_code: '', section_event_key: '', section_source_status: 'missing_code' };
        }
        var code = String(section.getAttribute('weline-code') || '').trim();
        if (code === '') {
            return { section_code: '', section_event_key: '', section_source_status: 'empty_code' };
        }
        return {
            section_code: code,
            section_event_key: code + ':' + normalizedEvent,
            section_source_status: 'ok'
        };
    }

    function __shouldWarnMissingSectionCode(eventName, status) {
        if (!window.DEV) {
            return false;
        }
        if (status !== 'missing_section' && status !== 'missing_code' && status !== 'empty_code') {
            return false;
        }
        var normalized = __normalizePixelEventName(eventName || '');
        if (!normalized || normalized === 'page_view' || normalized === 'page_load') {
            return false;
        }
        return /^(cta_|contact_|lead_|add_to_cart|begin_checkout|purchase|search_|login|register|click|checkout_)/.test(normalized)
            || normalized.indexOf('click') !== -1;
    }

    function __buildVisitorEventEnvelope(pixelEventName, payload, meta, element, platformEventName) {
        payload = payload || {};
        meta = meta || {};
        var normalized = __normalizePixelEventName(pixelEventName || payload.eventName || payload.event || 'behavior_event');
        var eventId = __visitorEventId(payload);
        var traffic = __visitorTrafficDecision();
        var consent = __visitorConsentState();
        var consentAllowed = consent.enabled ? consent.analytics === true : true;
        var forwardingReasons = traffic.reasons.slice();
        var forwardingRules = traffic.matchedRules.slice();
        if (!consentAllowed) {
            forwardingReasons.push('consent_denied');
            forwardingRules.push({ reason: 'consent_denied', label: '同意模式拒绝 analytics', value: '' });
        }
        var sectionSource = __resolveSectionSource(element, normalized);
        if (__shouldWarnMissingSectionCode(normalized, sectionSource.section_source_status)) {
            try {
                console.warn('[WelinePixel] section weline-code unresolved', {
                    eventName: normalized,
                    section_source_status: sectionSource.section_source_status,
                    element: element
                });
            } catch (warnError) {
            }
        }
        return {
            contractVersion: 'weline-visitor-event/v1',
            command: 'weline-visitor-event',
            eventId: eventId,
            eventName: normalized,
            timestampMs: Date.now(),
            section_code: sectionSource.section_code,
            section_event_key: sectionSource.section_event_key,
            section_source_status: sectionSource.section_source_status,
            page: {
                location: window.location.href,
                title: document.title || '',
                path: window.location.pathname,
                referrer: document.referrer || ''
            },
            consent: consent,
            traffic: traffic,
            forwarding: {
                allowed: traffic.forwardable && consentAllowed,
                filtered: traffic.filtered || !consentAllowed,
                reasons: forwardingReasons,
                matchedRules: forwardingRules
            },
            pixel: {
                name: payload.name || window.__WelinePixelName || '',
                module: payload.module || ''
            },
            payload: payload,
            meta: meta,
            element: {
                tagName: element && element.tagName || '',
                text: element ? (element.innerText || element.textContent || '').trim().slice(0, 120) : '',
                href: element && element.href || '',
                domElement: element || null
            },
            platforms: {
                ga4: {
                    eventName: platformEventName || ''
                },
                gtm: {
                    eventName: platformEventName || '',
                    skipPush: false
                }
            },
            dictVersion: (window.__WelineVisitorTrackingConfig && window.__WelineVisitorTrackingConfig.dictVersion) || ''
        };
    }

    function __recordVisitorRuntimeEvent(event) {
        var runtime = window.__WelineVisitorRuntime = window.__WelineVisitorRuntime || {};
        runtime.recentEvents = Array.isArray(runtime.recentEvents) ? runtime.recentEvents : [];
        runtime.recentEvents.unshift({
            eventId: event.eventId,
            eventName: event.eventName,
            timestampMs: event.timestampMs,
            timeLabel: new Date(event.timestampMs).toLocaleTimeString(),
            page: event.page,
            traffic: event.traffic,
            forwarding: event.forwarding,
            platforms: event.platforms,
            element: {
                tagName: event.element && event.element.tagName || '',
                text: event.element && event.element.text || '',
                href: event.element && event.element.href || ''
            }
        });
        runtime.recentEvents = runtime.recentEvents.slice(0, 80);
        try {
            window.dispatchEvent(new CustomEvent('weline:visitor-runtime-event', { detail: event }));
        } catch (error) {
        }
    }

    function __emitVisitorPixelEvent(pixelEventName, payload, meta, element, platformEventName) {
        var resolved = __dictionaryResolve(pixelEventName, payload, element);
        var ga4Name = platformEventName || resolved.ga4_event || '';
        var event = __buildVisitorEventEnvelope(pixelEventName, payload, meta, element, ga4Name);
        if (event.platforms && event.platforms.gtm) {
            event.platforms.gtm.skipPush = !!resolved.skip_gtm_push;
            event.platforms.gtm.eventName = ga4Name;
        }
        event.dictVersion = resolved.dict_version || event.dictVersion || '';
        event.mappingSource = resolved.mapping_source || '';

        __recordVisitorRuntimeEvent(event);
        window.WelineVisitorForwarders.emit(event);
        try {
            window.dispatchEvent(new CustomEvent('weline:visitor:event', { detail: event }));
        } catch (error) {
        }
        // Monitor 观察点在 Sandbox.publish（Forwarders 尾部透传），此处不再重复 emit。
        return event;
    }

    function __isVisitorLocalHost() {
        var host = String(window.location.hostname || '').toLowerCase();
        return host === 'localhost' ||
            host === '127.0.0.1' ||
            host === '::1' ||
            host === '[::1]' ||
            host.slice(-6) === '.local' ||
            /^10\./.test(host) ||
            /^192\.168\./.test(host) ||
            /^172\.(1[6-9]|2\d|3[0-1])\./.test(host);
    }

    function __isVisitorDiagnosticPanelElement(element) {
        return Boolean(element && element.closest && element.closest([
            '#weline-panel-visitor',
            '#weline-event-sandbox-monitor',
            '#weline-lifecycle-assistant',
            '#dev-tool-trigger',
            '.dev-tool-container',
            '.dev-tool-trigger',
            '[data-dev-tool-action]',
            '[data-wvp-subtab]',
            '[data-weline-panel-visitor-bootstrap]',
            '[data-weline-panel-seo-bootstrap]'
        ].join(',')));
    }

    function __sandboxNormalizeEventKey(name) {
        return String(name || '').trim().toLowerCase();
    }

    function __sandboxIsSystemDictEvent(name) {
        var key = __sandboxNormalizeEventKey(name);
        if (!key) {
            return false;
        }
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var dict = cfg.eventDictionary || {};
        var events = Array.isArray(dict.events) ? dict.events : [];
        for (var i = 0; i < events.length; i++) {
            var row = events[i] || {};
            if (__sandboxNormalizeEventKey(row.weline_event || row.name || '') === key) {
                return true;
            }
        }
        return false;
    }

    function __sandboxIsCustomPoolEvent(name) {
        var key = __sandboxNormalizeEventKey(name);
        if (!key) {
            return false;
        }
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var pools = [cfg.customEvents, cfg.custom_events];
        for (var p = 0; p < pools.length; p++) {
            var custom = pools[p];
            if (!custom) {
                continue;
            }
            if (Array.isArray(custom)) {
                for (var i = 0; i < custom.length; i++) {
                    var row = custom[i];
                    if (typeof row === 'string' && __sandboxNormalizeEventKey(row) === key) {
                        return true;
                    }
                    if (row && typeof row === 'object'
                        && __sandboxNormalizeEventKey(row.name || row.weline_event || row.event_name || '') === key) {
                        return true;
                    }
                }
            } else if (typeof custom === 'object') {
                if (Object.prototype.hasOwnProperty.call(custom, name)
                    || Object.prototype.hasOwnProperty.call(custom, key)) {
                    return true;
                }
                var nested = custom.events;
                if (nested && typeof nested === 'object'
                    && (Object.prototype.hasOwnProperty.call(nested, name)
                        || Object.prototype.hasOwnProperty.call(nested, key))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 是否「有意义命中」（相对纯透传流过）。命中后再由 __sandboxHitKind 分系统/自定义。
     */
    function __sandboxIsCustomEventHit(name, extras) {
        extras = extras && typeof extras === 'object' ? extras : {};
        name = String(name || extras.event_name || '').trim();
        if (!name) {
            return false;
        }
        if (extras.event_hit === true) {
            return true;
        }
        if (extras.event_hit === false) {
            return false;
        }
        if (extras.mapping_source || extras.ga4_event || extras.third_party_event) {
            return true;
        }
        if (name.indexOf('weline:') === 0) {
            return true;
        }
        var generic = name === 'click' || name === 'event' || name === 'page_view' || name === 'page_load' || name === 'page_exit' || name === 'page_hide';
        if (generic) {
            return false;
        }
        if (__sandboxIsSystemDictEvent(name) || __sandboxIsCustomPoolEvent(name)) {
            return true;
        }
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var vendors = Array.isArray(cfg.vendors) ? cfg.vendors : [];
        for (var i = 0; i < vendors.length; i++) {
            var map = vendors[i] && vendors[i].event_map && typeof vendors[i].event_map === 'object'
                ? vendors[i].event_map
                : null;
            if (map && Object.prototype.hasOwnProperty.call(map, name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @returns {''|'system'|'custom'|'dedupe'}
     */
    function __sandboxHitKind(name, extras) {
        extras = extras && typeof extras === 'object' ? extras : {};
        name = String(name || extras.event_name || '').trim();
        var forced = String(extras.hit_kind || extras.event_kind || '').trim().toLowerCase();
        var source = String(extras.source || '').trim().toLowerCase();
        if (forced === 'dedupe' || source === 'dedupe') {
            return 'dedupe';
        }
        if (!__sandboxIsCustomEventHit(name, extras)) {
            return '';
        }
        if (forced === 'system' || forced === 'custom') {
            return forced;
        }
        if (source === 'lifecycle' || source === 'chain') {
            return 'system';
        }
        if (name.indexOf('weline:') === 0) {
            return 'system';
        }
        if (__sandboxIsSystemDictEvent(name)) {
            return 'system';
        }
        var mappingSource = String(extras.mapping_source || '').trim().toLowerCase();
        if (mappingSource === 'dictionary'
            || mappingSource === 'legacy_fallback'
            || mappingSource === 'site_cta_override'
            || mappingSource === 'cta_heuristic') {
            return 'system';
        }
        if (__sandboxIsCustomPoolEvent(name) || mappingSource === 'custom' || mappingSource === 'discovered') {
            return 'custom';
        }
        // 搭接表命中但非系统字典 → 自定义事件
        return 'custom';
    }

    /**
     * 沙盒继电用：只保留可展示的标量参数，并展开 elementInfo.* / params.*。
     * 避免把 DOM/嵌套对象 JSON 截断成非法 params_json。
     */
    function __sandboxCompactParams(raw) {
        var out = {};
        var count = 0;
        function put(key, val) {
            key = String(key || '').trim();
            if (!key || Object.prototype.hasOwnProperty.call(out, key) || count >= 64) {
                return;
            }
            var t = typeof val;
            if (t === 'string' || t === 'number' || t === 'boolean') {
                out[key] = val;
                count++;
            } else if (val == null) {
                out[key] = '';
                count++;
            }
        }
        function putEcommerceBag(bag) {
            if (!bag || typeof bag !== 'object') {
                return;
            }
            ['value', 'currency', 'grand_total', 'transaction_id', 'order_uuid', 'order_id', 'business_key'].forEach(function (k) {
                if (bag[k] !== undefined && bag[k] !== null && bag[k] !== '') {
                    put(k, bag[k]);
                }
            });
            var items = Array.isArray(bag.items) ? bag.items : null;
            if (!items && bag.ecommerce && typeof bag.ecommerce === 'object' && Array.isArray(bag.ecommerce.items)) {
                items = bag.ecommerce.items;
                if (bag.ecommerce.value !== undefined) {
                    put('value', bag.ecommerce.value);
                }
                if (bag.ecommerce.currency) {
                    put('currency', bag.ecommerce.currency);
                }
            }
            if (!items || !items.length) {
                return;
            }
            put('items_count', items.length);
            try {
                put('items', JSON.stringify(items).slice(0, 2500));
            } catch (eItemsJson) {
            }
            for (var i = 0; i < items.length && i < 6; i++) {
                var it = items[i];
                if (!it || typeof it !== 'object') {
                    continue;
                }
                var n = i + 1;
                put('item_' + n + '_id', it.item_id || it.product_id || it.sku || '');
                put('item_' + n + '_name', it.item_name || it.name || '');
                if (it.price !== undefined && it.price !== null && it.price !== '') {
                    put('item_' + n + '_price', it.price);
                }
                if (it.quantity !== undefined && it.quantity !== null && it.quantity !== '') {
                    put('item_' + n + '_quantity', it.quantity);
                } else if (it.qty !== undefined && it.qty !== null && it.qty !== '') {
                    put('item_' + n + '_quantity', it.qty);
                }
            }
        }
        function takeObject(obj, prefix) {
            if (!obj || typeof obj !== 'object') {
                return;
            }
            Object.keys(obj).forEach(function (k) {
                if (k === 'domElement' || k === 'element' || k === 'meta' || k === 'raw' || k === 'chain' || k === 'event' || k === 'items' || k === 'ecommerce') {
                    return;
                }
                var val = obj[k];
                var path = prefix ? (prefix + '.' + k) : k;
                if (val && typeof val === 'object' && !Array.isArray(val)) {
                    if (k === 'params' || k === 'elementInfo' || k === 'page') {
                        takeObject(val, k === 'params' ? prefix : path);
                        if (k === 'elementInfo' || k === 'page') {
                            return;
                        }
                    }
                    return;
                }
                put(path, val);
            });
        }
        if (!raw || typeof raw !== 'object') {
            return out;
        }
        // GA4 电商字段优先：避免标量配额被噪音占满后丢掉 value/items
        putEcommerceBag(raw);
        if (raw.params && typeof raw.params === 'object') {
            putEcommerceBag(raw.params);
            takeObject(raw.params, '');
        }
        takeObject(raw, '');
        return out;
    }

    function __matchOp(actual, op, expected) {
        actual = String(actual == null ? '' : actual);
        expected = String(expected == null ? '' : expected);
        op = String(op || 'equals').toLowerCase();
        if (op === 'contains') {
            return actual.indexOf(expected) !== -1;
        }
        if (op === 'starts_with') {
            return actual.indexOf(expected) === 0;
        }
        if (op === 'ends_with') {
            return expected === '' ? true : actual.slice(-expected.length) === expected;
        }
        return actual === expected;
    }

    function __buildCustomMatchContext(element, extras) {
        extras = extras && typeof extras === 'object' ? extras : {};
        var snapshot = element ? (__getElementSnapshot(element) || {}) : {};
        var href = '';
        try {
            href = String((element && (element.href || (element.getAttribute && element.getAttribute('href')))) || snapshot.href || '');
        } catch (eHref) {}
        var pageLocation = '';
        var pagePath = '';
        var pageReferrer = '';
        try {
            pageLocation = String(window.location.href || '');
            pagePath = String(window.location.pathname || '');
            pageReferrer = String(document.referrer || '');
        } catch (eLoc) {}
        var className = String(snapshot.className || extras.className || '');
        var text = String(snapshot.text || extras.text || '');
        var tag = String((element && element.tagName) || snapshot.tagName || extras.tag || '').toLowerCase();
        var id = String(snapshot.id || extras.id || '');
        var name = String(snapshot.name || extras.name || '');
        var type = String(snapshot.type || extras.type || '');
        var tagName = String((element && element.tagName) || snapshot.tagName || '').toUpperCase();
        var elementInfo = {
            tagName: tagName,
            tag: tag,
            className: className,
            id: id,
            name: name,
            type: type,
            href: href,
            text: text
        };
        if (extras.elementInfo && typeof extras.elementInfo === 'object') {
            Object.keys(extras.elementInfo).forEach(function (k) {
                var v = extras.elementInfo[k];
                var t = typeof v;
                if (t === 'string' || t === 'number' || t === 'boolean' || v == null) {
                    elementInfo[k] = v == null ? '' : v;
                }
            });
        }
        return {
            event_name: String(extras.event_name || extras.pixelEventName || extras.name || 'click'),
            page_location: pageLocation,
            page_path: pagePath,
            page_referrer: pageReferrer,
            className: className,
            text: text,
            href: href,
            tag: tag,
            id: id,
            elementInfo: elementInfo
        };
    }

    function __resolveNestedValue(root, path) {
        path = String(path || '');
        if (!path || root == null) {
            return undefined;
        }
        if (Object.prototype.hasOwnProperty.call(root, path)) {
            return root[path];
        }
        if (path.indexOf('.') < 0) {
            return undefined;
        }
        var parts = path.split('.');
        var cur = root;
        for (var i = 0; i < parts.length; i++) {
            var key = parts[i];
            if (!key || cur == null || typeof cur !== 'object') {
                return undefined;
            }
            if (!Object.prototype.hasOwnProperty.call(cur, key)) {
                return undefined;
            }
            cur = cur[key];
        }
        return cur;
    }

    function __customEventConditionValue(ctx, param) {
        param = String(param || '');
        if (!param) {
            return '';
        }
        if (Object.prototype.hasOwnProperty.call(ctx, param)) {
            var direct = ctx[param];
            if (direct != null && typeof direct !== 'object') {
                return direct;
            }
            if (typeof direct === 'string' || typeof direct === 'number' || typeof direct === 'boolean') {
                return direct;
            }
        }
        var nested = __resolveNestedValue(ctx, param);
        if (nested !== undefined) {
            if (nested == null) {
                return '';
            }
            if (typeof nested === 'object') {
                try {
                    return JSON.stringify(nested);
                } catch (eJson) {
                    return '';
                }
            }
            return nested;
        }
        var lower = param.toLowerCase();
        if (lower === 'classname') {
            return ctx.className || '';
        }
        if (lower === 'pagelocation' || lower === 'page_location') {
            return ctx.page_location || '';
        }
        if (lower === 'pagepath' || lower === 'page_path') {
            return ctx.page_path || '';
        }
        if (lower === 'pagereferrer' || lower === 'page_referrer') {
            return ctx.page_referrer || '';
        }
        if (lower === 'eventname' || lower === 'event_name') {
            return ctx.event_name || '';
        }
        if (lower === 'elementinfo.text') {
            return (ctx.elementInfo && ctx.elementInfo.text) || ctx.text || '';
        }
        if (lower === 'elementinfo.classname') {
            return (ctx.elementInfo && ctx.elementInfo.className) || ctx.className || '';
        }
        if (lower === 'elementinfo.href') {
            return (ctx.elementInfo && ctx.elementInfo.href) || ctx.href || '';
        }
        return '';
    }

    /**
     * 按 runtime customEvents.match_conditions（AND）求值，返回命中的自定义事件行。
     * @returns {object[]}
     */
    function __matchCustomEventRowsByConditions(ctx) {
        ctx = ctx && typeof ctx === 'object' ? ctx : {};
        var cfg = window.__WelineVisitorTrackingConfig || __visitorTrackingConfig || {};
        var pools = [cfg.customEvents, cfg.custom_events];
        var hits = [];
        var seen = {};
        for (var p = 0; p < pools.length; p++) {
            var custom = pools[p];
            if (!Array.isArray(custom)) {
                continue;
            }
            for (var i = 0; i < custom.length; i++) {
                var row = custom[i];
                if (!row || typeof row !== 'object') {
                    continue;
                }
                var name = __sandboxNormalizeEventKey(row.name || row.weline_event || row.event_name || '');
                if (!name || seen[name]) {
                    continue;
                }
                var conditions = row.match_conditions || row.matchConditions || [];
                if (!Array.isArray(conditions) || !conditions.length) {
                    continue;
                }
                var ok = true;
                for (var c = 0; c < conditions.length; c++) {
                    var cond = conditions[c];
                    if (!cond || typeof cond !== 'object') {
                        ok = false;
                        break;
                    }
                    var actual = __customEventConditionValue(ctx, cond.param || cond.parameter || '');
                    if (!__matchOp(actual, cond.op || cond.operator || 'equals', cond.value)) {
                        ok = false;
                        break;
                    }
                }
                if (ok) {
                    seen[name] = 1;
                    hits.push(Object.assign({}, row, { name: name, weline_event: name }));
                }
            }
        }
        return hits;
    }

    function __matchCustomEventsByConditions(ctx) {
        return __matchCustomEventRowsByConditions(ctx).map(function (row) {
            return row.name || row.weline_event || '';
        }).filter(Boolean);
    }

    function __isCopyParamsEnabled(row) {
        if (!row || typeof row !== 'object') {
            return true;
        }
        if (row.copy_params === false || row.copy_params === 0 || row.copy_params === '0') {
            return false;
        }
        return true;
    }

    function __normalizeParamMappings(raw) {
        if (!raw) {
            return [];
        }
        if (typeof raw === 'string') {
            var t = String(raw).trim();
            if (!t) {
                return [];
            }
            if (t.charAt(0) === '[' || t.charAt(0) === '{') {
                try {
                    raw = JSON.parse(t);
                } catch (e) {
                    raw = t.split(/[\s,，;；]+/);
                }
            } else {
                raw = t.split(/[\s,，;；]+/);
            }
        }
        if (!Array.isArray(raw)) {
            return [];
        }
        var out = [];
        var seen = {};
        for (var i = 0; i < raw.length; i++) {
            var item = raw[i];
            var name = '';
            var from = '';
            if (item && typeof item === 'object') {
                name = String(item.name || item.param || item.key || item.to || '').trim();
                from = String(item.from || item.source || item.expr || item.template || '').trim();
            } else {
                name = String(item || '').trim();
            }
            name = name.replace(/[^a-zA-Z0-9_.\[\]-]+/g, '');
            if (!name) {
                continue;
            }
            if (!from) {
                from = '{' + name + '}';
            }
            if (seen[name]) {
                out[seen[name] - 1] = { name: name, from: from };
                continue;
            }
            seen[name] = out.length + 1;
            out.push({ name: name, from: from });
            if (out.length >= 24) {
                break;
            }
        }
        return out;
    }

    function __normalizeExtractParamList(raw) {
        return __normalizeParamMappings(raw).map(function (m) {
            return m.name;
        });
    }

    function __resolveParamTemplate(tpl, bag, ctx) {
        tpl = String(tpl == null ? '' : tpl);
        if (!tpl) {
            return '';
        }
        if (tpl.indexOf('{') < 0) {
            var nestedBag = __resolveNestedValue(bag, tpl);
            if (nestedBag !== undefined) {
                return nestedBag == null ? '' : nestedBag;
            }
            if (Object.prototype.hasOwnProperty.call(bag, tpl)) {
                return bag[tpl];
            }
            return __customEventConditionValue(ctx, tpl);
        }
        return tpl.replace(/\{([a-zA-Z0-9_.\[\]-]+)\}/g, function (_m, key) {
            var nested = __resolveNestedValue(bag, key);
            if (nested !== undefined) {
                return nested == null ? '' : String(nested);
            }
            if (Object.prototype.hasOwnProperty.call(bag, key)) {
                var v = bag[key];
                return v == null ? '' : String(v);
            }
            var fallback = __customEventConditionValue(ctx, key);
            return fallback == null ? '' : String(fallback);
        });
    }

    function __collectCustomSourceBag(ctx, extras) {
        var bag = {};
        var srcs = [ctx || {}, extras || {}];
        if (extras && extras.params && typeof extras.params === 'object') {
            srcs.push(extras.params);
        }
        if (extras && extras.payload && typeof extras.payload === 'object') {
            srcs.push(extras.payload);
        }
        var skip = {
            domElement: 1,
            element: 1,
            meta: 1,
            raw: 1,
            chain: 1,
            event: 1,
            domEvent: 1
        };
        for (var s = 0; s < srcs.length; s++) {
            var obj = srcs[s];
            if (!obj || typeof obj !== 'object') {
                continue;
            }
            Object.keys(obj).forEach(function (key) {
                if (skip[key]) {
                    return;
                }
                var val = obj[key];
                var t = typeof val;
                if (t === 'string' || t === 'number' || t === 'boolean') {
                    bag[key] = val;
                } else if (val == null) {
                    bag[key] = '';
                }
            });
        }
        return bag;
    }

    function __extractParamsForCustomEvent(row, ctx, extras) {
        var bag = __collectCustomSourceBag(ctx, extras);
        var copyAll = __isCopyParamsEnabled(row);
        var mappings = __normalizeParamMappings(
            row && (row.param_mappings || row.paramMappings || row.extract_params || row.extractParams)
        );
        var out = {};
        if (copyAll) {
            Object.keys(bag).forEach(function (key) {
                out[key] = bag[key];
            });
        }
        for (var i = 0; i < mappings.length; i++) {
            var m = mappings[i];
            out[m.name] = __resolveParamTemplate(m.from, bag, ctx);
        }
        return out;
    }

    function __trackMatchedCustomEvents(element, event, extras, meta) {
        extras = extras && typeof extras === 'object' ? extras : {};
        meta = meta && typeof meta === 'object' ? meta : {};
        if (!window.WelinePixel || typeof window.WelinePixel.track !== 'function') {
            return [];
        }
        var ctx = __buildCustomMatchContext(element, extras);
        var rows = __matchCustomEventRowsByConditions(ctx);
        var skipNames = {};
        var already = __normalizePixelEventName(extras.already_tracked || extras.skip_event || '');
        if (already) {
            skipNames[already] = 1;
        }
        var names = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var name = row.name || row.weline_event || '';
            if (!name || skipNames[__normalizePixelEventName(name)]) {
                continue;
            }
            names.push(name);
            var extracted = __extractParamsForCustomEvent(row, ctx, extras);
            var payload = Object.assign({}, extracted, {
                trigger: extras.trigger || 'click',
                source: 'custom_match',
                hit_kind: 'custom',
                event_hit: true,
                mapping_source: 'custom_match',
                matched_from: ctx.event_name || 'click',
                copy_params: __isCopyParamsEnabled(row),
                param_mappings: __normalizeParamMappings(row.param_mappings || row.paramMappings || row.extract_params || row.extractParams),
                extract_params: __normalizeExtractParamList(row.param_mappings || row.paramMappings || row.extract_params || row.extractParams),
                params: extracted,
                element: element ? (__getElementSnapshot(element) || null) : null,
                domElement: element || null
            });
            if (payload.className == null && ctx.className) {
                payload.className = ctx.className;
            }
            if (payload.page_location == null && ctx.page_location) {
                payload.page_location = ctx.page_location;
            }
            if (payload.page_path == null && ctx.page_path) {
                payload.page_path = ctx.page_path;
            }
            window.WelinePixel.track(name, payload, Object.assign({}, meta, {
                element: element || meta.element,
                domEvent: event || meta.domEvent
            }));
        }
        return names;
    }

    /**
     * 无业务 track 的点击仍走沙盒 publish 透传，Monitor 订 publish→emit 即可看见。
     * 是否「命中自定义事件」由 normalize / 搭接表判定，不在此旁路 invent 第二套监听。
     */
    function __publishSandboxClickPassthrough(element, event, extras) {
        extras = extras && typeof extras === 'object' ? extras : {};
        var sb = window.WelineEventSandbox || window.WelinePixelSandbox;
        if (!sb || typeof sb.publish !== 'function') {
            return null;
        }
        var snapshot = __getElementSnapshot(element) || {};
        var hitName = String(extras.event_name || extras.pixelEventName || '').trim();
        var tag = String((element && element.tagName) || snapshot.tagName || '').toLowerCase();
        var textPreview = '';
        try {
            textPreview = String((element && (element.innerText || element.textContent)) || snapshot.text || '')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 80);
        } catch (eText) {}
        var envelope = {
            eventName: hitName || 'click',
            name: hitName || 'click',
            weline_event: hitName || 'click',
            eventId: 'click-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
            timestampMs: Date.now(),
            event_hit: false,
            hit_kind: '',
            payload: {
                trigger: 'click',
                source: 'sandbox_passthrough',
                tag: tag,
                text: textPreview,
                href: (element && (element.href || (element.getAttribute && element.getAttribute('href')))) || snapshot.href || '',
                className: snapshot.className || '',
                id: snapshot.id || '',
                elementInfo: {
                    tagName: String(snapshot.tagName || (element && element.tagName) || ''),
                    className: snapshot.className || '',
                    id: snapshot.id || '',
                    text: textPreview,
                    href: (element && (element.href || (element.getAttribute && element.getAttribute('href')))) || snapshot.href || ''
                },
                x: event && typeof event.clientX === 'number' ? event.clientX : null,
                y: event && typeof event.clientY === 'number' ? event.clientY : null
            },
            element: snapshot,
            trigger: 'click',
            mappingSource: ''
        };
        sb.publish(envelope);
        return envelope;
    }

    function __visitorBrowserLanguages() {
        if (Array.isArray(navigator.languages) && navigator.languages.length) {
            return navigator.languages.slice();
        }
        return navigator.language ? [navigator.language] : [];
    }

    function __isVisitorChineseBrowser() {
        return __visitorBrowserLanguages().some(function (language) {
            return String(language || '').toLowerCase().indexOf('zh') === 0;
        });
    }

    function __normalizeGa4EventName(eventName) {
        eventName = String(eventName || '').trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
        return eventName ? eventName.slice(0, 40) : '';
    }

    function __ga4Config() {
        var ga4 = __visitorConfigSection('ga4');
        var measurementId = String(ga4.measurementId || ga4.measurement_id || '').trim().toUpperCase();
        var configured = /^G-[A-Z0-9]{4,20}$/.test(measurementId);
        var enableInDev = __visitorConfigBool(ga4.enableInDev || ga4.enable_in_dev, false);
        var localHost = __isVisitorLocalHost();
        var chineseBrowser = __isVisitorChineseBrowser();
        var traffic = __visitorTrafficDecision();
        var blockedReasons = [];
        if (traffic.filtered) {
            blockedReasons = blockedReasons.concat(traffic.reasons);
        }
        if (!__visitorConsentAllows('analytics')) {
            blockedReasons.push('consent_denied');
        }
        var eventsAllowed = blockedReasons.length === 0;
        var gtagRuntime = typeof window.gtag === 'function';
        var gtagScript = Boolean(document.querySelector('script[src*="googletagmanager.com/gtag/js"]'));
        var enabled = __visitorConfigBool(ga4.enabled, false) && configured;
        var runtime = {
            enabled: enabled,
            configured: configured,
            measurementId: configured ? measurementId : '',
            enableInDev: enableInDev,
            autoTrackVisitorEvents: __visitorConfigBool(ga4.autoTrackVisitorEvents || ga4.auto_track_visitor_events, true),
            ctaEventName: __normalizeGa4EventName(ga4.ctaEventName || ga4.cta_event_name || 'cta_click') || 'cta_click',
            debugMode: __visitorConfigBool(ga4.debugMode || ga4.debug_mode, false),
            localHost: localHost,
            chineseBrowser: chineseBrowser,
            browserLanguages: __visitorBrowserLanguages(),
            traffic: traffic,
            blockedReasons: blockedReasons,
            eventsAllowed: eventsAllowed,
            gtagRuntime: gtagRuntime,
            gtagScript: gtagScript,
            eventsWillFire: enabled && gtagRuntime && eventsAllowed,
            previewOnly: !configured && eventsAllowed,
            recentTriggers: Array.isArray(ga4.recentTriggers) ? ga4.recentTriggers.slice(0, 50) : ((window.__SITE_GA4__ && Array.isArray(window.__SITE_GA4__.recentTriggers)) ? window.__SITE_GA4__.recentTriggers.slice(0, 50) : []),
            source: ga4.source || 'Weline_Visitor SystemConfig'
        };
        window.__SITE_GA4__ = Object.assign({}, window.__SITE_GA4__ || {}, runtime);
        return runtime;
    }

    function __shouldLoadGa4(runtime) {
        runtime = runtime || __ga4Config();
        if (!runtime.enabled || !runtime.measurementId) {
            return false;
        }
        return runtime.eventsAllowed === true;
    }

    function __loadVisitorGa4() {
        var runtime = __ga4Config();
        var gtm = __gtmConfig();
        if (gtm.enabled) {
            runtime.enabled = false;
            runtime.disabledByGtm = true;
            return runtime;
        }
        if (!__shouldLoadGa4(runtime)) {
            return runtime;
        }
        if (window.__WelineVisitorGa4Loaded === runtime.measurementId) {
            return runtime;
        }

        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () {
            window.dataLayer.push(arguments);
        };
        window.gtag('js', new Date());
        // Google：关闭 debug 须「省略」debug_mode；传 false 不会关闭，也不应在开启路径里传 false。
        var ga4ConfigParams = { send_page_view: true };
        if (runtime.debugMode) {
            ga4ConfigParams.debug_mode = true;
        }
        window.gtag('config', runtime.measurementId, ga4ConfigParams);

        if (!document.querySelector('script[data-weline-visitor-ga4="true"][src*="' + runtime.measurementId + '"]')) {
            var script = document.createElement('script');
            script.async = true;
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(runtime.measurementId);
            script.setAttribute('data-weline-visitor-ga4', 'true');
            // gtag/js?id= 会自动再 config 一次；onload 后按需重申 debug_mode，避免竞态丢掉 DebugView 信号。
            if (runtime.debugMode) {
                script.addEventListener('load', function () {
                    try {
                        if (typeof window.gtag === 'function') {
                            window.gtag('config', runtime.measurementId, {
                                send_page_view: false,
                                debug_mode: true
                            });
                        }
                    } catch (eDbg) {
                    }
                });
            }
            document.head.appendChild(script);
        }
        window.__WelineVisitorGa4Loaded = runtime.measurementId;
        window.__SITE_GA4__ = Object.assign({}, window.__SITE_GA4__ || {}, runtime, {
            gtagRuntime: true,
            gtagScript: true,
            eventsWillFire: runtime.eventsAllowed === true
        });
        return runtime;
    }

    function __recordVisitorGa4Trigger(entry) {
        var runtime = window.__SITE_GA4__ = window.__SITE_GA4__ || {};
        runtime.recentTriggers = Array.isArray(runtime.recentTriggers) ? runtime.recentTriggers : [];
        var trigger = Object.assign({
            id: 'wv-ga4-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8),
            timestamp: Date.now(),
            timeLabel: new Date().toLocaleTimeString(),
            source: 'Weline_Visitor'
        }, entry || {});
        runtime.recentTriggers.unshift(trigger);
        runtime.recentTriggers = runtime.recentTriggers.slice(0, 50);
        try {
            window.dispatchEvent(new CustomEvent('site:ga4-trigger', { detail: trigger }));
        } catch (error) {
        }
    }

    function __isGa4CtaElement(element) {
        return Boolean(element && element.closest && element.closest('[data-pixel-event], [data-visitor-event], [data-cta-event], [data-cta], [data-cta-action], [data-ga-event], a[role="button"], button, .wf-btn, .btn-primary, .button--primary'));
    }

    function __dictionaryResolve(pixelEventName, payload, element) {
        payload = payload && typeof payload === 'object' ? payload : {};
        var normalized = __normalizePixelEventName(pixelEventName || payload.eventName || '');
        var dict = (window.__WelineVisitorTrackingConfig && window.__WelineVisitorTrackingConfig.eventDictionary) || {};
        var events = Array.isArray(dict.events) ? dict.events : [];
        var ctaName = __normalizeGa4EventName((__ga4Config().ctaEventName || 'cta_click'));
        var i;
        var entry;
        // 前端声明名：data-* 与 weline-pixel:: 类（与行为监视拾取同源）
        var declaredRaw = '';
        if (typeof __findPixelEventNameFromElement === 'function' && element) {
            try {
                declaredRaw = String(__findPixelEventNameFromElement(element) || '').trim();
            } catch (eDeclared) {
                declaredRaw = '';
            }
        }
        if (!declaredRaw && element && element.closest) {
            var explicitNode = element.closest('[data-pixel-event], [data-visitor-event], [data-cta-event], [data-ga-event]');
            if (explicitNode) {
                declaredRaw = explicitNode.getAttribute('data-pixel-event') ||
                    explicitNode.getAttribute('data-visitor-event') ||
                    explicitNode.getAttribute('data-cta-event') ||
                    explicitNode.getAttribute('data-ga-event') ||
                    '';
            }
        }
        var declared = __normalizeGa4EventName(declaredRaw);
        var customMatch = payload.hit_kind === 'custom'
            || payload.source === 'custom_match'
            || payload.mapping_source === 'custom_match';
        var frontendDeclaredTrack = customMatch
            || payload.source === 'behavior_monitor'
            || payload.source === 'pixel_target'
            || !!declared;
        for (i = 0; i < events.length; i++) {
            entry = events[i] || {};
            if (__normalizePixelEventName(entry.weline_event || '') === normalized) {
                var ga4 = __normalizeGa4EventName(entry.ga4_event || '');
                if ((entry.event_family || '') === 'cta' && ctaName) {
                    ga4 = ctaName;
                }
                if (declared) {
                    ga4 = declared;
                }
                if (!ga4 && normalized) {
                    ga4 = __normalizeGa4EventName(normalized);
                }
                return {
                    weline_event: normalized,
                    ga4_event: ga4,
                    skip_gtm_push: !!entry.skip_gtm_push || ga4 === 'page_view',
                    mapping_source: (entry.event_family || '') === 'cta' && ctaName ? 'site_cta_override' : 'dictionary',
                    event_family: entry.event_family || null,
                    dict_version: dict.version || ''
                };
            }
        }
        // 元素上声明 / 自定义条件命中 / 任意具体 track 名：字典未收录也自动桥接第三方
        // （不再依赖 payload.source 是否已扁平化；normalized 即业务事件名）
        var bridgeName = declared || __normalizeGa4EventName(normalized);
        if (bridgeName && bridgeName !== 'click' && bridgeName !== 'behavior_event') {
            return {
                weline_event: normalized || bridgeName,
                ga4_event: bridgeName,
                skip_gtm_push: bridgeName === 'page_view',
                mapping_source: customMatch
                    ? 'custom_match'
                    : (declared ? 'dom_declared' : (frontendDeclaredTrack ? 'frontend_declared' : 'track_name')),
                event_family: null,
                dict_version: dict.version || ''
            };
        }
        var fallback = {
            view_item: 'view_item',
            add_to_cart: 'add_to_cart',
            begin_checkout: 'begin_checkout',
            checkout_success: 'purchase',
            checkout_failure: 'checkout_failure',
            search_submit: 'search',
            search_suggestion_click: 'select_content'
        };
        if (fallback[normalized]) {
            return {
                weline_event: normalized,
                ga4_event: fallback[normalized],
                skip_gtm_push: false,
                mapping_source: 'legacy_fallback',
                event_family: null,
                dict_version: dict.version || ''
            };
        }
        // CTA 启发式仅兜底「无声明业务名」的裸按钮；不得吞掉元素声明事件
        if (!normalized && __isGa4CtaElement(element)) {
            return {
                weline_event: 'cta_click',
                ga4_event: ctaName || 'cta_click',
                skip_gtm_push: false,
                mapping_source: 'cta_heuristic',
                event_family: 'cta',
                dict_version: dict.version || ''
            };
        }
        return {
            weline_event: normalized,
            ga4_event: '',
            skip_gtm_push: true,
            mapping_source: 'unmapped',
            event_family: null,
            dict_version: dict.version || ''
        };
    }

    window.WelineEventDictionary = {
        resolve: function (pixelEventName, element) {
            return __dictionaryResolve(pixelEventName, {}, element || null);
        }
    };

    function __resolveGa4EventName(pixelEventName, payload, element) {
        var resolved = __dictionaryResolve(pixelEventName, payload, element);
        return resolved && resolved.ga4_event ? resolved.ga4_event : '';
    }

    function __gtmConfig() {
        var section = __visitorConfigSection('gtm');
        var forwarders = __visitorConfigSection('forwarders');
        var gtmForwarder = forwarders && forwarders.gtm ? forwarders.gtm : {};
        var containerId = String((section && section.containerId) || gtmForwarder.containerId || '').trim().toUpperCase();
        var configured = /^GTM-[A-Z0-9]{4,12}$/.test(containerId);
        var enabled = configured && __visitorConfigBool((section && section.enabled) || gtmForwarder.enabled, false);
        return {
            enabled: enabled,
            configured: configured,
            containerId: configured ? containerId : ''
        };
    }

    var __visitorGtmLoaded = '';
    var __visitorGtmTriggers = [];

    function __recordVisitorGtmTrigger(entry) {
        entry = entry || {};
        entry.at = Date.now();
        __visitorGtmTriggers.unshift(entry);
        if (__visitorGtmTriggers.length > 40) {
            __visitorGtmTriggers.length = 40;
        }
        try {
            window.dispatchEvent(new CustomEvent('weline:visitor-gtm-trigger', { detail: entry }));
        } catch (e) {
        }
    }

    function __loadVisitorGtm() {
        var runtime = __gtmConfig();
        if (!runtime.enabled || !runtime.containerId) {
            return runtime;
        }
        if (__visitorGtmLoaded === runtime.containerId) {
            return runtime;
        }
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
        if (!document.querySelector('script[data-weline-visitor-gtm="true"][src*="' + runtime.containerId + '"]')) {
            var script = document.createElement('script');
            script.async = true;
            script.setAttribute('data-weline-visitor-gtm', 'true');
            script.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(runtime.containerId);
            document.head.appendChild(script);
        }
        __visitorGtmLoaded = runtime.containerId;
        return runtime;
    }

    function __clearGtmEcommerceKeys() {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            items: null,
            value: null,
            transaction_id: null,
            currency: null,
            item_list_id: null,
            item_list_name: null,
            coupon: null,
            shipping: null,
            tax: null
        });
    }

    function __pushGtmDataLayer(envelope) {
        var runtime = __loadVisitorGtm();
        envelope = envelope || {};
        var payload = envelope.payload || {};
        var resolved = __dictionaryResolve(envelope.eventName || payload.eventName, payload, envelope.element && envelope.element.domElement);
        var ga4Event = __normalizeGa4EventName((envelope.platforms && envelope.platforms.gtm && envelope.platforms.gtm.eventName) || resolved.ga4_event || '');
        if (!ga4Event || resolved.skip_gtm_push || ga4Event === 'page_view') {
            return;
        }
        if (!runtime.enabled) {
            __recordVisitorGtmTrigger({
                eventName: ga4Event,
                delivery: { mode: 'preview', label: 'GTM off' },
                params: {}
            });
            return;
        }
        var env = payload.environment || __buildEventEnvironmentContext(envelope.element && envelope.element.domElement);
        var isCommerce = /^(view_item|add_to_cart|begin_checkout|purchase|checkout_)/.test(ga4Event) || /^(view_item|add_to_cart|begin_checkout|checkout_)/.test(resolved.weline_event || '');
        if (isCommerce) {
            __clearGtmEcommerceKeys();
        }
        var row = {
            event: 'weline_visitor',
            weline_event: resolved.weline_event || envelope.eventName || '',
            ga4_event: ga4Event,
            event_id: envelope.eventId || payload.event_id || '',
            page_location: env.page_location,
            page_title: env.page_title,
            page_path: env.page_path,
            page_referrer: env.page_referrer,
            website_id: env.website_id,
            website_code: env.website_code,
            website_url: env.website_url,
            language: env.language,
            currency: env.currency === 'RMB' ? 'CNY' : env.currency,
            session_id: env.session_id,
            page_id: env.page_id,
            content_locale: env.content_locale,
            weline_dict_version: resolved.dict_version || envelope.dictVersion || '',
            weline_mapping_source: resolved.mapping_source || 'dictionary',
            section_code: envelope.section_code || '',
            section_event_key: envelope.section_event_key || '',
            section_source_status: envelope.section_source_status || 'n/a'
        };
        ['value', 'transaction_id', 'items', 'search_term', 'link_url', 'link_text', 'item_id', 'coupon'].forEach(function (key) {
            if (payload[key] !== undefined && payload[key] !== null && payload[key] !== '') {
                row[key] = payload[key];
            }
        });
        // A12：GTM dataLayer 带 sticky 归因参
        __appendStickyAttributionParams(row);
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(row);
        __recordVisitorGtmTrigger({
            eventName: ga4Event,
            params: row,
            containerId: runtime.containerId,
            delivery: { mode: 'gtm', label: 'dataLayer' }
        });
    }

    window.WelineVisitorForwarders.register('gtm', function (event) {
        __pushGtmDataLayer(event || {});
    }, {
        title: 'GTM',
        kind: 'system',
        description: 'dataLayer → GTM 容器'
    });

    window.WelineGtmBridge = {
        pushDataLayer: __pushGtmDataLayer,
        getTriggers: function () { return __visitorGtmTriggers.slice(); },
        load: __loadVisitorGtm
    };

    function __buildGa4Params(payload, meta, element) {
        payload = payload || {};
        meta = meta || {};
        var elementInfo = payload.elementInfo || meta.element || {};
        var env = payload.environment || __buildEventEnvironmentContext(element);
        var linkUrl = payload.href || elementInfo.href || (element && element.href) || '';
        if (!linkUrl && element && element.getAttribute) {
            linkUrl = element.getAttribute('href') || element.getAttribute('data-pb-ai-target') || '';
        }
        if (!linkUrl) {
            // Button / event CTAs have no href — attribute to the current page for funnel paths.
            linkUrl = env.page_location || window.location.href;
        }
        var params = {
            visitor_event_name: payload.eventName || payload.event || '',
            page_location: env.page_location,
            page_title: env.page_title,
            page_path: env.page_path,
            page_referrer: env.page_referrer,
            page_hostname: env.page_hostname,
            event_id: __visitorEventId(payload),
            pixel_name: env.pixel_name || payload.name || window.__WelinePixelName || '',
            link_url: linkUrl,
            link_text: payload.link_text || elementInfo.text || (element ? (element.innerText || element.textContent || '').trim().slice(0, 120) : ''),
            website_id: env.website_id,
            website_code: env.website_code,
            website_url: env.website_url,
            language: env.language,
            currency: env.currency,
            session_id: env.session_id,
            page_id: env.page_id,
            content_locale: env.content_locale
        };
        // GA4 推荐电商/搜索参数（含站内别名归一）
        ['value', 'currency', 'transaction_id', 'items', 'search_term', 'query', 'product_id', 'item_id', 'sku',
            'coupon', 'tax', 'shipping', 'payment_type', 'shipping_tier', 'item_list_id', 'item_list_name'].forEach(function (key) {
            if (payload[key] !== undefined && payload[key] !== null && payload[key] !== '') {
                params[key === 'query' ? 'search_term' : key] = payload[key];
            } else if (meta[key] !== undefined && meta[key] !== null && meta[key] !== '') {
                params[key === 'query' ? 'search_term' : key] = meta[key];
            }
        });
        // 别名 → GA4 推荐参数名
        if (!params.payment_type) {
            var payType = payload.payment_type || payload.payment_method || meta.payment_type || meta.payment_method || '';
            if (payType) {
                params.payment_type = payType;
            }
        }
        if (!params.shipping_tier) {
            var tier = payload.shipping_tier || payload.shipping_method || payload.service_code
                || payload.selected_service_code || meta.shipping_tier || meta.shipping_method
                || meta.service_code || meta.selected_service_code || '';
            if (tier) {
                params.shipping_tier = tier;
            }
        }
        if (!params.transaction_id) {
            var tid = payload.transaction_id || payload.transaction_no || meta.transaction_id || meta.transaction_no || '';
            if (tid) {
                params.transaction_id = tid;
            }
        }
        if (params.value === undefined || params.value === null || params.value === '') {
            var val = payload.value != null ? payload.value
                : (payload.grand_total != null ? payload.grand_total
                    : (payload.total != null ? payload.total
                        : (meta.value != null ? meta.value
                            : (meta.grand_total != null ? meta.grand_total : meta.total))));
            if (val !== undefined && val !== null && val !== '') {
                params.value = val;
            }
        }
        if (!params.currency && (payload.currency || meta.currency)) {
            params.currency = payload.currency || meta.currency;
        }
        if ((!params.items || (Array.isArray(params.items) && !params.items.length)) && Array.isArray(meta.items) && meta.items.length) {
            params.items = meta.items;
        }
        var runtime = __ga4Config();
        // 必须用布尔 true → ep.debug_mode；数字 1 会变成 epn.debug_mode，DebugView 不认。
        if (runtime.debugMode) {
            params.debug_mode = true;
        }
        if (params.currency === 'RMB') {
            params.currency = 'CNY';
        }
        // A12：GA4 转发带 sticky 归因参
        return __appendStickyAttributionParams(params);
    }

    /**
     * GA4-aligned page/site environment for funnel attribution.
     * Attached to every Visitor track payload and mirrored into GA4 params.
     */
    function __buildEventEnvironmentContext(element) {
        var websiteUrl = __getPixelSiteEnvValue('website_url', 'WELINE_WEBSITE_URL') || '';
        try {
            websiteUrl = websiteUrl ? decodeURIComponent(websiteUrl) : '';
        } catch (e) {
            // keep raw value
        }
        var pathLocale = __resolvePixelPathLocale();
        var language = __resolvePixelLanguage('');
        var pageId = '';
        try {
            pageId = (typeof __pixelPageId === 'string' && __pixelPageId) ? __pixelPageId : '';
        } catch (e3) {
            pageId = '';
        }
        var sessionId = '';
        try {
            sessionId = (typeof __getPixelSessionId === 'function') ? String(__getPixelSessionId() || '') : '';
        } catch (e4) {
            sessionId = '';
        }
        return {
            page_location: window.location.href,
            page_path: window.location.pathname || '/',
            page_title: document.title || '',
            page_referrer: document.referrer || '',
            page_hostname: window.location.hostname || '',
            page_origin: window.location.origin || '',
            page_search: window.location.search || '',
            page_hash: window.location.hash || '',
            website_id: String(__getPixelSiteEnvValue('website_id', 'WELINE_WEBSITE_ID') || ''),
            website_code: String(__getPixelSiteEnvValue('website_code', 'WELINE_WEBSITE_CODE') || ''),
            website_url: websiteUrl,
            language: language,
            currency: __resolvePixelCurrency('CNY'),
            content_locale: pathLocale || language,
            session_id: sessionId,
            page_id: pageId,
            pixel_name: window.__WelinePixelName || '',
            engagement_target: (element && element.getAttribute && element.getAttribute('data-pb-ai-action')) || ''
        };
    }

    function __sendVisitorGa4Event(pixelEventName, payload, meta, element) {
        if (__gtmConfig().enabled) {
            return;
        }
        var runtime = __loadVisitorGa4();
        var ga4EventName = __normalizeGa4EventName(payload && payload.__ga4EventName) || __resolveGa4EventName(pixelEventName, payload, element);
        if (!ga4EventName || ga4EventName === 'page_view') {
            return;
        }
        var params = __buildGa4Params(payload, meta, element);
        if (!runtime.enabled || !runtime.autoTrackVisitorEvents || !__shouldLoadGa4(runtime) || typeof window.gtag !== 'function') {
            __recordVisitorGa4Trigger({
                eventName: ga4EventName,
                params: params,
                measurementId: runtime.measurementId,
                delivery: {
                    mode: runtime.configured ? 'panel_only' : 'preview',
                    label: runtime.configured ? 'Panel only' : 'Preview',
                    blockReason: runtime.blockedReasons && runtime.blockedReasons[0] || (!runtime.configured ? 'not_configured' : 'no_gtag')
                }
            });
            return;
        }
        if (runtime.measurementId) {
            params.send_to = runtime.measurementId;
        }
        // 结账跳转等 keepalive 场景：gtag 用 beacon，避免 location.assign 截断 collect。
        if (payload && payload.__keepalive) {
            params.transport_type = 'beacon';
        }
        window.gtag('event', ga4EventName, params);
        __recordVisitorGa4Trigger({
            eventName: ga4EventName,
            params: params,
            measurementId: runtime.measurementId,
            delivery: {
                mode: 'gtag',
                label: 'Sent'
            }
        });
    }

    window.WelineVisitorForwarders.register('ga4', function (event) {
        event = event || {};
        var payload = Object.assign({}, event.payload || {});
        var ga4EventName = event.platforms && event.platforms.ga4 ? event.platforms.ga4.eventName : '';
        if (ga4EventName) {
            payload.__ga4EventName = ga4EventName;
        }
        __sendVisitorGa4Event(event.eventName || payload.eventName || payload.event, payload, event.meta || {}, event.element && event.element.domElement || null);
    }, {
        title: 'GA4',
        kind: 'system',
        description: 'gtag 直连（与 GTM 互斥）'
    });

    __loadVisitorGa4();
    __loadVisitorGtm();
    __loadCustomVisitorForwarder();
    
    /**
     * 获取配置值
     * @param {string} key - 配置键名
     * @param {*} defaultValue - 默认值
     * @returns {*} 配置值
     */
    function __getPixelConfig(key, defaultValue) {
        var config = window.__WelineThemeConfig || {};
        switch(key) {
            case 'env_model':
                if (config.env && config.env.WELINE_ENV) {
                    return config.env.WELINE_ENV === 'DEV' ? 'dev' : 'prod';
                }
                return window.DEV ? 'dev' : 'prod';
            case 'user_id':
                return (config.site && config.site.user_id) || defaultValue;
            case 'module':
                return (config.site && config.site.module) || defaultValue;
            default:
                return (config.site && config.site[key]) || defaultValue;
        }
    }
    
    /**
     * 获取前端 API URL
     * @param {string} path - API 路径
     * @returns {string} 完整的 API URL
     */
    function __getPixelApiUrl(path) {
        return 'worker:visitor.trackPixel';
        // 使用 Weline.Url.frontendApi
        if (window.Weline && window.Weline.Url && typeof window.Weline.Url.frontendApi === 'function') {
            return window.Weline.Url.frontendApi(path);
        }
        // 从父窗口获取
        if (window.parent && window.parent.Weline && window.parent.Weline.Url && typeof window.parent.Weline.Url.frontendApi === 'function') {
            return window.parent.Weline.Url.frontendApi(path);
        }
        // 从配置构建 URL
        var config = window.__WelineThemeConfig || window.parent?.__WelineThemeConfig || {};
        var baseUrl = (config.baseUrl || window.location.origin).replace(/\/$/, '');
        if (!path.startsWith('/')) {
            path = '/' + path;
        }
        if (__welinePixelFrontendApiPrefix && path.indexOf('/' + __welinePixelFrontendApiPrefix + '/') !== 0) {
            path = '/' + __welinePixelFrontendApiPrefix + path;
        }
        return baseUrl + path;
    }
    
    /**
     * 获取 Cookie 值
     * @param {string} name - Cookie 名称
     * @returns {string} Cookie 值
     */
    function __getPixelCookie(name) {
        var value = "; " + document.cookie;
        var parts = value.split("; " + name + "=");
        if (parts.length === 2) {
            return parts.pop().split(";").shift();
        }
        return '';
    }

    /**
     * Prefer server-injected __WelinePixelEnv.
     * Website cookies (WELINE_WEBSITE_*) remain readable; language/currency
     * preference cookies (WELINE_USER_LANG / WELINE_USER_CURRENCY) are never used.
     */
    function __getPixelInjectedEnvValue(key) {
        try {
            var injected = window.__WelinePixelEnv;
            if (injected && typeof injected === 'object' && injected[key] !== undefined && injected[key] !== null && injected[key] !== '') {
                return String(injected[key]);
            }
        } catch (e) {
        }
        return '';
    }

    function __getPixelSiteEnvValue(key, cookieName) {
        var injected = __getPixelInjectedEnvValue(key);
        if (injected) {
            return injected;
        }
        var name = String(cookieName || '');
        if (name.indexOf('WELINE_WEBSITE_') === 0) {
            return __getPixelCookie(name) || '';
        }
        return '';
    }

    function __resolvePixelPathLocale() {
        try {
            var segments = String(window.location.pathname || '').split('/').filter(Boolean);
            var langPattern = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
            for (var i = 0; i < Math.min(segments.length, 3); i++) {
                if (langPattern.test(segments[i])) {
                    return segments[i];
                }
            }
        } catch (e) {
        }
        return '';
    }

    function __resolvePixelQueryLanguage() {
        try {
            var urlParams = new URLSearchParams((window.location && window.location.search) || '');
            var candidates = [urlParams.get('locale'), urlParams.get('locale_code'), urlParams.get('lang')];
            for (var i = 0; i < candidates.length; i++) {
                var raw = String(candidates[i] || '').trim();
                if (!raw || raw.toLowerCase() === 'default') {
                    continue;
                }
                return raw.replace(/-/g, '_');
            }
        } catch (e) {
        }
        return '';
    }

    function __resolvePixelDocumentLanguage() {
        try {
            var el = document.documentElement;
            if (!el) {
                return '';
            }
            var attr = (typeof el.getAttribute === 'function')
                ? (el.getAttribute('data-lang') || el.getAttribute('lang') || '')
                : '';
            return String(attr || el.lang || '').trim().replace(/-/g, '_');
        } catch (e) {
        }
        return '';
    }

    function __resolvePixelPathCurrency() {
        try {
            var segments = String(window.location.pathname || '').split('/').filter(Boolean);
            for (var i = 0; i < Math.min(segments.length, 4); i++) {
                if (__isPixelSupportedCurrencySegment(segments[i])) {
                    return String(segments[i]).toUpperCase();
                }
            }
        } catch (e) {
        }
        return '';
    }

    function __resolvePixelQueryCurrency() {
        try {
            var code = String(new URLSearchParams((window.location && window.location.search) || '').get('currency') || '')
                .trim()
                .toUpperCase();
            if (__isPixelSupportedCurrencySegment(code) || /^[A-Z]{3}$/.test(code)) {
                return code === 'RMB' ? 'CNY' : code;
            }
        } catch (e) {
        }
        return '';
    }

    function __resolvePixelDocumentCurrency() {
        try {
            var el = document.documentElement;
            if (!el || typeof el.getAttribute !== 'function') {
                return '';
            }
            var code = String(el.getAttribute('data-currency') || '').trim().toUpperCase();
            if (/^[A-Z]{3}$/.test(code)) {
                return code === 'RMB' ? 'CNY' : code;
            }
        } catch (e) {
        }
        return '';
    }

    /** inject → path → query/data-* → hard default; never preference cookies. */
    function __resolvePixelLanguage(fallback) {
        var resolved = __getPixelInjectedEnvValue('language')
            || __resolvePixelPathLocale()
            || __resolvePixelQueryLanguage()
            || __resolvePixelDocumentLanguage()
            || String(fallback || '').trim()
            || 'zh_Hans_CN';
        return resolved === 'RMB' ? 'CNY' : resolved;
    }

    function __resolvePixelCurrency(fallback) {
        var resolved = __getPixelInjectedEnvValue('currency')
            || __resolvePixelPathCurrency()
            || __resolvePixelQueryCurrency()
            || __resolvePixelDocumentCurrency()
            || String(fallback || '').trim()
            || 'CNY';
        resolved = String(resolved).toUpperCase();
        return resolved === 'RMB' ? 'CNY' : resolved;
    }

    function __isPixelSupportedCurrencySegment(segment) {
        var code = String(segment || '').trim().toUpperCase();
        if (!/^[A-Z]{3}$/.test(code)) {
            return false;
        }
        var cfg = window.__WelineThemeConfig || {};
        var site = window.site || {};
        var welineConfig = (window.Weline && window.Weline.config) || {};
        var supported = {};
        [
            cfg.availableCurrencies,
            cfg.supportedCurrencies,
            cfg.currencyCodes,
            cfg.currencies,
            site.availableCurrencies,
            site.supportedCurrencies,
            site.currencyCodes,
            site.currencies,
            welineConfig.availableCurrencies,
            welineConfig.supportedCurrencies,
            welineConfig.currencyCodes,
            welineConfig.currencies,
            cfg.defaultCurrency,
            site.defaultCurrency,
            site.default_currency
        ].forEach(function(source) {
            if (Array.isArray(source)) {
                source.forEach(function(entry) {
                    if (entry && typeof entry === 'object') {
                        entry = entry.code || entry.currency || entry.currency_code || entry.value || '';
                    }
                    var item = String(entry || '').trim().toUpperCase();
                    if (/^[A-Z]{3}$/.test(item)) {
                        supported[item] = true;
                    }
                });
                return;
            }
            var item = String(source || '').trim().toUpperCase();
            if (/^[A-Z]{3}$/.test(item)) {
                supported[item] = true;
            }
        });
        return supported[code] === true;
    }
    
    /**
     * 清理 URL 路径中的区域、货币、语言等前缀
     * @param {string} path - 原始路径
     * @returns {string} 清理后的路径
     */
    function __getPixelAppPath(path) {
        if (!path || path.indexOf('://') < 0) {
            return path;
        }
        
        try {
            var urlObj = new URL(path);
            var parts = urlObj.pathname.split('/').filter(function(p) { return p !== ''; });
            
            var langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
            var areaPattern = /^(frontend|backend|admin|api|api_admin)$/i;
            var websiteCode = __getPixelCookie('WELINE_WEBSITE_CODE');
            
            var filtered = [];
            for (var i = 0; i < parts.length; i++) {
                var part = parts[i];
                if (websiteCode && part === websiteCode) continue;
                if (areaPattern.test(part)) continue;
                if (__isPixelSupportedCurrencySegment(part)) continue;
                if (langPattern.test(part)) continue;
                filtered.push(part);
            }
            
            urlObj.pathname = '/' + filtered.join('/');
            return urlObj.toString();
        } catch (e) {
            return path;
        }
    }

    var __pixelSessionKey = 'weline_pixel_session_id';
    var __pixelFunnelKey = 'weline_pixel_funnel_chain';
    var __pixelPageStart = Date.now();
    var __pixelPagePerfStart = (window.performance && typeof window.performance.now === 'function') ? window.performance.now() : 0;
    var __pixelPageId = (__pixelPageStart.toString(36) + '-' + Math.random().toString(36).slice(2, 10));
    var __pixelLastSearchInputTimer = null;
    var __pixelLastLocation = window.location.href;
    var __pixelPageExitSent = false;
    /** 本轮「页面隐藏」是否已冲刷；visible 后复位，避免切标签误记为 page_exit。 */
    var __pixelPageHideCycleSent = false;
    /**
     * 像素多为 pageshow 之后懒加载，不能靠事后监听 pageshow 判「已展示」。
     * 仅以「是否对用户可见过」门闩：后台打开/预渲染未见光不算退出。
     */
    var __pixelPageWasVisible = (typeof document !== 'undefined' && document.visibilityState === 'visible');

    function __safeJsonParse(value, fallback) {
        try {
            return JSON.parse(value);
        } catch (e) {
            return fallback;
        }
    }

    function __safeSessionGet(key) {
        try {
            return window.sessionStorage ? window.sessionStorage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function __safeSessionSet(key, value) {
        try {
            if (window.sessionStorage) {
                window.sessionStorage.setItem(key, value);
            }
        } catch (e) {
        }
    }

    function __getPixelSessionId() {
        var sessionId = __safeSessionGet(__pixelSessionKey);
        if (!sessionId) {
            sessionId = 'wps-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
            __safeSessionSet(__pixelSessionKey, sessionId);
        }
        return sessionId;
    }

    var PIXEL_SCRIPT_VERSION = '2026.09.19-sticky-bus1';
    var __pixelStickyStorageKey = 'weline_pixel_sticky_utm';
    var __pixelStickyCookieName = 'WELINE_PIXEL_STICKY_UTM';

    function __stickyTtlMs() {
        var stickyCfg = __visitorConfigSection('sticky') || {};
        var hours = Number(stickyCfg.ttlHours != null ? stickyCfg.ttlHours : stickyCfg.ttl_hours);
        if (!isFinite(hours) || hours <= 0) {
            hours = 24;
        }
        return Math.round(hours * 3600 * 1000);
    }

    function __safeLocalGet(key) {
        try {
            return window.localStorage ? window.localStorage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function __safeLocalSet(key, value) {
        try {
            if (window.localStorage) {
                window.localStorage.setItem(key, value);
            }
        } catch (e) {
        }
    }

    function __setPixelCookie(name, value, maxAgeSec) {
        try {
            var encoded = encodeURIComponent(String(value == null ? '' : value));
            var parts = [name + '=' + encoded, 'path=/', 'SameSite=Lax'];
            var age = Number(maxAgeSec);
            if (isFinite(age) && age > 0) {
                parts.push('max-age=' + Math.round(age));
            }
            if (window.location && window.location.protocol === 'https:') {
                parts.push('Secure');
            }
            document.cookie = parts.join('; ');
        } catch (e) {
        }
    }

    function __captureMarketingPackFromSearch(search) {
        var params;
        try {
            params = new URLSearchParams(search || '');
        } catch (e) {
            params = { get: function () { return ''; } };
        }
        function pick() {
            for (var i = 0; i < arguments.length; i++) {
                var v = params.get(arguments[i]);
                if (v) {
                    return String(v).slice(0, 255);
                }
            }
            return '';
        }
        return {
            wch: pick('wch', 'channel_code'),
            utm_source: pick('utm_source'),
            utm_medium: pick('utm_medium'),
            utm_campaign: pick('utm_campaign'),
            utm_content: pick('utm_content'),
            utm_term: pick('utm_term'),
            gclid: pick('gclid'),
            fbclid: pick('fbclid'),
            msclkid: pick('msclkid')
        };
    }

    function __marketingPackHasSignal(pack) {
        if (!pack || typeof pack !== 'object') {
            return false;
        }
        var keys = ['wch', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'msclkid'];
        for (var i = 0; i < keys.length; i++) {
            if (String(pack[keys[i]] || '') !== '') {
                return true;
            }
        }
        return false;
    }

    function __normalizeStickyPack(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        var pack = {
            wch: String(raw.wch || raw.channel_code || '').slice(0, 64),
            utm_source: String(raw.utm_source || raw.source || '').slice(0, 255),
            utm_medium: String(raw.utm_medium || raw.medium || '').slice(0, 255),
            utm_campaign: String(raw.utm_campaign || raw.campaign || '').slice(0, 255),
            utm_content: String(raw.utm_content || raw.content || '').slice(0, 255),
            utm_term: String(raw.utm_term || raw.term || '').slice(0, 255),
            gclid: String(raw.gclid || '').slice(0, 255),
            fbclid: String(raw.fbclid || '').slice(0, 255),
            msclkid: String(raw.msclkid || '').slice(0, 255),
            locked_at: Number(raw.locked_at) || 0,
            locked_at_iso: String(raw.locked_at_iso || '')
        };
        if (!__marketingPackHasSignal(pack)) {
            return null;
        }
        if (!pack.locked_at) {
            pack.locked_at = Date.now();
            pack.locked_at_iso = new Date(pack.locked_at).toISOString();
        }
        return pack;
    }

    function __parseStickyRaw(raw) {
        if (!raw) {
            return null;
        }
        var parsed = typeof raw === 'string' ? __safeJsonParse(raw, null) : raw;
        var pack = __normalizeStickyPack(parsed);
        if (!pack) {
            return null;
        }
        if ((Date.now() - pack.locked_at) > __stickyTtlMs()) {
            return null;
        }
        return pack;
    }

    function __readStickyUtmPack() {
        var fromLocal = __parseStickyRaw(__safeLocalGet(__pixelStickyStorageKey));
        var fromSession = __parseStickyRaw(__safeSessionGet(__pixelStickyStorageKey));
        var fromCookie = null;
        try {
            var cookieRaw = __getPixelCookie(__pixelStickyCookieName);
            if (cookieRaw) {
                fromCookie = __parseStickyRaw(decodeURIComponent(cookieRaw));
            }
        } catch (e) {
            fromCookie = __parseStickyRaw(__getPixelCookie(__pixelStickyCookieName));
        }
        var candidates = [fromLocal, fromSession, fromCookie].filter(Boolean);
        if (!candidates.length) {
            return null;
        }
        candidates.sort(function (a, b) {
            return (a.locked_at || 0) - (b.locked_at || 0);
        });
        return candidates[0];
    }

    function __persistStickyUtmPack(pack) {
        if (!__visitorMarketingConsentAllowsStorage()) {
            return null;
        }
        var normalized = __normalizeStickyPack(pack);
        if (!normalized) {
            return null;
        }
        var serialized = JSON.stringify(normalized);
        __safeLocalSet(__pixelStickyStorageKey, serialized);
        __safeSessionSet(__pixelStickyStorageKey, serialized);
        __setPixelCookie(__pixelStickyCookieName, serialized, Math.round(__stickyTtlMs() / 1000));
        return normalized;
    }

    function __ensureStickyUtmLocked() {
        if (!__visitorMarketingConsentAllowsStorage()) {
            return null;
        }
        var existing = __readStickyUtmPack();
        var fromUrl = __captureMarketingPackFromSearch(window.location.search || '');
        if (__marketingPackHasSignal(fromUrl)) {
            if (!existing) {
                var now = Date.now();
                existing = __persistStickyUtmPack(Object.assign({}, fromUrl, {
                    locked_at: now,
                    locked_at_iso: new Date(now).toISOString()
                }));
            } else {
                // 首触锁定：已有有效 sticky 时忽略 URL 新参；locked_at 更早者胜
                __persistStickyUtmPack(existing);
            }
        }
        return existing;
    }

    function __getStickyUtmPack() {
        if (!__visitorMarketingConsentAllowsStorage()) {
            return null;
        }
        return __ensureStickyUtmLocked();
    }


    function __appendStickyAttributionParams(target) {
        target = target || {};
        if (typeof __getStickyUtmPack !== 'function') {
            return target;
        }
        var pack = null;
        try {
            pack = __getStickyUtmPack();
        } catch (e) {
            pack = null;
        }
        if (!pack) {
            return target;
        }
        var pairs = [
            ['wch', pack.wch],
            ['utm_source', pack.utm_source],
            ['utm_medium', pack.utm_medium],
            ['utm_campaign', pack.utm_campaign],
            ['utm_content', pack.utm_content],
            ['utm_term', pack.utm_term],
            ['gclid', pack.gclid],
            ['fbclid', pack.fbclid],
            ['msclkid', pack.msclkid]
        ];
        for (var i = 0; i < pairs.length; i++) {
            var key = pairs[i][0];
            var value = String(pairs[i][1] || '').trim();
            if (value !== '' && (target[key] === undefined || target[key] === null || target[key] === '')) {
                target[key] = value.slice(0, 255);
            }
        }
        // GA4 常见别名，便于调试/报表可见
        if (target.utm_source && !target.source) {
            target.source = target.utm_source;
        }
        if (target.utm_medium && !target.medium) {
            target.medium = target.utm_medium;
        }
        if (target.utm_campaign && !target.campaign) {
            target.campaign = target.utm_campaign;
        }
        if (target.utm_content && !target.content) {
            target.content = target.utm_content;
        }
        if (target.utm_term && !target.term) {
            target.term = target.utm_term;
        }
        if (pack.locked_at && !target.sticky_locked_at) {
            target.sticky_locked_at = pack.locked_at;
        }
        return target;
    }

    var __stickyLinkerTimer = null;
    var __stickyLinkerObserver = null;
    var __stickyLinkerAttr = 'data-weline-sticky-href';

    function __stickyParamMap(pack) {
        if (!pack || typeof pack !== 'object') {
            return {};
        }
        var map = {};
        var pairs = [
            ['wch', pack.wch],
            ['utm_source', pack.utm_source],
            ['utm_medium', pack.utm_medium],
            ['utm_campaign', pack.utm_campaign],
            ['utm_content', pack.utm_content],
            ['utm_term', pack.utm_term],
            ['gclid', pack.gclid],
            ['fbclid', pack.fbclid],
            ['msclkid', pack.msclkid]
        ];
        for (var i = 0; i < pairs.length; i++) {
            var key = pairs[i][0];
            var value = String(pairs[i][1] || '').trim();
            if (value !== '') {
                map[key] = value.slice(0, 255);
            }
        }
        return map;
    }

    function __isInternalStickyHref(href) {
        var raw = String(href || '').trim();
        if (!raw || raw.charAt(0) === '#') {
            return false;
        }
        var lower = raw.toLowerCase();
        if (lower.indexOf('javascript:') === 0
            || lower.indexOf('mailto:') === 0
            || lower.indexOf('tel:') === 0
            || lower.indexOf('sms:') === 0
            || lower.indexOf('data:') === 0) {
            return false;
        }
        try {
            var url = new URL(raw, window.location.href);
            if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                return false;
            }
            return url.host === window.location.host;
        } catch (e) {
            return false;
        }
    }

    function __applyStickyParamsToHref(href, pack) {
        var params = __stickyParamMap(pack);
        var keys = Object.keys(params);
        if (!keys.length || !__isInternalStickyHref(href)) {
            return href;
        }
        try {
            var url = new URL(String(href), window.location.href);
            for (var i = 0; i < keys.length; i++) {
                url.searchParams.set(keys[i], params[keys[i]]);
            }
            // Preserve relative form when original was path-relative / root-relative
            var original = String(href);
            if (original.indexOf('//') === 0) {
                return url.toString().replace(/^https?:/, '');
            }
            if (/^https?:\/\//i.test(original)) {
                return url.toString();
            }
            return url.pathname + url.search + url.hash;
        } catch (e) {
            return href;
        }
    }

    function __rewriteAnchorHref(anchor, pack) {
        if (!anchor || !anchor.getAttribute || anchor.tagName !== 'A') {
            return false;
        }
        if (anchor.hasAttribute('data-weline-sticky-skip')) {
            return false;
        }
        var currentHref = anchor.getAttribute('href');
        if (currentHref == null) {
            return false;
        }
        if (!anchor.hasAttribute(__stickyLinkerAttr)) {
            anchor.setAttribute(__stickyLinkerAttr, currentHref);
        }
        var baseHref = anchor.getAttribute(__stickyLinkerAttr) || currentHref;
        if (!__isInternalStickyHref(baseHref)) {
            return false;
        }
        var nextHref = __applyStickyParamsToHref(baseHref, pack);
        if (nextHref && nextHref !== currentHref) {
            anchor.setAttribute('href', nextHref);
            return true;
        }
        return false;
    }

    function __rewriteStickyAnchors(root) {
        if (!__stickyLinkerEnabled()) {
            return 0;
        }
        var pack = __getStickyUtmPack();
        if (!pack) {
            return 0;
        }
        var scope = root && root.querySelectorAll ? root : document;
        var anchors;
        try {
            anchors = scope.querySelectorAll('a[href]');
        } catch (e) {
            return 0;
        }
        var changed = 0;
        for (var i = 0; i < anchors.length; i++) {
            if (__rewriteAnchorHref(anchors[i], pack)) {
                changed++;
            }
        }
        return changed;
    }

    function __scheduleStickyAnchorRewrite(delayMs) {
        if (!__stickyLinkerEnabled()) {
            return;
        }
        if (__stickyLinkerTimer) {
            window.clearTimeout(__stickyLinkerTimer);
        }
        __stickyLinkerTimer = window.setTimeout(function () {
            __stickyLinkerTimer = null;
            __rewriteStickyAnchors(document);
        }, typeof delayMs === 'number' ? delayMs : 120);
    }

    function __collectStickyRewriteRoots(records, bucket) {
        if (!records || !records.length) {
            return;
        }
        for (var i = 0; i < records.length; i++) {
            var mutation = records[i];
            if (!mutation || mutation.type !== 'childList' || !mutation.addedNodes) {
                continue;
            }
            for (var n = 0; n < mutation.addedNodes.length; n++) {
                var node = mutation.addedNodes[n];
                if (node && node.nodeType === 1) {
                    bucket.push(node);
                }
            }
        }
    }

    function __rewriteStickyAnchorNodes(nodes) {
        if (!nodes || !nodes.length || !__stickyLinkerEnabled()) {
            return;
        }
        var pack = __getStickyUtmPack();
        if (!pack) {
            return;
        }
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (!node || node.nodeType !== 1) {
                continue;
            }
            if (node.tagName === 'A') {
                __rewriteAnchorHref(node, pack);
            }
            if (!node.querySelectorAll) {
                continue;
            }
            var nested;
            try {
                nested = node.querySelectorAll('a[href]');
            } catch (e) {
                continue;
            }
            for (var j = 0; j < nested.length; j++) {
                __rewriteAnchorHref(nested[j], pack);
            }
        }
    }

    function __initStickyUtmLinker() {
        if (window.__WelineStickyUtmLinkerLoaded) {
            return;
        }
        window.__WelineStickyUtmLinkerLoaded = true;
        if (!__stickyLinkerEnabled()) {
            return;
        }
        __rewriteStickyAnchors(document);
        if (typeof MutationObserver !== 'function' || !document.documentElement) {
            return;
        }
        var pending = [];
        var observeOptions = { childList: true, subtree: true };
        var spec = {
            target: document.documentElement,
            options: observeOptions,
            idleTimeoutMs: 100,
            label: 'visitor-pixel:sticky-utm',
            onRecords: function (records) {
                __collectStickyRewriteRoots(records, pending);
            },
            onFlush: function () {
                var batch = pending;
                pending = [];
                __rewriteStickyAnchorNodes(batch);
            }
        };
        var observeApi = (window.Weline && window.Weline.dom && typeof window.Weline.dom.observe === 'function')
            ? window.Weline.dom.observe.bind(window.Weline.dom)
            : (window.Weline && typeof window.Weline.observeMutationsCoalesced === 'function'
                ? window.Weline.observeMutationsCoalesced
                : null);
        if (observeApi) {
            try {
                __stickyLinkerObserver = observeApi(spec);
            } catch (e) {
            }
            return;
        }
        /* ARCH_MO_FALLBACK_START */
        try {
            var mo = new MutationObserver(function (records) {
                try { mo.disconnect(); } catch (err) {}
                __collectStickyRewriteRoots(records, pending);
                window.setTimeout(function () {
                    var batch = pending;
                    pending = [];
                    __rewriteStickyAnchorNodes(batch);
                    if (document.documentElement) {
                        try { mo.observe(document.documentElement, observeOptions); } catch (err2) {}
                    }
                }, 100);
            });
            mo.observe(document.documentElement, observeOptions);
            __stickyLinkerObserver = mo;
        } catch (e) {
        }
        /* ARCH_MO_FALLBACK_END */
    }


    function __stickyFormMergeEnabled() {
        if (!__visitorPixelEnabled()) {
            return false;
        }
        var stickyCfg = __visitorConfigSection('sticky') || {};
        var flag = stickyCfg.formMergeEnabled != null ? stickyCfg.formMergeEnabled : stickyCfg.form_merge_enabled;
        // A11：默认关闭
        if (!__visitorConfigBool(flag, false)) {
            return false;
        }
        return __visitorMarketingConsentAllowsStorage();
    }

    function __isGetForm(form) {
        if (!form || !form.tagName || form.tagName !== 'FORM') {
            return false;
        }
        var method = String(form.getAttribute('method') || form.method || 'get').trim().toLowerCase();
        return method === '' || method === 'get';
    }

    function __formActionIsInternal(form) {
        var action = form.getAttribute('action');
        if (action == null || String(action).trim() === '') {
            return true; // submit to current page
        }
        return __isInternalStickyHref(action);
    }

    function __mergeStickyIntoForm(form) {
        if (!__stickyFormMergeEnabled() || !__isGetForm(form) || !__formActionIsInternal(form)) {
            return 0;
        }
        if (form.hasAttribute('data-weline-sticky-skip')) {
            return 0;
        }
        var pack = __getStickyUtmPack();
        var params = __stickyParamMap(pack);
        var keys = Object.keys(params);
        if (!keys.length) {
            return 0;
        }
        var added = 0;
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var value = params[key];
            if (!value) {
                continue;
            }
            var existing = null;
            try {
                existing = form.querySelector('[name="' + key.replace(/"/g, '\\"') + '"]');
            } catch (e) {
                existing = form.elements && form.elements.namedItem ? form.elements.namedItem(key) : null;
            }
            if (existing) {
                continue;
            }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            input.setAttribute('data-weline-sticky-field', '1');
            form.appendChild(input);
            added++;
        }
        return added;
    }

    function __initStickyFormMerge() {
        if (window.__WelineStickyFormMergeLoaded) {
            return;
        }
        window.__WelineStickyFormMergeLoaded = true;
        document.addEventListener('submit', function (event) {
            if (!__stickyFormMergeEnabled()) {
                return;
            }
            var form = event.target;
            if (!form || form.tagName !== 'FORM') {
                return;
            }
            __mergeStickyIntoForm(form);
        }, true);
    }

    function __getPixelPerformanceTiming() {
        var timing = {
            page_started_at_ms: __pixelPageStart,
            page_age_ms: Date.now() - __pixelPageStart,
            perf_now_ms: window.performance && typeof window.performance.now === 'function' ? Math.round(window.performance.now()) : null,
            time_origin_ms: window.performance && window.performance.timeOrigin ? Math.round(window.performance.timeOrigin) : null
        };

        try {
            var nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
            if (nav) {
                timing.navigation = {
                    type: nav.type || '',
                    start_time_ms: Math.round(nav.startTime || 0),
                    dom_interactive_ms: Math.round(nav.domInteractive || 0),
                    dom_content_loaded_ms: Math.round(nav.domContentLoadedEventEnd || 0),
                    load_event_end_ms: Math.round(nav.loadEventEnd || 0),
                    response_start_ms: Math.round(nav.responseStart || 0),
                    response_end_ms: Math.round(nav.responseEnd || 0),
                    transfer_size: nav.transferSize || 0,
                    encoded_body_size: nav.encodedBodySize || 0,
                    decoded_body_size: nav.decodedBodySize || 0
                };
            }

            var paints = performance.getEntriesByType ? performance.getEntriesByType('paint') : [];
            timing.paint = {};
            for (var i = 0; i < paints.length; i++) {
                timing.paint[paints[i].name.replace(/-/g, '_') + '_ms'] = Math.round(paints[i].startTime || 0);
            }

            var resources = performance.getEntriesByType ? performance.getEntriesByType('resource') : [];
                timing.resource_summary = {
                    count: resources.length,
                    slowest: resources.slice().sort(function (a, b) {
                        return (b.duration || 0) - (a.duration || 0);
                    }).slice(0, 3).map(function (entry) {
                        return {
                            name: String(entry.name || '').slice(0, 96),
                            initiator_type: entry.initiatorType || '',
                            duration_ms: Math.round(entry.duration || 0),
                            transfer_size: entry.transferSize || 0
                        };
                    })
            };
        } catch (e) {
        }

        return timing;
    }

    function __getPixelTimeData(eventStartedAt) {
        var now = new Date();
        var startedAt = eventStartedAt || Date.now();
        return {
            event_timestamp: now.toISOString(),
            event_timestamp_ms: now.getTime(),
            event_started_at_ms: startedAt,
            event_elapsed_ms: Math.max(0, Date.now() - startedAt),
            page_elapsed_ms: Math.max(0, Date.now() - __pixelPageStart),
            perf_elapsed_ms: window.performance && typeof window.performance.now === 'function'
                ? Math.max(0, Math.round(window.performance.now() - __pixelPagePerfStart))
                : null,
            local_datetime: now.toLocaleString(),
            timezone: Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '',
            timezone_offset_minutes: now.getTimezoneOffset()
        };
    }

    function __normalizePixelEventName(eventName) {
        return String(eventName || 'click').replace(/-/g, '_');
    }

    function __getFunnelStep(eventName) {
        var normalized = __normalizePixelEventName(eventName);
        var map = {
            page_view: 10,
            page_enter: 10,
            search_focus: 20,
            search_input: 21,
            search_submit: 30,
            search_suggestion_click: 31,
            view_item: 40,
            add_to_wishlist: 50,
            add_to_cart: 60,
            view_cart: 70,
            begin_checkout: 80,
            place_order: 90,
            checkout_success: 100,
            checkout_failure: 100
        };
        return map[normalized] || 0;
    }

    function __getFunnelChain(eventName) {
        var chain = __safeJsonParse(__safeSessionGet(__pixelFunnelKey) || '[]', []);
        if (!Array.isArray(chain)) {
            chain = [];
        }
        var now = Date.now();
        var previous = chain.length ? chain[chain.length - 1] : null;
        var item = {
            event: __normalizePixelEventName(eventName),
            step: __getFunnelStep(eventName),
            url: window.location.href,
            path: window.location.pathname,
            page_id: __pixelPageId,
            timestamp_ms: now,
            since_previous_ms: previous ? Math.max(0, now - (previous.timestamp_ms || now)) : null
        };
        chain.push(item);
        if (chain.length > 12) {
            chain = chain.slice(chain.length - 12);
        }
        __safeSessionSet(__pixelFunnelKey, JSON.stringify(chain));
        return {
            session_id: __getPixelSessionId(),
            page_id: __pixelPageId,
            step: item.step,
            step_index: chain.length,
            previous_event: previous ? previous.event : null,
            since_previous_ms: item.since_previous_ms,
            chain: chain.slice(Math.max(0, chain.length - 8))
        };
    }

    function __buildPixelBehaviorInfo(eventName, meta, eventStartedAt) {
        var environment = __buildEventEnvironmentContext((meta && meta.domElement) || null);
        var device = __getPixelDeviceContext();
        var utm = __getPixelUtmContext();
        var sticky = __getStickyUtmPack();
        var timeData = __getPixelTimeData(eventStartedAt);
        var dwellMs = 0;
        if (meta && meta.duration_ms) {
            dwellMs = Number(meta.duration_ms) || 0;
        }
        var engagedEvents = {
            cta_click: 1, contact_click: 1, lead_submit: 1, hero_cta_click: 1,
            add_to_cart: 1, begin_checkout: 1, purchase: 1, checkout_success: 1,
            search_submit: 1, login: 1, register: 1
        };
        return {
            schema: 'weline_behavior_timing_v2',
            time: timeData,
            performance: __getPixelPerformanceTiming(),
            funnel: __getFunnelChain(eventName),
            environment: environment,
            device: device,
            utm: utm,
            sticky: sticky || null,
            engagement: {
                engaged: !!engagedEvents[String(eventName || '')] || dwellMs >= 10000,
                dwell_ms: dwellMs,
                page_elapsed_ms: Number(timeData && timeData.page_elapsed_ms) || 0
            },
            navigation: {
                current_url: environment.page_location,
                current_path: environment.page_path,
                current_search: environment.page_search,
                current_hash: environment.page_hash,
                referrer: environment.page_referrer,
                last_location: __pixelLastLocation,
                website_id: environment.website_id,
                website_code: environment.website_code,
                website_url: environment.website_url,
                language: environment.language,
                content_locale: environment.content_locale
            },
            viewport: {
                inner_width: window.innerWidth,
                inner_height: window.innerHeight,
                outer_width: window.outerWidth,
                outer_height: window.outerHeight,
                device_pixel_ratio: window.devicePixelRatio || 1,
                visibility_state: document.visibilityState || ''
            },
            meta: meta || {}
        };
    }

    function __getPixelDeviceContext() {
        var width = Number(window.screen && window.screen.width) || Number(window.innerWidth) || 0;
        var height = Number(window.screen && window.screen.height) || Number(window.innerHeight) || 0;
        var touch = !!(navigator.maxTouchPoints && navigator.maxTouchPoints > 0);
        var category = 'desktop';
        if (width > 0 && width < 768) {
            category = 'mobile';
        } else if ((width >= 768 && width < 1024) || (touch && width < 1280)) {
            category = width < 768 ? 'mobile' : 'tablet';
        }
        return {
            category: category,
            platform: navigator.platform || '',
            language: navigator.language || '',
            screen_width: width,
            screen_height: height,
            color_depth: (window.screen && window.screen.colorDepth) || 0,
            timezone_offset: new Date().getTimezoneOffset(),
            touch: touch
        };
    }

    function __getPixelUtmContext() {
        var pack = __captureMarketingPackFromSearch(window.location.search || '');
        return {
            source: pack.utm_source || '',
            medium: pack.utm_medium || '',
            campaign: pack.utm_campaign || '',
            content: pack.utm_content || '',
            term: pack.utm_term || '',
            gclid: pack.gclid || '',
            fbclid: pack.fbclid || '',
            msclkid: pack.msclkid || '',
            wch: pack.wch || ''
        };
    }

    function __findPixelEventNameFromElement(element) {
        var current = element;
        while (current && current !== document) {
            if (current.getAttribute) {
                var attr = current.getAttribute('data-visitor-event')
                    || current.getAttribute('data-pixel-event')
                    || '';
                attr = String(attr || '').trim();
                if (attr) {
                    return attr;
                }
            }
            var className = typeof current.className === 'string' ? current.className : '';
            if (className.indexOf('weline-pixel::') > -1) {
                var classNames = className.split(/\s+/);
                for (var i = 0; i < classNames.length; i++) {
                    if (classNames[i].indexOf('weline-pixel::') === 0 && classNames[i].indexOf(':value') === -1) {
                        return classNames[i].replace('weline-pixel::', '');
                    }
                }
            }
            current = current.parentNode;
        }
        return '';
    }

    function __getElementSnapshot(element) {
        if (!element || !element.tagName) {
            return null;
        }
        return {
            tagName: element.tagName,
            className: typeof element.className === 'string' ? element.className : '',
            id: element.id || '',
            name: element.getAttribute ? (element.getAttribute('name') || '') : '',
            type: element.getAttribute ? (element.getAttribute('type') || '') : '',
            href: element.href || (element.getAttribute ? element.getAttribute('href') : null),
            text: (element.innerText || element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 180)
        };
    }

    function __pixelText(element) {
        return element ? String(element.innerText || element.textContent || '').trim().replace(/\s+/g, ' ') : '';
    }

    function __pixelNumber(value) {
        if (typeof value === 'number') {
            return isFinite(value) ? value : null;
        }
        var cleaned = String(value || '').replace(/,/g, '').replace(/[^0-9.\-]+/g, '');
        if (cleaned === '') {
            return null;
        }
        var parsed = Number(cleaned);
        return isFinite(parsed) ? parsed : null;
    }

    function __firstAttr(element, names) {
        if (!element || !element.getAttribute) {
            return '';
        }
        for (var i = 0; i < names.length; i++) {
            var value = element.getAttribute(names[i]);
            if (value !== null && String(value).trim() !== '') {
                return String(value).trim();
            }
        }
        return '';
    }

    function __closestAttr(element, names) {
        var current = element;
        while (current && current !== document) {
            var value = __firstAttr(current, names);
            if (value !== '') {
                return value;
            }
            current = current.parentElement;
        }
        return '';
    }

    function __firstText(root, selectors) {
        if (!root || !root.querySelector) {
            return '';
        }
        for (var i = 0; i < selectors.length; i++) {
            var node = root.querySelector(selectors[i]);
            var text = __pixelText(node);
            if (text !== '') {
                return text;
            }
        }
        return '';
    }

    function __firstNumber(root, selectors) {
        if (!root || !root.querySelector) {
            return null;
        }
        for (var i = 0; i < selectors.length; i++) {
            var node = root.querySelector(selectors[i]);
            if (!node) {
                continue;
            }
            var number = __pixelNumber(node.getAttribute('data-pixel-value') || node.getAttribute('data-price') || node.value || __pixelText(node));
            if (number !== null) {
                return number;
            }
        }
        return null;
    }

    function __getPixelContext(element) {
        if (!element || !element.closest) {
            return element || document;
        }
        return element.closest('.product-native-detail, [data-testid="storefront-product-detail"], .product-detail-view, .product-detail, .product-main, .product-info, .product-info-section, .product-card, .cart-item, .cart-summary, .cart-summary-card, .mini-cart-drawer, .checkout-summary, .weshop-checkout-summary, .weshop-checkout-card, form')
            || element.closest('[data-product-id], [data-item-id]')
            || element.closest('main, article, section, body')
            || document;
    }

    function __resolvePixelValue(eventName, element) {
        var valueClassName = 'weline-pixel::' + eventName + ':value';
        var context = __getPixelContext(element);
        var valueElement = null;
        var current = element;
        while (!valueElement && current && current !== document) {
            if (current.getElementsByClassName) {
                valueElement = current.getElementsByClassName(valueClassName)[0] || null;
            }
            if (!valueElement && current.getAttribute && current.hasAttribute('data-pixel-value')) {
                valueElement = current;
            }
            current = current.parentElement;
        }
        if (!valueElement && context && context.getElementsByClassName) {
            valueElement = context.getElementsByClassName(valueClassName)[0] || null;
        }
        if (!valueElement && context && context.querySelector) {
            valueElement = context.querySelector('[data-pixel-value], .price-current, .price-amount, [data-summary-total], [data-mini-cart-subtotal], [data-weshop-summary-grand-total], .cart-summary-total strong, .cart-summary-card__row--total .cart-summary-card__value, .weshop-checkout-summary-total span:last-child');
        }
        if (!valueElement && document && document.querySelector) {
            valueElement = document.querySelector('[data-summary-total], [data-mini-cart-subtotal], [data-weshop-summary-grand-total], .cart-summary-total strong, .cart-summary-card__row--total .cart-summary-card__value, .weshop-checkout-summary-total span:last-child');
        }

        if (!valueElement) {
            return null;
        }
        return __pixelNumber(valueElement.value || valueElement.getAttribute('data-pixel-value') || valueElement.textContent || '');
    }

    function __getQuantity(element) {
        var context = __getPixelContext(element);
        var qtyNode = context && context.querySelector ? context.querySelector('input[name="qty"], input[name="quantity"], [data-qty], [data-quantity]') : null;
        var value = qtyNode ? (qtyNode.value || qtyNode.getAttribute('data-qty') || qtyNode.getAttribute('data-quantity')) : '';
        var qty = __pixelNumber(value);
        return qty !== null && qty > 0 ? qty : 1;
    }

    function __getSelectedOptions(element) {
        var context = __getPixelContext(element);
        if (!context || !context.querySelectorAll) {
            return {};
        }
        var options = {};
        var controls = context.querySelectorAll('select, input[type="radio"]:checked, input[type="checkbox"]:checked, [data-option-label][aria-pressed="true"], [data-option-label].active, [data-option-label].selected');
        for (var i = 0; i < controls.length; i++) {
            var control = controls[i];
            var label = __firstAttr(control, ['data-option-label', 'name', 'aria-label']);
            if (label === '') {
                continue;
            }
            var value = control.value || __firstAttr(control, ['data-option-value', 'data-value']) || __pixelText(control);
            if (value !== '') {
                options[label] = String(value).trim();
            }
        }
        // PDP 规格轴：当前选中的 data-variant-option
        var axes = context.querySelectorAll('.product-native-detail__variant-axis');
        for (var a = 0; a < axes.length; a++) {
            var axisEl = axes[a];
            var labelEl = axisEl.querySelector
                ? axisEl.querySelector('.product-native-detail__variant-axis-label')
                : null;
            var axisLabel = labelEl
                ? String(__pixelText(labelEl) || '').replace(/:\s*$/, '').replace(/\s+/g, ' ').trim()
                : '';
            var selected = axisEl.querySelector
                ? axisEl.querySelector('[data-variant-option].is-selected, [data-variant-option][aria-current="true"]')
                : null;
            if (!selected || axisLabel === '') {
                continue;
            }
            var axisValue = __firstAttr(selected, ['data-variant-label', 'aria-label', 'title']);
            if (axisValue === '') {
                var swatchLabel = selected.querySelector
                    ? selected.querySelector('.product-native-detail__variant-swatch-label')
                    : null;
                axisValue = swatchLabel ? __pixelText(swatchLabel) : __pixelText(selected);
            }
            axisValue = String(axisValue || '').replace(/\s+/g, ' ').trim();
            if (axisValue !== '') {
                options[axisLabel] = axisValue;
            }
        }
        return options;
    }

    /**
     * Storefront unit price in major currency units (shared by view_item / add_to_cart /
     * buy_now / quick_buy / express_pay / …). Prefer PDP minor attrs used by HelpPay
     * (data-offer-price-minor), then data-price / displayed .product-native-detail__price.
     */
    function __readStorefrontPriceMajor(element) {
        var attrNames = [
            'data-offer-price-minor',
            'data-price-minor',
            'data-product-price-minor',
            'data-reference-price-minor',
            'data-catalog-price-minor',
            'data-amount-minor'
        ];
        var minor = __pixelNumber(__closestAttr(element, attrNames));
        if (minor !== null && minor > 0) {
            return minor / 100;
        }
        var context = __getPixelContext(element);
        var roots = [];
        if (element && element.closest) {
            var detail = element.closest(
                '[data-testid="storefront-product-detail"], .product-native-detail, [data-offer-price-minor], [data-catalog-price-minor]'
            );
            if (detail) {
                roots.push(detail);
            }
        }
        if (context && roots.indexOf(context) < 0) {
            roots.push(context);
        }
        var r;
        var i;
        for (r = 0; r < roots.length; r++) {
            var root = roots[r];
            if (!root || !root.getAttribute) {
                continue;
            }
            for (i = 0; i < attrNames.length; i++) {
                minor = __pixelNumber(root.getAttribute(attrNames[i]));
                if (minor !== null && minor > 0) {
                    return minor / 100;
                }
            }
            var majorAttr = __firstAttr(root, ['data-price', 'data-pixel-value']);
            var majorFromAttr = __pixelNumber(majorAttr);
            if (majorFromAttr !== null && majorFromAttr > 0) {
                return majorFromAttr;
            }
        }
        if (document && document.querySelectorAll) {
            var nodes = document.querySelectorAll(
                '[data-offer-price-minor], [data-catalog-price-minor], [data-price-minor]'
            );
            for (i = 0; i < nodes.length; i++) {
                for (r = 0; r < attrNames.length; r++) {
                    minor = __pixelNumber(nodes[i].getAttribute(attrNames[r]));
                    if (minor !== null && minor > 0) {
                        return minor / 100;
                    }
                }
            }
        }
        var priceNode = context && context.querySelector
            ? context.querySelector('.product-native-detail__price:not(.product-native-detail__price-original)')
            : null;
        if (priceNode) {
            var major = __pixelNumber(__pixelText(priceNode));
            if (major !== null && major > 0) {
                return major;
            }
        }
        return null;
    }

    function __getProductMeta(eventName, element) {
        var context = __getPixelContext(element);
        var productId = __closestAttr(element, ['data-product-id', 'data-item-id', 'data-id'])
            || __firstAttr(context, ['data-product-id', 'data-item-id', 'data-id']);
        var sku = __closestAttr(element, ['data-sku', 'data-product-sku'])
            || __firstAttr(context, ['data-sku', 'data-product-sku']);
        var name = __firstAttr(element, ['data-product-name', 'data-name'])
            || __firstAttr(context, ['data-product-name', 'data-name'])
            || __firstText(context, ['h1', '[data-product-name]', '.product-title', '.product-name', '.card-title', '.font-bold']);
        var price = __resolvePixelValue(eventName, element);
        if (price === null || price <= 0) {
            price = __readStorefrontPriceMajor(element);
        }
        if (price === null || price <= 0) {
            price = __firstNumber(context, [
                '[data-price]',
                '[data-pixel-value]',
                '.price-current',
                '.price-amount',
                '.product-native-detail__price',
                '.text-red-500',
                '.font-bold'
            ]);
        }
        if (price !== null && price <= 0) {
            price = null;
        }
        var qty = __getQuantity(element);
        var currency = __resolvePixelCurrency(
            __closestAttr(element, ['data-pixel-currency', 'data-currency'])
            || __firstAttr(context, ['data-pixel-currency', 'data-currency'])
            || ''
        );
        var item = {
            product_id: productId,
            item_id: productId || sku,
            sku: sku,
            name: name,
            item_name: name,
            price: price,
            quantity: qty,
            qty: qty,
            selected_options: __getSelectedOptions(element),
            product_url: window.location.pathname.indexOf('/product/') === 0 ? window.location.href : (__firstAttr(context, ['href']) || '')
        };
        return {
            product_id: productId,
            item_id: productId || sku,
            sku: sku,
            name: name,
            content_name: name,
            price: price,
            quantity: qty,
            qty: qty,
            value: price !== null ? price * qty : null,
            currency: currency,
            items: [item],
            selected_options: item.selected_options,
            product_url: item.product_url
        };
    }

    function __getCartItems() {
        var nodes = document.querySelectorAll ? document.querySelectorAll('.cart-item, [data-cart-item], [data-product-id].cart-item') : [];
        var items = [];
        for (var i = 0; i < nodes.length && i < 20; i++) {
            var node = nodes[i];
            var productId = __firstAttr(node, ['data-product-id', 'data-item-id', 'data-id']);
            var name = __firstAttr(node, ['data-product-name', 'data-name']) || __firstText(node, ['.cart-item-title', '.product-title', '.product-name', 'h3', 'a']);
            var price = __firstNumber(node, ['[data-price]', '[data-pixel-value]', '.price-current', '.price-amount', '.cart-item-price']);
            var qtyNode = node.querySelector ? node.querySelector('input[name="qty"], input[name="quantity"], [data-qty], [data-quantity]') : null;
            var quantity = qtyNode ? __pixelNumber(qtyNode.value || qtyNode.getAttribute('data-qty') || qtyNode.getAttribute('data-quantity')) : null;
            if (!productId && !name && price === null) {
                continue;
            }
            items.push({
                product_id: productId,
                item_id: productId,
                name: name,
                item_name: name,
                price: price,
                quantity: quantity !== null && quantity > 0 ? quantity : 1,
                qty: quantity !== null && quantity > 0 ? quantity : 1
            });
        }
        return items;
    }

    function __getCartMeta(eventName, element) {
        var value = __resolvePixelValue(eventName, element);
        if (value === null) {
            value = __firstNumber(document, ['[data-summary-total], [data-mini-cart-subtotal], [data-weshop-summary-grand-total], .cart-summary-total strong, .cart-summary-card__row--total .cart-summary-card__value, .weshop-checkout-summary-total span:last-child']);
        }
        var items = __getCartItems();
        return {
            value: value,
            total: value,
            grand_total: value,
            cart_total: value,
            cart_items_count: items.length,
            currency: __resolvePixelCurrency('') || 'CNY',
            items: items
        };
    }

    function __getOrderConfirmItems() {
        var nodes = document.querySelectorAll
            ? document.querySelectorAll('.amz-order-confirm__item, [data-order-item], [data-testid="order-item"]')
            : [];
        var items = [];
        for (var i = 0; i < nodes.length && i < 20; i++) {
            var node = nodes[i];
            var productId = __firstAttr(node, ['data-product-id', 'data-item-id', 'data-id']);
            var name = __firstAttr(node, ['data-product-name', 'data-name', 'data-item-name'])
                || __firstText(node, ['.amz-order-confirm__item-title', '.product-title', '.product-name', 'h3', 'a', 'p']);
            var price = __firstNumber(node, ['[data-price]', '[data-pixel-value]', '.amz-order-confirm__item-price', '.price-amount']);
            var qtyText = __firstText(node, ['.amz-order-confirm__item-qty', '[data-qty]', '[data-quantity]']);
            var quantity = __pixelNumber(qtyText);
            if (quantity === null || quantity <= 0) {
                quantity = 1;
            }
            if (!productId && !name && price === null) {
                continue;
            }
            items.push({
                product_id: productId,
                item_id: productId || name,
                name: name,
                item_name: name,
                price: price,
                quantity: quantity,
                qty: quantity
            });
        }
        return items;
    }

    function __getCheckoutMeta(eventName, element) {
        var meta = __getCartMeta(eventName, element);
        var shipping = __firstText(document, ['input[name*="shipping"]:checked + label', '[data-selected-shipping]', '.shipping-method.active', '.shipping-method.selected']);
        var payment = __firstText(document, ['input[name*="payment"]:checked + label', '[data-selected-payment]', '.payment-method.active', '.payment-method.selected']);
        var orderId = __firstText(document, ['[data-order-id]', '[data-order-number]', '.order-number', '.order-id', '.amz-order-confirm__order-no strong']);
        if (shipping !== '') {
            meta.shipping_method = shipping;
        }
        if (payment !== '') {
            meta.payment_method = payment;
        }
        if (orderId !== '') {
            meta.order_id = orderId;
            meta.transaction_id = meta.transaction_id || orderId;
        }
        if (!meta.items || !meta.items.length) {
            var confirmItems = __getOrderConfirmItems();
            if (confirmItems.length) {
                meta.items = confirmItems;
                meta.cart_items_count = confirmItems.length;
            }
        }
        var root = null;
        try {
            root = (element && element.closest && element.closest('[data-pixel-value], [data-pixel-currency], [data-testid="checkout-success"], [data-testid="payment-return"], [data-testid="payment-success"]'))
                || document.querySelector('[data-testid="checkout-success"], [data-testid="payment-return"], [data-testid="payment-success"], [data-pixel-event="checkout_success"], [data-pixel-event="payment_success"]');
        } catch (eRoot) {
            root = null;
        }
        if (root) {
            var pixelValue = __pixelNumber(root.getAttribute('data-pixel-value') || root.getAttribute('data-amount'));
            var pixelCurrency = String(root.getAttribute('data-pixel-currency') || root.getAttribute('data-currency') || '').trim();
            if ((meta.value === null || meta.value === undefined || meta.value === '') && pixelValue !== null) {
                meta.value = pixelValue;
                meta.grand_total = pixelValue;
                meta.total = pixelValue;
            }
            if (!meta.currency && pixelCurrency) {
                meta.currency = pixelCurrency;
            }
            var ou = String(root.getAttribute('data-order-uuid') || '').trim();
            var tid = String(root.getAttribute('data-transaction-id') || '').trim();
            if (ou && !meta.order_uuid) {
                meta.order_uuid = ou;
            }
            if (tid && !meta.transaction_id) {
                meta.transaction_id = tid;
            }
        }
        return meta;
    }

    function __getSearchResultMeta() {
        var resultNodes = document.querySelectorAll ? document.querySelectorAll('[data-search-result], .search-result-item, .product-card, [data-product-id]') : [];
        var category = '';
        try {
            category = new URLSearchParams(window.location.search || '').get('category') || '';
        } catch (e) {
        }
        return {
            result_count: resultNodes.length,
            category: category,
            search_url: window.location.href
        };
    }

    function __missingFields(schema, meta) {
        var missing = [];
        for (var i = 0; i < schema.length; i++) {
            var key = schema[i];
            var value = meta[key];
            if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
                missing.push(key);
            }
        }
        return missing;
    }

    function __getEventSpecificMeta(eventName, element, domEvent, baseMeta) {
        var normalized = __normalizePixelEventName(eventName);
        var meta = {};
        var required = [];
        if (normalized.indexOf('search_') === 0) {
            meta = Object.assign({}, __getSearchMeta(__findSearchInput(document), normalized), __getSearchResultMeta());
            required = normalized === 'search_result_view' ? ['query', 'result_count'] : ['query'];
        } else if (['view_item', 'add_to_cart', 'buy_now', 'add_to_wishlist', 'friend_help_pay', 'selection_share', 'quick_buy', 'express_pay', 'add_payment_info'].indexOf(normalized) > -1) {
            meta = __getProductMeta(normalized, element);
            required = ['currency', 'value', 'items'];
            if (normalized === 'express_pay' || normalized === 'add_payment_info') {
                required = ['currency', 'value', 'items', 'payment_type'];
            }
        } else if (['view_cart', 'remove_from_cart'].indexOf(normalized) > -1) {
            meta = __getCartMeta(normalized, element);
            required = ['currency', 'value', 'items'];
        } else if (normalized === 'express_pay_started') {
            // PDP 拉起仍用商品元数据；结账页用结账汇总
            if ((window.location.pathname || '').indexOf('/product/') === 0) {
                meta = __getProductMeta(normalized, element);
            } else {
                meta = __getCheckoutMeta(normalized, element);
                if (!meta.items || !meta.items.length) {
                    meta = Object.assign({}, __getProductMeta(normalized, element), meta);
                }
            }
            required = ['currency', 'value', 'items'];
        } else if (['begin_checkout', 'place_order', 'checkout_success', 'checkout_failure', 'express_pay_confirmed', 'add_shipping_info', 'purchase', 'payment_success'].indexOf(normalized) > -1
            || (normalized.length > 17 && normalized.slice(-17) === '_checkout_success')) {
            meta = __getCheckoutMeta(normalized, element);
            if (normalized === 'begin_checkout') {
                required = ['currency', 'value', 'items'];
            } else if (normalized === 'express_pay_confirmed' || normalized === 'add_shipping_info') {
                required = ['currency', 'value', 'items', 'shipping_tier'];
            } else if (normalized === 'checkout_success' || normalized === 'purchase' || normalized === 'payment_success'
                || (normalized.length > 17 && normalized.slice(-17) === '_checkout_success')) {
                required = ['transaction_id', 'currency', 'value', 'items'];
            } else {
                required = ['currency', 'value', 'items'];
            }
        } else if (['route_click', 'page_transition'].indexOf(normalized) > -1) {
            var link = element && element.closest ? element.closest('a[href]') : null;
            var href = link ? (link.href || link.getAttribute('href') || '') : '';
            meta = {
                href: href,
                link_text: link ? __pixelText(link).slice(0, 180) : '',
                from_url: window.location.href,
                from_path: window.location.pathname,
                same_origin: href ? (new URL(href, window.location.href).origin === window.location.origin) : false
            };
            required = ['href', 'from_url'];
        }

        meta = Object.assign({}, meta, baseMeta || {});
        // 站内别名 → GA4 推荐参数（供 schema 校验与转发）
        if (!meta.payment_type && (meta.payment_method || baseMeta && baseMeta.payment_method)) {
            meta.payment_type = meta.payment_method || (baseMeta && baseMeta.payment_method) || '';
        }
        if (!meta.shipping_tier) {
            meta.shipping_tier = meta.shipping_method || meta.service_code || meta.selected_service_code
                || (baseMeta && (baseMeta.shipping_tier || baseMeta.shipping_method || baseMeta.service_code || baseMeta.selected_service_code))
                || '';
        }
        if (!meta.transaction_id && (meta.transaction_no || (baseMeta && baseMeta.transaction_no))) {
            meta.transaction_id = meta.transaction_no || (baseMeta && baseMeta.transaction_no) || '';
        }
        if ((meta.value === null || meta.value === undefined || meta.value === '')
            && (meta.grand_total != null || meta.total != null || meta.cart_total != null)) {
            meta.value = meta.grand_total != null ? meta.grand_total
                : (meta.total != null ? meta.total : meta.cart_total);
        }
        if (!meta.currency) {
            meta.currency = __resolvePixelCurrency('') || 'CNY';
        }
        var missing = __missingFields(required, meta);
        meta.event_schema = {
            name: normalized,
            required_fields: required,
            missing_fields: missing
        };
        return meta;
    }

    function __applyEventMetaToPayload(payload, meta) {
        var allowed = [
            'query', 'query_length', 'url_query', 'input_name', 'input_id', 'form_action', 'form_method', 'result_count', 'category', 'search_url', 'suggestion_text', 'suggestion_url', 'suggestion_position',
            'product_id', 'item_id', 'sku', 'name', 'content_name', 'price', 'quantity', 'qty', 'value', 'currency', 'items', 'selected_options', 'product_url',
            'total', 'grand_total', 'cart_total', 'cart_items_count',
            'shipping_method', 'shipping_tier', 'payment_method', 'payment_type', 'order_id', 'transaction_id',
            'order_uuid', 'checkout_group_uuid', 'transaction_no',
            'service_code', 'selected_service_code', 'coupon', 'tax', 'shipping',
            'href', 'link_text', 'from_url', 'from_path', 'to_url', 'to_path', 'same_origin'
        ];
        for (var i = 0; i < allowed.length; i++) {
            var key = allowed[i];
            if (meta && meta[key] !== undefined && meta[key] !== null && meta[key] !== '') {
                payload[key] = meta[key];
            }
        }
        if (payload.value !== undefined && payload.value !== null && payload.value !== '') {
            var value = __pixelNumber(payload.value);
            if (value !== null) {
                payload.value = value;
            }
        }
    }
    
    var __visitorWorkerApiPromise = null;
    function __getVisitorWorkerApi() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            __visitorWorkerApiPromise = Promise.resolve(window.Weline.Api.resource('visitor'));
            return __visitorWorkerApiPromise;
        }
        if (__visitorWorkerApiPromise) {
            return __visitorWorkerApiPromise;
        }

        __visitorWorkerApiPromise = new Promise(function (resolve, reject) {
            var attempts = 0;
            var waitForApi = function () {
                attempts += 1;
                if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                    resolve(window.Weline.Api.resource('visitor'));
                    return;
                }
                if (attempts >= 40) {
                    reject(new Error('Weline.Api.resource is not available'));
                    return;
                }
                window.setTimeout(waitForApi, 50);
            };
            waitForApi();
        });
        __visitorWorkerApiPromise.catch(function () {
            __visitorWorkerApiPromise = null;
        });
        return __visitorWorkerApiPromise;
    }

    var __pixelSendQueue = [];
    var __pixelSendActive = false;
    var __pixelSendTimer = null;

    function __pixelTruncate(value, length) {
        return String(value || '').slice(0, length);
    }

    function __compactPixelFunnel(funnel) {
        if (!funnel || typeof funnel !== 'object') {
            return {};
        }
        var chain = Array.isArray(funnel.chain) ? funnel.chain.slice(-8).map(function (item) {
            return {
                event: __pixelTruncate(item.event, 64),
                step: item.step || 0,
                path: __pixelTruncate(item.path, 160),
                page_id: __pixelTruncate(item.page_id, 48),
                timestamp_ms: item.timestamp_ms || 0,
                since_previous_ms: item.since_previous_ms || null
            };
        }) : [];

        return {
            session_id: __pixelTruncate(funnel.session_id, 64),
            page_id: __pixelTruncate(funnel.page_id, 48),
            step: funnel.step || 0,
            step_index: funnel.step_index || 0,
            previous_event: __pixelTruncate(funnel.previous_event, 64),
            since_previous_ms: funnel.since_previous_ms || null,
            chain: chain
        };
    }

    function __compactPixelPerformance(performanceData) {
        if (!performanceData || typeof performanceData !== 'object') {
            return {};
        }
        var resourceSummary = performanceData.resource_summary || {};
        var slowest = Array.isArray(resourceSummary.slowest) ? resourceSummary.slowest.slice(0, 3).map(function (entry) {
            return {
                name: __pixelTruncate(entry.name, 96),
                initiator_type: __pixelTruncate(entry.initiator_type, 32),
                duration_ms: entry.duration_ms || 0,
                transfer_size: entry.transfer_size || 0
            };
        }) : [];

        return {
            page_started_at_ms: performanceData.page_started_at_ms || 0,
            page_age_ms: performanceData.page_age_ms || 0,
            perf_now_ms: performanceData.perf_now_ms || null,
            time_origin_ms: performanceData.time_origin_ms || null,
            navigation: performanceData.navigation || null,
            paint: performanceData.paint || {},
            resource_summary: {
                count: resourceSummary.count || 0,
                slowest: slowest
            }
        };
    }

    function __pageBuilderAttributionPath(value) {
        try {
            var parsed = new URL(String(value || ''), window.location.origin);
            var path = String(parsed.pathname || '/').replace(/\/{2,}/g, '/');
            return path.length > 1 ? path.replace(/\/+$/, '') : path;
        } catch (error) {
            return '';
        }
    }

    function __pageBuilderAttributionText(node, attribute, maxLength) {
        var value = node && node.getAttribute ? String(node.getAttribute(attribute) || '').trim() : '';
        return value.length > maxLength ? value.slice(0, maxLength) : value;
    }

    function __pageBuilderAttributionNode(element, eventName) {
        var selector = '[data-pb-attribution-version="pagebuilder_ai_v1"]';
        var node = element && element.closest ? element.closest(selector) : null;
        if (!node && eventName === 'page_view') {
            node = document.querySelector(selector);
        }

        return node;
    }

    function __buildPageBuilderAttribution(element, eventName) {
        if (__isPageBuilderPreview() || !__visitorConsentAllows('analytics')) {
            return null;
        }

        var node = __pageBuilderAttributionNode(element, eventName);
        if (!node) {
            return null;
        }

        var websiteId = __pageBuilderAttributionText(node, 'data-pb-website-id', 32);
        var environmentWebsiteId = __getPixelSiteEnvValue('website_id', 'WELINE_WEBSITE_ID');
        if (!/^\d+$/.test(websiteId)
            || (environmentWebsiteId !== undefined
                && environmentWebsiteId !== null
                && String(environmentWebsiteId).trim() !== ''
                && String(environmentWebsiteId).trim() !== websiteId)) {
            return null;
        }

        var pageType = __pageBuilderAttributionText(node, 'data-pb-page-type', 64);
        var revisionText = __pageBuilderAttributionText(node, 'data-pb-plan-revision', 16);
        var canonicalPath = __pageBuilderAttributionPath(__pageBuilderAttributionText(node, 'data-pb-canonical-path', 255));
        var currentPath = __pageBuilderAttributionPath(window.location.pathname || '/');
        if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(pageType)
            || !/^\d+$/.test(revisionText)
            || !canonicalPath
            || canonicalPath !== currentPath) {
            return null;
        }

        var planRevision = Number(revisionText);
        if (!isFinite(planRevision) || planRevision < 0 || planRevision > 2147483647) {
            return null;
        }

        var isPageView = eventName === 'page_view';
        var blockKey = isPageView ? '' : __pageBuilderAttributionText(node, 'data-pb-block-key', 128);
        var fingerprint = isPageView ? '' : __pageBuilderAttributionText(node, 'data-pb-content-fingerprint', 64).toLowerCase();
        if (!isPageView
            && (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/.test(blockKey)
                || !/^[a-f0-9]{64}$/.test(fingerprint))) {
            return null;
        }

        var experimentId = __pageBuilderAttributionText(
            node,
            isPageView ? 'data-pb-page-experiment-id' : 'data-pb-experiment-id',
            96
        );
        var variant = __pageBuilderAttributionText(
            node,
            isPageView ? 'data-pb-page-variant' : 'data-pb-variant',
            32
        );
        if ((experimentId && !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/.test(experimentId))
            || (variant && !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,31}$/.test(variant))) {
            return null;
        }

        return {
            attribution_version: 'pagebuilder_ai_v1',
            source: 'pagebuilder_rendered_dom',
            surface: 'published',
            analytics_consent: 'granted',
            preview: false,
            website_id: websiteId,
            page_type: pageType,
            block_key: blockKey,
            plan_revision: Math.floor(planRevision),
            content_fingerprint: fingerprint,
            experiment_id: experimentId,
            variant: variant,
            page_experiment_id: __pageBuilderAttributionText(node, 'data-pb-page-experiment-id', 96),
            page_variant: __pageBuilderAttributionText(node, 'data-pb-page-variant', 32),
            canonical_path: canonicalPath
        };
    }

    function __compactPixelPayloadForWorker(payload) {
        payload = payload || {};
        var additionalInfo = payload.additionalInfo && typeof payload.additionalInfo === 'object' ? payload.additionalInfo : {};
        var compact = Object.assign({}, payload);
        compact.url = __pixelTruncate(compact.url, 512);
        compact.referrer = __pixelTruncate(compact.referrer, 512);
        compact.userAgent = __pixelTruncate(compact.userAgent, 255);
        compact.module = __pixelTruncate(compact.module, 128);
        compact.name = __pixelTruncate(compact.name, 128);
        compact.eventName = __pixelTruncate(compact.eventName || compact.event, 128);
        compact.userLang = __pixelTruncate(compact.userLang, 64);
        compact.currency = __pixelTruncate(compact.currency, 32);
        compact.websiteId = __pixelTruncate(compact.websiteId || (compact.environment && compact.environment.website_id), 32);
        compact.websiteCode = __pixelTruncate(compact.websiteCode || (compact.environment && compact.environment.website_code), 64);
        compact.websiteUrl = __pixelTruncate(compact.websiteUrl || (compact.environment && compact.environment.website_url), 255);
        compact.page_location = __pixelTruncate(compact.page_location || (compact.environment && compact.environment.page_location), 512);
        compact.page_path = __pixelTruncate(compact.page_path || (compact.environment && compact.environment.page_path), 160);
        compact.page_title = __pixelTruncate(compact.page_title || (compact.environment && compact.environment.page_title), 255);
        compact.page_referrer = __pixelTruncate(compact.page_referrer || (compact.environment && compact.environment.page_referrer), 512);
        compact.page_hostname = __pixelTruncate(compact.page_hostname || (compact.environment && compact.environment.page_hostname), 128);
        compact.content_locale = __pixelTruncate(compact.content_locale || (compact.environment && compact.environment.content_locale), 64);
        compact.session_id = __pixelTruncate(compact.session_id || (compact.environment && compact.environment.session_id), 64);
        compact.page_id = __pixelTruncate(compact.page_id || (compact.environment && compact.environment.page_id), 64);
        if (compact.environment && typeof compact.environment === 'object') {
            compact.environment = {
                page_location: __pixelTruncate(compact.environment.page_location, 512),
                page_path: __pixelTruncate(compact.environment.page_path, 160),
                page_title: __pixelTruncate(compact.environment.page_title, 255),
                page_referrer: __pixelTruncate(compact.environment.page_referrer, 512),
                page_hostname: __pixelTruncate(compact.environment.page_hostname, 128),
                page_origin: __pixelTruncate(compact.environment.page_origin, 128),
                page_search: __pixelTruncate(compact.environment.page_search, 160),
                page_hash: __pixelTruncate(compact.environment.page_hash, 80),
                website_id: __pixelTruncate(compact.environment.website_id, 32),
                website_code: __pixelTruncate(compact.environment.website_code, 64),
                website_url: __pixelTruncate(compact.environment.website_url, 255),
                language: __pixelTruncate(compact.environment.language, 64),
                currency: __pixelTruncate(compact.environment.currency, 32),
                content_locale: __pixelTruncate(compact.environment.content_locale, 64),
                session_id: __pixelTruncate(compact.environment.session_id, 64),
                page_id: __pixelTruncate(compact.environment.page_id, 64),
                pixel_name: __pixelTruncate(compact.environment.pixel_name, 128),
                engagement_target: __pixelTruncate(compact.environment.engagement_target, 64)
            };
        }

        if (compact.elementInfo && typeof compact.elementInfo === 'object') {
            compact.elementInfo = {
                tagName: __pixelTruncate(compact.elementInfo.tagName, 32),
                className: __pixelTruncate(compact.elementInfo.className, 120),
                id: __pixelTruncate(compact.elementInfo.id, 80),
                name: __pixelTruncate(compact.elementInfo.name, 80),
                type: __pixelTruncate(compact.elementInfo.type, 32),
                href: __pixelTruncate(compact.elementInfo.href, 255),
                text: __pixelTruncate(compact.elementInfo.text, 120),
                eventType: __pixelTruncate(compact.elementInfo.eventType, 32)
            };
        }

        var pageBuilderAttribution = additionalInfo.pagebuilder_attribution
            && typeof additionalInfo.pagebuilder_attribution === 'object'
            ? additionalInfo.pagebuilder_attribution
            : {};
        var compactPageBuilderRevision = Number(pageBuilderAttribution.plan_revision);
        if (!isFinite(compactPageBuilderRevision) || compactPageBuilderRevision < 0) {
            compactPageBuilderRevision = 0;
        }
        var sourceInfo = additionalInfo.source && typeof additionalInfo.source === 'object' ? additionalInfo.source : {};
        var ecommerceItems = Array.isArray(compact.items)
            ? compact.items
            : (additionalInfo.ecommerce && Array.isArray(additionalInfo.ecommerce.items)
                ? additionalInfo.ecommerce.items
                : (additionalInfo.meta && Array.isArray(additionalInfo.meta.items) ? additionalInfo.meta.items : []));
        compact.additionalInfo = {
            schema: additionalInfo.schema || 'weline_behavior_timing_v2',
            time: additionalInfo.time || {},
            performance: __compactPixelPerformance(additionalInfo.performance),
            funnel: __compactPixelFunnel(additionalInfo.funnel),
            environment: additionalInfo.environment || {},
            device: additionalInfo.device || {},
            utm: additionalInfo.utm || {},
            engagement: additionalInfo.engagement || {},
            source: {
                section_code: __pixelTruncate(sourceInfo.section_code || compact.section_code, 128),
                section_event_key: __pixelTruncate(sourceInfo.section_event_key || compact.section_event_key, 160),
                section_source_status: __pixelTruncate(sourceInfo.section_source_status || compact.section_source_status || 'n/a', 32)
            },
            navigation: {
                current_url: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_url, 512),
                current_path: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_path, 160),
                current_search: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_search, 160),
                current_hash: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_hash, 80),
                referrer: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.referrer, 512),
                last_location: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.last_location, 512),
                website_id: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.website_id, 32),
                website_code: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.website_code, 64),
                website_url: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.website_url, 255),
                language: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.language, 64),
                content_locale: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.content_locale, 64)
            },
            viewport: additionalInfo.viewport || {},
            meta: additionalInfo.meta || {},
            pagebuilder_attribution: pageBuilderAttribution.attribution_version === 'pagebuilder_ai_v1' ? {
                attribution_version: 'pagebuilder_ai_v1',
                source: __pixelTruncate(pageBuilderAttribution.source, 64),
                surface: __pixelTruncate(pageBuilderAttribution.surface, 32),
                analytics_consent: __pixelTruncate(pageBuilderAttribution.analytics_consent, 16),
                preview: pageBuilderAttribution.preview === true,
                website_id: __pixelTruncate(pageBuilderAttribution.website_id, 32),
                page_type: __pixelTruncate(pageBuilderAttribution.page_type, 64),
                block_key: __pixelTruncate(pageBuilderAttribution.block_key, 128),
                plan_revision: Math.floor(compactPageBuilderRevision),
                content_fingerprint: __pixelTruncate(pageBuilderAttribution.content_fingerprint, 64),
                experiment_id: __pixelTruncate(pageBuilderAttribution.experiment_id, 96),
                variant: __pixelTruncate(pageBuilderAttribution.variant, 32),
                page_experiment_id: __pixelTruncate(pageBuilderAttribution.page_experiment_id, 96),
                page_variant: __pixelTruncate(pageBuilderAttribution.page_variant, 32),
                canonical_path: __pixelTruncate(pageBuilderAttribution.canonical_path, 255)
            } : {},
            // F03：worker 压缩时保留电商 items，供服务端商品表现展开
            ecommerce: {
                items: ecommerceItems.slice(0, 20),
                item_id: __pixelTruncate(compact.item_id || (additionalInfo.ecommerce && additionalInfo.ecommerce.item_id) || '', 120),
                product_id: __pixelTruncate(compact.product_id || (additionalInfo.ecommerce && additionalInfo.ecommerce.product_id) || '', 120),
                sku: __pixelTruncate(compact.sku || (additionalInfo.ecommerce && additionalInfo.ecommerce.sku) || '', 120),
                transaction_id: __pixelTruncate(compact.transaction_id || (additionalInfo.ecommerce && additionalInfo.ecommerce.transaction_id) || '', 120)
            }
        };
        if (additionalInfo.incident && typeof additionalInfo.incident === 'object') {
            compact.additionalInfo.incident = additionalInfo.incident;
        }

        return compact;
    }

    function __isPassivePixelEvent(payload) {
        var eventName = __normalizePixelEventName(payload && (payload.eventName || payload.event) || '');
        return ['page_view', 'page_load', 'homepage', 'blog', 'category', 'account_', 'search_result_view'].some(function (name) {
            return name.slice(-1) === '_' ? eventName.indexOf(name) === 0 : eventName === name;
        });
    }

    function __schedulePixelDrain(delay) {
        window.clearTimeout(__pixelSendTimer);
        __pixelSendTimer = window.setTimeout(__drainPixelQueue, Math.max(0, delay || 0));
    }

    function __enqueuePixelPayload(payload, keepalive) {
        var compactPayload = __compactPixelPayloadForWorker(payload);
        __pixelSendQueue.push(compactPayload);
        if (__pixelSendQueue.length > 16) {
            __pixelSendQueue = __pixelSendQueue.slice(-16);
        }
        __schedulePixelDrain(keepalive ? 0 : (__isPassivePixelEvent(compactPayload) ? 1200 : 0));
    }

    function __drainPixelQueue() {
        if (!__visitorOptimizationEvidenceWriteAllowed()) {
            __pixelSendQueue = [];
            return;
        }
        if (__pixelSendActive || __pixelSendQueue.length === 0) {
            return;
        }

        __pixelSendActive = true;
        var payload = __pixelSendQueue.shift();
        __getVisitorWorkerApi()
            .then(function (VisitorApi) {
                return VisitorApi.trackPixel({ payload: payload }, { silent: true });
            })
            .catch(function (error) {
                if (window.DEV) {
                    console.debug('Weline Pixel worker send skipped:', error && error.message ? error.message : error);
                }
            })
            .then(function () {
                __pixelSendActive = false;
                if (__pixelSendQueue.length > 0) {
                    __schedulePixelDrain(250);
                }
            });
    }

    var __incidentStepRing = [];
    var __incidentThrottleMap = {};
    var __INCIDENT_RING_MAX = 30;
    var __INCIDENT_THROTTLE_MS = 8000;

    function __pushIncidentStep(step) {
        if (!step || typeof step !== 'object') {
            return;
        }
        __incidentStepRing.push({
            at: Date.now(),
            name: String(step.name || step.event || '').slice(0, 64),
            path: String(step.path || (window.location && window.location.pathname) || '').slice(0, 160),
            detail: String(step.detail || '').slice(0, 120)
        });
        if (__incidentStepRing.length > __INCIDENT_RING_MAX) {
            __incidentStepRing = __incidentStepRing.slice(-__INCIDENT_RING_MAX);
        }
    }

    function __readRuntimeVersions() {
        var cfg = {};
        try {
            var node = document.getElementById('weline-frontend-runtime-config')
                || document.querySelector('[data-weline-runtime-config]');
            if (node && node.textContent) {
                cfg = JSON.parse(node.textContent) || {};
            }
        } catch (eCfg) {
            cfg = {};
        }
        cfg = cfg || {};
        return {
            deployVersion: String(cfg.deployVersion || cfg.deploy_version || '').trim(),
            workerBuildId: String(cfg.workerBuildId || cfg.worker_build_id || '').trim(),
            themePublishedVersionId: String(cfg.themePublishedVersionId || cfg.theme_published_version_id || '').trim(),
            themePublishedVersion: String(cfg.themePublishedVersion || cfg.theme_published_version || '').trim()
        };
    }

    function __captureRedactedFormSnapshot() {
        var blocked = /password|pass|pwd|card|cvv|cvc|pan|otp|token|secret|ssn/i;
        var out = {};
        var nodes = document.querySelectorAll('input, select, textarea');
        var i;
        for (i = 0; i < nodes.length && Object.keys(out).length < 40; i++) {
            var el = nodes[i];
            if (!el || el.disabled) {
                continue;
            }
            var type = String(el.type || '').toLowerCase();
            if (type === 'password' || type === 'file') {
                continue;
            }
            var key = String(el.name || el.id || '').trim();
            if (!key || blocked.test(key)) {
                continue;
            }
            if (type === 'checkbox' || type === 'radio') {
                if (!el.checked) {
                    continue;
                }
            }
            var value = String(el.value == null ? '' : el.value).slice(0, 500);
            if (value === '') {
                continue;
            }
            out[key.slice(0, 64)] = value;
        }
        return out;
    }

    function __resolveIncidentEmail(formSnapshot) {
        formSnapshot = formSnapshot || {};
        var keys = Object.keys(formSnapshot);
        var i;
        for (i = 0; i < keys.length; i++) {
            if (/email/i.test(keys[i]) && /@/.test(String(formSnapshot[keys[i]] || ''))) {
                return String(formSnapshot[keys[i]]).trim().slice(0, 255);
            }
        }
        try {
            var hint = document.querySelector('input[type="email"], input[name*="email" i]');
            if (hint && hint.value && /@/.test(hint.value)) {
                return String(hint.value).trim().slice(0, 255);
            }
        } catch (eEmail) {
        }
        return '';
    }

    function __incidentShouldThrottle(fingerprint) {
        var now = Date.now();
        var last = __incidentThrottleMap[fingerprint] || 0;
        if (now - last < __INCIDENT_THROTTLE_MS) {
            return true;
        }
        __incidentThrottleMap[fingerprint] = now;
        return false;
    }

    function __reportSiteIncident(raw) {
        raw = raw || {};
        if (!__visitorPixelEnabled()) {
            return;
        }
        var message = String(raw.message || raw.error_message || 'site_error').slice(0, 1024);
        var errorType = String(raw.type || raw.error_type || '').trim();
        var errorCode = String(raw.error_code || '').trim();
        var captureSource = String(raw.capture_source || raw.source || 'manual').trim();
        var path = String((window.location && window.location.pathname) || '/');
        var fingerprint = [errorType, errorCode, message.slice(0, 200), path].join('|');
        if (String(raw.filename || raw.source_url || '').indexOf('chrome-extension://') === 0) {
            return;
        }
        if (captureSource === 'js' && !message && !raw.stack) {
            return;
        }
        if (__incidentShouldThrottle(fingerprint)) {
            return;
        }

        var versions = __readRuntimeVersions();
        var formSnapshot = __captureRedactedFormSnapshot();
        var identityEmail = String(raw.identity_email || __resolveIncidentEmail(formSnapshot) || '').trim();
        var incident = {
            error_type: errorType,
            type: errorType,
            error_code: errorCode,
            error_message: message,
            message: message,
            error_stack: String(raw.stack || raw.error_stack || '').slice(0, 8000),
            capture_source: captureSource,
            http_status: parseInt(raw.http_status || raw.status || 0, 10) || 0,
            page_url: String(window.location && window.location.href || '').slice(0, 512),
            step_path: __incidentStepRing.slice(-__INCIDENT_RING_MAX),
            form_snapshot: formSnapshot,
            identity_email: identityEmail,
            deploy_version: versions.deployVersion,
            deployVersion: versions.deployVersion,
            worker_build_id: versions.workerBuildId,
            workerBuildId: versions.workerBuildId,
            theme_published_version_id: versions.themePublishedVersionId,
            themePublishedVersionId: versions.themePublishedVersionId,
            theme_published_version: versions.themePublishedVersion,
            themePublishedVersion: versions.themePublishedVersion,
            pixel_script_version: PIXEL_SCRIPT_VERSION,
            dict_version: String((__visitorTrackingConfig && __visitorTrackingConfig.dict && __visitorTrackingConfig.dict.version) || '')
        };

        __pushIncidentStep({ name: 'site_error', detail: errorType || captureSource || message.slice(0, 40) });

        if (window.WelinePixel && typeof window.WelinePixel.track === 'function') {
            window.WelinePixel.track('site_error', {
                name: message.slice(0, 120),
                incident: incident,
                value: 0
            }, { keepalive: true });
        }
    }

    function __installSiteErrorMonitors() {
        window.addEventListener('error', function (event) {
            try {
                if (event && event.target && event.target !== window && event.target.tagName) {
                    var tag = String(event.target.tagName || '').toLowerCase();
                    if (tag === 'script' || tag === 'link') {
                        __reportSiteIncident({
                            capture_source: 'resource',
                            type: 'pixel_incident_resource',
                            message: 'resource_load_failed:' + tag,
                            source_url: event.target.src || event.target.href || ''
                        });
                        return;
                    }
                }
                __reportSiteIncident({
                    capture_source: 'js',
                    type: 'pixel_incident_js',
                    message: (event && event.message) || 'js_error',
                    stack: event && event.error && event.error.stack ? event.error.stack : '',
                    filename: event && event.filename ? event.filename : ''
                });
            } catch (e1) {
            }
        }, true);

        window.addEventListener('unhandledrejection', function (event) {
            try {
                var reason = event && event.reason;
                var message = '';
                var stack = '';
                if (reason && typeof reason === 'object') {
                    message = String(reason.message || reason);
                    stack = String(reason.stack || '');
                } else {
                    message = String(reason || 'unhandledrejection');
                }
                __reportSiteIncident({
                    capture_source: 'promise',
                    type: 'pixel_incident_promise',
                    message: message,
                    stack: stack
                });
            } catch (e2) {
            }
        });

        document.addEventListener('weline:api:error', __onWelineApiError);
        window.addEventListener('weline:api:error', __onWelineApiError);

        function __onWelineApiError(event) {
            try {
                var detail = (event && event.detail) || {};
                var nestedError = detail.error && typeof detail.error === 'object' ? detail.error : {};
                var status = parseInt(detail.status || detail.http_status || nestedError.status || 0, 10) || 0;
                var errorCode = String(
                    detail.error_code
                    || detail.code
                    || nestedError.code
                    || ''
                ).trim();
                var typeHint = '';
                if (status >= 500 || errorCode === 'worker_timeout' || errorCode === 'worker_error' || errorCode === 'protocol_error') {
                    typeHint = 'pixel_incident_network';
                }
                __reportSiteIncident({
                    capture_source: 'api',
                    type: typeHint,
                    error_code: errorCode,
                    message: String(detail.message || detail.msg || nestedError.message || 'api_error'),
                    http_status: status,
                    status: status,
                    stack: String((detail.provider || '') + '/' + (detail.operation || '')).slice(0, 200)
                });
            } catch (e3) {
            }
        }
    }

    function __getConversionDedupeRuntime() {
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var block = (cfg && cfg.conversionDedupe) || {};
        var enabled = block.enabled !== false && block.enabled !== 0 && block.enabled !== '0';
        var ttlDays = parseInt(block.ttlDays, 10);
        if (!isFinite(ttlDays) || ttlDays < 1) {
            ttlDays = 180;
        }
        if (ttlDays > 730) {
            ttlDays = 730;
        }
        var events = Array.isArray(block.events) ? block.events.slice() : [
            'payment_success', 'checkout_success', 'checkout_failure', '*_checkout_success'
        ];
        return {
            enabled: enabled,
            ttlDays: ttlDays,
            ttlMs: ttlDays * 86400000,
            events: events
        };
    }

    function __conversionDedupeEventMatches(eventName, events) {
        var name = String(eventName || '').toLowerCase();
        if (!name) {
            return false;
        }
        for (var i = 0; i < events.length; i++) {
            var candidate = String(events[i] || '').toLowerCase().trim();
            if (!candidate) {
                continue;
            }
            if (candidate === name) {
                return true;
            }
            if (candidate === '*_checkout_success' && name.slice(-17) === '_checkout_success') {
                return true;
            }
        }
        return false;
    }

    var __conversionBridgeBypass = false;

    function __conversionLedgerFamily(eventName) {
        var name = String(eventName || '').toLowerCase();
        if (!name || name === 'checkout_failure') {
            return name;
        }
        if (name === 'checkout_success' || name === 'payment_success' || name.slice(-17) === '_checkout_success') {
            return 'purchase';
        }
        var ga4 = '';
        try {
            if (typeof __resolveGa4EventName === 'function') {
                ga4 = __resolveGa4EventName(name, {}, null) || '';
            }
        } catch (eGa4) {
            ga4 = '';
        }
        return ga4 === 'purchase' ? 'purchase' : name;
    }

    function __conversionAliasBags(meta) {
        var bags = [];
        if (meta && typeof meta === 'object') {
            bags.push(meta);
            if (meta.ecommerce && typeof meta.ecommerce === 'object') {
                bags.push(meta.ecommerce);
            }
            if (meta.additionalInfo && typeof meta.additionalInfo === 'object') {
                bags.push(meta.additionalInfo);
                if (meta.additionalInfo.ecommerce && typeof meta.additionalInfo.ecommerce === 'object') {
                    bags.push(meta.additionalInfo.ecommerce);
                }
            }
            if (meta.payload && typeof meta.payload === 'object') {
                bags.push(meta.payload);
            }
            if (meta.meta && typeof meta.meta === 'object') {
                bags.push(meta.meta);
            }
        }
        // 别名只取事件载荷：禁止从页面 DOM 渗入，否则无单号的 *_checkout_success 会被成功页 order_uuid 误放行。
        return bags;
    }

    function __collectConversionAliasKeys(meta) {
        var bags = __conversionAliasBags(meta);
        var fields = ['transaction_id', 'order_uuid', 'order_id', 'checkout_group_uuid', 'transaction_no'];
        var seen = {};
        var out = [];
        for (var f = 0; f < fields.length; f++) {
            var field = fields[f];
            for (var b = 0; b < bags.length; b++) {
                var bag = bags[b];
                if (!bag || bag[field] === undefined || bag[field] === null) {
                    continue;
                }
                var value = String(bag[field]).trim();
                if (!value || seen[value]) {
                    continue;
                }
                seen[value] = true;
                out.push(value.slice(0, 191));
            }
        }
        return out;
    }

    function __resolveConversionBusinessKey(meta) {
        var aliases = __collectConversionAliasKeys(meta);
        return aliases.length ? aliases[0] : '';
    }

    function __conversionDedupeStorageKey(family, businessKey) {
        var scope = 'default.default.default';
        try {
            scope = __resolvePixelStorageScope() || scope;
        } catch (eScope) {
        }
        return 'weline_pixel_dedupe:' + scope + ':' + String(family || '') + ':' + String(businessKey || '');
    }

    function __conversionAliasIsLive(family, alias, runtime) {
        var key = __conversionDedupeStorageKey(family, alias);
        var now = Date.now();
        try {
            var raw = window.localStorage && window.localStorage.getItem(key);
            if (!raw) {
                return false;
            }
            var parsed = null;
            try {
                parsed = JSON.parse(raw);
            } catch (eParse) {
                parsed = null;
            }
            var expiresAt = 0;
            if (parsed && typeof parsed === 'object') {
                expiresAt = parseInt(parsed.expiresAt, 10) || 0;
            } else if (/^\d+$/.test(String(raw))) {
                expiresAt = now + runtime.ttlMs;
            }
            if (expiresAt > now) {
                return true;
            }
            try {
                window.localStorage.removeItem(key);
            } catch (eRm) {
            }
        } catch (eLs) {
            return false;
        }
        return false;
    }

    /**
     * 只读：转化键在 TTL 内是否已见过（不写 localStorage）。
     * purchase 族任一别名命中即重复。
     */
    function __isConversionDedupeDuplicate(eventName, meta) {
        if (__conversionBridgeBypass) {
            return false;
        }
        var runtime = __getConversionDedupeRuntime();
        if (!runtime.enabled) {
            return false;
        }
        if (!__conversionDedupeEventMatches(eventName, runtime.events)) {
            return false;
        }
        var aliases = __collectConversionAliasKeys(meta);
        if (!aliases.length) {
            return false;
        }
        var family = __conversionLedgerFamily(eventName);
        for (var i = 0; i < aliases.length; i++) {
            if (__conversionAliasIsLive(family, aliases[i], runtime)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 桥接前同步占用全部别名。本轮 fanout 用 __conversionBridgeBypass 放行。
     */
    function __markConversionDedupeSeen(eventName, meta) {
        var runtime = __getConversionDedupeRuntime();
        if (!runtime.enabled) {
            return;
        }
        if (!__conversionDedupeEventMatches(eventName, runtime.events)) {
            return;
        }
        var aliases = __collectConversionAliasKeys(meta);
        if (!aliases.length) {
            return;
        }
        var family = __conversionLedgerFamily(eventName);
        var now = Date.now();
        for (var i = 0; i < aliases.length; i++) {
            var key = __conversionDedupeStorageKey(family, aliases[i]);
            try {
                window.localStorage.setItem(key, JSON.stringify({
                    ts: now,
                    expiresAt: now + runtime.ttlMs,
                    family: family
                }));
            } catch (eLs) {
            }
        }
    }

    /**
     * @deprecated 保留别名：等价于「已是重复」只读检查；写键请用 __markConversionDedupeSeen。
     */
    function __shouldDropConversionDedupe(eventName, meta) {
        return __isConversionDedupeDuplicate(eventName, meta);
    }

    /**
     * 去重丢弃：黄标记入监视流，不代替主 track，也不再进 Forwarders。
     */
    function __emitConversionDedupeSandbox(eventName, meta) {
        try {
            var sb = window.WelineEventSandbox || window.WelinePixelSandbox;
            if (!sb || typeof sb.emit !== 'function') {
                return;
            }
            var businessKey = __resolveConversionBusinessKey(meta || {});
            var params = {
                dedupe: true,
                dedupe_dropped: true,
                dedupe_family: __conversionLedgerFamily(eventName),
                business_key: businessKey,
                event: String(eventName || '')
            };
            if (meta && typeof meta === 'object') {
                ['transaction_id', 'order_uuid', 'order_id', 'checkout_group_uuid', 'transaction_no', 'value', 'currency', 'grand_total', 'payment_method'].forEach(function (k) {
                    if (meta[k] !== undefined && meta[k] !== null && meta[k] !== '') {
                        params[k] = meta[k];
                    }
                });
                var items = Array.isArray(meta.items) ? meta.items : null;
                if (!items && meta.ecommerce && typeof meta.ecommerce === 'object' && Array.isArray(meta.ecommerce.items)) {
                    items = meta.ecommerce.items;
                    if (meta.ecommerce.value !== undefined && params.value === undefined) {
                        params.value = meta.ecommerce.value;
                    }
                    if (meta.ecommerce.currency && !params.currency) {
                        params.currency = meta.ecommerce.currency;
                    }
                }
                if (items && items.length) {
                    params.items = items.slice(0, 20);
                    params.items_count = items.length;
                }
            }
            sb.emit({
                weline_event: eventName,
                eventName: eventName,
                name: eventName,
                params: params,
                timestampMs: Date.now(),
                source: 'dedupe',
                trigger: 'dedupe'
            }, {
                event_hit: true,
                hit_kind: 'dedupe',
                source: 'dedupe',
                event_name: eventName
            });
        } catch (eEmit) {
        }
    }

    window.WelinePixel = {
        endpoint: 'worker:visitor.trackPixel',
        script_version: PIXEL_SCRIPT_VERSION,
        reportIncident: function (options) {
            __reportSiteIncident(options || {});
        },
        getStickyUtm: __getStickyUtmPack,
        stickyLinkerEnabled: __stickyLinkerEnabled,
        marketingConsentAllowsStorage: __visitorMarketingConsentAllowsStorage,
        rewriteStickyAnchors: __rewriteStickyAnchors,
        stickyFormMergeEnabled: __stickyFormMergeEnabled,
        mergeStickyIntoForm: __mergeStickyIntoForm,
        env_model: __getPixelConfig('env_model', 'prod'),
        init: {
            url: window.location.href,
            userId: __getPixelConfig('user_id', ''),
            module: __getPixelConfig('module', ''),
            domain: window.location.hostname,
            eventName: 'click',
            name: window.__WelinePixelName || '{:name}',
            value: 0,
            currency: __resolvePixelCurrency('CNY'),
            websiteUrl: __getPixelSiteEnvValue('website_url', 'WELINE_WEBSITE_URL') || '',
            websiteId: __getPixelSiteEnvValue('website_id', 'WELINE_WEBSITE_ID') || '',
            userLang: __resolvePixelLanguage('zh_Hans_CN'),
            elementInfo: null,
            additionalInfo: null
        },
        initData: {
            userId: __getPixelConfig('user_id', ''),
            url: window.location.href,
            module: __getPixelConfig('module', ''),
            domain: window.location.hostname,
            eventName: 'click',
            name: window.__WelinePixelName || '{:name}',
            value: 0,
            currency: __resolvePixelCurrency('CNY'),
            websiteUrl: __getPixelSiteEnvValue('website_url', 'WELINE_WEBSITE_URL') || '',
            websiteId: __getPixelSiteEnvValue('website_id', 'WELINE_WEBSITE_ID') || '',
            userLang: __resolvePixelLanguage('zh_Hans_CN'),
            elementInfo: null,
            additionalInfo: null
        },
        // 发送数据的函数
        send: function (data) {
            data = data || this.initData;
            var payloadData = Object.assign({}, data);
            var keepalive = !!payloadData.__keepalive;
            var ga4Element = payloadData.__ga4Element || null;
            var ga4EventName = payloadData.__ga4EventName || '';
            var ga4Meta = payloadData.__ga4Meta || null;
            delete payloadData.__ga4Element;
            delete payloadData.__ga4EventName;
            delete payloadData.__ga4Meta;
            // 保留 __keepalive 到 Forwarders（GA4 beacon）；入队前再删，避免落库脏字段。

            var visitorEvent = __emitVisitorPixelEvent(
                payloadData.eventName || payloadData.event,
                payloadData,
                ga4Meta || (payloadData.additionalInfo && payloadData.additionalInfo.meta) || {},
                ga4Element,
                ga4EventName
            );
            delete payloadData.__keepalive;
            payloadData.traffic = visitorEvent.traffic;
            payloadData.forwarding = visitorEvent.forwarding;
            payloadData.section_code = visitorEvent.section_code || '';
            payloadData.section_event_key = visitorEvent.section_event_key || '';
            payloadData.section_source_status = visitorEvent.section_source_status || 'n/a';
            payloadData.additionalInfo = Object.assign({}, payloadData.additionalInfo || {}, {
                traffic: visitorEvent.traffic,
                forwarding: visitorEvent.forwarding,
                source: {
                    section_code: visitorEvent.section_code || '',
                    section_event_key: visitorEvent.section_event_key || '',
                    section_source_status: visitorEvent.section_source_status || 'n/a'
                }
            });

            if (__visitorPixelEnabled() && __visitorOptimizationEvidenceWriteAllowed()) {
                __enqueuePixelPayload(payloadData, keepalive);
            }
            // 回写原对象，便于 track() 返回值与调试面板读取到 section 溯源字段
            data.section_code = payloadData.section_code;
            data.section_event_key = payloadData.section_event_key;
            data.section_source_status = payloadData.section_source_status;
            data.additionalInfo = payloadData.additionalInfo;
            data.traffic = payloadData.traffic;
            data.forwarding = payloadData.forwarding;
        },
        track: function (eventName, meta, options) {
            options = options || {};
            var normalizedEventName = __normalizePixelEventName(eventName || 'behavior_event');
            var suppressConversion = (document.body && document.body.getAttribute('data-pixel-conversion-suppress') === '1')
                || document.querySelector('[data-pixel-conversion-suppress="1"]');
            if (suppressConversion && ['checkout_success', 'payment_success', 'purchase'].indexOf(normalizedEventName) > -1) {
                return null;
            }
            // 转化去重不拦截主 track：事件流照常；桥接门闩在 Forwarders.emit，记键在桥接之前。
            var payload = JSON.parse(JSON.stringify(this.init));
            var now = new Date();
            var domElement = options.element || (meta && meta.domElement) || null;
            var eventMeta = __getEventSpecificMeta(normalizedEventName, domElement, options.domEvent || null, meta || {});
            var mergedMeta = Object.assign({}, eventMeta, meta || {});
            delete mergedMeta.domElement;

            payload.url = window.location.href;
            payload.eventName = normalizedEventName;
            payload.timestamp = now.toISOString();
            payload.local_datetime = now.toLocaleString();
            payload.userAgent = navigator.userAgent;
            payload.referrer = document.referrer;
            payload.referer = document.referrer;
            payload.screen = {
                width: window.screen.width,
                height: window.screen.height
            };
            // Refresh site context on every track — init snapshot / HttpOnly cookies may be stale or unreadable.
            payload.websiteId = __getPixelSiteEnvValue('website_id', 'WELINE_WEBSITE_ID') || payload.websiteId || '';
            payload.websiteUrl = __getPixelSiteEnvValue('website_url', 'WELINE_WEBSITE_URL') || payload.websiteUrl || '';
            payload.websiteCode = __getPixelSiteEnvValue('website_code', 'WELINE_WEBSITE_CODE') || '';
            payload.userLang = __resolvePixelLanguage(payload.userLang || '');
            payload.currency = __resolvePixelCurrency(payload.currency || '');
            payload.elementInfo = mergedMeta.element ? mergedMeta.element : null;
            __applyEventMetaToPayload(payload, mergedMeta);
            payload.additionalInfo = __buildPixelBehaviorInfo(normalizedEventName, Object.assign({}, mergedMeta, { domElement: domElement }), options.startedAt);
            if (normalizedEventName === 'site_error' && mergedMeta.incident && typeof mergedMeta.incident === 'object') {
                payload.additionalInfo = payload.additionalInfo || {};
                payload.additionalInfo.incident = mergedMeta.incident;
                payload.name = payload.name || String(mergedMeta.incident.error_message || mergedMeta.incident.message || 'site_error').slice(0, 120);
            } else if (normalizedEventName && normalizedEventName !== 'site_error') {
                __pushIncidentStep({ name: normalizedEventName, detail: String((mergedMeta && (mergedMeta.name || mergedMeta.link_text)) || '').slice(0, 80) });
            }
            var pageBuilderAttribution = __buildPageBuilderAttribution(domElement, normalizedEventName);
            if (pageBuilderAttribution) {
                payload.additionalInfo.pagebuilder_attribution = pageBuilderAttribution;
            }
            payload.environment = (payload.additionalInfo && payload.additionalInfo.environment)
                || __buildEventEnvironmentContext(domElement);
            // GA4-aligned top-level mirrors for funnel / path attribution consumers.
            payload.page_location = payload.environment.page_location;
            payload.page_path = payload.environment.page_path;
            payload.page_title = payload.environment.page_title;
            payload.page_referrer = payload.environment.page_referrer;
            payload.page_hostname = payload.environment.page_hostname;
            payload.content_locale = payload.environment.content_locale;
            payload.session_id = payload.environment.session_id;
            payload.sticky = (payload.additionalInfo && payload.additionalInfo.sticky) || __getStickyUtmPack() || null;
            payload.page_id = payload.environment.page_id;
            payload.__ga4Element = domElement;
            payload.__ga4Meta = mergedMeta;
            // 把监视来源扁平进 payload，供字典解析 / 第三方桥接识别前端声明事件
            if (!payload.source && mergedMeta.source) {
                payload.source = mergedMeta.source;
            }
            if (!payload.hit_kind && mergedMeta.hit_kind) {
                payload.hit_kind = mergedMeta.hit_kind;
            }
            if (!payload.mapping_source && mergedMeta.mapping_source) {
                payload.mapping_source = mergedMeta.mapping_source;
            }
            payload.__ga4EventName = __resolveGa4EventName(normalizedEventName, payload, domElement);
            // 仍为空时：具体事件名直接作第三方事件名（元素 class / 自定义 track 兜底）
            if (!payload.__ga4EventName && normalizedEventName
                && normalizedEventName !== 'click'
                && normalizedEventName !== 'behavior_event') {
                payload.__ga4EventName = __normalizeGa4EventName(normalizedEventName);
            }
            payload.__ga4Params = __buildGa4Params(payload, mergedMeta, domElement);

            if (options.keepalive) {
                payload.__keepalive = true;
            }

            try {
                var host = String(window.location && window.location.hostname || '');
                var isDev = !!(window.DEV
                    || window.WELINE_ENV === 'DEV'
                    || window.__WELINE_DEBUG__
                    || host === 'localhost'
                    || host === '127.0.0.1'
                    || /\.(?:test\.weline\.com|weline\.test)$/i.test(host));
                if (isDev && typeof console !== 'undefined' && typeof console.log === 'function') {
                    console.log('[WelinePixel] track', {
                        event: normalizedEventName,
                        ga4: payload.__ga4EventName || '',
                        trigger: mergedMeta.trigger || '',
                        href: mergedMeta.href || (domElement && (domElement.href || domElement.getAttribute && domElement.getAttribute('href'))) || '',
                        text: (domElement && ((domElement.innerText || domElement.textContent || '').trim().slice(0, 80))) || '',
                        className: (domElement && typeof domElement.className === 'string') ? domElement.className : '',
                        hasPixelClass: !!(domElement && typeof domElement.className === 'string'
                            && domElement.className.indexOf('weline-pixel::') > -1),
                        dataCtaEvent: (domElement && domElement.getAttribute && (domElement.getAttribute('data-cta-event')
                            || (domElement.closest && domElement.closest('[data-cta-event]')
                                && domElement.closest('[data-cta-event]').getAttribute('data-cta-event')))) || '',
                        env: payload.environment,
                        ga4Params: payload.__ga4Params
                    });
                }
            } catch (devLogError) {
            }

            this.send(payload, options.url || this.url);
            // 转化去重不拦截主 track：事件流照常；桥接门闩在 Forwarders.emit，记键在桥接之前。
            return payload;
        },
        target: function (event) {
            var eventStartedAt = Date.now();
            this.initData = JSON.parse(JSON.stringify(this.init));
            var elementSnapshot = __getElementSnapshot(event.target);
            this.initData.elementInfo = elementSnapshot ? Object.assign({}, elementSnapshot, {
                eventType: event.type
            }) : null;
            this.initData.timestamp = new Date().toISOString();
            this.initData.local_datetime = new Date().toLocaleString();
            this.initData.userAgent = navigator.userAgent;
            this.initData.referrer = document.referrer;
            this.initData.screen = {
                width: window.screen.width,
                height: window.screen.height
            };
            var baseAdditionalInfo = {
                innerWidth: window.innerWidth,
                innerHeight: window.innerHeight,
                outerWidth: window.outerWidth,
                outerHeight: window.outerHeight
            };
            // 自定义系统级别事件名
            let url = new URL(__getPixelAppPath(window.location.href));
            // 页面浏览提取事件名
            if (event.type === 'DOMContentLoaded') {
                switch (url.pathname) {
                    case '/':
                        this.initData.eventName = 'homepage';
                        break;
                    case '/cart':
                    case '/cart.html':
                        this.initData.eventName = 'view_cart';
                        break;
                    case '/checkout':
                    case '/checkout.html':
                        this.initData.eventName = 'begin_checkout';
                        break;
                    case '/checkout/success':
                    case '/checkout/success.html':
                        this.initData.eventName = 'checkout_success';
                        break;
                    case '/checkout/failure':
                    case '/checkout/failure.html':
                        this.initData.eventName = 'checkout_failure';
                        break;
                    case '/category':
                    case '/category.html':
                        this.initData.eventName = 'category';
                        break;
                    default:
                        this.initData.eventName = 'click';
                }
                if (this.initData.eventName === 'click') {
                    if (url.pathname.startsWith('/page/')) {
                        this.initData.eventName = 'page_' + url.pathname.replace('/page/', '').replace('.html', '');
                    } else if (url.pathname.startsWith('/product/')) {
                        this.initData.eventName = 'view_item';
                    } else if (url.pathname.startsWith('/blog')) {
                        this.initData.eventName = 'blog';
                    } else if (url.pathname.startsWith('/account/')) {
                        this.initData.eventName = 'account_' + url.pathname.replace('/account/', '').replace('.html', '');
                    }
                }
            }

            // 使得自定义事件生效
            if (event.type === 'click') {
                var pixelEventName = __findPixelEventNameFromElement(event.target);
                if (pixelEventName) {
                    this.initData.eventName = pixelEventName;
                }
            }

            this.initData.eventName = __normalizePixelEventName(this.initData.eventName);
            var targetEventMeta = __getEventSpecificMeta(this.initData.eventName, event.target, event, {
                trigger: event.type,
                source: 'pixel_target',
                element: elementSnapshot
            });
            __applyEventMetaToPayload(this.initData, targetEventMeta);
            this.initData.additionalInfo = Object.assign({}, baseAdditionalInfo, __buildPixelBehaviorInfo(this.initData.eventName, {
                trigger: event.type,
                source: 'pixel_target',
                element: elementSnapshot
            }, eventStartedAt));
            this.initData.additionalInfo.meta = targetEventMeta;

            // 需要值的事件类型
            if ([
                'view_item',
                'add_to_cart',
                'view_cart',
                'buy_now',
                'friend_help_pay',
                'selection_share',
                'quick_buy',
                'express_pay',
                'begin_checkout',
                'place_order',
                'checkout_success',
                'checkout_failure',
            ].indexOf(this.initData.eventName) > -1) {
                if (!this.initData.value) {
                    var resolvedValue = __resolvePixelValue(this.initData.eventName, event.target);
                    this.initData.value = resolvedValue !== null ? resolvedValue : this.initData.value;
                }
            }
        }
    };

    function __findSearchInput(scope) {
        var root = scope || document;
        if (root && __isSearchInput(root)) {
            return root;
        }
        if (root && root.querySelector) {
            return root.querySelector('input[type="search"], input[name="q"], input[name="search"], input[name="keyword"], input[name="query"], input[placeholder*="Search"], input[placeholder*="搜索"], [data-weshop-search] input, .search-input');
        }
        return null;
    }

    function __getSearchQueryFromUrl() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            return params.get('q') || params.get('search') || params.get('keyword') || params.get('query') || '';
        } catch (e) {
            return '';
        }
    }

    function __getSearchMeta(input, trigger, form) {
        var searchForm = form || (input && input.closest ? input.closest('form') : null);
        var searchInput = input || __findSearchInput(searchForm) || __findSearchInput(document);
        var value = searchInput && typeof searchInput.value === 'string' ? searchInput.value : '';
        if (!value && searchForm && window.FormData) {
            try {
                var formData = new FormData(searchForm);
                value = formData.get('q') || formData.get('search') || formData.get('keyword') || formData.get('query') || '';
            } catch (e) {
            }
        }
        if (!value) {
            value = __getSearchQueryFromUrl();
        }
        value = String(value || '');
        return {
            trigger: trigger,
            source: 'behavior_monitor',
            query: value,
            query_length: value.length,
            input_name: searchInput && searchInput.name ? searchInput.name : '',
            input_id: searchInput && searchInput.id ? searchInput.id : '',
            form_action: searchForm ? (searchForm.getAttribute('action') || '') : '',
            form_method: searchForm ? (searchForm.getAttribute('method') || 'get') : '',
            url_query: __getSearchQueryFromUrl()
        };
    }

    function __isSearchInput(element) {
        if (!element || !element.matches) {
            return false;
        }
        return element.matches('input[type="search"], input[name="q"], input[name="search"], input[name="keyword"], input[name="query"], input[placeholder*="Search"], input[placeholder*="搜索"], [data-weshop-search] input, .search-input');
    }

    function __isSearchForm(form) {
        if (!form || !form.matches) {
            return false;
        }
        var action = String(form.getAttribute('action') || '').toLowerCase();
        return action.indexOf('search') > -1 || !!__findSearchInput(form);
    }

    function __trackPageTransition(type, toUrl, extra) {
        var fromUrl = __pixelLastLocation;
        var nextUrl = toUrl ? String(toUrl) : window.location.href;
        window.WelinePixel.track('page_transition', Object.assign({
            trigger: type,
            source: 'behavior_monitor',
            from_url: fromUrl,
            to_url: nextUrl,
            from_path: (function () {
                try { return new URL(fromUrl, window.location.href).pathname; } catch (e) { return ''; }
            })(),
            to_path: (function () {
                try { return new URL(nextUrl, window.location.href).pathname; } catch (e) { return ''; }
            })()
        }, extra || {}));
        __pixelLastLocation = nextUrl;
        // A10：SPA pushState/replaceState/popstate/hashchange 后重跑 sticky 内链改写
        __ensureStickyUtmLocked();
        __scheduleStickyAnchorRewrite(160);
    }

    function __initBehaviorTelemetry() {
        if (window.__WelinePixelBehaviorTelemetryLoaded) {
            return;
        }
        window.__WelinePixelBehaviorTelemetryLoaded = true;
        __ensureStickyUtmLocked();
        __initStickyUtmLinker();
        __initStickyFormMerge();

        // 单页生命周期只发一次真实 page_view（防双引导/重入）。
        if (!window.__WelinePixelPageViewSent) {
            window.__WelinePixelPageViewSent = true;
            window.WelinePixel.track('page_view', {
                trigger: document.readyState === 'loading' ? 'script_init' : document.readyState,
                source: 'behavior_monitor'
            });
            __trackMatchedCustomEvents(null, null, { event_name: 'page_view', trigger: 'page_view' }, {});
        }

        if (__getSearchQueryFromUrl() || window.location.pathname.indexOf('/search') === 0) {
            window.WelinePixel.track('search_result_view', __getSearchMeta(__findSearchInput(document), 'page_view'));
        }

        window.addEventListener('load', function () {
            window.WelinePixel.track('page_load', {
                trigger: 'load',
                source: 'behavior_monitor'
            });
        });

        function trackPageHide(trigger, extra) {
            if (__pixelPageHideCycleSent) {
                return;
            }
            __pixelPageHideCycleSent = true;
            window.WelinePixel.track('page_hide', Object.assign({
                trigger: trigger,
                source: 'behavior_monitor',
                duration_ms: Date.now() - __pixelPageStart
            }, extra || {}), { keepalive: true });
        }

        function trackPageExit(trigger, extra) {
            if (__pixelPageExitSent) {
                return;
            }
            // 从未对用户可见（后台标签/预渲染）：不算退出
            if (!__pixelPageWasVisible) {
                return;
            }
            __pixelPageExitSent = true;
            window.WelinePixel.track('page_exit', Object.assign({
                trigger: trigger,
                source: 'behavior_monitor',
                duration_ms: Date.now() - __pixelPageStart
            }, extra || {}), { keepalive: true });
        }

        window.addEventListener('pageshow', function (event) {
            if (document.visibilityState === 'visible') {
                __pixelPageWasVisible = true;
            }
            if (event && event.persisted) {
                // BFCache 恢复：重新开一轮停留/退出
                __pixelPageExitSent = false;
                __pixelPageHideCycleSent = false;
                __pixelPageStart = Date.now();
            }
        });

        window.addEventListener('pagehide', function (event) {
            var persisted = !!(event && event.persisted);
            if (persisted) {
                // 进入 BFCache：只冲刷停留，用户还能后退回来，不算永久退出
                trackPageHide('pagehide_persisted', { persisted: true });
                return;
            }
            trackPageExit('pagehide', { persisted: false });
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                // 切标签/最小化/锁屏/IDE 失焦：冲刷停留，不算页面退出
                trackPageHide('visibility_hidden');
            } else if (document.visibilityState === 'visible') {
                __pixelPageHideCycleSent = false;
                __pixelPageWasVisible = true;
            }
        });

        document.addEventListener('focusin', function (event) {
            if (__isVisitorDiagnosticPanelElement(event.target)) {
                return;
            }
            if (__isSearchInput(event.target)) {
                window.WelinePixel.track('search_focus', __getSearchMeta(event.target, 'focusin'));
            }
        }, true);

        document.addEventListener('input', function (event) {
            if (__isVisitorDiagnosticPanelElement(event.target)) {
                return;
            }
            if (!__isSearchInput(event.target)) {
                return;
            }
            var input = event.target;
            clearTimeout(__pixelLastSearchInputTimer);
            __pixelLastSearchInputTimer = setTimeout(function () {
                window.WelinePixel.track('search_input', __getSearchMeta(input, 'input_debounce'));
            }, 600);
        }, true);

        document.addEventListener('submit', function (event) {
            if (__isVisitorDiagnosticPanelElement(event.target)) {
                return;
            }
            if (__isSearchForm(event.target)) {
                var input = __findSearchInput(event.target);
                window.WelinePixel.track('search_submit', __getSearchMeta(input, 'submit', event.target));
            }
        }, true);

        document.addEventListener('click', function (event) {
            var element = event.target;
            if (__isVisitorDiagnosticPanelElement(element)) {
                return;
            }
            var startedAt = Date.now();
            var tracked = false;
            var pixelEventName = __findPixelEventNameFromElement(element);
            if (pixelEventName) {
                window.WelinePixel.track(pixelEventName, {
                    trigger: 'click',
                    source: 'behavior_monitor',
                    element: __getElementSnapshot(element),
                    domElement: element
                }, { startedAt: startedAt, element: element, domEvent: event });
                tracked = true;
            }

            var suggestion = element && element.closest ? element.closest('[data-search-suggestion], .search-suggestion, .search-suggestions a, [role="option"]') : null;
            if (suggestion) {
                var suggestionMeta = __getSearchMeta(__findSearchInput(document), 'suggestion_click');
                suggestionMeta.suggestion_text = __pixelText(suggestion).slice(0, 180);
                suggestionMeta.suggestion_url = suggestion.href || suggestion.getAttribute('href') || '';
                suggestionMeta.suggestion_position = 0;
                if (suggestion.parentNode && suggestion.parentNode.children) {
                    for (var suggestionIndex = 0; suggestionIndex < suggestion.parentNode.children.length; suggestionIndex++) {
                        if (suggestion.parentNode.children[suggestionIndex] === suggestion) {
                            suggestionMeta.suggestion_position = suggestionIndex + 1;
                            break;
                        }
                    }
                }
                window.WelinePixel.track('search_suggestion_click', Object.assign(suggestionMeta, {
                    trigger: 'click',
                    source: 'behavior_monitor',
                    element: __getElementSnapshot(suggestion),
                    domElement: suggestion
                }), { startedAt: startedAt, element: suggestion, domEvent: event });
                tracked = true;
            }

            var link = element && element.closest ? element.closest('a[href]') : null;
            if (link) {
                // 链接上已有业务像素标记时，只记业务事件，避免一点击再叠一条 route_click
                var linkBusinessEvent = __findPixelEventNameFromElement(link);
                if (!linkBusinessEvent) {
                    var href = link.href || link.getAttribute('href') || '';
                    window.WelinePixel.track('route_click', {
                        trigger: 'click',
                        source: 'behavior_monitor',
                        element: __getElementSnapshot(link),
                        domElement: link,
                        href: href,
                        same_origin: href ? (new URL(href, window.location.href).origin === window.location.origin) : false,
                        button: event.button,
                        alt_key: event.altKey,
                        ctrl_key: event.ctrlKey,
                        meta_key: event.metaKey,
                        shift_key: event.shiftKey
                    }, { startedAt: startedAt, keepalive: true, element: link, domEvent: event });
                    tracked = true;
                }
            }

            // 自定义条件命中：与元素声明并行（不因已有 weline-pixel:: 而跳过；同名已 track 则去重）
            var matched = __trackMatchedCustomEvents(element, event, {
                event_name: pixelEventName || 'click',
                trigger: 'click',
                already_tracked: pixelEventName || ''
            }, { startedAt: startedAt, element: element, domEvent: event });
            if (matched && matched.length) {
                tracked = true;
            }
            // 原始 click 始终继电进本会话沙盒数据流（供勾选/组装参数）；不因业务/自定义命中而吞掉
            __publishSandboxClickPassthrough(element, event, {
                event_name: '',
                event_hit: false
            });
        }, true);

        var originalPushState = history.pushState;
        var originalReplaceState = history.replaceState;
        history.pushState = function (state, title, url) {
            var result = originalPushState.apply(this, arguments);
            __trackPageTransition('pushState', url || window.location.href, { state_title: title || '' });
            return result;
        };
        history.replaceState = function (state, title, url) {
            var result = originalReplaceState.apply(this, arguments);
            __trackPageTransition('replaceState', url || window.location.href, { state_title: title || '' });
            return result;
        };
        window.addEventListener('popstate', function () {
            __trackPageTransition('popstate', window.location.href);
        });
        window.addEventListener('hashchange', function () {
            __trackPageTransition('hashchange', window.location.href);
        });

        window.addEventListener('weline:search-suggestions-request', function (event) {
            window.WelinePixel.track('search_suggestions_request', {
                trigger: 'custom_event',
                source: 'behavior_monitor',
                detail: event.detail || {}
            });
        });
    }

    function __initPageBuilderOptimizationImpressions() {
        if (window.__WelinePageBuilderOptimizationImpressionsLoaded
            || !window.WelinePixel
            || typeof window.WelinePixel.track !== 'function'
            || !window.IntersectionObserver) {
            return;
        }
        window.__WelinePageBuilderOptimizationImpressionsLoaded = true;
        var blocks = document.querySelectorAll(
            '[data-pb-attribution-version="pagebuilder_ai_v1"][data-pb-block-key]'
        );
        if (!blocks.length) {
            return;
        }

        var observer = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var block = entry.target;
                if (!entry.isIntersecting
                    || Number(entry.intersectionRatio || 0) < 0.5
                    || block.getAttribute('data-pb-ai-impression-sent') === '1') {
                    return;
                }
                block.setAttribute('data-pb-ai-impression-sent', '1');
                observer.unobserve(block);
                window.WelinePixel.track('ai_block_impression', {
                    source: 'pagebuilder_ai_site',
                    trigger: 'intersection'
                }, { element: block });
            });
        }, { threshold: [0.5] });

        Array.prototype.forEach.call(blocks, function (block) {
            observer.observe(block);
        });
    }

    function __startPageBuilderOptimizationImpressions() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', __initPageBuilderOptimizationImpressions, { once: true });
            return;
        }
        __initPageBuilderOptimizationImpressions();
    }

    __initBehaviorTelemetry();
    __startPageBuilderOptimizationImpressions();

    // 创建 iframe 沙箱
    function initPixelSandbox() {
        var iframe = document.getElementById('sandbox-pixel');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'sandbox-pixel';
            iframe.style.display = 'none';
            document.body.appendChild(iframe);
        }
        
        iframe.onload = function () {
            var iframeWindow = iframe.contentWindow;
            iframeWindow.WelinePixel = window.WelinePixel;
            // 监听沙盒中的 JavaScript 代码发送的消息
            window.addEventListener('message', function (event) {
                if (event.data === 'sandboxListen') {
                    window.addEventListener('click', function (event) {
                        window.WelinePixel.initData = JSON.parse(JSON.stringify(window.WelinePixel.init));
                        window.WelinePixel.target(event);
                        iframeWindow.postMessage(window.WelinePixel.initData, '*');
                    });
                    window.addEventListener('DOMContentLoaded', function (event) {
                        window.WelinePixel.initData = JSON.parse(JSON.stringify(window.WelinePixel.init));
                        window.WelinePixel.target(event);
                        iframeWindow.postMessage(window.WelinePixel.initData, '*');
                    });
                }
            });
            // 在 iframe 中注入 JavaScript 代码
            var iframeDocument = iframe.contentDocument || iframeWindow.document;
            iframeDocument.body.textContent = '';

            var title = iframeDocument.createElement('title');
            title.textContent = 'Pixel sandbox';
            iframeDocument.head.appendChild(title);

            var sandboxScript = iframeDocument.createElement('script');
            sandboxScript.type = 'text/javascript';
            sandboxScript.textContent = [
                "window.parent.postMessage('sandboxListen', '*');",
                "window.addEventListener('message', function(event) {",
                "    window.WelinePixel.initData = event.data;",
                "    if(window.WelinePixel.initData.eventName !== 'click') {",
                "        if(window.WelinePixel.env_model === 'dev') {",
                "            console.log('@lang{系统级别事件}', window.WelinePixel.initData);",
                "        }",
                "        window.WelinePixel.send();",
                "    }",
                "    if(window.WelinePixel.env_model === 'dev') {",
                "        console.log('@lang{自定义事件}', window.WelinePixel.initData);",
                "    }",
                "});"
            ].join('\n');
            iframeDocument.body.appendChild(sandboxScript);
        };

        iframe.src = 'about:blank';
    }
    
    // Phase 2: fake #sandbox-pixel stopped as main path.
    // Phase 6: remove initPixelSandbox entirely and use allow-scripts sandbox bridge.
    // Intentionally not auto-started.
    if (false && typeof initPixelSandbox === 'function') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPixelSandbox);
        } else {
            initPixelSandbox();
        }
    }

    // Pixel event vendors (万能壳): per-vendor sandbox/inject + event_map + scope.
    // DEV / forwarding.allowed=false already blocks emit(); dual inject demotes ga4 at runtime config.
    function __vendorScopeMatches(scope, path, area) {
        scope = scope && typeof scope === 'object' ? scope : {};
        var include = Array.isArray(scope.path_include) ? scope.path_include : ['*'];
        var exclude = Array.isArray(scope.path_exclude) ? scope.path_exclude : [];
        var areas = Array.isArray(scope.areas) ? scope.areas : ['frontend'];
        area = String(area || 'frontend').toLowerCase();
        if (areas.indexOf(area) === -1) {
            return false;
        }
        path = String(path || '/').split('?')[0];
        if (path.charAt(0) !== '/') {
            path = '/' + path;
        }
        function matchOne(pattern, p) {
            pattern = String(pattern || '').trim();
            if (!pattern) return false;
            if (pattern === '*' || pattern === '/*') return true;
            if (pattern.charAt(pattern.length - 1) === '*') {
                var prefix = pattern.slice(0, -1);
                return !prefix || p.indexOf(prefix) === 0;
            }
            return p === pattern || p.indexOf(pattern.replace(/\/$/, '') + '/') === 0;
        }
        function anyMatch(list, p) {
            for (var i = 0; i < list.length; i++) {
                if (matchOne(list[i], p)) return true;
            }
            return false;
        }
        if (!include.length) include = ['*'];
        if (!anyMatch(include, path)) return false;
        if (exclude.length && anyMatch(exclude, path)) return false;
        return true;
    }

    function __conversionMetaFromVendorEvent(event) {
        var meta = {};
        if (!event || typeof event !== 'object') {
            return meta;
        }
        var bags = [event];
        if (event.meta && typeof event.meta === 'object') {
            bags.push(event.meta);
        }
        if (event.payload && typeof event.payload === 'object') {
            bags.push(event.payload);
        }
        if (event.params && typeof event.params === 'object') {
            bags.push(event.params);
        }
        if (event.additionalInfo && typeof event.additionalInfo === 'object') {
            bags.push(event.additionalInfo);
        }
        if (event.payload && event.payload.additionalInfo && typeof event.payload.additionalInfo === 'object') {
            bags.push(event.payload.additionalInfo);
        }
        ['transaction_id', 'order_uuid', 'order_id', 'checkout_group_uuid', 'transaction_no', 'value', 'currency', 'grand_total', 'payment_method', 'items'].forEach(function (k) {
            for (var i = 0; i < bags.length; i++) {
                var bag = bags[i];
                if (bag[k] !== undefined && bag[k] !== null && bag[k] !== '') {
                    meta[k] = bag[k];
                    break;
                }
            }
        });
        return meta;
    }

    function __resolvePixelVendorArea(cfg) {
        cfg = cfg || {};
        var fromBody = '';
        try {
            fromBody = String((document.body && document.body.getAttribute('data-area')) || '').trim().toLowerCase();
        } catch (eBody) {
            fromBody = '';
        }
        if (fromBody === 'frontend' || fromBody === 'backend') {
            return fromBody;
        }
        var path = '/';
        try {
            path = String((typeof location !== 'undefined' && location.pathname) || '/');
        } catch (ePath) {
            path = '/';
        }
        if (/^\/(admin|backend|weline_admin)\b/i.test(path)) {
            return 'backend';
        }
        // 店面路径默认 frontend：避免 tracking config 误带 backend 导致沙盒厂商 scope 全灭、去重黄标不到
        return 'frontend';
    }

    function __fanoutPixelVendors(event) {
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var vendors = Array.isArray(cfg.vendors) ? cfg.vendors : [];
        if (!vendors.length || !event) {
            return;
        }
        var welineEvent = String((event.weline_event || event.eventName || event.name || '')).trim();
        var path = (typeof location !== 'undefined' && location.pathname) ? location.pathname : '/';
        var area = __resolvePixelVendorArea(cfg);
        var injectSeen = {};
        var dedupeMeta = __conversionMetaFromVendorEvent(event);
        var sandboxDedupeEmitted = false;
        var sandboxDedupeIsDup = __isConversionDedupeDuplicate(welineEvent, dedupeMeta);
        vendors.forEach(function (vendor) {
            if (!vendor || !vendor.enabled) {
                return;
            }
            if (!__vendorScopeMatches(vendor.scope, path, area)) {
                return;
            }
            var code = String(vendor.code || '').trim();
            var mode = String(vendor.mode || 'sandbox');
            if (mode === 'inject' && (code === 'ga4' || code === 'gtm')) {
                if (injectSeen.ga4 || injectSeen.gtm) {
                    mode = 'sandbox';
                }
                injectSeen[code] = true;
            }
            var map = vendor.event_map && typeof vendor.event_map === 'object' ? vendor.event_map : {};
            var mapped = map[welineEvent] || (event.platforms && event.platforms.gtm && event.platforms.gtm.eventName) || welineEvent;
            if (!mapped) {
                return;
            }
            var payload = Object.assign({}, event, {
                vendor_code: code,
                vendor_mode: mode,
                third_party_event: mapped,
                ga4_event: mapped
            });
            if (mode === 'sandbox') {
                // 门闩后的二次防护：重复则黄标并跳过沙盒 iframe（主路径已在 Forwarders 包装里拦截）
                if (sandboxDedupeIsDup) {
                    if (!sandboxDedupeEmitted) {
                        __emitConversionDedupeSandbox(welineEvent, dedupeMeta);
                        sandboxDedupeEmitted = true;
                    }
                    return;
                }
                if (window.WelinePixelSandbox && typeof window.WelinePixelSandbox.publishVendor === 'function') {
                    window.WelinePixelSandbox.publishVendor(code, payload, vendor);
                } else if (window.WelinePixelSandbox && typeof window.WelinePixelSandbox.publish === 'function') {
                    window.WelinePixelSandbox.publish(payload);
                }
                return;
            }
            // inject: built-in ga4/gtm already handled by registered forwarders when legacy flags on;
            // custom vendors run inject_js in a Function scope (no eval string as global).
            if (code !== 'ga4' && code !== 'gtm' && vendor.inject_js) {
                try {
                    var runner = new Function('event', 'vendor', String(vendor.inject_js));
                    runner(payload, vendor);
                } catch (err) {
                    if (window.DEV) {
                        console.debug('custom vendor inject_js failed', code, err);
                    }
                }
            }
        });
    }

    // True sandbox bridge (Phase 6): postMessage envelopes only; no shared WelinePixel.
    // Monitor bus: emit/subscribe observes passthrough stream without changing fanout.
    // Always upgrade API (do not keep a stale pre-emit object via ||).
    window.WelinePixelSandbox = {
        injectMode: (window.__WelineVisitorTrackingConfig && window.__WelineVisitorTrackingConfig.sandbox && window.__WelineVisitorTrackingConfig.sandbox.inject_mode) || (window.DEV ? 'dry_run' : 'live'),
        subscribers: (window.WelinePixelSandbox && Array.isArray(window.WelinePixelSandbox.subscribers)) ? window.WelinePixelSandbox.subscribers : [],
        iframe: (window.WelinePixelSandbox && window.WelinePixelSandbox.iframe) || null,
        vendorFrames: (window.WelinePixelSandbox && window.WelinePixelSandbox.vendorFrames) || {},
        _lifecycleBridged: false,
        _emitSeq: 0,
        // 监视晚于 SSR track 订阅时，靠环形缓冲回放，避免 checkout_success / 黄标丢在「全量数据流」外
        _earlyBuffer: (window.WelinePixelSandbox && Array.isArray(window.WelinePixelSandbox._earlyBuffer))
            ? window.WelinePixelSandbox._earlyBuffer
            : [],
        _earlyBufferMax: 100,
        ensureFrame: function () {
            if (this.iframe && this.iframe.isConnected) {
                return this.iframe;
            }
            var frame = document.createElement('iframe');
            frame.id = 'weline-pixel-sandbox';
            frame.setAttribute('sandbox', 'allow-scripts');
            frame.style.display = 'none';
            frame.src = 'about:blank';
            document.body.appendChild(frame);
            this.iframe = frame;
            return frame;
        },
        ensureVendorFrame: function (code, vendor) {
            code = String(code || 'default');
            if (this.vendorFrames[code] && this.vendorFrames[code].isConnected) {
                return this.vendorFrames[code];
            }
            var frame = document.createElement('iframe');
            frame.id = 'weline-pixel-sandbox-' + code;
            frame.setAttribute('sandbox', 'allow-scripts');
            frame.setAttribute('data-weline-vendor', code);
            frame.style.display = 'none';
            frame.src = 'about:blank';
            document.body.appendChild(frame);
            this.vendorFrames[code] = frame;
            try {
                var doc = frame.contentDocument;
                if (doc && vendor && vendor.sandbox_js) {
                    var script = doc.createElement('script');
                    script.type = 'text/javascript';
                    script.textContent = String(vendor.sandbox_js);
                    doc.documentElement.appendChild(script);
                }
            } catch (e) {
                if (window.DEV) {
                    console.debug('vendor sandbox bootstrap failed', code, e);
                }
            }
            return frame;
        },
        destroyVendorFrames: function () {
            var frames = this.vendorFrames || {};
            Object.keys(frames).forEach(function (code) {
                var frame = frames[code];
                try {
                    if (frame && frame.parentNode) {
                        frame.parentNode.removeChild(frame);
                    }
                } catch (e) {}
            });
            this.vendorFrames = {};
        },
        rebuildVendorFrames: function () {
            this.destroyVendorFrames();
            var cfg = window.__WelineVisitorTrackingConfig || {};
            var vendors = Array.isArray(cfg.vendors) ? cfg.vendors : [];
            for (var i = 0; i < vendors.length; i++) {
                var vendor = vendors[i] || {};
                var code = String(vendor.code || '').trim();
                if (!code) {
                    continue;
                }
                var enabled = vendor.enabled;
                if (enabled === false || enabled === 0 || enabled === '0') {
                    continue;
                }
                this.ensureVendorFrame(code, vendor);
            }
        },
        /**
         * 事件编辑后服务端 bump 的版本标记：沙盒见变大则自行拉 runtime 并重建 iframe。
         */
        noteConfigRevision: function (rev) {
            if (typeof __noteServerConfigRevision === 'function') {
                __noteServerConfigRevision(rev);
            }
        },
        normalizeEnvelope: function (raw, extras) {
            extras = extras && typeof extras === 'object' ? extras : {};
            raw = raw && typeof raw === 'object' ? raw : {};
            var detail = raw.detail && typeof raw.detail === 'object' ? raw.detail : raw;
            var params = detail.payload && typeof detail.payload === 'object'
                ? detail.payload
                : (detail.params && typeof detail.params === 'object' ? detail.params : detail);
            if (params === detail && detail.element) {
                params = Object.assign({}, detail);
            }
            var name = String(
                extras.event_name
                || detail.eventName
                || detail.weline_event
                || detail.name
                || raw.name
                || extras.name
                || 'event'
            ).trim();
            var meta = (detail.meta && typeof detail.meta === 'object') ? detail.meta : {};
            var schema = meta.event_schema || detail.event_schema || {};
            var missing = schema.missing_fields || meta.missing_fields || detail.missing_fields || detail.missing || extras.missing || [];
            if (!Array.isArray(missing)) {
                missing = [];
            }
            missing = missing.map(function (item) { return String(item || '').trim(); }).filter(Boolean);
            var mappingSource = String(extras.mapping_source || detail.mappingSource || detail.mapping_source || '');
            var ga4 = String(extras.ga4_event || (detail.platforms && detail.platforms.gtm && detail.platforms.gtm.eventName) || detail.ga4_event || '');
            var third = String(extras.third_party_event || detail.third_party_event || ga4 || '');
            var source = String(extras.source || detail.source || params.source || 'track');
            var trigger = String(detail.trigger || (params && params.trigger) || extras.trigger || '');
            if (trigger === 'click' || source === 'sandbox_passthrough') {
                source = 'click';
            }
            if (params && typeof params === 'object') {
                params = __sandboxCompactParams(params);
            }
            var hitExtras = {
                event_hit: extras.event_hit != null ? extras.event_hit : detail.event_hit,
                hit_kind: extras.hit_kind || extras.event_kind || detail.hit_kind || '',
                mapping_source: mappingSource,
                ga4_event: ga4,
                third_party_event: third,
                source: source
            };
            var eventHit = typeof __sandboxIsCustomEventHit === 'function'
                ? __sandboxIsCustomEventHit(name, hitExtras)
                : !!(mappingSource || ga4 || third);
            if (source === 'click') {
                eventHit = false;
            }
            if (source === 'lifecycle' || source === 'chain') {
                eventHit = true;
                if (!hitExtras.hit_kind) {
                    hitExtras.hit_kind = 'system';
                }
            }
            if (source === 'dedupe') {
                eventHit = true;
                hitExtras.hit_kind = 'dedupe';
            }
            var hitKind = '';
            if (eventHit) {
                hitKind = typeof __sandboxHitKind === 'function'
                    ? __sandboxHitKind(name, hitExtras)
                    : 'custom';
                if (hitKind !== 'system' && hitKind !== 'custom' && hitKind !== 'dedupe') {
                    hitKind = 'custom';
                }
            }
            var anomalyFlag = !!(extras.anomaly || detail.anomaly || /anomaly/i.test(name) || missing.length);
            var id = String(extras.id || detail.eventId || detail.event_id || ('sbx-' + Date.now() + '-' + (++this._emitSeq)));
            return {
                id: id,
                ts: Number(detail.timestampMs || extras.ts || Date.now()) || Date.now(),
                name: name,
                event_name: eventHit ? (third || ga4 || name) : '',
                source: source,
                params: params && typeof params === 'object' ? params : {},
                missing: missing,
                anomaly: anomalyFlag,
                event_hit: eventHit,
                hit_kind: hitKind,
                vendor: String(extras.vendor || detail.vendor_code || ''),
                third_party_event: third,
                mode: String(extras.mode || detail.vendor_mode || ''),
                chain: extras.chain || detail.chain || null,
                bridge_status: String(extras.bridge_status || detail.bridge_status || ''),
                bridge_patch: !!(extras.bridge_patch || detail.bridge_patch),
                raw: detail
            };
        },
        emit: function (raw, extras) {
            var envelope = this.normalizeEnvelope(raw, extras);
            try {
                this._earlyBuffer = Array.isArray(this._earlyBuffer) ? this._earlyBuffer : [];
                this._earlyBuffer.push(envelope);
                var maxBuf = this._earlyBufferMax || 100;
                if (this._earlyBuffer.length > maxBuf) {
                    this._earlyBuffer = this._earlyBuffer.slice(-maxBuf);
                }
            } catch (eBuf) {
            }
            var list = Array.isArray(this.subscribers) ? this.subscribers.slice() : [];
            for (var i = 0; i < list.length; i++) {
                try {
                    list[i](envelope);
                } catch (subErr) {
                    if (window.DEV) {
                        console.debug('WelineEventSandbox subscriber failed', subErr);
                    }
                }
            }
            try {
                window.dispatchEvent(new CustomEvent('weline:pixel-sandbox:event', { detail: envelope }));
            } catch (eDispatch) {
            }
            try {
                this.relayToAdminSession(envelope);
            } catch (eRelay) {
            }
            return envelope;
        },
        /**
         * 继电到后台「本会话沙盒」accumulate（与 Monitor 同源全量流，含 click 透传）。
         * 仅监视/生命周期助手开启时继电；lifecycle 高频辅助事件按指纹去重，避免结账 DOM 同步风暴。
         */
        relayToAdminSession: function (envelope) {
            if (!envelope || typeof envelope !== 'object') {
                return;
            }
            if (typeof __isSandboxMonitorActiveForAutoRegister === 'function'
                && !__isSandboxMonitorActiveForAutoRegister()) {
                return;
            }
            var websiteId = '';
            try {
                websiteId = String(typeof __getPixelSiteEnvValue === 'function'
                    ? (__getPixelSiteEnvValue('website_id', 'WELINE_WEBSITE_ID') || '')
                    : '');
            } catch (eWid) {
                websiteId = '';
            }
            if (!/^\d+$/.test(websiteId)) {
                return;
            }
            var eventName = String(envelope.name || envelope.event_name || 'event');
            var source = String(envelope.source || 'sandbox_stream');
            var isLifecycle = source === 'lifecycle' || source === 'chain'
                || eventName.indexOf('weline:checkout:fields-ready') !== -1;
            if (isLifecycle) {
                var rawDetail = envelope.raw && typeof envelope.raw === 'object' ? envelope.raw : {};
                var params = envelope.params && typeof envelope.params === 'object' ? envelope.params : {};
                var fp = [
                    eventName,
                    source,
                    String(params.amount || rawDetail.amount || ''),
                    String(params.currency || rawDetail.currency || ''),
                    String(params.order_uuid || rawDetail.order_uuid || ''),
                    String(params.checkout_group_uuid || rawDetail.checkout_group_uuid || ''),
                    String(params.checkout_session_code || rawDetail.checkout_session_code || '')
                ].join('|');
                var fpSeen = window.__welineSandboxStreamRelayFp;
                if (!fpSeen || typeof fpSeen !== 'object') {
                    fpSeen = {};
                    window.__welineSandboxStreamRelayFp = fpSeen;
                }
                var now = Date.now();
                var prevFpAt = Number(fpSeen[fp] || 0);
                if (prevFpAt && (now - prevFpAt) < 2500) {
                    return;
                }
                fpSeen[fp] = now;
                var fpKeys = Object.keys(fpSeen);
                if (fpKeys.length > 120) {
                    fpKeys.slice(0, fpKeys.length - 80).forEach(function (k) { delete fpSeen[k]; });
                }
            }
            var seen = window.__welineSandboxStreamRelaySeen;
            if (!seen || typeof seen !== 'object') {
                seen = {};
                window.__welineSandboxStreamRelaySeen = seen;
            }
            var id = String(envelope.id || '');
            if (id && seen[id]) {
                return;
            }
            if (id) {
                seen[id] = 1;
                var keys = Object.keys(seen);
                if (keys.length > 200) {
                    keys.slice(0, keys.length - 160).forEach(function (k) { delete seen[k]; });
                }
            }
            var compact = typeof __sandboxCompactParams === 'function'
                ? __sandboxCompactParams(envelope.params || envelope.payload || {})
                : (envelope.params && typeof envelope.params === 'object' ? envelope.params : {});
            var body = new URLSearchParams();
            body.set('website_id', websiteId);
            body.set('weline_event', eventName);
            body.set('third_party_event', String(envelope.third_party_event || envelope.event_name || ''));
            body.set('source', source);
            body.set('hit_kind', String(envelope.hit_kind || ''));
            body.set('event_hit', envelope.event_hit ? '1' : '0');
            body.set('custom', String(envelope.hit_kind || '') === 'custom' ? '1' : '0');
            body.set('path', String((window.location && window.location.href) || ''));
            body.set('at', new Date().toISOString());
            body.set('summary', String(source === 'click' ? 'click passthrough' : ''));
            try {
                body.set('params_json', JSON.stringify(compact));
            } catch (eParams) {
                body.set('params_json', '{}');
            }
            var url = '/visitor/analytics/event-picker/observe';
            body.set('sandbox_stream', '1');
            // 提交响应带 config_revision：版本变大则沙盒自行重载事件配置到浏览器
            try {
                var self = this;
                fetch(url, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: { 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (!data) {
                        return;
                    }
                    if (typeof self.noteConfigRevision === 'function') {
                        self.noteConfigRevision(data.configRevision || data.config_revision);
                    } else if (typeof __noteServerConfigRevision === 'function') {
                        __noteServerConfigRevision(data.configRevision || data.config_revision);
                    }
                }).catch(function () {});
                return;
            } catch (eFetch) {
            }
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(url, body);
                }
            } catch (eBeacon) {
            }
        },
        subscribe: function (fn) {
            if (typeof fn !== 'function') {
                return function () {};
            }
            this.subscribers = Array.isArray(this.subscribers) ? this.subscribers : [];
            this.subscribers.push(fn);
            this.ensureLifecycleBridge();
            // 回放缓冲：成功页 SSR track 常早于监视 subscribe
            try {
                var buf = Array.isArray(this._earlyBuffer) ? this._earlyBuffer.slice() : [];
                for (var ri = 0; ri < buf.length; ri++) {
                    try {
                        fn(buf[ri]);
                    } catch (eRep) {
                        if (window.DEV) {
                            console.debug('WelineEventSandbox replay failed', eRep);
                        }
                    }
                }
            } catch (eReplay) {
            }
            var self = this;
            return function unsubscribe() {
                self.unsubscribe(fn);
            };
        },
        unsubscribe: function (fn) {
            this.subscribers = (Array.isArray(this.subscribers) ? this.subscribers : []).filter(function (item) {
                return item !== fn;
            });
        },
        ensureLifecycleBridge: function () {
            if (this._lifecycleBridged) {
                return;
            }
            this._lifecycleBridged = true;
            var self = this;
            var names = [
                'weline:checkout:order-created',
                'weline:checkout:success',
                'weline:checkout:cancelled',
                'weline:checkout:anomaly',
                'weline:checkout:fields-ready',
                'weline:payment:outcome',
                'weline:payment:paid',
                'weline:payment:pending',
                'weline:payment:failed',
                'weline:payment:cancelled',
                'weline:payment:anomaly',
                'weline:cart:update',
                'weline:event-chain-complete'
            ];
            names.forEach(function (evtName) {
                window.addEventListener(evtName, function (ev) {
                    var detail = (ev && ev.detail) || {};
                    var isChain = evtName === 'weline:event-chain-complete';
                    var isAnomaly = /anomaly/i.test(evtName);
                    self.emit(Object.assign({}, detail, {
                        eventName: evtName,
                        name: evtName,
                        anomaly: isAnomaly || !!detail.anomaly
                    }), {
                        source: isChain ? 'chain' : 'lifecycle',
                        event_hit: true,
                        hit_kind: 'system',
                        event_name: evtName,
                        anomaly: isAnomaly || !!detail.anomaly,
                        missing: detail.missing || detail.missing_fields || [],
                        chain: isChain ? {
                            chain_id: detail.chain && detail.chain.id,
                            complete: true,
                            event: detail.event
                        } : null
                    });
                });
            });
        },
        publishVendor: function (code, envelope, vendor) {
            // vendor 帧执行；Monitor 只从 publish 记一笔，避免同一 envelope 多 vendor 重复刷屏
            var frame = this.ensureVendorFrame(code, vendor || {});
            var win = frame.contentWindow;
            if (!win) {
                return;
            }
            win.postMessage({
                channel: 'weline-pixel-sandbox/v1',
                command: 'envelope',
                vendor: code,
                inject_mode: this.injectMode,
                envelope: envelope
            }, '*');
        },
        publish: function (envelope) {
            // 透传入口：先通知 Monitor 订阅者，再 postMessage 进沙盒 iframe
            try {
                var detail = envelope && typeof envelope === 'object' ? envelope : {};
                var trigger = String(detail.trigger || (detail.payload && detail.payload.trigger) || '');
                var source = trigger === 'click' || String((detail.payload && detail.payload.source) || '') === 'sandbox_passthrough'
                    ? 'click'
                    : 'track';
                this.emit(detail, {
                    source: source,
                    event_hit: detail.event_hit === false ? false : undefined,
                    mapping_source: detail.mappingSource || detail.mapping_source || '',
                    ga4_event: (detail.platforms && detail.platforms.gtm && detail.platforms.gtm.eventName) || detail.ga4_event || '',
                    third_party_event: detail.third_party_event || ''
                });
            } catch (eEmit) {
            }
            try {
                var frame = this.ensureFrame();
                var target = frame.contentWindow;
                if (!target) {
                    return;
                }
                target.postMessage({
                    channel: 'weline-pixel-sandbox/v1',
                    command: 'envelope',
                    inject_mode: this.injectMode,
                    envelope: envelope
                }, '*');
            } catch (e) {
            }
        },
        postToDefaultFrame: function (envelope) {
            try {
                var frame = this.ensureFrame();
                var target = frame.contentWindow;
                if (!target) {
                    return;
                }
                target.postMessage({
                    channel: 'weline-pixel-sandbox/v1',
                    command: 'envelope',
                    inject_mode: this.injectMode,
                    envelope: envelope
                }, '*');
            } catch (eFrame) {
            }
        },
        monitor: {
            enable: function () {
                if (window.WelineEventSandboxMonitor && typeof window.WelineEventSandboxMonitor.enable === 'function') {
                    return window.WelineEventSandboxMonitor.enable();
                }
                return null;
            },
            disable: function () {
                if (window.WelineEventSandboxMonitor && typeof window.WelineEventSandboxMonitor.disable === 'function') {
                    return window.WelineEventSandboxMonitor.disable();
                }
                return null;
            },
            isEnabled: function () {
                if (window.WelineEventSandboxMonitor && typeof window.WelineEventSandboxMonitor.isEnabled === 'function') {
                    return !!window.WelineEventSandboxMonitor.isEnabled();
                }
                return false;
            }
        }
    };
    window.WelineEventSandbox = window.WelinePixelSandbox;
    window.WelinePixelSandbox.ensureLifecycleBridge();

    // 先面板，再去重，最后桥接。状态回写只走 sandbox.emit，不再调用 gtag。
    function __bridgeStatusLabel(code) {
        var map = {
            pending: '待发送',
            dedupe: '未发送·去重',
            no_key: '未发送·无单号',
            filtered: '未发送·已过滤',
            ga4: '已发送·GA4',
            gtm: '已发送·GTM',
            offline: '未接通'
        };
        return map[code] || '';
    }

    function __emitPanelBridgeStatus(event, code, patch) {
        var sb = window.WelinePixelSandbox;
        if (!sb || typeof sb.emit !== 'function' || !event) {
            return;
        }
        var id = String(event.eventId || (event.payload && event.payload.event_id) || '');
        var label = __bridgeStatusLabel(code);
        sb.emit(Object.assign({}, event, {
            eventId: id,
            bridge_status: label,
            bridge_patch: !!patch
        }), {
            id: id,
            bridge_status: label,
            bridge_patch: !!patch,
            source: 'track',
            event_name: event.eventName || event.weline_event || ''
        });
    }

    function __recentBridgeDeliveryStatus() {
        var now = Date.now();
        try {
            if (typeof __gtmConfig === 'function' && __gtmConfig().enabled && __visitorGtmTriggers && __visitorGtmTriggers[0]) {
                var gtmHit = __visitorGtmTriggers[0];
                if (gtmHit.delivery && gtmHit.delivery.mode === 'gtm' && (now - (gtmHit.at || 0)) < 2000) {
                    return 'gtm';
                }
            }
        } catch (eGtm) {
        }
        try {
            var runtime = window.__SITE_GA4__ || {};
            var last = runtime.recentTriggers && runtime.recentTriggers[0];
            if (last && last.delivery && last.delivery.mode === 'gtag' && (now - (last.timestamp || 0)) < 2000) {
                return 'ga4';
            }
        } catch (eGa4) {
        }
        return 'offline';
    }

    function __conversionBridgeGate(event) {
        event = event || {};
        var name = String(event.eventName || event.weline_event || (event.payload && (event.payload.eventName || event.payload.event)) || '').trim();
        var meta = typeof __conversionMetaFromVendorEvent === 'function' ? __conversionMetaFromVendorEvent(event) : {};
        var runtime = __getConversionDedupeRuntime();
        var filtered = !!(event.forwarding && event.forwarding.allowed === false);
        var matched = !!(runtime.enabled && __conversionDedupeEventMatches(name, runtime.events));
        if (!matched) {
            return { action: filtered ? 'filtered' : 'send', name: name, meta: meta };
        }
        if (filtered) {
            return { action: 'filtered', name: name, meta: meta };
        }
        if (__conversionLedgerFamily(name) === 'purchase' && !__collectConversionAliasKeys(meta).length) {
            return { action: 'no_key', name: name, meta: meta };
        }
        if (__isConversionDedupeDuplicate(name, meta)) {
            return { action: 'dedupe', name: name, meta: meta };
        }
        return { action: 'send', name: name, meta: meta };
    }

    var __origForwardersEmit = window.WelineVisitorForwarders.emit;
    window.WelineVisitorForwarders.emit = function (event) {
        event = event || {};
        if (event.source === 'dedupe' || event.hit_kind === 'dedupe' || event.trigger === 'dedupe') {
            return;
        }
        if (!event.eventId) {
            event.eventId = (event.payload && event.payload.event_id) || ('wv-bridge-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8));
        }
        try {
            __emitPanelBridgeStatus(event, 'pending', false);
        } catch (ePanel) {
        }
        var gate = __conversionBridgeGate(event);
        if (gate.action !== 'send') {
            try {
                __emitPanelBridgeStatus(event, gate.action, true);
            } catch (ePatch) {
            }
            if (gate.action === 'dedupe') {
                try {
                    __emitConversionDedupeSandbox(gate.name, gate.meta);
                } catch (eYellow) {
                }
            }
            return;
        }
        try {
            __markConversionDedupeSeen(gate.name, gate.meta);
        } catch (eMark) {
        }
        __conversionBridgeBypass = true;
        try {
            __origForwardersEmit.call(window.WelineVisitorForwarders, event);
        } finally {
            __conversionBridgeBypass = false;
        }
        try {
            if (window.WelinePixelSandbox && typeof window.WelinePixelSandbox.postToDefaultFrame === 'function') {
                window.WelinePixelSandbox.postToDefaultFrame(event);
            }
        } catch (eFrame) {
        }
        try {
            __emitPanelBridgeStatus(event, __recentBridgeDeliveryStatus(), true);
        } catch (eStatus) {
        }
    };

    try {
        __installSiteErrorMonitors();
    } catch (eInstall) {
    }
})();
