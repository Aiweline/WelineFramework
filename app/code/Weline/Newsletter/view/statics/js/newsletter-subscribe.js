/**
 * Newsletter storefront subscribe: Weline.Api.resource('newsletter').subscribe
 * Progressive enhancement keeps form POST to newsletter/subscribe.
 * Never uses native fetch/ajax.
 */
(function (global) {
    'use strict';

    var ROOT_SEL = '[data-newsletter-root]';
    var FORM_SEL = 'form[data-newsletter-form], [data-testid="newsletter-footer-form"], [data-testid="newsletter-popup-form"]';
    var SUCCESS_SEL = '[data-testid="newsletter-subscribe-success"]';
    var BOUND = 'data-newsletter-bound';

    function wait(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    async function resolveApi() {
        for (var attempt = 0; attempt < 60; attempt += 1) {
            if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
                return global.Weline.Api.resource('newsletter');
            }
            if (global.Weline && typeof global.Weline.load === 'function') {
                try {
                    await global.Weline.load('api');
                } catch (_e) {
                    /* retry */
                }
            }
            await wait(100);
        }
        throw new Error('Weline.Api is not ready.');
    }

    function unwrap(result) {
        if (!result || typeof result !== 'object') {
            return result;
        }
        if (result.data && typeof result.data === 'object' && ('ok' in result.data || 'coupon_code' in result.data)) {
            return result.data;
        }
        return result;
    }

    function boolFromControl(form, name, fallback) {
        var checkbox = form.querySelector('input[type="checkbox"][name="' + name + '"]');
        if (checkbox) {
            return !!checkbox.checked;
        }
        var els = form.querySelectorAll('[name="' + name + '"]');
        if (!els.length) {
            return fallback;
        }
        var el = els[els.length - 1];
        var raw = String(el.value || '').toLowerCase();
        if (raw === '0' || raw === 'false' || raw === 'off' || raw === 'no') {
            return false;
        }
        if (raw === '1' || raw === 'true' || raw === 'on' || raw === 'yes') {
            return true;
        }
        return fallback;
    }

    function collectPayload(form) {
        var emailInput = form.querySelector('input[name="email"], input[type="email"]');
        var surface = form.getAttribute('data-source-surface')
            || (form.closest('[data-source-surface]') || {}).getAttribute?.('data-source-surface')
            || 'footer';
        return {
            email: emailInput ? String(emailInput.value || '').trim() : '',
            topic_promo: boolFromControl(form, 'topic_promo', true),
            topic_new_arrivals: boolFromControl(form, 'topic_new_arrivals', true),
            source_surface: surface
        };
    }

    function findSuccessNode(root) {
        return (root && root.querySelector(SUCCESS_SEL)) || null;
    }

    function showSuccess(root, result) {
        var node = findSuccessNode(root);
        if (!node) {
            return;
        }
        var message = (result && result.message) ? String(result.message) : '';
        var coupon = (result && result.coupon_code) ? String(result.coupon_code) : '';
        node.hidden = false;
        node.setAttribute('data-ok', result && result.ok ? '1' : '0');
        if (coupon) {
            node.setAttribute('data-coupon-code', coupon);
        } else {
            node.removeAttribute('data-coupon-code');
        }
        var msgEl = node.querySelector('[data-newsletter-success-message]');
        var couponEl = node.querySelector('[data-newsletter-coupon]');
        if (msgEl) {
            msgEl.textContent = message;
        }
        if (couponEl) {
            if (coupon) {
                couponEl.hidden = false;
                couponEl.textContent = coupon;
            } else {
                couponEl.hidden = true;
                couponEl.textContent = '';
            }
        }
        if (!msgEl) {
            node.textContent = coupon ? (message + ' · ' + coupon) : message;
        }
    }

    function showError(root, message) {
        var node = findSuccessNode(root);
        if (!node) {
            return;
        }
        node.hidden = false;
        node.setAttribute('data-ok', '0');
        node.removeAttribute('data-coupon-code');
        var msgEl = node.querySelector('[data-newsletter-success-message]');
        var couponEl = node.querySelector('[data-newsletter-coupon]');
        if (msgEl) {
            msgEl.textContent = message || '';
        } else {
            node.textContent = message || '';
        }
        if (couponEl) {
            couponEl.hidden = true;
            couponEl.textContent = '';
        }
    }

    function getCookie(name) {
        var value = '; ' + document.cookie;
        var parts = value.split('; ' + name + '=');
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }

    /**
     * Website Path from runtime baseUrl (e.g. /daocharms/) so multi-site same host
     * does not share show_once cookies. Fallback '/' when base is site root.
     */
    function cookiePath() {
        try {
            var cfg = document.getElementById('weline-frontend-runtime-config');
            var raw = cfg ? (cfg.textContent || cfg.innerText || '') : '';
            var json = raw ? JSON.parse(raw) : null;
            var base = (json && (json.baseUrl || (json.site && json.site.host))) || '';
            if (!base && window.WelineRuntime && window.WelineRuntime.baseUrl) {
                base = String(window.WelineRuntime.baseUrl);
            }
            if (!base) {
                return '/';
            }
            var path = '/';
            try {
                path = new URL(String(base), window.location.origin).pathname || '/';
            } catch (eUrl) {
                var m = String(base).match(/^https?:\/\/[^/]+(\/.*)?$/i);
                path = (m && m[1]) ? m[1] : '/';
            }
            if (path.charAt(path.length - 1) !== '/') {
                path += '/';
            }
            return path || '/';
        } catch (e) {
            return '/';
        }
    }

    /** Cookie name suffix: website path key so / and /daocharms/ stay independent. */
    function cookieSiteKey() {
        var path = cookiePath();
        if (!path || path === '/') {
            return 'root';
        }
        return String(path).replace(/^\/+|\/+$/g, '').replace(/[^a-zA-Z0-9_-]+/g, '-') || 'root';
    }

    function setCookie(name, value, days) {
        var date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=' + cookiePath();
    }

    function clearCookie(name) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=' + cookiePath();
        /* Also clear legacy path=/ so old dismissals do not keep suppressing this site. */
        if (cookiePath() !== '/') {
            document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        }
    }

    function unlockNewsletterScroll() {
        if (document.body.getAttribute('data-newsletter-scroll-lock') === '1') {
            document.body.style.overflow = '';
            document.body.removeAttribute('data-newsletter-scroll-lock');
        }
    }

    function lockNewsletterScroll() {
        document.body.style.overflow = 'hidden';
        document.body.setAttribute('data-newsletter-scroll-lock', '1');
    }

    function forceClosePopup(root) {
        if (!root) {
            return;
        }
        root.classList.remove('is-open');
        root.style.display = 'none';
        unlockNewsletterScroll();
    }

    /**
     * 页脚/chrome 内嵌时祖先常带 transform/filter，会把 position:fixed 困在页脚「趴地」。
     * 绑定与打开前挂到 body，保证相对视口全屏居中。
     */
    function mountPopupToBody(root) {
        if (!root || !document.body) {
            return;
        }
        if (root.parentNode === document.body) {
            return;
        }
        if (root.classList.contains('editor-preview-mode')
            || root.classList.contains('is-preview')
            || root.closest('.widget-preview-canvas')) {
            return;
        }
        try {
            document.body.appendChild(root);
            root.setAttribute('data-newsletter-popup-mounted', 'body');
        } catch (eMount) { /* ignore */ }
    }

    function bindPopup(root) {
        if (!root || root.getAttribute('data-newsletter-kind') !== 'popup') {
            return;
        }
        if (root.getAttribute('data-newsletter-popup-bound') === '1') {
            return;
        }
        root.setAttribute('data-newsletter-popup-bound', '1');
        mountPopupToBody(root);

        if (root.classList.contains('editor-preview-mode')
            || root.classList.contains('is-preview')
            || root.closest('.widget-preview-canvas')) {
            root.style.display = 'block';
            root.classList.add('is-open');
            return;
        }

        var trigger = root.getAttribute('data-trigger') || 'deferred';
        var delay = parseInt(root.getAttribute('data-delay') || '15000', 10);
        var scrollPercent = parseInt(root.getAttribute('data-scroll') || '40', 10);
        var minOpenMs = parseInt(root.getAttribute('data-min-open') || '3000', 10);
        var showOnce = root.getAttribute('data-show-once') !== 'false';
        var cookieDays = parseInt(root.getAttribute('data-cookie-days') || '14', 10);
        if (!cookieDays || cookieDays < 1) {
            cookieDays = 14;
        }
        if (!delay || delay < 0) {
            delay = 15000;
        }
        if (!scrollPercent || scrollPercent < 1) {
            scrollPercent = 40;
        }
        if (!minOpenMs || minOpenMs < 0) {
            minOpenMs = 3000;
        }
        /* 旧站 delay<15s：升级为组合延后，禁止首访首屏立即弹 */
        if (trigger === 'delay' && delay < 15000) {
            trigger = 'deferred';
            delay = 15000;
            if (scrollPercent < 40) {
                scrollPercent = 40;
            }
        }
        /* cookie 用稳定 widget-code + 网站 path key，避免 data-uid 重建与跨站 path=/ 误伤 */
        var cookieKey = root.getAttribute('data-widget-code') || root.getAttribute('data-uid') || 'popup';
        var cookieName = 'weline-newsletter-popup-shown-' + cookieSiteKey() + '-' + cookieKey;
        /* 验收/预览：?newsletter_popup=1 跳过 show_once cookie，并尽快弹出 */
        var forcePreview = false;
        try {
            forcePreview = /(?:^|[?&])newsletter_popup=1(?:&|$)/.test(String(window.location.search || ''));
        } catch (eForce) {
            forcePreview = false;
        }
        if (forcePreview) {
            showOnce = false;
            try {
                clearCookie(cookieName);
                /* 清旧版无站点前缀的 cookie（path=/） */
                clearCookie('weline-newsletter-popup-shown-' + cookieKey);
            } catch (eClear) { /* ignore */ }
        }
        var isOpen = false;
        var closeTimer = null;
        var fired = false;
        var pageReadyAt = Date.now();
        var pendingOpenTimer = null;

        function showPopup() {
            if (isOpen || fired) {
                return;
            }
            fired = true;
            if (pendingOpenTimer) {
                window.clearTimeout(pendingOpenTimer);
                pendingOpenTimer = null;
            }
            if (closeTimer) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
            isOpen = true;
            mountPopupToBody(root);
            root.style.display = 'block';
            lockNewsletterScroll();
            /* 双 rAF：先挂载闭合态再加 is-open，触发信封掀开 + 信笺升起 */
            root.classList.remove('is-open');
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    if (!isOpen) {
                        return;
                    }
                    root.classList.add('is-open');
                });
            });
            if (showOnce) {
                setCookie(cookieName, '1', cookieDays);
            }
        }

        /* 首屏硬门槛：任意触发源在 minOpenMs 内不得弹（验收：首访 3s 内不弹） */
        function requestShow() {
            if (fired || isOpen) {
                return;
            }
            var elapsed = Date.now() - pageReadyAt;
            if (elapsed < minOpenMs) {
                if (!pendingOpenTimer) {
                    pendingOpenTimer = window.setTimeout(function () {
                        pendingOpenTimer = null;
                        showPopup();
                    }, minOpenMs - elapsed);
                }
                return;
            }
            showPopup();
        }

        function hidePopup() {
            if (!isOpen && root.style.display !== 'block') {
                return;
            }
            isOpen = false;
            root.classList.remove('is-open');
            /* 关闭动画期间立即解锁滚动，避免「看不见弹层却卡死页面」 */
            unlockNewsletterScroll();
            if (closeTimer) {
                window.clearTimeout(closeTimer);
            }
            closeTimer = window.setTimeout(function () {
                closeTimer = null;
                if (!isOpen) {
                    root.style.display = 'none';
                }
            }, 420);
            if (showOnce) {
                setCookie(cookieName, '1', cookieDays);
            }
        }

        if (showOnce && getCookie(cookieName)) {
            forceClosePopup(root);
            return;
        }

        /* 自愈：历史竞态可能留下 display:block + overflow:hidden 且无 is-open */
        if (root.style.display === 'block' && !root.classList.contains('is-open')) {
            forceClosePopup(root);
        }

        function bindScrollTrigger() {
            window.addEventListener('scroll', function () {
                if (fired) {
                    return;
                }
                var scrolled = (window.scrollY / Math.max(1, document.body.scrollHeight - window.innerHeight)) * 100;
                if (scrolled >= scrollPercent) {
                    requestShow();
                }
            }, {passive: true});
        }

        function bindExitTrigger() {
            document.addEventListener('mouseout', function (e) {
                if (fired) {
                    return;
                }
                if (e.clientY <= 0) {
                    requestShow();
                }
            });
        }

        function bindDelayTrigger() {
            window.setTimeout(requestShow, delay);
        }

        var isDeferred = trigger === 'deferred'
            || trigger === 'combined'
            || trigger === 'smart';

        if (forcePreview) {
            /* 强制预览：仅等待最早弹出门槛后打开，便于验收看信笺 */
            window.setTimeout(requestShow, Math.min(minOpenMs, 1200));
        } else if (isDeferred) {
            /* 滚动约 40% / 停留约 15s / 退出意向 — OR 组合 */
            bindDelayTrigger();
            bindScrollTrigger();
            bindExitTrigger();
        } else if (trigger === 'delay') {
            bindDelayTrigger();
        } else if (trigger === 'scroll') {
            bindScrollTrigger();
        } else if (trigger === 'exit' || trigger === 'exit-intent') {
            bindExitTrigger();
        } else {
            /* 未知触发：回退组合延后 */
            bindDelayTrigger();
            bindScrollTrigger();
            bindExitTrigger();
        }

        var closeBtn = root.querySelector('.popup-close');
        var overlay = root.querySelector('.popup-overlay');
        if (closeBtn) {
            closeBtn.addEventListener('click', hidePopup);
        }
        if (overlay) {
            overlay.addEventListener('click', hidePopup);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) {
                hidePopup();
            }
        });
        root.__newsletterHidePopup = hidePopup;
    }

    async function onSubmit(event) {
        var form = event.currentTarget;
        if (!form) {
            return;
        }
        event.preventDefault();
        var root = form.closest(ROOT_SEL) || form.closest('[data-widget-code]') || form.parentElement;
        var payload = collectPayload(form);
        if (!payload.email) {
            showError(root, form.getAttribute('data-msg-email-required') || '请输入有效的邮箱地址。');
            return;
        }
        var submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        try {
            var client = await resolveApi();
            if (!client || typeof client.subscribe !== 'function') {
                throw new Error('newsletter.subscribe unavailable');
            }
            var result = unwrap(await client.subscribe(payload, { silent: true }));
            if (!result || !result.ok) {
                showError(root, (result && result.message) || '订阅失败。');
                return;
            }
            showSuccess(root, result);
            form.setAttribute('data-subscribed', '1');
            if (typeof root.__newsletterHidePopup === 'function' && result.ok) {
                /* keep popup open to show coupon; do not auto-close */
            }
        } catch (error) {
            showError(root, (error && error.message) ? error.message : '订阅失败。');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
            }
        }
    }

    function bindForm(form) {
        if (!form || form.getAttribute(BOUND) === '1') {
            return;
        }
        form.setAttribute(BOUND, '1');
        form.addEventListener('submit', onSubmit);
    }

    function boot(root) {
        if (!root) {
            return;
        }
        bindPopup(root);
        root.querySelectorAll(FORM_SEL).forEach(bindForm);
        if (root.matches && root.matches(FORM_SEL)) {
            bindForm(root);
        }
    }

    function bootAll() {
        document.querySelectorAll(ROOT_SEL).forEach(boot);
        document.querySelectorAll(FORM_SEL).forEach(function (form) {
            var root = form.closest(ROOT_SEL);
            if (root) {
                boot(root);
            } else {
                bindForm(form);
            }
        });
    }

    var api = {
        boot: boot,
        bootAll: bootAll
    };

    global.WelineNewsletterSubscribe = api;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAll);
    } else {
        bootAll();
    }
})(typeof window !== 'undefined' ? window : this);
