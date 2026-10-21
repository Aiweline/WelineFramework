/**
 * 支付方式管理：同步提供商 + 拖拽排序。
 * 由页面根节点 data-weline-load="paymentMethodAdmin" 声明加载。
 */
(function () {
    'use strict';

    function root() {
        return document.querySelector('[data-testid="payment-method-management"]') || document.body;
    }

    function initSyncProviders() {
        var button = document.getElementById('payment-method-sync-providers');
        if (!button) return;
        button.addEventListener('click', function () {
            button.disabled = true;
            button.dataset.state = 'pending';
            Promise.resolve(window.Weline.load ? window.Weline.load('api') : window.Weline.Api)
                .then(function (api) {
                    return api.resource('payment').registerProviders({
                        target_scope: button.dataset.targetScope,
                        expected_grant_version: button.dataset.grantVersion
                    });
                })
                .then(function (data) {
                    if (!data || data.success === false) throw new Error((data && data.message) || 'provider_sync_failed');
                    button.dataset.state = 'success';
                    button.dataset.registered = String(data.providers_registered || 0);
                    if (typeof Weline.UI.toast !== 'undefined') Weline.UI.toast.success(root().getAttribute('data-text-sync-success') || '支付提供商同步成功');
                })
                .catch(function (error) {
                    button.dataset.state = 'error';
                    console.error('Payment provider sync failed:', error);
                    if (typeof Weline.UI.toast !== 'undefined') Weline.UI.toast.error(root().getAttribute('data-text-sync-fail') || '支付提供商同步失败');
                })
                .finally(function () { button.disabled = false; });
        });
    }

    function initDragSort() {
        var list = document.getElementById('payment-method-sort-list');
        if (!list || list.dataset.canReorder !== '1') return;

        var dragging = null;
        var saveTimer = null;
        var saving = false;

        function rows() {
            return Array.prototype.slice.call(list.querySelectorAll('tr.payment-method-sort-row'));
        }

        function orderedCodes() {
            return rows().map(function (row) { return row.dataset.methodCode || ''; }).filter(Boolean);
        }

        function applySortValues(map) {
            if (!map || typeof map !== 'object') return;
            rows().forEach(function (row) {
                var code = row.dataset.methodCode || '';
                var cell = row.querySelector('.payment-method-sort-value');
                if (cell && Object.prototype.hasOwnProperty.call(map, code)) {
                    cell.textContent = String(map[code]);
                }
            });
        }

        function persistOrder() {
            if (saving) return;
            saving = true;
            list.dataset.sortState = 'pending';
            Promise.resolve(window.Weline.load ? window.Weline.load('api') : window.Weline.Api)
                .then(function (api) {
                    return api.resource('payment').reorderPaymentMethods({
                        target_scope: list.dataset.targetScope,
                        expected_grant_version: list.dataset.grantVersion,
                        ordered_codes: orderedCodes()
                    });
                })
                .then(function (data) {
                    if (!data || data.success === false) {
                        throw new Error((data && data.message) || 'reorder_failed');
                    }
                    applySortValues(data.sort_orders || {});
                    list.dataset.sortState = 'success';
                    if (typeof Weline.UI.toast !== 'undefined') {
                        Weline.UI.toast.success(data.message || root().getAttribute('data-text-reorder-saved') || '');
                    }
                })
                .catch(function (error) {
                    list.dataset.sortState = 'error';
                    console.error('Payment method reorder failed:', error);
                    if (typeof Weline.UI.toast !== 'undefined') {
                        Weline.UI.toast.error(root().getAttribute('data-text-reorder-fail') || '');
                    }
                })
                .finally(function () { saving = false; });
        }

        function schedulePersist() {
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(persistOrder, 280);
        }

        list.addEventListener('dragstart', function (event) {
            var row = event.target && event.target.closest ? event.target.closest('tr.payment-method-sort-row') : null;
            if (!row || !list.contains(row)) return;
            dragging = row;
            row.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                try { event.dataTransfer.setData('text/plain', row.dataset.methodCode || ''); } catch (e) {}
            }
        });

        list.addEventListener('dragend', function () {
            rows().forEach(function (row) {
                row.classList.remove('is-dragging', 'is-drop-target');
            });
            dragging = null;
            schedulePersist();
        });

        list.addEventListener('dragover', function (event) {
            event.preventDefault();
            var row = event.target && event.target.closest ? event.target.closest('tr.payment-method-sort-row') : null;
            if (!dragging || !row || row === dragging) return;
            rows().forEach(function (r) { r.classList.toggle('is-drop-target', r === row); });
            var rect = row.getBoundingClientRect();
            var before = (event.clientY - rect.top) < (rect.height / 2);
            if (before) {
                list.insertBefore(dragging, row);
            } else {
                list.insertBefore(dragging, row.nextSibling);
            }
        });

        list.addEventListener('drop', function (event) {
            event.preventDefault();
        });
    }

    function boot() {
        initSyncProviders();
        initDragSort();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
