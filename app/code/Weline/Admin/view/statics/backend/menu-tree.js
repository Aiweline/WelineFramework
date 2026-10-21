/**
 * Admin 菜单树交互（迁移自 System/Menus/index.phtml 页面内联脚本）。
 *
 * 结构契约：
 *   根：        [data-menu-tree] + data-menu-save-url / data-menu-delete-url / data-menu-detail-url
 *   节点：      [data-menu-item]
 *   展开按钮：  [data-menu-toggle]；子列表 [data-menu-children]（hidden 表示折叠）
 *   展开/折叠： [data-menu-expand-all] / [data-menu-collapse-all]
 *   弹层：      #menuEditModal + #menuEditForm + #menuSaveBtn
 *
 * 由 data-weline-load="adminMenuTree" 加载（Admin/view/statics/backend/weline.modules.js 登记）。
 */
(function () {
    'use strict';

    function init(root) {
        if (!root || root.getAttribute('data-menu-tree-ready') === '1') {
            return;
        }
        root.setAttribute('data-menu-tree-ready', '1');

        var saveUrl = root.getAttribute('data-menu-save-url') || '';
        var deleteUrl = root.getAttribute('data-menu-delete-url') || '';
        var detailUrl = root.getAttribute('data-menu-detail-url') || '';
        var modal = document.getElementById('menuEditModal');
        var form = document.getElementById('menuEditForm');
        var iconPreview = root.querySelector('[data-menu-icon-preview]');
        var iconInput = document.getElementById('menu_icon');

        function withQuery(url, key, value) {
            var text = String(url || '');
            var separator = text.indexOf('?') >= 0 ? '&' : '?';
            return text + separator + encodeURIComponent(key) + '=' + encodeURIComponent(value);
        }

        function childrenOf(item) {
            return item ? item.querySelector(':scope > [data-menu-children]') : null;
        }

        function toggleNode(button) {
            var item = button.closest('[data-menu-item]');
            var children = childrenOf(item);
            var open = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', String(!open));
            if (children) {
                children.hidden = open;
            }
            if (item) {
                item.setAttribute('data-state', open ? 'closed' : 'open');
            }
            var icon = button.querySelector('w\\:icon, [data-w-icon], .w-icon');
            if (icon) {
                icon.setAttribute('name', open ? 'chevron-right' : 'chevron-down');
            }
        }

        function setAll(open) {
            root.querySelectorAll('[data-menu-toggle]').forEach(function (button) {
                if (button.getAttribute('aria-expanded') === String(open)) {
                    return;
                }
                toggleNode(button);
            });
        }

        function setIconPreview(name) {
            if (!iconPreview) {
                return;
            }
            var iconName = String(name || 'menu')
                .replace(/^(?:mdi\s+)+/, '')
                .replace(/^(?:fa[brs]?|ri)-/i, '')
                .replace(/^mdi-/i, '') || 'menu';
            var icon = iconPreview.querySelector('w\\:icon, [data-w-icon], .w-icon');
            if (icon) {
                icon.setAttribute('name', iconName);
                return;
            }
            iconPreview.innerHTML = '';
            var element = document.createElement('w-icon');
            element.setAttribute('name', iconName);
            iconPreview.appendChild(element);
        }

        function openAddModal(parentSource) {
            document.getElementById('menuEditModalTitle').textContent = root.getAttribute('data-menu-add-title') || '{{新增菜单}}';
            form.reset();
            document.getElementById('menu_id').value = '';
            if (parentSource) {
                document.getElementById('menu_parent_source').value = parentSource;
            }
            setIconPreview('menu');
            Weline.UI.dialog.open(modal);
        }

        function openEditModal(menuId) {
            document.getElementById('menuEditModalTitle').textContent = root.getAttribute('data-menu-edit-title') || '{{编辑菜单}}';
            var url = withQuery(detailUrl, 'id', menuId);
            Weline.adminRequest('admin', url, { silent: true })
                .then(function (response) {
                    var envelope = response || {};
                    var data = envelope.data;
                    if (data && typeof data === 'object' && data.code !== undefined && data.data !== undefined) {
                        if (data.code !== 200) {
                            Weline.UI.toast.error(data.msg || String(root.getAttribute('data-menu-load-fail') || ''));
                            return;
                        }
                        data = data.data;
                    } else if (!((envelope.code === 200 || envelope.ok === true) && data)) {
                        Weline.UI.toast.error((envelope && (envelope.msg || envelope.message)) || String(root.getAttribute('data-menu-load-fail') || ''));
                        return;
                    }
                    if (!data) {
                        return;
                    }
                    document.getElementById('menu_id').value = data.menu_id || '';
                    document.getElementById('menu_name').value = data.name || '';
                    document.getElementById('menu_title').value = data.title || '';
                    document.getElementById('menu_source').value = data.source || '';
                    document.getElementById('menu_parent_source').value = data.parent_source || '';
                    document.getElementById('menu_action').value = data.action || '';
                    document.getElementById('menu_module').value = data.module || '';
                    document.getElementById('menu_icon').value = data.icon || '';
                    document.getElementById('menu_order').value = data.order || 0;
                    document.getElementById('menu_is_enable').value = data.is_enable || 1;
                    setIconPreview(data.icon || 'menu');
                    Weline.UI.dialog.open(modal);
                })
                .catch(function (error) {
                    Weline.UI.toast.error((root.getAttribute('data-menu-request-fail') || '') + ': ' + error.message);
                });
        }

        function saveMenu() {
            var formData = new FormData(form);
            var data = {};
            formData.forEach(function (value, key) {
                data[key] = value;
            });
            if (!data.name || !data.title || !data.source || !data.module) {
                Weline.UI.toast.warning(String(root.getAttribute('data-menu-required') || ''));
                return;
            }
            var options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            };
            Weline.adminRequest('admin', saveUrl, options)
                .then(function (response) {
                    var result = (response && response.data) !== undefined ? response.data : response;
                    if (result.success || result.code === 200) {
                        Weline.UI.toast.success(String(root.getAttribute('data-menu-save-success') || ''));
                        Weline.UI.dialog.close(modal, 'saved');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 1000);
                    } else {
                        Weline.UI.toast.error(result.message || result.msg || String(root.getAttribute('data-menu-save-fail') || ''));
                    }
                })
                .catch(function (error) {
                    Weline.UI.toast.error((root.getAttribute('data-menu-request-fail') || '') + ': ' + error.message);
                });
        }

        function deleteMenu(menuId, menuName) {
            var template = String(root.getAttribute('data-menu-delete-confirm') || '');
            var message = template.replace('%name%', menuName);
            Weline.UI.dialog.confirm(message, {
                title: String(root.getAttribute('data-menu-delete-title') || ''),
                tone: 'danger',
                confirmLabel: String(root.getAttribute('data-menu-delete-label') || ''),
                cancelLabel: String(root.getAttribute('data-menu-cancel-label') || ''),
            }).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }
                var formData = new FormData();
                formData.append('id', menuId);
                Weline.adminRequest('admin', deleteUrl, { method: 'POST', body: formData })
                    .then(function (response) {
                        var result = (response && response.data) !== undefined ? response.data : response;
                        if (result.code === 200) {
                            Weline.UI.toast.success(result.msg || String(root.getAttribute('data-menu-delete-success') || ''));
                            window.setTimeout(function () {
                                window.location.reload();
                            }, 1000);
                        } else {
                            Weline.UI.toast.error(result.msg || String(root.getAttribute('data-menu-delete-fail') || ''));
                        }
                    })
                    .catch(function (error) {
                        Weline.UI.toast.error((root.getAttribute('data-menu-request-fail') || '') + ': ' + error.message);
                    });
            });
        }

        root.querySelectorAll('[data-menu-toggle]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                toggleNode(button);
            });
        });

        root.querySelectorAll('[data-menu-item]').forEach(function (item) {
            item.addEventListener('dblclick', function (event) {
                if (event.target.closest('[data-menu-toggle]')) {
                    return;
                }
                var toggle = item.querySelector(':scope > [data-menu-row] [data-menu-toggle]');
                if (toggle && toggle.getAttribute('aria-expanded') === 'false') {
                    toggleNode(toggle);
                }
            });
        });

        var expandAll = root.querySelector('[data-menu-expand-all]');
        if (expandAll) {
            expandAll.addEventListener('click', function () {
                setAll(true);
            });
        }
        var collapseAll = root.querySelector('[data-menu-collapse-all]');
        if (collapseAll) {
            collapseAll.addEventListener('click', function () {
                setAll(false);
            });
        }
        var addButton = root.querySelector('[data-menu-add]');
        if (addButton) {
            addButton.addEventListener('click', function () {
                openAddModal('');
            });
        }
        root.querySelectorAll('[data-menu-add-child]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                openAddModal(button.getAttribute('data-parent-source') || '');
            });
        });
        root.querySelectorAll('[data-menu-edit]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                openEditModal(button.getAttribute('data-id') || '');
            });
        });
        root.querySelectorAll('[data-menu-delete]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                deleteMenu(button.getAttribute('data-id') || '', button.getAttribute('data-name') || '');
            });
        });
        var saveButton = document.getElementById('menuSaveBtn');
        if (saveButton) {
            saveButton.addEventListener('click', saveMenu);
        }
        if (iconInput) {
            iconInput.addEventListener('input', function () {
                setIconPreview(this.value.trim() || 'menu');
            });
        }
    }

    function boot() {
        Array.prototype.slice.call(document.querySelectorAll('[data-menu-tree]')).forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
