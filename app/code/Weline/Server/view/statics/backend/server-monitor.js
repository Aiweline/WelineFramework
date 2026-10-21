/**
 * Weline_Server 服务器监控页（后台）。
 * 由 view/statics/backend/weline.modules.js 登记，页面通过 data-weline-load="serverMonitor" 按需加载。
 * 保留原页面的 30 秒自动刷新、手动刷新、压测、清理日志与 Fiber 展开逻辑。
 */
(function (global) {
    'use strict';

    var CONFIG_ID = 'server-monitor-config';
    var REFRESH_INTERVAL_MS = 30000;

    function readConfig() {
        var el = document.getElementById(CONFIG_ID);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (error) {
            console.error('Failed to parse server monitor config:', error);
            return null;
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : value;
        return div.innerHTML;
    }

    function formatNumber(num) {
        return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatSeconds(seconds) {
        var total = Math.max(0, Math.floor(Number(seconds || 0)));
        var h = Math.floor(total / 3600);
        var m = Math.floor((total % 3600) / 60);
        var s = total % 60;
        if (h > 0) {
            return h + 'h ' + m + 'm ' + s + 's';
        }
        if (m > 0) {
            return m + 'm ' + s + 's';
        }
        return s + 's';
    }

    function formatHeartbeat(ts) {
        var value = Number(ts || 0);
        if (!value) {
            return '-';
        }
        var delta = Math.floor(Date.now() / 1000) - value;
        return delta <= 0 ? 'now' : delta + 's ago';
    }

    function formatBytes(bytes) {
        var value = Number(bytes || 0);
        if (!value) {
            return '0 B';
        }
        if (value < 1024) {
            return value + ' B';
        }
        if (value < 1024 * 1024) {
            return (value / 1024).toFixed(1) + ' KB';
        }
        if (value < 1024 * 1024 * 1024) {
            return (value / (1024 * 1024)).toFixed(1) + ' MB';
        }
        return (value / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    function getRoleBadge(role) {
        var lower = String(role || '').toLowerCase();
        if (lower === 'master') {
            return { tone: 'muted', label: 'MASTER' };
        }
        if (lower === 'dispatcher') {
            return { tone: 'info', label: 'DISPATCHER' };
        }
        if (lower === 'worker') {
            return { tone: 'success', label: 'WORKER' };
        }
        return { tone: 'muted', label: role || 'UNKNOWN' };
    }

    function getStateBadge(state) {
        var lower = String(state || '').toLowerCase();
        if (lower === 'running') {
            return { tone: 'success', label: 'running' };
        }
        if (lower === 'starting') {
            return { tone: 'warning', label: 'starting' };
        }
        if (lower === 'error') {
            return { tone: 'danger', label: 'error' };
        }
        if (lower === 'stopped' || lower === 'down') {
            return { tone: 'muted', label: state || 'stopped' };
        }
        return { tone: 'muted', label: state || 'unknown' };
    }

    function emptyRow(colspan, text) {
        return '<tr><td colspan="' + colspan + '" class="w-text" data-align="center" data-tone="muted" ' +
            'style="--w-pt:var(--weline-space-4);--w-pb:var(--weline-space-4);">' + escapeHtml(text) + '</td></tr>';
    }

    function init(root) {
        if (!root || root.getAttribute('data-server-monitor-ready') === '1') {
            return;
        }
        root.setAttribute('data-server-monitor-ready', '1');

        var config = readConfig();
        if (!config) {
            return;
        }
        var i18n = config.i18n || {};
        var instanceQuery = '?instance=' + encodeURIComponent(config.instance || 'default');
        var refreshTimer = null;

        function updateStats(data) {
            var stats = data.server_stats || {};
            var runningWorkers = document.getElementById('running-workers');
            var totalConnections = document.getElementById('total-connections');
            var totalRequests = document.getElementById('total-requests');
            if (runningWorkers) {
                runningWorkers.textContent = stats.running_workers || 0;
            }
            if (totalConnections) {
                totalConnections.textContent = stats.total_connections || 0;
            }
            if (totalRequests) {
                totalRequests.textContent = formatNumber(stats.total_requests || 0);
            }
        }

        function renderProcessDetails(services) {
            var tbody = document.getElementById('process-detail-body');
            if (!tbody) {
                return;
            }
            if (!Array.isArray(services) || services.length === 0) {
                tbody.innerHTML = emptyRow(13, i18n.noProcessDetail);
                return;
            }
            var rows = [];
            var rowIndex = 0;
            services.forEach(function (serviceGroup) {
                var role = String(serviceGroup.role || '').toLowerCase();
                var instances = Array.isArray(serviceGroup.instances) ? serviceGroup.instances : [];
                instances.forEach(function (item) {
                    var roleBadge = getRoleBadge(serviceGroup.role || '');
                    var stateBadge = getStateBadge(item.state || '');
                    var healthDetail = item.health_detail || {};
                    var fiberCount = Number(healthDetail.fiber_count == null ? 0 : healthDetail.fiber_count);
                    var fibers = Array.isArray(healthDetail.fibers) ? healthDetail.fibers : [];
                    var isWorker = role === 'worker';
                    var canExpand = isWorker && fiberCount > 0;
                    var fibersJson = escapeHtml(JSON.stringify(fibers));
                    var expandCell = canExpand
                        ? '<td class="w-text" data-align="center" data-w-vertical="middle"><button type="button" class="w-button server-monitor-fiber-toggle" data-fibers-json="' + fibersJson + '" data-row-idx="' + rowIndex + '" aria-label="' + escapeHtml(i18n.fiberToggle) + '" data-w-border="none" data-tone="neutral" data-variant="outline" data-size="sm" style="--w-pt:var(--weline-space-1);--w-pe:var(--weline-space-1);--w-pb:var(--weline-space-1);--w-ps:var(--weline-space-1);"><w:icon name="chevron-down" size="sm"></w:icon></button></td>'
                        : '<td class="w-text" data-align="center"></td>';
                    var fiberCell = isWorker
                        ? '<td class="process-detail-table-memory">' + fiberCount + '</td>'
                        : '<td class="w-text" data-tone="muted">-</td>';
                    rows.push(
                        '<tr class="server-monitor-process-row" data-row-idx="' + rowIndex + '">' +
                        expandCell +
                        '<td><span class="w-badge" data-w-background="' + roleBadge.tone + '">' + escapeHtml(roleBadge.label) + '</span></td>' +
                        '<td>#' + escapeHtml(String(item.instance_id == null ? '-' : item.instance_id)) + '</td>' +
                        '<td>' + escapeHtml(String(item.pid || '-')) + '</td>' +
                        '<td>' + escapeHtml(String(item.port || '-')) + '</td>' +
                        '<td><span class="w-badge" data-w-background="' + stateBadge.tone + '">' + escapeHtml(stateBadge.label) + '</span></td>' +
                        '<td>' + escapeHtml(formatSeconds(item.uptime || 0)) + '</td>' +
                        '<td>' + escapeHtml(String(item.restarts || 0)) + '</td>' +
                        '<td>' + formatNumber(Number(item.connections || 0)) + ' / ' + formatNumber(Number(item.active_requests || 0)) + '</td>' +
                        fiberCell +
                        '<td>' + formatNumber(Number(item.total_requests || 0)) + '</td>' +
                        '<td class="process-detail-table-memory">' + escapeHtml(formatBytes(item.memory_usage || 0)) + '</td>' +
                        '<td>' + escapeHtml(formatHeartbeat(item.heartbeat || 0)) + '</td>' +
                        '</tr>'
                    );
                    rowIndex++;
                });
            });
            tbody.innerHTML = rows.join('');
            tbody.querySelectorAll('.server-monitor-fiber-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = this.closest('tr');
                    var fibersJson = this.getAttribute('data-fibers-json');
                    var fibers = [];
                    try {
                        fibers = fibersJson ? JSON.parse(fibersJson) : [];
                    } catch (error) {
                        fibers = [];
                    }
                    var next = row.nextElementSibling;
                    if (next && next.classList.contains('server-monitor-fiber-detail-row')) {
                        next.remove();
                        return;
                    }
                    var statusLabel = function (status) {
                        return status === 'idle' ? i18n.idle : i18n.busy;
                    };
                    var protocolLabel = function (protocol) {
                        return protocol === 'sse' ? 'SSE' : 'HTTP';
                    };
                    var fiberRows = fibers.length === 0
                        ? '<tr><td colspan="4" class="w-text" data-tone="muted">' + escapeHtml(i18n.noFiber) + '</td></tr>'
                        : fibers.map(function (fiber) {
                            return '<tr>' +
                                '<td>' + escapeHtml(String(fiber.conn_id == null ? '' : fiber.conn_id)) + '</td>' +
                                '<td><span class="w-badge" data-w-background="' + (fiber.status === 'idle' ? 'muted' : 'warning') + '">' + escapeHtml(statusLabel(fiber.status)) + '</span></td>' +
                                '<td>' + escapeHtml(protocolLabel(fiber.protocol)) + '</td>' +
                                '</tr>';
                        }).join('');
                    var detailRow = document.createElement('tr');
                    detailRow.className = 'server-monitor-fiber-detail-row';
                    detailRow.innerHTML =
                        '<td colspan="13" style="--w-pt:0;--w-pe:0;--w-pb:0;--w-ps:0;">' +
                        '<div data-w-border="start" style="--w-pt:var(--weline-space-2);--w-pe:var(--weline-space-2);--w-pb:var(--weline-space-2);--w-ps:var(--weline-space-2);">' +
                        '<table class="w-table w-text" data-size="sm" style="--w-mb:0;">' +
                        '<thead><tr><th>' + escapeHtml(i18n.connId) + '</th><th>' + escapeHtml(i18n.status) + '</th><th>' + escapeHtml(i18n.protocol) + '</th></tr></thead>' +
                        '<tbody>' + fiberRows + '</tbody></table></div></td>';
                    row.after(detailRow);
                });
            });
        }

        function renderProcessHistory(logs) {
            var tbody = document.getElementById('process-history-body');
            if (!tbody) {
                return;
            }
            if (!Array.isArray(logs) || logs.length === 0) {
                tbody.innerHTML = emptyRow(10, i18n.noStatusLog);
                return;
            }
            var rows = logs.slice(0, 30).map(function (log) {
                var statusBadge = getStateBadge(log.status || '');
                var lastError = String(log.last_error || '');
                return '<tr>' +
                    '<td>' + escapeHtml(String(log.created_at || '-')) + '</td>' +
                    '<td>' + escapeHtml(String(log.process_type || '-')) + '</td>' +
                    '<td>' + escapeHtml(String(log.process_id || '-')) + '</td>' +
                    '<td>' + escapeHtml(String(log.pid || '-')) + '</td>' +
                    '<td><span class="w-badge" data-w-background="' + statusBadge.tone + '">' + escapeHtml(statusBadge.label) + '</span></td>' +
                    '<td>' + formatNumber(Number(log.connections || 0)) + '</td>' +
                    '<td>' + formatNumber(Number(log.active_requests || 0)) + '</td>' +
                    '<td>' + formatNumber(Number(log.total_requests || 0)) + '</td>' +
                    '<td>' + escapeHtml(formatBytes(Number(log.memory_usage || 0))) + '</td>' +
                    '<td title="' + escapeHtml(lastError) + '">' + escapeHtml(lastError || '-') + '</td>' +
                    '</tr>';
            });
            tbody.innerHTML = rows.join('');
        }

        async function refreshStatus() {
            try {
                var data = await Weline.adminRequest('server', config.statusUrl + instanceQuery, {
                    method: 'GET',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });
                if (data.success) {
                    var payload = data.data || {};
                    updateStats(payload);
                    renderTrafficHosts((payload.traffic || {}).hosts || []);
                    renderProcessDetails((payload.status_dto || {}).services || []);
                    renderProcessHistory(payload.status_logs || []);
                }
            } catch (error) {
                console.error('Failed to refresh status:', error);
            }
        }

        function renderTrafficHosts(hosts) {
            var tbody = document.getElementById('traffic-host-table');
            if (!tbody) {
                return;
            }
            if (!hosts || hosts.length === 0) {
                tbody.innerHTML = emptyRow(4, i18n.noTraffic);
                return;
            }
            tbody.innerHTML = hosts.map(function (item) {
                var avg = item.request_count > 0 ? (item.latency_total_ms / item.request_count).toFixed(2) : '0.00';
                return '<tr>' +
                    '<td>' + escapeHtml(item.host || '*') + '</td>' +
                    '<td class="w-text" data-align="end">' + formatNumber(item.request_count || 0) + '</td>' +
                    '<td class="w-text" data-align="end">' + formatNumber(item.error_count || 0) + '</td>' +
                    '<td class="w-text" data-align="end">' + avg + '</td>' +
                    '</tr>';
            }).join('');
        }

        async function refreshTraffic() {
            try {
                var data = await Weline.adminRequest('server', config.trafficUrl + instanceQuery + '&window_sec=300', {
                    method: 'GET',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });
                if (data.success) {
                    renderTrafficHosts((data.data || {}).hosts || []);
                }
            } catch (error) {
                console.error('Failed to refresh traffic:', error);
            }
        }

        async function runBenchmark() {
            var targetEl = document.getElementById('benchmark-target');
            var concurrencyEl = document.getElementById('benchmark-concurrency');
            var requestsEl = document.getElementById('benchmark-requests');
            var target = (targetEl && targetEl.value ? targetEl.value : '').trim();
            var concurrency = parseInt(concurrencyEl && concurrencyEl.value ? concurrencyEl.value : '50', 10);
            var requests = parseInt(requestsEl && requestsEl.value ? requestsEl.value : '1000', 10);
            if (!target) {
                Weline.UI.toast.warning(i18n.benchmarkTargetRequired);
                return;
            }
            Weline.UI.toast.info(i18n.benchmarkRunning);
            try {
                var data = await Weline.adminRequest('server', config.benchmarkUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'instance=' + encodeURIComponent(config.instance || 'default') +
                        '&target=' + encodeURIComponent(target) +
                        '&concurrency=' + encodeURIComponent(concurrency) +
                        '&requests=' + encodeURIComponent(requests),
                    credentials: 'same-origin'
                });
                if (!data.success) {
                    Weline.UI.toast.error(data.message || i18n.benchmarkFail);
                    return;
                }
                var result = data.data || {};
                var lastResult = document.getElementById('benchmark-last-result');
                if (lastResult) {
                    lastResult.textContent = 'QPS: ' + (result.qps || 0) +
                        ', p95: ' + (result.latency_p95 || 0) + 'ms' +
                        ', error_rate: ' + (result.error_rate || 0) + '%';
                }
                Weline.UI.toast.success(i18n.benchmarkDone);
            } catch (error) {
                Weline.UI.toast.error(i18n.benchmarkRetryHint + ' ' + error.message);
            }
        }

        async function cleanupLogs() {
            var confirmed = await Weline.UI.dialog.confirm(i18n.cleanupConfirm, {
                title: i18n.cleanupTitle,
                tone: 'warning',
                confirmLabel: i18n.cleanupConfirmLabel,
                cancelLabel: i18n.cancelLabel
            });
            if (!confirmed) {
                return;
            }
            Weline.UI.toast.info(i18n.cleaning);
            try {
                var data = await Weline.adminRequest('server', config.cleanupUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'status_days=7&attack_days=30',
                    credentials: 'same-origin'
                });
                if (data.success) {
                    Weline.UI.toast.success(data.message + ' (' + i18n.statusLogLabel + ': ' +
                        (data.data ? data.data.status_logs_cleaned : 0) + ', ' + i18n.attackLogLabel + ': ' +
                        (data.data ? data.data.attack_logs_cleaned : 0) + ')');
                } else {
                    Weline.UI.toast.error(data.message || i18n.cleanupFail);
                }
            } catch (error) {
                Weline.UI.toast.error(i18n.cleanupFail + ': ' + error.message);
            }
        }

        function handleClick(event) {
            var trigger = event.target.closest('[data-server-monitor-action]');
            if (!trigger) {
                return;
            }
            var action = trigger.getAttribute('data-server-monitor-action');
            if (action === 'refresh-status') {
                refreshStatus();
                return;
            }
            if (action === 'run-benchmark') {
                runBenchmark();
                return;
            }
            if (action === 'cleanup-logs') {
                cleanupLogs();
            }
        }

        root.addEventListener('click', handleClick);
        refreshTimer = setInterval(refreshStatus, REFRESH_INTERVAL_MS);
        refreshStatus();
        refreshTraffic();
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
        document.querySelectorAll('[data-server-monitor="1"]').forEach(init);
    }

    global.WelineServerMonitor = { init: init, boot: boot };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { whenWelineReady(boot); });
    } else {
        whenWelineReady(boot);
    }
})(typeof window !== 'undefined' ? window : this);
