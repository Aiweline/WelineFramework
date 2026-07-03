(function() {
    // 防止重复加载像素代码
    if (window.__WelinePixelLoaded) {
        console.warn('Weline Pixel: 像素已加载，跳过重复加载。如需添加自定义事件，请使用像素hook方式（监听 Weline_Visitor::taglib_pixel 事件）');
        return;
    }
    window.__WelinePixelLoaded = true;

    var __visitorTrackingConfig = window.__WelineVisitorTrackingConfig || {};

    window.addEventListener('weline:visitor-tracking-config', function (event) {
        if (event && event.detail && typeof event.detail === 'object') {
            __visitorTrackingConfig = event.detail;
            window.__WelineVisitorTrackingConfig = __visitorTrackingConfig;
            __loadVisitorGa4();
            __loadCustomVisitorForwarder();
        }
    });

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
    window.__WelineVisitorForwarders = __visitorForwarders;
    window.WelineVisitorForwarders = Object.assign({}, window.WelineVisitorForwarders || {}, {
        register: function (name, handler) {
            name = String(name || '').trim();
            if (!name || typeof handler !== 'function') {
                return false;
            }
            __visitorForwarders[name] = handler;
            return true;
        },
        unregister: function (name) {
            delete __visitorForwarders[String(name || '').trim()];
        },
        emit: function (event) {
            if (event && event.forwarding && event.forwarding.allowed === false) {
                return;
            }
            Object.keys(__visitorForwarders).forEach(function (name) {
                try {
                    __visitorForwarders[name](event);
                } catch (error) {
                    if (window.DEV) {
                        console.debug('Weline Visitor forwarder failed:', name, error && error.message ? error.message : error);
                    }
                }
            });
        },
        getHandlers: function () {
            return Object.keys(__visitorForwarders);
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
        return {
            contractVersion: 'weline-visitor-event/v1',
            command: 'weline-visitor-event',
            eventId: eventId,
            eventName: normalized,
            timestampMs: Date.now(),
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
                }
            }
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
        var event = __buildVisitorEventEnvelope(pixelEventName, payload, meta, element, platformEventName);
        __recordVisitorRuntimeEvent(event);
        window.WelineVisitorForwarders.emit(event);
        try {
            window.dispatchEvent(new CustomEvent('weline:visitor:event', { detail: event }));
        } catch (error) {
        }
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
            '#dev-tool-trigger',
            '.dev-tool-container',
            '.dev-tool-trigger',
            '[data-dev-tool-action]',
            '[data-wvp-subtab]',
            '[data-weline-panel-visitor-bootstrap]',
            '[data-weline-panel-seo-bootstrap]'
        ].join(',')));
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
        window.gtag('config', runtime.measurementId, {
            send_page_view: true,
            debug_mode: runtime.debugMode
        });

        if (!document.querySelector('script[data-weline-visitor-ga4="true"][src*="' + runtime.measurementId + '"]')) {
            var script = document.createElement('script');
            script.async = true;
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(runtime.measurementId);
            script.setAttribute('data-weline-visitor-ga4', 'true');
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

    function __resolveGa4EventName(pixelEventName, payload, element) {
        var explicit = '';
        if (element && element.closest) {
            var explicitNode = element.closest('[data-pixel-event], [data-visitor-event], [data-cta-event], [data-ga-event]');
            if (explicitNode) {
                explicit = explicitNode.getAttribute('data-pixel-event') ||
                    explicitNode.getAttribute('data-visitor-event') ||
                    explicitNode.getAttribute('data-cta-event') ||
                    explicitNode.getAttribute('data-ga-event') ||
                    '';
            }
        }
        explicit = __normalizeGa4EventName(explicit);
        if (explicit) {
            return explicit;
        }

        var normalized = __normalizePixelEventName(pixelEventName || payload && payload.eventName || '');
        var map = {
            view_item: 'view_item',
            add_to_cart: 'add_to_cart',
            begin_checkout: 'begin_checkout',
            checkout_success: 'purchase',
            checkout_failure: 'checkout_failure',
            search_submit: 'search',
            search_suggestion_click: 'select_content'
        };
        if (map[normalized]) {
            return map[normalized];
        }
        if (__isGa4CtaElement(element)) {
            return __ga4Config().ctaEventName || 'cta_click';
        }
        return '';
    }

    function __buildGa4Params(payload, meta, element) {
        payload = payload || {};
        meta = meta || {};
        var elementInfo = payload.elementInfo || meta.element || {};
        var params = {
            visitor_event_name: payload.eventName || payload.event || '',
            page_location: window.location.href,
            page_title: document.title || '',
            page_path: window.location.pathname,
            event_id: __visitorEventId(payload),
            pixel_name: payload.name || window.__WelinePixelName || '',
            link_url: payload.href || elementInfo.href || (element && element.href) || '',
            link_text: payload.link_text || elementInfo.text || (element ? (element.innerText || element.textContent || '').trim().slice(0, 120) : '')
        };
        ['value', 'currency', 'transaction_id', 'items', 'search_term', 'query', 'product_id', 'item_id', 'sku'].forEach(function (key) {
            if (payload[key] !== undefined && payload[key] !== null && payload[key] !== '') {
                params[key === 'query' ? 'search_term' : key] = payload[key];
            }
        });
        var runtime = __ga4Config();
        if (runtime.debugMode) {
            params.debug_mode = true;
        }
        if (params.currency === 'RMB') {
            params.currency = 'CNY';
        }
        return params;
    }

    function __sendVisitorGa4Event(pixelEventName, payload, meta, element) {
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
    });

    __loadVisitorGa4();
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
        return {
            schema: 'weline_behavior_timing_v1',
            time: __getPixelTimeData(eventStartedAt),
            performance: __getPixelPerformanceTiming(),
            funnel: __getFunnelChain(eventName),
            navigation: {
                current_url: window.location.href,
                current_path: window.location.pathname,
                current_search: window.location.search,
                current_hash: window.location.hash,
                referrer: document.referrer || '',
                last_location: __pixelLastLocation
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

    function __findPixelEventNameFromElement(element) {
        var current = element;
        while (current && current !== document) {
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
        return element.closest('.product-detail-view, .product-detail, .product-main, .product-info, .product-info-section, .product-card, .cart-item, .cart-summary, .cart-summary-card, .mini-cart-drawer, .checkout-summary, .weshop-checkout-summary, .weshop-checkout-card, form')
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
        return options;
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
        if (price === null) {
            price = __firstNumber(context, ['[data-price]', '[data-pixel-value]', '.price-current', '.price-amount', '.text-red-500', '.font-bold']);
        }
        var qty = __getQuantity(element);
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
            items: items
        };
    }

    function __getCheckoutMeta(eventName, element) {
        var meta = __getCartMeta(eventName, element);
        var shipping = __firstText(document, ['input[name*="shipping"]:checked + label', '[data-selected-shipping]', '.shipping-method.active', '.shipping-method.selected']);
        var payment = __firstText(document, ['input[name*="payment"]:checked + label', '[data-selected-payment]', '.payment-method.active', '.payment-method.selected']);
        var orderId = __firstText(document, ['[data-order-id]', '[data-order-number]', '.order-number', '.order-id']);
        if (shipping !== '') {
            meta.shipping_method = shipping;
        }
        if (payment !== '') {
            meta.payment_method = payment;
        }
        if (orderId !== '') {
            meta.order_id = orderId;
            meta.transaction_id = orderId;
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
        } else if (['view_item', 'add_to_cart', 'buy_now', 'add_to_wishlist'].indexOf(normalized) > -1) {
            meta = __getProductMeta(normalized, element);
            required = ['product_id', 'name', 'price', 'value', 'items'];
        } else if (['view_cart'].indexOf(normalized) > -1) {
            meta = __getCartMeta(normalized, element);
            required = ['cart_total', 'items'];
        } else if (['begin_checkout', 'place_order', 'checkout_success', 'checkout_failure'].indexOf(normalized) > -1) {
            meta = __getCheckoutMeta(normalized, element);
            required = ['grand_total', 'items'];
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
            'product_id', 'item_id', 'sku', 'name', 'content_name', 'price', 'quantity', 'qty', 'value', 'items', 'selected_options', 'product_url',
            'total', 'grand_total', 'cart_total', 'cart_items_count',
            'shipping_method', 'payment_method', 'order_id', 'transaction_id',
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

        compact.additionalInfo = {
            schema: additionalInfo.schema || 'weline_behavior_timing_v1',
            time: additionalInfo.time || {},
            performance: __compactPixelPerformance(additionalInfo.performance),
            funnel: __compactPixelFunnel(additionalInfo.funnel),
            navigation: {
                current_url: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_url, 512),
                current_path: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_path, 160),
                current_search: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_search, 160),
                current_hash: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.current_hash, 80),
                referrer: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.referrer, 512),
                last_location: __pixelTruncate(additionalInfo.navigation && additionalInfo.navigation.last_location, 512)
            },
            viewport: additionalInfo.viewport || {},
            meta: additionalInfo.meta || {}
        };

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

    window.WelinePixel = {
        endpoint: 'worker:visitor.trackPixel',
        env_model: __getPixelConfig('env_model', 'prod'),
        init: {
            url: window.location.href,
            userId: __getPixelConfig('user_id', ''),
            module: __getPixelConfig('module', ''),
            domain: window.location.hostname,
            eventName: 'click',
            name: window.__WelinePixelName || '{:name}',
            value: 0,
            currency: __getPixelCookie('WELINE_USER_CURRENCY') || 'RMB',
            websiteUrl: __getPixelCookie('WELINE_WEBSITE_URL') || '',
            websiteId: __getPixelCookie('WELINE_WEBSITE_ID') || '',
            userLang: __getPixelCookie('WELINE_USER_LANG') || 'zh-CN',
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
            currency: __getPixelCookie('WELINE_USER_CURRENCY') || 'RMB',
            websiteUrl: __getPixelCookie('WELINE_WEBSITE_URL') || '',
            websiteId: __getPixelCookie('WELINE_WEBSITE_ID') || '',
            userLang: __getPixelCookie('WELINE_USER_LANG') || 'zh-CN',
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
            delete payloadData.__keepalive;
            delete payloadData.__ga4Element;
            delete payloadData.__ga4EventName;
            delete payloadData.__ga4Meta;

            var visitorEvent = __emitVisitorPixelEvent(
                payloadData.eventName || payloadData.event,
                payloadData,
                ga4Meta || (payloadData.additionalInfo && payloadData.additionalInfo.meta) || {},
                ga4Element,
                ga4EventName
            );
            payloadData.traffic = visitorEvent.traffic;
            payloadData.forwarding = visitorEvent.forwarding;
            payloadData.additionalInfo = Object.assign({}, payloadData.additionalInfo || {}, {
                traffic: visitorEvent.traffic,
                forwarding: visitorEvent.forwarding
            });

            if (__visitorPixelEnabled()) {
                __enqueuePixelPayload(payloadData, keepalive);
            }
        },
        track: function (eventName, meta, options) {
            options = options || {};
            var normalizedEventName = __normalizePixelEventName(eventName || 'behavior_event');
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
            payload.screen = {
                width: window.screen.width,
                height: window.screen.height
            };
            payload.elementInfo = mergedMeta.element ? mergedMeta.element : null;
            __applyEventMetaToPayload(payload, mergedMeta);
            payload.additionalInfo = __buildPixelBehaviorInfo(normalizedEventName, mergedMeta, options.startedAt);
            payload.__ga4Element = domElement;
            payload.__ga4Meta = mergedMeta;
            payload.__ga4EventName = __resolveGa4EventName(normalizedEventName, payload, domElement);

            if (options.keepalive) {
                payload.__keepalive = true;
            }

            this.send(payload, options.url || this.url);
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
    }

    function __initBehaviorTelemetry() {
        if (window.__WelinePixelBehaviorTelemetryLoaded) {
            return;
        }
        window.__WelinePixelBehaviorTelemetryLoaded = true;

        window.WelinePixel.track('page_view', {
            trigger: document.readyState === 'loading' ? 'script_init' : document.readyState,
            source: 'behavior_monitor'
        });

        if (__getSearchQueryFromUrl() || window.location.pathname.indexOf('/search') === 0) {
            window.WelinePixel.track('search_result_view', __getSearchMeta(__findSearchInput(document), 'page_view'));
        }

        window.addEventListener('load', function () {
            window.WelinePixel.track('page_load', {
                trigger: 'load',
                source: 'behavior_monitor'
            });
        });

        function trackPageExit(trigger) {
            if (__pixelPageExitSent) {
                return;
            }
            __pixelPageExitSent = true;
            window.WelinePixel.track('page_exit', {
                trigger: trigger,
                source: 'behavior_monitor',
                duration_ms: Date.now() - __pixelPageStart
            }, { keepalive: true });
        }

        window.addEventListener('pagehide', function () {
            trackPageExit('pagehide');
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                trackPageExit('visibility_hidden');
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
            var pixelEventName = __findPixelEventNameFromElement(element);
            if (pixelEventName) {
                window.WelinePixel.track(pixelEventName, {
                    trigger: 'click',
                    source: 'behavior_monitor',
                    element: __getElementSnapshot(element),
                    domElement: element
                }, { startedAt: startedAt, element: element, domEvent: event });
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
            }

            var link = element && element.closest ? element.closest('a[href]') : null;
            if (link) {
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
            }
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

    __initBehaviorTelemetry();

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
    
    // 等待 DOM 加载完成后初始化沙箱
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPixelSandbox);
    } else {
        initPixelSandbox();
    }
})();
