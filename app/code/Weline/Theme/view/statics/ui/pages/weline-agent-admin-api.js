/* Weline UI source: js/agent-admin-api.js */
/**
 * Agent admin BinQuery helper — Weline.Api.resource('agent') only.
 */
(function (global) {
    'use strict';

    function toast(type, message) {
        if (global.BackendToast && typeof global.BackendToast[type] === 'function') {
            global.BackendToast[type](message);
            return;
        }
        if (global.BackendToast && typeof global.BackendToast.info === 'function') {
            global.BackendToast.info(message);
            return;
        }
        console.log('[agent-admin][' + type + ']', message);
    }

    function getAgentApi() {
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            return Promise.reject(new Error('Weline.Api.resource 不可用'));
        }
        return Promise.resolve(global.Weline.Api.resource('agent'));
    }

    /** Write ops that declare a single `payload` map in the QueryProvider descriptor. */
    var PAYLOAD_OPS = {
        saveRole: true,
        suggestRole: true,
        saveSchedule: true
    };

    function normalizeParams(operation, params) {
        var body = params && typeof params === 'object' ? params : {};
        if (!PAYLOAD_OPS[operation]) {
            return body;
        }
        if (Object.prototype.hasOwnProperty.call(body, 'payload') && body.payload && typeof body.payload === 'object') {
            return body;
        }
        return { payload: body };
    }

    function call(operation, params) {
        return getAgentApi().then(function (api) {
            if (!api || typeof api[operation] !== 'function') {
                throw new Error('智能体接口不可用：' + operation);
            }
            return api[operation](normalizeParams(operation, params));
        });
    }

    function unwrap(result) {
        if (result && typeof result === 'object' && Object.prototype.hasOwnProperty.call(result, 'data')
            && result.data && typeof result.data === 'object'
            && Object.prototype.hasOwnProperty.call(result.data, 'success')) {
            return result.data;
        }
        return result;
    }

    global.WelineAgentAdmin = {
        toast: toast,
        getAgentApi: getAgentApi,
        call: call,
        unwrap: unwrap,
        bindListingActions: function (root) {
            var scope = root && root.querySelectorAll ? root : document;
            scope.querySelectorAll('[data-agent-op]').forEach(function (btn) {
                if (btn.getAttribute('data-agent-bound') === '1') return;
                btn.setAttribute('data-agent-bound', '1');
                btn.addEventListener('click', function () {
                    var op = btn.getAttribute('data-agent-op') || '';
                    var id = parseInt(btn.getAttribute('data-agent-id') || '0', 10) || 0;
                    if (!op || id <= 0) return;
                    btn.disabled = true;
                    call(op, { id: id }).then(function (raw) {
                        var data = unwrap(raw);
                        if (!data || !data.success) {
                            throw new Error((data && data.msg) || '操作失败');
                        }
                        toast('success', data.msg || '操作成功');
                        window.location.reload();
                    }).catch(function (error) {
                        toast('error', error && error.message ? error.message : '操作失败');
                        btn.disabled = false;
                    });
                });
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            if (global.WelineAgentAdmin) global.WelineAgentAdmin.bindListingActions(document);
        });
    } else if (global.WelineAgentAdmin) {
        global.WelineAgentAdmin.bindListingActions(document);
    }
})(window);
