(function (window, document) {
    'use strict';

    if (window.__WELINE_PANEL_LOADER_READY__) {
        return;
    }
    window.__WELINE_PANEL_LOADER_READY__ = true;
    if (document.documentElement) {
        document.documentElement.setAttribute('data-weline-panel-loader-ready', '1');
    }

    var script = document.currentScript;
    var apiBase = script ? (script.getAttribute('data-api-base') || '') : '';
    var requestId = script ? (script.getAttribute('data-request-id') || '') : '';
    var tokenRequired = script ? script.getAttribute('data-token-required') === '1' : false;
    var sessionUrl = script ? (script.getAttribute('data-session-url') || '') : '';
    var command = 'weline';
    var keyBuffer = '';
    var loadingPromise = null;
    var authorized = !tokenRequired;
    var panelReadyCallbacks = [];
    var restoreAttempted = false;
    var panelStateKey = 'dev-panel-state';

    window.__WELINE_PANEL_CONFIG__ = Object.assign({}, window.__WELINE_PANEL_CONFIG__ || {}, {
        apiBase: apiBase,
        requestId: requestId,
        tokenRequired: tokenRequired,
        sessionUrl: sessionUrl
    });
    window.__WELINE_PANEL_TAB_QUEUE__ = window.__WELINE_PANEL_TAB_QUEUE__ || [];
    window.__WELINE_PANEL_REPORT_PROVIDERS__ = window.__WELINE_PANEL_REPORT_PROVIDERS__ || {};
    window.__WELINE_PANEL_TABS__ = window.__WELINE_PANEL_TABS__ || {};

    function readPanelState() {
        try {
            if (!window.localStorage) {
                return {};
            }
            var raw = window.localStorage.getItem(panelStateKey);
            if (!raw) {
                return {};
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function restorePanelFromState() {
        if (restoreAttempted || tokenRequired) {
            return;
        }
        var saved = readPanelState();
        if (!saved || saved.collapsed !== false) {
            return;
        }
        restoreAttempted = true;
        window.setTimeout(function () {
            var tabId = typeof saved.activeMainTab === 'string' ? saved.activeMainTab : '';
            var promise = tabId ? activateTab(tabId) : openPanel();
            Promise.resolve(promise).catch(function (error) {
                if (window.console && console.warn) {
                    console.warn('[WelinePanel] restore failed:', error);
                }
            });
        }, 0);
    }

    function resolveApiBase() {
        var base = apiBase || (window.__WELINE_PANEL_CONFIG__ && window.__WELINE_PANEL_CONFIG__.apiBase) || 'dev/tool/rest/v1';
        return String(base).replace(/^\/+|\/+$/g, '');
    }

    function panelUrl() {
        return '/' + resolveApiBase() + '/panel' + (requestId ? '?id=' + encodeURIComponent(requestId) : '');
    }

    function resolvedSessionUrl() {
        if (sessionUrl) {
            return sessionUrl;
        }
        return '/' + resolveApiBase() + '/panel/session';
    }

    function apiUrl(path, params) {
        var value = String(path || '').trim();
        if (/^https?:\/\//i.test(value)) {
            return appendQuery(value, params || {});
        }
        value = value.replace(/^\/+/, '');
        var base = resolveApiBase();
        if (value.indexOf(base + '/') === 0) {
            return appendQuery('/' + value, params || {});
        }
        return appendQuery('/' + base + '/' + value, params || {});
    }

    function appendQuery(url, params) {
        params = params || {};
        var query = Object.keys(params).filter(function (key) {
            return params[key] !== undefined && params[key] !== null && params[key] !== '';
        }).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(String(params[key]));
        }).join('&');
        if (!query) {
            return url;
        }
        return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
    }

    function apiFetch(path, options) {
        options = options || {};
        return requestAuthorization().then(function (allowed) {
            if (!allowed) {
                throw new Error('Weline Panel token required.');
            }
            var headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Weline-Panel': '1'
            }, options.headers || {});
            var body = options.body;
            var isFormData = typeof window.FormData !== 'undefined' && body instanceof window.FormData;
            if (body !== undefined && body !== null && typeof body !== 'string' && !isFormData) {
                headers['Content-Type'] = headers['Content-Type'] || 'application/json';
                body = JSON.stringify(body);
            }
            return fetch(apiUrl(path, options.params || {}), {
                method: options.method || (body !== undefined && body !== null ? 'POST' : 'GET'),
                credentials: 'same-origin',
                cache: options.cache || 'no-store',
                headers: headers,
                body: body
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    if (text) {
                        try {
                            payload = JSON.parse(text);
                        } catch (error) {
                            payload = { raw: text };
                        }
                    }
                    if (!response.ok || (payload && payload.success === false)) {
                        var message = payload && (payload.message || payload.error || payload.msg);
                        throw new Error(message || ('HTTP ' + response.status));
                    }
                    return payload || {};
                });
            });
        });
    }

    function isIgnoredTarget(target) {
        if (!target) {
            return false;
        }
        var tag = String(target.tagName || '').toLowerCase();
        if (tag === 'textarea' || tag === 'select') {
            return true;
        }
        if (tag === 'input') {
            return true;
        }
        return target.isContentEditable === true;
    }

    function ensureStyle() {
        if (document.querySelector('style[data-weline-panel-loader-style]')) {
            return;
        }
        var style = document.createElement('style');
        style.setAttribute('data-weline-panel-loader-style', '1');
        style.textContent = [
            '.weline-panel-token{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.36);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
            '.weline-panel-token[hidden]{display:none}',
            '.weline-panel-token__dialog{width:min(360px,calc(100vw - 32px));background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:8px;box-shadow:0 24px 60px rgba(15,23,42,.28);padding:18px}',
            '.weline-panel-token__title{font-size:15px;font-weight:700;color:#0f172a;margin:0 0 6px}',
            '.weline-panel-token__hint{font-size:12px;line-height:1.5;color:#64748b;margin:0 0 12px}',
            '.weline-panel-token__input{box-sizing:border-box;width:100%;height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;font-size:14px;outline:none}',
            '.weline-panel-token__input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.14)}',
            '.weline-panel-token__error{min-height:18px;margin:8px 0 0;font-size:12px;color:#b91c1c}',
            '.weline-panel-token__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}',
            '.weline-panel-token__btn{height:34px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;padding:0 12px;cursor:pointer;font-size:13px}',
            '.weline-panel-token__btn--primary{border-color:#0f172a;background:#0f172a;color:#fff}',
            '.weline-panel-token__btn[disabled]{opacity:.62;cursor:wait}'
        ].join('');
        (document.head || document.documentElement).appendChild(style);
    }

    function ensureTokenDialog() {
        ensureStyle();
        var root = document.getElementById('weline-panel-token-dialog');
        if (root) {
            return root;
        }
        root = document.createElement('div');
        root.id = 'weline-panel-token-dialog';
        root.className = 'weline-panel-token';
        root.hidden = true;
        root.innerHTML = [
            '<form class="weline-panel-token__dialog" data-weline-panel-token-form>',
            '<h2 class="weline-panel-token__title">Weline Panel Token</h2>',
            '<p class="weline-panel-token__hint">生产环境需要先验证 token，验证通过后才会加载面板。</p>',
            '<input class="weline-panel-token__input" type="password" autocomplete="off" name="token" aria-label="Weline panel token">',
            '<div class="weline-panel-token__error" data-weline-panel-token-error></div>',
            '<div class="weline-panel-token__actions">',
            '<button type="button" class="weline-panel-token__btn" data-weline-panel-token-cancel>取消</button>',
            '<button type="submit" class="weline-panel-token__btn weline-panel-token__btn--primary">打开</button>',
            '</div>',
            '</form>'
        ].join('');
        document.body.appendChild(root);
        root.querySelector('[data-weline-panel-token-cancel]').addEventListener('click', function () {
            root.hidden = true;
        });
        root.addEventListener('click', function (event) {
            if (event.target === root) {
                root.hidden = true;
            }
        });
        root.querySelector('form').addEventListener('submit', function (event) {
            event.preventDefault();
            submitToken(root);
        });
        return root;
    }

    function submitToken(root) {
        var input = root.querySelector('input[name="token"]');
        var errorNode = root.querySelector('[data-weline-panel-token-error]');
        var submit = root.querySelector('button[type="submit"]');
        var token = input ? String(input.value || '').trim() : '';
        if (!token) {
            if (errorNode) {
                errorNode.textContent = '请输入 token。';
            }
            return;
        }
        if (submit) {
            submit.disabled = true;
        }
        if (errorNode) {
            errorNode.textContent = '';
        }
        fetch(resolvedSessionUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Weline-Panel': '1'
            },
            body: JSON.stringify({ token: token })
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload = {};
                if (text) {
                    try {
                        payload = JSON.parse(text);
                    } catch (error) {
                        payload = {};
                    }
                }
                if (!response.ok || payload.success === false) {
                    throw new Error(payload.message || ('HTTP ' + response.status));
                }
                return payload;
            });
        }).then(function () {
            authorized = true;
            window.dispatchEvent(new CustomEvent('weline-panel:authorized'));
            if (input) {
                input.value = '';
            }
            root.hidden = true;
            return loadPanelShell().then(openLoadedPanel);
        }).catch(function (error) {
            if (errorNode) {
                errorNode.textContent = (error && error.message) || 'token 验证失败。';
            }
        }).finally(function () {
            if (submit) {
                submit.disabled = false;
            }
        });
    }

    function requestAuthorization() {
        if (!tokenRequired || authorized) {
            return Promise.resolve(true);
        }
        var root = ensureTokenDialog();
        root.hidden = false;
        setTimeout(function () {
            var input = root.querySelector('input[name="token"]');
            if (input && typeof input.focus === 'function') {
                input.focus();
            }
        }, 0);
        return Promise.resolve(false);
    }

    function executeScripts(scripts) {
        scripts.forEach(function (oldScript) {
            var nextScript = document.createElement('script');
            Array.prototype.slice.call(oldScript.attributes || []).forEach(function (attr) {
                if (attr.name !== 'src') {
                    nextScript.setAttribute(attr.name, attr.value);
                }
            });
            if (oldScript.src) {
                nextScript.async = false;
                nextScript.src = oldScript.src;
            } else {
                nextScript.text = oldScript.textContent || '';
            }
            document.body.appendChild(nextScript);
        });
    }

    function mountPanel(html) {
        if (!html || document.getElementById('dev-tool-panel')) {
            return;
        }
        var template = document.createElement('template');
        template.innerHTML = html;
        var scripts = Array.prototype.slice.call(template.content.querySelectorAll('script'));
        scripts.forEach(function (panelScript) {
            if (panelScript.parentNode) {
                panelScript.parentNode.removeChild(panelScript);
            }
        });
        document.body.appendChild(template.content);
        executeScripts(scripts);
        installBridge();
        flushPanelReady();
    }

    function loadPanelShell() {
        if (document.getElementById('dev-tool-panel')) {
            installBridge();
            return Promise.resolve(window.WelinePanel);
        }
        if (loadingPromise) {
            return loadingPromise;
        }
        loadingPromise = fetch(panelUrl(), {
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Weline-Panel': '1'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            mountPanel(html);
            return window.WelinePanel;
        }).catch(function (error) {
            loadingPromise = null;
            if (window.console && console.warn) {
                console.warn('[WelinePanel] load failed:', error);
            }
            throw error;
        });
        return loadingPromise;
    }

    function flushPanelReady() {
        var callbacks = panelReadyCallbacks.slice();
        panelReadyCallbacks = [];
        callbacks.forEach(function (callback) {
            try {
                callback();
            } catch (error) {
                if (window.console && console.warn) {
                    console.warn('[WelinePanel] ready callback failed:', error);
                }
            }
        });
    }

    function openLoadedPanel() {
        var panel = document.getElementById('dev-tool-panel');
        if (!panel) {
            return window.WelinePanel;
        }
        if (window.DevToolPanel && typeof window.DevToolPanel.toggle === 'function' && panel.classList.contains('collapsed')) {
            window.DevToolPanel.toggle();
        }
        return window.WelinePanel;
    }

    function openPanel() {
        return requestAuthorization().then(function (allowed) {
            if (!allowed) {
                return window.WelinePanel;
            }
            return loadPanelShell().then(openLoadedPanel);
        });
    }

    function activateTab(tabId) {
        tabId = String(tabId || '').trim();
        if (!tabId) {
            return openPanel();
        }
        return openPanel().then(function () {
            installBridge();
            var button = document.querySelector('.dev-tool-tab[data-tab="' + cssEscape(tabId) + '"]');
            if (window.DevToolPanel && typeof window.DevToolPanel.switchMainTab === 'function') {
                window.DevToolPanel.switchMainTab(button, tabId);
            }
            return window.WelinePanel;
        });
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/"/g, '\\"');
    }

    function normalizeTabReport(tabId, report) {
        var copy = report && typeof report === 'object' ? Object.assign({}, report) : { value: report };
        if (tabId === 'wls') {
            copy.contractVersion = 'weline-panel-wls/v1';
            copy.command = 'weline-panel:wls';
        } else if (tabId === 'seo') {
            copy.contractVersion = 'weline-panel-seo/v1';
            copy.command = 'weline-panel:seo';
        } else if (tabId === 'visitor') {
            copy.contractVersion = 'weline-panel-visitor/v1';
            copy.command = 'weline-panel:visitor';
        }
        return copy;
    }

    function selectedTabs(options) {
        var tabs = options && Array.isArray(options.tabs) ? options.tabs : [];
        if (!tabs.length) {
            tabs = Object.keys(window.__WELINE_PANEL_REPORT_PROVIDERS__ || {});
        }
        return tabs.map(function (tab) { return String(tab || '').trim(); }).filter(Boolean);
    }

    function buildReport(options) {
        options = options || {};
        return requestAuthorization().then(function (allowed) {
            if (!allowed) {
                throw new Error('Weline Panel token required.');
            }
            return loadPanelShell();
        }).then(function () {
            var tabs = selectedTabs(options);
            var providers = window.__WELINE_PANEL_REPORT_PROVIDERS__ || {};
            var payload = {
                contractVersion: 'weline-panel-console/v1',
                command: 'weline-panel-report',
                generatedAt: new Date().toISOString(),
                page: {
                    title: document.title || '',
                    url: window.location.href,
                    path: window.location.pathname,
                    requestId: requestId || (window.__WELINE_REQUEST_ID__ || '')
                },
                tabs: {},
                actions: []
            };
            return Promise.all(tabs.map(function (tabId) {
                var provider = providers[tabId];
                if (typeof provider !== 'function') {
                    return null;
                }
                return Promise.resolve(provider(options)).then(function (report) {
                    var normalized = normalizeTabReport(tabId, report);
                    payload.tabs[tabId] = normalized;
                    if (Array.isArray(normalized.actions)) {
                        payload.actions = payload.actions.concat(normalized.actions);
                    }
                    return normalized;
                });
            })).then(function () {
                return payload;
            });
        });
    }

    function publishReport(options) {
        return buildReport(options || {}).then(function (report) {
            window.__WELINE_PANEL_REPORT__ = report;
            var node = document.getElementById('weline-panel-report');
            if (!node) {
                node = document.createElement('script');
                node.id = 'weline-panel-report';
                node.type = 'application/json';
                (document.head || document.documentElement).appendChild(node);
            }
            node.textContent = JSON.stringify(report);
            window.dispatchEvent(new CustomEvent('weline-panel:report', { detail: report }));
            return report;
        });
    }

    function registerReportProvider(tabId, provider) {
        tabId = String(tabId || '').trim();
        if (!tabId || typeof provider !== 'function') {
            return;
        }
        window.__WELINE_PANEL_REPORT_PROVIDERS__[tabId] = provider;
    }

    function registerTab(manifest) {
        if (!manifest || !manifest.id) {
            return;
        }
        var id = String(manifest.id);
        window.__WELINE_PANEL_TABS__[id] = manifest;
        if (typeof manifest.report === 'function') {
            registerReportProvider(id, function (options) {
                return manifest.report(createTabContext(id), options || {});
            });
        }
        if (!document.getElementById('dev-tool-panel')) {
            return;
        }
        ensureDynamicTab(manifest);
    }

    function createTabContext(id) {
        return {
            id: id,
            content: document.getElementById('dev-tool-content'),
            searchArea: document.getElementById('dev-tool-search-area-' + id),
            open: openPanel,
            activateTab: activateTab,
            publish: publishReport,
            apiUrl: apiUrl,
            apiFetch: apiFetch
        };
    }

    function tabOrderValue(manifest, button) {
        var raw = manifest && manifest.order;
        if (raw === undefined && button) {
            raw = button.getAttribute('data-order');
        }
        var order = Number(raw);
        if (Number.isFinite(order)) {
            return order;
        }
        var tabId = button ? button.getAttribute('data-tab') : (manifest && manifest.id);
        var builtInOrder = {
            performance: 10,
            framework: 20,
            docs: 30
        };
        return builtInOrder[tabId] || 1000;
    }

    function insertOrderedTab(tabs, button, order) {
        button.setAttribute('data-order', String(order));
        var before = null;
        Array.prototype.slice.call(tabs.querySelectorAll('.dev-tool-tab')).some(function (item) {
            if (item === button) {
                return false;
            }
            if (tabOrderValue(null, item) > order) {
                before = item;
                return true;
            }
            return false;
        });
        tabs.insertBefore(button, before);
    }

    function ensureDynamicTab(manifest) {
        var id = String(manifest.id);
        var tabs = document.querySelector('.dev-tool-tabs');
        var order = tabOrderValue(manifest, null);
        if (tabs) {
            var button = tabs.querySelector('.dev-tool-tab[data-tab="' + cssEscape(id) + '"]');
            if (!button) {
                button = document.createElement('button');
            }
            button.type = 'button';
            button.className = 'dev-tool-tab';
            button.setAttribute('data-tab', id);
            button.setAttribute('data-dev-tool-action', 'switch-main-tab');
            button.textContent = manifest.title || id;
            insertOrderedTab(tabs, button, order);
        }
        var searchWrap = document.querySelector('.dev-tool-search-area');
        if (searchWrap && !document.getElementById('dev-tool-search-area-' + id)) {
            var searchArea = document.createElement('div');
            searchArea.id = 'dev-tool-search-area-' + id;
            searchArea.style.display = 'none';
            searchArea.innerHTML = '<div style="padding:8px;font-size:12px;color:#64748b;">' + escapeHtml(manifest.title || id) + '</div>';
            searchWrap.appendChild(searchArea);
        }
        var loader = function () {
            if (typeof manifest.activate === 'function') {
                return manifest.activate(createTabContext(id));
            }
            var content = document.getElementById('dev-tool-content');
            if (content) {
                content.innerHTML = '<div class="dev-tool-empty">' + escapeHtml(manifest.title || id) + '</div>';
            }
            return null;
        };
        if (typeof window.DevToolPanelRegisterExtensionTab === 'function') {
            window.DevToolPanelRegisterExtensionTab(id, loader);
        } else if (window.DevToolPanel && typeof window.DevToolPanel.registerExtensionTab === 'function') {
            window.DevToolPanel.registerExtensionTab(id, loader);
        }
        var saved = readPanelState();
        if (saved && saved.collapsed === false && saved.activeMainTab === id && window.DevToolPanel && typeof window.DevToolPanel.switchMainTab === 'function') {
            window.setTimeout(function () {
                var button = document.querySelector('.dev-tool-tab[data-tab="' + cssEscape(id) + '"]');
                window.DevToolPanel.switchMainTab(button, id, false);
            }, 0);
        }
    }

    function drainQueuedTabs() {
        var queue = window.__WELINE_PANEL_TAB_QUEUE__ || [];
        window.__WELINE_PANEL_TAB_QUEUE__ = [];
        queue.forEach(registerTab);
        Object.keys(window.__WELINE_PANEL_TABS__ || {}).forEach(function (id) {
            registerTab(window.__WELINE_PANEL_TABS__[id]);
        });
    }

    function installBridge() {
        var api = window.WelinePanel || {};
        api.__isWelinePanelBridge = true;
        api.open = openPanel;
        api.activateTab = activateTab;
        api.report = buildReport;
        api.publish = publishReport;
        api.apiUrl = apiUrl;
        api.apiFetch = apiFetch;
        api.registerTab = registerTab;
        api.registerReportProvider = registerReportProvider;
        api.isAuthorized = function () {
            return authorized;
        };
        api.requiresToken = function () {
            return tokenRequired;
        };
        api.requestAuthorization = requestAuthorization;
        api.whenReady = function (callback) {
            if (document.getElementById('dev-tool-panel')) {
                callback();
                return;
            }
            panelReadyCallbacks.push(callback);
        };
        window.WelinePanel = api;
        if (window.DevToolPanel) {
            drainQueuedTabs();
        }
        return api;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function onKeydown(event) {
        if (event.ctrlKey || event.metaKey || event.altKey || event.isComposing || isIgnoredTarget(event.target)) {
            return;
        }
        if (!event.key || event.key.length !== 1) {
            return;
        }
        keyBuffer = (keyBuffer + event.key.toLowerCase()).slice(-command.length);
        if (keyBuffer !== command) {
            return;
        }
        keyBuffer = '';
        openPanel();
    }

    installBridge();
    document.addEventListener('keydown', onKeydown, true);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restorePanelFromState, { once: true });
    } else {
        restorePanelFromState();
    }
})(window, document);
