/**
 * 网站维护模式页（Websites_Maintenance）
 *
 * 由模板 data-weline-load="websiteMaintenance" 按需加载（后端主题标准 §6.1）。
 * 配置来自 <script type="application/json" id="website-maintenance-config">。
 */
(function () {
    'use strict';

    window.WelineWebsitesMaintenance = { ready: true };

    var configEl = document.getElementById('website-maintenance-config');
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

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-maintenance-action="toggle"]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return;
        }
        var websiteId = parseInt(trigger.getAttribute('data-website-id') || '-1', 10);
        var websiteCode = trigger.getAttribute('data-website-code') || '';
        var enabled = trigger.getAttribute('data-maintenance-enabled') === '1';
        if (websiteId < 0 || !websiteCode) {
            return;
        }
        trigger.disabled = true;
        var scope = {
            scope_kind: 'website',
            website_id: websiteId,
            website_code: websiteCode,
            store_code: null,
            channel_code: null,
            store_mode: null,
            context_version: 'v1'
        };
        Promise.resolve(window.Weline.Api.resource('websiteMaintenance').set({
            scope: scope,
            enabled: enabled,
            reason: ''
        }, { keepBusinessResult: true, silent: true })).then(function (response) {
            var result = response && response.data ? response.data : response;
            if (!result || result.success === false) {
                throw new Error((result && result.message) || text.updateFail || '');
            }
            if (window.Weline.UI.toast && typeof window.Weline.UI.toast.success === 'function') {
                window.Weline.UI.toast.success(text.updated || '');
            }
            window.location.reload();
        }).catch(function (error) {
            trigger.disabled = false;
            if (window.Weline.UI.toast && typeof window.Weline.UI.toast.error === 'function') {
                window.Weline.UI.toast.error(error && error.message ? error.message : (text.updateFail || ''));
            }
        });
    });
})();
