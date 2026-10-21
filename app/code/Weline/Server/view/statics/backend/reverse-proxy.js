/**
 * Weline_Server 反向代理规则列表页（后台）。
 * 由 view/statics/backend/weline.modules.js 登记，页面通过 data-weline-load="serverReverseProxy" 按需加载。
 */
(function (global) {
    'use strict';

    var CONFIG_ID = 'reverse-proxy-config';

    function readConfig() {
        var el = document.getElementById(CONFIG_ID);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (error) {
            console.error('Failed to parse reverse proxy config:', error);
            return null;
        }
    }

    function init(root) {
        if (!root || root.getAttribute('data-reverse-proxy-ready') === '1') {
            return;
        }
        root.setAttribute('data-reverse-proxy-ready', '1');

        var config = readConfig();
        if (!config) {
            return;
        }
        var urls = config.urls || {};
        var messages = config.messages || {};

        function toast(type, message) {
            var text = String(message || messages.operationFailed);
            var ui = global.Weline && global.Weline.UI;
            if (ui && ui.toast && typeof ui.toast[type] === 'function') {
                ui.toast[type](text);
                return;
            }
            if (global.console) {
                console[type === 'error' ? 'error' : 'log'](text);
            }
        }

        function confirmAction(message) {
            var ui = global.Weline && global.Weline.UI;
            if (ui && ui.dialog && typeof ui.dialog.confirm === 'function') {
                return ui.dialog.confirm(message, {
                    title: messages.operationFailed,
                    confirmLabel: messages.operationFailed,
                    cancelLabel: messages.operationFailed
                });
            }
            toast('error', messages.confirmUnavailable);
            return Promise.resolve(false);
        }

        async function request(url, body) {
            try {
                return await global.Weline.adminRequest('server', url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body || {}),
                    credentials: 'same-origin'
                });
            } catch (error) {
                toast('error', messages.requestFailed + ': ' + (error && error.message ? error.message : error));
                return { success: false };
            }
        }

        async function applyConfig() {
            var confirmed = await confirmAction(messages.applyConfirm);
            if (!confirmed) {
                return;
            }
            var data = await request(urls.apply, {});
            if (data && data.success) {
                toast('success', data.message);
                global.location.reload();
            } else if (data) {
                toast('error', messages.applyFailed + ': ' + (data.message || messages.operationFailed));
            }
        }

        async function toggleStatus(proxyId, status) {
            var data = await request(urls.toggleStatus, { proxy_id: proxyId, status: status });
            if (data && data.success) {
                global.location.reload();
            } else if (data) {
                toast('error', messages.operationFailed + ': ' + (data.message || messages.operationFailed));
            }
        }

        async function deleteProxy(proxyId) {
            var confirmed = await confirmAction(messages.deleteConfirm);
            if (!confirmed) {
                return;
            }
            var data = await request(urls.delete, { proxy_id: proxyId });
            if (data && data.success) {
                global.location.reload();
            } else if (data) {
                toast('error', messages.deleteFailed + ': ' + (data.message || messages.operationFailed));
            }
        }

        async function testConnection(host, port, ssl) {
            var data = await request(urls.testConnection, { backend_host: host, backend_port: port, backend_ssl: ssl });
            if (data && data.success) {
                toast('success', messages.connectionSuccess + ' (' + data.latency_ms + 'ms)');
            } else if (data) {
                toast('error', messages.connectionFailed + ': ' + (data.message || messages.operationFailed));
            }
        }

        root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-reverse-proxy-action]');
            if (!trigger) {
                return;
            }
            var action = trigger.getAttribute('data-reverse-proxy-action');
            if (action === 'apply') {
                applyConfig();
                return;
            }
            if (action === 'reload-page') {
                global.location.reload();
                return;
            }
            if (action === 'test-connection') {
                testConnection(
                    trigger.getAttribute('data-backend-host') || '',
                    Number(trigger.getAttribute('data-backend-port') || 0),
                    trigger.getAttribute('data-backend-ssl') === '1'
                );
                return;
            }
            if (action === 'toggle-status') {
                toggleStatus(Number(trigger.getAttribute('data-proxy-id') || 0), trigger.getAttribute('data-status') || '');
                return;
            }
            if (action === 'delete') {
                deleteProxy(Number(trigger.getAttribute('data-proxy-id') || 0));
            }
        });
    }


    function initEdit(root) {
        if (!root || root.getAttribute('data-reverse-proxy-edit-ready') === '1') {
            return;
        }
        root.setAttribute('data-reverse-proxy-edit-ready', '1');

        var el = document.getElementById('reverse-proxy-edit-config');
        if (!el) {
            return;
        }
        var config;
        try {
            config = JSON.parse(el.textContent || '{}');
        } catch (error) {
            console.error('Failed to parse reverse proxy edit config:', error);
            return;
        }
        var urls = config.urls || {};
        var messages = config.messages || {};

        function toast(type, message) {
            var text = String(message || messages.operationFailed);
            var ui = global.Weline && global.Weline.UI;
            if (ui && ui.toast && typeof ui.toast[type] === 'function') {
                ui.toast[type](text);
                return;
            }
            if (global.console) {
                console[type === 'error' ? 'error' : 'log'](text);
            }
        }

        async function request(url, body) {
            try {
                return await global.Weline.adminRequest('server', url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body || {}),
                    credentials: 'same-origin'
                });
            } catch (error) {
                toast('error', messages.requestFailed + ': ' + (error && error.message ? error.message : error));
                return { success: false };
            }
        }

        async function saveProxy() {
            var form = document.getElementById('proxyForm');
            if (!form) {
                return;
            }
            var formData = new FormData(form);
            var data = {};
            formData.forEach(function (value, key) {
                if (key === 'backend_ssl') {
                    var sslEl = document.getElementById('backend_ssl');
                    data[key] = sslEl && sslEl.checked ? 1 : 0;
                } else {
                    data[key] = value;
                }
            });
            var result = await request(urls.save, data);
            if (result && result.success) {
                toast('success', result.message);
                global.location.href = urls.index;
            } else if (result) {
                toast('error', messages.saveFailed + ': ' + (result.message || messages.operationFailed));
            }
        }

        async function testConnection() {
            var hostEl = document.getElementById('backend_host');
            var portEl = document.getElementById('backend_port');
            var sslEl = document.getElementById('backend_ssl');
            var host = hostEl ? hostEl.value : '';
            var port = portEl ? portEl.value : '';
            if (!host || !port) {
                toast('error', messages.backendRequired);
                return;
            }
            var result = await request(urls.testConnection, {
                backend_host: host,
                backend_port: port,
                backend_ssl: sslEl ? sslEl.checked : false
            });
            if (result && result.success) {
                toast('success', messages.connectionSuccess + ' (' + result.latency_ms + 'ms)');
            } else if (result) {
                toast('error', messages.connectionFailed + ': ' + (result.message || messages.operationFailed));
            }
        }

        root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-reverse-proxy-edit-action]');
            if (!trigger) {
                return;
            }
            var action = trigger.getAttribute('data-reverse-proxy-edit-action');
            if (action === 'save') {
                saveProxy();
                return;
            }
            if (action === 'test-connection') {
                testConnection();
            }
        });
    }

    function whenWelineReady(callback) {
            var tries = 0;
            (function tick() {
                if (global.Weline && typeof global.Weline.adminRequest === 'function') {
                    callback();
                    return;
                }
                tries += 1;
                if (tries > 120) {
                    console.error('Weline admin API is unavailable; backend actions will not work.');
                    return;
                }
                setTimeout(tick, 50);
            })();
        }

    function boot() {
        document.querySelectorAll('[data-reverse-proxy-page="1"]').forEach(init);
        document.querySelectorAll('[data-reverse-proxy-edit="1"]').forEach(initEdit);
    }

    global.WelineServerReverseProxy = { init: init, initEdit: initEdit, boot: boot };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { whenWelineReady(boot); });
    } else {
        whenWelineReady(boot);
    }
})(typeof window !== 'undefined' ? window : this);
