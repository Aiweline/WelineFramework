/**
 * TrackingVendor 后台工作范围切换（Website → Store → Channel）。
 * 绑定 Taglib <w:scope>：hidden#tracking-vendor-work-scope、tree#tracking-vendor-work-scope_tree。
 */
(function () {
    'use strict';
    var field = document.getElementById('tracking-vendor-work-scope');
    var tree = document.getElementById('tracking-vendor-work-scope_tree');
    if (!field) {
        return;
    }

    var i18n = { globalAlert: '' };
    try {
        var raw = document.getElementById('tracking-vendor-work-scope-i18n');
        if (raw && raw.textContent) {
            i18n = JSON.parse(raw.textContent) || i18n;
        }
    } catch (e) {
        // keep defaults
    }

    function decodeSegment(raw) {
        var s = String(raw || '');
        if (s === '__website__' || s === '__store__' || s === '__channel__') {
            return 'default';
        }
        return s;
    }

    function navigateFromNode(node) {
        var value = String((node && node.getAttribute('data-value')) || field.value || '').trim();
        var kind = String((node && node.getAttribute('data-kind')) || '').toLowerCase();
        if (!value) {
            return;
        }
        var parts = value.split('.');
        var website = decodeSegment(parts[0] || '');
        var store = decodeSegment(parts[1] || '');
        var channel = decodeSegment(parts[2] || '');
        var url = new URL(window.location.href);
        url.searchParams.set('target_scope', value);
        if (kind === 'global') {
            url.searchParams.set('website_code', '');
            url.searchParams.set('store_code', '');
            url.searchParams.set('channel_code', '');
            url.searchParams.set('scope_kind', 'global');
        } else if (kind === 'website') {
            url.searchParams.set('website_code', website || 'default');
            url.searchParams.set('store_code', '');
            url.searchParams.set('channel_code', '');
            url.searchParams.set('scope_kind', 'website');
        } else if (kind === 'store') {
            url.searchParams.set('website_code', website || 'default');
            url.searchParams.set('store_code', store || 'default');
            url.searchParams.set('channel_code', '');
            url.searchParams.set('scope_kind', 'store');
        } else if (kind === 'channel') {
            url.searchParams.set('website_code', website || 'default');
            url.searchParams.set('store_code', store || 'default');
            url.searchParams.set('channel_code', channel || 'default');
            url.searchParams.set('scope_kind', 'channel');
        } else {
            url.searchParams.set('website_code', website);
            url.searchParams.set('store_code', store === 'default' ? '' : store);
            url.searchParams.set('channel_code', channel === 'default' ? '' : channel);
            url.searchParams.delete('scope_kind');
        }
        // vendor 深链保留；website_id 由服务端从 TargetScope 重算
        url.searchParams.delete('website_id');
        // 纠偏：*/tracking-vendor 展开会丢掉 Controller/Backend 段 → 404
        if (url.pathname.indexOf('/visitor/tracking-vendor/') !== -1
            && url.pathname.indexOf('/visitor/backend/tracking-vendor/') === -1) {
            url.pathname = url.pathname.replace('/visitor/tracking-vendor/', '/visitor/backend/tracking-vendor/');
        }

        var next = url.toString();
        if (next === window.location.href) {
            if (kind === 'global' && i18n.globalAlert) {
                window.alert(i18n.globalAlert);
            }
            return;
        }
        window.location.assign(next);
    }

    function selectedNode() {
        return document.querySelector('#tracking-vendor-work-scope_tree [data-w-scope-node].selected')
            || document.querySelector('#tracking-vendor-work-scope_dropdown [data-w-scope-node].selected');
    }

    field.addEventListener('change', function () {
        navigateFromNode(selectedNode());
    });
    field.addEventListener('input', function () {
        navigateFromNode(selectedNode());
    });

    if (tree) {
        tree.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-w-scope-expand]')) {
                return;
            }
            var node = ev.target.closest('[data-w-scope-node]');
            if (!node) {
                return;
            }
            window.setTimeout(function () {
                navigateFromNode(node);
            }, 0);
        });
    }
})();
