window.WelineWidgetAssets.register('theme-data-form-default-1', function (widgetScript) {
(function() {
    'use strict';
    
    var widgetId = (widgetScript.dataset.v0);
    var container = document.getElementById(widgetId);
    if (!container) return;
    
    var form = container.querySelector('form');
    if (!form) return;
    
    var isAjaxForm = form.hasAttribute('data-ajax-form');
    
    // 字段集折叠
    container.querySelectorAll('[data-toggle-fieldset]').forEach(function(legend) {
        legend.addEventListener('click', function() {
            var fieldset = this.closest('.backend-form-fieldset');
            if (fieldset) {
                fieldset.classList.toggle('backend-form-fieldset--collapsed');
            }
        });
    });
    
    // 显示错误
    function showError(fieldName, message) {
        var errorEl = container.querySelector('[data-error-for="' + fieldName + '"]');
        var inputEl = container.querySelector('[name="' + fieldName + '"]');
        if (errorEl) {
            errorEl.textContent = message;
        }
        if (inputEl) {
            inputEl.classList.add('is-invalid');
        }
    }
    
    // 清除错误
    function clearErrors() {
        container.querySelectorAll('.backend-form-error').forEach(function(el) {
            el.textContent = '';
        });
        container.querySelectorAll('.is-invalid').forEach(function(el) {
            el.classList.remove('is-invalid');
        });
    }
    
    // AJAX 提交
    if (isAjaxForm) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            clearErrors();
            
            var formData = new FormData(form);
            var submitBtn = form.querySelector('[type="submit"]');
            var originalText = submitBtn ? submitBtn.innerHTML : '';
            
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = ('<i class="ri-loader-4-line ri-spin"></i> ' + widgetScript.dataset.v1);
            }
            
            var Toast = typeof BackendToast !== 'undefined' ? BackendToast : (typeof BackendToast !== 'undefined' ? BackendToast : null);
            WelineThemeBinQuery.request(form.action, {
                method: form.method || 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) {
                var data = (function(r){ var b=window.WelineApiBusiness||(window.Weline&&window.Weline.ApiBusiness); return (b&&typeof b.unwrapBusiness==='function')?b.unwrapBusiness(r):r.data; })(response);
                if (response.ok && data && data.success) {
                    if (Toast) Toast.success(data.message || (widgetScript.dataset.v2));
                    if (data.redirect) {
                        setTimeout(function() { window.location.href = data.redirect; }, 1000);
                    }
                } else {
                    if (Toast) Toast.error((data && data.message) || (widgetScript.dataset.v3));
                    if (data && data.errors) {
                        for (var fieldName in data.errors) {
                            showError(fieldName, data.errors[fieldName]);
                        }
                    }
                }
            })
            .catch(function(error) {
                if (Toast) Toast.error(error && error.message ? error.message : (widgetScript.dataset.v4));
                console.error('Form submit error:', error);
            })
            .finally(function() {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });
        });
    }
    
    // 暴露 API
    window.BackendDataForm = window.BackendDataForm || {};
    window.BackendDataForm[widgetId] = {
        getForm: function() { return form; },
        showError: showError,
        clearErrors: clearErrors,
        getData: function() { return new FormData(form); },
        setData: function(data) {
            for (var key in data) {
                var input = form.querySelector('[name="' + key + '"]');
                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = !!data[key];
                    } else {
                        input.value = data[key];
                    }
                }
            }
        }
    };
    
})();
});
