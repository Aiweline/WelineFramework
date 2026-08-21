import {NativeChart} from './native-chart.js';

function showNotice(options = {}) {
    const tone = options.icon === 'error' ? 'danger' : (options.icon || 'info');
    const message = [options.title, options.text].filter(Boolean).join(': ');
    return window.Weline?.UI?.toast.show(message, {tone});
}

        const charts = {};
        let autoRefreshInterval = null;
        let isAutoRefreshing = false;

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

        function escapeAttribute(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        }

        function safeNumber(value, fallback = 0) {
            const number = Number(value);
            return Number.isFinite(number) ? number : fallback;
        }

        document.addEventListener('click', function(event) {
            const source = event.target instanceof Element ? event.target : null;
            const abTestButton = source?.closest('[data-abtest-id]');
            if (abTestButton) {
                event.preventDefault();
                viewAbTest(abTestButton.getAttribute('data-abtest-id'));
                return;
            }

            const modalAction = source?.closest('[data-modal-action]');
            if (modalAction) {
                event.preventDefault();
                const modal = modalAction.closest('[data-visitor-modal]');
                if (modalAction.getAttribute('data-modal-action') === 'close' && modal) {
                    window.Weline?.UI?.dialog.close(modal, 'cancel');
                }
                return;
            }

            const action = source?.closest('[data-visitor-action]');
            if (!action) {
                return;
            }

            event.preventDefault();
            const handlers = {
                'load-business-value': loadBusinessValue,
                'load-dashboard': loadDashboard,
                'toggle-auto-refresh': toggleAutoRefresh,
                'load-comparison': loadComparison,
                'load-abtest-list': loadAbTestList,
                'show-create-abtest': showCreateAbTest,
                'load-abtest-data': loadAbTestData,
                'load-report': loadReport,
                'export-data': exportData,
                'submit-create-abtest': submitCreateAbTest
            };
            const handler = handlers[action.getAttribute('data-visitor-action')];
            if (typeof handler === 'function') {
                handler();
            }
        });
        let visitorApiPromise = null;
        function getVisitorApi() {
            if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                visitorApiPromise = Promise.resolve(window.Weline.Api.resource('visitor'));
                return visitorApiPromise;
            }
            if (visitorApiPromise) {
                return visitorApiPromise;
            }
            visitorApiPromise = new Promise((resolve, reject) => {
                let attempts = 0;
                const waitForApi = () => {
                    attempts += 1;
                    if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                        resolve(window.Weline.Api.resource('visitor'));
                        return;
                    }
                    if (attempts >= 80) {
                        reject(new Error('Weline.Api.resource is not available'));
                        return;
                    }
                    window.setTimeout(waitForApi, 50);
                };
                waitForApi();
            });
            visitorApiPromise.catch(() => {
                visitorApiPromise = null;
            });
            return visitorApiPromise;
        }

        async function requestVisitor(operation, params) {
            const api = await getVisitorApi();
            return api[operation](params || {});
        }

        // 初始化图表
        function initChart(canvasId, type = 'line') {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return null;
            
            if (charts[canvasId]) {
                charts[canvasId].destroy();
            }

            charts[canvasId] = new NativeChart(ctx, {
                type: type,
                data: {
                    labels: [],
                    datasets: []
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true
                        }
                    },
                    scales: {
                        x: {
                            display: true
                        },
                        y: {
                            display: true,
                            beginAtZero: true
                        }
                    }
                }
            });
            return charts[canvasId];
        }

        // 加载商业价值数据
        async function loadBusinessValue() {
            const websiteId = document.getElementById('business-websiteId').value;
            const period = document.getElementById('business-period').value;
            const startDate = document.getElementById('business-startDate').value;
            const endDate = document.getElementById('business-endDate').value;

            const params = { period };
            if (websiteId) params.websiteId = websiteId;
            if (startDate) params.startDate = startDate;
            if (endDate) params.endDate = endDate;

            try {
                const result = await requestVisitor('analyticsBusinessValue', params);

                if (result.code === 200) {
                    const data = result.data;
                    document.getElementById('total-value').textContent = data.total_value.toLocaleString();
                    document.getElementById('total-events').textContent = data.total_events.toLocaleString();

                    // 更新图表
                    updateBusinessCharts(data);
                    updateBusinessTable(data);
                } else {
                    if (window.Weline?.UI?.toast) {
                        showNotice({
                            icon: 'error',
                            title: '加载失败',
                            text: result.msg,
                            confirmButtonText: '确定'
                        });
                    } else {
                        console.error('加载失败: ' + result.msg);
                    }
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '加载失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('加载失败: ' + error.message);
                }
            }
        }

        // 更新商业价值图表
        function updateBusinessCharts(data) {
            const labels = data.data_points.map(p => p.date);
            const values = data.data_points.map(p => p.value);
            const events = data.data_points.map(p => p.events);

            const valueChart = initChart('business-value-chart');
            if (valueChart) {
                valueChart.data.labels = labels;
                valueChart.data.datasets = [{
                    label: '价值',
                    data: values,
                    borderColor: 'var(--weline-theme-visitor-chart-primary)',
                    backgroundColor: 'var(--weline-theme-visitor-chart-primary-soft)',
                    tension: 0.4
                }];
                valueChart.update();
            }

            const eventsChart = initChart('business-events-chart');
            if (eventsChart) {
                eventsChart.data.labels = labels;
                eventsChart.data.datasets = [{
                    label: '事件数',
                    data: events,
                    borderColor: 'var(--weline-theme-visitor-chart-success)',
                    backgroundColor: 'var(--weline-theme-visitor-chart-success-soft)',
                    tension: 0.4
                }];
                eventsChart.update();
            }
        }

        // 更新商业价值表格
        function updateBusinessTable(data) {
            const tbody = document.getElementById('business-table-body');
            tbody.innerHTML = data.data_points.map(point => `
                <tr>
                    <td>${escapeHtml(point.date)}</td>
                    <td>${safeNumber(point.value).toLocaleString()}</td>
                    <td>${safeNumber(point.events).toLocaleString()}</td>
                    <td>${safeNumber(point.event_types).toLocaleString()}</td>
                    <td>${safeNumber(point.avg_value).toFixed(2)}</td>
                    <td>${(safeNumber(point.conversion_rate) * 100).toFixed(2)}%</td>
                </tr>
            `).join('');
        }

        // 加载大屏数据
        async function loadDashboard() {
            const websiteId = document.getElementById('dashboard-websiteId').value;
            const interval = document.getElementById('dashboard-interval').value;
            const hours = document.getElementById('dashboard-hours').value;

            const params = { interval, hours };
            if (websiteId) params.websiteId = websiteId;

            try {
                const result = await requestVisitor('analyticsDashboard', params);

                if (result.code === 200) {
                    const data = result.data;
                    const current = data.current_period;
                    
                    if (current) {
                        document.getElementById('dashboard-current-value').textContent = current.value.toLocaleString();
                        document.getElementById('dashboard-current-events').textContent = current.events.toLocaleString();
                        document.getElementById('dashboard-current-time').textContent = current.timestamp;
                    }

                    const changePercent = data.change_percentage || 0;
                    const changeEl = document.getElementById('dashboard-change-percent');
                    changeEl.textContent = (changePercent >= 0 ? '+' : '') + changePercent.toFixed(2) + '%';
                    changeEl.dataset.tone = changePercent >= 0 ? 'success' : 'danger';

                    updateDashboardCharts(data);
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '加载失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('加载失败: ' + error.message);
                }
            }
        }

        // 更新大屏图表
        function updateDashboardCharts(data) {
            const labels = data.data_points.map(p => {
                const date = new Date(p.timestamp);
                return date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
            });
            const values = data.data_points.map(p => p.value);
            const events = data.data_points.map(p => p.events);

            const valueChart = initChart('dashboard-value-chart');
            if (valueChart) {
                valueChart.data.labels = labels;
                valueChart.data.datasets = [{
                    label: '价值',
                    data: values,
                    borderColor: 'var(--weline-theme-visitor-chart-primary)',
                    backgroundColor: 'var(--weline-theme-visitor-chart-primary-soft)',
                    tension: 0.4
                }];
                valueChart.update('none');
            }

            const eventsChart = initChart('dashboard-events-chart');
            if (eventsChart) {
                eventsChart.data.labels = labels;
                eventsChart.data.datasets = [{
                    label: '事件数',
                    data: events,
                    borderColor: 'var(--weline-theme-visitor-chart-success)',
                    backgroundColor: 'var(--weline-theme-visitor-chart-success-soft)',
                    tension: 0.4
                }];
                eventsChart.update('none');
            }
        }

        // 切换自动刷新
        function toggleAutoRefresh() {
            isAutoRefreshing = !isAutoRefreshing;
            const btn = document.getElementById('auto-refresh-btn');
            
            if (isAutoRefreshing) {
                btn.textContent = '停止自动刷新';
                btn.dataset.tone = 'danger';
                autoRefreshInterval = setInterval(loadDashboard, 30000);
            } else {
                btn.textContent = '开启自动刷新';
                btn.dataset.tone = 'neutral';
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
        }

        // 加载对比数据
        async function loadComparison() {
            const websiteId = document.getElementById('comparison-websiteId').value;
            const days = document.getElementById('comparison-days').value;

            const params = { days };
            if (websiteId) params.websiteId = websiteId;

            try {
                const result = await requestVisitor('analyticsDailyComparison', params);

                if (result.code === 200) {
                    updateComparisonTable(result.data);
                    updateComparisonChart(result.data);
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '加载失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('加载失败: ' + error.message);
                }
            }
        }

        // 更新对比表格
        function updateComparisonTable(data) {
            const tbody = document.getElementById('comparison-table-body');
            tbody.innerHTML = data.comparisons.map(comp => `
                <tr>
                    <td>${escapeHtml(comp.date)}</td>
                    <td>${safeNumber(comp.today?.value).toLocaleString()}</td>
                    <td>${safeNumber(comp.yesterday?.value).toLocaleString()}</td>
                    <td class="w-visitor-stat" data-tone="${safeNumber(comp.change_value) >= 0 ? 'success' : 'danger'}">
                        ${safeNumber(comp.change_value) >= 0 ? '+' : ''}${safeNumber(comp.change_value).toFixed(2)}
                    </td>
                    <td class="w-visitor-stat" data-tone="${safeNumber(comp.change_percentage) >= 0 ? 'success' : 'danger'}">
                        ${safeNumber(comp.change_percentage) >= 0 ? '+' : ''}${safeNumber(comp.change_percentage).toFixed(2)}%
                    </td>
                    <td>${safeNumber(comp.today?.events).toLocaleString()}</td>
                    <td>${safeNumber(comp.yesterday?.events).toLocaleString()}</td>
                </tr>
            `).join('');
        }

        // 更新对比图表
        function updateComparisonChart(data) {
            const labels = data.comparisons.map(c => c.date);
            const todayValues = data.comparisons.map(c => c.today.value);
            const yesterdayValues = data.comparisons.map(c => c.yesterday.value);

            const chart = initChart('comparison-chart');
            if (chart) {
                chart.data.labels = labels;
                chart.data.datasets = [
                    {
                        label: '今天',
                        data: todayValues,
                        borderColor: 'var(--weline-theme-visitor-chart-primary)',
                        backgroundColor: 'var(--weline-theme-visitor-chart-primary-soft)',
                        tension: 0.4
                    },
                    {
                        label: '昨天',
                        data: yesterdayValues,
                        borderColor: 'var(--weline-theme-visitor-chart-neutral)',
                        backgroundColor: 'var(--weline-theme-visitor-chart-neutral-soft)',
                        tension: 0.4
                    }
                ];
                chart.update();
            }
        }

        // 加载A/B测试列表
        async function loadAbTestList() {
            const websiteId = document.getElementById('abtest-websiteId').value;
            const status = document.getElementById('abtest-status').value;

            const params = {};
            if (websiteId) params.websiteId = websiteId;
            if (status) params.status = status;

            try {
                const result = await requestVisitor('analyticsAbTestList', params);

                if (result.code === 200) {
                    updateAbTestTable(result.data.tests);
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '加载失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('加载失败: ' + error.message);
                }
            }
        }

        // 更新A/B测试表格
        function updateAbTestTable(tests) {
            const tbody = document.getElementById('abtest-table-body');
            if (tests.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="w-visitor-loading">暂无测试数据</td></tr>';
                return;
            }

            const statusBadge = (status) => {
                const badges = {
                    'active': '<span class="w-badge" data-tone="success">进行中</span>',
                    'paused': '<span class="w-badge" data-tone="warning">暂停</span>',
                    'completed': '<span class="w-badge" data-tone="info">已完成</span>',
                    'draft': '<span class="w-badge" data-tone="danger">草稿</span>'
                };
                return badges[status] || escapeHtml(status);
            };

            tbody.innerHTML = tests.map(test => `
                <tr>
                    <td>${escapeHtml(test.test_id)}</td>
                    <td>${escapeHtml(test.name)}</td>
                    <td>${statusBadge(test.status)}</td>
                    <td>${escapeHtml(test.start_date || '--')}</td>
                    <td>${escapeHtml(test.end_date || '--')}</td>
                    <td>
                        <button class="w-button" data-abtest-id="${escapeAttribute(test.test_id)}">查看</button>
                    </td>
                </tr>
            `).join('');
        // 查看A/B测试
        function viewAbTest(testId) {
            document.getElementById('abtest-detail-testId').value = testId;
            document.getElementById('abtest-detail').hidden = false;
            loadAbTestData();
        }

        // 加载A/B测试数据
        async function loadAbTestData() {
            const testId = document.getElementById('abtest-detail-testId').value;
            const websiteId = document.getElementById('abtest-websiteId').value;

            if (!testId) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'warning',
                        title: '提示',
                        text: '请先选择测试',
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('请先选择测试');
                }
                return;
            }

            const params = { testId };
            if (websiteId) params.websiteId = websiteId;

            try {
                const result = await requestVisitor('analyticsAbTest', params);

                if (result.code === 200) {
                    displayAbTestData(result.data);
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '加载失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('加载失败: ' + error.message);
                }
            }
        }

        // 显示A/B测试数据
        function displayAbTestData(data) {
            const resultDiv = document.getElementById('abtest-detail-result');
            const variants = data.variants || {};

            let html = '<div class="w-visitor-grid">';
            for (const [variant, stats] of Object.entries(variants)) {
                const isWinner = data.winner === variant;
                html += `
                    <div class="w-card w-visitor-abtest-variant" data-state="${isWinner ? 'winner' : 'candidate'}">
                        <h3>变体 ${escapeHtml(variant)} ${isWinner ? '<w-icon name="star" size="sm" label="获胜"></w-icon> 获胜' : ''}</h3>
                        <div class="w-visitor-stat__row">
                            <span class="w-visitor-stat__label">价值:</span>
                            <span class="w-visitor-stat">${safeNumber(stats.value).toLocaleString()}</span>
                        </div>
                        <div class="w-visitor-stat__row">
                            <span class="w-visitor-stat__label">事件数:</span>
                            <span class="w-visitor-stat">${safeNumber(stats.events).toLocaleString()}</span>
                        </div>
                        <div class="w-visitor-stat__row">
                            <span class="w-visitor-stat__label">转化率:</span>
                            <span class="w-visitor-stat">${(safeNumber(stats.conversion_rate) * 100).toFixed(2)}%</span>
                        </div>
                    </div>
                `;
            }
            html += '</div>';

            if (data.improvement !== 0) {
                html += `<div class="w-card w-visitor-abtest-improvement">
                    <h3>改进情况</h3>
                    <p>变体B相比变体A改进了 <strong>${safeNumber(data.improvement).toFixed(2)}%</strong></p>
                </div>`;
            }

            resultDiv.innerHTML = html;
        }

        // 显示创建A/B测试表单
        function showCreateAbTest() {
            const existing = document.querySelector('[data-visitor-modal="create-abtest"]');
            if (existing instanceof HTMLDialogElement) {
                window.Weline?.UI?.dialog.open(existing);
                return;
            }

            const modal = document.createElement('dialog');
            modal.className = 'w-dialog';
            modal.dataset.wComponent = 'dialog';
            modal.dataset.visitorModal = 'create-abtest';
            modal.dataset.size = 'lg';
            modal.setAttribute('aria-labelledby', 'create-abtest-title');
            modal.innerHTML = `
                <header class="w-dialog__header">
                    <h2 id="create-abtest-title">创建A/B测试</h2>
                    <button class="w-button" type="button" data-modal-action="close" data-tone="quiet" aria-label="关闭">
                        <w-icon name="close" size="sm"></w-icon>
                    </button>
                </header>
                <div class="w-dialog__body w-visitor-dialog-form">
                    <div class="w-field">
                        <label class="w-field__label" for="create-testId">测试ID *</label>
                        <input class="w-input" type="text" id="create-testId" placeholder="test_001" required>
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-name">测试名称 *</label>
                        <input class="w-input" type="text" id="create-name" placeholder="首页按钮颜色测试" required>
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-description">测试描述</label>
                        <textarea class="w-textarea" id="create-description" rows="3" placeholder="测试不同按钮颜色对转化率的影响"></textarea>
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-websiteId">站点ID</label>
                        <input class="w-input" type="number" id="create-websiteId" value="0" min="0">
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-status">状态</label>
                        <select class="w-select" id="create-status">
                            <option value="draft">草稿</option>
                            <option value="active">进行中</option>
                            <option value="paused">暂停</option>
                        </select>
                    </div>
                    <div class="w-visitor-grid" data-w-columns="2">
                        <div class="w-field">
                            <label class="w-field__label" for="create-startDate">开始时间</label>
                            <input class="w-input" type="datetime-local" id="create-startDate">
                        </div>
                        <div class="w-field">
                            <label class="w-field__label" for="create-endDate">结束时间</label>
                            <input class="w-input" type="datetime-local" id="create-endDate">
                        </div>
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-trafficSplit">流量分配 (A:B)</label>
                        <input class="w-input" type="text" id="create-trafficSplit" value="50:50" placeholder="50:50">
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-variantA">变体A配置 (JSON)</label>
                        <textarea class="w-textarea w-visitor-code-input" id="create-variantA" rows="3" placeholder='{"color": "blue"}'>{}</textarea>
                    </div>
                    <div class="w-field">
                        <label class="w-field__label" for="create-variantB">变体B配置 (JSON)</label>
                        <textarea class="w-textarea w-visitor-code-input" id="create-variantB" rows="3" placeholder='{"color": "red"}'>{}</textarea>
                    </div>
                </div>
                <footer class="w-dialog__footer">
                    <button class="w-button" type="button" data-modal-action="close" data-tone="neutral">取消</button>
                    <button class="w-button" type="button" data-visitor-action="submit-create-abtest">创建</button>
                </footer>
            `;
            modal.addEventListener('weline:ui:dialog:close', () => modal.remove(), {once: true});
            document.body.appendChild(modal);
            window.Weline?.UI?.mount(modal);
            window.Weline?.UI?.dialog.open(modal);
        }

        // 提交创建A/B测试
        async function submitCreateAbTest() {
            const testId = document.getElementById('create-testId').value;
            const name = document.getElementById('create-name').value;
            
            if (!testId || !name) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'warning',
                        title: '提示',
                        text: '请填写测试ID和测试名称',
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('请填写测试ID和测试名称');
                }
                return;
            }

            let variantA = {};
            let variantB = {};
            try {
                variantA = JSON.parse(document.getElementById('create-variantA').value || '{}');
                variantB = JSON.parse(document.getElementById('create-variantB').value || '{}');
            } catch (e) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '格式错误',
                        text: '变体配置JSON格式错误: ' + e.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('变体配置JSON格式错误: ' + e.message);
                }
                return;
            }

            const data = {
                testId: testId,
                name: name,
                description: document.getElementById('create-description').value,
                websiteId: parseInt(document.getElementById('create-websiteId').value) || 0,
                status: document.getElementById('create-status').value,
                variantA: variantA,
                variantB: variantB,
                trafficSplit: document.getElementById('create-trafficSplit').value || '50:50'
            };

            const startDate = document.getElementById('create-startDate').value;
            const endDate = document.getElementById('create-endDate').value;
            if (startDate) {
                data.startDate = startDate.replace('T', ' ') + ':00';
            }
            if (endDate) {
                data.endDate = endDate.replace('T', ' ') + ':00';
            }

            try {
                const result = await requestVisitor('analyticsAbTestCreate', data);

                if (result.code === 200) {
                    if (window.Weline?.UI?.toast) {
                        showNotice({
                            icon: 'success',
                            title: '成功',
                            text: '创建成功',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        console.log('创建成功！');
                    }
                    const modal = document.querySelector('[data-visitor-modal="create-abtest"]');
                    if (modal) window.Weline?.UI?.dialog.close(modal, 'created');
                    loadAbTestList();
                } else {
                    if (window.Weline?.UI?.toast) {
                        showNotice({
                            icon: 'error',
                            title: '创建失败',
                            text: result.msg,
                            confirmButtonText: '确定'
                        });
                    } else {
                        console.error('创建失败: ' + result.msg);
                    }
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '创建失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('创建失败: ' + error.message);
                }
            }
        }

        // 加载综合报告
        async function loadReport() {
            const websiteId = document.getElementById('report-websiteId').value;
            const startDate = document.getElementById('report-startDate').value;
            const endDate = document.getElementById('report-endDate').value;

            const params = {};
            if (websiteId) params.websiteId = websiteId;
            if (startDate) params.startDate = startDate;
            if (endDate) params.endDate = endDate;

            try {
                const result = await requestVisitor('analyticsReport', params);

                if (result.code === 200) {
                    const data = result.data;
                    
                    // 更新统计卡片
                    const totalCount = data.time_range_stats?.total_count || data.summary?.total_count || 0;
                    document.getElementById('report-total').textContent = totalCount.toLocaleString();
                    
                    const totalValue = data.daily_stats?.total_value || 0;
                    document.getElementById('report-total-value').textContent = totalValue.toLocaleString();
                    
                    const eventCount = data.summary?.event_count || Object.keys(data.event_stats || {}).length || 0;
                    document.getElementById('report-event-count').textContent = eventCount;
                    
                    const unDealCount = data.summary?.un_deal_count || 0;
                    document.getElementById('report-undeal').textContent = unDealCount.toLocaleString();

                    // 更新热门事件表格
                    updateTopEventsTable(data.top_events, data.event_stats);

                    // 更新图表
                    updateReportCharts(data);
                }
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '加载失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('加载失败: ' + error.message);
                }
            }
        }

        // 更新热门事件表格
        function updateTopEventsTable(topEvents, allEvents) {
            const tbody = document.getElementById('report-top-events');
            const total = Object.values(allEvents).reduce((sum, count) => sum + count, 0);
            
            if (Object.keys(topEvents).length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="w-visitor-loading">暂无数据</td></tr>';
                return;
            }

            let rank = 1;
            tbody.innerHTML = Object.entries(topEvents).map(([event, count]) => {
                const safeCount = safeNumber(count);
                const percentage = total > 0 ? ((safeCount / total) * 100).toFixed(2) : 0;
                return `
                    <tr>
                        <td>${rank++}</td>
                        <td>${escapeHtml(event)}</td>
                        <td>${safeCount.toLocaleString()}</td>
                        <td>${percentage}%</td>
                    </tr>
                `;
            }).join('');
        }

        // 更新报告图表
        function updateReportCharts(data) {
            // 事件分布饼图
            const eventChart = initChart('report-events-chart', 'doughnut');
            if (eventChart && data.top_events) {
                const labels = Object.keys(data.top_events);
                const values = Object.values(data.top_events);
                const colors = [
                    'var(--weline-theme-visitor-chart-primary)',
                    'var(--weline-theme-visitor-chart-success)',
                    'var(--weline-theme-visitor-chart-warning)',
                    'var(--weline-theme-visitor-chart-danger)',
                    'var(--weline-theme-visitor-chart-info)',
                    'var(--weline-theme-visitor-chart-purple)',
                    'var(--weline-theme-visitor-chart-pink)',
                    'var(--weline-theme-visitor-chart-orange)',
                    'var(--weline-theme-visitor-chart-teal)',
                    'var(--weline-theme-visitor-chart-neutral)'
                ];
                
                eventChart.data.labels = labels;
                eventChart.data.datasets = [{
                    data: values,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 1
                }];
                eventChart.update();
            }

            // 每日趋势图
            const dailyChart = initChart('report-daily-chart');
            if (dailyChart && data.daily_stats && data.daily_stats.data_points) {
                const labels = data.daily_stats.data_points.map(p => p.date);
                const values = data.daily_stats.data_points.map(p => p.value);
                const events = data.daily_stats.data_points.map(p => p.events);

                dailyChart.data.labels = labels;
                dailyChart.data.datasets = [
                    {
                        label: '价值',
                        data: values,
                        borderColor: 'var(--weline-theme-visitor-chart-primary)',
                        backgroundColor: 'var(--weline-theme-visitor-chart-primary-soft)',
                        yAxisID: 'y',
                        tension: 0.4
                    },
                    {
                        label: '事件数',
                        data: events,
                        borderColor: 'var(--weline-theme-visitor-chart-success)',
                        backgroundColor: 'var(--weline-theme-visitor-chart-success-soft)',
                        yAxisID: 'y1',
                        tension: 0.4
                    }
                ];
                dailyChart.options.scales = {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                };
                dailyChart.update();
            }
        }

        // 导出数据
        async function exportData() {
            const websiteId = document.getElementById('report-websiteId').value;
            const startDate = document.getElementById('report-startDate').value;
            const endDate = document.getElementById('report-endDate').value;

            const params = {};
            if (websiteId) params.websiteId = websiteId;
            if (startDate) params.startDate = startDate;
            if (endDate) params.endDate = endDate;

            try {
                const result = await requestVisitor('analyticsExport', params);
                if (result.code !== 200) {
                    throw new Error(result.msg || 'Export failed');
                }
                const blob = new Blob([result.data.content || ''], { type: 'text/csv;charset=utf-8' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = result.data.filename || 'visitor-analytics.csv';
                link.click();
                URL.revokeObjectURL(link.href);
            } catch (error) {
                if (window.Weline?.UI?.toast) {
                    showNotice({
                        icon: 'error',
                        title: '导出失败',
                        text: error.message,
                        confirmButtonText: '确定'
                    });
                } else {
                    console.error('导出失败: ' + error.message);
                }
            }
        }

        function initializePanel() {
            // 设置默认日期
            const today = new Date();
            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(today.getDate() - 30);
            document.getElementById('business-endDate').value = today.toISOString().split('T')[0];
            document.getElementById('business-startDate').value = thirtyDaysAgo.toISOString().split('T')[0];
            document.getElementById('report-endDate').value = today.toISOString().split('T')[0];
            document.getElementById('report-startDate').value = thirtyDaysAgo.toISOString().split('T')[0];
        }

        function destroyPanel() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            Object.values(charts).forEach((chart) => chart.destroy());
        }

        window.addEventListener('load', initializePanel, {once: true});
        window.addEventListener('beforeunload', destroyPanel, {once: true});
