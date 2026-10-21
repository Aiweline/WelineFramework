/**
 * 组件库页面（Weline_Component::Components/getIndex）
 *
 * 由模板 data-weline-load="componentLibrary" 按需加载（后端主题标准 §6.1）。
 * 配置来自 <script type="application/json" id="component-library-config">。
 */
(function () {
    'use strict';

    window.WelineComponentLibrary = { ready: true };

    var configEl = document.getElementById('component-library-config');
    if (!configEl) {
        return;
    }
    var config;
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (error) {
        return;
    }
    var text = config.text || {};

    function toastError(message) {
        var parentWeline = window.parent && window.parent.Weline;
        if (parentWeline && parentWeline.UI && parentWeline.UI.toast && typeof parentWeline.UI.toast.error === 'function') {
            parentWeline.UI.toast.error(message);
            return;
        }
        if (window.console && typeof window.console.error === 'function') {
            window.console.error(message);
        }
    }

    function loadDetail(componentClass) {
        var url = config.detailUrl + (config.detailUrl.indexOf('?') === -1 ? '?' : '&') + 'class=' + encodeURIComponent(componentClass);
        return window.Weline.adminRequest('component_admin', url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.json();
        });
    }

    function showComponentDetail(component) {
        var docHtml = component.doc || '<p>' + (text.noDoc || '') + '</p>';
        var modal = document.createElement('div');
        modal.className = 'w-dialog';
        modal.setAttribute('data-w-component', 'dialog');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', component.class || '');

        var surface = document.createElement('div');
        surface.className = 'w-dialog__surface';
        surface.innerHTML = ''
            + '<div class="w-dialog__header">'
            + '<h2 class="w-dialog__title"></h2>'
            + '<button type="button" class="w-button close-modal" data-tone="neutral" data-variant="quiet" aria-label="关闭">&times;</button>'
            + '</div>'
            + '<div class="w-dialog__body w-stack">'
            + '<div><strong></strong> <span class="w-badge" data-w-background="info" data-module></span></div>'
            + '<div><strong></strong> <span class="w-badge" data-w-background="success" data-category></span></div>'
            + '<div><strong></strong> <code class="w-text" data-size="sm" data-full-class></code></div>'
            + '<div><strong></strong><div class="w-alert" data-tone="info" data-doc></div></div>'
            + '</div>';

        surface.querySelector('.w-dialog__title').textContent = component.class || '';
        surface.querySelector('[data-module]').textContent = component.module || '';
        surface.querySelector('[data-category]').textContent = component.category || (text.other || '');
        surface.querySelector('[data-full-class]').textContent = component.full_class || '';
        var labels = surface.querySelectorAll('.w-dialog__body strong');
        labels[0].textContent = (text.module || '') + '：';
        labels[1].textContent = (text.category || '') + '：';
        labels[2].textContent = (text.fullClass || '') + '：';
        labels[3].textContent = (text.doc || '') + '：';
        surface.querySelector('[data-doc]').innerHTML = docHtml;

        modal.appendChild(surface);
        document.body.appendChild(modal);

        function close() {
            if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
        }
        surface.querySelector('.close-modal').addEventListener('click', close);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                close();
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.weline-btn-view-detail, .btn-view-detail');
        if (!button) {
            return;
        }
        var componentClass = button.getAttribute('data-component-class');
        if (!componentClass) {
            return;
        }
        loadDetail(componentClass).then(function (data) {
            if (data && data.success) {
                showComponentDetail(data.component || {});
                return;
            }
            toastError((data && data.message) || text.loadFailed || '');
        }).catch(function (error) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('Error:', error);
            }
            toastError(text.loadFailed || '');
        });
    });
})();
