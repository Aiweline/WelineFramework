(function (window, document) {
    'use strict';

    if (window.I18nAdminUI) {
        return;
    }

    function confirmAction(message, type, options) {
        if (typeof window.BackendConfirm !== 'undefined' && BackendConfirm.show) {
            return BackendConfirm.show(message, Object.assign({ type: type || 'info' }, options || {}));
        }

        if (typeof window.Swal !== 'undefined' && Swal.fire) {
            return Swal.fire({
                text: message,
                icon: type === 'danger' ? 'warning' : (type || 'info'),
                showCancelButton: true,
                confirmButtonText: (window.__WelineThemeConfig && window.__WelineThemeConfig.i18n && window.__WelineThemeConfig.i18n.confirm) || 'OK',
                cancelButtonText: (window.__WelineThemeConfig && window.__WelineThemeConfig.i18n && window.__WelineThemeConfig.i18n.cancel) || 'Cancel'
            }).then(function (result) {
                return !!result.isConfirmed;
            });
        }

        console.warn('[Weline I18n] Confirmation component is unavailable; action cancelled.');
        return Promise.resolve(false);
    }

    function toast(type, message) {
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

    function appendPayloadValue(payload, key, value) {
        var normalizedKey = String(key || '').replace(/\[\]$/, '');
        if (!normalizedKey || value === undefined || value === null) {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(payload, normalizedKey)) {
            payload[normalizedKey] = Array.isArray(payload[normalizedKey])
                ? payload[normalizedKey].concat([value])
                : [payload[normalizedKey], value];
            return;
        }

        payload[normalizedKey] = value;
    }

    function formPayload(form) {
        var payload = {};
        new FormData(form).forEach(function (value, key) {
            if (typeof File !== 'undefined' && value instanceof File) {
                return;
            }
            appendPayloadValue(payload, key, value);
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
            appendPayloadValue(payload, key, value);
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

    function requestBinAction(action, payload) {
        var apiModule = getQueryApi();
        if (!apiModule) {
            return Promise.reject(new Error('Weline.Api bin-query 不可用。'));
        }

        return Promise.resolve(apiModule.resource('i18n_admin')).then(function (api) {
            if (!api || typeof api.action !== 'function') {
                throw new Error('I18n bin-query 查询器不可用。');
            }
            return api.action({
                action: action,
                payload: payload || {}
            }, {silent: true});
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

        if (isActivation) {
            setStateBadge(activeCell, form.getAttribute('data-async-complete-label') || '已激活', 'success');
        } else if (isDeactivation) {
            setStateBadge(activeCell, form.getAttribute('data-async-complete-label') || '未激活', 'muted');
        } else if (isUninstall) {
            setStateBadge(activeCell, form.getAttribute('data-async-active-label') || '未激活', 'muted');
            setStateBadge(installCell, form.getAttribute('data-async-install-label') || '未安装', 'muted');
        } else {
            setStateBadge(installCell, form.getAttribute('data-async-complete-label') || '已安装', '');
        }

        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = button.getAttribute('data-async-complete-label') ||
                (isActivation ? '已激活' : (isDeactivation ? '已停用' : (isUninstall ? '已卸载' : '已安装')));
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
            return requestBinAction(action, formPayload(form)).then(function (response) {
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
                return payload;
            }).catch(function (error) {
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

    window.I18nAdminUI = {
        confirm: confirmAction,
        toast: toast,
        collectCheckedValues: collectCheckedValues,
        setHiddenValues: setHiddenValues,
        bindConfirmSubmit: bindConfirmSubmit,
        bindConfirmLinks: bindConfirmLinks,
        bindAsyncForms: bindAsyncForms,
        bindAsyncLinks: bindAsyncLinks,
        bindSelectAll: bindSelectAll,
        requestForm: requestAsyncForm,
        markActionComplete: markAsyncActionComplete
    };

    bindConfirmSubmit(document);
    bindAsyncForms(document);
    bindAsyncLinks(document);
    bindConfirmLinks(document);
})(window, document);
