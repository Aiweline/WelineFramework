/**
 * Weline_Maintenance — async 503 wait modal + same-URL wait-gift.
 * Loaded on demand by Frontend ModuleLoader when Api hits maintenance/503.
 */
(function (window, document) {
    'use strict';

    if (window.WelineMaintenanceAsyncWait && window.WelineMaintenanceAsyncWait.__full) {
        return;
    }

    let modalVisible = false;
    let recoveryTimer = 0;
    const WAIT_STORAGE_KEY = 'weline_mw_wait_gift';
    const PENDING_COUPON_KEY = 'weline.cart.pending_coupon';
    const endpoints = {
        issue: '/maintenance/frontend/wait-gift/issue',
        redeem: '/maintenance/frontend/wait-gift/redeem',
        wave: '/maintenance/frontend/wait-gift/wave',
    };

    function __(key) {
        if (window.Weline && window.Weline.__) {
            return window.Weline.__(key);
        }
        if (window.Weline && window.Weline.i18n && typeof window.Weline.i18n.translate === 'function') {
            return window.Weline.i18n.translate(key);
        }
        return key;
    }

    function pageKey(href) {
        try {
            const url = new URL(href || window.location.href);
            return url.pathname + url.search;
        } catch (e) {
            return String(href || '');
        }
    }

    function readSellingModeCookie() {
        try {
            const raw = String(document.cookie || '');
            const names = [];
            raw.split(';').forEach((part) => {
                const key = String(part.split('=')[0] || '').trim();
                if (key === 'weline_selling_mode' || /^weline_selling_mode_w\d+$/.test(key)) {
                    names.push(key);
                }
            });
            names.sort((a, b) => {
                const score = (n) => (/^weline_selling_mode_w\d+$/.test(n) ? 0 : 1);
                return score(a) - score(b) || a.localeCompare(b);
            });
            for (let i = 0; i < names.length; i += 1) {
                const match = raw.match(new RegExp('(?:^|; )' + names[i].replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
                const value = match ? decodeURIComponent(match[1] || '').toLowerCase() : '';
                if (value === 'toc' || value === 'tob') {
                    return value;
                }
            }
        } catch (e) {
            // ignore
        }
        return '';
    }

    /** Retail ToC only; wholesale tob never sees / claims wait-gift. Default toc when B2B absent. */
    function isTocAudience() {
        if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
            return String(window.WelineB2BSellingMode.preferredMode() || 'toc').toLowerCase() !== 'tob';
        }
        const fromCookie = readSellingModeCookie();
        if (fromCookie === 'tob') {
            return false;
        }
        try {
            const fromSession = String(sessionStorage.getItem('weline_selling_mode') || '').toLowerCase();
            if (fromSession === 'tob') {
                return false;
            }
        } catch (e) {
            // ignore
        }
        return true;
    }

    function currentSellingMode() {
        if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
            const mode = String(window.WelineB2BSellingMode.preferredMode() || 'toc').toLowerCase();
            return mode === 'tob' ? 'tob' : 'toc';
        }
        const fromCookie = readSellingModeCookie();
        return fromCookie === 'tob' ? 'tob' : 'toc';
    }

    function readWaitGift() {
        try {
            const raw = sessionStorage.getItem(WAIT_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            const data = JSON.parse(raw);
            if (!data || !data.token || !data.url) {
                return null;
            }
            return { token: String(data.token), url: String(data.url) };
        } catch (e) {
            return null;
        }
    }

    function storeWaitGift(token) {
        const value = String(token || '');
        try {
            if (!value) {
                sessionStorage.removeItem(WAIT_STORAGE_KEY);
                return;
            }
            sessionStorage.setItem(WAIT_STORAGE_KEY, JSON.stringify({
                token: value,
                url: pageKey(),
            }));
        } catch (e) {
            // ignore
        }
    }

    function clearWaitGift() {
        storeWaitGift('');
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body || {}),
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            return { ok: response.ok, status: response.status, data: data || {} };
        });
    }

    function publishWaitGiftRedeemed(code) {
        const normalized = String(code || '').trim().toUpperCase();
        if (!normalized) {
            return Promise.resolve();
        }
        // Persist before cart.js may load — boot applyPendingCoupon picks this up.
        const expiresAt = Date.now() + (7 * 24 * 3600 * 1000);
        try {
            localStorage.setItem(PENDING_COUPON_KEY, JSON.stringify({
                coupon_code: normalized,
                source: 'maintenance_wait_gift',
                issued_at: Date.now(),
                expires_at: expiresAt,
            }));
        } catch (e) {
            // privacy modes
        }
        const detail = {
            coupon_code: normalized,
            source: 'maintenance_wait_gift',
            expires_at: expiresAt,
        };
        window.dispatchEvent(new CustomEvent('weline:maintenance:wait-gift-redeemed', { detail: detail }));
        window.dispatchEvent(new CustomEvent('weline:cart:apply-coupon', { detail: detail }));
        return Promise.resolve();
    }

    function tryRedeemSameUrlToken() {
        if (document.documentElement.getAttribute('data-w-wait-gift') === '1') {
            return;
        }
        if (!isTocAudience()) {
            clearWaitGift();
            return;
        }
        const stored = readWaitGift();
        if (!stored) {
            return;
        }
        if (stored.url !== pageKey()) {
            clearWaitGift();
            return;
        }
        postJson(endpoints.redeem, { token: stored.token, selling_mode: currentSellingMode() }).then((result) => {
            clearWaitGift();
            if (result.ok && result.data && result.data.success && result.data.coupon_code) {
                return publishWaitGiftRedeemed(String(result.data.coupon_code));
            }
            return null;
        }).catch(() => {
            clearWaitGift();
        });
    }

    function closeModal(leave) {
        const overlay = document.getElementById('weline-maintenance-wait-modal');
        if (overlay) {
            overlay.remove();
        }
        modalVisible = false;
        window.clearTimeout(recoveryTimer);
        recoveryTimer = 0;
        if (leave) {
            clearWaitGift();
        }
    }

    function scheduleRecoveryReload() {
        window.clearTimeout(recoveryTimer);
        recoveryTimer = window.setTimeout(() => {
            recoveryTimer = 0;
            if (document.hidden) {
                scheduleRecoveryReload();
                return;
            }
            fetch('/maintenance/frontend/recovery-check?_maintenance_recovery_probe=' + Date.now(), {
                method: 'HEAD',
                cache: 'no-store',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: { Accept: 'text/plain,*/*;q=0.8', 'X-Maintenance-Recovery-Check': '1' },
            }).then((response) => {
                const recovered = response.type === 'opaqueredirect'
                    || (response.status !== 503 && response.status !== 0 && response.status !== 500
                        && response.status !== 502 && response.status !== 504);
                if (recovered) {
                    window.location.reload();
                    return;
                }
                scheduleRecoveryReload();
            }).catch(() => scheduleRecoveryReload());
        }, 5000);
    }

    function issueOnCurrentPage() {
        if (!isTocAudience()) {
            clearWaitGift();
            return Promise.resolve();
        }
        const existing = readWaitGift();
        const body = existing && existing.url === pageKey() ? { token: existing.token } : {};
        body.selling_mode = currentSellingMode();
        return postJson(endpoints.issue, body).then((result) => {
            if (result.data && result.data.success && result.data.token) {
                storeWaitGift(result.data.token);
            }
        }).catch(() => undefined);
    }

    function truthyFlag(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function collectMaintenanceBags(ctx) {
        const bags = [];
        const push = (value) => {
            if (value && typeof value === 'object') {
                bags.push(value);
            }
        };
        push(ctx);
        push(ctx && ctx.data);
        push(ctx && ctx.payload);
        push(ctx && ctx.payload && ctx.payload.data);
        push(ctx && ctx.error && ctx.error.response);
        push(ctx && ctx.error && ctx.error.response && ctx.error.response.data);
        push(ctx && ctx.error && ctx.error.response && ctx.error.response.data && ctx.error.response.data.data);
        return bags;
    }

    function resolveMaintenanceMeta(ctx) {
        const bags = collectMaintenanceBags(ctx);
        let waitGift = false;
        let systemVersion = '';
        let themeVersion = '';
        let message = '';
        bags.forEach((bag) => {
            if (truthyFlag(bag.wait_gift_enabled)) {
                waitGift = true;
            }
            if (!systemVersion && bag.system_version) {
                systemVersion = String(bag.system_version);
            }
            if (!themeVersion && bag.theme_version) {
                themeVersion = String(bag.theme_version);
            }
            if (!message && bag.message) {
                message = String(bag.message);
            }
        });
        if (!message && ctx && ctx.error && ctx.error.message) {
            message = String(ctx.error.message);
        }
        if (!waitGift && /礼金|gift|compensation/i.test(message)) {
            waitGift = true;
        }
        return { waitGift, systemVersion, themeVersion, message };
    }

    function giftPanelHtml() {
        return [
            '<div data-w-mw-gift style="margin:0 0 16px;padding:12px 14px;text-align:left;background:#fff8e7;border:1px solid #f0c14b;border-radius:8px;">',
            '<div style="font-size:13px;font-weight:700;color:#0f1111;margin:0 0 6px;">' + __('维护补偿礼金') + '</div>',
            '<p style="margin:0 0 6px;line-height:1.55;font-size:13px;color:#0f1111;">'
                + __('不过也要恭喜您——若您耐心等到升级完成，我们将发放补偿礼金。请先不要关闭。')
                + '</p>',
            '<p style="margin:0;font-size:12px;color:#565959;">'
                + __('恢复后约 10 分钟内自动领取，请勿关闭本页。')
                + '</p>',
            '</div>',
        ].join('');
    }

    function applyGiftUi(card, waitBtn) {
        if (!card || card.querySelector('[data-w-mw-gift]')) {
            return;
        }
        const body = card.querySelector('[data-w-mw-body]');
        if (body) {
            body.textContent = __('非常抱歉，给您带来不便。');
        }
        const slot = card.querySelector('[data-w-mw-gift-slot]');
        if (slot) {
            slot.innerHTML = giftPanelHtml();
        }
        if (waitBtn) {
            waitBtn.disabled = false;
            waitBtn.textContent = __('耐心等待');
        }
    }

    function fetchWaveGiftEnabled() {
        return fetch(endpoints.wave, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!data || typeof data !== 'object') {
                return false;
            }
            if (Object.prototype.hasOwnProperty.call(data, 'wait_gift_eligible')) {
                return truthyFlag(data.wait_gift_eligible);
            }
            if (String(data.audience || data.selling_mode || '').toLowerCase() === 'tob') {
                return false;
            }
            return !!(truthyFlag(data.wait_gift_enabled) && isTocAudience());
        }).catch(() => false);
    }

    function showModal(ctx) {
        if (modalVisible) {
            return;
        }
        modalVisible = true;
        const meta = resolveMaintenanceMeta(ctx || {});
        // Gift offer is ToC-only; wholesale keeps plain maintenance apology.
        let waitGift = !!meta.waitGift && isTocAudience();
        const systemVersion = String(meta.systemVersion || '');
        const themeVersion = String(meta.themeVersion || '');
        const overlay = document.createElement('div');
        overlay.id = 'weline-maintenance-wait-modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(15,17,17,0.55);padding:20px;';
        const card = document.createElement('div');
        card.style.cssText = 'max-width:460px;width:100%;overflow:hidden;background:#fff;border:1px solid #d5d9d9;border-radius:8px;box-shadow:0 4px 14px rgba(15,17,17,0.18);font-family:"Amazon Ember",Arial,"PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;color:#0f1111;';
        card.innerHTML = [
            '<div style="background:linear-gradient(180deg,#131921 0%,#232f3e 100%);padding:14px 18px;color:#fff;text-align:left;">',
            '<div style="font-size:15px;font-weight:700;letter-spacing:0.01em;">Weline</div>',
            '<div style="width:3.2rem;height:0.35rem;margin-top:4px;border:2px solid #ff9900;border-top:0;border-radius:0 0 2rem 2rem;" aria-hidden="true"></div>',
            '</div>',
            '<div style="padding:22px 20px 20px;text-align:center;">',
            '<h2 style="margin:0 0 10px;font-size:1.25rem;font-weight:700;line-height:1.3;color:#0f1111;">'
                + __('抱歉，网站正在升级维护')
                + '</h2>',
            '<p data-w-mw-body style="margin:0 0 12px;line-height:1.6;color:#565959;font-size:14px;">'
                + __('非常抱歉，给您带来不便。')
                + '</p>',
            '<div data-w-mw-gift-slot>',
            waitGift
                ? giftPanelHtml()
                : '<p data-w-mw-plain style="margin:0 0 14px;line-height:1.6;color:#565959;font-size:14px;">'
                    + __('请稍候片刻，恢复后即可继续。')
                    + '</p>',
            '</div>',
            (systemVersion || themeVersion)
                ? '<p style="margin:0 0 16px;font-size:12px;color:#565959;">SYS '
                    + systemVersion
                    + ' · Theme '
                    + themeVersion
                    + '</p>'
                : '',
            '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">',
            '<button type="button" data-w-mw-leave style="min-height:2.4rem;border:1px solid #d5d9d9;border-radius:20px;padding:8px 18px;background:linear-gradient(180deg,#fff 0%,#f7fafa 100%);color:#0f1111;cursor:pointer;font-size:14px;font-family:inherit;box-shadow:0 1px 0 rgba(213,217,217,0.55);">'
                + __('稍后再来')
                + '</button>',
            '<button type="button" data-w-mw-wait style="min-height:2.4rem;border:1px solid #fcd200;border-radius:20px;padding:8px 18px;background:linear-gradient(180deg,#ffe566 0%,#ffd814 55%,#f0c14b 100%);color:#0f1111;cursor:pointer;font-size:14px;font-weight:500;font-family:inherit;box-shadow:0 2px 0 rgba(213,217,217,0.5);">'
                + (waitGift ? __('耐心等待') : __('稍候片刻'))
                + '</button>',
            '</div>',
            '</div>',
        ].join('');
        overlay.appendChild(card);
        document.body.appendChild(overlay);

        const waitBtn = card.querySelector('[data-w-mw-wait]');
        const leaveBtn = card.querySelector('[data-w-mw-leave]');
        if (waitBtn) {
            waitBtn.addEventListener('click', () => {
                if (!waitGift) {
                    return;
                }
                issueOnCurrentPage().then(() => {
                    waitBtn.textContent = __('正在等待恢复…');
                    waitBtn.disabled = true;
                    scheduleRecoveryReload();
                });
            });
        }
        if (leaveBtn) {
            leaveBtn.addEventListener('click', () => closeModal(true));
        }

        const startWaitFlow = () => {
            if (waitGift) {
                issueOnCurrentPage().then(() => scheduleRecoveryReload());
            } else {
                scheduleRecoveryReload();
            }
        };

        if (waitGift) {
            startWaitFlow();
            return;
        }

        // Already decided tob → no gift chase.
        if (!isTocAudience()) {
            startWaitFlow();
            return;
        }

        fetchWaveGiftEnabled().then((enabled) => {
            if (!enabled || !document.body.contains(overlay) || !isTocAudience()) {
                startWaitFlow();
                return;
            }
            waitGift = true;
            applyGiftUi(card, waitBtn);
            startWaitFlow();
        });
    }

    function handle(ctx) {
        showModal(ctx && typeof ctx === 'object' ? ctx : {});
    }

    function hasPendingWaitGiftToken() {
        try {
            return !!sessionStorage.getItem(WAIT_STORAGE_KEY);
        } catch (e) {
            return false;
        }
    }

    const api = {
        __full: true,
        handle: handle,
        tryRedeemSameUrlToken: tryRedeemSameUrlToken,
        hasPendingWaitGiftToken: hasPendingWaitGiftToken,
        WAIT_STORAGE_KEY: WAIT_STORAGE_KEY,
    };

    window.WelineMaintenanceAsyncWait = api;
})(window, document);
