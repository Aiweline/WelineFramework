/**
 * BT 服务器列表：删除确认（数据由根节点 data-* 提供）。
 */
(function () {
    'use strict';

    function init() {
        var root = document.querySelector('[data-bt-server-list]');
        if (!root) return;
        var deleteUrl = root.getAttribute('data-delete-url') || '';
        var text = {
            title: root.getAttribute('data-text-delete-title') || '',
            message: root.getAttribute('data-text-delete-message') || '',
            confirmText: root.getAttribute('data-text-confirm-delete') || '',
            cancelText: root.getAttribute('data-text-cancel') || '',
            failed: root.getAttribute('data-text-delete-failed') || '',
            requestFailed: root.getAttribute('data-text-request-failed') || ''
        };

        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-bt-server-delete]');
            if (!button) return;
            var id = button.getAttribute('data-bt-server-delete') || '';
            var confirm = window.BackendConfirm || window.AdminConfirm;
            if (confirm && typeof confirm.show === 'function') {
                confirm.show(text.message, {
                    title: text.title,
                    confirmText: text.confirmText,
                    cancelText: text.cancelText,
                    type: 'danger'
                }).then(function (confirmed) {
                    if (confirmed) deleteServer(id);
                });
            } else {
                deleteServer(id);
            }
        });

        function deleteServer(id) {
            var formData = new URLSearchParams();
            formData.append('id', id);
            fetch(deleteUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        if (typeof BackendToast !== 'undefined') BackendToast.success(resp.message);
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else if (typeof BackendToast !== 'undefined') {
                        BackendToast.error(resp.message || text.failed);
                    }
                })
                .catch(function () {
                    if (typeof BackendToast !== 'undefined') BackendToast.error(text.requestFailed);
                });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
