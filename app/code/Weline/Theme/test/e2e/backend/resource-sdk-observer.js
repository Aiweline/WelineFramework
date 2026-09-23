const { EventEmitter } = require('node:events');

// Observe the real SDK before the editor caches its theme resource. Every call,
// argument, result and rejection still goes through the original BinQuery client.
async function observeResourceSdk(page, { themeUrlIncludes = ['resource_refresh=1'] } = {}) {
    const events = new EventEmitter();
    await page.exposeBinding('__welineRecordResourceCall', (_source, record) => {
        console.log('RESOURCE_SDK_RESULT', JSON.stringify(record));
        const response = {
            operation: record.operation, provider: record.provider, params: record.params, error: record.error,
            url: () => record.params?.url || '',
            json: async () => {
                if (record.error) throw new Error(JSON.stringify(record.error));
                let result = record.result;
                if (result && result.data && result.success === undefined) result = result.data;
                return result;
            },
        };
        events.emit('response', response);
    });
    await page.addInitScript(({ themeUrlIncludes }) => {
        const wrappedApis = new WeakSet();
        const watched = new WeakSet();
        function wrapApi(api) {
            if (!api || typeof api.resource !== 'function' || wrappedApis.has(api)) return api;
            wrappedApis.add(api);
            const original = api.resource;
            api.resource = function (provider, ...args) {
                const resource = original.call(this, provider, ...args);
                return new Proxy(resource, {
                    get(target, operation) {
                        const method = Reflect.get(target, operation);
                        if (typeof method !== 'function' || !((provider === 'theme' && operation === 'editorRequest') || (provider === 'system_config' && operation === 'setScopedConfig'))) return method;
                        return function (params, ...options) {
                            if (provider === 'theme' && !themeUrlIncludes.some(part => String(params?.url || '').includes(part))) {
                                return method.call(target, params, ...options);
                            }
                            const record = { provider, operation, params: JSON.parse(JSON.stringify(params || {})) };
                            return Promise.resolve(method.call(target, params, ...options)).then(result => {
                                window.__welineRecordResourceCall({ ...record, result });
                                return result;
                            }, error => {
                                const detail = { message: error?.message, name: error?.name };
                                for (const key of Object.getOwnPropertyNames(error || {})) {
                                    if (key !== 'stack') { try { detail[key] = JSON.parse(JSON.stringify(error[key])); } catch (_) {} }
                                }
                                window.__welineRecordResourceCall({ ...record, error: detail });
                                throw error;
                            });
                        };
                    },
                });
            };
            return api;
        }
        function watchWeline(value) {
            if (!value || typeof value !== 'object' || watched.has(value)) return value;
            watched.add(value);
            let api = wrapApi(value.Api);
            Object.defineProperty(value, 'Api', { configurable: true, enumerable: true, get: () => api, set: next => { api = wrapApi(next); } });
            return value;
        }
        let weline = watchWeline(window.Weline);
        Object.defineProperty(window, 'Weline', { configurable: true, enumerable: true, get: () => weline, set: value => { weline = watchWeline(value); } });
    }, { themeUrlIncludes });
    return {
        on: (...args) => events.on(...args), off: (...args) => events.off(...args),
        waitForResponse(predicate, { timeout = 90000 } = {}) {
            return new Promise((resolve, reject) => {
                const listener = response => {
                    if (!predicate(response)) return;
                    clearTimeout(timer); events.off('response', listener); resolve(response);
                };
                const timer = setTimeout(() => { events.off('response', listener); reject(new Error('No matching real SDK result within ' + timeout + 'ms')); }, timeout);
                events.on('response', listener);
            });
        },
    };
}
module.exports = { observeResourceSdk };
