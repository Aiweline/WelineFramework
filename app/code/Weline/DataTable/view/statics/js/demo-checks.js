const root = document.querySelector('[data-w-datatable-demo]');
const output = root?.querySelector('[data-w-demo-check-results]');

const definitions = {
    basic: [
        ['表格组件已挂载', () => document.querySelectorAll('.w-datatable').length >= 5],
        ['表头已输出', () => document.querySelectorAll('.w-datatable thead th').length > 0],
        ['数据行已输出', () => document.querySelectorAll('.w-datatable tbody tr').length > 0],
        ['分页组件已输出', () => document.querySelectorAll('.w-pagination').length > 0],
        ['筛选表单已输出', () => document.querySelectorAll('[data-w-datatable-filter]').length > 0],
    ],
    join: [
        ['多表组件已挂载', () => document.querySelectorAll('.w-datatable').length >= 4],
        ['JOIN 结果已输出', () => document.querySelectorAll('.w-datatable tbody tr').length > 0],
        ['跨表筛选已输出', () => document.querySelectorAll('[data-w-datatable-filter] input, [data-w-datatable-filter] select').length > 0],
        ['分组表单已输出', () => document.querySelectorAll('fieldset').length >= 2],
        ['事务配置已输出', () => [...document.querySelectorAll('[data-w-config]')].some((element) => element.dataset.wConfig?.includes('transaction'))],
    ],
};

function render(items, evaluate) {
    if (!(output instanceof HTMLElement)) return;
    const list = document.createElement('ul');
    list.className = 'w-datatable-demo__results';
    for (const [label, test] of items) {
        const item = document.createElement('li');
        let passed = true;
        if (evaluate) {
            try { passed = Boolean(test()); } catch (_error) { passed = false; }
        }
        item.dataset.state = evaluate ? (passed ? 'passed' : 'failed') : 'idle';
        item.textContent = `${evaluate ? (passed ? '✓' : '✗') : '•'} ${label}`;
        list.append(item);
    }
    output.replaceChildren(list);
}

root?.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-w-demo-check]') : null;
    if (!(trigger instanceof HTMLButtonElement)) return;
    const items = definitions[root.dataset.wDatatableDemo] || [];
    render(items, trigger.dataset.wDemoCheck === 'run');
});

if (root && output) render(definitions[root.dataset.wDatatableDemo] || [], false);

function tableComponent(id) {
    const element = document.getElementById(`w-datatable-${id}`);
    return element ? window.Weline?.UI?.get(element, 'data-table') : null;
}

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element
        ? event.target.closest('[data-w-datatable-demo-action]')
        : null;
    if (!(trigger instanceof HTMLButtonElement)) return;
    const action = trigger.dataset.wDatatableDemoAction || '';
    const ids = action === 'refresh-cascade'
        ? ['demo-cascade-users', 'demo-cascade-orders']
        : (action === 'reload-performance' ? ['demo-performance-table'] : []);
    if (ids.length === 0) return;
    event.preventDefault();
    trigger.disabled = true;
    Promise.all(ids.map((id) => tableComponent(id)?.reload?.()))
        .finally(() => { trigger.disabled = false; });
});
