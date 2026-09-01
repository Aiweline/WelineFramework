(function (window, document) {
    'use strict';

    var I18N_ADMIN_UI_VERSION = '20260830-module-status1';
    if (window.I18nAdminUI && window.I18nAdminUI.version === I18N_ADMIN_UI_VERSION) {
        return;
    }

    function confirmAction(message, type, options) {
        options = options || {};
        var dialogOptions = Object.assign({
            tone: type || options.tone || 'info',
        }, options);

        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
            && typeof window.Weline.UI.dialog.confirm === 'function') {
            return window.Weline.UI.dialog.confirm(String(message || ''), dialogOptions);
        }

        if (typeof window.BackendConfirm !== 'undefined' && BackendConfirm.show) {
            return BackendConfirm.show(String(message || ''), {
                title: dialogOptions.title,
                type: dialogOptions.tone || 'info',
                confirmText: dialogOptions.confirmLabel,
                cancelText: dialogOptions.cancelLabel,
            });
        }

        if (typeof window.Swal !== 'undefined' && Swal.fire) {
            return Swal.fire({
                title: dialogOptions.title || '',
                text: String(message || ''),
                icon: dialogOptions.tone === 'danger' ? 'warning' : (dialogOptions.tone || 'info'),
                showCancelButton: true,
                confirmButtonText: dialogOptions.confirmLabel
                    || (window.__WelineThemeConfig && window.__WelineThemeConfig.i18n && window.__WelineThemeConfig.i18n.confirm)
                    || 'OK',
                cancelButtonText: dialogOptions.cancelLabel
                    || (window.__WelineThemeConfig && window.__WelineThemeConfig.i18n && window.__WelineThemeConfig.i18n.cancel)
                    || 'Cancel'
            }).then(function (result) {
                return !!result.isConfirmed;
            });
        }

        console.warn('[Weline I18n] Confirmation component is unavailable; action cancelled.');
        return Promise.resolve(false);
    }

    function toast(type, message) {
        if (window.Weline && window.Weline.UI && window.Weline.UI.toast
            && typeof window.Weline.UI.toast[type] === 'function') {
            window.Weline.UI.toast[type](message);
            return;
        }

        if (typeof window.BackendToast !== 'undefined' && BackendToast[type]) {
            BackendToast[type](message);
            return;
        }

        if (typeof window.BackendToast !== 'undefined' && BackendToast.info) {
            BackendToast.info(message);
            return;
        }

        console[type === 'error' ? 'error' : 'log'](message);
    }

    function collectCheckedValues(selector) {
        return Array.prototype.slice.call(document.querySelectorAll(selector))
            .filter(function (checkbox) {
                return checkbox.checked;
            })
            .map(function (checkbox) {
                return checkbox.value;
            })
            .filter(Boolean);
    }

    function setHiddenValues(form, fieldName, values) {
        if (!form) {
            return;
        }

        form.querySelectorAll('input[name="' + fieldName + '"]').forEach(function (input) {
            input.remove();
        });

        values.forEach(function (value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = fieldName;
            input.value = value;
            form.appendChild(input);
        });
    }

    function assignNestedFormValue(target, key, value) {
        var path = String(key || '').replace(/\]/g, '').split('[').filter(Boolean);
        if (!path.length || value === undefined || value === null) {
            return;
        }

        var cursor = target;
        for (var i = 0; i < path.length - 1; i += 1) {
            var segment = path[i];
            if (!Object.prototype.hasOwnProperty.call(cursor, segment)
                || typeof cursor[segment] !== 'object'
                || cursor[segment] === null) {
                cursor[segment] = {};
            }
            cursor = cursor[segment];
        }

        var leaf = path[path.length - 1];
        if (Object.prototype.hasOwnProperty.call(cursor, leaf)) {
            cursor[leaf] = Array.isArray(cursor[leaf]) ? cursor[leaf].concat([value]) : [cursor[leaf], value];
            return;
        }

        cursor[leaf] = value;
    }

    function formPayload(form) {
        var payload = queryPayload(form.getAttribute('action') || '');
        new FormData(form).forEach(function (value, key) {
            if (typeof File !== 'undefined' && value instanceof File) {
                return;
            }
            // bin-query 走 Worker 鉴权，不需要表单 CSRF 字段（带上反而可能干扰排障）。
            if (key === 'csrf' || key === '_csrf' || key === 'form_key') {
                return;
            }
            assignNestedFormValue(payload, key, value);
        });
        return payload;
    }

    function queryPayload(url) {
        var payload = {};
        if (!url) {
            return payload;
        }

        var parsed = new URL(url, window.location.href);
        parsed.searchParams.forEach(function (value, key) {
            assignNestedFormValue(payload, key, value);
        });
        return payload;
    }

    function getQueryApi() {
        var candidates = [
            window.Weline && window.Weline.Api,
            window.WelineApiModule
        ];

        for (var index = 0; index < candidates.length; index += 1) {
            if (candidates[index] && typeof candidates[index].resource === 'function') {
                return candidates[index];
            }
        }

        return null;
    }

    function ensureQueryApi() {
        var apiModule = getQueryApi();
        if (apiModule) {
            return Promise.resolve(apiModule);
        }

        if (window.Weline && typeof window.Weline.load === 'function') {
            return Promise.resolve(window.Weline.load('api')).then(function () {
                var loaded = getQueryApi();
                if (!loaded) {
                    throw new Error('Weline.Api bin-query 不可用。');
                }
                return loaded;
            });
        }

        if (window.Weline && typeof window.Weline.loadModule === 'function') {
            return Promise.resolve(window.Weline.loadModule('api')).then(function () {
                var loaded = getQueryApi();
                if (!loaded && window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                    return window.Weline.Api;
                }
                if (!loaded) {
                    throw new Error('Weline.Api bin-query 不可用。');
                }
                return loaded;
            });
        }

        return Promise.reject(new Error('Weline.Api bin-query 不可用。'));
    }

    function resolveModuleMatrixUrl(matrix, root) {
        var workspace = (matrix && matrix.closest)
            ? matrix.closest('[data-ai-module-workspace]')
            : null;
        if (!workspace && root && root.querySelector) {
            workspace = root.querySelector('[data-ai-module-workspace]');
        }
        var explicit = (matrix && matrix.getAttribute('data-matrix-url'))
            || (workspace ? workspace.getAttribute('data-ai-module-matrix-url') : '')
            || '';
        if (explicit && explicit.indexOf('@backend-url') === -1) {
            return explicit;
        }

        // Derive from current AI translation page path — no dependency on Taglib attrs.
        try {
            var path = String(window.location.pathname || '').replace(/\/+$/, '');
            if (/\/ai-translation$/i.test(path)) {
                return path + '/module-locale-matrix';
            }
            var idx = path.toLowerCase().lastIndexOf('/ai-translation/');
            if (idx >= 0) {
                return path.slice(0, idx) + '/ai-translation/module-locale-matrix';
            }
        } catch (e) {
        }
        return '';
    }

    function requestMatrixViaFetch(moduleName, matrixUrl) {
        // Must bypass Backend worker fetch bridge — worker path can hang / exhaust DB pool.
        var fetchFn = (typeof window.WelineNativeFetch === 'function')
            ? window.WelineNativeFetch
            : (typeof fetch === 'function' ? fetch : null);
        if (!matrixUrl || !moduleName || !fetchFn) {
            return Promise.reject(new Error('原生 HTTP 不可用。'));
        }
        var url;
        try {
            url = new URL(matrixUrl, window.location.href);
        } catch (error) {
            return Promise.reject(error);
        }
        url.searchParams.set('module_name', moduleName);
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = null;
        if (controller) {
            timer = setTimeout(function () {
                try {
                    controller.abort();
                } catch (e) {
                }
            }, 20000);
        }
        return fetchFn(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
            __welineNativeFetch: true,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (timer) {
                clearTimeout(timer);
            }
            if (!response.ok) {
                throw new Error('加载语言矩阵失败（HTTP ' + response.status + '）');
            }
            return response.json();
        }).catch(function (error) {
            if (timer) {
                clearTimeout(timer);
            }
            if (error && error.name === 'AbortError') {
                throw new Error('加载语言矩阵超时，请收起后重试。');
            }
            throw error;
        });
    }

    function requestBinAction(action, payload) {
        return ensureQueryApi().then(function (apiModule) {
            return Promise.resolve(apiModule.resource('i18n_admin')).then(function (api) {
                if (!api || typeof api.action !== 'function') {
                    throw new Error('I18n bin-query 查询器不可用。');
                }
                return api.action({
                    action: action,
                    payload: payload || {}
                }, {silent: true});
            });
        });
    }

    function requestBinActionWithTimeout(action, payload, timeoutMs) {
        timeoutMs = timeoutMs || 45000;
        return new Promise(function (resolve, reject) {
            var settled = false;
            var timer = setTimeout(function () {
                if (settled) {
                    return;
                }
                settled = true;
                reject(new Error('加载超时，请收起后重试。'));
            }, timeoutMs);
            requestBinAction(action, payload).then(function (response) {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                resolve(response);
            }).catch(function (error) {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                reject(error);
            });
        });
    }

    function bindConfirmSubmit(scope) {
        (scope || document).addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            if (form.hasAttribute('data-async-action')) {
                return;
            }

            if (form.dataset.confirmed === '1') {
                form.dataset.confirmed = '0';
                return;
            }

            var message = form.getAttribute('data-confirm-message');
            if (!message) {
                return;
            }

            event.preventDefault();
            confirmAction(message, form.getAttribute('data-confirm-type')).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                form.dataset.confirmed = '1';
                form.submit();
            });
        }, true);
    }

    function setStateBadge(cell, label, tone) {
        if (!cell) {
            return;
        }

        var className = tone === 'success'
            ? 'badge bg-success-subtle text-success'
            : (tone === 'muted' ? 'badge bg-secondary-subtle text-secondary' : 'badge bg-primary-subtle text-primary');
        cell.innerHTML = '<span class="' + className + '">' + label + '</span>';
    }

    function markAsyncActionComplete(form, action) {
        var row = form.closest('tr');
        if (!row) {
            return;
        }

        var isLocale = action.indexOf('locale-') === 0 || action.indexOf('localization-') === 0;
        var activeCell = row.querySelector(form.getAttribute('data-async-active-selector') ||
            (isLocale ? '[data-locale-active-state]' : '[data-country-active-state]'));
        var installCell = row.querySelector(form.getAttribute('data-async-install-selector') ||
            (isLocale ? '[data-locale-install-state]' : '[data-country-install-state]'));
        var isActivation = action === 'locale-activate' || action === 'localization-activate' || action === 'country-activate';
        var isDeactivation = action === 'locale-deactivate' || action === 'localization-deactivate' || action === 'country-disable';
        var isUninstall = action === 'locale-uninstall' || action === 'localization-uninstall' || action === 'country-uninstall';

        var isInstall = action === 'locale-install' || action === 'localization-install' || action === 'country-install'
            || action === 'country-batch-install';
        if (isActivation) {
            setStateBadge(activeCell, form.getAttribute('data-async-complete-label') || '已激活', 'success');
        } else if (isDeactivation) {
            setStateBadge(activeCell, form.getAttribute('data-async-complete-label') || '未激活', 'muted');
        } else if (isUninstall) {
            setStateBadge(activeCell, form.getAttribute('data-async-active-label') || '未激活', 'muted');
            setStateBadge(installCell, form.getAttribute('data-async-install-label') || '未安装', 'muted');
        } else if (isInstall) {
            // 安装即默认激活：同步更新两列状态徽章。
            setStateBadge(installCell, '已安装', '');
            setStateBadge(activeCell, '已激活', 'success');
        } else {
            setStateBadge(installCell, form.getAttribute('data-async-complete-label') || '已安装', '');
        }

        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = button.getAttribute('data-async-complete-label') ||
                (isActivation ? '已激活' : (isDeactivation ? '已停用' : (isUninstall ? '已卸载' : (isInstall ? '已安装并激活' : '已安装'))));
            button.classList.remove('btn-outline-primary');
            button.classList.remove('btn-outline-success');
            button.classList.remove('btn-outline-warning');
            button.classList.remove('btn-outline-danger');
            button.classList.add('btn-outline-secondary');
        }
    }

    function unwrapResponse(response) {
        return response && response.success === undefined && response.data !== undefined
            ? response.data
            : response;
    }

    function requestHttpFormFallback(form) {
        var actionUrl = form.getAttribute('action') || '';
        if (!actionUrl) {
            return Promise.reject(new Error('缺少表单提交地址'));
        }

        // 不用 window.fetch：后台 Worker bridge 可能劫持 fetch，导致 Failed to fetch。
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open((form.getAttribute('method') || 'POST').toUpperCase(), actionUrl, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                var json = null;
                try {
                    json = JSON.parse(xhr.responseText || '{}');
                } catch (e) {
                    reject(new Error('HTTP ' + xhr.status));
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(json);
                    return;
                }
                reject(new Error((json && (json.message || json.msg)) || ('HTTP ' + xhr.status)));
            };
            xhr.onerror = function () {
                reject(new Error('网络请求失败，请刷新后重试'));
            };
            xhr.send(new FormData(form));
        });
    }

    function isAuthTransportError(error) {
        if (!error) {
            return false;
        }
        var code = String(error.code || error.reason || '');
        var status = Number(error.status || (error.response && error.response.status) || 0);
        var message = String(error.message || '');
        return code === 'auth_error'
            || code === 'backend_attestation_invalid'
            || code === 'backend_acl_denied'
            || status === 401
            || status === 403
            || message.indexOf('不满足操作授权') !== -1
            || message.indexOf('凭证已失效') !== -1;
    }

    function requestAsyncForm(form, options) {
        options = options || {};
        if (!(form instanceof HTMLFormElement)) {
            return Promise.reject(new Error('无效的表单。'));
        }
        if (form.dataset.asyncBusy === '1') {
            return Promise.resolve(null);
        }

        var confirmationMessage = options.message !== undefined
            ? options.message
            : (form.getAttribute('data-confirm-message') || '');
        var confirmPromise = options.confirmed || !confirmationMessage
            ? Promise.resolve(true)
            : confirmAction(
                confirmationMessage,
                options.type || form.getAttribute('data-confirm-type') || 'info'
            );

        return confirmPromise.then(function (confirmed) {
            if (!confirmed) {
                return null;
            }

            form.dataset.asyncBusy = '1';
            var submit = form.querySelector('button[type="submit"]');
            var defaultLabel = submit
                ? (submit.getAttribute('data-async-default-label') || submit.textContent || '提交')
                : '';
            if (submit) {
                submit.disabled = true;
                submit.textContent = submit.getAttribute('data-async-submit-label') || '处理中…';
            }

            var action = form.getAttribute('data-async-action') || '';
            function finishSuccess(response) {
                var payload = unwrapResponse(response);
                if (!payload || payload.success !== true) {
                    throw new Error((payload && (payload.message || payload.msg)) || '操作失败');
                }
                markAsyncActionComplete(form, form.getAttribute('data-async-action') || '');
                form.dataset.asyncBusy = '0';
                toast('success', payload.message || form.getAttribute('data-async-success-message') || '操作成功');
                if (typeof options.onSuccess === 'function') {
                    options.onSuccess(payload);
                }
                if (form.getAttribute('data-async-reload') === '1') {
                    var reloadUrl = form.getAttribute('data-async-reload-url') || '';
                    if (reloadUrl) {
                        window.location.href = reloadUrl;
                    } else {
                        try {
                            var next = new URL(window.location.href);
                            next.searchParams.delete('page');
                            window.location.href = next.toString();
                        } catch (e) {
                            window.location.reload();
                        }
                    }
                    return payload;
                }
                return payload;
            }

            // 模块写回：直接走后台 Cookie POST，避开 Worker bin-query 鉴权抖动（403 auth_error）。
            var preferHttp = form.hasAttribute('data-ai-module-writeback-form')
                || form.getAttribute('data-async-transport') === 'http';
            var submitPromise = preferHttp
                ? requestHttpFormFallback(form)
                : requestBinAction(action, formPayload(form)).catch(function (error) {
                    if (!isAuthTransportError(error)) {
                        throw error;
                    }
                    return requestHttpFormFallback(form);
                });

            return submitPromise.then(finishSuccess).catch(function (error) {
                form.dataset.asyncBusy = '0';
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = defaultLabel;
                }
                throw error;
            });
        });
    }

    function bindAsyncForms(scope) {
        (scope || document).addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-async-action')) {
                return;
            }

            event.preventDefault();
            requestAsyncForm(form).catch(function (error) {
                toast('error', error && error.message ? error.message : '操作失败，请重试');
            });
        }, true);
    }

    function bindConfirmLinks(scope) {
        (scope || document).addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-confirm-href]');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            confirmAction(trigger.getAttribute('data-confirm-message') || '', trigger.getAttribute('data-confirm-type')).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                window.location.href = trigger.getAttribute('data-confirm-href');
            });
        });
    }

    function bindAsyncLinks(scope) {
        (scope || document).addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-async-href]');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            if (trigger.dataset.asyncBusy === '1') {
                return;
            }

            var message = trigger.getAttribute('data-confirm-message') || '';
            var confirmation = message
                ? confirmAction(message, trigger.getAttribute('data-confirm-type') || 'info')
                : Promise.resolve(true);
            confirmation.then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                trigger.dataset.asyncBusy = '1';
                trigger.classList.add('disabled');
                var action = trigger.getAttribute('data-async-action') || '';
                return requestBinAction(action, queryPayload(trigger.getAttribute('data-async-href'))).then(function (response) {
                    var payload = unwrapResponse(response);
                    if (!payload || payload.success !== true) {
                        throw new Error((payload && (payload.message || payload.msg)) || '操作失败');
                    }
                    if (trigger.getAttribute('data-async-remove-row') === '1') {
                        var row = trigger.closest('tr');
                        if (row) {
                            row.remove();
                        }
                    }
                    trigger.dataset.asyncBusy = '0';
                    toast('success', payload.message || trigger.getAttribute('data-async-success-message') || '操作成功');
                });
            }).catch(function (error) {
                trigger.dataset.asyncBusy = '0';
                trigger.classList.remove('disabled');
                toast('error', error && error.message ? error.message : '操作失败，请重试');
            });
        });
    }

    function bindSelectAll(selectAllSelector, itemSelector, countSelector) {
        var selectAll = document.querySelector(selectAllSelector);
        var items = function () {
            return Array.prototype.slice.call(document.querySelectorAll(itemSelector));
        };

        function updateCount() {
            var count = items().filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            document.querySelectorAll(countSelector || '[data-selection-count]').forEach(function (node) {
                node.textContent = String(count);
            });

            if (selectAll) {
                var total = items().length;
                selectAll.checked = total > 0 && count === total;
                selectAll.indeterminate = count > 0 && count < total;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                items().forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
                updateCount();
            });
        }

        items().forEach(function (checkbox) {
            checkbox.addEventListener('change', updateCount);
        });

        updateCount();
    }

    function bindAiExportDialog(scope) {
        var root = scope || document;
        root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-ai-export-open]');
            if (!trigger || trigger.disabled) {
                return;
            }

            var localeInput = document.getElementById('ai-export-locale-code');
            var moduleSelect = document.getElementById('ai-export-module-name');
            var hint = document.getElementById('ai-export-module-hint');
            if (!localeInput || !moduleSelect) {
                return;
            }

            var localeCode = trigger.getAttribute('data-locale-code') || '';
            localeInput.value = localeCode;

            var modules = [];
            try {
                modules = JSON.parse(trigger.getAttribute('data-export-modules') || '[]');
            } catch (error) {
                modules = [];
            }
            if (!Array.isArray(modules)) {
                modules = [];
            }

            var allLabel = moduleSelect.getAttribute('data-all-label')
                || (moduleSelect.options[0] && moduleSelect.options[0].textContent)
                || '全部模块（按来源模块分别写回）';
            moduleSelect.innerHTML = '';
            var allOption = document.createElement('option');
            allOption.value = '*';
            allOption.textContent = allLabel;
            moduleSelect.appendChild(allOption);

            modules.forEach(function (item) {
                if (!item || typeof item !== 'object') {
                    return;
                }
                var moduleName = String(item.module || '').trim();
                if (!moduleName) {
                    return;
                }
                var option = document.createElement('option');
                option.value = moduleName;
                var count = Number(item.count || 0);
                option.textContent = count > 0
                    ? (moduleName + ' (' + count + ')')
                    : moduleName;
                moduleSelect.appendChild(option);
            });

            if (hint) {
                var withModules = moduleSelect.getAttribute('data-hint-with-modules')
                    || '当前语言 %{1} 共有 %{2} 个来源模块含 AI 译文；选全部会分别写回这些模块。';
                var emptyHint = moduleSelect.getAttribute('data-hint-empty')
                    || '当前语言 %{1} 暂无带来源模块的 AI 译文可导出。';
                hint.textContent = modules.length
                    ? withModules.replace('%{1}', localeCode).replace('%{2}', String(modules.length))
                    : emptyHint.replace('%{1}', localeCode);
            }
        }, true);
    }

    function bindAiModuleWorkspace(scope) {
        var root = scope || document;
        if (root.__i18nAiModuleWorkspaceBound) {
            return;
        }

        // Script may load before the modules workspace markup exists.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                bindAiModuleWorkspace(root);
            });
            return;
        }

        if (!root.querySelector || !root.querySelector('[data-ai-module-workspace]')) {
            return;
        }

        root.__i18nAiModuleWorkspaceBound = true;

        function syncLocaleInputs(form) {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            var moduleName = form.getAttribute('data-module-name') || '';
            var box = form.querySelector('[data-ai-module-locale-inputs]');
            if (!box) {
                return;
            }
            box.innerHTML = '';
            var checks = root.querySelectorAll(
                '[data-ai-module-locale-check][data-module-name="' + moduleName.replace(/"/g, '\\"') + '"]'
            );
            Array.prototype.forEach.call(checks, function (checkbox) {
                if (!checkbox.checked || !checkbox.value) {
                    return;
                }
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'locale_codes[]';
                input.value = checkbox.value;
                box.appendChild(input);
            });
        }

        root.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ai-module-writeback-form')) {
                return;
            }
            // 接管写回：走 SSE 进度，避免 bindAsyncForms 的同步 XHR「处理中…」无明细。
            event.preventDefault();
            event.stopImmediatePropagation();
            syncLocaleInputs(form);
            startModuleWritebackSse(form);
        }, true);

        function closeWritebackStream(matrix) {
            if (!matrix) {
                return;
            }
            var es = matrix.__writebackEs;
            if (es) {
                try {
                    es.close();
                } catch (e) {
                }
                matrix.__writebackEs = null;
            }
        }

        function findMatrixForWritebackForm(form) {
            var moduleName = form.getAttribute('data-module-name') || '';
            if (!moduleName) {
                return null;
            }
            try {
                return root.querySelector(
                    '[data-ai-module-matrix][data-module-name="' + String(moduleName).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]'
                );
            } catch (e) {
                return null;
            }
        }

        function setLocaleRowStatus(matrix, localeCode, label, tone) {
            if (!matrix || !localeCode) {
                return;
            }
            var tbody = matrix.querySelector('[data-ai-module-matrix-tbody]')
                || matrix.querySelector('[data-ai-module-matrix-body] tbody');
            if (!tbody) {
                return;
            }
            var row = null;
            try {
                row = tbody.querySelector(
                    'tr[data-locale-code="' + String(localeCode).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]'
                );
            } catch (e) {
                row = null;
            }
            if (!row) {
                return;
            }
            var badge = row.querySelector('.w-badge');
            if (!badge) {
                return;
            }
            badge.textContent = label || '';
            badge.setAttribute('data-w-background', tone || 'info');
        }

        function parseSseBuffer(buffer, onEvent) {
            var parts = String(buffer || '').split('\n\n');
            var rest = parts.pop() || '';
            parts.forEach(function (block) {
                if (!block || block.charAt(0) === ':') {
                    return;
                }
                var eventName = 'message';
                var dataLines = [];
                String(block).split('\n').forEach(function (line) {
                    if (line.indexOf('event:') === 0) {
                        eventName = line.slice(6).trim() || 'message';
                    } else if (line.indexOf('data:') === 0) {
                        dataLines.push(line.slice(5).replace(/^ /, ''));
                    }
                });
                if (!dataLines.length) {
                    return;
                }
                var raw = dataLines.join('\n');
                var data = null;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    data = { message: raw };
                }
                onEvent(eventName, data);
            });
            return rest;
        }

        function dismissStaleDialogs(returnValue) {
            document.querySelectorAll('dialog.w-dialog').forEach(function (dialog) {
                if (dialog.getAttribute('data-ai-writeback-progress') === '1') {
                    return;
                }
                try {
                    if (dialog.open) {
                        dialog.close(returnValue || 'dismiss');
                    }
                } catch (e) {
                }
                try {
                    dialog.remove();
                } catch (e) {
                }
            });
        }

        /**
         * Theme dialog shell for module writeback SSE progress.
         * @returns {{
         *   open: Function,
         *   close: Function,
         *   setChip: Function,
         *   applyEvent: Function,
         *   setFinished: Function,
         *   bindAbort: Function,
         *   el: HTMLElement
         * }}
         */
        function createWritebackProgressUi(moduleName, matrix) {
            var NODES = [
                { id: 'collect', label: '收集源语言' },
                { id: 'translate', label: '翻译语言' },
                { id: 'writeback', label: '写回 CSV' },
                { id: 'verify', label: '校验对齐' },
                { id: 'finish', label: '完成' }
            ];
            var nodeState = {
                collect: 'pending',
                translate: 'pending',
                writeback: 'pending',
                verify: 'pending',
                finish: 'pending'
            };
            var locales = {};
            var percent = 0;
            var abortHandler = null;
            var finished = false;

            var chip = null;
            if (matrix) {
                chip = matrix.querySelector('[data-ai-writeback-chip]');
                if (!chip) {
                    var toolbar = matrix.querySelector('.w-card__header .w-cluster') || matrix.querySelector('.w-card__header');
                    chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'w-button';
                    chip.setAttribute('data-tone', 'neutral');
                    chip.setAttribute('data-variant', 'outline');
                    chip.setAttribute('data-ai-writeback-chip', '1');
                    chip.hidden = true;
                    chip.textContent = '查看进度';
                    if (toolbar) {
                        toolbar.appendChild(chip);
                    }
                }
            }

            var dialog = document.createElement('dialog');
            dialog.className = 'w-dialog';
            dialog.dataset.wComponent = 'dialog';
            dialog.dataset.size = 'lg';
            dialog.dataset.tone = 'info';
            dialog.setAttribute('data-ai-writeback-progress', '1');
            dialog.innerHTML = ''
                + '<header class="w-dialog__header"><div class="w-cluster"><h2 class="w-dialog__title">模块写回进度</h2></div></header>'
                + '<div class="w-dialog__body w-stack" style="--w-gap:var(--weline-space-4);">'
                +   '<div class="w-text" data-weight="strong" data-ai-wb-module></div>'
                +   '<div class="w-cluster" data-wrap="true" data-ai-wb-nodes style="--w-gap:var(--weline-space-2);"></div>'
                +   '<div class="w-progress"><div class="w-progress__bar" data-ai-wb-bar role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="--w-progress:0%"></div></div>'
                +   '<div class="w-text" data-size="sm" data-ai-wb-message data-tone="muted"></div>'
                +   '<div class="w-table-wrap"><table class="w-table" data-w-vertical="middle" style="--w-mb:0;"><thead><tr>'
                +     '<th>语言</th><th>状态</th><th>翻译</th><th>写回</th><th>差额</th>'
                +   '</tr></thead><tbody data-ai-wb-locales></tbody></table></div>'
                +   '<details><summary class="w-text" data-size="sm">事件流</summary>'
                +     '<div class="w-stack" data-ai-wb-log style="--w-gap:var(--weline-space-1);max-height:10rem;overflow:auto;"></div>'
                +   '</details>'
                + '</div>'
                + '<footer class="w-dialog__footer w-cluster" data-justify="end" style="--w-gap:var(--weline-space-2);">'
                +   '<button type="button" class="w-button" data-tone="neutral" data-ai-wb-background>后台运行</button>'
                +   '<button type="button" class="w-button" data-tone="danger" data-variant="outline" data-ai-wb-cancel>取消</button>'
                + '</footer>';

            var moduleEl = dialog.querySelector('[data-ai-wb-module]');
            var nodesEl = dialog.querySelector('[data-ai-wb-nodes]');
            var barEl = dialog.querySelector('[data-ai-wb-bar]');
            var messageEl = dialog.querySelector('[data-ai-wb-message]');
            var localesEl = dialog.querySelector('[data-ai-wb-locales]');
            var logEl = dialog.querySelector('[data-ai-wb-log]');
            var btnBg = dialog.querySelector('[data-ai-wb-background]');
            var btnCancel = dialog.querySelector('[data-ai-wb-cancel]');

            if (moduleEl) {
                moduleEl.textContent = String(moduleName || '');
            }

            NODES.forEach(function (node, index) {
                if (index > 0) {
                    var arrow = document.createElement('span');
                    arrow.className = 'w-text';
                    arrow.setAttribute('data-tone', 'muted');
                    arrow.textContent = '→';
                    nodesEl.appendChild(arrow);
                }
                var badge = document.createElement('span');
                badge.className = 'w-badge';
                badge.setAttribute('data-ai-wb-node', node.id);
                badge.setAttribute('data-w-background', 'muted');
                badge.textContent = node.label;
                nodesEl.appendChild(badge);
            });

            function renderNodes() {
                NODES.forEach(function (node) {
                    var badge = nodesEl.querySelector('[data-ai-wb-node="' + node.id + '"]');
                    if (!badge) {
                        return;
                    }
                    var state = nodeState[node.id] || 'pending';
                    var tone = 'muted';
                    if (state === 'active') {
                        tone = 'info';
                    } else if (state === 'done') {
                        tone = 'success';
                    } else if (state === 'error') {
                        tone = 'danger';
                    }
                    badge.setAttribute('data-w-background', tone);
                });
            }

            function setNode(activeId) {
                var order = NODES.map(function (n) { return n.id; });
                var activeIndex = order.indexOf(activeId);
                order.forEach(function (id, index) {
                    if (nodeState[id] === 'error') {
                        return;
                    }
                    if (activeIndex < 0) {
                        return;
                    }
                    if (index < activeIndex) {
                        nodeState[id] = 'done';
                    } else if (index === activeIndex) {
                        nodeState[id] = finished && id === 'finish' ? 'done' : 'active';
                    }
                });
                renderNodes();
            }

            function setPercent(value) {
                percent = Math.max(0, Math.min(100, Number(value) || 0));
                if (barEl) {
                    barEl.style.setProperty('--w-progress', percent + '%');
                    barEl.setAttribute('aria-valuenow', String(Math.round(percent)));
                }
            }

            function computePercent(data) {
                var stage = String((data && data.stage) || '');
                var node = String((data && data.node) || '');
                if (stage === 'sync' || node === 'collect') {
                    return 8;
                }
                if (stage === 'done' || node === 'finish') {
                    return 100;
                }
                if (stage === 'verify' || node === 'verify') {
                    var localesTotalV = Math.max(1, Number((data && data.locales_total) || Object.keys(locales).length || 1));
                    var localeIndexV = Math.max(0, Number((data && data.locale_index) || 0));
                    return 85 + Math.min(14, (localeIndexV / localesTotalV) * 14);
                }
                var localesTotal = Math.max(1, Number((data && data.locales_total) || Object.keys(locales).length || 1));
                var localeIndex = Math.max(0, Number((data && data.locale_index) || 0) - 1);
                var remaining = Number((data && data.remaining));
                var translatedTotal = Number((data && data.translated_total) || 0);
                var within = 0.5;
                if (Number.isFinite(remaining) && remaining >= 0 && (remaining + translatedTotal) > 0) {
                    within = translatedTotal / (remaining + translatedTotal);
                }
                if (stage === 'writeback' || node === 'writeback') {
                    within = 0.95;
                }
                var translateShare = ((localeIndex + within) / localesTotal) * 70;
                return 10 + translateShare;
            }

            function setChip(text, tone) {
                if (!chip) {
                    return;
                }
                chip.hidden = false;
                chip.textContent = text || '查看进度';
                if (tone) {
                    chip.setAttribute('data-tone', tone);
                }
            }

            function appendLog(message) {
                if (!logEl || !message) {
                    return;
                }
                var row = document.createElement('div');
                row.className = 'w-text';
                row.setAttribute('data-size', 'sm');
                row.setAttribute('data-tone', 'muted');
                var time = new Date();
                var hh = String(time.getHours()).padStart(2, '0');
                var mm = String(time.getMinutes()).padStart(2, '0');
                var ss = String(time.getSeconds()).padStart(2, '0');
                row.textContent = hh + ':' + mm + ':' + ss + ' · ' + String(message);
                logEl.appendChild(row);
                logEl.scrollTop = logEl.scrollHeight;
            }

            function upsertLocale(data) {
                var code = String((data && data.locale) || '');
                if (!code || !localesEl) {
                    return;
                }
                var name = String((data && data.locale_name) || (data && data.name) || code);
                var stage = String((data && data.stage) || '');
                var statusHint = String((data && data.status) || '');
                var entry = locales[code] || {
                    code: code,
                    name: name,
                    status: '排队',
                    translated: 0,
                    exported: 0,
                    gap: 0
                };
                entry.name = name || entry.name;
                if (data.translated_total != null) {
                    entry.translated = Number(data.translated_total) || entry.translated;
                }
                if (data.translated != null) {
                    entry.translated = Number(data.translated) || entry.translated;
                }
                if (data.exported != null) {
                    entry.exported = Number(data.exported) || entry.exported;
                }
                if (data.gap != null) {
                    entry.gap = Number(data.gap) || 0;
                } else if (data.remaining != null && (stage === 'verify' || stage === 'writeback')) {
                    entry.gap = Number(data.remaining) || entry.gap;
                }
                if (statusHint === 'failed' || data.verified === false) {
                    entry.status = '未对齐';
                } else if (statusHint === 'aligned') {
                    entry.status = '已对齐';
                } else if (statusHint === 'completed') {
                    entry.status = '已完成';
                } else if (stage === 'verify') {
                    entry.status = '校验中';
                } else if (stage === 'writeback') {
                    entry.status = '写回中';
                } else if (stage === 'translate') {
                    entry.status = '翻译中';
                    if (data.round) {
                        entry.status += ' #' + String(data.round);
                    }
                }
                locales[code] = entry;

                var row = localesEl.querySelector('tr[data-locale="' + code.replace(/"/g, '') + '"]');
                if (!row) {
                    row = document.createElement('tr');
                    row.setAttribute('data-locale', code);
                    row.innerHTML = '<td></td><td></td><td></td><td></td><td></td>';
                    localesEl.appendChild(row);
                }
                var cells = row.querySelectorAll('td');
                if (cells[0]) {
                    cells[0].textContent = entry.name + ' (' + entry.code + ')';
                }
                if (cells[1]) {
                    cells[1].textContent = entry.status;
                }
                if (cells[2]) {
                    cells[2].textContent = String(entry.translated);
                }
                if (cells[3]) {
                    cells[3].textContent = String(entry.exported);
                }
                if (cells[4]) {
                    cells[4].textContent = String(entry.gap);
                }
            }

            function markLocaleDone(data) {
                var code = String((data && data.locale) || '');
                if (!code) {
                    return;
                }
                upsertLocale(data);
                if (locales[code]) {
                    var status = String((data && data.status) || '');
                    if (status === 'failed' || data.verified === false) {
                        locales[code].status = '未对齐';
                    } else if (status === 'aligned') {
                        locales[code].status = '已对齐';
                    } else {
                        locales[code].status = '已完成';
                    }
                    locales[code].exported = Number(data.exported || locales[code].exported || 0);
                    locales[code].translated = Number(data.translated || locales[code].translated || 0);
                    locales[code].gap = Number(data.gap != null ? data.gap : (data.remaining || locales[code].gap || 0));
                    var row = localesEl.querySelector('tr[data-locale="' + code.replace(/"/g, '') + '"]');
                    if (row) {
                        var cells = row.querySelectorAll('td');
                        if (cells[1]) {
                            cells[1].textContent = locales[code].status;
                        }
                        if (cells[2]) {
                            cells[2].textContent = String(locales[code].translated);
                        }
                        if (cells[3]) {
                            cells[3].textContent = String(locales[code].exported);
                        }
                        if (cells[4]) {
                            cells[4].textContent = String(locales[code].gap);
                        }
                    }
                }
            }

            function applyEvent(eventName, data) {
                data = data || {};
                var node = String(data.node || '');
                var stage = String(data.stage || '');
                if (!node) {
                    if (stage === 'sync') {
                        node = 'collect';
                    } else if (stage === 'translate') {
                        node = 'translate';
                    } else if (stage === 'writeback') {
                        node = 'writeback';
                    } else if (stage === 'verify') {
                        node = 'verify';
                    } else if (stage === 'done') {
                        node = 'finish';
                    }
                }
                if (eventName === 'source') {
                    node = 'collect';
                }
                if (node) {
                    setNode(node);
                }
                if (data.message) {
                    if (messageEl) {
                        messageEl.textContent = String(data.message);
                    }
                    appendLog(data.message);
                    setChip(String(data.message).slice(0, 36), 'info');
                }
                setPercent(computePercent(Object.assign({}, data, { node: node })));
                if (eventName === 'locale_done') {
                    markLocaleDone(data);
                } else if (data.locale) {
                    upsertLocale(data);
                }
            }

            function open() {
                if (!dialog.isConnected) {
                    document.body.appendChild(dialog);
                }
                try {
                    if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.open === 'function') {
                        window.Weline.UI.mount && window.Weline.UI.mount(dialog);
                        window.Weline.UI.dialog.open(dialog);
                    } else if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', '');
                    }
                } catch (e) {
                    dialog.setAttribute('open', '');
                }
                setChip('处理中…', 'info');
            }

            function close() {
                try {
                    if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.close === 'function') {
                        window.Weline.UI.dialog.close(dialog, 'dismiss');
                    } else if (dialog.open) {
                        dialog.close('dismiss');
                    }
                } catch (e) {
                }
            }

            function setFinished(ok, message) {
                finished = true;
                if (ok) {
                    nodeState.collect = 'done';
                    nodeState.translate = 'done';
                    nodeState.writeback = 'done';
                    nodeState.verify = 'done';
                    nodeState.finish = 'done';
                    setPercent(100);
                    setChip(message ? String(message).slice(0, 40) : '已完成', 'success');
                } else {
                    Object.keys(nodeState).forEach(function (id) {
                        if (nodeState[id] === 'active') {
                            nodeState[id] = 'error';
                        }
                    });
                    if (nodeState.verify === 'pending' || nodeState.verify === 'active') {
                        nodeState.verify = 'error';
                    }
                    setChip(message ? String(message).slice(0, 40) : '失败', 'danger');
                }
                renderNodes();
                if (messageEl && message) {
                    messageEl.textContent = String(message);
                }
                if (btnCancel) {
                    btnCancel.textContent = '关闭';
                    btnCancel.setAttribute('data-tone', 'neutral');
                }
            }

            if (chip) {
                chip.addEventListener('click', function () {
                    open();
                });
            }
            if (btnBg) {
                btnBg.addEventListener('click', function () {
                    close();
                });
            }
            if (btnCancel) {
                btnCancel.addEventListener('click', function () {
                    if (finished) {
                        close();
                        return;
                    }
                    if (typeof abortHandler === 'function') {
                        abortHandler();
                    }
                });
            }

            renderNodes();

            return {
                el: dialog,
                open: open,
                close: close,
                setChip: setChip,
                applyEvent: applyEvent,
                setFinished: setFinished,
                bindAbort: function (fn) {
                    abortHandler = fn;
                }
            };
        }

        function confirmWriteback(message) {
            dismissStaleDialogs();
            return new Promise(function (resolve) {
                var settled = false;
                function done(confirmed) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    document.removeEventListener('click', onDocClick, true);
                    dismissStaleDialogs(confirmed ? 'confirm' : 'cancel');
                    resolve(!!confirmed);
                }

                function onDocClick(event) {
                    var target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }
                    var dialog = target.closest('dialog.w-dialog');
                    if (!dialog || !dialog.open) {
                        return;
                    }
                    var button = target.closest('button');
                    if (!button || !dialog.contains(button)) {
                        return;
                    }
                    var text = String(button.textContent || '').trim();
                    if (/^(确定|Confirm|OK)$/i.test(text)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        done(true);
                        return;
                    }
                    if (/^(取消|Cancel)$/i.test(text)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        done(false);
                    }
                }

                document.addEventListener('click', onDocClick, true);

                confirmAction(message, 'info').then(function (result) {
                    if (result && typeof result === 'object' && Object.prototype.hasOwnProperty.call(result, 'confirmed')) {
                        done(!!result.confirmed);
                        return;
                    }
                    done(!!result);
                }).catch(function () {
                    done(window.confirm(String(message || '')));
                });
            });
        }

        function startModuleWritebackSse(form) {
            if (!(form instanceof HTMLFormElement) || form.dataset.asyncBusy === '1') {
                return;
            }
            // 确认 Promise 曾挂死时会留下 confirming=1，挡住后续点击；无框且非忙则回收。
            if (form.dataset.confirming === '1') {
                if (document.querySelector('dialog.w-dialog[open]')) {
                    return;
                }
                form.dataset.confirming = '0';
            }
            var confirmationMessage = form.getAttribute('data-confirm-message') || '';
            form.dataset.confirming = '1';
            var confirmPromise = confirmationMessage
                ? confirmWriteback(confirmationMessage)
                : Promise.resolve(true);

            confirmPromise.then(function (confirmed) {
                form.dataset.confirming = '0';
                if (!confirmed) {
                    return;
                }

                try {
                    var actionUrl = form.getAttribute('action') || '';
                    if (!actionUrl) {
                        toast('error', '缺少写回地址');
                        return;
                    }

                    var moduleName = form.getAttribute('data-module-name')
                        || (form.querySelector('input[name="module_name"]') || {}).value
                        || '';
                    var matrix = findMatrixForWritebackForm(form);
                    var hintEl = matrix ? matrix.querySelector('[data-ai-module-matrix-hint]') : null;
                    var submit = form.querySelector('button[type="submit"]');
                    var defaultLabel = submit
                        ? (submit.getAttribute('data-async-default-label') || submit.textContent || '一键翻译并写回')
                        : '';
                    var busyLabel = submit
                        ? (submit.getAttribute('data-async-submit-label') || '处理中…')
                        : '处理中…';

                    var progressUi = createWritebackProgressUi(moduleName, matrix);
                    progressUi.open();

                    form.dataset.asyncBusy = '1';
                    if (submit) {
                        submit.disabled = true;
                        submit.textContent = busyLabel;
                    }
                    if (hintEl) {
                        hintEl.textContent = busyLabel;
                    }

                    if (matrix) {
                        matrix.__writebackBusy = 1;
                        matrix.__writebackProgressUi = progressUi;
                        closeWritebackStream(matrix);
                        closeMatrixStream(matrix, { keepWriteback: true });
                    } else {
                        closeWritebackStream(matrix);
                    }

                    var settled = false;
                    var userAborted = false;
                    var reconnectAttempt = 0;
                    var reconnectTimer = null;
                    var sseBuffer = '';
                    var seenBytes = 0;
                    var xhr = null;

                    function clearWritebackReconnect() {
                        if (!reconnectTimer) {
                            return;
                        }
                        try {
                            clearTimeout(reconnectTimer);
                        } catch (e) {
                        }
                        reconnectTimer = null;
                    }

                    function bindWritebackHandle() {
                        if (!matrix) {
                            return;
                        }
                        matrix.__writebackEs = {
                            close: function () {
                                userAborted = true;
                                clearWritebackReconnect();
                                try {
                                    if (xhr) {
                                        xhr.abort();
                                    }
                                } catch (e) {
                                }
                            }
                        };
                    }

                    progressUi.bindAbort(function () {
                        userAborted = true;
                        clearWritebackReconnect();
                        try {
                            if (xhr) {
                                xhr.abort();
                            }
                        } catch (e) {
                        }
                        progressUi.setFinished(false, '已取消');
                        progressUi.close();
                    });

                    function currentHintEl() {
                        if (matrix) {
                            return matrix.querySelector('[data-ai-module-matrix-hint]') || hintEl;
                        }
                        return hintEl;
                    }

                    function stageButtonLabel(data) {
                        var stage = String((data && data.stage) || '');
                        var localeName = String((data && data.locale_name) || (data && data.locale) || '');
                        var round = Number((data && data.round) || 0);
                        if (stage === 'sync') {
                            return '收集中…';
                        }
                        if (stage === 'translate') {
                            if (localeName && round > 0) {
                                return '翻译 ' + localeName + ' #' + round;
                            }
                            if (localeName) {
                                return '翻译 ' + localeName;
                            }
                            return '翻译中…';
                        }
                        if (stage === 'writeback') {
                            return localeName ? ('写回 ' + localeName) : '写回中…';
                        }
                        if (stage === 'verify') {
                            return localeName ? ('校验 ' + localeName) : '校验中…';
                        }
                        return busyLabel;
                    }

                    function finish(ok, message) {
                        if (settled) {
                            return;
                        }
                        settled = true;
                        clearWritebackReconnect();
                        if (matrix) {
                            matrix.__writebackBusy = 0;
                        }
                        closeWritebackStream(matrix);
                        form.dataset.asyncBusy = '0';
                        if (submit) {
                            submit.disabled = false;
                            submit.textContent = defaultLabel;
                        }
                        progressUi.setFinished(ok, message || (ok ? '操作完成' : '操作失败'));
                        if (ok) {
                            toast('success', message || '操作完成');
                            try {
                                var next = new URL(window.location.href);
                                next.searchParams.delete('page');
                                if (moduleName) {
                                    next.searchParams.set('tab', 'modules');
                                    next.searchParams.set('module_q', moduleName);
                                }
                                window.location.href = next.toString();
                            } catch (e) {
                                window.location.reload();
                            }
                            return;
                        }
                        var liveHint = currentHintEl();
                        if (liveHint && message) {
                            liveHint.textContent = message;
                        }
                        toast('error', message || '操作失败');
                    }

                    function scheduleWritebackReconnect(reason) {
                        if (settled || userAborted) {
                            return;
                        }
                        reconnectAttempt += 1;
                        if (reconnectAttempt > SSE_RECONNECT_MAX) {
                            finish(false, '写回进度连接中断，已达重连上限。'
                                + (reason ? ('（' + String(reason).slice(0, 80) + '）') : ''));
                            return;
                        }
                        var delay = sseReconnectDelayMs(reconnectAttempt);
                        var msg = '连接中断，'
                            + Math.ceil(delay / 1000)
                            + 's 后自动重连（'
                            + reconnectAttempt
                            + '/'
                            + SSE_RECONNECT_MAX
                            + '）…';
                        progressUi.applyEvent('progress', {
                            stage: 'sync',
                            node: 'collect',
                            message: msg
                        });
                        var liveHint = currentHintEl();
                        if (liveHint) {
                            liveHint.textContent = msg;
                        }
                        if (submit) {
                            submit.textContent = '重连中…';
                        }
                        clearWritebackReconnect();
                        reconnectTimer = setTimeout(function () {
                            reconnectTimer = null;
                            if (settled || userAborted) {
                                return;
                            }
                            openWritebackStream();
                        }, delay);
                    }

                    function handleSseEvent(eventName, data) {
                        data = data || {};
                        progressUi.applyEvent(eventName, data);
                        var liveHint = currentHintEl();
                        if (eventName === 'start' || eventName === 'progress') {
                            if (liveHint && data.message) {
                                liveHint.textContent = String(data.message);
                            }
                            if (submit) {
                                submit.textContent = stageButtonLabel(data);
                            }
                            if (data.locale) {
                                var stage = String(data.stage || '');
                                var label = stage === 'writeback' ? '写回中' : (stage === 'translate' ? '翻译中' : '处理中');
                                if (data.round) {
                                    label += ' #' + String(data.round);
                                }
                                setLocaleRowStatus(matrix, data.locale, label, stage === 'writeback' ? 'success' : 'info');
                            }
                            return;
                        }
                        if (eventName === 'locale_done') {
                            if (liveHint && data.message) {
                                liveHint.textContent = String(data.message);
                            }
                            if (data.locale) {
                                var doneStatus = String(data.status || '');
                                var doneLabel = doneStatus === 'failed' ? '未对齐'
                                    : (doneStatus === 'aligned' ? '已对齐' : '已完成');
                                setLocaleRowStatus(
                                    matrix,
                                    data.locale,
                                    doneLabel,
                                    doneStatus === 'failed' ? 'danger' : 'success'
                                );
                            }
                            return;
                        }
                        if (eventName === 'done') {
                            if (data.success === false) {
                                finish(false, data.message || '操作失败');
                                return;
                            }
                            finish(true, data.message || '操作完成');
                            return;
                        }
                        if (eventName === 'failed' || eventName === 'error') {
                            var failedMsg = data.message || '操作失败';
                            if (/未登录|登录已过期|UNAUTHORIZED/i.test(String(failedMsg))) {
                                finish(false, failedMsg);
                                return;
                            }
                            scheduleWritebackReconnect(failedMsg);
                        }
                    }

                    function openWritebackStream() {
                        if (settled || userAborted) {
                            return;
                        }
                        if (xhr) {
                            try {
                                xhr.onreadystatechange = null;
                                xhr.onprogress = null;
                                xhr.onload = null;
                                xhr.onerror = null;
                                xhr.onabort = null;
                                xhr.abort();
                            } catch (e) {
                            }
                        }
                        sseBuffer = '';
                        seenBytes = 0;
                        xhr = new XMLHttpRequest();
                        bindWritebackHandle();
                        xhr.open('POST', actionUrl, true);
                        xhr.withCredentials = true;
                        xhr.setRequestHeader('Accept', 'text/event-stream');
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.onprogress = function () {
                            var text = xhr.responseText || '';
                            if (text.length <= seenBytes) {
                                return;
                            }
                            var chunk = text.slice(seenBytes);
                            seenBytes = text.length;
                            sseBuffer = parseSseBuffer(sseBuffer + chunk, handleSseEvent);
                        };
                        xhr.onload = function () {
                            var text = xhr.responseText || '';
                            if (text.length > seenBytes) {
                                sseBuffer = parseSseBuffer(sseBuffer + text.slice(seenBytes), handleSseEvent);
                                seenBytes = text.length;
                            }
                            if (settled || userAborted) {
                                return;
                            }
                            if (xhr.status === 401 || xhr.status === 403) {
                                finish(false, '未登录或登录已过期');
                                return;
                            }
                            if (xhr.status >= 200 && xhr.status < 300) {
                                scheduleWritebackReconnect('写回进度流异常结束');
                                return;
                            }
                            if (xhr.status === 0 || xhr.status >= 500) {
                                scheduleWritebackReconnect('HTTP ' + xhr.status);
                                return;
                            }
                            finish(false, 'HTTP ' + xhr.status);
                        };
                        xhr.onerror = function () {
                            if (settled || userAborted) {
                                return;
                            }
                            scheduleWritebackReconnect('写回进度连接中断');
                        };
                        xhr.onabort = function () {
                            if (settled) {
                                return;
                            }
                            if (!userAborted) {
                                // aborted by closeWritebackStream during reconnect setup — ignore
                                return;
                            }
                            settled = true;
                            clearWritebackReconnect();
                            if (matrix) {
                                matrix.__writebackBusy = 0;
                            }
                            closeWritebackStream(matrix);
                            form.dataset.asyncBusy = '0';
                            if (submit) {
                                submit.disabled = false;
                                submit.textContent = defaultLabel;
                            }
                            var liveHint = currentHintEl();
                            if (liveHint) {
                                liveHint.textContent = '已取消';
                            }
                            progressUi.setFinished(false, '已取消');
                        };
                        try {
                            xhr.send(new FormData(form));
                        } catch (error) {
                            scheduleWritebackReconnect((error && error.message) ? error.message : '无法启动写回请求');
                        }
                    }

                    openWritebackStream();
                } catch (error) {
                    form.dataset.asyncBusy = '0';
                    toast('error', (error && error.message) ? error.message : '启动写回失败');
                }
            }).catch(function (error) {
                form.dataset.confirming = '0';
                toast('error', (error && error.message) ? error.message : '确认对话框失败');
            });
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function setMatrixToolbarEnabled(matrix, enabled) {
            matrix.querySelectorAll('[data-ai-module-select-work], [data-ai-module-clear-selection]').forEach(function (btn) {
                btn.disabled = !enabled;
            });
        }

        function formatSummary(template, workCount, totalCount) {
            return String(template || '有待办 %{1} / 共 %{2} 种语言')
                .replace('%{1}', String(workCount))
                .replace('%{2}', String(totalCount));
        }

        function formatSourceBanner(template, name, wordCount) {
            return String(template || '源语言 %{1}：%{2} 个词（其他语言需对齐到该词数）')
                .replace('%{1}', String(name || ''))
                .replace('%{2}', String(wordCount == null ? 0 : wordCount));
        }

        function formatModuleStatusDetail(template, a, b, c) {
            return String(template || '')
                .replace('%{1}', String(a == null ? 0 : a))
                .replace('%{2}', String(b == null ? 0 : b))
                .replace('%{3}', String(c == null ? 0 : c));
        }

        function updateModuleRowStatusFromMatrix(matrix, locales, source) {
            if (!matrix || !root) {
                return;
            }
            var moduleName = matrix.getAttribute('data-module-name') || '';
            if (!moduleName) {
                return;
            }
            var row = null;
            try {
                row = root.querySelector(
                    'tr[data-ai-module-row="' + String(moduleName).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]'
                );
            } catch (e) {
                row = null;
            }
            if (!row) {
                return;
            }
            var list = Array.isArray(locales) ? locales : [];
            var localesTotal = list.length;
            var localesPending = 0;
            var wordsPending = 0;
            var pendingExport = 0;
            list.forEach(function (item) {
                var gap = Number((item && item.gap) != null ? item.gap : ((item && item.untranslated) || 0));
                var exportCount = Number((item && item.ai_pending_export) || 0);
                pendingExport += exportCount;
                if (gap > 0 || exportCount > 0) {
                    localesPending += 1;
                }
                if (gap > 0) {
                    wordsPending += gap;
                }
            });
            var sourceWords = Number((source && source.word_count) || row.getAttribute('data-source-words') || 0);
            var hasWork = localesPending > 0 || wordsPending > 0 || pendingExport > 0;
            row.setAttribute('data-locales-pending', String(localesPending));
            row.setAttribute('data-words-pending', String(wordsPending));
            row.setAttribute('data-locales-total', String(localesTotal));
            row.setAttribute('data-source-words', String(sourceWords));
            row.setAttribute('data-pending-export', String(pendingExport));

            var exportEl = row.querySelector('[data-ai-module-pending-export]');
            if (exportEl) {
                exportEl.textContent = String(pendingExport);
            }
            var badge = row.querySelector('[data-ai-module-status-badge]');
            var detail = row.querySelector('[data-ai-module-status-detail]');
            var labelWork = matrix.getAttribute('data-label-status-work') || '有待办';
            var labelAligned = matrix.getAttribute('data-label-status-aligned') || '已对齐';
            var detailWorkTpl = matrix.getAttribute('data-label-status-detail-work')
                || '待处理 %{1}/%{2} 种语言 · %{3} 词';
            var detailIdleTpl = matrix.getAttribute('data-label-status-detail-idle')
                || '源语言 %{1} 词 · 各语言已对齐';
            if (badge) {
                badge.textContent = hasWork ? labelWork : labelAligned;
                badge.setAttribute('data-w-background', hasWork ? 'warning' : 'muted');
            }
            if (detail) {
                detail.textContent = hasWork
                    ? formatModuleStatusDetail(detailWorkTpl, localesPending, localesTotal, wordsPending)
                    : formatModuleStatusDetail(detailIdleTpl, sourceWords, 0, 0);
            }
        }

        function updateSourceBanner(matrix, source) {
            if (!matrix) {
                return;
            }
            var banner = matrix.querySelector('[data-ai-module-source-banner]');
            if (!banner) {
                return;
            }
            if (!source) {
                return;
            }
            matrix.__source = source;
            var locale = String(source.locale || '');
            var name = String(source.name || locale);
            var wordCount = Number(source.word_count || 0);
            var tpl = matrix.getAttribute('data-label-source') || '';
            banner.textContent = formatSourceBanner(tpl, name, wordCount);
            if (source.message) {
                banner.setAttribute('title', String(source.message));
            }
        }

        function matrixColumnLabels(matrix) {
            return {
                colLang: matrix.getAttribute('data-col-lang') || '语言',
                colWords: matrix.getAttribute('data-col-words') || '词数',
                colTranslated: matrix.getAttribute('data-col-translated') || '已译',
                colPending: matrix.getAttribute('data-col-pending') || '未译',
                colGap: matrix.getAttribute('data-col-gap') || '差额',
                colExport: matrix.getAttribute('data-col-export') || '待写回',
                colStatus: matrix.getAttribute('data-col-status') || '状态'
            };
        }

        function renderModuleLocaleMatrix(matrix, locales, source) {
            var body = matrix.querySelector('[data-ai-module-matrix-body]');
            if (!body) {
                body = document.createElement('div');
                body.setAttribute('data-ai-module-matrix-body', '1');
                matrix.appendChild(body);
            }

            var hintEl = matrix.querySelector('[data-ai-module-matrix-hint]');
            var moduleName = matrix.getAttribute('data-module-name') || '';
            var hint = matrix.getAttribute('data-label-hint') || '';
            var emptyLabel = matrix.getAttribute('data-label-empty') || '没有可用的目标语言。';
            var summaryTpl = matrix.getAttribute('data-label-summary') || '';
            var cols = matrixColumnLabels(matrix);
            var statusWork = matrix.getAttribute('data-status-work') || '有待办';
            var statusIdle = matrix.getAttribute('data-status-idle') || '无待办';

            if (source) {
                updateSourceBanner(matrix, source);
            } else if (matrix.__source) {
                updateSourceBanner(matrix, matrix.__source);
            }

            if (!Array.isArray(locales) || locales.length === 0) {
                if (hintEl) {
                    hintEl.textContent = emptyLabel;
                }
                body.innerHTML = '<div class="w-text" data-tone="muted" data-size="sm">'
                    + escapeHtml(emptyLabel) + '</div>';
                setMatrixToolbarEnabled(matrix, false);
                return;
            }

            var sorted = locales.slice().sort(function (a, b) {
                var aw = !!(a && a.has_work);
                var bw = !!(b && b.has_work);
                if (aw !== bw) {
                    return aw ? -1 : 1;
                }
                return String((a && a.code) || '').localeCompare(String((b && b.code) || ''));
            });

            var workCount = 0;
            var rowsHtml = sorted.map(function (row) {
                var code = String((row && row.code) || '');
                var name = String((row && row.name) || code);
                var wordCount = Number((row && row.word_count) || 0);
                var translated = Number((row && row.translated) || 0);
                var pending = Number((row && row.untranslated) || 0);
                var gap = Number((row && row.gap) != null ? row.gap : pending);
                var exportCount = Number((row && row.ai_pending_export) || 0);
                var hasWork = !!(row && row.has_work);
                if (hasWork) {
                    workCount += 1;
                }
                return ''
                    + '<tr data-has-work="' + (hasWork ? '1' : '0') + '">'
                    + '<td><input type="checkbox" value="' + escapeHtml(code) + '"'
                    + ' data-ai-module-locale-check data-module-name="' + escapeHtml(moduleName) + '"'
                    + ' data-has-work="' + (hasWork ? '1' : '0') + '"'
                    + (hasWork ? ' checked' : '') + '></td>'
                    + '<td><div class="w-text" data-weight="strong">' + escapeHtml(name) + '</div>'
                    + '<code class="w-text" data-size="sm" data-tone="muted">' + escapeHtml(code) + '</code></td>'
                    + '<td>' + wordCount + '</td>'
                    + '<td>' + translated + '</td>'
                    + '<td>' + pending + '</td>'
                    + '<td>' + gap + '</td>'
                    + '<td>' + exportCount + '</td>'
                    + '<td><span class="w-badge" data-w-background="' + (hasWork ? 'warning' : 'muted') + '">'
                    + escapeHtml(hasWork ? statusWork : statusIdle) + '</span></td>'
                    + '</tr>';
            }).join('');

            if (hintEl) {
                hintEl.textContent = formatSummary(summaryTpl, workCount, sorted.length)
                    + (hint ? ' · ' + hint : '');
            }

            body.innerHTML = ''
                + '<div class="w-table-wrap">'
                + '<table class="w-table i18n-admin-table" data-w-vertical="middle" style="--w-mb:0;">'
                + '<thead><tr>'
                + '<th style="width:2.5rem;"></th>'
                + '<th>' + escapeHtml(cols.colLang) + '</th>'
                + '<th>' + escapeHtml(cols.colWords) + '</th>'
                + '<th>' + escapeHtml(cols.colTranslated) + '</th>'
                + '<th>' + escapeHtml(cols.colPending) + '</th>'
                + '<th>' + escapeHtml(cols.colGap) + '</th>'
                + '<th>' + escapeHtml(cols.colExport) + '</th>'
                + '<th>' + escapeHtml(cols.colStatus) + '</th>'
                + '</tr></thead>'
                + '<tbody>' + rowsHtml + '</tbody>'
                + '</table></div>';
            setMatrixToolbarEnabled(matrix, true);
            updateModuleRowStatusFromMatrix(matrix, sorted, source || matrix.__source || null);
        }

        function isWritebackBusy(matrix) {
            return !!(matrix && matrix.__writebackBusy === 1);
        }

        var SSE_RECONNECT_MAX = 8;
        var SSE_RECONNECT_BASE_MS = 1200;

        function sseReconnectDelayMs(attempt) {
            var n = Math.max(1, Number(attempt) || 1);
            return Math.min(30000, SSE_RECONNECT_BASE_MS * Math.pow(2, n - 1));
        }

        function clearMatrixReconnect(matrix) {
            if (!matrix || !matrix.__matrixReconnectTimer) {
                return;
            }
            try {
                clearTimeout(matrix.__matrixReconnectTimer);
            } catch (e) {
            }
            matrix.__matrixReconnectTimer = null;
        }

        function isMatrixDetailOpen(matrix) {
            if (!matrix || !matrix.isConnected) {
                return false;
            }
            var detail = matrix.closest('[data-ai-module-detail]');
            if (!detail) {
                return true;
            }
            return !detail.hidden && detail.getAttribute('hidden') == null;
        }

        function scheduleMatrixReconnect(matrix, attempt, reason) {
            clearMatrixReconnect(matrix);
            if (!isMatrixDetailOpen(matrix) || isWritebackBusy(matrix)) {
                return;
            }
            var nextAttempt = Math.max(1, Number(attempt) || 1);
            if (nextAttempt > SSE_RECONNECT_MAX) {
                var hintEl = matrix.querySelector('[data-ai-module-matrix-hint]');
                var body = matrix.querySelector('[data-ai-module-matrix-body]');
                var message = '语言进度连接中断，已达重连上限。请收起后重试。'
                    + (reason ? ('（' + String(reason).slice(0, 80) + '）') : '');
                matrix.setAttribute('data-loaded', '0');
                if (hintEl) {
                    hintEl.textContent = message;
                }
                if (body) {
                    body.innerHTML = '<div class="w-text" data-tone="danger" data-size="sm">'
                        + escapeHtml(message)
                        + '</div>';
                }
                toast('error', message);
                return;
            }
            var delay = sseReconnectDelayMs(nextAttempt);
            var hint = matrix.querySelector('[data-ai-module-matrix-hint]');
            if (hint) {
                hint.textContent = '连接中断，'
                    + Math.ceil(delay / 1000)
                    + 's 后自动重连（'
                    + nextAttempt
                    + '/'
                    + SSE_RECONNECT_MAX
                    + '）…';
            }
            matrix.__matrixReconnectTimer = setTimeout(function () {
                matrix.__matrixReconnectTimer = null;
                if (!isMatrixDetailOpen(matrix) || isWritebackBusy(matrix)) {
                    return;
                }
                matrix.setAttribute('data-loaded', '0');
                loadModuleLocaleMatrix(matrix, { reconnectAttempt: nextAttempt });
            }, delay);
        }

        function closeMatrixStream(matrix, options) {
            options = options || {};
            if (!matrix) {
                return;
            }
            if (!options.keepReconnect) {
                clearMatrixReconnect(matrix);
            }
            var es = matrix.__matrixEs;
            if (es) {
                try {
                    es.close();
                } catch (e) {
                }
                matrix.__matrixEs = null;
            }
            var xhr = matrix.__matrixXhr;
            if (xhr) {
                try {
                    xhr.onreadystatechange = null;
                    xhr.onprogress = null;
                    xhr.onload = null;
                    xhr.onerror = null;
                    xhr.onabort = null;
                    xhr.abort();
                } catch (e) {
                }
                matrix.__matrixXhr = null;
            }
            // 写回 SSE 进行中时，不要被矩阵流收尾误杀。
            if (!options.keepWriteback && !isWritebackBusy(matrix)) {
                closeWritebackStream(matrix);
            }
        }

        function ensureMatrixTable(matrix, body) {
            var cols = matrixColumnLabels(matrix);
            body.innerHTML = ''
                + '<div class="w-table-wrap">'
                + '<table class="w-table i18n-admin-table" data-w-vertical="middle" style="--w-mb:0;">'
                + '<thead><tr>'
                + '<th style="width:2.5rem;"></th>'
                + '<th>' + escapeHtml(cols.colLang) + '</th>'
                + '<th>' + escapeHtml(cols.colWords) + '</th>'
                + '<th>' + escapeHtml(cols.colTranslated) + '</th>'
                + '<th>' + escapeHtml(cols.colPending) + '</th>'
                + '<th>' + escapeHtml(cols.colGap) + '</th>'
                + '<th>' + escapeHtml(cols.colExport) + '</th>'
                + '<th>' + escapeHtml(cols.colStatus) + '</th>'
                + '</tr></thead>'
                + '<tbody data-ai-module-matrix-tbody></tbody></table></div>';
            return body.querySelector('[data-ai-module-matrix-tbody]');
        }

        function appendLocaleRow(matrix, tbody, row) {
            if (!tbody || !row) {
                return;
            }
            var moduleName = matrix.getAttribute('data-module-name') || '';
            var statusWork = matrix.getAttribute('data-status-work') || '有待办';
            var statusIdle = matrix.getAttribute('data-status-idle') || '无待办';
            var code = String(row.code || '');
            var name = String(row.name || code);
            var wordCount = Number(row.word_count || 0);
            var translated = Number(row.translated || 0);
            var pending = Number(row.untranslated || 0);
            var gap = Number(row.gap != null ? row.gap : pending);
            var exportCount = Number(row.ai_pending_export || 0);
            var hasWork = !!row.has_work;
            var existing = null;
            try {
                existing = tbody.querySelector('tr[data-locale-code="' + String(code).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]');
            } catch (e) {
                existing = null;
            }
            var html = ''
                + '<tr data-locale-code="' + escapeHtml(code) + '" data-has-work="' + (hasWork ? '1' : '0') + '">'
                + '<td><input type="checkbox" value="' + escapeHtml(code) + '"'
                + ' data-ai-module-locale-check data-module-name="' + escapeHtml(moduleName) + '"'
                + ' data-has-work="' + (hasWork ? '1' : '0') + '"'
                + (hasWork ? ' checked' : '') + '></td>'
                + '<td><div class="w-text" data-weight="strong">' + escapeHtml(name) + '</div>'
                + '<code class="w-text" data-size="sm" data-tone="muted">' + escapeHtml(code) + '</code></td>'
                + '<td>' + wordCount + '</td>'
                + '<td>' + translated + '</td>'
                + '<td>' + pending + '</td>'
                + '<td>' + gap + '</td>'
                + '<td>' + exportCount + '</td>'
                + '<td><span class="w-badge" data-w-background="' + (hasWork ? 'warning' : 'muted') + '">'
                + escapeHtml(hasWork ? statusWork : statusIdle) + '</span></td>'
                + '</tr>';
            if (existing) {
                existing.outerHTML = html;
            } else {
                tbody.insertAdjacentHTML('beforeend', html);
            }
        }

        function loadModuleLocaleMatrix(matrix, options) {
            options = options || {};
            if (!matrix || matrix.getAttribute('data-loaded') === '1'
                || matrix.getAttribute('data-loaded') === 'loading') {
                return;
            }
            var reconnectAttempt = Math.max(0, Number(options.reconnectAttempt) || 0);
            var moduleName = matrix.getAttribute('data-module-name') || '';
            var body = matrix.querySelector('[data-ai-module-matrix-body]');
            var hintEl = matrix.querySelector('[data-ai-module-matrix-hint]');
            var loading = matrix.getAttribute('data-label-loading') || '正在加载该模块各语言进度';
            if (hintEl) {
                hintEl.textContent = reconnectAttempt > 0
                    ? ('正在重连语言进度（' + reconnectAttempt + '/' + SSE_RECONNECT_MAX + '）…')
                    : loading;
            }
            if (!body) {
                return;
            }
            if (!moduleName) {
                return;
            }

            clearMatrixReconnect(matrix);
            closeMatrixStream(matrix, { keepReconnect: true });
            var tbody = ensureMatrixTable(matrix, body);
            setMatrixToolbarEnabled(matrix, false);
            matrix.setAttribute('data-loaded', 'loading');

            var matrixUrl = resolveModuleMatrixUrl(matrix, root);
            if (!matrixUrl) {
                matrix.setAttribute('data-loaded', '0');
                if (hintEl) {
                    hintEl.textContent = '缺少语言矩阵地址';
                }
                return;
            }
            var streamUrl;
            try {
                streamUrl = new URL(matrixUrl, window.location.href);
            } catch (error) {
                matrix.setAttribute('data-loaded', '0');
                if (hintEl) {
                    hintEl.textContent = '语言矩阵地址无效';
                }
                return;
            }
            streamUrl.searchParams.set('module_name', moduleName);
            streamUrl.searchParams.set('sse', '1');

            var locales = [];
            var settled = false;
            var gotLocale = false;
            var sourceInfo = null;
            var seenBytes = 0;
            var sseBuffer = '';
            // Prefer XHR over EventSource: HTTPS non-standard ports use Partitioned
            // session cookies; EventSource can omit them and mint an empty session
            // that overwrites the login cookie (auth_device_rejected / 未登录).
            var xhr = new XMLHttpRequest();
            matrix.__matrixXhr = xhr;
            var watchdog = setTimeout(function () {
                if (!settled && matrix.getAttribute('data-loaded') === 'loading' && !gotLocale && !sourceInfo) {
                    fail('语言进度统计超时（可能数据库繁忙）', { reconnect: true });
                }
            }, 60000);

            function fail(message, failOptions) {
                failOptions = failOptions || {};
                if (settled) {
                    return;
                }
                settled = true;
                try {
                    clearTimeout(watchdog);
                } catch (e) {
                }
                closeMatrixStream(matrix, { keepWriteback: true, keepReconnect: !!failOptions.reconnect });
                if (isWritebackBusy(matrix)) {
                    return;
                }
                if (failOptions.reconnect) {
                    scheduleMatrixReconnect(matrix, reconnectAttempt + 1, message);
                    return;
                }
                clearMatrixReconnect(matrix);
                matrix.setAttribute('data-loaded', '0');
                if (hintEl) {
                    hintEl.textContent = message;
                }
                if (body) {
                    body.innerHTML = '<div class="w-text" data-tone="danger" data-size="sm">'
                        + escapeHtml(message)
                        + '</div>';
                }
                toast('error', message);
            }

            function handleSseEvent(eventName, data) {
                if (isWritebackBusy(matrix)) {
                    return;
                }
                data = data || {};
                if (eventName === 'start' || eventName === 'progress') {
                    if (hintEl && data.message) {
                        hintEl.textContent = String(data.message);
                    } else if (hintEl && eventName === 'start') {
                        hintEl.textContent = loading;
                    }
                    return;
                }
                if (eventName === 'source') {
                    sourceInfo = data;
                    updateSourceBanner(matrix, data);
                    if (hintEl && data.message) {
                        hintEl.textContent = String(data.message);
                    }
                    return;
                }
                if (eventName === 'locale') {
                    if (!data.code) {
                        return;
                    }
                    gotLocale = true;
                    try {
                        clearTimeout(watchdog);
                    } catch (e) {
                    }
                    locales.push(data);
                    appendLocaleRow(matrix, tbody, data);
                    if (hintEl) {
                        var total = Number(data.total || locales.length);
                        var index = Number(data.index || locales.length);
                        hintEl.textContent = loading + ' (' + index + '/' + total + ')';
                    }
                    return;
                }
                if (eventName === 'done') {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    try {
                        clearTimeout(watchdog);
                    } catch (e) {
                    }
                    clearMatrixReconnect(matrix);
                    closeMatrixStream(matrix, { keepWriteback: true });
                    if (isWritebackBusy(matrix)) {
                        return;
                    }
                    if (data.success === false) {
                        fail(data.message || '加载语言矩阵失败');
                        return;
                    }
                    var finalLocales = Array.isArray(data.locales) && data.locales.length
                        ? data.locales
                        : locales;
                    if (data.source) {
                        sourceInfo = data.source;
                    }
                    renderModuleLocaleMatrix(matrix, finalLocales, sourceInfo);
                    updateModuleRowStatusFromMatrix(matrix, finalLocales, sourceInfo);
                    matrix.setAttribute('data-loaded', '1');
                    return;
                }
                if (eventName === 'failed' || eventName === 'error') {
                    var failedMsg = data.message || '加载语言矩阵失败';
                    var authLike = /未登录|登录已过期|UNAUTHORIZED/i.test(String(failedMsg));
                    fail(failedMsg, { reconnect: !authLike });
                }
            }

            xhr.open('GET', streamUrl.toString(), true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Accept', 'text/event-stream');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onprogress = function () {
                var text = xhr.responseText || '';
                if (text.length <= seenBytes) {
                    return;
                }
                var chunk = text.slice(seenBytes);
                seenBytes = text.length;
                sseBuffer = parseSseBuffer(sseBuffer + chunk, handleSseEvent);
            };
            xhr.onload = function () {
                var text = xhr.responseText || '';
                if (text.length > seenBytes) {
                    sseBuffer = parseSseBuffer(sseBuffer + text.slice(seenBytes), handleSseEvent);
                    seenBytes = text.length;
                }
                if (!settled) {
                    if (xhr.status === 401 || xhr.status === 403) {
                        fail('未登录或登录已过期');
                    } else if (xhr.status >= 200 && xhr.status < 300) {
                        fail('语言进度流异常结束', { reconnect: true });
                    } else if (xhr.status === 0 || xhr.status >= 500) {
                        fail('加载语言矩阵失败（HTTP ' + xhr.status + '）', { reconnect: true });
                    } else {
                        fail('加载语言矩阵失败（HTTP ' + xhr.status + '）');
                    }
                }
            };
            xhr.onerror = function () {
                fail('语言进度连接中断', { reconnect: true });
            };
            xhr.onabort = function () {
                if (!settled && matrix.getAttribute('data-loaded') === 'loading') {
                    settled = true;
                    try {
                        clearTimeout(watchdog);
                    } catch (e) {
                    }
                }
            };
            try {
                xhr.send();
            } catch (error) {
                fail((error && error.message) ? error.message : '无法启动语言进度请求', { reconnect: true });
            }
        }

        root.addEventListener('click', function (event) {
            var selectWorkBtn = event.target.closest('[data-ai-module-select-work]');
            if (selectWorkBtn) {
                event.preventDefault();
                var matrixSelect = selectWorkBtn.closest('[data-ai-module-locale-matrix]');
                if (matrixSelect) {
                    matrixSelect.querySelectorAll('[data-ai-module-locale-check]').forEach(function (checkbox) {
                        checkbox.checked = checkbox.getAttribute('data-has-work') === '1';
                    });
                }
                return;
            }

            var clearBtn = event.target.closest('[data-ai-module-clear-selection]');
            if (clearBtn) {
                event.preventDefault();
                var matrixClear = clearBtn.closest('[data-ai-module-locale-matrix]');
                if (!matrixClear) {
                    return;
                }
                matrixClear.querySelectorAll('[data-ai-module-locale-check]').forEach(function (checkbox) {
                    checkbox.checked = false;
                });
                return;
            }

            var trigger = event.target.closest('[data-ai-module-expand]');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            var targetSel = trigger.getAttribute('data-target') || '';
            var detail = targetSel ? root.querySelector(targetSel) : null;
            if (!detail) {
                return;
            }
            var expanded = trigger.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                detail.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.textContent = trigger.getAttribute('data-label-expand') || '展开';
                var matrixCollapse = detail.querySelector('[data-ai-module-locale-matrix]');
                // Allow retry after a stuck/in-flight load.
                if (matrixCollapse) {
                    closeMatrixStream(matrixCollapse);
                    if (matrixCollapse.getAttribute('data-loaded') === 'loading') {
                        matrixCollapse.setAttribute('data-loaded', '0');
                    }
                }
                return;
            }
            detail.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            trigger.textContent = trigger.getAttribute('data-label-collapse') || '收起';
            var matrixExpand = detail.querySelector('[data-ai-module-locale-matrix]');
            if (matrixExpand) {
                closeMatrixStream(matrixExpand);
                if (matrixExpand.getAttribute('data-loaded') === 'loading') {
                    matrixExpand.setAttribute('data-loaded', '0');
                }
            }
            loadModuleLocaleMatrix(matrixExpand);
        }, true);
    }

    window.I18nAdminUI = {
        version: I18N_ADMIN_UI_VERSION,
        confirm: confirmAction,
        toast: toast,
        collectCheckedValues: collectCheckedValues,
        setHiddenValues: setHiddenValues,
        bindConfirmSubmit: bindConfirmSubmit,
        bindConfirmLinks: bindConfirmLinks,
        bindAsyncForms: bindAsyncForms,
        bindAsyncLinks: bindAsyncLinks,
        bindSelectAll: bindSelectAll,
        bindAiExportDialog: bindAiExportDialog,
        bindAiModuleWorkspace: bindAiModuleWorkspace,
        requestForm: requestAsyncForm,
        requestBinAction: requestBinAction,
        markActionComplete: markAsyncActionComplete
    };

    bindConfirmSubmit(document);
    bindAsyncForms(document);
    bindAsyncLinks(document);
    bindConfirmLinks(document);
    bindAiExportDialog(document);
    bindAiModuleWorkspace(document);
})(window, document);
// 1788008815
