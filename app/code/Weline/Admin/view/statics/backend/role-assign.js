/**
 * Admin 管理员角色分配（迁移自 User/assign_role.phtml 页面内联脚本）。
 *
 * 结构契约：
 *   根：      [data-role-assign] + data-api-assign 及文案 data-* 属性
 *   行：      [data-role-assign-row]（含 [data-role-selector]）
 *   下拉：    [data-role-select][data-user-id]
 *   状态：    [data-role-saving] / [data-role-saved]（hidden 切换）
 *   状态列：  [data-assign-status] > [data-status-assigned] / [data-status-unassigned]
 *
 * 由 data-weline-load="adminRoleAssign" 加载（Admin/view/statics/backend/weline.modules.js 登记）。
 */
(function () {
    'use strict';

    function init(root) {
        if (!root || root.getAttribute('data-role-assign-ready') === '1') {
            return;
        }
        root.setAttribute('data-role-assign-ready', '1');

        var apiAssign = root.getAttribute('data-api-assign') || '';
        var text = {
            assignSuccess: root.getAttribute('data-text-assign-success') || '',
            assignFail: root.getAttribute('data-text-assign-fail') || '',
            networkError: root.getAttribute('data-text-network-error') || '',
        };

        function setHidden(element, hidden) {
            if (element) {
                element.hidden = hidden;
            }
        }

        function showSaving(selector) {
            setHidden(selector.querySelector('[data-role-saving]'), false);
            setHidden(selector.querySelector('[data-role-saved]'), true);
        }

        function showSaved(selector) {
            setHidden(selector.querySelector('[data-role-saving]'), true);
            setHidden(selector.querySelector('[data-role-saved]'), false);
            window.setTimeout(function () {
                setHidden(selector.querySelector('[data-role-saved]'), true);
            }, 2000);
        }

        function hideSaving(selector) {
            setHidden(selector.querySelector('[data-role-saving]'), true);
        }

        function applyStatus(row, roleId) {
            var statusCell = row.querySelector('[data-assign-status]');
            if (!statusCell) {
                return;
            }
            var assigned = roleId !== '' && roleId !== null && roleId !== undefined;
            setHidden(statusCell.querySelector('[data-status-assigned]'), !assigned);
            setHidden(statusCell.querySelector('[data-status-unassigned]'), assigned);
        }

        function handleRoleChange(event) {
            var select = event.target;
            var userId = select.getAttribute('data-user-id');
            var roleId = select.value;
            var selector = select.closest('[data-role-selector]');
            var row = select.closest('[data-role-assign-row]');
            var originalRole = selector.getAttribute('data-current-role') || '';
            showSaving(selector);
            select.disabled = true;

            var options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ user_id: userId, role_id: roleId }),
            };

            Weline.adminRequest('admin', apiAssign, options)
                .then(function (response) {
                    var result = (response && response.data) !== undefined ? response.data : response;
                    select.disabled = false;
                    if (result.success) {
                        showSaved(selector);
                        Weline.UI.toast.success(result.msg || text.assignSuccess);
                        selector.setAttribute('data-current-role', roleId);
                        applyStatus(row, roleId);
                    } else {
                        hideSaving(selector);
                        Weline.UI.toast.error(result.msg || text.assignFail);
                        select.value = originalRole;
                    }
                })
                .catch(function (error) {
                    select.disabled = false;
                    hideSaving(selector);
                    Weline.UI.toast.error(text.networkError + (error.message ? ': ' + error.message : ''));
                    select.value = originalRole;
                });
        }

        root.querySelectorAll('[data-role-select]').forEach(function (select) {
            select.addEventListener('change', handleRoleChange);
        });
    }

    function boot() {
        Array.prototype.slice.call(document.querySelectorAll('[data-role-assign]')).forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
