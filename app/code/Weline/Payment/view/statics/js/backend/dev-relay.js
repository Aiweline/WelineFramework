(function (window) {
    'use strict';

    function log(el, message) {
        if (!el) {
            return;
        }
        el.textContent += '[' + new Date().toISOString() + '] ' + message + '\n';
        el.scrollTop = el.scrollHeight;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json();
        });
    }

    function copyValue(id, appendLog, label) {
        var input = document.getElementById(id);
        if (!input || !input.value) {
            appendLog('Empty: ' + label);
            return;
        }
        navigator.clipboard.writeText(input.value).then(function () {
            appendLog('Copied ' + label);
        }).catch(function () {
            input.select();
            document.execCommand('copy');
            appendLog('Copied ' + label);
        });
    }

    function renderWorkerStatus(status) {
        var box = document.getElementById('dev-relay-worker-status');
        if (!box) {
            return;
        }
        box.innerHTML = '<pre class="mb-0 small">' + JSON.stringify(status || {}, null, 2) + '</pre>';
    }

    window.WelinePaymentDevRelay = {
        initIndex: function (config) {
            var logEl = document.getElementById('dev-relay-log');
            var state = { session: null };

            function appendLog(message) {
                log(logEl, message);
            }

            function applyFeatureEnabled(enabled) {
                var actions = document.querySelector('[data-role="relay-actions"]');
                if (actions) {
                    if (enabled) {
                        actions.classList.remove('opacity-50');
                    } else {
                        actions.classList.add('opacity-50');
                    }
                }
                ['dev-relay-worker-start', 'dev-relay-start-online', 'dev-relay-bind-local'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) {
                        el.disabled = !enabled;
                    }
                });
                var hint = document.getElementById('dev-relay-settings-hint');
                if (hint) {
                    hint.textContent = enabled ? '当前已启用' : '当前未启用：保存开启后再操作中继';
                }
            }

            var saveSettings = document.getElementById('dev-relay-save-settings');
            if (saveSettings && config.urls.saveSettings) {
                saveSettings.addEventListener('click', function () {
                    var enabledEl = document.getElementById('dev-relay-enabled');
                    var allowEl = document.getElementById('dev-relay-allow-on-production');
                    var modeEl = document.getElementById('dev-relay-settings-outbound-mode');
                    var onlineEl = document.getElementById('dev-relay-settings-online-base-url')
                        || document.getElementById('dev-relay-online-base-url');
                    postJson(config.urls.saveSettings, {
                        enabled: !!(enabledEl && enabledEl.checked),
                        allow_on_production: !!(allowEl && allowEl.checked),
                        outbound_mode: modeEl ? modeEl.value : 'local_direct',
                        online_base_url: onlineEl ? onlineEl.value : ''
                    }).then(function (result) {
                        if (!result.success) {
                            appendLog(result.message || 'Save settings failed');
                            return;
                        }
                        var on = !!(result.feature_enabled || (result.config && result.config.enabled));
                        applyFeatureEnabled(on);
                        if (onlineEl && result.config && result.config.online_base_url) {
                            var workerUrl = document.getElementById('dev-relay-online-base-url');
                            if (workerUrl && !workerUrl.value) {
                                workerUrl.value = result.config.online_base_url;
                            }
                        }
                        appendLog('Settings saved. enabled=' + on);
                    }).catch(function (error) {
                        appendLog(String(error));
                    });
                });
            }

            var copyBase = document.getElementById('dev-relay-copy-base-url');
            if (copyBase) {
                copyBase.addEventListener('click', function () {
                    copyValue('dev-relay-website-base-url', appendLog, 'website base URL');
                });
            }
            var copyUserToken = document.getElementById('dev-relay-copy-user-token');
            if (copyUserToken) {
                copyUserToken.addEventListener('click', function () {
                    copyValue('dev-relay-user-api-token', appendLog, 'user API token');
                });
            }

            var copyBtn = document.getElementById('dev-relay-copy-inbound');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    copyValue('dev-relay-local-copy-url', appendLog, 'local inbound URL');
                });
            }

            var workerStart = document.getElementById('dev-relay-worker-start');
            if (workerStart) {
                workerStart.addEventListener('click', function () {
                    postJson(config.urls.workerStart, {
                        online_base_url: document.getElementById('dev-relay-online-base-url').value,
                        user_token: document.getElementById('dev-relay-user-token').value
                    }).then(function (result) {
                        if (!result.success) {
                            appendLog(result.message || 'Worker start failed');
                            return;
                        }
                        appendLog('Silent worker started.');
                        renderWorkerStatus(result.status);
                    }).catch(function (error) {
                        appendLog(String(error));
                    });
                });
            }

            var workerStop = document.getElementById('dev-relay-worker-stop');
            if (workerStop) {
                workerStop.addEventListener('click', function () {
                    postJson(config.urls.workerStop, {}).then(function (result) {
                        if (!result.success) {
                            appendLog(result.message || 'Worker stop failed');
                            return;
                        }
                        appendLog('Silent worker stopped.');
                        renderWorkerStatus(result.status);
                    }).catch(function (error) {
                        appendLog(String(error));
                    });
                });
            }

            var workerRefresh = document.getElementById('dev-relay-worker-refresh');
            if (workerRefresh) {
                workerRefresh.addEventListener('click', function () {
                    fetch(config.urls.workerStatus, { credentials: 'same-origin' })
                        .then(function (response) { return response.json(); })
                        .then(function (result) {
                            renderWorkerStatus(result.status || {});
                            appendLog('Worker status refreshed.');
                        })
                        .catch(function (error) {
                            appendLog(String(error));
                        });
                });
            }

            var bindBtn = document.getElementById('dev-relay-bind-local');
            if (bindBtn) {
                bindBtn.addEventListener('click', function () {
                    postJson(config.urls.bind, {
                        session_code: document.getElementById('dev-relay-session-code').value,
                        token: document.getElementById('dev-relay-token').value,
                        online_stream_url: document.getElementById('dev-relay-online-stream-url').value
                    }).then(function (result) {
                        if (!result.success) {
                            appendLog(result.message || 'Bind failed');
                            return;
                        }
                        state.session = result.session;
                        document.getElementById('dev-relay-local-copy-url').value = result.session.local_inbound_url || '';
                        appendLog('Local session bound.');
                    }).catch(function (error) {
                        appendLog(String(error));
                    });
                });
            }

            var startBtn = document.getElementById('dev-relay-start-online');
            if (startBtn) {
                startBtn.addEventListener('click', function () {
                    postJson(config.urls.start, {
                        local_inbound_url: '',
                        outbound_mode: document.getElementById('dev-relay-outbound-mode').value
                    }).then(function (result) {
                        if (!result.success) {
                            appendLog(result.message || 'Start failed');
                            return;
                        }
                        state.session = result.session;
                        appendLog('Online relay session created (local workers may also pair via Token).');
                        appendLog('session_code=' + (result.session.session_code || ''));
                    }).catch(function (error) {
                        appendLog(String(error));
                    });
                });
            }

            var openConsoleBtn = document.getElementById('dev-relay-open-console');
            if (openConsoleBtn) {
                openConsoleBtn.addEventListener('click', function () {
                    var sessionCode = document.getElementById('dev-relay-session-code').value;
                    var token = document.getElementById('dev-relay-token').value;
                    var streamUrl = document.getElementById('dev-relay-online-stream-url').value;
                    var localInbound = document.getElementById('dev-relay-local-copy-url').value;
                    if (!streamUrl) {
                        appendLog('Provide online SSE stream URL.');
                        return;
                    }
                    var consoleUrl = config.urls.console
                        + '?session_code=' + encodeURIComponent(sessionCode)
                        + '&token=' + encodeURIComponent(token)
                        + '&mode=local'
                        + '&local_inbound_url=' + encodeURIComponent(localInbound)
                        + '&stream_url=' + encodeURIComponent(streamUrl);
                    window.open(consoleUrl, 'paymentDevRelayConsole', 'width=960,height=720');
                });
            }
        }
    };
})(window);
