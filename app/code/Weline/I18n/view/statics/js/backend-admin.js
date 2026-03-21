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

        return Promise.resolve(true);
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

    function bindConfirmSubmit(scope) {
        (scope || document).addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) {
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
        bindSelectAll: bindSelectAll
    };

    bindConfirmSubmit(document);
    bindConfirmLinks(document);
})(window, document);
