(function (window, document) {
    'use strict';

    if (window.__WELINE_DEV_TOOL_PANEL_LOADER_READY__) {
        return;
    }
    window.__WELINE_DEV_TOOL_PANEL_LOADER_READY__ = true;

    var script = document.currentScript;
    var apiBase = script ? (script.getAttribute('data-api-base') || '') : '';
    var requestId = script ? (script.getAttribute('data-request-id') || '') : '';
    var loading = false;

    function ensureStyle() {
        if (document.querySelector('style[data-weline-dev-tool-loader-style]')) {
            return;
        }
        var style = document.createElement('style');
        style.setAttribute('data-weline-dev-tool-loader-style', '1');
        style.textContent = [
            '.weline-dev-tool-loader{position:fixed;right:16px;bottom:16px;z-index:2147483000}',
            '.weline-dev-tool-loader__button{min-width:44px;height:34px;border:1px solid rgba(15,23,42,.18);border-radius:999px;background:#0f172a;color:#fff;box-shadow:0 10px 28px rgba(15,23,42,.22);font:700 12px/1.1 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.04em;cursor:pointer}',
            '.weline-dev-tool-loader__button:hover{background:#1e293b}',
            '.weline-dev-tool-loader__button[aria-busy="true"]{opacity:.72;cursor:wait}'
        ].join('');
        document.head.appendChild(style);
    }

    function ensureLoader() {
        var loader = document.getElementById('dev-tool-panel-loader');
        if (loader) {
            return loader;
        }
        ensureStyle();
        loader = document.createElement('div');
        loader.id = 'dev-tool-panel-loader';
        loader.className = 'weline-dev-tool-loader';
        loader.setAttribute('data-loaded', '0');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'weline-dev-tool-loader__button';
        button.setAttribute('aria-label', 'Open Weline dev panel');
        button.textContent = 'WLS';
        button.addEventListener('click', loadPanel);

        loader.appendChild(button);
        document.body.appendChild(loader);
        return loader;
    }

    function buildPanelUrl() {
        var base = apiBase || (window.__WELINE_DEV_TOOL__ && window.__WELINE_DEV_TOOL__.apiBase) || 'dev/tool/rest/v1';
        base = String(base).replace(/^\/+|\/+$/g, '');
        return '/' + base + '/panel' + (requestId ? '?id=' + encodeURIComponent(requestId) : '');
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
        var loader = document.getElementById('dev-tool-panel-loader');
        if (loader) {
            loader.setAttribute('data-loaded', '1');
            loader.hidden = true;
        }
        executeScripts(scripts);
    }

    function setButtonBusy(button, busy) {
        if (!button) {
            return;
        }
        if (busy) {
            button.setAttribute('aria-busy', 'true');
            button.textContent = '...';
            return;
        }
        button.removeAttribute('aria-busy');
        button.textContent = 'WLS';
    }

    function loadPanel() {
        if (loading || document.getElementById('dev-tool-panel')) {
            return;
        }
        var loader = ensureLoader();
        var button = loader ? loader.querySelector('button') : null;
        loading = true;
        setButtonBusy(button, true);
        fetch(buildPanelUrl(), {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(mountPanel).catch(function (error) {
            if (window.console && console.warn) {
                console.warn('[DevToolPanel] lazy load failed:', error);
            }
        }).finally(function () {
            loading = false;
            if (!document.getElementById('dev-tool-panel')) {
                setButtonBusy(button, false);
            }
        });
    }

    if (document.body) {
        ensureLoader();
    }
})(window, document);
