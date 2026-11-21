/**
 * HelpPay CTA 预捕获：主模块 helpPayShare 按视口延迟加载，
 * 慢网下用户可能在绑定前点击 CTA。本模块 eager 装载，
 * 在捕获阶段把这次点击压进 window.__helppayPendingOpen，
 * 由 helpPayShare boot 后的 drainPendingOpens() 补放。
 * Registered as weline module `helpPayCtaCapture`.
 */
(function (w, d) {
    'use strict';

    if (w.__helppayCtaCaptureBound) {
        return;
    }
    w.__helppayCtaCaptureBound = true;

    d.addEventListener('click', function (ev) {
        if (w.WelineModules && w.WelineModules.helpPayShare) {
            return;
        }
        var t = ev.target;
        var btn = t && typeof t.closest === 'function' ? t.closest('[data-helppay-open]') : null;
        if (!btn) {
            return;
        }
        (w.__helppayPendingOpen = w.__helppayPendingOpen || []).push(btn);
        ev.preventDefault();
    }, true);
})(window, document);
