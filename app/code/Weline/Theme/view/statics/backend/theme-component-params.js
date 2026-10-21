/**
 * 组件预览：参数保存初始化
 *
 * 由页面 [data-weline-load="themeComponentParams"] 异步加载，避免页面内联业务脚本。
 */
(function () {
    'use strict';

    // 后台 admin bridge：统一把主题配置请求收敛到 editorRequest（与其它主题配置页一致）。
    (function (g) {
        if (g.WelineThemeBinQuery) return;
        g.WelineThemeBinQuery = {
            request: function (url, options) {
                options = options || {};
                var method = String(options.method || 'GET').toUpperCase();
                var body = '';
                if (options.body != null) {
                    if (typeof options.body === 'string') {
                        body = options.body;
                    } else if (typeof FormData !== 'undefined' && options.body instanceof FormData) {
                        var p = new URLSearchParams();
                        options.body.forEach(function (v, k) {
                            if (!(typeof File !== 'undefined' && v instanceof File)) p.append(k, String(v));
                        });
                        body = p.toString();
                    } else {
                        try { body = JSON.stringify(options.body); } catch (e) { body = ''; }
                    }
                }
                var run = function (api) {
                    return api.resource('theme').editorRequest({ url: url, method: method, headers: options.headers || {}, body: body })
                        .then(function (data) {
                            var b = g.WelineApiBusiness || (g.Weline && g.Weline.ApiBusiness);
                            return (b && typeof b.wrapAdminBridgeResult === 'function') ? b.wrapAdminBridgeResult(data) : data;
                        });
                };
                if (g.Weline && g.Weline.load) return g.Weline.load('api').then(run);
                return Promise.resolve(run(g.Weline.Api));
            }
        };
    })(window);

    var TEXT = window.WelineThemeComponentParamsText || {
        saveSuccess: '保存成功',
        saveFail: '保存失败',
        retry: '保存失败，请重试'
    };

    function notify(type, message) {
        var ui = window.Weline && window.Weline.UI;
        if (ui && ui.toast && typeof ui.toast[type] === 'function') {
            ui.toast[type](message);
            return;
        }
        console[type === 'error' ? 'error' : 'log'](message);
    }

    function init() {
        var form = document.getElementById('componentParamsForm');
        if (!form || form.dataset.themeComponentParamsBound === '1') return;
        form.dataset.themeComponentParamsBound = '1';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = form.querySelector('button[type="submit"]');
            var originalText = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="w-spinner" data-size="sm" aria-hidden="true"></span>';
            }
            window.WelineThemeBinQuery.request(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
                .then(function (response) {
                    var data = (response && response.data !== undefined) ? response.data : response;
                    var ok = (response && (response.ok === true || response.success === true))
                        || (data && (data.status === 'success' || data.success === true || data.code === 200));
                    if (ok) {
                        notify('success', (data && (data.message || data.msg)) || TEXT.saveSuccess);
                        setTimeout(function () { location.reload(); }, 600);
                        return;
                    }
                    notify('error', (data && (data.message || data.msg)) || TEXT.saveFail);
                })
                .catch(function () {
                    notify('error', TEXT.retry);
                })
                .finally(function () {
                    if (!submitBtn) return;
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
        });

        document.addEventListener('click', function (event) {
            var resetButton = event.target.closest('[data-component-reset]');
            if (resetButton) {
                location.reload();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    window.WelineThemeComponentParams = { init: init };
})();
