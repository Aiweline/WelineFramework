const configElement = document.getElementById('w-datatable-demo-config');
const root = document.querySelector('[data-w-datatable-demo="index"], [data-w-datatable-admin]');
const status = root?.querySelector('[data-w-demo-status]');
let config = {};

try {
    config = JSON.parse(configElement?.textContent || '{}');
} catch (_error) {
    config = {};
}

function setStatus(tone, message, detail = '') {
    if (!(status instanceof HTMLElement)) return;
    const iconName = { success: 'check-circle', danger: 'x-circle', warning: 'warning', info: 'info' }[tone] || 'info';
    const copy = document.createElement('span');
    const messageNode = document.createElement('strong');
    messageNode.textContent = String(message || '');
    copy.append(messageNode);
    if (detail) {
        const detailNode = document.createElement('small');
        detailNode.textContent = String(detail);
        copy.append(document.createElement('br'), detailNode);
    }
    status.className = 'w-alert';
    status.dataset.tone = tone;
    status.replaceChildren(window.Weline.UI.icon.create(iconName, { size: 'sm' }), copy);
    status.hidden = false;
}

async function request(operation) {
    const provider = String(config.apiProvider || 'datatable');
    const api = await window.Weline?.Api?.resource?.(provider);
    if (!api || typeof api[operation] !== 'function') {
        throw new Error(`DataTable operation is unavailable: ${provider}.${operation}`);
    }
    const result = await api[operation]({}, {silent: true});
    if (result?.success === false || result?.error === true) throw new Error(result?.message || result?.msg || 'Request failed');
    return result || {};
}

async function run(action, trigger) {
    const clear = action === 'clear';
    if (clear && !await window.Weline.UI.dialog.confirm(config.clearConfirm || 'Confirm?')) return;
    trigger.disabled = true;
    setStatus('info', clear ? '正在清理测试数据…' : '正在初始化测试数据…');
    try {
        const result = await request(clear ? 'clearData' : 'initData');
        const counts = result?.data && !clear
            ? `用户 ${result.data.users ?? 0} · 产品 ${result.data.products ?? 0} · 订单 ${result.data.orders ?? 0}`
            : '';
        setStatus('success', result?.message || '操作完成', counts);
    } catch (error) {
        setStatus('danger', config.requestFailed || '请求失败', error instanceof Error ? error.message : String(error));
    } finally {
        trigger.disabled = false;
    }
}

root?.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-w-demo-action]') : null;
    if (!(trigger instanceof HTMLButtonElement)) return;
    run(trigger.dataset.wDemoAction || '', trigger);
});
