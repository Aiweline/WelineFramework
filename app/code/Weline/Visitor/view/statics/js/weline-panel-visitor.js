(function (window, document) {
    'use strict';

    if (window.__WELINE_VISITOR_PANEL_BOOTSTRAPPED__) {
        return;
    }
    window.__WELINE_VISITOR_PANEL_BOOTSTRAPPED__ = true;
    if (document.documentElement) {
        document.documentElement.setAttribute('data-weline-visitor-panel-ready', '1');
    }

    var CONTRACT_VERSION = 'weline-panel-visitor/v1';
    var visitorApiPromise = null;
    var sdkLoadPromise = null;
    var lastAnalytics = null;
    var lastReport = null;
    var activeSubtab = 'overview';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatNumber(value) {
        var number = Number(value || 0);
        return Number.isFinite(number) ? number.toLocaleString() : '0';
    }

    function formatMoney(value) {
        var number = Number(value || 0);
        if (!Number.isFinite(number)) {
            number = 0;
        }
        return number.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    function boolLabel(value) {
        return value ? '开启' : '关闭';
    }

    function nowIso() {
        try {
            return new Date().toISOString();
        } catch (error) {
            return '';
        }
    }

    function ensureStyle() {
        if (document.querySelector('style[data-weline-visitor-panel-style]')) {
            return;
        }
        var style = document.createElement('style');
        style.setAttribute('data-weline-visitor-panel-style', '1');
        style.textContent = [
            '#dev-tool-panel .weline-visitor-panel{color:#172033;font-size:13px;line-height:1.5}',
            '#dev-tool-panel .wvp-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:38px}',
            '#dev-tool-panel .wvp-toolbar__title{font-weight:800;color:#172033}',
            '#dev-tool-panel .wvp-toolbar__meta{font-size:12px;color:#64748b;margin-left:8px;font-weight:500}',
            '#dev-tool-panel .wvp-toolbar__actions{display:flex;gap:8px;flex-wrap:wrap}',
            '#dev-tool-panel .wvp-btn{height:32px;border:1px solid #cbd6e4;border-radius:6px;background:#fff;color:#22304a;padding:0 12px;font-weight:700;cursor:pointer}',
            '#dev-tool-panel .wvp-btn:hover{border-color:#2563eb;color:#1d4ed8;background:#f8fbff}',
            '#dev-tool-panel .wvp-btn--primary{background:#172033;border-color:#172033;color:#fff}',
            '#dev-tool-panel .wvp-btn--primary:hover{background:#0f172a;color:#fff}',
            '#dev-tool-panel .wvp-page{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:start;margin-bottom:14px;padding:14px 16px;border:1px solid #d8e1ec;border-radius:8px;background:#fff}',
            '#dev-tool-panel .wvp-page h3{margin:0 0 4px;font-size:17px;color:#101827}',
            '#dev-tool-panel .wvp-page p{margin:0;color:#5b6b83;word-break:break-all}',
            '#dev-tool-panel .wvp-chip-row{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}',
            '#dev-tool-panel .wvp-chip{display:inline-flex;align-items:center;min-height:28px;padding:3px 10px;border-radius:999px;background:#edf3fa;color:#41516a;font-weight:700;white-space:nowrap}',
            '#dev-tool-panel .wvp-chip.pass{background:#d8f8e6;color:#0f6b43}',
            '#dev-tool-panel .wvp-chip.warn{background:#fff1c2;color:#8a3b00}',
            '#dev-tool-panel .wvp-chip.fail{background:#ffe1dd;color:#9f1d14}',
            '#dev-tool-panel .wvp-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}',
            '#dev-tool-panel .wvp-metric{background:#fff;border:1px solid #d8e1ec;border-radius:8px;padding:14px 16px;min-width:0}',
            '#dev-tool-panel .wvp-metric__label{font-size:12px;color:#64748b;font-weight:700;margin-bottom:6px}',
            '#dev-tool-panel .wvp-metric__value{font-size:24px;font-weight:850;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '#dev-tool-panel .wvp-metric__note{font-size:12px;color:#64748b;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '#dev-tool-panel .wvp-tabs{display:flex;gap:8px;padding:6px;border:1px solid #d8e1ec;border-radius:8px;background:#eef3f8;margin-bottom:14px}',
            '#dev-tool-panel .wvp-subtab{height:36px;border:1px solid transparent;border-radius:7px;background:transparent;color:#40516a;font-weight:800;padding:0 14px;cursor:pointer}',
            '#dev-tool-panel .wvp-subtab.is-active{background:#fff;border-color:#c8d5e6;color:#1d4ed8;box-shadow:0 1px 3px rgba(15,23,42,.08)}',
            '#dev-tool-panel .wvp-pane{display:none}',
            '#dev-tool-panel .wvp-pane.is-active{display:block}',
            '#dev-tool-panel .wvp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}',
            '#dev-tool-panel .wvp-section{background:#fff;border:1px solid #d8e1ec;border-radius:8px;padding:16px;min-width:0}',
            '#dev-tool-panel .wvp-section h4{margin:0 0 12px;font-size:15px;color:#142033}',
            '#dev-tool-panel .wvp-list{display:grid;gap:8px}',
            '#dev-tool-panel .wvp-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #edf2f7}',
            '#dev-tool-panel .wvp-row:last-child{border-bottom:none}',
            '#dev-tool-panel .wvp-row span:first-child{color:#64748b;font-weight:700}',
            '#dev-tool-panel .wvp-row strong{color:#172033;text-align:right;word-break:break-all}',
            '#dev-tool-panel .wvp-table-wrap{overflow:auto;border:1px solid #d8e1ec;border-radius:8px;background:#fff}',
            '#dev-tool-panel .wvp-table{width:100%;border-collapse:collapse;min-width:680px}',
            '#dev-tool-panel .wvp-table th,#dev-tool-panel .wvp-table td{padding:10px 12px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top}',
            '#dev-tool-panel .wvp-table th{background:#f7fafe;color:#3b4a60;font-size:12px;font-weight:800;position:sticky;top:0}',
            '#dev-tool-panel .wvp-table td{color:#172033}',
            '#dev-tool-panel .wvp-muted{color:#64748b}',
            '#dev-tool-panel .wvp-empty{padding:30px 16px;text-align:center;color:#64748b;background:#fff;border:1px dashed #cbd6e4;border-radius:8px}',
            '#dev-tool-panel .wvp-error{padding:12px 14px;border:1px solid #ffd0ca;background:#fff5f3;color:#9f1d14;border-radius:8px;margin-bottom:12px}',
            '@media (max-width:900px){#dev-tool-panel .wvp-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}#dev-tool-panel .wvp-grid{grid-template-columns:1fr}#dev-tool-panel .wvp-page{grid-template-columns:1fr}#dev-tool-panel .wvp-chip-row{justify-content:flex-start}}'
        ].join('');
        (document.head || document.documentElement).appendChild(style);
    }

    function isLocalHost() {
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

    function browserLanguages() {
        if (Array.isArray(navigator.languages) && navigator.languages.length) {
            return navigator.languages.slice();
        }
        return navigator.language ? [navigator.language] : [];
    }

    function isChineseBrowser() {
        return browserLanguages().some(function (language) {
            return String(language || '').toLowerCase().indexOf('zh') === 0;
        });
    }

    function configList(section, key) {
        var value = section && section[key];
        if (Array.isArray(value)) {
            return value.map(function (item) {
                return String(item || '').trim();
            }).filter(Boolean);
        }
        return String(value || '').split(/[\n\r,]+/).map(function (item) {
            return item.trim();
        }).filter(Boolean);
    }

    function hostMatches(host, patterns) {
        host = String(host || '').toLowerCase();
        return (patterns || []).some(function (pattern) {
            pattern = String(pattern || '').trim().toLowerCase();
            if (!pattern) {
                return false;
            }
            if (pattern.indexOf('*.') === 0) {
                return host.slice(-pattern.length + 1) === pattern.slice(1);
            }
            return host === pattern;
        });
    }

    function pathMatches(path, prefixes) {
        return (prefixes || []).some(function (prefix) {
            prefix = String(prefix || '').trim();
            return prefix && String(path || '/').indexOf(prefix) === 0;
        });
    }

    function queryMatches(keys) {
        var lower = (keys || []).map(function (key) {
            return String(key || '').trim().toLowerCase();
        }).filter(Boolean);
        var matched = [];
        if (!lower.length) {
            return matched;
        }
        try {
            var params = new URLSearchParams(window.location.search || '');
            params.forEach(function (_value, key) {
                if (lower.indexOf(String(key || '').toLowerCase()) > -1) {
                    matched.push(key);
                }
            });
        } catch (error) {
        }
        return matched;
    }

    function referrerHost() {
        if (!document.referrer) {
            return '';
        }
        try {
            return new URL(document.referrer).hostname || '';
        } catch (error) {
            return '';
        }
    }

    function collectTrafficDecision(rules) {
        rules = rules || {};
        var host = String(window.location.hostname || '').toLowerCase();
        var path = window.location.pathname || '/';
        var userAgent = String(navigator.userAgent || '').toLowerCase();
        var reasons = [];
        var matchedRules = [];

        function add(reason, label, value) {
            reasons.push(reason);
            matchedRules.push({ reason: reason, label: label, value: value || '' });
        }

        if (rules.excludeLocalForwarding !== false && isLocalHost()) {
            add('local_access', '本地/私网访问', host);
        }
        if (hostMatches(host, configList(rules, 'excludedHosts'))) {
            add('excluded_host', '站点排除 Host', host);
        }
        if (pathMatches(path, configList(rules, 'excludedPathPrefixes'))) {
            add('excluded_path', '站点排除路径', path);
        }
        var matchedQuery = queryMatches(configList(rules, 'excludedQueryKeys'));
        if (matchedQuery.length) {
            add('excluded_query', '站点排除 Query', matchedQuery.join(','));
        }
        var refHost = referrerHost();
        if (refHost && hostMatches(refHost, configList(rules, 'excludedReferrerHosts'))) {
            add('excluded_referrer', '站点排除来源', refHost);
        }
        configList(rules, 'excludedUserAgentKeywords').some(function (keyword) {
            keyword = String(keyword || '').toLowerCase();
            if (keyword && userAgent.indexOf(keyword) > -1) {
                add('excluded_user_agent', '站点排除 User-Agent', keyword);
                return true;
            }
            return false;
        });

        return {
            source: rules.source || 'Weline_Visitor SystemConfig',
            filtered: reasons.length > 0,
            forwardable: reasons.length === 0,
            localAccess: isLocalHost(),
            reasons: reasons,
            matchedRules: matchedRules
        };
    }

    function collectConsentState(config) {
        config = config || {};
        var enabled = config.enabled === true;
        var state = window.__WelineConsentState || window.WelineConsent || {};
        var analytics = state.analytics;
        if (analytics === undefined) {
            analytics = state.analytics_storage;
        }
        return {
            enabled: enabled,
            analytics: !enabled || analytics === true || analytics === 'granted' || analytics === 'yes' || analytics === 1
        };
    }

    function normalizeEventName(value) {
        value = String(value || '').trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
        return value || 'cta_click';
    }

    function elementSelectorHint(element) {
        if (!element) {
            return '';
        }
        if (element.id) {
            return '#' + element.id;
        }
        var attrHint = '';
        ['data-pixel-event', 'data-visitor-event', 'data-cta-event', 'data-ga-event', 'data-track'].some(function (name) {
            if (element.getAttribute(name)) {
                attrHint = '[' + name + '="' + element.getAttribute(name) + '"]';
                return true;
            }
            return false;
        });
        if (attrHint) {
            return attrHint;
        }
        var classes = Array.prototype.slice.call(element.classList || []).slice(0, 2).join('.');
        return String(element.tagName || '').toLowerCase() + (classes ? '.' + classes : '');
    }

    function findPixelClassEvent(element) {
        var current = element;
        while (current && current !== document) {
            var classList = Array.prototype.slice.call(current.classList || []);
            for (var i = 0; i < classList.length; i += 1) {
                if (classList[i].indexOf('weline-pixel::') === 0 && classList[i].indexOf(':value') === -1) {
                    return {
                        element: current,
                        eventName: classList[i].replace('weline-pixel::', ''),
                        source: 'weline-pixel-class'
                    };
                }
            }
            current = current.parentElement;
        }
        return null;
    }

    function findEventAttribute(element) {
        var attrs = [
            'data-pixel-event',
            'data-visitor-event',
            'data-cta-event',
            'data-cta-action',
            'data-track-event',
            'data-track',
            'data-event-name',
            'data-event',
            'data-action',
            'data-ga-event'
        ];
        var current = element;
        while (current && current !== document) {
            for (var i = 0; i < attrs.length; i += 1) {
                var value = current.getAttribute && current.getAttribute(attrs[i]);
                if (value) {
                    return {
                        element: current,
                        eventName: value,
                        source: attrs[i]
                    };
                }
            }
            if (current.hasAttribute && current.hasAttribute('data-cta')) {
                return {
                    element: current,
                    eventName: current.getAttribute('data-cta') || 'cta_click',
                    source: 'data-cta'
                };
            }
            current = current.parentElement;
        }
        return null;
    }

    function resolveElementEvent(element) {
        var found = findEventAttribute(element) || findPixelClassEvent(element);
        var source = found ? found.source : '';
        var eventName = found ? found.eventName : '';
        var eventElement = found ? found.element : element;
        var link = element && element.closest ? element.closest('a[href]') : null;

        if (!eventName && link) {
            source = 'route-click';
            eventName = 'route_click';
            eventElement = link;
        }
        if (!eventName && element && element.matches && element.matches('button, a[role="button"], .cta, .wf-btn, .btn-primary, .button--primary')) {
            source = 'inferred-cta';
            eventName = 'cta_click';
        }
        return {
            eventName: normalizeEventName(eventName),
            element: eventElement,
            source: source || 'inferred',
            legacy: source === 'data-ga-event',
            explicit: source && source !== 'inferred-cta' && source !== 'inferred'
        };
    }

    function isDiagnosticPanelElement(element) {
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

    function hasMeaningfulInferredCta(element, text, href) {
        if (!element) {
            return false;
        }
        var normalizedText = String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (element.hasAttribute('data-cta')) {
            return true;
        }
        if (href) {
            return true;
        }
        if (!normalizedText || normalizedText === 'button' || normalizedText === 'close' || normalizedText === 'menu') {
            return false;
        }
        return normalizedText.length >= 2;
    }

    function collectEventInventory(forwarding) {
        var selector = [
            '[data-pixel-event]',
            '[data-visitor-event]',
            '[data-cta-event]',
            '[data-cta]',
            '[data-cta-action]',
            '[data-track]',
            '[data-track-event]',
            '[data-event-name]',
            '[data-event]',
            '[data-action]',
            '[data-ga-event]',
            '[class*="weline-pixel::"]',
            'a[role="button"]',
            'a[href]',
            'button',
            '.wf-btn',
            '.btn-primary',
            '.button--primary'
        ].join(',');
        var seen = {};
        return Array.prototype.slice.call(document.querySelectorAll(selector)).filter(function (element) {
            return !isDiagnosticPanelElement(element);
        }).map(function (element, index) {
            var eventInfo = resolveElementEvent(element);
            var eventElement = eventInfo.element || element;
            if (isDiagnosticPanelElement(eventElement)) {
                return null;
            }
            var text = (eventElement.innerText || eventElement.textContent || element.innerText || element.textContent || '').replace(/\s+/g, ' ').trim();
            var href = eventElement.href || eventElement.getAttribute('href') || element.href || element.getAttribute('href') || '';
            if (!eventInfo.explicit && !hasMeaningfulInferredCta(element, text, href)) {
                return null;
            }
            var key = [eventElement.tagName, eventElement.id || '', text, href, eventInfo.eventName, eventInfo.source].join('|');
            if (seen[key]) {
                return null;
            }
            seen[key] = true;
            return {
                tag: String(eventElement.tagName || element.tagName || '').toLowerCase(),
                text: text.slice(0, 120),
                href: href,
                eventName: eventInfo.eventName,
                source: eventInfo.source,
                selector: elementSelectorHint(eventElement),
                explicit: eventInfo.explicit,
                legacy: eventInfo.legacy,
                order: index,
                forwardable: forwarding.allowed === true,
                filtered: forwarding.filtered === true,
                filterReasons: forwarding.reasons || []
            };
        }).filter(Boolean).sort(function (left, right) {
            var leftPriority = left.explicit ? 0 : (left.source === 'route-click' ? 2 : 1);
            var rightPriority = right.explicit ? 0 : (right.source === 'route-click' ? 2 : 1);
            if (leftPriority !== rightPriority) {
                return leftPriority - rightPriority;
            }
            return left.order - right.order;
        }).slice(0, 80);
    }

    function collectCtaElements(forwarding) {
        return collectEventInventory(forwarding || { allowed: true, filtered: false, reasons: [] });
    }

    function readRecentTriggers() {
        var runtime = window.__SITE_GA4__ || {};
        return Array.isArray(runtime.recentTriggers) ? runtime.recentTriggers.slice(0, 50) : [];
    }

    function readRecentVisitorEvents() {
        var runtime = window.__WelineVisitorRuntime || {};
        return Array.isArray(runtime.recentEvents) ? runtime.recentEvents.slice(0, 80) : [];
    }

    function collectTrackingStatus() {
        var config = window.__WelineVisitorTrackingConfig || {};
        var pixel = config.pixel || {};
        var forwarders = config.forwarders || {};
        var customForwarder = forwarders.custom || {};
        var ga4 = Object.assign({}, config.ga4 || {}, window.__SITE_GA4__ || {});
        var measurementId = String(ga4.measurementId || '').trim().toUpperCase();
        var configured = typeof ga4.configured === 'boolean' ? ga4.configured : /^G-[A-Z0-9]{4,20}$/.test(measurementId);
        var traffic = collectTrafficDecision(config.trafficRules || {});
        var consentState = collectConsentState(config.consent || {});
        var forwardingReasons = traffic.reasons.slice();
        var forwardingRules = traffic.matchedRules.slice();
        if (!consentState.analytics) {
            forwardingReasons.push('consent_denied');
            forwardingRules.push({ reason: 'consent_denied', label: '同意模式拒绝 analytics', value: '' });
        }
        var forwarding = {
            allowed: traffic.forwardable && consentState.analytics,
            filtered: traffic.filtered || !consentState.analytics,
            reasons: forwardingReasons,
            matchedRules: forwardingRules
        };
        var localHost = typeof ga4.localHost === 'boolean' ? ga4.localHost : isLocalHost();
        var chineseBrowser = typeof ga4.chineseBrowser === 'boolean' ? ga4.chineseBrowser : isChineseBrowser();
        var enableInDev = ga4.enableInDev === true;
        var eventsAllowed = forwarding.allowed && (typeof ga4.eventsAllowed === 'boolean' ? ga4.eventsAllowed : true);
        var gtagRuntime = typeof ga4.gtagRuntime === 'boolean' ? ga4.gtagRuntime : typeof window.gtag === 'function';
        var gtagScript = typeof ga4.gtagScript === 'boolean'
            ? ga4.gtagScript
            : Boolean(document.querySelector('script[src*="googletagmanager.com/gtag/js"]'));
        var eventInventory = collectCtaElements(forwarding);

        return {
            module: 'Weline_Visitor',
            source: ga4.source || 'Weline_Visitor SystemConfig',
            page: {
                title: document.title || '',
                url: window.location.href,
                path: window.location.pathname,
                referrer: document.referrer || ''
            },
            pixel: {
                enabled: pixel.enabled !== false,
                loaded: window.__WelinePixelLoaded === true || window.__WelinePixelLazyLoaded === true,
                scheduled: window.__WelinePixelLazyScheduled === true,
                name: window.__WelinePixelName || ''
            },
            hotBuffer: Object.assign({
                enabled: false,
                flushInterval: 15,
                batchSize: 500,
                source: 'Weline_Visitor SystemConfig'
            }, config.hotBuffer || {}),
            consent: Object.assign({
                enabled: false
            }, config.consent || {}, consentState),
            traffic: traffic,
            forwarding: forwarding,
            forwarders: {
                handlers: window.WelineVisitorForwarders && window.WelineVisitorForwarders.getHandlers ? window.WelineVisitorForwarders.getHandlers() : [],
                eventBus: !(forwarders.eventBus && forwarders.eventBus.enabled === false),
                customEnabled: customForwarder.enabled === true
            },
            ga4: {
                enabled: ga4.enabled === true,
                configured: configured,
                measurementId: configured ? measurementId : '',
                enableInDev: enableInDev,
                autoTrackVisitorEvents: ga4.autoTrackVisitorEvents !== false,
                ctaEventName: ga4.ctaEventName || 'cta_click',
                debugMode: ga4.debugMode === true,
                localHost: localHost,
                chineseBrowser: chineseBrowser,
                browserLanguages: browserLanguages(),
                eventsAllowed: eventsAllowed,
                eventsWillFire: configured && gtagRuntime && forwarding.allowed,
                previewOnly: !configured && eventsAllowed,
                gtagRuntime: gtagRuntime,
                gtagScript: gtagScript,
                recentTriggers: readRecentTriggers()
            },
            cta: {
                total: eventInventory.length,
                items: eventInventory
            },
            eventInventory: {
                total: eventInventory.length,
                items: eventInventory,
                explicit: eventInventory.filter(function (item) { return item.explicit; }).length,
                legacy: eventInventory.filter(function (item) { return item.legacy; }).length
            },
            recentEvents: readRecentVisitorEvents()
        };
    }

    function getVisitorApi() {
        if (visitorApiPromise) {
            return visitorApiPromise;
        }
        visitorApiPromise = ensureWelineApi().then(function (api) {
            if (!api || typeof api.resource !== 'function') {
                throw new Error('Weline.Api.resource is not available');
            }
            return api.resource('visitor');
        });
        visitorApiPromise.catch(function () {
            visitorApiPromise = null;
        });
        return visitorApiPromise;
    }

    function configureWelineSdk() {
        var apiConfig = {
            workerUrl: '/Weline/Frontend/view/statics/js/weline-api-worker.js',
            endpoint: '/api/framework/query-bin',
            queryBinUrl: '/api/framework/query-bin',
            baseUrl: window.location.origin
        };
        window.WelineApiConfig = Object.assign({}, apiConfig, window.WelineApiConfig || {});
        window.__WelineThemeConfig = Object.assign({}, window.__WelineThemeConfig || {}, {
            modulesConfigUrl: '/Weline/Frontend/view/statics/base/weline.modules.js',
            modulesBaseUrl: '/Weline/Frontend/view/statics/js/weline-api',
            assetVersion: '20260701-visitor-panel-api',
            api: Object.assign({}, apiConfig, (window.__WelineThemeConfig && window.__WelineThemeConfig.api) || {})
        });
    }

    function loadScriptOnce(url, ready) {
        if (typeof ready === 'function' && ready()) {
            return Promise.resolve();
        }
        return new Promise(function (resolve, reject) {
            var existing = Array.prototype.slice.call(document.scripts).find(function (script) {
                return script.src && script.src.indexOf(url) !== -1;
            });
            if (existing) {
                existing.addEventListener('load', function () { resolve(); }, { once: true });
                existing.addEventListener('error', function () { reject(new Error('Script load failed: ' + url)); }, { once: true });
                if (typeof ready === 'function') {
                    window.setTimeout(function () {
                        if (ready()) {
                            resolve();
                        }
                    }, 0);
                }
                return;
            }
            var script = document.createElement('script');
            script.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=20260701-visitor-panel-api';
            script.async = true;
            script.onload = function () {
                if (typeof ready === 'function' && !ready()) {
                    reject(new Error('Script loaded but API is not ready: ' + url));
                    return;
                }
                resolve();
            };
            script.onerror = function () {
                reject(new Error('Script load failed: ' + url));
            };
            (document.head || document.documentElement).appendChild(script);
        });
    }

    function ensureWelineApi() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return Promise.resolve(window.Weline.Api);
        }
        if (sdkLoadPromise) {
            return sdkLoadPromise;
        }
        configureWelineSdk();
        sdkLoadPromise = loadScriptOnce('/Weline/Frontend/view/statics/js/weline.js', function () {
            return window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function';
        }).then(function () {
            if (window.Weline && typeof window.Weline.preLoad === 'function') {
                return window.Weline.preLoad('api').catch(function () {
                    return loadScriptOnce('/Weline/Frontend/view/statics/js/weline-api.js', function () {
                        return window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function';
                    });
                });
            }
            return loadScriptOnce('/Weline/Frontend/view/statics/js/weline-api.js', function () {
                return window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function';
            });
        }).then(function () {
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                throw new Error('Weline.Api.resource is not available');
            }
            return window.Weline.Api;
        }).catch(function (error) {
            sdkLoadPromise = null;
            throw error;
        });
        return sdkLoadPromise;
    }

    function requestVisitorByCall(operation, params) {
        return ensureWelineApi().then(function (api) {
            if (!api || typeof api.call !== 'function') {
                throw new Error('visitor.' + operation + ' is not available');
            }
            return api.call('visitor', operation, params || {});
        });
    }

    function requestVisitor(operation, params) {
        return getVisitorApi().then(function (api) {
            if (!api || typeof api[operation] !== 'function') {
                return requestVisitorByCall(operation, params);
            }
            return api[operation](params || {});
        }).catch(function (error) {
            var message = error && error.message ? String(error.message) : '';
            if (message.indexOf('Weline.Api.resource') !== -1) {
                return requestVisitorByCall(operation, params);
            }
            throw error;
        });
    }

    function responseData(response) {
        if (!response) {
            return null;
        }
        if (response.code && Number(response.code) !== 200) {
            throw new Error(response.msg || response.message || 'Visitor API returned ' + response.code);
        }
        return response.data || response;
    }

    function defaultRange() {
        var end = new Date();
        var start = new Date();
        start.setDate(end.getDate() - 30);
        function dateOnly(date) {
            return date.toISOString().slice(0, 10);
        }
        return {
            startDate: dateOnly(start),
            endDate: dateOnly(end)
        };
    }

    function loadAnalytics() {
        var range = defaultRange();
        return Promise.allSettled([
            requestVisitor('analyticsReport', range).then(responseData),
            requestVisitor('analyticsDashboard', { interval: 30, hours: 24 }).then(responseData),
            requestVisitor('pixelBufferStats', {}).then(responseData)
        ]).then(function (results) {
            var analytics = {
                range: range,
                report: null,
                dashboard: null,
                buffer: null,
                errors: []
            };
            if (results[0].status === 'fulfilled') {
                analytics.report = results[0].value;
            } else {
                analytics.errors.push(results[0].reason && results[0].reason.message || 'analyticsReport failed');
            }
            if (results[1].status === 'fulfilled') {
                analytics.dashboard = results[1].value;
            } else {
                analytics.errors.push(results[1].reason && results[1].reason.message || 'analyticsDashboard failed');
            }
            if (results[2].status === 'fulfilled') {
                analytics.buffer = results[2].value;
            } else {
                analytics.errors.push(results[2].reason && results[2].reason.message || 'pixelBufferStats failed');
            }
            analytics.errors = analytics.errors.filter(function (message, index, list) {
                return message && list.indexOf(message) === index;
            });
            lastAnalytics = analytics;
            return analytics;
        });
    }

    function renderToolbar(searchArea) {
        if (!searchArea) {
            return;
        }
        searchArea.innerHTML = [
            '<div class="wvp-toolbar">',
            '<div><span class="wvp-toolbar__title">访问者模块</span>',
            '<span class="wvp-toolbar__meta">Weline_Visitor Pixel 注入，外部平台仅做事件转发</span></div>',
            '<div class="wvp-toolbar__actions">',
            '<button type="button" class="wvp-btn" data-wvp-action="refresh">刷新</button>',
            '<button type="button" class="wvp-btn wvp-btn--primary" data-wvp-action="publish">发布 AI 报告</button>',
            '</div>',
            '</div>'
        ].join('');
    }

    function statusChip(label, tone) {
        return '<span class="wvp-chip ' + escapeHtml(tone || '') + '">' + escapeHtml(label) + '</span>';
    }

    function renderShell(status) {
        return [
            '<div id="weline-panel-visitor" class="weline-visitor-panel">',
            renderPageHeader(status),
            '<div data-wvp-error></div>',
            '<div data-wvp-metrics>' + renderMetrics(status, lastAnalytics) + '</div>',
            '<div class="wvp-tabs" role="tablist" aria-label="访问面板">',
            '<button type="button" class="wvp-subtab is-active" data-wvp-subtab="overview">概览</button>',
            '<button type="button" class="wvp-subtab" data-wvp-subtab="forwarding">事件转发</button>',
            '<button type="button" class="wvp-subtab" data-wvp-subtab="events">事件数据</button>',
            '</div>',
            '<section class="wvp-pane is-active" data-wvp-pane="overview">' + renderOverview(status, lastAnalytics) + '</section>',
            '<section class="wvp-pane" data-wvp-pane="forwarding">' + renderForwarding(status) + '</section>',
            '<section class="wvp-pane" data-wvp-pane="events">' + renderEvents(status, lastAnalytics) + '</section>',
            '</div>'
        ].join('');
    }

    function renderPageHeader(status) {
        var forwardingTone = status.forwarding.allowed ? 'pass' : 'warn';
        var ga4Tone = status.ga4.eventsWillFire ? 'pass' : (status.ga4.configured ? 'warn' : '');
        return [
            '<div class="wvp-page">',
            '<div>',
            '<h3>' + escapeHtml(status.page.title || '当前页面') + '</h3>',
            '<p>' + escapeHtml(status.page.url) + '</p>',
            '</div>',
            '<div class="wvp-chip-row">',
            statusChip('Pixel ' + boolLabel(status.pixel.enabled), status.pixel.enabled ? 'pass' : 'warn'),
            statusChip(status.forwarding.allowed ? '转发可提交' : '转发已过滤', forwardingTone),
            statusChip('事件 ' + status.eventInventory.total, status.eventInventory.total ? 'pass' : 'warn'),
            status.ga4.configured ? statusChip('GA4 ' + status.ga4.measurementId, ga4Tone) : '',
            '</div>',
            '</div>'
        ].join('');
    }

    function renderMetrics(status, analytics) {
        var report = analytics && analytics.report ? analytics.report : {};
        var dashboard = analytics && analytics.dashboard ? analytics.dashboard : {};
        var totalCount = report.time_range_stats && report.time_range_stats.total_count || report.summary && report.summary.total_count || 0;
        var totalValue = report.daily_stats && report.daily_stats.total_value || 0;
        var topEvents = report.top_events || {};
        var current = dashboard.current_period || {};
        var buffer = analytics && analytics.buffer ? analytics.buffer : {};
        return [
            '<div class="wvp-metrics">',
            metric('采集状态', status.pixel.loaded ? '已加载' : (status.pixel.enabled ? '待加载' : '已关闭'), status.pixel.name || status.module),
            metric('30天访问记录', formatNumber(totalCount), 'Visitor analytics'),
            metric('30天价值', formatMoney(totalValue), '按 Visitor Pixel value 汇总'),
            metric('最近实时事件', formatNumber(current.events || 0), current.timestamp || '最近 24 小时'),
            metric('热缓冲', buffer.enabled ? formatNumber(buffer.pending || 0) + ' 待刷' : '未启用', buffer.available ? 'WLS 内存可用' : (buffer.runtime || '当前运行时')),
            '</div>',
            topEvents && Object.keys(topEvents).length ? '' : ''
        ].join('');
    }

    function metric(label, value, note) {
        return [
            '<div class="wvp-metric">',
            '<div class="wvp-metric__label">' + escapeHtml(label) + '</div>',
            '<div class="wvp-metric__value">' + escapeHtml(value) + '</div>',
            '<div class="wvp-metric__note">' + escapeHtml(note || '') + '</div>',
            '</div>'
        ].join('');
    }

    function renderOverview(status, analytics) {
        var report = analytics && analytics.report ? analytics.report : {};
        var topEvents = report.top_events || {};
        var errors = analytics && analytics.errors ? analytics.errors : [];
        var buffer = analytics && analytics.buffer ? analytics.buffer : {};
        return [
            errors.length ? '<div class="wvp-error">' + escapeHtml(errors.join('；')) + '</div>' : '',
            '<div class="wvp-grid">',
            '<div class="wvp-section"><h4>采集运行状态</h4><div class="wvp-list">',
            row('配置来源', status.source),
            row('Pixel 开关', boolLabel(status.pixel.enabled)),
            row('Pixel 脚本', status.pixel.loaded ? '已加载' : (status.pixel.scheduled ? '已排队' : '未加载')),
            row('WLS 热缓冲', buffer.enabled ? (buffer.available ? '已启用，可用' : '已启用，内存不可用') : '未启用'),
            row('待持久化事件', formatNumber(buffer.pending || 0) + ' / ' + formatNumber(buffer.bucketCount || 0) + ' 桶'),
            row('最近 Flush', buffer.lastFlushAt ? formatTimestamp(buffer.lastFlushAt) : '暂无'),
            buffer.lastError ? row('缓冲错误', buffer.lastError) : '',
            row('当前转发状态', status.forwarding.allowed ? '可提交到外部平台' : '仅记录 Pixel，不转发'),
            row('过滤原因', status.forwarding.reasons.length ? status.forwarding.reasons.join('、') : '无'),
            row('同意模式', status.consent.enabled ? (status.consent.analytics ? '已同意 analytics' : '拒绝 analytics') : '关闭'),
            row('事件转发器', status.forwarders.handlers.length ? status.forwarders.handlers.join('、') : '暂无'),
            row('自定义转化 JS', boolLabel(status.forwarders.customEnabled)),
            row('GA4 转发器', status.ga4.configured ? (status.ga4.autoTrackVisitorEvents ? '已配置，跟随 Pixel 事件' : '已配置，自动同步关闭') : '未配置'),
            row('当前环境', status.traffic.localAccess ? '本地/私网' : '公网域名'),
            '</div></div>',
            '<div class="wvp-section"><h4>热门事件 Top 10</h4>',
            renderTopEvents(topEvents),
            '</div>',
            '</div>'
        ].join('');
    }

    function renderTopEvents(topEvents) {
        var keys = Object.keys(topEvents || {});
        if (!keys.length) {
            return '<div class="wvp-empty">暂无事件统计数据，刷新后仍为空则说明当前站点还没有 Visitor 采集记录。</div>';
        }
        return '<div class="wvp-list">' + keys.slice(0, 10).map(function (eventName) {
            return row(eventName, formatNumber(topEvents[eventName]));
        }).join('') + '</div>';
    }

    function formatTimestamp(value) {
        var number = Number(value || 0);
        if (!number) {
            return '';
        }
        var ms = number < 100000000000 ? number * 1000 : number;
        try {
            return new Date(ms).toLocaleString();
        } catch (error) {
            return String(value);
        }
    }

    function renderRuleMatches(items) {
        if (!items || !items.length) {
            return '<div class="wvp-empty">未命中本地访问或站点排除规则，当前事件允许转发。</div>';
        }
        return '<div class="wvp-list">' + items.map(function (item) {
            return row(item.label || item.reason, item.value || item.reason);
        }).join('') + '</div>';
    }

    function renderForwarding(status) {
        var ga4 = status.ga4;
        return [
            '<div class="wvp-grid">',
            '<div class="wvp-section"><h4>当前转发决策</h4><div class="wvp-list">',
            row('提交状态', status.forwarding.allowed ? '可提交到已启用外部平台' : '仅记录 Pixel，已阻止外部转发'),
            row('Pixel 记录', status.pixel.enabled ? '会记录' : 'Pixel 已关闭'),
            row('规则来源', status.traffic.source),
            row('本地访问', status.traffic.localAccess ? '是' : '否'),
            row('同意模式', status.consent.enabled ? (status.consent.analytics ? 'analytics 已同意' : 'analytics 已拒绝') : '未启用'),
            '</div></div>',
            '<div class="wvp-section"><h4>命中规则</h4>',
            renderRuleMatches(status.forwarding.matchedRules),
            '</div>',
            '</div>',
            '<div class="wvp-grid" style="margin-top:14px;">',
            '<div class="wvp-section"><h4>事件转发器</h4><div class="wvp-list">',
            row('标准事件总线', status.forwarders.eventBus ? '开启' : '关闭'),
            row('已注册处理器', status.forwarders.handlers.length ? status.forwarders.handlers.join('、') : '暂无'),
            row('自定义转化 JS', boolLabel(status.forwarders.customEnabled)),
            row('Measurement ID', ga4.measurementId || '未配置'),
            row('GA4 开关', boolLabel(ga4.enabled)),
            row('gtag.js', ga4.gtagScript ? '已加载' : '未加载'),
            row('window.gtag', ga4.gtagRuntime ? '可用' : '不可用'),
            row('debug_mode', boolLabel(ga4.debugMode)),
            row('GA4 事件提交', ga4.eventsWillFire ? '会接收 Pixel 转发' : '不会提交'),
            '</div></div>',
            '<div class="wvp-section"><h4>当前页面事件清单</h4>',
            renderCtaTable(status.eventInventory.items),
            '</div>',
            '</div>',
            '<div class="wvp-section" style="margin-top:14px;"><h4>最近 Pixel 事件</h4>',
            renderRecentVisitorEvents(status.recentEvents),
            '</div>',
            '<div class="wvp-section" style="margin-top:14px;"><h4>最近 GA4 转发结果</h4>',
            renderRecentTriggers(ga4.recentTriggers),
            '</div>'
        ].join('');
    }

    function renderEvents(status, analytics) {
        var report = analytics && analytics.report ? analytics.report : {};
        var dashboard = analytics && analytics.dashboard ? analytics.dashboard : {};
        return [
            '<div class="wvp-grid">',
            '<div class="wvp-section"><h4>30天汇总</h4><div class="wvp-list">',
            row('记录数', formatNumber(report.time_range_stats && report.time_range_stats.total_count || report.summary && report.summary.total_count || 0)),
            row('事件类型数', formatNumber(report.summary && report.summary.event_count || Object.keys(report.event_stats || {}).length)),
            row('未处理记录', formatNumber(report.summary && report.summary.un_deal_count || 0)),
            row('价值合计', formatMoney(report.daily_stats && report.daily_stats.total_value || 0)),
            '</div></div>',
            '<div class="wvp-section"><h4>实时窗口</h4><div class="wvp-list">',
            row('事件数', formatNumber(dashboard.current_period && dashboard.current_period.events || 0)),
            row('价值', formatMoney(dashboard.current_period && dashboard.current_period.value || 0)),
            row('变化', (Number(dashboard.change_percentage || 0)).toFixed(2) + '%'),
            row('时间', dashboard.current_period && dashboard.current_period.timestamp || '--'),
            '</div></div>',
            '</div>',
            '<div class="wvp-section" style="margin-top:14px;"><h4>事件分布</h4>',
            renderTopEvents(report.top_events || {}),
            '</div>'
        ].join('');
    }

    function row(label, value) {
        if (label === '') {
            return '';
        }
        return '<div class="wvp-row"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>';
    }

    function renderCtaTable(items) {
        if (!items || !items.length) {
            return '<div class="wvp-empty">未发现 Pixel/CTA 事件点。主要按钮建议补 data-pixel-event、data-visitor-event 或 data-cta-event。</div>';
        }
        return [
            '<div class="wvp-table-wrap"><table class="wvp-table"><thead><tr>',
            '<th>#</th><th>事件</th><th>来源</th><th>文案</th><th>链接</th><th>转发</th>',
            '</tr></thead><tbody>',
            items.slice(0, 30).map(function (item, index) {
                var delivery = item.forwardable ? '可提交' : '过滤: ' + (item.filterReasons || []).join('、');
                if (item.legacy) {
                    delivery += '；建议改为 Pixel 属性';
                }
                return '<tr><td>' + (index + 1) + '</td><td>' + escapeHtml(item.eventName) + '</td><td>' +
                    escapeHtml(item.source || (item.explicit ? '显式' : '推断')) + '</td><td>' +
                    escapeHtml(item.text || item.tag || item.selector) + '</td><td>' + escapeHtml(item.href || '--') +
                    '</td><td>' + escapeHtml(delivery) + '</td></tr>';
            }).join(''),
            '</tbody></table></div>'
        ].join('');
    }

    function renderRecentVisitorEvents(items) {
        if (!items || !items.length) {
            return '<div class="wvp-empty">暂无当前页面运行时 Pixel 事件。刷新后触发点击或搜索事件，这里会显示是否被过滤。</div>';
        }
        return [
            '<div class="wvp-table-wrap"><table class="wvp-table"><thead><tr>',
            '<th>时间</th><th>事件</th><th>元素</th><th>状态</th><th>原因</th>',
            '</tr></thead><tbody>',
            items.slice(0, 30).map(function (entry) {
                var forwarding = entry.forwarding || {};
                var element = entry.element || {};
                return '<tr><td>' + escapeHtml(entry.timeLabel || entry.timestampMs || '') + '</td><td>' +
                    escapeHtml(entry.eventName || '') + '</td><td>' +
                    escapeHtml(element.text || element.href || element.tagName || '--') + '</td><td>' +
                    escapeHtml(forwarding.allowed ? '已转发/可转发' : '已过滤，仅记录') + '</td><td>' +
                    escapeHtml((forwarding.reasons || []).join('、') || '--') + '</td></tr>';
            }).join(''),
            '</tbody></table></div>'
        ].join('');
    }

    function renderRecentTriggers(items) {
        if (!items || !items.length) {
            return '<div class="wvp-empty">尚未记录 GA4 转发结果。GA4 作为 Pixel 转发器，仅在规则允许且已配置时提交。</div>';
        }
        return [
            '<div class="wvp-table-wrap"><table class="wvp-table"><thead><tr>',
            '<th>时间</th><th>事件</th><th>位置/文案</th><th>投递</th>',
            '</tr></thead><tbody>',
            items.slice(0, 20).map(function (entry) {
                var params = entry.params || {};
                return '<tr><td>' + escapeHtml(entry.timeLabel || entry.timestamp || '') + '</td><td>' +
                    escapeHtml(entry.eventName || '') + '</td><td>' +
                    escapeHtml(params.cta_position || params.link_text || params.link_url || '--') + '</td><td>' +
                    escapeHtml(entry.delivery && entry.delivery.label || entry.mode || 'recorded') + '</td></tr>';
            }).join(''),
            '</tbody></table></div>'
        ].join('');
    }

    function syncSubtabs(root) {
        root.querySelectorAll('[data-wvp-subtab]').forEach(function (button) {
            var selected = button.getAttribute('data-wvp-subtab') === activeSubtab;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        root.querySelectorAll('[data-wvp-pane]').forEach(function (pane) {
            pane.classList.toggle('is-active', pane.getAttribute('data-wvp-pane') === activeSubtab);
        });
    }

    function repaint(content) {
        var root = content && content.querySelector('#weline-panel-visitor');
        if (!root) {
            return;
        }
        var status = collectTrackingStatus();
        root.querySelector('[data-wvp-metrics]').innerHTML = renderMetrics(status, lastAnalytics);
        var overview = root.querySelector('[data-wvp-pane="overview"]');
        var forwarding = root.querySelector('[data-wvp-pane="forwarding"]');
        var events = root.querySelector('[data-wvp-pane="events"]');
        if (overview) overview.innerHTML = renderOverview(status, lastAnalytics);
        if (forwarding) forwarding.innerHTML = renderForwarding(status);
        if (events) events.innerHTML = renderEvents(status, lastAnalytics);
        syncSubtabs(root);
        lastReport = buildReport();
    }

    function bindPanel(root, ctx) {
        if (!root || root.dataset.wvpBound === '1') {
            return;
        }
        root.dataset.wvpBound = '1';
        root.addEventListener('click', function (event) {
            var subtab = event.target.closest('[data-wvp-subtab]');
            if (subtab) {
                activeSubtab = subtab.getAttribute('data-wvp-subtab') || 'overview';
                syncSubtabs(root);
                return;
            }
        });
        var searchArea = ctx && ctx.searchArea;
        if (searchArea && searchArea.dataset.wvpBound !== '1') {
            searchArea.dataset.wvpBound = '1';
            searchArea.addEventListener('click', function (event) {
                var action = event.target.closest('[data-wvp-action]');
                if (!action) {
                    return;
                }
                event.preventDefault();
                if (action.getAttribute('data-wvp-action') === 'publish') {
                    publishReport();
                    return;
                }
                if (action.getAttribute('data-wvp-action') === 'refresh') {
                    refresh(ctx);
                }
            });
        }
    }

    function refresh(ctx) {
        var content = ctx && ctx.content ? ctx.content : document.getElementById('dev-tool-content');
        if (!content) {
            return Promise.resolve(buildReport());
        }
        var root = content.querySelector('#weline-panel-visitor');
        var errorNode = root ? root.querySelector('[data-wvp-error]') : null;
        if (errorNode) {
            errorNode.innerHTML = '';
        }
        return loadAnalytics().then(function () {
            repaint(content);
            return lastReport;
        }).catch(function (error) {
            if (errorNode) {
                errorNode.innerHTML = '<div class="wvp-error">' + escapeHtml(error && error.message || '访问数据加载失败') + '</div>';
            }
            repaint(content);
            return lastReport;
        });
    }

    function renderInto(container, ctx) {
        ensureStyle();
        var content = container || document.getElementById('dev-tool-content');
        if (!content) {
            return Promise.resolve(buildReport());
        }
        var status = collectTrackingStatus();
        renderToolbar(ctx && ctx.searchArea);
        content.innerHTML = renderShell(status);
        var root = content.querySelector('#weline-panel-visitor');
        bindPanel(root, ctx || {});
        lastReport = buildReport();
        return refresh(Object.assign({}, ctx || {}, { content: content }));
    }

    function buildReport() {
        var status = collectTrackingStatus();
        return {
            contractVersion: CONTRACT_VERSION,
            command: 'weline-panel:visitor',
            generatedAt: nowIso(),
            page: status.page,
            tracking: {
                source: status.source,
                pixel: status.pixel,
                hotBuffer: status.hotBuffer,
                traffic: status.traffic,
                forwarding: status.forwarding,
                consent: status.consent,
                forwarders: status.forwarders,
                ga4: status.ga4,
                cta: status.cta,
                eventInventory: status.eventInventory,
                recentEvents: status.recentEvents
            },
            analytics: lastAnalytics,
            actions: buildActions(status)
        };
    }

    function buildActions(status) {
        var actions = [];
        if (status.pixel.enabled && !status.pixel.loaded) {
            actions.push({
                type: 'check',
                label: '确认 Weline Pixel 是否被延迟加载或被页面策略阻止。',
                tab: 'visitor'
            });
        }
        if (status.forwarding.filtered) {
            actions.push({
                type: 'review_filter',
                label: '当前流量命中过滤规则：Pixel 会记录，但不会转发到 GA4 或自定义平台。',
                tab: 'visitor'
            });
        }
        if (!status.eventInventory.total) {
            actions.push({
                type: 'instrument',
                label: '为主要转化按钮补 data-pixel-event、data-visitor-event 或 data-cta-event。',
                tab: 'visitor'
            });
        }
        if (!status.ga4.configured) {
            actions.push({
                type: 'configure_forwarder',
                label: '如需 GA4， 在 Weline_Visitor 站点统计配置中设置 GA4 Measurement ID；SEO 面板不负责该配置。',
                tab: 'visitor'
            });
        }
        return actions;
    }

    function publishReport() {
        var report = buildReport();
        lastReport = report;
        window.__WELINE_VISITOR_PANEL_REPORT__ = report;
        window.__WELINE_PANEL_VISITOR_REPORT__ = report;
        try {
            window.dispatchEvent(new CustomEvent('weline-panel:visitor-report', { detail: report }));
        } catch (error) {
        }
        return report;
    }

    function normalizeTrigger(entry) {
        entry = entry || {};
        var runtime = window.__SITE_GA4__ = window.__SITE_GA4__ || {};
        runtime.recentTriggers = Array.isArray(runtime.recentTriggers) ? runtime.recentTriggers : [];
        runtime.recentTriggers.unshift(Object.assign({
            timestamp: Date.now(),
            timeLabel: new Date().toLocaleTimeString(),
            delivery: { label: 'Recorded' }
        }, entry));
        runtime.recentTriggers = runtime.recentTriggers.slice(0, 50);
    }

    window.addEventListener('site:ga4-trigger', function (event) {
        normalizeTrigger(event.detail || {});
        var content = document.getElementById('dev-tool-content');
        if (content && content.querySelector('#weline-panel-visitor')) {
            repaint(content);
        }
    });

    window.addEventListener('weline:visitor-runtime-event', function () {
        var content = document.getElementById('dev-tool-content');
        if (content && content.querySelector('#weline-panel-visitor')) {
            repaint(content);
        }
    });

    function registerWithPanel() {
        var manifest = {
            id: 'visitor',
            title: '访问',
            order: 190,
            activate: function (ctx) {
                return renderInto(ctx && ctx.content, ctx);
            },
            report: function () {
                return buildReport();
            }
        };
        window.__WELINE_PANEL_TAB_QUEUE__ = window.__WELINE_PANEL_TAB_QUEUE__ || [];
        window.__WELINE_PANEL_REPORT_PROVIDERS__ = window.__WELINE_PANEL_REPORT_PROVIDERS__ || {};
        window.__WELINE_PANEL_REPORT_PROVIDERS__.visitor = function () {
            return buildReport();
        };
        if (window.WelinePanel && typeof window.WelinePanel.registerTab === 'function') {
            window.WelinePanel.registerTab(manifest);
        } else {
            window.__WELINE_PANEL_TAB_QUEUE__.push(manifest);
        }
        if (window.WelinePanel && typeof window.WelinePanel.registerReportProvider === 'function') {
            window.WelinePanel.registerReportProvider('visitor', function () {
                return buildReport();
            });
        }
    }

    window.__WELINE_VISITOR_PANEL__ = {
        renderInto: renderInto,
        report: buildReport,
        publish: publishReport,
        refresh: refresh
    };

    registerWithPanel();
})(window, document);
