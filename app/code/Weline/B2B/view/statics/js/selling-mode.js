(function (global) {
    'use strict';

    var COOKIE_NAME = 'weline_selling_mode';
    var COOKIE_DAYS = 30;

    function readCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function writeCookie(name, value) {
        var maxAge = COOKIE_DAYS * 24 * 60 * 60;
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
    }

    function preferredMode(root) {
        var fromRoot = String((root && root.getAttribute('data-selling-mode')) || '').toLowerCase();
        if (fromRoot === 'toc' || fromRoot === 'tob') {
            return fromRoot;
        }
        var fromCookie = String(readCookie(COOKIE_NAME) || '').toLowerCase();
        if (fromCookie === 'toc' || fromCookie === 'tob') {
            return fromCookie;
        }
        return 'toc';
    }

    function setMode(root, mode) {
        mode = mode === 'tob' ? 'tob' : 'toc';
        writeCookie(COOKIE_NAME, mode);
        try {
            global.sessionStorage.setItem(COOKIE_NAME, mode);
        } catch (e) {}
        if (root) {
            root.setAttribute('data-selling-mode', mode);
            root.setAttribute('data-cart-type', mode);
        }
        var detail = document.querySelector('[data-testid="storefront-product-detail"]');
        if (detail) {
            detail.setAttribute('data-selling-mode', mode);
            detail.setAttribute('data-cart-type', mode);
        }
        document.documentElement.setAttribute('data-selling-mode', mode);
        syncButtons(mode, root);
        syncQty(mode, root);
        global.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
            detail: { selling_mode: mode, cart_type: mode }
        }));
    }

    function syncButtons(mode, root) {
        var scope = root || document;
        scope.querySelectorAll('[data-selling-mode-option]').forEach(function (btn) {
            var option = String(btn.getAttribute('data-selling-mode-option') || '').toLowerCase();
            var active = option === mode;
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            btn.setAttribute('data-state', active ? 'active' : 'inactive');
            if (active) {
                btn.setAttribute('data-variant', 'primary');
                btn.setAttribute('data-tone', 'primary');
            } else {
                // soft inactive: clear contrast vs primary without a second solid slab
                btn.setAttribute('data-variant', 'soft');
                btn.setAttribute('data-tone', 'neutral');
            }
        });
        document.querySelectorAll(
            '[data-testid="product-add-to-cart"], [data-testid="product-buy-now"], .product-native-detail__add, .product-native-detail__buy-now'
        ).forEach(function (button) {
            button.dataset.sellingMode = mode;
            button.dataset.cartType = mode;
        });
        var applyCta = (root || document).querySelector('[data-b2b-open-apply]');
        var membership = String((root && root.getAttribute('data-has-membership')) || '0') === '1';
        var loggedIn = String((root && root.getAttribute('data-customer-logged-in')) || '0') === '1';
        if (applyCta) {
            applyCta.hidden = !(mode === 'tob' && loggedIn && !membership);
        }
        var guestHint = (root || document).querySelector('[data-b2b-guest-tob-hint]');
        if (guestHint) {
            guestHint.hidden = !(mode === 'tob' && !loggedIn);
        }
        var moqHint = (root || document).querySelector('[data-b2b-tob-moq-hint]');
        if (moqHint) {
            moqHint.hidden = !(mode === 'tob' && loggedIn && membership);
        }
    }

    function rebuildQtySelect(select, min, step, max) {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }
        min = Math.max(1, Number(min) || 1);
        step = Math.max(1, Number(step) || 1);
        max = Math.max(min, Number(max) || 30);
        var current = Math.max(min, Number(select.value) || min);
        if (current % step !== 0) {
            current = min;
        }
        select.innerHTML = '';
        for (var q = min; q <= max; q += step) {
            var opt = document.createElement('option');
            opt.value = String(q);
            opt.textContent = String(q);
            if (q === current) {
                opt.selected = true;
            }
            select.appendChild(opt);
        }
        select.setAttribute('data-qty-min', String(min));
        select.setAttribute('data-qty-step', String(step));
        select.value = String(current);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function syncQty(mode, root) {
        var detail = document.querySelector('[data-testid="storefront-product-detail"]');
        var select = detail && detail.querySelector('[data-testid="product-qty"]');
        if (!select) {
            return;
        }
        var moq = Number((root && root.getAttribute('data-tob-moq')) || select.getAttribute('data-tob-moq') || 5) || 5;
        var step = Number((root && root.getAttribute('data-tob-qty-step')) || select.getAttribute('data-tob-qty-step') || 5) || 5;
        var max = Number(select.getAttribute('data-qty-max') || 30) || 30;
        if (mode === 'tob') {
            rebuildQtySelect(select, moq, step, max);
        } else {
            rebuildQtySelect(select, 1, 1, max);
        }
    }

    function openDrawer(drawer) {
        if (!drawer) {
            return;
        }
        drawer.hidden = false;
        drawer.setAttribute('data-state', 'open');
        drawer.setAttribute('aria-hidden', 'false');
        if (global.Weline && global.Weline.UI && typeof global.Weline.UI.open === 'function') {
            try {
                global.Weline.UI.open(drawer);
            } catch (e) {}
        }
    }

    function closeDrawer(drawer) {
        if (!drawer) {
            return;
        }
        drawer.setAttribute('data-state', 'closed');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.hidden = true;
        if (global.Weline && global.Weline.UI && typeof global.Weline.UI.close === 'function') {
            try {
                global.Weline.UI.close(drawer);
            } catch (e) {}
        }
    }

    function loginRedirect(root) {
        var loginUrl = String((root && root.getAttribute('data-login-url')) || '/customer/account/login');
        var returnUrl = global.location.pathname + global.location.search + global.location.hash;
        var url = new URL(loginUrl, global.location.origin);
        url.searchParams.set('redirect', returnUrl);
        url.searchParams.set('return_url', returnUrl);
        global.location.href = url.toString();
    }

    async function submitMembership(root, form) {
        var status = root.querySelector('[data-b2b-apply-status]');
        var websiteId = Number(root.getAttribute('data-website-id') || 0);
        var customerId = String(root.getAttribute('data-customer-id') || '').trim();
        var company = String((form.querySelector('[name="company_name"]') || {}).value || '').trim();
        var phone = String((form.querySelector('[name="contact_phone"]') || {}).value || '').trim();
        var notes = String((form.querySelector('[name="notes"]') || {}).value || '').trim();
        if (status) {
            status.textContent = String(root.getAttribute('data-i18n-submitting') || 'Submitting...');
        }
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            throw new Error('api_unavailable');
        }
        var api = global.Weline.Api.resource('b2b');
        var result = await api['membership.submit']({
            customer_id: customerId,
            website_id: websiteId,
            company_name: company,
            contact_phone: phone,
            notes: notes
        }, { silent: true });
        if (!result || result.success === false || result.ok === false) {
            throw new Error((result && result.message) || 'submit_failed');
        }
        if (status) {
            status.textContent = String(root.getAttribute('data-i18n-submitted') || 'Submitted');
        }
        closeDrawer(root.querySelector('[data-b2b-apply-drawer]'));
    }

    function bindRoot(root) {
        if (!root || root.getAttribute('data-b2b-selling-bound') === '1') {
            return;
        }
        root.setAttribute('data-b2b-selling-bound', '1');
        var mode = preferredMode(root);
        var tocEnabled = String(root.getAttribute('data-toc-enabled') || '1') !== '0';
        var tobEnabled = String(root.getAttribute('data-tob-enabled') || '1') !== '0';
        if (mode === 'tob' && !tobEnabled) {
            mode = 'toc';
        }
        if (mode === 'toc' && !tocEnabled && tobEnabled) {
            mode = 'tob';
        }
        setMode(root, mode);

        root.addEventListener('click', function (event) {
            var optionBtn = event.target.closest('[data-selling-mode-option]');
            if (optionBtn && root.contains(optionBtn)) {
                var next = String(optionBtn.getAttribute('data-selling-mode-option') || '').toLowerCase();
                if (next !== 'toc' && next !== 'tob') {
                    return;
                }
                if (next === 'tob' && String(root.getAttribute('data-tob-enabled') || '1') === '0') {
                    return;
                }
                if (next === 'toc' && String(root.getAttribute('data-toc-enabled') || '1') === '0') {
                    return;
                }
                var loggedIn = String(root.getAttribute('data-customer-logged-in') || '0') === '1';
                if (next === 'tob' && !loggedIn) {
                    setMode(root, 'tob');
                    loginRedirect(root);
                    return;
                }
                setMode(root, next);
                var membership = String(root.getAttribute('data-has-membership') || '0') === '1';
                if (next === 'tob' && loggedIn && !membership) {
                    openDrawer(root.querySelector('[data-b2b-apply-drawer]'));
                }
                return;
            }
            if (event.target.closest('[data-b2b-open-apply]')) {
                openDrawer(root.querySelector('[data-b2b-apply-drawer]'));
                return;
            }
            if (event.target.closest('[data-b2b-close-apply]')) {
                closeDrawer(root.querySelector('[data-b2b-apply-drawer]'));
            }
        });

        var form = root.querySelector('[data-b2b-apply-form]');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                submitMembership(root, form).catch(function (error) {
                    var status = root.querySelector('[data-b2b-apply-status]');
                    if (status) {
                        status.textContent = (error && error.message) || String(root.getAttribute('data-i18n-submit-failed') || 'Failed');
                    }
                });
            });
        }
    }

    function init() {
        document.querySelectorAll('[data-b2b-selling-mode="1"]').forEach(bindRoot);
        global.WelineB2BSellingMode = {
            preferredMode: function () {
                return preferredMode(document.querySelector('[data-b2b-selling-mode="1"]'));
            },
            cookieName: COOKIE_NAME,
            readCookie: readCookie,
            writeCookie: writeCookie
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
