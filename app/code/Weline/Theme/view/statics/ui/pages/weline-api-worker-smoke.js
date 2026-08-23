/* Weline UI source: js/worker-smoke.js */
const output = document.querySelector('[data-worker-smoke-result]');
const report = { ok: false, checks: {}, errors: [], debug: {} };
const pass = (name, value) => { report.checks[name] = Boolean(value); };
const fail = (name, error) => {
    report.checks[name] = false;
    report.errors.push(`${name}: ${error instanceof Error ? error.message : String(error)}`);
};

try {
    report.debug.before_resource = {
        moduleFull: Boolean(window.WelineApiModule?.__full),
        moduleResourceType: typeof window.WelineApiModule?.resource,
        moduleKeys: window.WelineApiModule ? Object.keys(window.WelineApiModule).slice(0, 20) : [],
        welineApiResourceType: typeof window.Weline?.Api?.resource,
    };
    pass('api_loaded', Boolean(window.Weline?.Api));

    const cartApi = await window.Weline.Api.resource('cart');
    pass('reserved_then_hidden', cartApi.then === undefined);
    pass('reserved_constructor_hidden', cartApi.constructor === undefined);
    pass('reserved_proto_not_in_proxy', !('__proto__' in cartApi));

    const cartCount = await cartApi.count({});
    pass('resource_cart_count', cartCount?.data?.success === true);
    const graph = await window.Weline.Api.graph([
        { provider: 'cart', operation: 'count', params: {}, as: 'cartCount' },
    ]);
    pass('graph_cart_count', graph?.cartCount?.data?.success === true);

    try {
        await window.Weline.Api.request('/api/rest/v1/weshop/cart/add');
        pass('direct_request_rejected', false);
    } catch (_error) {
        pass('direct_request_rejected', true);
    }
    try {
        await window.Weline.Api.stream('cart.count');
        pass('stream_non_stream_operation_denied', false);
    } catch (error) {
        pass('stream_non_stream_operation_denied', error?.code === 'capability_denied');
    }
    report.ok = Object.keys(report.checks).length > 0 && Object.values(report.checks).every(Boolean);
} catch (error) {
    fail('smoke_runtime', error);
}

if (output) {
    output.dataset.state = report.ok ? 'pass' : 'fail';
    output.textContent = JSON.stringify(report, null, 2);
}
window.__WELINE_WORKER_SMOKE__ = report;
