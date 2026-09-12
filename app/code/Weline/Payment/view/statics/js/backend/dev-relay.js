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

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderWorkerStatus(status) {
        var box = document.getElementById('dev-relay-worker-status');
        if (!box) {
            return;
        }
        status = status || {};
        var running = !!status.running;
        var online = String(status.online_base_url || '').trim();
        var session = String(status.session_code || '').trim();
        var pid = Number(status.pid || 0) || 0;
        var lastError = String(status.last_error || '').trim();
        var lastEvent = String(status.last_event_code || '').trim();
        var headline = running ? '运行中' : '已停止';
        var onlineLabel = online || '未连接';
        var sessionLabel = session || '无';
        var pidLabel = (running && pid > 0) ? String(pid) : '无';
        var errorLabel = lastError || '无';
        var eventLabel = lastEvent || '无';

        box.setAttribute('data-tone', running ? 'success' : 'muted');
        // 运行态由卡片头徽章表达；摘要区只展示连接细节，避免「已停止 已停止」。
        box.innerHTML = ''
            + '<div class="w-stack" style="--w-gap:var(--weline-space-2);" data-testid="payment-dev-relay-worker-summary">'
            + '<div class="w-cluster" data-align="center" data-gap="2">'
            + '<strong class="w-text" style="--w-mb:0;font-size:var(--weline-text-lg, 1.125rem);" data-testid="payment-dev-relay-worker-summary-badge">连接详情</strong>'
            + '</div>'
            + '<div class="w-stack" style="--w-gap:var(--weline-space-1);">'
            + '<div class="w-text" style="--w-mb:0;">线上站点：<code>' + escapeHtml(onlineLabel) + '</code></div>'
            + '<div class="w-text" style="--w-mb:0;">会话：<code>' + escapeHtml(sessionLabel) + '</code></div>'
            + '<div class="w-text" style="--w-mb:0;">进程：<code>' + escapeHtml(pidLabel) + '</code></div>'
            + '<div class="w-text" style="--w-mb:0;">最近事件：<code>' + escapeHtml(eventLabel) + '</code></div>'
            + '<div class="w-text" style="--w-mb:0;" data-tone="' + (lastError ? 'danger' : 'muted') + '">最近错误：'
            + escapeHtml(errorLabel) + '</div>'
            + '</div>'
            + '<details style="--w-mt:var(--weline-space-1);">'
            + '<summary class="w-text" data-tone="muted" style="cursor:pointer;" data-testid="payment-dev-relay-worker-tech">技术详情（JSON）</summary>'
            + '<pre class="w-text" style="--w-mb:0;--w-mt:var(--weline-space-2);white-space:pre-wrap;word-break:break-word;font-family:var(--weline-font-mono, ui-monospace, monospace);font-size:var(--weline-text-sm, 0.875rem);">'
            + escapeHtml(JSON.stringify(status, null, 2))
            + '</pre></details></div>';

        var badge = document.getElementById('dev-relay-worker-running-badge');
        if (badge) {
            badge.setAttribute('data-tone', running ? 'success' : 'muted');
            badge.textContent = headline;
        }
    }

    window.WelinePaymentDevRelay = {
        initIndex: function (config) {
            var logEl = document.getElementById('dev-relay-log');
            var state = { session: null };

            function appendLog(message) {
                log(logEl, message);
            }

            function setFieldInvalid(el, invalid) {
                if (!el) {
                    return;
                }
                if (invalid) {
                    el.setAttribute('aria-invalid', 'true');
                } else {
                    el.removeAttribute('aria-invalid');
                }
            }

            function showLocalFeedback(message, tone) {
                var box = document.getElementById('dev-relay-local-feedback');
                if (!box) {
                    return;
                }
                var text = String(message || '').trim();
                if (!text) {
                    box.hidden = true;
                    box.textContent = '';
                    box.removeAttribute('data-tone');
                    return;
                }
                box.hidden = false;
                box.setAttribute('data-tone', tone || 'danger');
                box.setAttribute('role', tone === 'success' ? 'status' : 'alert');
                box.textContent = text;
                try {
                    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } catch (e) {
                    box.scrollIntoView(true);
                }
            }

            function notifyLocal(message, tone) {
                appendLog(message);
                showLocalFeedback(message, tone || 'danger');
            }

            function applyFeatureEnabled(enabled) {
                var onlineGate = document.querySelector('[data-role="online-actions-gate"]');
                if (onlineGate) {
                    if (enabled) {
                        onlineGate.classList.remove('is-disabled');
                        onlineGate.style.opacity = '';
                        onlineGate.style.pointerEvents = '';
                    } else {
                        onlineGate.classList.add('is-disabled');
                        onlineGate.style.opacity = '0.55';
                        onlineGate.style.pointerEvents = 'none';
                    }
                }
                // 本机开启中继始终可点；仅线上面板动作随开关禁用。
                ['dev-relay-start-online', 'dev-relay-bind-local'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) {
                        el.disabled = !enabled;
                    }
                });
                var startLocal = document.getElementById('dev-relay-worker-start');
                if (startLocal) {
                    startLocal.disabled = false;
                }
                var badge = document.getElementById('dev-relay-settings-hint');
                if (badge) {
                    badge.setAttribute('data-tone', enabled ? 'success' : 'warning');
                    badge.textContent = enabled ? '已启用' : '未启用';
                }
                var hint = document.getElementById('dev-relay-settings-hint-text');
                if (hint) {
                    hint.textContent = enabled ? '当前已启用，可操作中继' : '当前未启用：保存开启后再操作中继（本机点开启也会自动启用）';
                }
                var localEnableHint = document.querySelector('[data-testid="payment-dev-relay-local-enable-hint"]');
                if (localEnableHint) {
                    localEnableHint.hidden = !!enabled;
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
                    var onlineEl = document.getElementById('dev-relay-online-base-url');
                    var tokenEl = document.getElementById('dev-relay-user-token');
                    var tokenHint = document.getElementById('dev-relay-token-hint');
                    var online = onlineEl ? String(onlineEl.value || '').trim() : '';
                    var token = tokenEl ? String(tokenEl.value || '').trim() : '';
                    setFieldInvalid(onlineEl, false);
                    setFieldInvalid(tokenEl, false);
                    if (!online) {
                        setFieldInvalid(onlineEl, true);
                        if (onlineEl) {
                            onlineEl.focus();
                        }
                        notifyLocal('请填写线上站点地址（完整 http(s) URL）。', 'danger');
                        return;
                    }
                    if (!token && !config.credentialsReady) {
                        setFieldInvalid(tokenEl, true);
                        if (tokenHint) {
                            tokenHint.textContent = '首次开启必填：到线上后台复制当前用户 API Token。';
                        }
                        if (tokenEl) {
                            tokenEl.focus();
                        }
                        notifyLocal('请填写线上用户 API Token（首次开启必填；成功后本机会记住）。', 'danger');
                        return;
                    }
                    workerStart.disabled = true;
                    showLocalFeedback('正在开启本机静默中继…', 'info');
                    postJson(config.urls.workerStart, {
                        online_base_url: online,
                        user_token: token
                    }).then(function (result) {
                        if (!result.success) {
                            notifyLocal(result.message || 'Worker start failed', 'danger');
                            return;
                        }
                        config.credentialsReady = true;
                        applyFeatureEnabled(true);
                        var enabledEl = document.getElementById('dev-relay-enabled');
                        if (enabledEl) {
                            enabledEl.checked = true;
                        }
                        notifyLocal('静默中继已开启。', 'success');
                        renderWorkerStatus(result.status);
                        if (tokenHint) {
                            tokenHint.textContent = '本机已记住 Token：可留空直接开启；填写则覆盖。';
                        }
                        if (tokenEl) {
                            tokenEl.placeholder = '已记住凭证，可留空';
                            tokenEl.value = '';
                            setFieldInvalid(tokenEl, false);
                        }
                    }).catch(function (error) {
                        notifyLocal(String(error), 'danger');
                    }).finally(function () {
                        workerStart.disabled = false;
                    });
                });
            }

            var workerStop = document.getElementById('dev-relay-worker-stop');
            if (workerStop) {
                workerStop.addEventListener('click', function () {
                    postJson(config.urls.workerStop, {}).then(function (result) {
                        if (!result.success) {
                            notifyLocal(result.message || 'Worker stop failed', 'danger');
                            return;
                        }
                        notifyLocal('静默中继已关闭。', 'muted');
                        renderWorkerStatus(result.status);
                    }).catch(function (error) {
                        notifyLocal(String(error), 'danger');
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
                            notifyLocal('Worker status refreshed.', 'muted');
                        })
                        .catch(function (error) {
                            notifyLocal(String(error), 'danger');
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
