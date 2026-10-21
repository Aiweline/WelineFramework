/**
 * BT 服务器表单：密码显隐与异步保存。
 */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('btServerForm');
        if (!form) return;
        var toggleButton = document.getElementById('togglePasswordBtn');
        var passwordInput = document.getElementById('password');
        if (toggleButton && passwordInput) {
            toggleButton.addEventListener('click', function () {
                passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
            });
        }

        var saveUrl = form.getAttribute('data-save-url') || form.getAttribute('action') || '';
        var listUrl = form.getAttribute('data-list-url') || '';
        var textSaved = form.getAttribute('data-text-saved') || '';
        var textSaveFailed = form.getAttribute('data-text-save-failed') || '';
        var textRequestFailed = form.getAttribute('data-text-request-failed') || '';

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var data = {};
            new FormData(form).forEach(function (value, key) {
                data[key] = value;
            });
            fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data).toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        if (typeof BackendToast !== 'undefined') BackendToast.success(resp.message || textSaved);
                        setTimeout(function () { window.location.href = listUrl; }, 800);
                    } else if (typeof BackendToast !== 'undefined') {
                        BackendToast.error(resp.message || textSaveFailed);
                    }
                })
                .catch(function () {
                    if (typeof BackendToast !== 'undefined') BackendToast.error(textRequestFailed);
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
