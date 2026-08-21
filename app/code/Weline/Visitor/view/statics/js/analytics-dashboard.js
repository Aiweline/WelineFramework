import {NativeChart} from './native-chart.js';

let valueChart = null;
let eventsChart = null;
let refreshTimer = null;
let clockTimer = null;
let visitorApiPromise = null;

function safeNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

function showError(error) {
    const message = error instanceof Error ? error.message : String(error || '未知错误');
    if (window.Weline?.UI?.toast) {
        window.Weline.UI.toast.error(`获取数据失败: ${message}`);
        return;
    }
    console.error('获取数据失败:', error);
}

function getVisitorApi() {
    if (window.Weline?.Api?.resource) {
        visitorApiPromise = Promise.resolve(window.Weline.Api.resource('visitor'));
        return visitorApiPromise;
    }
    if (visitorApiPromise) return visitorApiPromise;

    visitorApiPromise = new Promise((resolve, reject) => {
        let attempts = 0;
        const waitForApi = () => {
            attempts += 1;
            if (window.Weline?.Api?.resource) {
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

async function requestDashboard(params) {
    const api = await getVisitorApi();
    return api.analyticsDashboard(params);
}

function initCharts() {
    const valueCanvas = document.getElementById('value-chart');
    const eventsCanvas = document.getElementById('events-chart');
    if (!(valueCanvas instanceof HTMLCanvasElement) || !(eventsCanvas instanceof HTMLCanvasElement)) return;

    valueChart = new NativeChart(valueCanvas, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: '价值',
                data: [],
                borderColor: 'var(--weline-theme-visitor-dashboard-brand-start)',
                backgroundColor: 'var(--weline-theme-visitor-dashboard-brand-start-soft)',
            }],
        },
    });
    eventsChart = new NativeChart(eventsCanvas, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: '事件数',
                data: [],
                borderColor: 'var(--weline-theme-visitor-dashboard-brand-end)',
                backgroundColor: 'var(--weline-theme-visitor-dashboard-brand-end-soft)',
            }],
        },
    });
}

function updateCharts(data) {
    const points = Array.isArray(data?.data_points) ? data.data_points : [];
    const labels = points.map((point) => new Date(point.timestamp).toLocaleTimeString('zh-CN', {
        hour: '2-digit',
        minute: '2-digit',
    }));
    const values = points.map((point) => safeNumber(point.value));
    const events = points.map((point) => safeNumber(point.events));

    if (valueChart) {
        valueChart.data.labels = labels;
        valueChart.data.datasets[0].data = values;
        valueChart.update();
    }
    if (eventsChart) {
        eventsChart.data.labels = labels;
        eventsChart.data.datasets[0].data = events;
        eventsChart.update();
    }
}

function updateStats(data) {
    const current = data?.current_period;
    if (current) {
        document.getElementById('current-value').textContent = safeNumber(current.value).toLocaleString();
        document.getElementById('current-events').textContent = safeNumber(current.events).toLocaleString();
    }

    const changePercent = safeNumber(data?.change_percentage);
    const tone = changePercent >= 0 ? 'success' : 'danger';
    const changeElement = document.getElementById('change-percentage');
    const trendElement = document.getElementById('change-trend');
    changeElement.textContent = `${changePercent >= 0 ? '+' : ''}${changePercent.toFixed(2)}%`;
    changeElement.dataset.tone = tone;
    trendElement.textContent = changePercent >= 0 ? '↑ 上升' : '↓ 下降';
    trendElement.dataset.tone = tone;
    document.getElementById('current-change').textContent = '相比上一时段';
    document.getElementById('update-time').textContent = new Date().toLocaleTimeString('zh-CN');
}

async function fetchData() {
    const websiteId = document.getElementById('website-id').value;
    const params = {
        interval: document.getElementById('interval').value,
        hours: document.getElementById('hours').value,
    };
    if (websiteId) params.websiteId = websiteId;

    try {
        const result = await requestDashboard(params);
        if (result.code !== 200) throw new Error(result.msg || '请求失败');
        updateCharts(result.data || {});
        updateStats(result.data || {});
    } catch (error) {
        showError(error);
    }
}

function updateTime() {
    document.getElementById('current-time').textContent = new Date().toLocaleString('zh-CN');
}

function initializeDashboard() {
    initCharts();
    updateTime();
    fetchData();
    clockTimer = window.setInterval(updateTime, 1000);
    refreshTimer = window.setInterval(fetchData, 30000);
}

function destroyDashboard() {
    if (clockTimer) window.clearInterval(clockTimer);
    if (refreshTimer) window.clearInterval(refreshTimer);
    valueChart?.destroy();
    eventsChart?.destroy();
}

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element
        ? event.target.closest('[data-visitor-dashboard-refresh]')
        : null;
    if (!trigger) return;
    event.preventDefault();
    fetchData();
});

window.addEventListener('load', initializeDashboard, {once: true});
window.addEventListener('beforeunload', destroyDashboard, {once: true});
